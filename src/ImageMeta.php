<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

require_once __DIR__ . '/BmpReader.php';

/**
 * Header-level facts about a source image, read without decoding it.
 *
 * Two of these are correctness fixes, not optimisations:
 *
 *  - `tRNS` on a colour-type 0/2 PNG.  GD ignores that chunk, so an image
 *    whose transparency is expressed as "this exact RGB value is a hole"
 *    came back fully opaque and was coded that way, silently.
 *  - `iCCP` / JPEG `APP2`.  The encoder could already write an 'ICCP' chunk,
 *    but nothing ever handed it a profile, so the colour space of every
 *    converted image was dropped on the floor.
 *
 * Walking the chunk list also fixes a subtler bug: the previous alpha sniff
 * looked for the literal string "tRNS" in the first 4 KiB, which a large
 * `iCCP` chunk sitting in front of it would push out of the window.
 *
 * It also answers the one question the rest of the package asks before doing
 * any work: is this file already a WebP?  That is decided by the bytes, not by
 * the extension, and the size comes out of the container header so a skipped
 * input can still be reported without decoding it.
 *
 * BMP is read through BmpReader's header walk so channel masks and embedded
 * profiles are available alongside the dimensions reported by getimagesize().
 */
final class ImageMeta
{
    /** PNG colour types, IHDR byte 9. */
    public const GREY = 0;
    public const TRUE = 2;
    public const PALETTE = 3;
    public const GREY_ALPHA = 4;
    public const RGBA = 6;

    /** Refuse to decompress an absurd iCCP chunk. */
    private const ICC_LIMIT = 16777216;
    /** tRNS is at most 256 entries; anything longer is not one we understand. */
    private const TRNS_LIMIT = 1024;
    /** A JPEG APP2 segment holds at most this much after the 14-byte header. */
    private const JPEG_APP2_MAX = 65533 - 14;
    /** Enough of a RIFF header to see WEBP and the picture header behind it. */
    private const HEAD_BYTES = 32;

    /** One of the IMAGETYPE_* constants. */
    public int $type;
    public int $width;
    public int $height;
    /** PNG colour type, or -1 for other formats. */
    public int $colorType;
    /** PNG bits per channel or BMP bits per pixel; -1 for other formats. */
    public int $bitDepth;
    /** Raw tRNS payload, or null when the PNG has none. */
    public ?string $trns;
    /** Decompressed ICC profile, or null. */
    public ?string $icc;
    /** For a WebP source, the RIFF chunk carrying the image: VP8X, VP8 or VP8L. */
    public ?string $webpChunk;
    /** BMP compression method (0 BI_RGB, 1 RLE8, 2 RLE4, 3 bitfields, 6 alpha bitfields), or -1. */
    public int $bmpCompression;
    /** BMP: the header declares a real alpha channel. */
    public bool $bmpAlpha;

    /** @var array<int,int>|null resolved tRNS key colour */
    private ?array $trnsKey = null;
    private bool $trnsResolved = false;

    public function __construct(
        int $type,
        int $width,
        int $height,
        int $colorType = -1,
        int $bitDepth = -1,
        ?string $trns = null,
        ?string $icc = null,
        ?string $webpChunk = null,
        int $bmpCompression = -1,
        bool $bmpAlpha = false
    ) {
        $this->type = $type;
        $this->width = $width;
        $this->height = $height;
        $this->colorType = $colorType;
        $this->bitDepth = $bitDepth;
        $this->trns = $trns;
        $this->icc = $icc;
        $this->webpChunk = $webpChunk;
        $this->bmpCompression = $bmpCompression;
        $this->bmpAlpha = $bmpAlpha;
    }

