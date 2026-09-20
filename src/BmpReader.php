<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

/**
 * Pure-PHP BMP reader.
 *
 * BMP is the legacy format a Windows host cannot avoid: Paint, the clipboard,
 * screen-capture utilities, fax and scan software and a decade of internal
 * tools all write it.  Nothing here needs GD, Imagick or libwebp, so a host
 * with no image extension at all can still read one.
 *
 * What it covers:
 *
 *  - The 12-byte OS/2 core header,
 *    BITMAPINFOHEADER, and the V2–V5 headers that add channel masks and an
 *    embedded ICC profile.
 *  - 1, 4, 8, 16, 24 and 32 bits per pixel, bottom-up or top-down rows, with
 *    the four-byte row padding BMP mandates.
 *  - Palette images, including BI_RLE8 and BI_RLE4 run-length data.
 *  - 16- and 32-bit BI_BITFIELDS / BI_ALPHABITFIELDS layouts, with the masks
 *    taken from the header or from the block that follows it, whichever the
 *    file actually has.
 *
 * An alpha mask on a 16- or 32-bit bitfields image declares transparency;
 * zero means fully transparent. BI_RGB uses fixed RGB layouts and ignores
 * channel masks, including the unused fourth byte of a 32-bit BGRX pixel.
 */
final class BmpReader
{
    private const MAX_DIMENSION = 16384;
    private const MAX_PIXELS = 40000000;
    private const MAX_ICC = 16777216;

    /** Compression methods, as they appear in the DIB header. */
    private const BI_RGB = 0;
    private const BI_RLE8 = 1;
    private const BI_RLE4 = 2;
    private const BI_BITFIELDS = 3;
    private const BI_ALPHABITFIELDS = 6;

    /** DIB header sizes that share BITMAPINFOHEADER's field layout. */
    private const INFO_HEADS = [40, 52, 56, 108, 124];

    /** Enough for a V5 header and, behind it, an out-of-band mask block. */
    private const PROBE_BYTES = 160;

