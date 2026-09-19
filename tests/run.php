<?php

declare(strict_types=1);

/**
 * Self-contained smoke suite.  No fixtures, no network: the images are
 * synthesised, and libwebp's dwebp is used to decode our own output when it is
 * on PATH so that a shared misunderstanding cannot pass.
 *
 *   php tests/run.php
 */

$root = dirname(__DIR__);
$vendored = $root . '/vendor/autoload.php';
if (is_file($vendored)) {
    require_once $vendored;
}
foreach (['Vp8Tables', 'AlphaCoder', 'ImageMeta', 'SkippedInput', 'PngReader', 'JpegReader', 'Vp8LossyEncoder', 'ImageInput', 'PlaneStore'] as $file) {
    require_once $root . '/src/' . $file . '.php';
}

use PicoWebP\Vp8\AlphaCoder;
use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\ImageMeta;
use PicoWebP\Vp8\JpegReader;
use PicoWebP\Vp8\PlaneStore;
use PicoWebP\Vp8\PngReader;
use PicoWebP\Vp8\SkippedInput;
use PicoWebP\Vp8\Vp8LossyEncoder;

$failures = 0;
$skipped = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $failures;
    if (!$ok) {
        $failures++;
    }
    printf("  [%s] %s%s\n", $ok ? 'ok' : 'FAIL', $what, $detail === '' ? '' : "  ($detail)");
}

function skip(string $what, string $why): void
{
    global $skipped;
    $skipped++;
    printf("  [skip] %s  (%s)\n", $what, $why);
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? rmTree($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** A smooth gradient with a little fixed-pattern texture. */
function synthRgb(int $w, int $h): string
{
    $out = '';
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $noise = (($x * 7 + $y * 13) % 5) * 4;
            // The (int) casts are for PHP 8.1+: the `&` used to convert these
            // floats itself, which is deprecated now.  Truncation is the same
            // either way, so the fixtures are unchanged.
            $out .= chr((int) ($x * 255 / max(1, $w - 1) + $noise * 0.3) & 0xFF);
            $out .= chr((int) ($y * 255 / max(1, $h - 1)) & 0xFF);
            $out .= chr((int) (($x + $y) * 127 / max(1, $w + $h - 2) + $noise) & 0xFF);
        }
    }

    return $out;
}

/** Opaque ramp with a hard transparent rectangle and a soft edge. */
function synthAlpha(int $w, int $h): string
{
    $out = '';
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            if ($x < intdiv($w, 4) && $y < intdiv($h, 2)) {
                $a = 0;                       // cut-out
            } elseif ($x >= intdiv($w, 2)) {
                $a = intdiv($w - $x, 1) * 255 / max(1, $w - intdiv($w, 2)); // soft ramp
                $a = (int) max(0, min(255, $a));
            } else {
                $a = 255;
            }
            $out .= chr($a);
        }
    }

    return $out;
}

function pngChunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/** @return array<int,string> chunk fourccs in file order */
function webpChunks(string $webp): array
{
    $out = [];
    $i = 12;
    $n = strlen($webp);
    while ($i + 8 <= $n) {
        $len = unpack('V', substr($webp, $i + 4, 4))[1];
        $out[] = substr($webp, $i, 4);
        $i += 8 + $len + ($len & 1);
    }

    return $out;
}

function webpChunk(string $webp, string $fourcc): ?string
{
    $i = 12;
    $n = strlen($webp);
    while ($i + 8 <= $n) {
        $len = unpack('V', substr($webp, $i + 4, 4))[1];
        if (substr($webp, $i, 4) === $fourcc) {
            return substr($webp, $i + 8, $len);
        }
        $i += 8 + $len + ($len & 1);
    }

    return null;
}

/** @return array{0:string,1:int}|null [pam, depth] */
function dwebpPam(string $webp, string $tmpDir): ?array
{
    $src = $tmpDir . '/t.webp';
    $out = $tmpDir . '/t.pam';
    file_put_contents($src, $webp);
    @unlink($out);
    exec('dwebp -quiet -pam ' . escapeshellarg($src) . ' -o ' . escapeshellarg($out) . ' 2>/dev/null', $o, $status);
    if ($status !== 0 || !is_file($out)) {
        return null;
    }
    $pam = (string) file_get_contents($out);
    preg_match('/DEPTH (\d+)/', $pam, $m);

    return [$pam, (int) ($m[1] ?? 0)];
}

function pamBody(string $pam): string
{
    $at = strpos($pam, "ENDHDR\n");

    return $at === false ? '' : substr($pam, $at + 7);
}