    /**
     * Read the metadata for a PNG, JPEG, BMP or WebP file.
     *
     * Only chunk/marker headers are read; pixel data is skipped over, so this
     * is cheap next to a decode even on a 12 MP source.
     */
    public static function read(string $path): self
    {
        // Content, not extension: a WebP somebody renamed to .jpg is still a
        // WebP, and a PNG somebody renamed to .webp is not one.  This runs
        // before getimagesize() because it is the check the skip path — and
        // anything deciding "is there conversion work here at all" — hangs off.
        if (self::looksLikeWebp($path)) {
            return self::readWebp($path);
        }

        // BMP before getimagesize() as well: the DIB header's channel masks
        // decide whether alpha is possible, and getimagesize() omits them.
        $head = self::head($path);
        $info = null;
        if (str_starts_with($head, 'BM')) {
            try {
                return self::readBmp($path);
            } catch (\InvalidArgumentException $bmpFailure) {
                // Starts with "BM": either a bitmap this reader will not take
                // — a truncated one, an embedded JPEG — or some other file
                // that merely begins that way.  getimagesize() tells the two
                // apart, and a bitmap keeps the reader's own reason.
                $info = @getimagesize($path);
                if ($info !== false && $info[2] === IMAGETYPE_BMP) {
                    // It really is a bitmap, so the reader's reason is the
                    // useful one — reported as the same RuntimeException shape
                    // every other unreadable file gets from this method.
                    throw new \RuntimeException(
                        'cannot read image: ' . $path . ' (' . $bmpFailure->getMessage() . ')',
                        0,
                        $bmpFailure
                    );
                }
            }
        }

        $info ??= @getimagesize($path);
        if ($info === false) {
            throw new \RuntimeException("cannot read image: $path");
        }
        $type = $info[2];
        if ($type === IMAGETYPE_PNG) {
            return self::readPng($path);
        }
        if ($type === IMAGETYPE_JPEG) {
            return self::readJpeg($path, $info);
        }

        throw new \RuntimeException(
            'unsupported image type for ' . basename($path) . ': PNG, JPEG and BMP are the inputs (WebP is skipped)'
        );
    }

    /**
     * Is this file already a WebP?  If so there is nothing to convert: the
     * source is the format we write.
     */
    public function isWebp(): bool
    {
        return $this->type === IMAGETYPE_WEBP;
    }

    /**
     * Does the *format* allow per-pixel alpha at all?  Whether any pixel is
     * actually translucent is decided by the caller's pixel scan.
     */
    public function mayHaveAlpha(): bool
    {
        if ($this->type === IMAGETYPE_WEBP) {
            // Extended-format WebP carries an alpha channel; whether it uses
            // one is a per-pixel question the loader answers.
            return true;
        }
        if ($this->type === IMAGETYPE_BMP) {
            // Only a header that declares an alpha mask can be translucent.
            return $this->bmpAlpha;
        }
        if ($this->type !== IMAGETYPE_PNG) {
            return false;
        }
        if ($this->colorType === self::GREY_ALPHA || $this->colorType === self::RGBA) {
            return true;
        }

        return $this->trns !== null;
    }

    /**
     * Is this 8-bit RGB triple the tRNS key colour, i.e. transparent?
     *
     * Palette PNGs do not need this — libgd expands indexed transparency into
     * the alpha channel itself.  Colour types 0 and 2 do, because libgd drops
     * their tRNS chunk without a word.
     */
    public function pixelIsTransparent(int $r, int $g, int $b): bool
    {
        $key = $this->trnsKey();
        if ($key === null) {
            return false;
        }

        return match ($key[0]) {
            self::GREY => $r === $key[1] && $g === $key[1] && $b === $key[1],
            self::TRUE => $r === $key[1] && $g === $key[2] && $b === $key[3],
            default => false,
        };
    }

    /**
     * A human-readable summary for CLI reports.
     */
    public function describe(): string
    {
        if ($this->isWebp()) {
            return 'webp (' . rtrim((string) $this->webpChunk) . ')';
        }
        $parts = [];
        if ($this->type === IMAGETYPE_BMP) {
            $parts[] = 'bmp';
            $parts[] = $this->bitDepth . 'bpp';
            $parts[] = match ($this->bmpCompression) {
                1 => 'rle8',
                2 => 'rle4',
                3 => 'bitfields',
                6 => 'alpha-bitfields',
                default => 'rgb',
            };
            if ($this->bmpAlpha) {
                $parts[] = 'alpha';
            }
        } elseif ($this->type === IMAGETYPE_PNG) {
            $parts[] = 'png/ct' . $this->colorType . '/bd' . $this->bitDepth;
            if ($this->trns !== null) {
                $parts[] = $this->trnsKey() === null ? 'trns(palette)' : 'trns(key)';
            }
        } else {
            $parts[] = 'jpeg';
        }
        if ($this->icc !== null) {
            $parts[] = 'icc ' . strlen($this->icc) . 'B';
        }

        return implode(' ', $parts);
    }