    /**
     * @return array{w:int,h:int,rgb:string,alpha:?string,icc:?string,bits:int,compression:int,header:int,topDown:bool,colors:int}
     */
    public static function read(string $path): array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("cannot read image: $path");
        }

        return (new self())->decode($bytes);
    }

    /**
     * Header facts, with no pixel work: what a caller needs to know the size,
     * the storage layout and whether the file can be transparent at all.
     *
     * The whole DIB header plus the optional mask block fits in PROBE_BYTES, so
     * this is one small read even for a 100 MB scan.  An embedded ICC profile
     * is the only thing behind that window, and only a V5 header has one.
     *
     * @return array{w:int,h:int,bits:int,compression:int,headerSize:int,offBits:int,topDown:bool,
     *               alpha:bool,masks:array<int,int>,icc:?string,iccCandidates:array<int,array{0:int,1:int}>,size:int}
     */
    public static function probe(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("cannot read image: $path");
        }
        $head = (string) fread($fh, self::PROBE_BYTES);
        $stat = fstat($fh);
        $size = (int) ($stat['size'] ?? 0);
        $hdr = self::parseHeader($head, $size);

        $icc = null;
        foreach ($hdr['iccCandidates'] as [$at, $len]) {
            if (fseek($fh, $at) === 0) {
                $icc = self::iccFrom((string) fread($fh, $len), 0, $len);
                if ($icc !== null) {
                    break;
                }
            }
        }
        fclose($fh);
        $hdr['icc'] = $icc;

        return $hdr;
    }

    /**
     * @return array{w:int,h:int,rgb:string,alpha:?string,icc:?string,bits:int,compression:int,header:int,topDown:bool,colors:int}
     */
    public function decode(string $bytes): array
    {
        $hdr = self::parseHeader($bytes, strlen($bytes));
        $w = $hdr['w'];
        $h = $hdr['h'];
        $palette = $hdr['bits'] <= 8 ? self::readPalette($bytes, $hdr) : [];
        $alpha = $hdr['alpha'] ? '' : null;

        if ($hdr['compression'] === self::BI_RLE8 || $hdr['compression'] === self::BI_RLE4) {
            $rgb = self::paletteRows(self::decodeRle($bytes, $hdr), $palette);
        } else {
            $rgb = self::decodePacked($bytes, $hdr, $palette, $alpha);
        }
        if ($alpha !== null && strspn($alpha, "\xff") === strlen($alpha)) {
            $alpha = null;
        }

        $icc = null;
        foreach ($hdr['iccCandidates'] as [$at, $len]) {
            $icc = self::iccFrom($bytes, $at, $len);
            if ($icc !== null) {
                break;
            }
        }

        return [
            'w' => $w,
            'h' => $h,
            'rgb' => $rgb,
            'alpha' => $alpha,
            'icc' => $icc,
            'bits' => $hdr['bits'],
            'compression' => $hdr['compression'],
            'header' => $hdr['headerSize'],
            'topDown' => $hdr['topDown'],
            'colors' => count($palette),
        ];
    }

    /**
     * The file header, the DIB header, the out-of-band masks and the palette
     * geometry, resolved into one description of where the pixels are and how
     * they are packed.  Shared by probe() and decode() so that a skip decision
     * and a decode can never disagree about a file.
     *
     * @return array{w:int,h:int,bits:int,compression:int,headerSize:int,offBits:int,payloadOffset:int,
     *               paletteEntry:int,paletteColors:int,stride:int,topDown:bool,alpha:bool,
     *               masks:array<int,int>,iccCandidates:array<int,array{0:int,1:int}>,core:bool}
     */
    private static function parseHeader(string $bytes, int $size): array
    {
        if (strlen($bytes) < 26 || substr($bytes, 0, 2) !== 'BM') {
            throw new \InvalidArgumentException('not a BMP file');
        }
        $offBits = self::u32($bytes, 10);
        $dibSize = self::u32($bytes, 14);
        $core = $dibSize === 12;
        if (!$core && !in_array($dibSize, self::INFO_HEADS, true)) {
            throw new \InvalidArgumentException("unsupported BMP header size $dibSize");
        }
        if (strlen($bytes) < 14 + $dibSize) {
            throw new \InvalidArgumentException('truncated BMP header');
        }

        $masks = [0, 0, 0, 0];
        if ($core) {
            $w = self::u16($bytes, 18);
            $h = self::u16($bytes, 20);
            $bits = self::u16($bytes, 24);
            $compression = self::BI_RGB;
            $clrUsed = 0;
        } else {
            // The height is signed: a negative value is how a BMP says its
            // rows are stored top-down instead of bottom-up.
            $w = self::i32($bytes, 18);
            $h = self::i32($bytes, 22);
            $bits = self::u16($bytes, 28);
            $compression = self::u32($bytes, 30);
            $clrUsed = self::u32($bytes, 46);
            if ($dibSize >= 52) {
                $masks = [
                    self::u32($bytes, 54),
                    self::u32($bytes, 58),
                    self::u32($bytes, 62),
                    $dibSize >= 56 ? self::u32($bytes, 66) : 0,
                ];
            }
        }
        $topDown = $h < 0;
        if ($topDown) {
            $h = -$h;
        }

        if ($w < 1 || $h < 1 || $w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION || $w * $h > self::MAX_PIXELS) {
            throw new \InvalidArgumentException("BMP dimensions out of range: {$w}x{$h}");
        }
        if (!in_array($bits, [1, 4, 8, 16, 24, 32], true)) {
            throw new \InvalidArgumentException("unsupported BMP bit depth: $bits");
        }

        // Masks normally live in the V2+ header, but the classic 40-byte
        // header has nowhere to put them and carries them immediately behind
        // itself instead.  Both spellings are common; accept either.
        $payload = 14 + $dibSize;
        $headerMasks = ($masks[0] | $masks[1] | $masks[2] | $masks[3]) !== 0;
        if (($compression === self::BI_BITFIELDS || $compression === self::BI_ALPHABITFIELDS) && !$headerMasks) {
            $count = $compression === self::BI_ALPHABITFIELDS ? 4 : 3;
            if (strlen($bytes) < $payload + $count * 4) {
                throw new \InvalidArgumentException('truncated BMP channel masks');
            }
            for ($i = 0; $i < $count; $i++) {
                $masks[$i] = self::u32($bytes, $payload + $i * 4);
            }
            $payload += $count * 4;
        }
        // BI_RGB has fixed channel layouts, even when a V4/V5 header leaves
        // unused masks populated. A 24-bit pixel is always a BGR triplet;
        // accept writers that label it BITFIELDS but do not apply the masks.
        if ($compression === self::BI_RGB || $bits === 24) {
            $masks = match ($bits) {
                16 => [0x7C00, 0x03E0, 0x001F, 0],        // XRGB1555
                24 => [0xFF0000, 0x00FF00, 0x0000FF, 0],
                32 => [0x00FF0000, 0x0000FF00, 0x000000FF, 0], // BGRX, opaque
                default => [0, 0, 0, 0],
            };
        }

        // A palette is only meaningful at eight bits and below; at 16 and up
        // the samples are colours and clrUsed, when set at all, describes an
        // optional acceleration table nothing here needs.
        $paletteEntry = $core ? 3 : 4;
        $paletteColors = $bits <= 8 ? ($clrUsed > 0 ? $clrUsed : (1 << $bits)) : 0;
        if ($offBits < $payload) {
            // A zero or nonsense offset: the palette/mask block is what really
            // follows the header, so trust the header's own arithmetic.
            $offBits = $payload;
        }
        if ($size > 0 && $offBits > $size) {
            throw new \InvalidArgumentException('BMP pixel offset is past the end of the file');
        }

        $stride = 0;
        if ($compression === self::BI_RLE8 && $bits !== 8) {
            throw new \InvalidArgumentException('BI_RLE8 data on a BMP that is not 8 bits deep');
        }
        if ($compression === self::BI_RLE4 && $bits !== 4) {
            throw new \InvalidArgumentException('BI_RLE4 data on a BMP that is not 4 bits deep');
        }
        if ($compression === self::BI_RGB || $compression === self::BI_BITFIELDS
            || $compression === self::BI_ALPHABITFIELDS) {
            // Rows are padded out to a four-byte boundary, which is the one
            // piece of arithmetic every BMP reader has to get right.
            $stride = ((($bits * $w) + 31) >> 5) << 2;
            if ($size > 0 && $size - $offBits < $stride * $h) {
                throw new \InvalidArgumentException('truncated BMP pixel data');
            }
        } elseif ($compression !== self::BI_RLE8 && $compression !== self::BI_RLE4) {
            $named = match ($compression) {
                4 => 'an embedded JPEG',
                5 => 'an embedded PNG',
                default => "compression method $compression",
            };
            throw new \InvalidArgumentException("BMP holds $named, which this reader does not decode");
        }

        // BITMAPV5HEADER can embed a profile, with the byte offsets stored in
        // the header.  The colour-space tag is the DWORD 'MBED', which on disk
        // is the four bytes DEBM; 'LINK' (a path on the authoring machine) is
        // useless here, so only an embedded profile is taken.  The offset is
        // documented as relative to the DIB header, but writers that store an
        // absolute file offset are common enough that both are offered and the
        // profile's own signature decides which one is real.
        $iccCandidates = [];
        $tag = substr($bytes, 70, 4);
        if ($dibSize === 124 && ($tag === 'DEBM' || $tag === 'MBED')) {
            $field = self::u32($bytes, 126);
            $len = self::u32($bytes, 130);
            if ($len >= 132 && $len <= self::MAX_ICC) {
                foreach ([14 + $field, $field] as $at) {
                    if ($at > 0 && ($size === 0 || $at + $len <= $size)) {
                        $iccCandidates[] = [$at, $len];
                    }
                }
            }
        }

        return [
            'w' => $w,
            'h' => $h,
            'bits' => $bits,
            'compression' => $compression,
            'headerSize' => $dibSize,
            'offBits' => $offBits,
            'payloadOffset' => $payload,
            'paletteEntry' => $paletteEntry,
            'paletteColors' => $paletteColors,
            'stride' => $stride,
            'topDown' => $topDown,
            // An alpha mask only means something if the pixels have room for
            // it: writers that emit one header for every depth leave the mask
            // set on 24-bit files, whose samples hold no alpha at all.
            'alpha' => in_array($bits, [16, 32], true) && $masks[3] !== 0 && ($masks[3] >> $bits) === 0,
            'masks' => $masks,
            'iccCandidates' => $iccCandidates,
            'core' => $core,
            'size' => $size,
        ];
    }

    /**
     * Take a profile out of a buffer, if what is there really is one.
     *
     * An ICC profile states its own length in the first four bytes and carries
     * the 'acsp' signature at byte 36.  Requiring both is what lets the two
     * possible bases for bV5ProfileData be told apart instead of guessed at,
     * and it keeps a bad offset from handing the encoder a slice of pixel data
     * labelled as a colour profile.
     */
    private static function iccFrom(string $bytes, int $at, int $len): ?string
    {
        if ($at < 0 || $len < 132 || $at + $len > strlen($bytes)) {
            return null;
        }
        $data = substr($bytes, $at, $len);
        $declared = unpack('N', substr($data, 0, 4))[1];
        if ($declared < 132 || $declared > $len || substr($data, 36, 4) !== 'acsp') {
            return null;
        }

        return substr($data, 0, $declared);
    }

    /**
     * Palette entries as RGB strings, in file order.
     *
     * Entries are four bytes (blue, green, red, reserved) behind a V2+ header
     * and three bytes behind the OS/2 core header.  A file that claims more
     * entries than it stores simply gets the ones it has: indices past the end
     * come out black rather than throwing, which is what viewers do.
     *
     * @param array<string,mixed> $hdr
     * @return array<int,string>
     */
    private static function readPalette(string $bytes, array $hdr): array
    {
        $want = (int) $hdr['paletteColors'];
        if ($want === 0) {
            return [];
        }
        $entry = (int) $hdr['paletteEntry'];
        $at = (int) $hdr['payloadOffset'];
        $room = min((int) $hdr['offBits'] - $at, strlen($bytes) - $at);
        $count = max(0, min($want, intdiv($room, $entry)));
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $o = $at + $i * $entry;
            $out[] = $bytes[$o + 2] . $bytes[$o + 1] . $bytes[$o];
        }

        return $out;
    }

    /**
     * Bitmaps stored as rows: 24- and 32-bit colours, 16-bit masked colours
     * and 1/4/8-bit palette indices.
     *
     * @param array<string,mixed> $hdr
     * @param array<int,string> $palette
     */
    private static function decodePacked(string $bytes, array $hdr, array $palette, ?string &$alpha): string
    {
        $w = (int) $hdr['w'];
        $h = (int) $hdr['h'];
        $bits = (int) $hdr['bits'];
        $stride = (int) $hdr['stride'];
        $base = (int) $hdr['offBits'];
        /** @var array<int,int> $masks */
        $masks = $hdr['masks'];
        $rgb = '';

        /** The stored row for output row $y: bottom-up unless the height was negative. */
        $rowOf = static fn (int $y): int => $hdr['topDown'] ? $y : $h - 1 - $y;

        if ($bits === 24 && $masks[0] === 0xFF0000 && $masks[1] === 0x00FF00 && $masks[2] === 0x0000FF) {
            for ($y = 0; $y < $h; $y++) {
                $row = substr($bytes, $base + $rowOf($y) * $stride, $w * 3);
                for ($i = 0; $i < $w; $i++) {
                    $o = $i * 3;
                    $rgb .= $row[$o + 2] . $row[$o + 1] . $row[$o];
                }
            }

            return $rgb;
        }

        if ($bits === 32 && $masks[0] === 0x00FF0000 && $masks[1] === 0x0000FF00
            && $masks[2] === 0x000000FF && ($masks[3] === 0 || $masks[3] === 0xFF000000)) {
            for ($y = 0; $y < $h; $y++) {
                $row = substr($bytes, $base + $rowOf($y) * $stride, $w * 4);
                for ($i = 0; $i < $w; $i++) {
                    $o = $i * 4;
                    $rgb .= $row[$o + 2] . $row[$o + 1] . $row[$o];
                    if ($alpha !== null) {
                        $alpha .= $row[$o + 3];
                    }
                }
            }

            return $rgb;
        }

        if ($bits <= 8) {
            for ($y = 0; $y < $h; $y++) {
                $row = substr($bytes, $base + $rowOf($y) * $stride, $stride);
                for ($i = 0; $i < $w; $i++) {
                    if ($bits === 8) {
                        $index = ord($row[$i]);
                    } elseif ($bits === 4) {
                        $byte = ord($row[$i >> 1]);
                        $index = ($i & 1) === 0 ? ($byte >> 4) : ($byte & 0x0F);
                    } else {
                        $index = (ord($row[$i >> 3]) >> (7 - ($i & 7))) & 1;
                    }
                    $rgb .= $palette[$index] ?? "\x00\x00\x00";
                }
            }

            return $rgb;
        }

        // Everything else: pull each channel out of the packed sample by its
        // mask, scaling whatever range the bits hold up to a full byte.
        $r = self::channel($masks[0]);
        $g = self::channel($masks[1]);
        $b = self::channel($masks[2]);
        $a = $alpha === null ? null : self::channel($masks[3]);
        $sampleBytes = $bits >> 3;
        for ($y = 0; $y < $h; $y++) {
            $row = substr($bytes, $base + $rowOf($y) * $stride, $w * $sampleBytes);
            for ($i = 0; $i < $w; $i++) {
                $o = $i * $sampleBytes;
                $sample = ord($row[$o]);
                if ($sampleBytes > 1) {
                    $sample |= ord($row[$o + 1]) << 8;
                }
                if ($sampleBytes > 2) {
                    $sample |= (ord($row[$o + 2]) << 16) | (ord($row[$o + 3]) << 24);
                }
                $rgb .= self::channelByte($sample, $r) . self::channelByte($sample, $g) . self::channelByte($sample, $b);
                if ($a !== null) {
                    $alpha .= self::channelByte($sample, $a);
                }
            }
        }

        return $rgb;
    }

    /**
     * BI_RLE8 / BI_RLE4: expand the run-length stream into palette indices.
     *
     * The two encodings differ only in how a run's pixels are taken out of the
     * byte: RLE8 repeats one index, RLE4 alternates the high and low nibbles.
     * Both use the same four escapes (end of line, end of bitmap, delta,
     * absolute run).  Rows run bottom-up unless the height was negative, and
     * the stream ends at the end-of-bitmap escape. Incomplete commands are
     * rejected so a damaged input cannot silently turn into a partial image.
     */
    private static function decodeRle(string $bytes, array $hdr): string
    {
        $w = (int) $hdr['w'];
        $h = (int) $hdr['h'];
        $nibbles = $hdr['compression'] === self::BI_RLE4;
        $plane = str_repeat("\x00", $w * $h);
        $advance = $hdr['topDown'] ? 1 : -1;
        $x = 0;
        $y = $hdr['topDown'] ? 0 : $h - 1;
        $at = (int) $hdr['offBits'];
        $end = strlen($bytes);

        while ($at + 2 <= $end) {
            $count = ord($bytes[$at]);
            $value = ord($bytes[$at + 1]);
            $at += 2;

            if ($count > 0) {
                for ($i = 0; $i < $count; $i++) {
                    self::plot($plane, $w, $h, $x++, $y, $nibbles
                        ? (($i & 1) === 0 ? ($value >> 4) : ($value & 0x0F))
                        : $value);
                }
                continue;
            }
            if ($value === 0) {
                $x = 0;
                $y += $advance;
                continue;
            }
            if ($value === 1) {
                return $plane;
            }
            if ($value === 2) {
                if ($at + 2 > $end) {
                    throw new \InvalidArgumentException('truncated BMP RLE data');
                }
                $x += ord($bytes[$at]);
                $y += $advance * ord($bytes[$at + 1]);
                $at += 2;
                continue;
            }
            // Absolute run: $value literal pixels, padded to an even number
            // of bytes so the next run starts on a word boundary.
            $literal = $nibbles ? intdiv($value + 1, 2) : $value;
            if ($at + $literal + ($literal & 1) > $end) {
                throw new \InvalidArgumentException('truncated BMP RLE data');
            }
            for ($i = 0; $i < $value; $i++) {
                self::plot($plane, $w, $h, $x++, $y, $nibbles
                    ? (($i & 1) === 0 ? (ord($bytes[$at + ($i >> 1)]) >> 4) : (ord($bytes[$at + ($i >> 1)]) & 0x0F))
                    : ord($bytes[$at + $i]));
            }
            $at += $literal + ($literal & 1);
        }

        throw new \InvalidArgumentException('truncated BMP RLE data');
    }

    /** Write one palette index into the index plane, ignoring a stream that runs off the edge. */
    private static function plot(string &$plane, int $w, int $h, int $x, int $y, int $index): void
    {
        if ($x >= 0 && $x < $w && $y >= 0 && $y < $h) {
            $plane[$y * $w + $x] = chr($index);
        }
    }

    /**
     * An index plane through the palette: the shared tail of both RLE paths.
     *
     * @param array<int,string> $palette
     */
    private static function paletteRows(string $plane, array $palette): string
    {
        $rgb = '';
        $n = strlen($plane);
        for ($i = 0; $i < $n; $i++) {
            $rgb .= $palette[ord($plane[$i])] ?? "\x00\x00\x00";
        }

        return $rgb;
    }

    /**
     * A channel mask resolved to "shift right, then scale its range up to 255".
     *
     * BMP does not say how a 5- or 6-bit channel should be widened, and the
     * decoders disagree by a step here and there: on the same 32-value ramp,
     * libwebp-era tools, ImageMagick and Pillow each land on slightly
     * different tables.  Rounding to nearest is the honest reading — every
     * level is the closest byte — and the difference from any other decoder is
     * at most one step on a handful of levels.
     *
     * @return array{0:int,1:int,2:int} [shift, max, rounding]
     */
    private static function channel(int $mask): array
    {
        if ($mask === 0) {
            return [0, 0, 0];
        }
        $shift = 0;
        while ((($mask >> $shift) & 1) === 0 && $shift < 32) {
            $shift++;
        }
        $max = $mask >> $shift;

        return [$shift, $max, $max >> 1];
    }

    /**
     * @param array{0:int,1:int,2:int} $channel
     */
    private static function channelByte(int $sample, array $channel): string
    {
        [$shift, $max, $round] = $channel;
        if ($max === 0) {
            return "\x00";
        }
        $value = ($sample >> $shift) & $max;

        return $value >= $max ? "\xff" : chr(intdiv($value * 255 + $round, $max));
    }

    private static function u16(string $bytes, int $at): int
    {
        return unpack('v', substr($bytes, $at, 2))[1];
    }

    private static function u32(string $bytes, int $at): int
    {
        return unpack('V', substr($bytes, $at, 4))[1];
    }

    private static function i32(string $bytes, int $at): int
    {
        $value = self::u32($bytes, $at);

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
