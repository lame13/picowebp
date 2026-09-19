<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

require_once __DIR__ . '/ImageMeta.php';
require_once __DIR__ . '/SkippedInput.php';
require_once __DIR__ . '/PngReader.php';
require_once __DIR__ . '/JpegReader.php';

/**
 * Source-image loading for the pure-PHP encoder: one GD decode, one pass over
 * the pixels, producing both the RGB buffer the encoder consumes and (when the
 * source actually has usable transparency) the 8-bit alpha plane.
 *
 * Three things this has to get right, because each of them used to be wrong:
 *
 *  - **Palette PNGs.**  libgd hands back palette *indices* from
 *    imagecolorat(), so an indexed PNG used to be encoded as whatever colours
 *    those small integers happened to name.  The image is converted to true
 *    colour before any pixel is read, which also expands indexed transparency
 *    into the alpha channel.
 *  - **tRNS on a truecolour PNG.**  libgd ignores the chunk, so a "this exact
 *    colour is a hole" PNG came back opaque.  ImageMeta reads the key and the
 *    pixel loop applies it.
 *  - **Dropped transparency and colour profiles.**  Alpha used to be thrown
 *    away (an RGB-only loader) and the ICC profile was never even looked for.
 *
 * It is also where the "nothing to do here" decision lives: a source that is
 * already a WebP throws SkippedInput instead of being decoded and re-coded.
 */
final class ImageInput
{
    /**
     * Use the bundled readers even where GD is available.
     *
     * GD is the default when it is present: it is faster, and it keeps output
     * byte-identical to what this encoder produced before the bundled readers
     * existed.  The bundled PNG reader is nevertheless worth having on
     * purpose — it expands palette entries and tRNS keys the way the
     * specification says rather than the way libgd approximates them, and it
     * carries all eight bits of alpha instead of libgd's seven.
     */
    public static bool $preferBundled = false;

    /**
     * Force the GD path on or off instead of asking the extension list.
     *
     * Setting this to false is how the bundled readers get exercised on a
     * machine that does have GD — including in this package's own test suite —
     * and it is also the honest way for a caller to insist that no GD code
     * runs at all.
     */
    public static ?bool $gdAvailable = null;

    /**
     * Skip WebP sources rather than re-encoding them.
     *
     * On by default, because a WebP is the format this package produces: the
     * conversion a caller asked for has already happened, and re-encoding
     * costs CPU to lose quality that is already spent.  A caller who really
     * wants a WebP decoded and coded again (needs GD, which is the only WebP
     * decoder here) sets this to false, as `bin/picowebp --reencode-webp` does.
     */
    public static bool $skipWebp = true;

    private static function haveGd(): bool
    {
        return self::$gdAvailable ?? extension_loaded('gd');
    }

    /**
     * Decode a source image, whichever reader is the best fit.
     *
     * @return array{w:int,h:int,rgb:string,alpha:?string,icc:?string,reader:string}
     */
    public static function pixels(string $path): array
    {
        $meta = ImageMeta::read($path);
        if ($meta->isWebp() && self::$skipWebp) {
            throw SkippedInput::webp($path, $meta);
        }
        $haveGd = self::haveGd();
        $bundled = self::$preferBundled || !$haveGd;

        if ($bundled && $meta->type === IMAGETYPE_PNG) {
            $png = PngReader::read($path);

            return [
                'w' => $png['w'],
                'h' => $png['h'],
                'rgb' => $png['rgb'],
                'alpha' => $png['alpha'],
                'icc' => $png['icc'] ?? $meta->icc,
                'reader' => 'bundled',
            ];
        }
        if ($bundled && $meta->type === IMAGETYPE_JPEG) {
            try {
                $jpeg = JpegReader::read($path);

                return [
                    'w' => $jpeg['w'],
                    'h' => $jpeg['h'],
                    'rgb' => $jpeg['rgb'],
                    'alpha' => null,
                    'icc' => $jpeg['icc'] ?? $meta->icc,
                    'reader' => 'bundled',
                ];
            } catch (\RuntimeException $e) {
                // Baseline-only by design.  If GD is here it can take the
                // awkward file; if it is not, the caller gets the reason.
                if (!$haveGd) {
                    throw $e;
                }
            }
        }

        if (!$haveGd) {
            throw new \RuntimeException(
                'decoding ' . basename($path) . ' needs either ext-gd or one of the bundled readers'
                . ($meta->isWebp() ? ', and nothing here decodes WebP in pure PHP' : '')
            );
        }

        return self::pixelsWithGd($path, $meta);
    }
    /**
     * Decode the source image and return the packed RGB bytes.
     *
     * When $alpha is non-null on return, the source carried at least one
     * non-opaque pixel and $alpha holds the w*h alpha plane (8 bits/pixel,
     * scan order).  When it stays null the image is fully opaque and no alpha
     * needs to be coded.  $icc receives the source's ICC profile, or null.
     *
     * @param string|null $alpha receives the alpha plane, or null when opaque
     * @param string|null $icc receives the ICC profile, or null
     */
    public static function load(string $path, int $w, int $h, ?string &$alpha = null, ?string &$icc = null): string
    {
        $pixels = self::pixels($path);
        if ($pixels['w'] !== $w || $pixels['h'] !== $h) {
            throw new \RuntimeException(sprintf(
                '%s is %dx%d, not the %dx%d the caller asked for',
                basename($path),
                $pixels['w'],
                $pixels['h'],
                $w,
                $h
            ));
        }
        $alpha = $pixels['alpha'];
        $icc = $pixels['icc'];

        return $pixels['rgb'];
    }

