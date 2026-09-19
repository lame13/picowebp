<?php
/**
 * Simple compressing pure-PHP VP8L (WebP lossless) encoder.
 *
 * Deliberately small scope:
 *   - one prefix-code group (no entropy image / meta prefix codes)
 *   - canonical length-limited Huffman codes per channel
 *   - optional LZ77 backward references
 *   - optional subtract-green transform
 *
 * No colour cache, no predictor transform, no colour transform, no palette.
 * That is enough to answer the real question: what sizes does a pure-PHP
 * lossless WebP reach, how long does it take, how much memory does it hold?
 *
 * Grammar followed from the VP8L bitstream specification:
 *   numeric fields are LSB-first, canonical Huffman codes are MSB-first.
 *
 * Every build must be validated against an independent decoder (libwebp) with
 * exact pixel comparison; a matching encoder and decoder can agree on a
 * misunderstanding and still produce files nothing else accepts.
 */

declare(strict_types=1);

namespace PicoWebP\Spike;

use InvalidArgumentException;
use RuntimeException;

final class BitWriter {
	private string $bytes = '';
	private int $acc = 0;
	private int $count = 0;

	public function bits(int $value, int $count): void {
		if ($count < 0 || $count > 24 || $value < 0 || ($count < 24 && $value >= (1 << $count))) {
			throw new InvalidArgumentException("Bit field out of range: $value/$count");
		}
		$this->acc |= ($value & ((1 << $count) - 1)) << $this->count;
		$this->count += $count;
		while ($this->count >= 8) {
			$this->bytes .= chr($this->acc & 0xFF);
			$this->acc >>= 8;
			$this->count -= 8;
		}
	}

	public function finish(): string {
		if ($this->count > 0) {
			$this->bytes .= chr($this->acc & 0xFF);
			$this->acc = 0;
			$this->count = 0;
		}
		return $this->bytes;
	}
}

final class Huff {
	private const ORDER = array(17, 18, 0, 1, 2, 3, 4, 5, 16, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15);

	/**
	 * Length-limited Huffman code lengths (package-merge).
	 *
	 * @param array<int, int> $freq Frequencies by symbol.
	 * @return array<int, int> Code length by symbol, 0 when unused.
	 */
	public static function lengths(array $freq, int $alphabetSize, int $limit): array {
		$lengths = array_fill(0, $alphabetSize, 0);
		$used    = array();
		foreach ($freq as $symbol => $count) {
			if ($count > 0) {
				$used[] = array((int) $symbol, (int) $count);
			}
		}
		$n = count($used);
		if (0 === $n) {
			return $lengths;
		}
		if (1 === $n) {
			$lengths[$used[0][0]] = 1;
			return $lengths;
		}
		usort($used, static fn(array $a, array $b): int => $a[1] <=> $b[1]);

		$nodes  = array();
		$leaves = array();
		foreach ($used as [$symbol, $count]) {
			$nodes[]  = array($count, -1, -1, $symbol);
			$leaves[] = count($nodes) - 1;
		}

		$prev = $leaves;
		for ($level = 1; $level <= $limit; $level++) {
			if (1 === $level) {
				$list = $leaves;
			} else {
				$pack = array();
				for ($i = 0; $i + 1 < count($prev); $i += 2) {
					$a       = $prev[$i];
					$b       = $prev[$i + 1];
					$nodes[] = array($nodes[$a][0] + $nodes[$b][0], $a, $b, -1);
					$pack[]  = count($nodes) - 1;
				}
				$list = self::merge($leaves, $pack, $nodes);
			}
			$prev = $list;
		}

		$take = 2 * $n - 2;
		if (count($prev) < $take) {
			throw new RuntimeException('Package-merge underflow.');
		}
		$visits = array_fill(0, count($nodes), 0);
		for ($i = 0; $i < $take; $i++) {
			$visits[$prev[$i]]++;
		}
		for ($i = count($nodes) - 1; $i >= 0; $i--) {
			if ($visits[$i] > 0 && $nodes[$i][1] >= 0) {
				$visits[$nodes[$i][1]] += $visits[$i];
				$visits[$nodes[$i][2]] += $visits[$i];
			}
		}
		foreach ($leaves as $index) {
			$lengths[$nodes[$index][3]] = $visits[$index];
		}
		return $lengths;
	}