    /**
     * A BMP, described by its DIB header: size, bit depth, storage layout and
     * whether it declares an alpha channel.  No pixels are touched.
     */
    private static function readBmp(string $path): self
    {
        $hdr = BmpReader::probe($path);

        return new self(
            IMAGETYPE_BMP,
            $hdr['w'],
            $hdr['h'],
            -1,
            $hdr['bits'],
            null,
            $hdr['icc'],
            null,
            $hdr['compression'],
            $hdr['alpha']
        );
    }

    /**
     * The tRNS key colour in 8-bit channels, or null when this image has no
     * tRNS that needs applying by hand.  For bit depth 16 the decoder hands us
     * the high byte only, so the key is reduced the same way.
     *
     * @return array<int,int>|null [PNG colour type, ...channels]
     */
    private function trnsKey(): ?array
    {
        if ($this->trnsResolved) {
            return $this->trnsKey;
        }
        $this->trnsResolved = true;

        $t = $this->trns;
        if ($t === null) {
            return $this->trnsKey = null;
        }
        $shift = $this->bitDepth >= 16 ? 8 : 0;
        if ($this->colorType === self::GREY && strlen($t) >= 2) {
            $v = unpack('n', substr($t, 0, 2))[1];

            return $this->trnsKey = [self::GREY, ($v >> $shift) & 0xFF];
        }
        if ($this->colorType === self::TRUE && strlen($t) >= 6) {
            /** @var array<int,int> $v */
            $v = unpack('n3', substr($t, 0, 6));

            return $this->trnsKey = [
                self::TRUE,
                ($v[1] >> $shift) & 0xFF,
                ($v[2] >> $shift) & 0xFF,
                ($v[3] >> $shift) & 0xFF,
            ];
        }

        return $this->trnsKey = null;
    }