    /**
     * The GD-backed path.  Kept because GD is fast and because it is what
     * every stored baseline was produced with.
     *
     * @return array{w:int,h:int,rgb:string,alpha:?string,icc:?string,reader:string}
     */
    private static function pixelsWithGd(string $path, ImageMeta $meta): array
    {
        $im = self::decode($path, $meta->type);
        $w = imagesx($im);
        $h = imagesy($im);

        $probeAlpha = $meta->mayHaveAlpha();
        $alphaBytes = '';
        $sawAlpha = false;

        $rgb = '';
        for ($j = 0; $j < $h; $j++) {
            $row = '';
            for ($i = 0; $i < $w; $i++) {
                $c = imagecolorat($im, $i, $j);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                $row .= chr($r) . chr($g) . chr($b);
                if ($probeAlpha) {
                    // GD packs truecolour alpha in bits 24..30, 0 = opaque.
                    $a = ($c >> 24) & 0x7F;
                    $a = $a === 0 ? 255 : ((127 - $a) << 1);
                    if ($a > 255) {
                        $a = 255;
                    }
                    if ($a === 255 && $meta->pixelIsTransparent($r, $g, $b)) {
                        $a = 0;
                    }
                    $alphaBytes .= chr($a);
                    if ($a !== 255) {
                        $sawAlpha = true;
                    }
                }
            }
            $rgb .= $row;
        }
        imagedestroy($im);

        return [
            'w' => $w,
            'h' => $h,
            'rgb' => $rgb,
            'alpha' => $sawAlpha ? $alphaBytes : null,
            'icc' => $meta->icc,
            'reader' => 'gd',
        ];
    }

    /**
     * Decode a PNG or JPEG into a true-colour GdImage ready to be read with
     * imagecolorat().  Shared with PlaneStore so that both loaders agree bit
     * for bit about what the source pixels are.
     *
     * @return \GdImage
     */
    public static function decode(string $path, int $type)
    {
        $im = match ($type) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            // GD is the only WebP decoder in the package, and not every GD
            // build has it: a function that is not there must throw the same
            // way a decode failure does, not fatal with "undefined function".
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if ($im === false) {
            throw new \RuntimeException(
                'cannot decode ' . basename($path) . ' as ' . match ($type) {
                    IMAGETYPE_PNG => 'PNG',
                    IMAGETYPE_JPEG => 'JPEG',
                    IMAGETYPE_WEBP => 'WebP (this GD build has no WebP decoder)',
                    default => 'an unsupported image type',
                }
            );
        }
        // A palette image answers imagecolorat() with an index into its
        // palette, not a colour.  Expand it (transparency included) before
        // anything reads a pixel.
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, false);

        return $im;
    }

    /**
     * Does the source's *format* allow per-pixel alpha?  A cheap header sniff;
     * whether any pixel is actually translucent is decided by load().
     */
    public static function mayHaveAlpha(string $path): bool
    {
        return ImageMeta::read($path)->mayHaveAlpha();
    }

    /**
     * Is this file already a WebP, i.e. is there nothing to convert?  A cheap
     * header sniff, no decode — the same question `pixels()` answers with a
     * SkippedInput exception, for callers that would rather ask first.
     */
    public static function isWebp(string $path): bool
    {
        return ImageMeta::read($path)->isWebp();
    }

    /**
     * The source's ICC profile, or null.  For callers that want the profile
     * without paying for a decode.
     */
    public static function iccProfile(string $path): ?string
    {
        return ImageMeta::read($path)->icc;
    }

    /** Alias for the old name. */
    public static function formatSupportsAlpha(string $path): bool
    {
        return self::mayHaveAlpha($path);
    }
}