	private static function merge(array $a, array $b, array $nodes): array {
		$out = array();
		$i   = 0;
		$j   = 0;
		$na  = count($a);
		$nb  = count($b);
		while ($i < $na && $j < $nb) {
			$out[] = ( $nodes[$a[$i]][0] <= $nodes[$b[$j]][0] ) ? $a[$i++] : $b[$j++];
		}
		while ($i < $na) {
			$out[] = $a[$i++];
		}
		while ($j < $nb) {
			$out[] = $b[$j++];
		}
		return $out;
	}

	/**
	 * Canonical codes plus the bit-reversed form the LSB-first writer needs.
	 *
	 * @param array<int, int> $lengths Code lengths.
	 * @return array<int, array{0: int, 1: int, 2: int}> symbol => [code, length, reversed]
	 */
	public static function codes(array $lengths): array {
		$blCount = array_fill(0, 16, 0);
		foreach ($lengths as $length) {
			if ($length > 0) {
				$blCount[$length]++;
			}
		}
		$nextCode = array_fill(0, 16, 0);
		$code     = 0;
		for ($bits = 1; $bits <= 15; $bits++) {
			$code            = ( $code + $blCount[$bits - 1] ) << 1;
			$nextCode[$bits] = $code;
		}
		$table = array();
		foreach ($lengths as $symbol => $length) {
			if ($length > 0) {
				$c              = $nextCode[$length]++;
				$table[$symbol] = array($c, $length, self::reverse($c, $length));
			}
		}
		return $table;
	}

	private static function reverse(int $value, int $length): int {
		$out = 0;
		for ($i = 0; $i < $length; $i++) {
			$out    = ( $out << 1 ) | ( $value & 1 );
			$value >>= 1;
		}
		return $out;
	}

	/** Write a prefix code description (simple form when possible). */
	public static function writeTree(BitWriter $out, array $lengths): void {
		$used = array();
		foreach ($lengths as $symbol => $length) {
			if ($length > 0) {
				$used[] = $symbol;
			}
		}
		$maxUsed = empty($used) ? -1 : max($used);
		if (count($used) <= 2 && $maxUsed <= 255) {
			if (empty($used)) {
				$used = array(0);
			}
			$out->bits(1, 1);
			$out->bits(count($used) - 1, 1);
			$first = $used[0];
			if ($first <= 1) {
				$out->bits(0, 1);
				$out->bits($first, 1);
			} else {
				$out->bits(1, 1);
				$out->bits($first, 8);
			}
			if (2 === count($used)) {
				$out->bits($used[1], 8);
			}
			return;
		}

		$rle    = self::rle($lengths);
		$clFreq = array_fill(0, 19, 0);
		foreach ($rle as $item) {
			$clFreq[$item[0]]++;
		}
		$clLengths = self::lengths($clFreq, 19, 7);
		$clCodes   = self::codes($clLengths);

		$num = 4;
		foreach (self::ORDER as $index => $symbol) {
			if ($clLengths[$symbol] > 0 && $index + 1 > $num) {
				$num = $index + 1;
			}
		}
		$num = max(4, min(19, $num));

		$out->bits(0, 1); // normal code length code
		$out->bits($num - 4, 4);
		for ($i = 0; $i < $num; $i++) {
			$out->bits($clLengths[self::ORDER[$i]], 3);
		}
		$out->bits(0, 1); // max_symbol = full alphabet

		foreach ($rle as [$symbol, $extra, $extraBits]) {
			$out->bits($clCodes[$symbol][2], $clLengths[$symbol]);
			if ($extraBits > 0) {
				$out->bits($extra, $extraBits);
			}
		}
	}