function psnr(string $a, string $b): float
{
    $n = min(strlen($a), strlen($b));
    if ($n === 0) {
        return 0.0;
    }
    $sse = 0;
    for ($i = 0; $i < $n; $i++) {
        $d = ord($a[$i]) - ord($b[$i]);
        $sse += $d * $d;
    }

    return $sse === 0 ? INF : 10 * log10(255 * 255 * $n / $sse);
}

/** Strip alpha from an RGB_ALPHA plane dump. */
function rgbOf(string $pamBody, int $depth): string
{
    if ($depth === 3) {
        return $pamBody;
    }
    $out = '';
    for ($i = 0; $i + 4 <= strlen($pamBody); $i += 4) {
        $out .= $pamBody[$i] . $pamBody[$i + 1] . $pamBody[$i + 2];
    }

    return $out;
}

/** Alpha bytes out of an RGB_ALPHA plane dump. */
function alphaOf(string $pamBody): string
{
    $out = '';
    for ($i = 0; $i + 4 <= strlen($pamBody); $i += 4) {
        $out .= $pamBody[$i + 3];
    }

    return $out;
}

// ------------------------------------------------------------------ setup

$tmp = sys_get_temp_dir() . '/picowebp-test-' . getmypid();
rmTree($tmp);
mkdir($tmp, 0777, true);

$haveGd = extension_loaded('gd');
$haveGdWebp = $haveGd && function_exists('imagecreatefromwebp');
$haveDwebp = trim((string) shell_exec('command -v dwebp 2>/dev/null')) !== '';

$w = 96;
$h = 64;
$rgb = synthRgb($w, $h);
$alpha = synthAlpha($w, $h);

echo "picowebp smoke suite\n";
printf(
    "  php %s  gd %s  dwebp %s\n",
    PHP_VERSION,
    $haveGd ? ($haveGdWebp ? 'yes (webp)' : 'yes, no webp decode') : 'no',
    $haveDwebp ? 'yes' : 'no'
);

echo "\n1. bitstream structure\n";
$enc = Vp8LossyEncoder::fromQuality(80);
$res = $enc->encode($rgb, $w, $h);
$webp = $res['webp'];
check('starts with RIFF....WEBP', str_starts_with($webp, "RIFF") && substr($webp, 8, 4) === 'WEBP');
check('RIFF size field matches the file', unpack('V', substr($webp, 4, 4))[1] === strlen($webp) - 8);
check('opaque image is a simple VP8  container', webpChunks($webp) === ['VP8 '], implode(' ', webpChunks($webp)));
check('VP8 chunk declares the display size', (unpack('v', substr((string) webpChunk($webp, 'VP8 '), 6, 2))[1] & 0x3FFF) === $w);

echo "\n2. rate control\n";
$bytes = [];
foreach ([90, 50, 10] as $q) {
    $bytes[$q] = strlen(Vp8LossyEncoder::fromQuality($q)->encode($rgb, $w, $h)['webp']);
}
check('lower quality means fewer bytes', $bytes[90] > $bytes[50] && $bytes[50] > $bytes[10], "q90 {$bytes[90]} > q50 {$bytes[50]} > q10 {$bytes[10]}");

echo "\n3. transparency\n";
$encA = Vp8LossyEncoder::fromQuality(80);
$encA->alphaPlane = $alpha;
$webpA = $encA->encode($rgb, $w, $h)['webp'];
check('extended container VP8X + ALPH + VP8 ', webpChunks($webpA) === ['VP8X', 'ALPH', 'VP8 '], implode(' ', webpChunks($webpA)));
$vp8x = (string) webpChunk($webpA, 'VP8X');
check('VP8X declares the alpha flag', (ord($vp8x[0]) & 0x10) !== 0);
check('ALPH chose a filter and compression method 1', (ord((string) webpChunk($webpA, 'ALPH')) & 0x03) === 1, AlphaCoder::filterName(AlphaCoder::lastFilter()));

if ($haveDwebp) {
    [$pam, $depth] = dwebpPam($webpA, $tmp) ?? ['', 0];
    if ($depth === 4) {
        $decoded = alphaOf(pamBody($pam));
        $worst = 0;
        for ($i = 0; $i < min(strlen($decoded), strlen($alpha)); $i++) {
            $worst = max($worst, abs(ord($decoded[$i]) - ord($alpha[$i])));
        }
        check('alpha plane round-trips exactly', $worst === 0, "max delta $worst");
    } else {
        check('decoded as RGB_ALPHA', false, "depth=$depth");
    }
    [$pam, $depth] = dwebpPam($webp, $tmp) ?? ['', 0];
    $p = psnr(rgbOf(pamBody($pam), $depth), $rgb);
    check('q80 PSNR is sane', $p >= 28.0, sprintf('%.2f dB', $p));
} else {
    skip('alpha and quality round-trip', 'dwebp not on PATH');
}