    /** The first HEAD_BYTES bytes of a file, or '' when it cannot be read. */
    private static function head(string $path): string
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return '';
        }
        $bytes = (string) fread($fh, self::HEAD_BYTES);
        fclose($fh);

        return $bytes;
    }

    private static function looksLikeWebp(string $path): bool
    {
        $head = self::head($path);

        return str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP';
    }

    /**
     * A WebP, read just far enough to know its size and which chunk holds the
     * image — VP8X (extended: alpha and/or ICC), VP8 (lossy) or VP8L
     * (lossless).  Nothing is decoded; this is a header walk.
     */
    private static function readWebp(string $path): self
    {
        $head = self::head($path);
        if (strlen($head) < 21) {
            throw new \RuntimeException("cannot read WebP header: $path");
        }
        $chunk = substr($head, 12, 4);
        $w = 0;
        $h = 0;

        if ($chunk === 'VP8X') {
            // Extended container: a flag byte, three reserved bytes, then the
            // canvas size minus one, 24 bits each, little endian.
            if (strlen($head) >= 30) {
                $w = 1 + self::le24(substr($head, 24, 3));
                $h = 1 + self::le24(substr($head, 27, 3));
            }
        } elseif ($chunk === 'VP8 ') {
            // Lossy: a 3-byte frame tag, a 3-byte start code, then 14 bits of
            // width and 14 bits of height, the top two bits of each being the
            // upscaling hint rather than part of the size.
            if (strlen($head) >= 30 && substr($head, 23, 3) === "\x9d\x01\x2a") {
                $w = unpack('v', substr($head, 26, 2))[1] & 0x3FFF;
                $h = unpack('v', substr($head, 28, 2))[1] & 0x3FFF;
            }
        } elseif ($chunk === 'VP8L') {
            // Lossless: a signature byte, then width-1 and height-1 packed
            // into 14 bits each, little endian.
            if (strlen($head) >= 25 && ord($head[20]) === 0x2F) {
                $bits = unpack('V', substr($head, 21, 4))[1];
                $w = 1 + ($bits & 0x3FFF);
                $h = 1 + (($bits >> 14) & 0x3FFF);
            }
        }

        if ($w <= 0 || $h <= 0) {
            throw new \RuntimeException("cannot read WebP header: $path");
        }

        return new self(IMAGETYPE_WEBP, $w, $h, -1, -1, null, null, $chunk);
    }

    /** A 24-bit little-endian integer, as VP8X stores canvas sizes. */
    private static function le24(string $bytes): int
    {
        return ord($bytes[0]) | (ord($bytes[1]) << 8) | (ord($bytes[2]) << 16);
    }

    private static function readPng(string $path): self
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("cannot read image: $path");
        }
        $signature = (string) fread($fh, 8);
        if ($signature !== "\x89PNG\r\n\x1a\n") {
            fclose($fh);
            throw new \RuntimeException("not a PNG file: $path");
        }

        $colorType = -1;
        $bitDepth = -1;
        $trns = null;
        $icc = null;
        $w = 0;
        $h = 0;
        $first = true;

        while (true) {
            $header = (string) fread($fh, 8);
            if (strlen($header) !== 8) {
                break;
            }
            $len = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);

            // Everything this class needs precedes the first IDAT, and once
            // IDAT starts there can be megabytes of it.
            if ($type === 'IDAT' || $type === 'IEND') {
                break;
            }
            if ($first && $type !== 'IHDR') {
                break;
            }
            $first = false;

            if ($type === 'IHDR' && $len === 13) {
                $data = (string) fread($fh, 13);
                if (strlen($data) !== 13) {
                    break;
                }
                $ihdr = unpack('N2', substr($data, 0, 8));
                $w = $ihdr[1];
                $h = $ihdr[2];
                $bitDepth = ord($data[8]);
                $colorType = ord($data[9]);
            } elseif ($type === 'tRNS' && $len <= self::TRNS_LIMIT) {
                $trns = (string) fread($fh, $len);
            } elseif ($type === 'iCCP' && $len <= self::ICC_LIMIT) {
                $data = (string) fread($fh, $len);
                $profile = self::decodeIccp($data);
                if ($profile !== null) {
                    $icc = $profile;
                }
            } else {
                fseek($fh, $len, SEEK_CUR);
            }
            fseek($fh, 4, SEEK_CUR); // chunk CRC
        }
        fclose($fh);

        if ($colorType < 0) {
            throw new \RuntimeException("cannot read PNG header: $path");
        }

        return new self(IMAGETYPE_PNG, $w, $h, $colorType, $bitDepth, $trns, $icc);
    }

    /**
     * iCCP payload: a Latin-1 profile name, a null, a compression method byte
     * (0 = zlib) and a zlib stream holding the profile itself.
     */
    public static function decodeIccp(string $payload): ?string
    {
        $nul = strpos($payload, "\x00");
        if ($nul === false || $nul > 79 || !isset($payload[$nul + 1])) {
            return null;
        }
        if ($payload[$nul + 1] !== "\x00") {
            return null;
        }
        $raw = @gzuncompress(substr($payload, $nul + 2));

        return $raw === false ? null : $raw;
    }

    /**
     * @param array<int,int|string> $info the getimagesize() result
     */
    private static function readJpeg(string $path, array $info): self
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("cannot read image: $path");
        }
        if ((string) fread($fh, 2) !== "\xff\xd8") {
            fclose($fh);
            throw new \RuntimeException("not a JPEG file: $path");
        }

        /** @var array<int,string> $parts */
        $parts = [];
        $expected = 0;

        while (true) {
            $byte = fread($fh, 1);
            if ($byte === false || $byte === '') {
                break; // truncated file
            }
            if ($byte !== "\xff") {
                continue; // resynchronise on a mangled stream
            }
            do {
                $markerByte = fread($fh, 1);
            } while ($markerByte === "\xff"); // fill bytes are legal
            if ($markerByte === false || $markerByte === '') {
                break;
            }
            $marker = ord($markerByte);
            // Standalone markers carry no length: TEM, the restart markers,
            // SOI and EOI.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD9)) {
                continue;
            }
            if ($marker === 0xDA) {
                break; // start of scan: metadata is behind us
            }
            $lengthBytes = (string) fread($fh, 2);
            if (strlen($lengthBytes) !== 2) {
                break;
            }
            $len = unpack('n', $lengthBytes)[1] - 2;
            if ($len < 0) {
                break;
            }
            if ($marker === 0xE2 && $len >= 14 && $len <= self::JPEG_APP2_MAX) {
                $payload = (string) fread($fh, $len);
                if (str_starts_with($payload, "ICC_PROFILE\x00")) {
                    $seq = ord($payload[12]);
                    $count = ord($payload[13]);
                    if ($seq >= 1 && $count >= 1 && $seq <= $count) {
                        $parts[$seq] = substr($payload, 14);
                        $expected = $count;
                    }
                }
                continue;
            }
            fseek($fh, $len, SEEK_CUR);
        }
        fclose($fh);

        $icc = null;
        if ($expected >= 1 && count($parts) === $expected) {
            ksort($parts);
            $icc = implode('', $parts);
        }

        return new self(IMAGETYPE_JPEG, (int) $info[0], (int) $info[1], -1, -1, null, $icc);
    }
}