	/**
	 * Run-length encode a code-length sequence.
	 *
	 * @param array<int, int> $lengths Lengths.
	 * @return array<int, array{0: int, 1: int, 2: int}> [symbol, extra value, extra bits]
	 */
	private static function rle(array $lengths): array {
		$out = array();
		$n   = count($lengths);
		$i   = 0;
		while ($i < $n) {
			$length = $lengths[$i];
			if (0 === $length) {
				$j = $i;
				while ($j < $n && 0 === $lengths[$j]) {
					$j++;
				}
				$run = $j - $i;
				while ($run >= 11) {
					$take  = min($run, 138);
					$out[] = array(18, $take - 11, 7);
					$run  -= $take;
				}
				while ($run >= 3) {
					$take  = min($run, 10);
					$out[] = array(17, $take - 3, 3);
					$run  -= $take;
				}
				while ($run > 0) {
					$out[] = array(0, 0, 0);
					$run--;
				}
				$i = $j;
				continue;
			}
			$out[] = array($length, 0, 0);
			$i++;
			$j = $i;
			while ($j < $n && $lengths[$j] === $length) {
				$j++;
			}
			$run = $j - $i;
			while ($run >= 3) {
				$take  = min($run, 6);
				$out[] = array(16, $take - 3, 2);
				$run  -= $take;
			}
			while ($run > 0) {
				$out[] = array($length, 0, 0);
				$run--;
			}
			$i = $j;
		}
		return $out;
	}
}

/**
 * The encoder.
 *
 * Pixels are a packed RGBA string (4 bytes per pixel, row major).
 */
final class Vp8lEncoder {
	/** @var array<string, mixed> */
	public array $stats = array();

	private int $width  = 0;
	private int $height = 0;
	private int $pixels = 0;

	/**
	 * @param array{lz77?: bool, subtract_green?: bool, depth?: int} $options
	 */
	public function encode(int $width, int $height, string $rgba, array $options = array()): string {
		$this->width  = $width;
		$this->height = $height;
		$this->pixels = $width * $height;

		$useLz77     = $options['lz77'] ?? true;
		$useSubtract = $options['subtract_green'] ?? false;
		$depth       = $options['depth'] ?? 8;
		$this->stats['options'] = array('lz77' => $useLz77, 'subtract_green' => $useSubtract, 'depth' => $depth);

		$alphaUsed = 0;
		for ($i = 3, $n = strlen($rgba); $i < $n; $i += 4) {
			if (255 !== \ord($rgba[$i])) {
				$alphaUsed = 1;
				break;
			}
		}

		$bits = new BitWriter();
		$bits->bits($width - 1, 14);
		$bits->bits($height - 1, 14);
		$bits->bits($alphaUsed, 1);
		$bits->bits(0, 3);

		$stream = $rgba;
		if ($useSubtract) {
			$t0                               = microtime(true);
			$stream                           = $this->forwardSubtractGreen($stream);
			$this->stats['transform_seconds'] = microtime(true) - $t0;
			$bits->bits(1, 1);
			$bits->bits(2, 2); // SUBTRACT_GREEN_TRANSFORM
		}
		$bits->bits(0, 1); // end of transforms

		$t0                              = microtime(true);
		$tokens                          = $this->tokenize($stream, $useLz77, $depth);
		$this->stats['tokenize_seconds'] = microtime(true) - $t0;
		$this->stats['token_bytes']      = strlen($tokens);

		$t0                             = microtime(true);
		$this->writeImageData($bits, $tokens);
		$this->stats['entropy_seconds'] = microtime(true) - $t0;

		$payload = "\x2f" . $bits->finish();
		$chunk   = 'VP8L' . pack('V', strlen($payload)) . $payload;
		if (0 !== (strlen($payload) & 1)) {
			$chunk .= "\0";
		}
		return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
	}