echo "\n4. colour profiles\n";
$profile = "not-really-an-icc-profile\x00\x01\x02" . str_repeat("\x5a", 200);
$encI = Vp8LossyEncoder::fromQuality(80);
$encI->iccProfile = $profile;
$webpI = $encI->encode($rgb, $w, $h)['webp'];
check('container is VP8X + ICCP + VP8 ', webpChunks($webpI) === ['VP8X', 'ICCP', 'VP8 '], implode(' ', webpChunks($webpI)));
check('ICCP payload is byte-exact', webpChunk($webpI, 'ICCP') === $profile);
check('VP8X declares the ICC flag', (ord((string) webpChunk($webpI, 'VP8X')[0]) & 0x20) !== 0);

$encB = Vp8LossyEncoder::fromQuality(80);
$encB->alphaPlane = $alpha;
$encB->iccProfile = $profile;
$both = $encB->encode($rgb, $w, $h)['webp'];
check('alpha and ICC coexist in the documented order', webpChunks($both) === ['VP8X', 'ICCP', 'ALPH', 'VP8 '], implode(' ', webpChunks($both)));

echo "\n5. GD loaders and the staged path\n";
if (!$haveGd) {
    skip('PNG/JPEG loaders, PlaneStore', 'ext-gd not loaded');
} else {
    $png = $tmp . '/src.png';
    $image = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $o = ($y * $w + $x) * 3;
            imagesetpixel($image, $x, $y, imagecolorallocate($image, ord($rgb[$o]), ord($rgb[$o + 1]), ord($rgb[$o + 2])));
        }
    }
    imagepng($image, $png);
    imagedestroy($image);

    [$pw, $ph] = getimagesize($png);
    $loaded = ImageInput::load($png, $pw, $ph, $loadedAlpha, $loadedIcc);
    check('ImageInput::load returns the right number of bytes', strlen($loaded) === $pw * $ph * 3);
    check('a PNG round-trips pixel for pixel', $loaded === $rgb);
    check('no phantom alpha plane', $loadedAlpha === null);
    check('no phantom ICC profile', $loadedIcc === null);

    $meta = PlaneStore::prepare($png, $tmp . '/work');
    $store = PlaneStore::open($tmp . '/work');
    check('staged geometry matches the source', $meta['w'] === $w && $meta['h'] === $h);
    check('staged planes are complete', $meta['plane_bytes']['y'] === $meta['wp'] * $meta['hp']);

    $single = Vp8LossyEncoder::fromQuality(80);
    $singleRes = $single->encode($rgb, $w, $h);

    // Two-phase, exactly as the resumable queue runs it: count the frame,
    // derive the probabilities, then encode for real with those odds.
    $mbh = (int) $meta['mbh'];
    $counter = Vp8LossyEncoder::fromQuality(80);
    $counter->initFrame($w, $h);
    $counter->enableStats();
    [$by, $bu, $bv] = $store->readBand(0, $mbh);
    $counter->beginBand(0, $mbh, $by, $bu, $bv);
    $counter->encodeRowRange(0, $mbh);
    $counter->adaptFromStats();
    $counter->deriveSkipProbability();

    $staged = Vp8LossyEncoder::fromQuality(80);
    $staged->skipProb = $counter->skipProb;
    $probs = $counter->coeffProbs();
    if (is_array($probs) && count($probs) === 1056) {
        $staged->setCoeffProbs($probs);
    }
    $staged->initFrame($w, $h);
    unset($by, $bu, $bv);
    $staged->beginBand(0, $mbh, ...$store->readBand(0, $mbh));
    $staged->encodeRowRange(0, $mbh);
    $stagedRes = $staged->finishFrame();

    check(
        'chunked output is byte-identical to single shot',
        $stagedRes['webp'] === $singleRes['webp'],
        md5($stagedRes['webp']) . ' vs ' . md5($singleRes['webp'])
    );
}

