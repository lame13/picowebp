<?php

declare(strict_types=1);

/**
 * The resumable path: stage the planes once, then encode in bands.
 *
 *   php examples/chunked-queue.php photo.jpg /tmp/work --mb-rows=8
 *
 * Why it exists: the whole-image call holds a padded 1.5 bytes/pixel copy of
 * the frame plus the reconstruction, so a 12 MP photo wants ~128 MB. Staging
 * the planes to disk first means each step only ever touches a band of
 * macroblock rows, which is what makes this runnable from a queue on a host
 * with a 16 MB `memory_limit`.
 *
 * A real queue would run one band per request and persist the state between
 * them (`exportState()` -> serialize -> the next request calls
 * `importState()`), which is exactly what `chunk-step.php` in the research
 * tree does. This script runs the whole thing in one process so the shape of
 * the loop is readable, and checks its own work against the single-shot
 * encoder at the end.
 */

$root = dirname(__DIR__);
$vendored = $root . '/vendor/autoload.php';
if (is_file($vendored)) {
    require_once $vendored;
} else {
    foreach (['Vp8Tables', 'AlphaCoder', 'ImageMeta', 'PngReader', 'JpegReader',
             'Vp8LossyEncoder', 'ImageInput', 'PlaneStore', 'SkippedInput'] as $file) {
        require_once $root . '/src/' . $file . '.php';
    }
}

use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\PlaneStore;
use PicoWebP\Vp8\SkippedInput;
use PicoWebP\Vp8\Vp8LossyEncoder;

$image = $argv[1] ?? null;
$workDir = $argv[2] ?? null;
$quality = 80;
$mbRows = 8;
foreach (array_slice($argv, 3) as $arg) {
    if (preg_match('/^--quality=(\d+)$/', $arg, $m)) {
        $quality = (int) $m[1];
    } elseif (preg_match('/^--mb-rows=(\d+)$/', $arg, $m)) {
        $mbRows = max(1, (int) $m[1]);
    }
}
if ($image === null || $workDir === null) {
    fwrite(STDERR, "usage: php examples/chunked-queue.php <image> <workdir> [--mb-rows=8] [--quality=80]\n");
    exit(1);
}

$started = microtime(true);

// Step 1 — the one pass that still needs the whole decoded image. Everything
// after this reads rows off disk.
try {
    $meta = PlaneStore::prepare($image, $workDir);
} catch (SkippedInput $e) {
    // Already a WebP: a queue would mark the job done, not failed.
    printf("%s: %s\n", basename($image), $e->reason());
    exit(4);
}
$store = PlaneStore::open($workDir);
$mbh = (int) $meta['mbh'];
$stagedPeak = memory_get_peak_usage(true) / 1048576;
printf("staged %dx%d as %d macroblock rows (peak %.0f MB, %.2fs)\n",
    $meta['w'], $meta['h'], $mbh, $stagedPeak, microtime(true) - $started);

// Step 2 — count the frame, then derive the coefficient probabilities from
// the counts. The header carries those odds, so it cannot be written until
// the counting pass is over.
$counter = Vp8LossyEncoder::fromQuality($quality);
$counter->initFrame((int) $meta['w'], (int) $meta['h']);
$counter->enableStats();
for ($from = 0; $from < $mbh; $from += $mbRows) {
    $rows = min($mbRows, $mbh - $from);
    $counter->beginBand($from, $rows, ...$store->readBand($from, $rows));
    $counter->encodeRowRange($from, $rows);
    $counter->compactReconWindow();
}
$counter->adaptFromStats();
$counter->deriveSkipProbability();
$probs = $counter->coeffProbs();
$skipProb = $counter->skipProb;
unset($counter);

// Step 3 — encode for real, band by band, with the odds from step 2.
$enc = Vp8LossyEncoder::fromQuality($quality);
$enc->skipProb = $skipProb;
if (is_array($probs) && count($probs) === 1056) {
    $enc->setCoeffProbs($probs);
}
$enc->initFrame((int) $meta['w'], (int) $meta['h']);
for ($from = 0; $from < $mbh; $from += $mbRows) {
    $rows = min($mbRows, $mbh - $from);
    $enc->beginBand($from, $rows, ...$store->readBand($from, $rows));
    $enc->encodeRowRange($from, $rows);
    $enc->compactReconWindow();
}

// Transparency and the colour profile were staged beside the planes, so the
// closing step can attach them without ever holding the image.
$alphaPlane = $store->alphaPlane();
if ($alphaPlane !== null) {
    $enc->alphaPlane = $alphaPlane;
}
$iccProfile = $store->iccProfile();
if ($iccProfile !== null) {
    $enc->iccProfile = $iccProfile;
}

$result = $enc->finishFrame();
$outFile = rtrim($workDir, '/') . '/out.webp';
if (@file_put_contents($outFile, $result['webp']) !== strlen($result['webp'])) {
    fwrite(STDERR, "cannot write $outFile\n");
    exit(1);
}
$bandPeak = memory_get_peak_usage(true) / 1048576;

printf(
    "encoded %d bytes to %s  (peak %.0f MB after staging, %.2fs total)\n",
    strlen($result['webp']),
    $outFile,
    $bandPeak,
    microtime(true) - $started
);

// Self-check: the banded path must produce the single-shot bytes. A queue
// would never do this part — it is here so a wrong edit is loud, not silent.
$pixels = ImageInput::pixels($image);
$whole = Vp8LossyEncoder::fromQuality($quality);
$whole->alphaPlane = $pixels['alpha'];
$whole->iccProfile = $pixels['icc'];
$single = $whole->encode($pixels['rgb'], $pixels['w'], $pixels['h'])['webp'];

$banded = $result['webp'];
printf("banded md5 %s vs single shot %s: %s\n",
    md5($banded), md5($single), $banded === $single ? 'identical' : 'MISMATCH');
exit($banded === $single ? 0 : 1);
