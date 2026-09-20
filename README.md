# PicoWebP

PicoWebP converts PNGs, baseline JPEGs, BMPs and raw RGB pixels to WebP using PHP. The encoder writes the VP8 bitstream itself, and the PNG, JPEG and BMP readers ship with the package. No GD, Imagick, `cwebp` or libwebp is required for those inputs.

This is for the host where you need WebP conversion but cannot install an image extension or a command-line encoder. It keeps transparency and ICC colour profiles, works from PHP or the command line, and includes a way to split encoding into smaller jobs.

The trade-off is CPU time. With the default settings, encoding a roughly 1 MP image took 25 seconds on the benchmark machine; a 12 MP image took several minutes. Use a native encoder when one is available, and run PicoWebP in a background queue. The [timings below](#speed-and-memory) show what that means in practice.

[Install](#install) · [Quick start](#quick-start) · [Command line](#command-line) · [Supported inputs](#supported-inputs) · [Benchmarks](#file-sizes) · [Resumable encoding](#resumable-encoding)

[Project website](https://nikocodes.com/software/picowebp/) · [Packagist](https://packagist.org/packages/nikocodes/picowebp)

## Install

You need **PHP 8.1 or newer** and **`ext-zlib`**.

```sh
composer require nikocodes/picowebp
```

The package is available on [Packagist](https://packagist.org/packages/nikocodes/picowebp).

GD is optional. PicoWebP can use it to read images faster when it is installed, and it is required for progressive JPEG input or re-encoding an existing WebP. WebP encoding itself always runs in PHP.

## Quick start

Put this script and `photo.jpg` in your application's root directory:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\Vp8LossyEncoder;

$pixels = ImageInput::pixels('photo.jpg');

$encoder = Vp8LossyEncoder::fromQuality(80);
$encoder->alphaPlane = $pixels['alpha']; // null for an opaque image
$encoder->iccProfile = $pixels['icc'];   // null when no profile is present

$result = $encoder->encode($pixels['rgb'], $pixels['w'], $pixels['h']);

if (file_put_contents('photo.webp', $result['webp']) !== strlen($result['webp'])) {
    throw new RuntimeException('Could not write photo.webp');
}
```

Quality runs from `0` to `100`. The same code works with a PNG, including one with transparency. Colour is encoded lossily; the alpha plane is encoded losslessly.

To prefer the bundled readers even when GD is installed, set `ImageInput::$preferBundled = true` before reading the file. To disable GD entirely, set `ImageInput::$gdAvailable = false`.

### Starting with raw pixels

The encoder accepts a packed RGB string: three bytes per pixel, red then green then blue, ordered from the top-left pixel across each row. For example, this writes a small red square:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PicoWebP\Vp8\Vp8LossyEncoder;

$width = 16;
$height = 16;
$rgb = str_repeat("\xff\x00\x00", $width * $height);

$encoder = Vp8LossyEncoder::fromQuality(80);
$webp = $encoder->encode($rgb, $width, $height)['webp'];

if (file_put_contents('red.webp', $webp) !== strlen($webp)) {
    throw new RuntimeException('Could not write red.webp');
}
```

For transparency, assign a packed alpha string to `$encoder->alphaPlane`: one byte per pixel, from `0` (transparent) to `255` (opaque). An RGB buffer must contain exactly `width × height × 3` bytes; an alpha buffer must contain `width × height` bytes. Each dimension must be between 1 and 16,383 pixels. Those format limits are much higher than sensible limits for a PHP queue: set your own megapixel cap.

See [`examples/encode.php`](examples/encode.php) for a runnable example.

## Command line

Composer installs the command in `vendor/bin`:

```sh
vendor/bin/picowebp photo.jpg photo.webp --quality=80
vendor/bin/picowebp image.png image.webp --quality=80 --no-gd
vendor/bin/picowebp screenshot.bmp screenshot.webp --quality=80
vendor/bin/picowebp pixels.raw out.webp --quality=80 --rgb=1200x800
vendor/bin/picowebp --help
```

From a checkout of this repository, use `bin/picowebp` instead.

| Option | What it does |
|---|---|
| `--quality=0..100` | Set the quality; defaults to 80 |
| `--rgb=WxH` | Read headerless, 8-bit RGB pixels at the supplied dimensions |
| `--alpha-plane=FILE` | Read one byte of alpha per pixel for raw RGB input |
| `--alpha=keep\|flatten\|fail` | Keep transparency, discard it, or reject input with alpha; defaults to `keep` |
| `--alpha-filter=0..3` | Choose an alpha prediction filter; otherwise the encoder chooses |
| `--icc=FILE` / `--no-icc` | Supply a colour profile or leave it out |
| `--bundled` / `--no-gd` | Prefer the bundled readers or disable GD entirely |
| `--reencode-webp` | Read and re-encode an existing WebP; requires GD |
| `--json` | Print a machine-readable report, including read and encode times |
| `--dump-yuv=FILE` | Also write the padded Y, U and V planes for inspection |

Despite its name, `--alpha=flatten` simply drops the alpha plane. It does not composite the image onto a chosen background.

The CLI exits with `0` after an encode, `1` for an error, `3` when `--alpha=fail` rejects input, and `4` when it skips an existing WebP. Check the exit code before marking a queue job as converted.

## Supported inputs

| Input | Support |
|---|---|
| Baseline JPEG | Sequential, 8-bit greyscale or YCbCr; all sampling factors, restart markers and `APP2` ICC profiles |
| Progressive JPEG | Requires GD |
| PNG | Every colour type and bit depth, including interlacing, palettes, `tRNS` transparency and `iCCP` profiles |
| BMP | 1, 4, 8, 16, 24 and 32 bits per pixel; uncompressed pixels, `RLE4` and `RLE8`; channel masks and embedded V5 ICC profiles |
| Raw RGB | Packed 8-bit RGB, with an optional alpha plane |
| WebP | Skipped by default; re-encoding requires GD |
| GIF, TIFF / TIF, raw Y′CbCr | Not supported |

On a host without GD, progressive JPEGs need to be converted to baseline JPEG or decoded to raw RGB elsewhere first. The bundled reader reports unsupported JPEG variants rather than attempting to decode them as baseline.

BMP files always use the bundled reader, so decoding behaves the same on hosts with and without GD. It reads OS/2 core headers and Windows `BITMAPINFOHEADER` and V2–V5 headers, including palettes, channel masks and bottom-up or top-down rows. Other header formats and BMPs containing JPEG or PNG payloads are not supported.

For 16-bit BMPs, PicoWebP scales the 5- or 6-bit colour channels to eight bits using rounding. Another decoder may produce a value one step higher or lower for some colours.

### Transparency and metadata

The bundled PNG reader retains all eight bits of alpha, including palette transparency and `tRNS`. The encoder stores that alpha losslessly in an `ALPH` chunk alongside the lossy colour data. Fully opaque images do not need an alpha chunk. GD has lower alpha precision, so use the bundled reader when preserving the source alpha exactly matters.

BMP transparency comes from an alpha mask in the header or the mask block that follows it. A declared alpha channel is preserved, including an image where every pixel is fully transparent. A 32-bit `BI_RGB` file uses the opaque `BGRX` layout, so its fourth byte and any unused channel masks are ignored.

ICC profiles from PNG `iCCP` and JPEG `APP2` chunks, and embedded profiles from BMP V5 headers, are copied into the output. This preserves the profile; it does not make the colour encoding lossless.

EXIF metadata is not copied, and orientation tags are not applied. Rotate or mirror photos as needed before passing them to PicoWebP.

### Existing WebP files

PicoWebP skips WebP input by default. It checks the file contents, so a WebP named `photo.jpg` is still skipped, while a PNG named `photo.webp` can still be converted.

In PHP, catch `SkippedInput` separately from conversion failures:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\SkippedInput;

foreach (['photo.jpg', 'already.webp'] as $file) {
    try {
        $pixels = ImageInput::pixels($file);
    } catch (SkippedInput $e) {
        // $e->slug() is 'skipped_webp'; $e->reason() explains the skip.
        // $e->meta contains metadata read from the WebP header.
        continue;
    }

    // Encode or enqueue $pixels here.
}
```

`SkippedInput` extends `RuntimeException`. You can check a file in advance with `ImageInput::isWebp($file)`.

To allow re-encoding, set `ImageInput::$skipWebp = false` or pass `--reencode-webp`. GD must be available to read it. A normal CLI skip leaves the destination untouched and returns exit code `4`.

## File sizes

These are the project's recorded results for ten real images at quality 80 with the encoder defaults. The libwebp column uses its default effort. **The same quality number does not mean the same visual quality across encoders**, so smaller output alone does not establish better compression.

| Image | Source | Input | PicoWebP | libwebp |
|---|---|---:|---:|---:|
| Logo | JPEG 560×560 | 50 KB | 18 KB | 17 KB |
| App UI screenshot | PNG 2174×900 | 193 KB | 55 KB | 49 KB |
| Photo | JPEG 960×1706 | 229 KB | 71 KB | 74 KB |
| Photo | JPEG 1280×1280 | 275 KB | 71 KB | 76 KB |
| Photo | JPEG 1280×1280 | 327 KB | 96 KB | 99 KB |
| Graphic | PNG 1024×1024 | 356 KB | 51 KB | 42 KB |
| Logo | PNG 1254×1254 | 442 KB | 54 KB | 54 KB |
| Phone screenshot | PNG 1170×2532 | 1712 KB | 26 KB | 31 KB |
| Video-call screenshot | PNG 1170×2532 | 4069 KB | 60 KB | 67 KB |
| Phone screenshot | PNG 1170×2532 | 5275 KB | 122 KB | 127 KB |

PicoWebP produced smaller files on some photos and phone captures, and larger files on some artwork and UI captures. The recorded real-photo results also had lower PSNR, by 0.2–1.6 dB. These results describe this small sample, not every image you might convert.

Check appearance and file size before replacing an original. For transparent images, raw RGB PSNR can also be misleading because fully transparent pixels may contain different, invisible colour values.

### Generated fixtures

These images were generated from gradients, noise and graphics during development. They are useful for comparing encoder changes, but results on them won't necessarily carry over to photographs. Like the real-image results above, these were recorded at quality 80 with the default settings.

| Source | Input | PicoWebP | libwebp |
|---|---:|---:|---:|
| `photo.jpg` 1200×800 | 296 KB | 187 KB | 190 KB |
| `photo.png` 1200×800 | 1743 KB | 186 KB | 197 KB |
| `photo-2mp.jpg` 1600×1200 | 587 KB | 370 KB | 376 KB |
| `big-photo.jpg` 2000×1500 | 930 KB | 585 KB | 597 KB |
| `big-photo.png` 2000×1500 | 5457 KB | 584 KB | 607 KB |
| `huge-photo.jpg` 4000×3000 | 3830 KB | 1532 KB | 1569 KB |
| `huge-photo.png` 4000×3000 | 7133 KB | 1616 KB | 1685 KB |
| `small-photo.jpg` 320×240 | 24 KB | 15 KB | 15 KB |
| `small-photo.png` 320×240 | 137 KB | 15 KB | 16 KB |

The recorded output sizes were 1.7–5.8% below libwebp up to about 3 MP and 2.4–4.1% below it at 12 MP, with PSNR 0.05–0.25 dB lower. As with the real images, the smaller files came with a quality difference.

At maximum effort (`cwebp -m6`), libwebp reduced its output size by 1.4–5.8%. On the 12 MP fixtures, its JPEG result was 1.1% smaller than PicoWebP's and its PNG result was 1.4% larger; on the smaller fixtures, its files remained 0.5–7% larger. The recorded 12 MP encode took 2.2 seconds with `cwebp -m6`, compared with about 377 seconds in PHP.

## Speed and memory

The measurements below were recorded on one macOS laptop using PHP 8.4, quality 80, the default encoder settings and one process. They are useful for planning a queue, but they are not promises about another machine or host.

**Encoding only**, after the source has been decoded to RGB:

| Image | Encode time | Seconds per MP | Recorded peak memory |
|---|---:|---:|---:|
| 0.96 MP synthetic JPEG | 25.0 s | 26.0 | 14 MB |
| 1.64 MP real JPEG | 37.4 s | 22.8 | 25 MB |
| 3 MP synthetic JPEG | 78.8 s | 26.3 | 24 MB |
| 12 MP synthetic JPEG | 377.5 s | 31.5 | 84 MB |

**Reading only**, before encoding starts. The table records results for a 4000×3000 photo re-saved in each format, with a range for PNG filter layouts. The phone screenshot discussed below is a separate sample:

| Reader | Time per MP | Time for 12 MP | Peak memory |
|---|---:|---:|---:|
| Bundled JPEG reader | 1.7 s | 20.9 s | 111 MB |
| Bundled PNG reader | 0.01–0.8 s | 0.1–9.5 s | 86 MB |
| Bundled BMP reader, 24-bit | 0.06 s | 0.7 s | 73 MB |
| Bundled BMP reader, 16-bit masked | 0.45 s | 5.4 s | 61 MB |
| Bundled BMP reader, 8-bit `RLE` | 0.17 s | 2.0 s | 54 MB |
| GD, reading the same JPEG | 0.12 s | 1.4 s | 84 MB |

The encoder receives decoded pixels, so inputs with the same RGB, alpha and ICC data produce the same output at the same settings. The same 800×600 photo saved as BMP, PNG and raw RGB produced byte-identical WebP output — 42,814 bytes from all three — where libwebp wrote 42,952.

The three BMP rows use the same source image in different storage layouts. Reducing it to 16-bit colour or an 8-bit palette can change the decoded pixels. The 24-bit reader mainly reorders BGR bytes into RGB. The 16-bit path took about seven times as long per pixel in this sample, since each channel has to be unpacked and scaled to a byte; `RLE` took about two and a half times as long. An uncompressed 24-bit BMP also needs roughly three bytes per pixel: a 12 MP file is about 34 MiB, and the reader holds that alongside the decoded RGB while it works.

PNG read time depends heavily on the filters chosen by the writer, as well as the compressed data. In a separate screenshot sample, rows using `None` read at 19 ms/MP (about 0.02 s/MP); the photo PNG using Paeth read at 0.8 s/MP, about forty times slower. The screenshot result is not the lower bound in the table. Time a file from your own source before planning a queue around PNG input.

In the bundled JPEG reader, the recorded CPU breakdown was 53% inverse DCT, 27% upsampling and YCbCr-to-RGB conversion, 6% block storage and 13% Huffman decoding. For comparison, libjpeg-turbo decoded the 12 MP image in 0.11 s; the GD path also spends time copying pixels into the RGB buffer.

Adding the listed 12 MP JPEG read and encode times gives about 398 seconds with the bundled reader. This is a sum of the separate measurements. GD speeds up the reading stage, but the PHP encoding work remains. Native libwebp is substantially faster.

Keep conversions out of page requests. Limit input dimensions, allow for the decoded image's memory use, and pace queue jobs to fit your host's CPU allowance.

### Adjusting the work per image

The defaults enable 4×4 prediction, coefficient-probability adaptation, rate-distortion mode selection and macroblock skip flags. Trellis quantisation is off by default.

The CLI exposes `--no-bpred`, `--no-adapt-probs`, `--no-rd`, `--prune=N`, `--rd-lambda=N`, `--trellis` and `--no-skip`. The corresponding encoder properties include `enableBPred`, `adaptProbs`, `enableRd`, `rdPruneCandidates`, `rdLambda`, `enableTrellis` and `enableSkipFlags`.

The following measurements use the same two images for each setting. File-size changes are relative to the defaults, with synthetic-image results first and real-image results second. Changing the search also changes quality, so compare the resulting images as well as the numbers.

| Configuration | 0.96 MP synthetic | 1.64 MP real | File size vs defaults |
|---|---:|---:|---|
| Defaults (B_PRED + adaptation + RD) | 26.0 s/MP | 22.8 s/MP | Reference |
| `--no-bpred` | 11.4 s/MP | 9.9 s/MP | −1% synthetic, −17% real |
| `--no-adapt-probs` | 13.4 s/MP | 11.6 s/MP | +23% / +2% |
| `--no-rd` | 7.3 s/MP | 6.5 s/MP | +9% / +33% |
| `--no-bpred --no-adapt-probs --no-rd` | 2.6 s/MP | 2.0 s/MP | +40% / +47% |
| `--trellis` (off by default) | 61.1 s/MP | 30.7 s/MP | −4% / −14%, at PSNR −0.7 / −2.8 dB |
| libwebp, `cwebp -q 80` | ~0.1 s/MP | ~0.1 s/MP | See [File sizes](#file-sizes) |

Reducing the search can save CPU, but also changes output size and quality. Test with your own images before changing the defaults. Enabling trellis adds work and can change detail as well as file size; it is not a free quality improvement.

## Resumable encoding

The resumable path stages image planes on disk with `PlaneStore`, then processes bands of macroblock rows. `beginBand()`, `encodeRowRange()` and `compactReconWindow()` handle the bands; `finishFrame()` completes the file. `exportState()` and `importState()` let an application carry the encoder state between invocations.

See [`examples/chunked-queue.php`](examples/chunked-queue.php) for the sequence. It runs the stages in one process and compares the result with a single-shot encode. A real queue needs to persist the state and schedule the next band itself.

This makes the work resumable, not faster. The demonstrated path includes staging, a statistics pass and an encoding pass, and uses about twice the CPU of a single-shot encode. The initial staging step still needs the decoded image in memory, so it does not remove the need for an input-size limit.

For the recorded 3 MP image, the resumable loop held 23 MB compared with a 24 MB peak for the single-shot encoder. About 79 seconds of encoding became 158 seconds of work across the two passes. The benefit is being able to pause between bands and continue later.

## Tests

From a checkout of this repository:

```sh
composer test
# or: php tests/run.php
```

Tests cover the bitstream, alpha and ICC chunks, image readers, CLI results, malformed input and resumable encoding. When `dwebp` is available on `PATH`, additional checks decode the output with libwebp. These external tools are for validation; they are not encoding dependencies.

## Scope and license

PicoWebP writes lossy VP8 WebP images. It does not provide a general-purpose lossless WebP encoder. `PicoWebP\Spike\Vp8lEncoder` is an internal helper for alpha channels.

Release changes are in [CHANGELOG.md](CHANGELOG.md). The package is released under the [MIT license](LICENSE).