echo "\n6. the bundled readers, with GD switched off\n";
if (!$haveGd) {
    skip('bundled reader comparison', 'ext-gd missing, so there is nothing to compare against');
} else {
    // A palette PNG whose palette carries alpha, and a baseline JPEG: one
    // exercises the paths libgd approximates, the other the whole DCT.
    $pal = $tmp . '/pal.png';
    $image = imagecreate(64, 48);
    imagealphablending($image, false);
    $opaque = imagecolorallocatealpha($image, 20, 180, 40, 0);
    $half = imagecolorallocatealpha($image, 200, 30, 60, 63);
    imagefilledrectangle($image, 0, 0, 31, 47, $opaque);
    imagefilledrectangle($image, 32, 0, 63, 47, $half);
    imagepng($image, $pal);
    imagedestroy($image);

    $jpg = $tmp . '/src.jpg';
    $image = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $o = ($y * $w + $x) * 3;
            imagesetpixel($image, $x, $y, imagecolorallocate($image, ord($rgb[$o]), ord($rgb[$o + 1]), ord($rgb[$o + 2])));
        }
    }
    imagejpeg($image, $jpg, 90);
    imagedestroy($image);

    /** @return array{0:string,1:?string} [rgb, alpha] decoded through GD */
    $gdDecode = static function (string $file): array {
        $im = str_ends_with($file, '.png') ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, false);
        $out = '';
        $alpha = '';
        for ($y = 0; $y < imagesy($im); $y++) {
            for ($x = 0; $x < imagesx($im); $x++) {
                $c = imagecolorat($im, $x, $y);
                $out .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
                $a = ($c >> 24) & 0x7F;
                $alpha .= chr($a === 0 ? 255 : ((127 - $a) << 1));
            }
        }
        imagedestroy($im);

        return [$out, $alpha];
    };

    ImageInput::$gdAvailable = false;

    $bundled = ImageInput::pixels($pal);
    check('palette PNG decoded by the bundled reader', $bundled['reader'] === 'bundled' && $bundled['w'] === 64);
    [$gdRgb, $gdAlpha] = $gdDecode($pal);
    check('bundled RGB matches libgd exactly', $bundled['rgb'] === $gdRgb);
    $alphaDelta = 0;
    for ($i = 0; $i < strlen((string) $bundled['alpha']); $i++) {
        $alphaDelta = max($alphaDelta, abs(ord($bundled['alpha'][$i]) - ord($gdAlpha[$i])));
    }
    check('bundled alpha agrees with libgd within its 7-bit rounding', $alphaDelta <= 1, "max delta $alphaDelta");

    $bundledJpeg = ImageInput::pixels($jpg);
    check('baseline JPEG decoded by the bundled reader', $bundledJpeg['reader'] === 'bundled');
    [$gdJpeg] = $gdDecode($jpg);
    $p = psnr($bundledJpeg['rgb'], $gdJpeg);
    check('bundled JPEG agrees with libjpeg', $p >= 45.0, sprintf('%.2f dB', $p));

    // The staged plane writer reads through the same chooser, so a job that
    // runs with no GD anywhere must still produce the single-shot bytes.
    $single = Vp8LossyEncoder::fromQuality(80);
    $single->adaptProbs = false;
    $singleWebp = $single->encode($bundledJpeg['rgb'], $w, $h)['webp'];

    PlaneStore::prepare($jpg, $tmp . '/work-bundled');
    $store = PlaneStore::open($tmp . '/work-bundled');
    $mbh = (int) $store->meta()['mbh'];
    $stagedEnc = Vp8LossyEncoder::fromQuality(80);
    $stagedEnc->adaptProbs = false;
    $stagedEnc->initFrame($w, $h);
    $stagedEnc->beginBand(0, $mbh, ...$store->readBand(0, $mbh));
    $stagedEnc->encodeRowRange(0, $mbh);
    $stagedWebp = $stagedEnc->finishFrame()['webp'];
    check(
        'bundled reader + staged path matches single shot',
        $stagedWebp === $singleWebp,
        md5($stagedWebp) . ' vs ' . md5($singleWebp)
    );

    ImageInput::$gdAvailable = null;
}

echo "\n7. a WebP input is skipped, not re-encoded\n";
// Both containers this package writes, on disk, so the detector is exercised
// against real files rather than a hand-built header where it can.
$ourWebp = $tmp . '/our-own.webp';
$ourWebpA = $tmp . '/our-own-alpha.webp';
file_put_contents($ourWebp, $webp);
file_put_contents($ourWebpA, $webpA);

$metaW = ImageMeta::read($ourWebp);
check('detected from the bytes', $metaW->isWebp() && $metaW->type === IMAGETYPE_WEBP);
check('simple container: size out of the VP8  header', $metaW->width === $w && $metaW->height === $h, "{$metaW->width}x{$metaW->height}");
check('describe() names the container', $metaW->describe() === 'webp (VP8)', $metaW->describe());