	private function forwardSubtractGreen(string $rgba): string {
		return $this->forwardSubtractGreenImpl($rgba);
	}

	/**
	 * Header-less lossless image stream: the payload of a WebP ALPH chunk with
	 * compression method 1.
	 *
	 * The container spec says the alpha bitstream "is a compressed
	 * image-stream (as described in the WebP Lossless Bitstream Format) of
	 * implicit dimensions width x height. That is, this image-stream does not
	 * contain any headers describing the image dimension."  So this emits the
	 * transform loop terminator, the colour-cache flag, the prefix codes and
	 * the pixel data -- everything encode() writes after the five-byte VP8L
	 * header, and nothing else.
	 *
	 * $alpha is one byte per pixel in scan order.  The plane is carried in the
	 * green channel because that is the component libwebp's alpha decoder
	 * reads back out of the lossless stream.
	 *
	 * @param array{lz77?: bool, depth?: int, channel?: string} $options
	 */
	public function encodeAlphaStream(int $width, int $height, string $alpha, array $options = array()): string {
		$this->width  = $width;
		$this->height = $height;
		$this->pixels = $width * $height;
		if (strlen($alpha) !== $this->pixels) {
			throw new InvalidArgumentException('alpha plane does not match the frame size');
		}

		$useLz77 = $options['lz77'] ?? true;
		$depth   = $options['depth'] ?? 8;
		$channel = $options['channel'] ?? 'g';
		$this->stats['options'] = array('lz77' => $useLz77, 'channel' => $channel, 'alpha_stream' => true);

		$rgba = $this->alphaAsRgba($alpha, $channel);

		$bits = new BitWriter();
		$bits->bits(0, 1); // end of the transform loop: no transforms

		$t0                              = microtime(true);
		$tokens                          = $this->tokenize($rgba, $useLz77, $depth);
		$this->stats['tokenize_seconds'] = microtime(true) - $t0;
		$this->stats['token_bytes']      = strlen($tokens);

		$t0                             = microtime(true);
		$this->writeImageData($bits, $tokens);
		$this->stats['entropy_seconds'] = microtime(true) - $t0;

		return $bits->finish();
	}

	/**
	 * Wrap the alpha plane as ARGB pixels with the plane in the chosen channel
	 * and the other three channels constant, so their prefix codes collapse to
	 * a single symbol and cost nothing per pixel.
	 */
	private function alphaAsRgba(string $alpha, string $channel): string {
		$n = $this->pixels;
		if ('g' === $channel) {
			$rgba = str_repeat("\x00\x00\x00\xff", $n);
			for ($i = 0; $i < $n; $i++) {
				$rgba[($i << 2) + 1] = $alpha[$i];
			}
			return $rgba;
		}
		if ('a' === $channel) {
			$rgba = str_repeat("\x00\x00\x00\x00", $n);
			for ($i = 0; $i < $n; $i++) {
				$rgba[($i << 2) + 3] = $alpha[$i];
			}
			return $rgba;
		}
		throw new InvalidArgumentException("unknown alpha channel: $channel");
	}

	private function forwardSubtractGreenImpl(string $rgba): string {
		$out = $rgba;
		for ($i = 0, $n = strlen($rgba); $i + 3 < $n; $i += 4) {
			$g           = \ord($rgba[$i + 1]);
			$out[$i]     = \chr( ( \ord($rgba[$i]) - $g ) & 0xFF );
			$out[$i + 2] = \chr( ( \ord($rgba[$i + 2]) - $g ) & 0xFF );
		}
		return $out;
	}

