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
foreach (['Vp8Tables', 'AlphaCoder', 'BmpReader', 'ImageMeta', 'SkippedInput', 'PngReader', 'JpegReader', 'Vp8LossyEncoder', 'ImageInput', 'PlaneStore'] as $file) {
    require_once $root . '/src/' . $file . '.php';
}

use PicoWebP\Vp8\AlphaCoder;
use PicoWebP\Vp8\BmpReader;
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

/**
 * Assemble a BMP around a caller-built pixel payload, for the fixtures this
 * suite needs: the 12-byte OS/2 core header, BITMAPINFOHEADER, and the V2–V5
 * headers that add channel masks and an embedded profile.
 *
 * The three header fields that matter for the geometry — the file size, the
 * pixel offset and the profile offset — are computed here, so a fixture can
 * only be wrong on purpose ($opts['offBits']).
 *
 * @param array<string,mixed> $opts dibSize, compression, masks, extra, palette, clrUsed,
 *                                  topDown, cstype, profile, profileFromDib, offBits
 */
function buildBmp(int $w, int $h, int $bits, string $payload, array $opts = []): string
{
    $dibSize = (int) ($opts['dibSize'] ?? 40);
    $core = $dibSize === 12;
    $compression = (int) ($opts['compression'] ?? 0);
    $masks = $opts['masks'] ?? [0, 0, 0, 0];
    $extra = (string) ($opts['extra'] ?? '');
    $palette = (string) ($opts['palette'] ?? '');
    $profile = (string) ($opts['profile'] ?? '');
    $clrUsed = (int) ($opts['clrUsed'] ?? 0);
    $topDown = (bool) ($opts['topDown'] ?? false);
    $offBits = (int) ($opts['offBits'] ?? (14 + $dibSize + strlen($extra) + strlen($palette)));
    // bV5ProfileData is documented as an offset from the DIB header; plenty of
    // writers store an absolute file offset instead.  Both get tested.
    $profileAt = $offBits + strlen($payload) - (($opts['profileFromDib'] ?? false) ? 14 : 0);

    if ($core) {
        $dib = pack('Vvvvv', 12, $w, $h, 1, $bits);
    } else {
        $dib = pack('V', $dibSize) . pack(
            'VVvvVVVVVV',
            $w,
            ($topDown ? -$h : $h) & 0xFFFFFFFF,
            1,
            $bits,
            $compression,
            0,
            0,
            0,
            $clrUsed,
            0
        );
        if ($dibSize >= 52) {
            $dib .= pack('V3', $masks[0], $masks[1], $masks[2]);
        }
        if ($dibSize >= 56) {
            $dib .= pack('V', $masks[3]);
        }
        if ($dibSize >= 108) {
            // The colour-space tag is a four-byte field, not the name MSDN
            // spells it with: LCS_sRGB is the DWORD 'sRGB', which on disk is
            // BGRs.  Only the embedded-profile tag means anything here.
            $dib .= ($opts['cstype'] ?? 'BGRs') . str_repeat("\x00", 48);
        }
        if ($dibSize >= 124) {
            $dib .= pack('VVVV', 0, $profile === '' ? 0 : $profileAt, strlen($profile), 0);
        }
    }

    $body = $dib . $extra . $palette . $payload . $profile;

    return 'BM' . pack('VvvV', 14 + strlen($body), 0, 0, $offBits) . $body;
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

echo "\n11. BMP input\n";
// A 5x3 source with an odd width, so every 24-bit row has padding to skip.
$bw = 5;
$bh = 3;
$bmpRgb = '';
$bmpAlpha = '';
for ($y = 0; $y < $bh; $y++) {
    for ($x = 0; $x < $bw; $x++) {
        $bmpRgb .= chr(10 + $x * 7) . chr(20 + $y * 11) . chr(190 - $x * 3 - $y);
        $bmpAlpha .= chr(255 - ($x + $y) * 13);
    }
}
/** One stored row, in BMP's own channel order: blue, green, red. */
$row24 = static function (int $y) use ($bmpRgb, $bw): string {
    $row = '';
    for ($x = 0; $x < $bw; $x++) {
        $o = ($y * $bw + $x) * 3;
        $row .= $bmpRgb[$o + 2] . $bmpRgb[$o + 1] . $bmpRgb[$o];
    }

    return $row . str_repeat("\x00", (4 - strlen($row) % 4) % 4);
};
$bottomUp = '';
$topRows = '';
for ($y = $bh - 1; $y >= 0; $y--) {
    $bottomUp .= $row24($y);
}
for ($y = 0; $y < $bh; $y++) {
    $topRows .= $row24($y);
}

$bmp24 = $tmp . '/plain.bmp';
file_put_contents($bmp24, buildBmp($bw, $bh, 24, $bottomUp));
$bmpMeta = ImageMeta::read($bmp24);
check(
    'a BMP is recognised from its bytes',
    $bmpMeta->type === IMAGETYPE_BMP && $bmpMeta->width === $bw && $bmpMeta->height === $bh
);
check('describe() names the layout', $bmpMeta->describe() === 'bmp 24bpp rgb', $bmpMeta->describe());
check('a 24-bit BMP is opaque', !$bmpMeta->mayHaveAlpha());
check('rows are unpadded, bottom-up and in RGB order', BmpReader::read($bmp24)['rgb'] === $bmpRgb);

$bmpTop = $tmp . '/top-down.bmp';
file_put_contents($bmpTop, buildBmp($bw, $bh, 24, $topRows, ['topDown' => true]));
check('a negative height means top-down rows', BmpReader::read($bmpTop)['rgb'] === $bmpRgb);

$viaInput = ImageInput::pixels($bmp24);
check(
    'ImageInput reads BMP with its own reader, GD or not',
    $viaInput['reader'] === 'bundled' && $viaInput['rgb'] === $bmpRgb && $viaInput['alpha'] === null
);
ImageInput::$gdAvailable = false;
$withoutGd = ImageInput::pixels($bmp24);
ImageInput::$gdAvailable = null;
check('and produces the same pixels with GD switched off', $withoutGd['rgb'] === $bmpRgb);

$bmpWebp = Vp8LossyEncoder::fromQuality(80)->encode($viaInput['rgb'], $bw, $bh)['webp'];
check('a BMP encodes to the same bytes as its pixels', $bmpWebp === Vp8LossyEncoder::fromQuality(80)->encode($bmpRgb, $bw, $bh)['webp']);

// 32-bit BGRA behind a V4 header: the alpha mask is what makes it translucent.
$bgra = '';
$zeroAlpha = '';
$bgrx = '';
for ($y = $bh - 1; $y >= 0; $y--) {
    for ($x = 0; $x < $bw; $x++) {
        $o = ($y * $bw + $x) * 3;
        $bgra .= $bmpRgb[$o + 2] . $bmpRgb[$o + 1] . $bmpRgb[$o] . $bmpAlpha[$y * $bw + $x];
        $zeroAlpha .= $bmpRgb[$o + 2] . $bmpRgb[$o + 1] . $bmpRgb[$o] . "\x00";
        $bgrx .= $bmpRgb[$o + 2] . $bmpRgb[$o + 1] . $bmpRgb[$o] . "\x5a";
    }
}
$alphaMasks = [0x00FF0000, 0x0000FF00, 0x000000FF, 0xFF000000];

$bmp32 = $tmp . '/alpha32.bmp';
file_put_contents($bmp32, buildBmp($bw, $bh, 32, $bgra, ['dibSize' => 108, 'compression' => 3, 'masks' => $alphaMasks]));
$decoded32 = BmpReader::read($bmp32);
check('a V4 header alpha mask is decoded', $decoded32['rgb'] === $bmpRgb && $decoded32['alpha'] === $bmpAlpha);
check('ImageMeta reports the alpha channel', ImageMeta::read($bmp32)->mayHaveAlpha());
check('describe() says which layout it is', ImageMeta::read($bmp32)->describe() === 'bmp 32bpp bitfields alpha', ImageMeta::read($bmp32)->describe());

$bmpZero = $tmp . '/zero-alpha.bmp';
file_put_contents($bmpZero, buildBmp($bw, $bh, 32, $zeroAlpha, ['dibSize' => 108, 'compression' => 3, 'masks' => $alphaMasks]));
check('a declared all-zero alpha channel stays transparent', BmpReader::read($bmpZero)['alpha'] === str_repeat("\x00", $bw * $bh));

$bmpOpaque = $tmp . '/opaque-alpha.bmp';
file_put_contents($bmpOpaque, buildBmp(1, 1, 32, "\x30\x20\x10\xff", [
    'dibSize' => 108, 'compression' => 3, 'masks' => $alphaMasks,
]));
check('a fully opaque BMP does not return an alpha plane', ImageInput::pixels($bmpOpaque)['alpha'] === null);

$bmp32x = $tmp . '/bgrx.bmp';
file_put_contents($bmp32x, buildBmp($bw, $bh, 32, $bgrx));
$decoded32x = BmpReader::read($bmp32x);
check('32-bit BI_RGB ignores its fourth byte', $decoded32x['alpha'] === null && $decoded32x['rgb'] === $bmpRgb);

$bmpRgbMasks = $tmp . '/rgb-unused-masks.bmp';
file_put_contents($bmpRgbMasks, buildBmp(1, 1, 32, "\x30\x20\x10\x80", [
    'dibSize' => 108, 'masks' => [0xff, 0xff00, 0xff0000, 0xff000000],
]));
$unusedMasks = ImageInput::pixels($bmpRgbMasks);
check(
    'BI_RGB ignores V4 masks and keeps BGRX opaque',
    $unusedMasks['rgb'] === "\x10\x20\x30" && $unusedMasks['alpha'] === null
        && !ImageMeta::read($bmpRgbMasks)->mayHaveAlpha()
);

// The same alpha masks behind a 40-byte header, where they live in the block
// that follows it rather than in the header itself.
$bmpAbf = $tmp . '/alpha-out-of-band.bmp';
file_put_contents($bmpAbf, buildBmp($bw, $bh, 32, $bgra, [
    'compression' => 6,
    'extra' => pack('V4', ...$alphaMasks),
]));
$decodedAbf = BmpReader::read($bmpAbf);
check('masks stored behind a 40-byte header work too', $decodedAbf['rgb'] === $bmpRgb && $decodedAbf['alpha'] === $bmpAlpha);

// Palette images at every depth, including an index past the end of the
// palette — viewers paint that black, and so does this reader.
$palette = [];
for ($i = 0; $i < 16; $i++) {
    $palette[$i] = chr(5 + $i * 15) . chr(240 - $i * 15) . chr($i * 7);
}
$paletteBytes = '';
foreach ($palette as $triple) {
    $paletteBytes .= $triple[2] . $triple[1] . $triple[0] . "\x00";
}
$corePaletteBytes = '';
foreach (array_slice($palette, 0, 8, true) as $triple) {
    $corePaletteBytes .= $triple[2] . $triple[1] . $triple[0];
}
$paletteRgb = static function (array $rows) use ($palette): string {
    $out = '';
    foreach ($rows as $row) {
        foreach ($row as $index) {
            $out .= $palette[$index] ?? "\x00\x00\x00";
        }
    }

    return $out;
};

$rows8 = [[0, 1, 2, 3, 4], [4, 3, 2, 1, 0], [1, 1, 2, 2, 99]];
$payload8 = '';
for ($y = $bh - 1; $y >= 0; $y--) {
    foreach ($rows8[$y] as $index) {
        $payload8 .= chr($index);
    }
    $payload8 .= str_repeat("\x00", (4 - $bw % 4) % 4);
}
$bmp8 = $tmp . '/palette8.bmp';
file_put_contents($bmp8, buildBmp($bw, $bh, 8, $payload8, ['palette' => $paletteBytes]));
$decoded8 = BmpReader::read($bmp8);
check('an 8-bit palette BMP resolves through its palette', $decoded8['rgb'] === $paletteRgb($rows8));
check(
    'an index past the palette is black, not a fatal error',
    substr($decoded8['rgb'], ($bh - 1) * $bw * 3 + ($bw - 1) * 3, 3) === "\x00\x00\x00"
);

$bmpCore = $tmp . '/core-header.bmp';
file_put_contents($bmpCore, buildBmp($bw, $bh, 8, $payload8, ['dibSize' => 12, 'palette' => $corePaletteBytes]));
check('the OS/2 core header, with three-byte palette entries, works', BmpReader::read($bmpCore)['rgb'] === $paletteRgb($rows8));

$rows4 = [[0, 1, 2, 3, 4], [4, 2, 0, 3, 1], [1, 0, 4, 2, 3]];
$payload4 = '';
for ($y = $bh - 1; $y >= 0; $y--) {
    $row = '';
    for ($i = 0; $i < $bw; $i += 2) {
        $row .= chr(($rows4[$y][$i] << 4) | ($rows4[$y][$i + 1] ?? 0));
    }
    $payload4 .= $row . str_repeat("\x00", (4 - strlen($row) % 4) % 4);
}
$bmp4 = $tmp . '/palette4.bmp';
file_put_contents($bmp4, buildBmp($bw, $bh, 4, $payload4, ['palette' => $paletteBytes]));
check('4-bit rows unpack high nibble first', BmpReader::read($bmp4)['rgb'] === $paletteRgb($rows4));

$rows1 = [[1, 0, 1, 0, 1], [0, 0, 1, 1, 0], [1, 1, 1, 0, 0]];
$payload1 = '';
for ($y = $bh - 1; $y >= 0; $y--) {
    $byte = 0;
    for ($i = 0; $i < $bw; $i++) {
        if ($rows1[$y][$i] === 1) {
            $byte |= 1 << (7 - $i);
        }
    }
    $payload1 .= chr($byte) . str_repeat("\x00", 3);
}
$bmp1 = $tmp . '/palette1.bmp';
file_put_contents($bmp1, buildBmp($bw, $bh, 1, $payload1, ['palette' => $paletteBytes]));
check('1-bit rows unpack most significant bit first', BmpReader::read($bmp1)['rgb'] === $paletteRgb($rows1));

// 16-bit samples.  BMP does not fix how a 5- or 6-bit channel widens to a
// byte, so the expectation is built with the same nearest-step rule the reader
// documents, and the ImageMagick comparison below allows one step of slack.
$widen5 = static fn (int $v): int => intdiv($v * 255 + 15, 31);
$widen6 = static fn (int $v): int => intdiv($v * 255 + 31, 63);
$samples555 = [[3, 5, 7], [31, 0, 12], [0, 31, 1], [17, 17, 17], [9, 27, 4]];
$payload555 = '';
for ($i = 0; $i < $bw; $i++) {
    [$r5, $g5, $b5] = $samples555[$i];
    $payload555 .= pack('v', ($r5 << 10) | ($g5 << 5) | $b5);
}
$payload555 .= str_repeat("\x00", (4 - ($bw * 2) % 4) % 4);
$expect555 = '';
foreach ($samples555 as [$r5, $g5, $b5]) {
    $expect555 .= chr($widen5($r5)) . chr($widen5($g5)) . chr($widen5($b5));
}
$bmp555 = $tmp . '/rgb555.bmp';
file_put_contents($bmp555, buildBmp($bw, 1, 16, $payload555));
check('16-bit BI_RGB defaults to 5-5-5', BmpReader::read($bmp555)['rgb'] === $expect555);

$samples565 = [[5, 12, 9], [31, 63, 0], [0, 0, 31], [21, 40, 17], [1, 62, 30]];
$payload565 = '';
for ($i = 0; $i < $bw; $i++) {
    [$r5, $g6, $b5] = $samples565[$i];
    $payload565 .= pack('v', ($r5 << 11) | ($g6 << 5) | $b5);
}
$payload565 .= str_repeat("\x00", (4 - ($bw * 2) % 4) % 4);
$expect565 = '';
foreach ($samples565 as [$r5, $g6, $b5]) {
    $expect565 .= chr($widen5($r5)) . chr($widen6($g6)) . chr($widen5($b5));
}
$bmp565 = $tmp . '/rgb565.bmp';
file_put_contents($bmp565, buildBmp($bw, 1, 16, $payload565, [
    'compression' => 3,
    'extra' => pack('V3', 0xF800, 0x07E0, 0x001F),
]));
check('16-bit BI_BITFIELDS honours 5-6-5 masks', BmpReader::read($bmp565)['rgb'] === $expect565);

// RLE8: an encoded run, an end-of-line, an absolute run with its pad byte, a
// delta jump and an explicit end of bitmap.  Rows run bottom-up.
$rowsRle8 = [[4, 0, 0, 0, 0], [3, 3, 3, 3, 3], [1, 1, 1, 1, 2]];
$bmpRle8 = $tmp . '/rle8.bmp';
file_put_contents($bmpRle8, buildBmp($bw, $bh, 8, "\x04\x01\x01\x02\x00\x00"
    . "\x05\x03\x00\x00"
    . "\x00\x05\x04\x00\x00\x00\x00\x00\x00\x00"
    . "\x00\x01", ['compression' => 1, 'palette' => $paletteBytes]));
check('BI_RLE8 runs, absolute runs and row changes decode', BmpReader::read($bmpRle8)['rgb'] === $paletteRgb($rowsRle8));

// One pixel at the left of the bottom row, then a jump up and right of it.
$rowsDelta = [[0, 0, 0, 0, 0], [0, 0, 2, 2, 0], [1, 0, 0, 0, 0]];
$bmpDelta = $tmp . '/rle8-delta.bmp';
file_put_contents($bmpDelta, buildBmp($bw, $bh, 8, "\x01\x01"
    . "\x00\x02\x01\x01"
    . "\x02\x02\x00\x00"
    . "\x00\x01", ['compression' => 1, 'palette' => $paletteBytes]));
check('an RLE8 delta jump lands where it says', BmpReader::read($bmpDelta)['rgb'] === $paletteRgb($rowsDelta));

$rowsRle4 = [[0, 10, 0, 10, 0], [1, 2, 3, 4, 5], [0, 0, 0, 0, 0]];
$bmpRle4 = $tmp . '/rle4.bmp';
file_put_contents($bmpRle4, buildBmp($bw, 2, 4, "\x00\x05\x12\x34\x50\x00\x00\x00"
    . "\x05\x0a\x00\x00\x00\x01", ['compression' => 2, 'palette' => $paletteBytes]));
check('BI_RLE4 alternates its nibbles', BmpReader::read($bmpRle4)['rgb'] === $paletteRgb(array_slice($rowsRle4, 0, 2)));

// A V5 header profile.  The payload has to look like a real profile: the
// reader checks the signature before believing the header's offset.
$profile = pack('N', 200) . str_repeat("\x00", 32) . 'acsp' . str_repeat("\x1f", 160);
$bmpIcc = $tmp . '/icc.bmp';
file_put_contents($bmpIcc, buildBmp($bw, $bh, 24, $bottomUp, ['dibSize' => 124, 'cstype' => 'MBED', 'profile' => $profile]));
check("a V5 header's embedded profile is read from the header", ImageMeta::read($bmpIcc)->icc === $profile);
check('and reaches the encoder path', ImageInput::pixels($bmpIcc)['icc'] === $profile);

$bmpIccDib = $tmp . '/icc-from-dib.bmp';
file_put_contents($bmpIccDib, buildBmp($bw, $bh, 24, $bottomUp, [
    'dibSize' => 124,
    'cstype' => 'MBED',
    'profile' => $profile,
    'profileFromDib' => true,
]));
check('both bases for the profile offset are accepted', ImageInput::pixels($bmpIccDib)['icc'] === $profile);

// A V4/V5 header written once for every depth carries an alpha mask on 24-bit
// files too, where there is no fourth byte for it to describe.
$bmp24V5 = $tmp . '/24bit-v5-mask.bmp';
file_put_contents($bmp24V5, buildBmp($bw, $bh, 24, $bottomUp, [
    'dibSize' => 124,
    'compression' => 3,
    'masks' => $alphaMasks,
]));
$maskedMeta = ImageMeta::read($bmp24V5);
check(
    'an alpha mask on a 24-bit header is not a promise of transparency',
    !$maskedMeta->mayHaveAlpha() && $maskedMeta->describe() === 'bmp 24bpp bitfields',
    $maskedMeta->describe()
);
check('and the pixels still decode', BmpReader::read($bmp24V5)['rgb'] === $bmpRgb && BmpReader::read($bmp24V5)['alpha'] === null);

$bmp24Unused = $tmp . '/24bit-unused-masks.bmp';
file_put_contents($bmp24Unused, buildBmp(1, 1, 24, "\x30\x20\x10\x00", [
    'dibSize' => 108, 'compression' => 3, 'masks' => [0xff, 0xff00, 0xff0000, 0],
]));
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
try {
    $unused24 = BmpReader::read($bmp24Unused);
} finally {
    restore_error_handler();
}
check('24-bit pixels remain BGR even with unused channel masks', $unused24['rgb'] === "\x10\x20\x30" && $warnings === []);

// The V4 header stops at byte 122. Bytes at the V5 profile-field offsets are
// pixels here, even if they happen to point to something resembling an ICC.
$bmpV4Profile = $tmp . '/v4-false-profile.bmp';
file_put_contents($bmpV4Profile, buildBmp(4, 1, 24,
    str_repeat("\x00", 4) . pack('VV', 134, strlen($profile)),
    ['dibSize' => 108, 'cstype' => 'DEBM']
) . $profile);
check(
    'a V4 header cannot claim a V5 embedded profile',
    ImageMeta::read($bmpV4Profile)->icc === null && BmpReader::read($bmpV4Profile)['icc'] === null
);

// The staged queue path reads through the same chooser, so a BMP job has to
// come out with the single-shot bytes and its alpha intact.  Adaptation is off
// for both, exactly as the other staged comparisons do it: the question here
// is whether the staged pixels match, not how the probabilities are derived.
$reference32 = Vp8LossyEncoder::fromQuality(80);
$reference32->adaptProbs = false;
$reference32->alphaPlane = $bmpAlpha;
$reference32Webp = $reference32->encode($bmpRgb, $bw, $bh)['webp'];
PlaneStore::prepare($bmp32, $tmp . '/bmp-work');
$bmpStore = PlaneStore::open($tmp . '/bmp-work');
$bmpMbRows = (int) $bmpStore->meta()['mbh'];
$staged32 = Vp8LossyEncoder::fromQuality(80);
$staged32->adaptProbs = false;
$staged32->alphaPlane = $bmpStore->alphaPlane();
$staged32->initFrame($bw, $bh);
$staged32->beginBand(0, $bmpMbRows, ...$bmpStore->readBand(0, $bmpMbRows));
$staged32->encodeRowRange(0, $bmpMbRows);
$staged32Webp = $staged32->finishFrame()['webp'];
check(
    'the staged queue path reads a translucent BMP byte for byte',
    $staged32Webp === $reference32Webp,
    md5($staged32Webp) . ' vs ' . md5($reference32Webp)
);

// The CLI is what a queue calls, so the BMP path is checked from there too.
@unlink($cliOut);
[$status, $out] = $run($bmp24, $cliOut, '--json', '--no-gd');
$report = json_decode($out, true);
check(
    'CLI encodes a BMP with GD switched off',
    $status === 0 && is_file($cliOut) && ($report['source'] ?? '') === 'bmp 24bpp rgb',
    "exit $status, source " . ($report['source'] ?? '-')
);
check('and writes exactly the bytes the encoder produced', is_file($cliOut) && file_get_contents($cliOut) === $bmpWebp);
@unlink($cliOut);

file_put_contents($cliOut, 'existing output');
[$status, $text] = $stderrRun($bmp32, $cliOut, '--alpha=fail', '--no-gd');
check(
    'a translucent BMP obeys --alpha=fail without overwriting the output',
    $status === 3 && file_get_contents($cliOut) === 'existing output',
    "exit $status"
);
[$status, $out] = $run($bmp32, $cliOut, '--json', '--no-gd');
$report = json_decode($out, true);
check(
    'and encodes with its alpha plane when left alone',
    $status === 0 && ($report['alpha_plane'] ?? '') === 'coded ' . $bw * $bh . ' B' && webpChunks((string) file_get_contents($cliOut)) === ['VP8X', 'ALPH', 'VP8 '],
    implode(' ', webpChunks((string) file_get_contents($cliOut)))
);
@unlink($cliOut);

[$status, $out] = $run($bmpZero, $cliOut, '--json', '--no-gd');
$zeroReport = json_decode($out, true);
check(
    'CLI keeps a fully transparent BMP alpha plane',
    $status === 0 && ($zeroReport['alpha_plane'] ?? '') === 'coded ' . $bw * $bh . ' B'
        && in_array('ALPH', webpChunks((string) file_get_contents($cliOut)), true)
);
@unlink($cliOut);

// Malformed input: a truncated payload, a bitmap that wraps a JPEG, a file
// that merely begins with "BM", and one too large to encode.
$truncatedBmp = $tmp . '/truncated.bmp';
file_put_contents($truncatedBmp, buildBmp($bw, $bh, 24, substr($bottomUp, 0, 6)));
[$status, $text] = $stderrRun($truncatedBmp, $cliOut, '--no-gd');
check(
    'a truncated BMP exits 1 and says why',
    $status === 1 && str_contains($text, 'truncated BMP pixel data') && !str_contains($text, 'Fatal error'),
    trim($text)
);

// An incomplete RLE command must fail before the CLI replaces an old output.
$truncatedRle = $tmp . '/truncated-rle.bmp';
foreach ([
    'RLE8 count' => [8, 1, "\x03"],
    'RLE8 delta' => [8, 1, "\x00\x02\x01"],
    'RLE8 literals' => [8, 1, "\x00\x03\x01"],
    'RLE8 padding' => [8, 1, "\x00\x03\x01\x02\x03"],
    'RLE4 literals' => [4, 2, "\x00\x05\x12\x34"],
    'RLE4 padding' => [4, 2, "\x00\x05\x12\x34\x50"],
    'missing end marker' => [8, 1, "\x05\x01"],
] as $name => [$bits, $compression, $payload]) {
    file_put_contents($truncatedRle, buildBmp($bw, 1, $bits, $payload, [
        'compression' => $compression, 'palette' => $paletteBytes,
    ]));
    file_put_contents($cliOut, 'existing output');
    [$status, $text] = $stderrRun($truncatedRle, $cliOut, '--no-gd');
    check(
        "truncated $name is rejected without replacing output",
        $status === 1 && str_contains($text, 'truncated BMP RLE data')
            && !str_contains($text, 'Fatal error') && file_get_contents($cliOut) === 'existing output'
    );
}
@unlink($cliOut);

$embeddedJpeg = $tmp . '/embedded-jpeg.bmp';
file_put_contents($embeddedJpeg, buildBmp($bw, $bh, 24, $bottomUp, ['compression' => 4]));
[$status, $text] = $stderrRun($embeddedJpeg, $cliOut, '--no-gd');
check('a BMP holding an embedded JPEG is refused by name', $status === 1 && str_contains($text, 'embedded JPEG'), trim($text));

$bmText = $tmp . '/starts-with-bm.bmp';
file_put_contents($bmText, 'BM' . str_repeat('not a bitmap at all ', 8));
[$status, $text] = $stderrRun($bmText, $cliOut, '--no-gd');
check('a file that merely starts with BM is refused cleanly', $status === 1 && !str_contains($text, 'Fatal error'), trim($text));

$hugeBmp = $tmp . '/oversized.bmp';
file_put_contents($hugeBmp, buildBmp(20000, 1, 24, str_repeat("\x00", 8)));
$oversized = null;
try {
    ImageMeta::read($hugeBmp);
} catch (\RuntimeException $e) {
    $oversized = $e->getMessage();
}
check('an oversized BMP is refused before any pixels are read', $oversized !== null && str_contains($oversized, 'dimensions out of range'), (string) $oversized);

// Independent decoders on the same files: libgd where it reads BMP at all, and
// ImageMagick for the layouts libgd will not touch.
if ($haveGd) {
    $gdImage = @imagecreatefrombmp($bmp24);
    if ($gdImage === false) {
        skip('BMP compared with libgd', 'this libgd build will not read the file');
    } else {
        $gdRgb = '';
        for ($y = 0; $y < imagesy($gdImage); $y++) {
            for ($x = 0; $x < imagesx($gdImage); $x++) {
                $c = imagecolorat($gdImage, $x, $y);
                $gdRgb .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
            }
        }
        imagedestroy($gdImage);
        check('bundled BMP pixels match libgd', $gdRgb === BmpReader::read($bmp24)['rgb']);
    }
} else {
    skip('BMP compared with libgd', 'ext-gd not loaded');
}

if (trim((string) shell_exec('command -v magick 2>/dev/null')) !== '') {
    $ppm = $tmp . '/bmp-src.ppm';
    file_put_contents($ppm, "P6\n$w $h\n255\n" . $rgb);
    $pam = $tmp . '/bmp-src.pam';
    $rgbaRows = '';
    for ($i = 0; $i < $w * $h; $i++) {
        $rgbaRows .= substr($rgb, $i * 3, 3) . $alpha[$i];
    }
    file_put_contents($pam, "P7\nWIDTH $w\nHEIGHT $h\nDEPTH 4\nMAXVAL 255\nTUPLTYPE RGB_ALPHA\nENDHDR\n" . $rgbaRows);

    $cases = [
        ['24-bit', '-type TrueColor', $ppm, false, 0],
        ['24-bit 40-byte header', '-define bmp:format=bmp3', $ppm, false, 0],
        ['8-bit palette', '-colors 256 -type Palette -compress None', $ppm, false, 0],
        ['4-bit palette', '-colors 16 -type Palette -compress None', $ppm, false, 0],
        ['1-bit palette', '-colors 2 -type Palette -compress None', $ppm, false, 0],
        ['8-bit RLE', '-colors 256 -type Palette -compress RLE', $ppm, false, 0],
        ['16-bit 5-5-5', '-define bmp:subtype=RGB555', $ppm, false, 1],
        ['16-bit 5-6-5', '-define bmp:subtype=RGB565', $ppm, false, 1],
        ['32-bit alpha', '-define bmp:format=bmp4', $pam, true, 0],
    ];
    foreach ($cases as [$name, $flags, $source, $withAlpha, $slack]) {
        $file = $tmp . '/magick-' . str_replace(' ', '-', $name) . '.bmp';
        exec('magick ' . escapeshellarg($source) . ' ' . $flags . ' ' . escapeshellarg($file) . ' 2>&1', $lines, $status);
        if ($status !== 0 || !is_file($file)) {
            skip("ImageMagick $name BMP", 'magick could not write it');
            continue;
        }
        try {
            $decoded = BmpReader::read($file);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            check("ImageMagick $name BMP decodes", false, $e->getMessage());
            continue;
        }
        $reference = $tmp . '/magick-ref.raw';
        @unlink($reference);
        exec('magick ' . escapeshellarg($file) . ' -depth 8 rgb:' . escapeshellarg($reference) . ' 2>&1', $lines, $status);
        $refBytes = (string) @file_get_contents($reference);
        $worst = $status !== 0 || $refBytes === '' || strlen($refBytes) !== strlen($decoded['rgb']) ? 999 : 0;
        for ($i = 0, $n = min(strlen($refBytes), strlen($decoded['rgb'])); $i < $n; $i++) {
            $worst = max($worst, abs(ord($refBytes[$i]) - ord($decoded['rgb'][$i])));
        }
        check(
            "ImageMagick $name BMP matches ImageMagick's own decode",
            $worst <= $slack,
            $worst === 0 ? 'identical' : "max delta $worst"
        );
        if ($withAlpha) {
            $refAlphaFile = $tmp . '/magick-ref-alpha.raw';
            @unlink($refAlphaFile);
            exec('magick ' . escapeshellarg($file) . ' -depth 8 rgba:' . escapeshellarg($refAlphaFile) . ' 2>&1', $lines, $status);
            $refAlphaBytes = (string) @file_get_contents($refAlphaFile);
            $refAlpha = '';
            for ($i = 3; $i < strlen($refAlphaBytes); $i += 4) {
                $refAlpha .= $refAlphaBytes[$i];
            }
            check('ImageMagick 32-bit alpha BMP carries the alpha plane', $refAlpha !== '' && $refAlpha === $decoded['alpha']);
        }
    }
} else {
    skip('BMP files written by ImageMagick', 'magick not on PATH');
}

rmTree($tmp);

printf("\n%s\n", $failures === 0
    ? sprintf('all checks passed%s', $skipped > 0 ? " ($skipped skipped)" : '')
    : "$failures check(s) FAILED");
exit($failures === 0 ? 0 : 1);