$metaWA = ImageMeta::read($ourWebpA);
check('extended container: size out of the VP8X canvas', $metaWA->width === $w && $metaWA->height === $h, "{$metaWA->width}x{$metaWA->height}");
check('extended container: describe() says VP8X', $metaWA->describe() === 'webp (VP8X)', $metaWA->describe());

// VP8L (lossless) is the third header layout and we never write one, so it is
// synthesised: signature byte, then width-1 and height-1 in 14 bits each.
$vp8lChunk = 'VP8L' . pack('V', 5) . "\x2f" . pack('V', (320 - 1) | ((240 - 1) << 14)) . "\x00";
$vp8lFile = $tmp . '/lossless.webp';
file_put_contents($vp8lFile, 'RIFF' . pack('V', 4 + strlen($vp8lChunk)) . 'WEBP' . $vp8lChunk);
$metaL = ImageMeta::read($vp8lFile);
check('lossless container: size out of the VP8L header', $metaL->isWebp() && $metaL->width === 320 && $metaL->height === 240, "{$metaL->width}x{$metaL->height}");

// Content, not extension, in both directions.
$pngHeader = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('N2', 40, 30) . "\x08\x02\x00\x00\x00" . pack('N', 0);
$misnamedPng = $tmp . '/actually-a-png.webp';
file_put_contents($misnamedPng, $pngHeader);
$metaP = ImageMeta::read($misnamedPng);
check('a PNG named .webp is read as a PNG', !$metaP->isWebp() && $metaP->type === IMAGETYPE_PNG && $metaP->width === 40);

$misnamedWebp = $tmp . '/actually-a-webp.jpg';
file_put_contents($misnamedWebp, $webp);
check('a WebP named .jpg is still a WebP', ImageInput::isWebp($misnamedWebp));

// A RIFF file that is not WEBP must not be mistaken for one.
$wav = $tmp . '/audio.wav';
file_put_contents($wav, 'RIFF' . pack('V', 36) . 'WAVE' . 'fmt ' . pack('V', 16) . str_repeat("\x00", 16) . 'data' . pack('V', 0));
$wavRejected = false;
try {
    ImageMeta::read($wav);
} catch (SkippedInput $e) {
    // The one answer that would be wrong: calling an audio file a WebP.
} catch (\RuntimeException $e) {
    // Refusing it is right, whichever way it refuses.
    $wavRejected = true;
}
check('RIFF/WAVE is not mistaken for WebP', $wavRejected);

// Named apart from the suite's own $skipped counter, which the summary uses.
$skipException = null;
try {
    ImageInput::pixels($ourWebp);
} catch (SkippedInput $e) {
    $skipException = $e;
}
check('ImageInput::pixels() reports a skip', $skipException instanceof SkippedInput);
check(
    'the skip explains itself, in the machine-readable form a queue wants',
    $skipException !== null
        && $skipException->reason() === 'webp (already the target format)'
        && $skipException->slug() === 'skipped_webp'
        && $skipException->path === $ourWebp
        && $skipException->meta?->width === $w
);
check('the skip is a RuntimeException, so callers that never heard of it still work', $skipException instanceof \RuntimeException);

$stagedSkip = null;
try {
    PlaneStore::prepare($ourWebp, $tmp . '/webp-work');
} catch (SkippedInput $e) {
    $stagedSkip = $e;
}
check('the staging queue skips it too', $stagedSkip instanceof SkippedInput && !is_dir($tmp . '/webp-work'));

if ($haveGdWebp) {
    // The escape hatch: a caller who really does want a WebP decoded again.
    ImageInput::$skipWebp = false;
    try {
        $again = ImageInput::pixels($ourWebp);
        check('--reencode-webp decodes through GD', $again['reader'] === 'gd' && strlen($again['rgb']) === $w * $h * 3);
        $p = psnr($again['rgb'], $rgb);
        check('and the pixels are what we encoded', $p >= 28.0, sprintf('%.2f dB', $p));
    } catch (\RuntimeException $e) {
        check('--reencode-webp decodes through GD', false, $e->getMessage());
    } finally {
        ImageInput::$skipWebp = true;
    }
} else {
    skip('--reencode-webp decodes through GD', 'this GD has no WebP decoder');
}

