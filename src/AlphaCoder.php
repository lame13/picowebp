<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

/**
 * The 'ALPH' chunk of an extended WebP file: the transparency plane, coded
 * separately from the lossy colour data.
 *
 * Layout (WebP Container Specification):
 *
 *     |Rsv| P | F | C |      one header byte, then the alpha bitstream
 *
 *   Rsv reserved (2 bits, 0), P preprocessing (2 bits), F filtering method
 *   (2 bits), C compression method (2 bits; 0 = raw, 1 = WebP lossless).
 *
 * The filtering step is a per-pixel prediction over already-reconstructed
 * neighbours, so it is exactly invertible:
 *
 *     residual = (alpha - predictor) & 0xFF
 *     alpha    = (predictor + residual) % 256
 *
 * Coded alpha is very often a flat 255 with a few cut-out regions, and the
 * gradient filter turns a smooth ramp into a run of zeros, which the lossless
 * coder then gets almost for free.
 */
final class AlphaCoder
{
    public const FILTER_NONE = 0;
    public const FILTER_HORIZONTAL = 1;
    public const FILTER_VERTICAL = 2;
    public const FILTER_GRADIENT = 3;
    /** Try the useful filters and keep the smallest stream. */
    public const FILTER_AUTO = -1;
    /** Above this many pixels, AUTO only tries the two filters that win. */
    private const AUTO_THOROUGH_LIMIT = 2000000;

    /**
     * Encode one alpha plane into a complete 'ALPH' chunk payload.
     *
     * $plane is one byte per pixel in scan order at the *display* size.
     * $filter is one of the FILTER_* constants; the plane is filtered before
     * coding, and the chosen method is recorded in the chunk header so the
     * decoder inverts it.
     */
    public static function encode(string $plane, int $w, int $h, int $filter = self::FILTER_AUTO): string
    {
        if ($w < 1 || $h < 1) {
            throw new \InvalidArgumentException('alpha dimensions must be positive');
        }
        if (strlen($plane) !== $w * $h) {
            throw new \InvalidArgumentException('alpha plane does not match the display size');
        }
        if ($filter === self::FILTER_AUTO) {
            return self::encodeAuto($plane, $w, $h);
        }
        if ($filter < self::FILTER_NONE || $filter > self::FILTER_GRADIENT) {
            throw new \InvalidArgumentException('alpha filter must be auto (-1) or 0..3');
        }
        $stream = self::stream($plane, $w, $h, $filter);
        self::$lastFilter = $filter;

        // Rsv=0, P=0 (no preprocessing), F=$filter, C=1 (lossless)
        $header = chr(($filter << 2) | 1);

        return $header . $stream;
    }

    /**
     * Which filter helps is a property of the alpha plane, not of the format:
     * a hard-edged cut-out codes best unfiltered, a soft edge or a gradient
     * codes best gradient-filtered.  The plane is usually small next to the
     * colour data, so trying the candidates is cheap and removes a knob.
     */
    private static function encodeAuto(string $plane, int $w, int $h): string
    {
        $n = $w * $h;
        $candidates = $n <= self::AUTO_THOROUGH_LIMIT
            ? [self::FILTER_NONE, self::FILTER_GRADIENT, self::FILTER_HORIZONTAL]
            : [self::FILTER_NONE, self::FILTER_GRADIENT];

        $best = null;
        $bestFilter = self::FILTER_NONE;
        foreach ($candidates as $filter) {
            $payload = chr(($filter << 2) | 1) . self::stream($plane, $w, $h, $filter);
            if ($best === null || strlen($payload) < strlen($best)) {
                $best = $payload;
                $bestFilter = $filter;
            }
        }
        self::$lastFilter = $bestFilter;

        return (string) $best;
    }

    /** The filter used by the last successful encode, for reporting. */
    private static int $lastFilter = self::FILTER_NONE;

    public static function lastFilter(): int
    {
        return self::$lastFilter;
    }

    public static function filterName(int $filter): string
    {
        return match ($filter) {
            self::FILTER_NONE => 'none',
            self::FILTER_HORIZONTAL => 'horizontal',
            self::FILTER_VERTICAL => 'vertical',
            self::FILTER_GRADIENT => 'gradient',
            default => 'auto',
        };
    }

    private static function stream(string $plane, int $w, int $h, int $filter): string
    {
        $residual = $filter === self::FILTER_NONE ? $plane : self::filter($plane, $w, $h, $filter);

        return self::lossless($residual, $w, $h);
    }

    /**
     * Invert the filter.  Used by the test harness to prove the round trip
     * independently of libwebp.
     */
    public static function unfilter(string $residual, int $w, int $h, int $filter): string
    {
        if ($filter === self::FILTER_NONE) {
            return $residual;
        }
        $out = str_repeat("\x00", $w * $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = $y * $w + $x;
                $p = match ($filter) {
                    self::FILTER_HORIZONTAL => $x === 0 ? ($y === 0 ? 0 : \ord($out[$i - $w])) : \ord($out[$i - 1]),
                    self::FILTER_VERTICAL => $y === 0 ? ($x === 0 ? 0 : \ord($out[$i - 1])) : \ord($out[$i - $w]),
                    self::FILTER_GRADIENT => match (true) {
                        $x === 0 && $y === 0 => 0,
                        $x === 0 => \ord($out[$i - $w]),
                        $y === 0 => \ord($out[$i - 1]),
                        default => self::clip(\ord($out[$i - 1]) + \ord($out[$i - $w]) - \ord($out[$i - $w - 1])),
                    },
                    default => 0,
                };
                $out[$i] = chr((\ord($residual[$i]) + $p) & 0xFF);
            }
        }

        return $out;
    }

    private static function filter(string $plane, int $w, int $h, int $filter): string
    {
        $out = str_repeat("\x00", $w * $h);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $i = $y * $w + $x;
                $p = match ($filter) {
                    self::FILTER_HORIZONTAL => $x === 0 ? ($y === 0 ? 0 : \ord($plane[$i - $w])) : \ord($plane[$i - 1]),
                    self::FILTER_VERTICAL => $y === 0 ? ($x === 0 ? 0 : \ord($plane[$i - 1])) : \ord($plane[$i - $w]),
                    self::FILTER_GRADIENT => match (true) {
                        $x === 0 && $y === 0 => 0,
                        $x === 0 => \ord($plane[$i - $w]),
                        $y === 0 => \ord($plane[$i - 1]),
                        default => self::clip(\ord($plane[$i - 1]) + \ord($plane[$i - $w]) - \ord($plane[$i - $w - 1])),
                    },
                    default => 0,
                };
                $out[$i] = chr((\ord($plane[$i]) - $p) & 0xFF);
            }
        }

        return $out;
    }

    private static function clip(int $v): int
    {
        return $v < 0 ? 0 : ($v > 255 ? 255 : $v);
    }

    /**
     * Compression method 1: the WebP lossless image stream of implicit
     * dimensions.  The VP8L encoder lives in the lossless spike; it is pulled
     * in lazily so that a build without it can still encode opaque images.
     */
    private static function lossless(string $plane, int $w, int $h): string
    {
        if (!class_exists(\PicoWebP\Spike\Vp8lEncoder::class)) {
            // Autoloading finds this in a Composer install; the relative path
            // covers running straight out of a checkout of the package.
            $path = __DIR__ . '/Spike/Vp8lEncoder.php';
            if (is_file($path)) {
                require_once $path;
            }
        }
        $enc = new \PicoWebP\Spike\Vp8lEncoder();

        return $enc->encodeAlphaStream($w, $h, $plane);
    }
}
