<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

require_once __DIR__ . '/ImageMeta.php';

/**
 * Pure-PHP PNG reader: every colour type, every bit depth, interlaced or not.
 *
 * PNG is a good fit for a bundled reader.  The expensive half of the format —
 * the DEFLATE container — is already inside PHP's zlib extension, so what is
 * left is chunk framing, unfiltering and sample expansion, all of which the
 * specification pins down exactly.  The result is byte-identical to what a
 * native decoder produces, including the alpha channel, which libgd never
 * gives you at better than seven bits.
 *
 * The whole image is returned as two packed strings (RGB, and alpha when the
 * source has any), never an array per pixel.
 */
final class PngReader
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";
    private const MAX_PIXELS = 40000000;
    private const MAX_DIMENSION = 16384;
    private const MAX_IDAT_BYTES = 134217728;

    /** Adam7 pass geometry: [xStart, yStart, xStep, yStep]. */
    private const ADAM7 = [
        [0, 0, 8, 8],
        [4, 0, 8, 8],
        [0, 4, 4, 8],
        [2, 0, 4, 4],
        [0, 2, 2, 4],
        [1, 0, 2, 2],
        [0, 1, 1, 2],
    ];

    private int $colorType = 0;
    private int $bitDepth = 8;
    private int $channels = 1;
    private string $palette = '';
    private string $paletteTrns = '';
    private int $trnsGrey = -1;
    /** @var array<int,int> */
    private array $trnsRgb = [-1, -1, -1];
    private bool $alphaPossible = false;

    /**
     * @return array{w:int,h:int,rgb:string,alpha:?string,icc:?string,colorType:int,bitDepth:int,interlaced:bool}
     */
    public static function read(string $path): array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("cannot read image: $path");
        }
        if (!function_exists('gzuncompress')) {
            throw new \RuntimeException('PNG input requires the PHP zlib extension');
        }

        return (new self())->decode($bytes);
    }

    /**
     * @return array{w:int,h:int,rgb:string,alpha:?string,icc:?string,colorType:int,bitDepth:int,interlaced:bool}
     */
    public function decode(string $bytes): array
    {
        [$ihdr, $palette, $trns, $icc, $idat] = $this->walkChunks($bytes);

        /** @var array<string,int> $header */
        $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $ihdr);
        $w = (int) $header['width'];
        $h = (int) $header['height'];
        $this->bitDepth = (int) $header['bitDepth'];
        $this->colorType = (int) $header['colorType'];
        $interlaced = (int) $header['interlace'];

        if ($header['compression'] !== 0 || $header['filter'] !== 0 || $interlaced > 1) {
            throw new \InvalidArgumentException('unsupported PNG compression/filter/interlace method');
        }
        if ($w < 1 || $h < 1 || $w > self::MAX_DIMENSION || $h > self::MAX_DIMENSION || $w * $h > self::MAX_PIXELS) {
            throw new \InvalidArgumentException("PNG dimensions out of range: {$w}x{$h}");
        }

        $depths = [0 => [1, 2, 4, 8, 16], 2 => [8, 16], 3 => [1, 2, 4, 8], 4 => [8, 16], 6 => [8, 16]];
        if (!isset($depths[$this->colorType]) || !in_array($this->bitDepth, $depths[$this->colorType], true)) {
            throw new \InvalidArgumentException(
                "unsupported PNG colour type {$this->colorType} at bit depth {$this->bitDepth}"
            );
        }
        $this->channels = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4][$this->colorType];
        if ($this->colorType === 3 && strlen($palette) < 3) {
            throw new \InvalidArgumentException('palette PNG without a PLTE chunk');
        }
        if ($this->colorType === 3 && strlen($palette) % 3 !== 0) {
            throw new \InvalidArgumentException('PLTE length is not a multiple of three');
        }
        $this->palette = $palette;
        $this->paletteTrns = ($this->colorType === 3 && $trns !== null) ? $trns : '';

        // tRNS carries a key colour for colour types 0 and 2, and per-entry
        // alpha for colour type 3.  It is meaningless for types 4 and 6, which
        // already have an alpha channel.
        if ($trns !== null) {
            if ($this->colorType === 0 && strlen($trns) >= 2) {
                $this->trnsGrey = (ord($trns[0]) << 8) | ord($trns[1]);
            } elseif ($this->colorType === 2 && strlen($trns) >= 6) {
                $t = unpack('n3', substr($trns, 0, 6));
                $this->trnsRgb = [(int) $t[1], (int) $t[2], (int) $t[3]];
            }
        }
        $this->alphaPossible = $this->colorType === 4 || $this->colorType === 6
            || ($this->colorType === 3 && $this->paletteTrns !== '')
            || ($this->colorType === 0 && $this->trnsGrey >= 0)
            || ($this->colorType === 2 && $this->trnsRgb[0] >= 0);

        $bitsPerPixel = $this->channels * $this->bitDepth;
        $bpp = max(1, intdiv($bitsPerPixel + 7, 8));
        $passes = $interlaced === 1 ? self::ADAM7 : [[0, 0, 1, 1]];

        // Work out the exact inflated size before inflating, so a malicious
        // stream cannot ask for an unbounded allocation.
        $geometry = [];
        $expected = 0;
        foreach ($passes as [$xs, $ys, $xStep, $yStep]) {
            $pw = intdiv($w - $xs + $xStep - 1, $xStep);
            $ph = intdiv($h - $ys + $yStep - 1, $yStep);
            if ($pw <= 0 || $ph <= 0) {
                $geometry[] = null;
                continue;
            }
            $rowBytes = intdiv($pw * $bitsPerPixel + 7, 8);
            $expected += ($rowBytes + 1) * $ph;
            $geometry[] = [$pw, $ph, $rowBytes];
        }

        $raw = @gzuncompress($idat, $expected);
        unset($idat);
        if (!is_string($raw) || strlen($raw) !== $expected) {
            throw new \InvalidArgumentException('PNG image data does not inflate to the expected size');
        }

        $rgb = '';
        $alpha = '';
        $rgbRows = $interlaced === 1 ? array_fill(0, $h, str_repeat("\x00", $w * 3)) : null;
        $alphaRows = ($interlaced === 1 && $this->alphaPossible) ? array_fill(0, $h, str_repeat("\xff", $w)) : null;
        $pos = 0;

        foreach ($passes as $p => [$xs, $ys, $xStep, $yStep]) {
            $geom = $geometry[$p];
            if ($geom === null) {
                continue;
            }
            [$pw, $ph, $rowBytes] = $geom;
            $previous = str_repeat("\x00", $rowBytes);
            for ($py = 0; $py < $ph; $py++) {
                $filter = ord($raw[$pos]);
                $row = substr($raw, $pos + 1, $rowBytes);
                $pos += $rowBytes + 1;
                if ($filter === 0) {
                    // no prediction: nothing to reconstruct
                } elseif ($filter <= 4) {
                    $row = self::unfilter($row, $filter, $bpp, $previous);
                } else {
                    throw new \InvalidArgumentException("unknown PNG scanline filter $filter");
                }
                $previous = $row;

                [$rowRgb, $rowAlpha] = $this->expandRow($row, $pw);

                if ($interlaced !== 1) {
                    $rgb .= $rowRgb;
                    if ($rowAlpha !== null) {
                        $alpha .= $rowAlpha;
                    }
                    continue;
                }

                $y = $ys + $py * $yStep;
                if ($xs === 0 && $xStep === 1) {
                    $rgbRows[$y] = $rowRgb;
                    if ($rowAlpha !== null && $alphaRows !== null) {
                        $alphaRows[$y] = $rowAlpha;
                    }
                    continue;
                }
                $target = $rgbRows[$y];
                $at = 0;
                for ($i = 0; $i < $pw; $i++) {
                    $x = ($xs + $i * $xStep) * 3;
                    $target[$x] = $rowRgb[$at];
                    $target[$x + 1] = $rowRgb[$at + 1];
                    $target[$x + 2] = $rowRgb[$at + 2];
                    $at += 3;
                }
                $rgbRows[$y] = $target;
                if ($rowAlpha !== null && $alphaRows !== null) {
                    $targetAlpha = $alphaRows[$y];
                    for ($i = 0; $i < $pw; $i++) {
                        $targetAlpha[$xs + $i * $xStep] = $rowAlpha[$i];
                    }
                    $alphaRows[$y] = $targetAlpha;
                }
            }
        }

        if ($interlaced === 1) {
            $rgb = implode('', $rgbRows ?? []);
            $alpha = implode('', $alphaRows ?? []);
        }

        // A source may be *allowed* an alpha channel and still be entirely
        // opaque; only code an ALPH chunk when something is actually see-through.
        $hasAlpha = $alpha !== '' && strspn($alpha, "\xff") !== strlen($alpha);

        return [
            'w' => $w,
            'h' => $h,
            'rgb' => $rgb,
            'alpha' => $hasAlpha ? $alpha : null,
            'icc' => $icc,
            'colorType' => $this->colorType,
            'bitDepth' => $this->bitDepth,
            'interlaced' => $interlaced === 1,
        ];
    }

    /**
     * @return array{0:string,1:string,2:?string,3:?string,4:string} [IHDR, PLTE, tRNS, ICC, IDAT]
     */
    private function walkChunks(string $bytes): array
    {
        $size = strlen($bytes);
        if ($size < 8 || substr($bytes, 0, 8) !== self::SIGNATURE) {
            throw new \InvalidArgumentException('not a PNG file');
        }
        $offset = 8;
        $ihdr = null;
        $palette = '';
        $trns = null;
        $icc = null;
        $idat = '';
        $idatBytes = 0;
        $sawIend = false;

        while ($offset + 12 <= $size) {
            $len = unpack('N', substr($bytes, $offset, 4))[1];
            if ($len > $size - $offset - 12) {
                throw new \InvalidArgumentException('truncated PNG chunk');
            }
            $type = substr($bytes, $offset + 4, 4);
            $data = substr($bytes, $offset + 8, $len);
            $offset += 8 + $len;
            if (crc32($type . $data) !== unpack('N', substr($bytes, $offset, 4))[1]) {
                throw new \InvalidArgumentException("PNG chunk CRC mismatch in $type");
            }
            $offset += 4;

            switch ($type) {
                case 'IHDR':
                    if ($ihdr !== null || $len !== 13) {
                        throw new \InvalidArgumentException('invalid or duplicate PNG IHDR');
                    }
                    $ihdr = $data;
                    break;
                case 'PLTE':
                    $palette = $data;
                    break;
                case 'tRNS':
                    $trns = $data;
                    break;
                case 'iCCP':
                    $icc = ImageMeta::decodeIccp($data);
                    break;
                case 'IDAT':
                    $idatBytes += $len;
                    if ($idatBytes > self::MAX_IDAT_BYTES) {
                        throw new \RuntimeException('PNG image data is unreasonably large');
                    }
                    $idat .= $data;
                    break;
                case 'IEND':
                    $sawIend = true;
                    break 2;
                default:
                    break; // ancillary chunks (gAMA, pHYs, tEXt, acTL, ...) are ignored
            }
        }

        if ($ihdr === null) {
            throw new \InvalidArgumentException('PNG has no IHDR chunk');
        }
        if (!$sawIend) {
            throw new \InvalidArgumentException('PNG is truncated or missing IEND');
        }
        if ($idat === '') {
            throw new \InvalidArgumentException('PNG has no image data');
        }

        return [$ihdr, $palette, $trns, $icc, $idat];
    }

    /**
     * Undo one scanline filter.  Filters are defined against the *already
     * reconstructed* left neighbour, so this walks the row in place.
     */
    private static function unfilter(string $row, int $filter, int $bpp, string $previous): string
    {
        $n = strlen($row);
        $out = $row;
        for ($i = 0; $i < $n; $i++) {
            $left = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $up = ord($previous[$i]);
            $upLeft = $i >= $bpp ? ord($previous[$i - $bpp]) : 0;
            $prediction = match ($filter) {
                1 => $left,
                2 => $up,
                3 => ($left + $up) >> 1,
                4 => self::paeth($left, $up, $upLeft),
                default => 0,
            };
            if ($prediction !== 0) {
                $out[$i] = chr((ord($row[$i]) + $prediction) & 0xFF);
            }
        }

        return $out;
    }

    private static function paeth(int $left, int $up, int $upperLeft): int
    {
        $p = $left + $up - $upperLeft;
        $pa = abs($p - $left);
        $pb = abs($p - $up);
        $pc = abs($p - $upperLeft);
        if ($pa <= $pb && $pa <= $pc) {
            return $left;
        }

        return $pb <= $pc ? $up : $upperLeft;
    }

    /**
     * Turn one unfiltered scanline into 8-bit RGB (and, when the source can be
     * translucent, alpha).
     *
     * @return array{0:string,1:?string} [rgb row, alpha row or null]
     */
    private function expandRow(string $row, int $pixels): array
    {
        $alpha = $this->alphaPossible ? '' : null;

        if ($this->bitDepth < 8) {
            return [$this->expandPacked($row, $pixels, $alpha), $alpha];
        }

        // The one case that needs no work at all: opaque 8-bit RGB.
        if ($this->colorType === 2 && $this->bitDepth === 8 && $alpha === null) {
            return [$row, null];
        }

        $step = $this->bitDepth === 16 ? 2 : 1;
        $stride = $this->channels * $step;
        $rgb = '';
        $offset = 0;
        for ($i = 0; $i < $pixels; $i++) {
            switch ($this->colorType) {
                case 0:
                    $g = ord($row[$offset]);
                    $rgb .= $g === 0 ? "\x00\x00\x00" : chr($g) . chr($g) . chr($g);
                    if ($alpha !== null) {
                        $sample = $this->bitDepth === 16
                            ? ((ord($row[$offset]) << 8) | ord($row[$offset + 1]))
                            : $g;
                        $alpha .= $sample === $this->trnsGrey ? "\x00" : "\xff";
                    }
                    break;
                case 2:
                    $r = ord($row[$offset]);
                    // 16-bit samples are two bytes, so the channel stride is
                    // the sample size, not one.
                    $g = ord($row[$offset + $step]);
                    $b = ord($row[$offset + 2 * $step]);
                    $rgb .= chr($r) . chr($g) . chr($b);
                    if ($alpha !== null) {
                        if ($this->bitDepth === 16) {
                            $match = ((ord($row[$offset]) << 8) | ord($row[$offset + 1])) === $this->trnsRgb[0]
                                && ((ord($row[$offset + 2]) << 8) | ord($row[$offset + 3])) === $this->trnsRgb[1]
                                && ((ord($row[$offset + 4]) << 8) | ord($row[$offset + 5])) === $this->trnsRgb[2];
                        } else {
                            $match = $r === $this->trnsRgb[0] && $g === $this->trnsRgb[1] && $b === $this->trnsRgb[2];
                        }
                        $alpha .= $match ? "\x00" : "\xff";
                    }
                    break;
                case 3:
                    $index = ord($row[$offset]);
                    $at = $index * 3;
                    if ($at + 2 >= strlen($this->palette)) {
                        throw new \InvalidArgumentException('PNG palette index out of range');
                    }
                    $rgb .= $this->palette[$at] . $this->palette[$at + 1] . $this->palette[$at + 2];
                    if ($alpha !== null) {
                        $alpha .= $this->paletteTrns[$index] ?? "\xff";
                    }
                    break;
                case 4:
                    $g = ord($row[$offset]);
                    $rgb .= $g === 0 ? "\x00\x00\x00" : chr($g) . chr($g) . chr($g);
                    if ($alpha !== null) {
                        $alpha .= $row[$offset + $step];
                    }
                    break;
                default: // 6, RGBA
                    $rgb .= $row[$offset] . $row[$offset + $step] . $row[$offset + 2 * $step];
                    if ($alpha !== null) {
                        $alpha .= $row[$offset + 3 * $step];
                    }
                    break;
            }
            $offset += $stride;
        }

        return [$rgb, $alpha];
    }

    /**
     * Grey and palette images at 1, 2 and 4 bits per pixel.
     */
    private function expandPacked(string $row, int $pixels, ?string &$alpha): string
    {
        $depth = $this->bitDepth;
        $mask = (1 << $depth) - 1;
        $perByte = intdiv(8, $depth);
        $scale = $depth === 1 ? 255 : ($depth === 2 ? 85 : 17);
        $grey = $this->colorType === 0;
        $rgb = '';
        for ($i = 0; $i < $pixels; $i++) {
            $byte = ord($row[intdiv($i, $perByte)]);
            $value = ($byte >> (8 - $depth * (($i % $perByte) + 1))) & $mask;
            if ($grey) {
                if ($value === 0) {
                    $rgb .= "\x00\x00\x00";
                } else {
                    $level = chr($value * $scale);
                    $rgb .= $level . $level . $level;
                }
                if ($alpha !== null) {
                    $alpha .= $value === $this->trnsGrey ? "\x00" : "\xff";
                }
                continue;
            }
            $at = $value * 3;
            if ($at + 2 >= strlen($this->palette)) {
                throw new \InvalidArgumentException('PNG palette index out of range');
            }
            $rgb .= $this->palette[$at] . $this->palette[$at + 1] . $this->palette[$at + 2];
            if ($alpha !== null) {
                $alpha .= $this->paletteTrns[$value] ?? "\xff";
            }
        }

        return $rgb;
    }
}