// The CLI is what a queue actually calls, so its contract is checked too:
// nothing written, and an exit code that means "skipped" rather than "wrote".
$cliOut = $tmp . '/cli-out.webp';
@unlink($cliOut);
$bin = $root . '/bin/picowebp';
$run = static function (string ...$args) use ($bin): array {
    $lines = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . implode(' ', array_map('escapeshellarg', $args)), $lines, $status);

    return [$status, implode("\n", $lines)];
};

[$status, $out] = $run($ourWebp, $cliOut, '--json');
$report = json_decode($out, true);
check('CLI exits 4 for a WebP', $status === 4, "exit $status");
check('CLI writes no file', !is_file($cliOut));
check(
    'CLI report says skipped, and says why',
    is_array($report)
        && ($report['status'] ?? '') === 'skipped'
        && ($report['reason'] ?? '') === 'webp (already the target format)'
        && ($report['source'] ?? '') === 'webp (VP8)'
);
check('CLI report carries the geometry', is_array($report) && ($report['width'] ?? 0) === $w && ($report['height'] ?? 0) === $h);

if ($haveGdWebp) {
    [$status, $out] = $run($ourWebp, $cliOut, '--reencode-webp', '--json');
    $report = json_decode($out, true);
    check('CLI --reencode-webp encodes instead of skipping', $status === 0 && is_file($cliOut), "exit $status");
    check('and reports ordinary numbers', is_array($report) && ($report['width'] ?? 0) === $w && ($report['bytes'] ?? 0) > 0);
    @unlink($cliOut);
} else {
    skip('CLI --reencode-webp', 'this GD has no WebP decoder');
}

// An unwritable destination must not look like success.  Two ways to arrange
// one: a path under a directory that does not exist, and a directory itself.
$rawPixels = $tmp . '/pixels.raw';
file_put_contents($rawPixels, synthRgb(16, 16));
$stderrRun = static function (string ...$args) use ($bin): array {
    $lines = [];
    $status = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1',
        $lines,
        $status
    );

    return [$status, implode("\n", $lines)];
};

$missingDir = $tmp . '/no-such-dir/out.webp';
[$status, $text] = $stderrRun($rawPixels, $missingDir, '--rgb=16x16');
check('CLI exits 1 when the output directory does not exist', $status === 1, "exit $status");
check('CLI says the destination is not writable', str_contains($text, 'cannot write'), trim($text));
check('CLI writes nothing to a destination like that', !is_file($missingDir));

[$status, $text] = $stderrRun($rawPixels, $tmp, '--rgb=16x16');
check('CLI exits 1 when the output path is a directory', $status === 1, "exit $status");

[$status, $text] = $stderrRun($rawPixels, $cliOut, '--rgb=16x16', '--dump-yuv=' . $tmp . '/no-such-dir/planes.yuv');
check('CLI exits 1 when --dump-yuv cannot be written', $status === 1, "exit $status");

// And a good write still reports success with the right byte count.
[$status, $out] = $run($rawPixels, $cliOut, '--rgb=16x16', '--json');
$report = json_decode($out, true);
check(
    'CLI exits 0 and the file on disk matches the reported size',
    $status === 0 && is_file($cliOut) && ($report['bytes'] ?? 0) === filesize($cliOut),
    "exit $status, " . (is_file($cliOut) ? filesize($cliOut) . ' B on disk' : 'no file')
);
@unlink($cliOut);

echo "\n8. reader state and baseline JPEG scans\n";
foreach ([0 => ["\x14", pack('n', 20)], 2 => ["\x14\x28\x3c", pack('n3', 20, 40, 60)]] as $type => [$pixel, $key]) {
    $head = "\x89PNG\r\n\x1a\n" . pngChunk('IHDR', pack('N2C5', 1, 1, 8, $type, 0, 0, 0));
    $tail = pngChunk('IDAT', gzcompress("\x00" . $pixel)) . pngChunk('IEND', '');
    $reader = new PngReader();
    $transparent = $reader->decode($head . pngChunk('tRNS', $key) . $tail);
    $plainPng = $head . $tail;
    $opaque = $reader->decode($plainPng);
    check("PNG type $type does not inherit the previous transparency key", $transparent['alpha'] === "\x00" && $opaque['alpha'] === null);
}