	/**
	 * Tokenize into literals and backward references.
	 *
	 * Record layout: 1 byte type, then 4 bytes ARGB (literal) or
	 * 2 bytes length + 4 bytes scan-line distance (copy).
	 */
	private function tokenize(string $rgba, bool $useLz77, int $depth): string {
		$n   = $this->pixels;
		$out = '';

		if (!$useLz77) {
			for ($p = 0, $i = 0; $p < $n; $p++, $i += 4) {
				// ARGB byte order inside the record: alpha, red, green, blue.
				$out .= \chr(0) . $rgba[$i + 3] . $rgba[$i] . $rgba[$i + 1] . $rgba[$i + 2];
			}
			return $out;
		}

		// 1-indexed ARGB value per pixel; this array is the memory hot spot.
		$px          = unpack('N*', $rgba);
		$head        = array_fill(0, 1 << 16, -1);
		$prev        = str_repeat("\xFF\xFF\xFF\xFF", $n);
		$p           = 0;
		$maxDistance = 1048456; // prefix coding tops out at 1048576, minus 120 plane codes
		while ($p < $n) {
			$bestLen  = 0;
			$bestDist = 0;
			if ($p + 1 < $n) {
				$key    = ( ( $px[$p + 1] * 0x1E35A7BD ) >> 16 ) & 0xFFFF;
				$cand   = $head[$key];
				$steps  = 0;
				$maxLen = min(4096, $n - $p);
				while ($cand >= 0 && $steps < $depth) {
					$dist = $p - $cand;
					if ($dist > $maxDistance) {
						break;
					}
					if ($px[$cand + 1] === $px[$p + 1]) {
						$len = 1;
						while ($len < $maxLen && $px[$cand + 1 + $len] === $px[$p + 1 + $len]) {
							$len++;
						}
						if ($len > $bestLen) {
							$bestLen  = $len;
							$bestDist = $dist;
						}
					}
					$cand = unpack('V', substr($prev, $cand * 4, 4))[1];
					if (0xFFFFFFFF === $cand) {
						$cand = -1;
					}
					$steps++;
				}
			}

			if ($bestLen >= 4) {
				$out .= \chr(1) . pack('n', $bestLen) . pack('V', $bestDist);
				for ($k = 0; $k < $bestLen; $k++) {
					$cur = $p + $k;
					if ($cur + 1 >= $n) {
						continue;
					}
					$key                = ( ( $px[$cur + 1] * 0x1E35A7BD ) >> 16 ) & 0xFFFF;
					$prev[$cur * 4]     = \chr($head[$key] & 0xFF);
					$prev[$cur * 4 + 1] = \chr( ( $head[$key] >> 8 ) & 0xFF );
					$prev[$cur * 4 + 2] = \chr( ( $head[$key] >> 16 ) & 0xFF );
					$prev[$cur * 4 + 3] = \chr( ( $head[$key] >> 24 ) & 0xFF );
					$head[$key]         = $cur;
				}
				$p += $bestLen;
				continue;
			}

			$out .= \chr(0) . $rgba[$p * 4 + 3] . $rgba[$p * 4] . $rgba[$p * 4 + 1] . $rgba[$p * 4 + 2];
			if ($p + 1 < $n) {
				$key                = ( ( $px[$p + 1] * 0x1E35A7BD ) >> 16 ) & 0xFFFF;
				$prev[$p * 4]       = \chr($head[$key] & 0xFF);
				$prev[$p * 4 + 1]   = \chr( ( $head[$key] >> 8 ) & 0xFF );
				$prev[$p * 4 + 2]   = \chr( ( $head[$key] >> 16 ) & 0xFF );
				$prev[$p * 4 + 3]   = \chr( ( $head[$key] >> 24 ) & 0xFF );
				$head[$key]         = $p;
			}
			$p++;
		}
		return $out;
	}

