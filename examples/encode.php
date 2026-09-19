<?php

declare(strict_types=1);

/**
 * Two ways to get a WebP out of picowebp:
 *
 *   php examples/encode.php photo.jpg photo.webp
 *   php examples/encode.php --raw pixels.raw out.webp 1200 800
 *
 * A WebP source is skipped (exit 4): it is already the format this writes.
 */

$root = dirname(__DIR__);
$vendored = $root . '/vendor/autoload.php';
if (is_file($vendored)) {
    require_once $vendored;
} else {
    foreach (['Vp8Tables', 'AlphaCoder', 'ImageMeta', 'SkippedInput', 'PngReader', 'JpegReader', 'Vp8LossyEncoder', 'ImageInput'] as $file) {
        require_once $root . '/src/' . $file . '.php';
    }
}

use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\SkippedInput;
use PicoWebP\Vp8\Vp8LossyEncoder;

$argvRest = array_slice($argv, 1);

if (($argvRest[0] ?? '') === '--raw') {
    // Raw path: no GD, no file format.  Just bytes and a geometry.
    [$raw, $outFile, $w, $h] = [$argvRest[1], $argvRest[2], (int) $argvRest[3], (int) $argvRest[4]];
    $rgb = (string) file_get_contents($raw);
    if (strlen($rgb) !== $w * $h * 3) {
        fwrite(STDERR, sprintf("expected %d bytes of RGB, got %d\n", $w * $h * 3, strlen($rgb)));
        exit(1);
    }
} else {
    // File path: the bundled loader gives back RGB, and the alpha plane and
    // ICC profile when the source has them.
    [$in, $outFile] = [$argvRest[0], $argvRest[1]];
    $info = getimagesize($in);
    if ($info === false) {
        fwrite(STDERR, "cannot read $in\n");
        exit(1);
    }
    [$w, $h] = [$info[0], $info[1]];
    $alpha = null;
    $icc = null;
    try {
        $rgb = ImageInput::load($in, $w, $h, $alpha, $icc);
    } catch (SkippedInput $e) {
        printf("%s: %s\n", basename($in), $e->reason());
        exit(4);
    }
}

$enc = Vp8LossyEncoder::fromQuality(80);
$enc->alphaPlane = $alpha ?? null;
$enc->iccProfile = $icc ?? null;

$result = $enc->encode($rgb, $w, $h);
// Check the write: this is the step that decides whether the caller got a file.
if (@file_put_contents($outFile, $result['webp']) !== strlen($result['webp'])) {
    fwrite(STDERR, "cannot write $outFile\n");
    exit(1);
}

printf(
    "%dx%d -> %s  %d bytes  (%.2fs, peak %.0f MB)%s\n",
    $w,
    $h,
    $outFile,
    strlen($result['webp']),
    $result['seconds'],
    memory_get_peak_usage(true) / 1048576,
    ($alpha ?? null) !== null ? '  +alpha' : ''
);