if ($haveGd) {
    // Seed per-image metadata on a one-MCU image. The next JPEG has many
    // MCUs and no restart markers, profile, or Adobe colour transform.
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagejpeg($image);
    $smallJpeg = (string) ob_get_clean();
    imagedestroy($image);
    $iccSegment = "ICC_PROFILE\x00\x01\x01test-profile";
    $adobeSegment = 'Adobe' . pack('n3C', 100, 0, 0, 0);
    $taggedJpeg = substr($smallJpeg, 0, 2)
        . "\xff\xe2" . pack('n', strlen($iccSegment) + 2) . $iccSegment
        . "\xff\xee" . pack('n', strlen($adobeSegment) + 2) . $adobeSegment
        . "\xff\xdd\x00\x04\x00\x01" . substr($smallJpeg, 2);
    $reader = new JpegReader();
    $reader->decode($taggedJpeg);
    try {
        $reused = $reader->decode((string) file_get_contents($jpg));
        $fresh = JpegReader::read($jpg);
        check('JPEG reuse resets ICC, colour transform, and restart interval', $reused === $fresh && $reused['icc'] === null);
    } catch (\InvalidArgumentException $e) {
        check('JPEG reuse resets ICC, colour transform, and restart interval', false, $e->getMessage());
    }
} else {
    skip('JPEG reader reuse', 'ext-gd missing for fixture generation');
}

if (trim((string) shell_exec('command -v cjpeg 2>/dev/null')) !== '') {
    $ppm = $tmp . '/scans.ppm';
    // Odd dimensions exercise partial component blocks in separate scans.
    file_put_contents($ppm, "P6\n33 19\n255\n" . synthRgb(33, 19));
    $scanRgb = [];
    foreach (['0 1 2;', '0; 1; 2;', '2; 0; 1;'] as $i => $script) {
        $scanFile = $tmp . '/scans.txt';
        $jpegFile = $tmp . "/scans-$i.jpg";
        file_put_contents($scanFile, $script . "\n");
        exec('cjpeg -quality 90 -restart 2B -scans ' . escapeshellarg($scanFile)
            . ' -outfile ' . escapeshellarg($jpegFile) . ' ' . escapeshellarg($ppm) . ' 2>&1', $lines, $status);
        check("baseline JPEG scan fixture $i generated", $status === 0);
        try {
            $scanRgb[$i] = JpegReader::read($jpegFile)['rgb'];
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            check("baseline JPEG scan fixture $i decoded", false, $e->getMessage());
        }
    }
    check('separate and reordered JPEG scans match interleaved pixels', count($scanRgb) === 3 && $scanRgb[0] === $scanRgb[1] && $scanRgb[0] === $scanRgb[2]);
} else {
    skip('separate baseline JPEG scans', 'cjpeg not on PATH');
}

echo "\n9. encoder boundaries and resumed state\n";
foreach ([[0, 1], [1, 0], [-1, 1], [16384, 1], [1, 16384]] as [$badW, $badH]) {
    $rejected = false;
    try {
        (new Vp8LossyEncoder())->initFrame($badW, $badH);
    } catch (\InvalidArgumentException $e) {
        $rejected = true;
    }
    check("invalid VP8 dimensions {$badW}x{$badH} rejected", $rejected);
}
$rejected = false;
try {
    (new Vp8LossyEncoder())->encode("\x00\x00", 1, 1);
} catch (\InvalidArgumentException $e) {
    $rejected = true;
}
check('short RGB buffer rejected before reading pixels', $rejected);

// Resume with the same geometry and options in a new object. Adapted odds
// belong to the frame state and must survive without separate bookkeeping.
$reference = Vp8LossyEncoder::fromQuality(80);
$referenceWebp = $reference->encode($rgb, $w, $h)['webp'];
check('resume fixture has adapted coefficient probabilities', $reference->probUpdates() > 0);
$resumePng = $tmp . '/resume.png';
$scanlines = '';
foreach (str_split($rgb, $w * 3) as $row) {
    $scanlines .= "\x00" . $row;
}
file_put_contents($resumePng, "\x89PNG\r\n\x1a\n"
    . pngChunk('IHDR', pack('N2C5', $w, $h, 8, 2, 0, 0, 0))
    . pngChunk('IDAT', gzcompress($scanlines)) . pngChunk('IEND', ''));
ImageInput::$gdAvailable = false;
PlaneStore::prepare($resumePng, $tmp . '/resume-work');
ImageInput::$gdAvailable = null;
$resumeStore = PlaneStore::open($tmp . '/resume-work');
$resuming = Vp8LossyEncoder::fromQuality(80);
$resuming->setCoeffProbs($reference->coeffProbs());
$resuming->skipProb = $reference->skipProb;
$resuming->initFrame($w, $h);
for ($mby = 0; $mby < intdiv($h + 15, 16); $mby++) {
    $resuming->beginBand($mby, 1, ...$resumeStore->readBand($mby, 1));
    $resuming->encodeRowRange($mby, 1);
    $resuming->compactReconWindow();
    $state = unserialize(serialize($resuming->exportState()));
    $resuming = Vp8LossyEncoder::fromQuality(80);
    $resuming->initFrame($w, $h);
    $resuming->importState($state);
}
check('resuming an adapted frame preserves the exact WebP bytes', $resuming->finishFrame()['webp'] === $referenceWebp);