	/** Build the five prefix codes and emit the token stream. */
	private function writeImageData(BitWriter $bits, string $tokens): void {
		$gFreq = array_fill(0, 280, 0);
		$rFreq = array_fill(0, 256, 0);
		$bFreq = array_fill(0, 256, 0);
		$aFreq = array_fill(0, 256, 0);
		$dFreq = array_fill(0, 40, 0);

		$offset = 0;
		$len    = strlen($tokens);
		while ($offset < $len) {
			if (0 === \ord($tokens[$offset])) {
				// Literal record layout after the type byte: A R G B.
				$gFreq[\ord($tokens[$offset + 3])]++;
				$rFreq[\ord($tokens[$offset + 2])]++;
				$bFreq[\ord($tokens[$offset + 4])]++;
				$aFreq[\ord($tokens[$offset + 1])]++;
				$offset += 5;
				continue;
			}
			$length       = unpack('n', substr($tokens, $offset + 1, 2))[1];
			$distance     = unpack('V', substr($tokens, $offset + 3, 4))[1];
			[$lenPrefix]  = self::splitValue($length);
			[$distPrefix] = self::splitValue(self::planeCode($distance, $this->width));
			$gFreq[256 + $lenPrefix]++;
			$dFreq[$distPrefix]++;
			$offset += 7;
		}

		$gLengths = Huff::lengths($gFreq, 280, 15);
		$rLengths = Huff::lengths($rFreq, 256, 15);
		$bLengths = Huff::lengths($bFreq, 256, 15);
		$aLengths = Huff::lengths($aFreq, 256, 15);
		$dLengths = Huff::lengths($dFreq, 40, 15);

		// A tree with a single used symbol consumes zero bits per symbol for
		// the decoder, so the encoder must emit nothing for that channel.
		$gSingle = 1 === count(array_filter($gLengths));
		$rSingle = 1 === count(array_filter($rLengths));
		$bSingle = 1 === count(array_filter($bLengths));
		$aSingle = 1 === count(array_filter($aLengths));
		$dSingle = 1 === count(array_filter($dLengths));

		$bits->bits(0, 1); // no colour cache
		$bits->bits(0, 1); // single meta prefix code group
		Huff::writeTree($bits, $gLengths);
		Huff::writeTree($bits, $rLengths);
		Huff::writeTree($bits, $bLengths);
		Huff::writeTree($bits, $aLengths);
		Huff::writeTree($bits, $dLengths);

		$gCodes = Huff::codes($gLengths);
		$rCodes = Huff::codes($rLengths);
		$bCodes = Huff::codes($bLengths);
		$aCodes = Huff::codes($aLengths);
		$dCodes = Huff::codes($dLengths);

		$offset = 0;
		while ($offset < $len) {
			if (0 === \ord($tokens[$offset])) {
				$g = \ord($tokens[$offset + 3]);
				$r = \ord($tokens[$offset + 2]);
				$b = \ord($tokens[$offset + 4]);
				$a = \ord($tokens[$offset + 1]);
				if (!$gSingle) {
					$bits->bits($gCodes[$g][2], $gLengths[$g]);
				}
				if (!$rSingle) {
					$bits->bits($rCodes[$r][2], $rLengths[$r]);
				}
				if (!$bSingle) {
					$bits->bits($bCodes[$b][2], $bLengths[$b]);
				}
				if (!$aSingle) {
					$bits->bits($aCodes[$a][2], $aLengths[$a]);
				}
				$offset += 5;
				continue;
			}
			$length                                    = unpack('n', substr($tokens, $offset + 1, 2))[1];
			$distance                                  = unpack('V', substr($tokens, $offset + 3, 4))[1];
			[$lenPrefix, $lenExtraBits, $lenExtra]     = self::splitValue($length);
			$planeCode                                 = self::planeCode($distance, $this->width);
			[$distPrefix, $distExtraBits, $distExtra]  = self::splitValue($planeCode);
			$symbol                                    = 256 + $lenPrefix;
			$bits->bits($gCodes[$symbol][2], $gLengths[$symbol]);
			if ($lenExtraBits > 0) {
				$bits->bits($lenExtra, $lenExtraBits);
			}
			if (!$dSingle) {
				$bits->bits($dCodes[$distPrefix][2], $dLengths[$distPrefix]);
			}
			if ($distExtraBits > 0) {
				$bits->bits($distExtra, $distExtraBits);
			}
			$offset += 7;
		}
	}

	/**
	 * Split a length/distance value into prefix code plus extra bits.
	 *
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function splitValue(int $value): array {
		if ($value <= 4) {
			return array($value - 1, 0, 0);
		}
		for ($prefix = 4; $prefix <= 39; $prefix++) {
			$extraBits = ( $prefix - 2 ) >> 1;
			$offset    = ( 2 + ( $prefix & 1 ) ) << $extraBits;
			if ($value - 1 >= $offset && $value - 1 < $offset + ( 1 << $extraBits )) {
				return array($prefix, $extraBits, $value - 1 - $offset);
			}
		}
		throw new InvalidArgumentException("Value outside prefix coding range: $value");
	}

	/** The 120 neighbour offsets from the specification, in code order. */
	private static function offsets(): array {
		return array(
			array(0, 1), array(1, 0), array(1, 1), array(-1, 1), array(0, 2), array(2, 0), array(1, 2),
			array(-1, 2), array(2, 1), array(-2, 1), array(2, 2), array(-2, 2), array(0, 3), array(3, 0),
			array(1, 3), array(-1, 3), array(3, 1), array(-3, 1), array(2, 3), array(-2, 3), array(3, 2),
			array(-3, 2), array(0, 4), array(4, 0), array(1, 4), array(-1, 4), array(4, 1), array(-4, 1),
			array(3, 3), array(-3, 3), array(2, 4), array(-2, 4), array(4, 2), array(-4, 2), array(0, 5),
			array(3, 4), array(-3, 4), array(4, 3), array(-4, 3), array(5, 0), array(1, 5), array(-1, 5),
			array(5, 1), array(-5, 1), array(2, 5), array(-2, 5), array(5, 2), array(-5, 2), array(4, 4),
			array(-4, 4), array(3, 5), array(-3, 5), array(5, 3), array(-5, 3), array(0, 6), array(6, 0),
			array(1, 6), array(-1, 6), array(6, 1), array(-6, 1), array(2, 6), array(-2, 6), array(6, 2),
			array(-6, 2), array(4, 5), array(-4, 5), array(5, 4), array(-5, 4), array(3, 6), array(-3, 6),
			array(6, 3), array(-6, 3), array(0, 7), array(7, 0), array(1, 7), array(-1, 7), array(5, 5),
			array(-5, 5), array(7, 1), array(-7, 1), array(4, 6), array(-4, 6), array(6, 4), array(-6, 4),
			array(2, 7), array(-2, 7), array(7, 2), array(-7, 2), array(3, 7), array(-3, 7), array(7, 3),
			array(-7, 3), array(5, 6), array(-5, 6), array(6, 5), array(-6, 5), array(8, 0), array(4, 7),
			array(-4, 7), array(7, 4), array(-7, 4), array(8, 1), array(8, 2), array(6, 6), array(-6, 6),
			array(8, 3), array(5, 7), array(-5, 7), array(7, 5), array(-7, 5), array(8, 4), array(6, 7),
			array(-6, 7), array(7, 6), array(-7, 6), array(8, 5), array(7, 7), array(-7, 7), array(8, 6),
			array(8, 7),
		);
	}

	/** Map a scan-line distance to its distance code. */
	private static function planeCode(int $distance, int $width): int {
		static $cache = array();
		if (!isset($cache[$width])) {
			$map = array();
			foreach (self::offsets() as $index => $pair) {
				$code = $index + 1;
				$dist = $pair[0] + $pair[1] * $width;
				if ($dist < 1) {
					$dist = 1;
				}
				if (!isset($map[$dist]) || $map[$dist] > $code) {
					$map[$dist] = $code;
				}
			}
			$cache[$width] = $map;
		}
		return $cache[$width][$distance] ?? ( $distance + 120 );
	}
}