if ($haveDwebp) {
    $lowRgb = '';
    for ($i = 0; $i < 3 * 17; $i++) {
        $lowRgb .= chr(($i * 37) & 255) . chr(($i * 71) & 255) . chr(($i * 13) & 255);
    }
    $low = Vp8LossyEncoder::fromQuality(0)->encode($lowRgb, 3, 17);
    file_put_contents($tmp . '/low.webp', $low['webp']);
    exec('dwebp -quiet -yuv ' . escapeshellarg($tmp . '/low.webp') . ' -o ' . escapeshellarg($tmp . '/low.yuv') . ' 2>&1', $lines, $status);
    check('quality 0 reconstruction matches libwebp exactly', $status === 0 && file_get_contents($tmp . '/low.yuv') === Vp8LossyEncoder::displayPlanes($low, 3, 17));
}

echo "\n10. alpha filters and CLI options\n";
$smallAlpha = synthAlpha(9, 5);
foreach ([0, 1, 2, 3] as $filter) {
    $forced = Vp8LossyEncoder::fromQuality(80);
    $forced->alphaPlane = $smallAlpha;
    $forced->alphaFilter = $filter;
    $forcedWebp = $forced->encode(synthRgb(9, 5), 9, 5)['webp'];
    check("forced alpha filter $filter is reported correctly", AlphaCoder::lastFilter() === $filter);
    if ($haveDwebp) {
        [$pam, $depth] = dwebpPam($forcedWebp, $tmp) ?? ['', 0];
        check("forced alpha filter $filter round-trips exactly", $depth === 4 && alphaOf(pamBody($pam)) === $smallAlpha);
    }
}
foreach ([-2, 4, 64] as $filter) {
    $rejected = false;
    try {
        AlphaCoder::encode($smallAlpha, 9, 5, $filter);
    } catch (\InvalidArgumentException $e) {
        $rejected = true;
    }
    check("invalid alpha filter $filter rejected", $rejected);
}

[$status, $out] = $run($rawPixels, $cliOut, '--rgb=16x16', '--json');
$report = json_decode($out, true);
$defaultWebp = (string) file_get_contents($cliOut);
[$explicitStatus] = $run($rawPixels, $cliOut, '--rgb=16x16', '--quality=80', '--json');
check('CLI default is the advertised quality 80', $status === 0 && $explicitStatus === 0 && ($report['quality'] ?? null) === 80 && $defaultWebp === file_get_contents($cliOut));
[$status, $out] = $run($rawPixels, $cliOut, '40', '--rgb=16x16', '--json');
$report = json_decode($out, true);
check('explicit positional qindex remains supported', $status === 0 && ($report['qindex'] ?? null) === 40);

$rawAlpha = $tmp . '/alpha.raw';
file_put_contents($rawAlpha, synthAlpha(16, 16));
$sentinel = 'existing output';
file_put_contents($cliOut, $sentinel);
[$status, $text] = $stderrRun($rawPixels, $cliOut, '--rgb=16x16', '--alpha-plane=' . $rawAlpha, '--alpha=fail');
check('raw alpha obeys --alpha=fail without overwriting output', $status === 3 && file_get_contents($cliOut) === $sentinel);
[$status, $text] = $stderrRun($rawPixels, $cliOut, '--rgb=16x16', '--alpha-plane=' . $rawAlpha, '--alpha-filter=4');
check('invalid alpha filter exits 1 without a fatal error or output change', $status === 1 && !str_contains($text, 'Fatal error') && file_get_contents($cliOut) === $sentinel);

$brokenPng = $tmp . '/truncated.png';
file_put_contents($brokenPng, substr($plainPng, 0, -12));
[$status, $text] = $stderrRun($brokenPng, $cliOut, '--no-gd');
check('malformed bundled input exits 1 with a concise error', $status === 1 && str_contains($text, 'missing IEND') && !str_contains($text, 'Fatal error') && file_get_contents($cliOut) === $sentinel);

rmTree($tmp);

printf("\n%s\n", $failures === 0
    ? sprintf('all checks passed%s', $skipped > 0 ? " ($skipped skipped)" : '')
    : "$failures check(s) FAILED");
exit($failures === 0 ? 0 : 1);
