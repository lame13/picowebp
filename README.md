# picowebp

picowebp makes WebP files in PHP. Give it a PNG, a baseline JPEG or raw RGB
pixels, and it writes a lossy WebP with transparency and ICC colour profiles
intact.

It's meant for hosts where GD, Imagick and `cwebp` aren't available. The PNG
and JPEG readers are included, and the encoder writes the VP8 bitstream
itself. You can use it from PHP or the command line, and there's a resumable
path for jobs that need to run in small chunks.

The catch is speed. With the default settings, a 1 MP image takes about
25 seconds on the machine used for the benchmarks below. A 12 MP photo takes
several minutes. Plan to run this in a background queue; the [benchmarks](#cost)
give you a better idea of what to expect.

[Install](#install) · [Quick start](#quick-start) · [Command line](#command-line) ·
[Input formats](#input-formats) · [Sizes](#sizes) · [Limitations](#limitations)

## Requirements

You'll need PHP 8.1 or newer and the zlib extension (`ext-zlib`).

If GD is installed, picowebp uses it to read images faster. It's also needed
for progressive JPEGs and for re-encoding an existing WebP. Everything else
works without it, and WebP encoding always runs in PHP.

## Install

For now, install from GitHub. Run these in your application's directory:

```sh
composer config repositories.picowebp vcs https://github.com/lame13/picowebp.git
composer require nikocodes/picowebp:^1.0
```

While the repository is private, you'll need to give Composer access to your
GitHub account. [Composer's documentation](https://getcomposer.org/doc/05-repositories.md#using-private-repositories)
covers private repositories.

Once it's published on Packagist, this is all a new project will need:

```sh
composer require nikocodes/picowebp
```

## Quick start

Put `photo.jpg` and this script in your application's root directory, then
run the script with PHP:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\Vp8LossyEncoder;

$pixels = ImageInput::pixels('photo.jpg');

$encoder = Vp8LossyEncoder::fromQuality(80);
$encoder->alphaPlane = $pixels['alpha']; // null for an opaque source
$encoder->iccProfile = $pixels['icc'];   // null for an untagged source

$result = $encoder->encode($pixels['rgb'], $pixels['w'], $pixels['h']);
if (file_put_contents('photo.webp', $result['webp']) !== strlen($result['webp'])) {
    throw new RuntimeException('Could not write photo.webp');
}
```

Quality runs from `0` to `100`. Swap `photo.jpg` for a PNG and the same code
will keep its transparency. If the input is already a WebP, you'll get a
[`SkippedInput`](#handling-existing-webp-files) exception.

If you already have the pixels, pass them to the encoder as an RGB string:
three bytes per pixel, red then green then blue, from top left to bottom
right. Here's a small red square:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PicoWebP\Vp8\Vp8LossyEncoder;

$width = 16;
$height = 16;
$rgb = str_repeat("\xff\x00\x00", $width * $height); // solid red

$encoder = Vp8LossyEncoder::fromQuality(80);
$webp = $encoder->encode($rgb, $width, $height)['webp'];
if (file_put_contents('red.webp', $webp) !== strlen($webp)) {
    throw new RuntimeException('Could not write red.webp');
}
```

There's a runnable version for both kinds of input in
[`examples/encode.php`](examples/encode.php). For a job you can pause and
resume, see [`examples/chunked-queue.php`](examples/chunked-queue.php).

You can choose the bundled readers even when GD is installed:
set `ImageInput::$preferBundled = true`. To turn off GD completely, use
`ImageInput::$gdAvailable = false`.

## Command line

Composer installs the command in `vendor/bin`:

```sh
vendor/bin/picowebp photo.jpg photo.webp --quality=80
vendor/bin/picowebp image.png image.webp --quality=80 --no-gd
vendor/bin/picowebp pixels.raw out.webp --quality=80 --rgb=1200x800
vendor/bin/picowebp --help
```

If you've cloned this repository, the command is `bin/picowebp`.

For raw input, `--rgb=WxH` tells the reader how wide and tall the image is.
You can add transparency with `--alpha-plane=FILE`, using one byte per pixel.
A few other useful options:

| Option | Behaviour |
|---|---|
| `--alpha=keep\|flatten\|fail` | Preserve transparency (default), drop it, or refuse transparent input |
| `--icc=FILE` / `--no-icc` | Supply a colour profile or omit it |
| `--bundled` / `--no-gd` | Prefer the bundled readers or disable GD |
| `--reencode-webp` | Convert an existing WebP instead of skipping it; requires GD |
| `--json` | Print a machine-readable report |

Run `--help` for the rest, including the settings that trade quality for speed.

Exit codes are `0` for a successful encode, `1` for an error, `3` when
`--alpha=fail` rejects transparency, and `4` when an existing WebP is skipped.

## Input formats

| Input | Support |
|---|---|
| JPEG (`.jpg`, `.jpeg`) | Baseline sequential, 8-bit, greyscale or YCbCr; all sampling factors, restart markers and `APP2` ICC |
| Progressive JPEG | Needs GD |
| PNG | Every colour type and bit depth, including interlaced images, palettes, `tRNS` and `iCCP` |
| Raw RGB | Use `--rgb=WxH`; an optional alpha plane adds transparency |
| WebP | Skipped by default; `--reencode-webp` needs GD |
| BMP, GIF, TIFF / TIF | Not supported |
| Raw Y′CbCr | Not supported |

If you hit a progressive JPEG on a host without GD, re-save it as baseline
JPEG or pass in raw RGB pixels. The bundled reader will tell you when that's
the problem.

### Handling existing WebP files

An existing WebP usually doesn't need converting again. Re-encoding takes
time and can lose more detail, so picowebp skips it by default.

The reader checks the file's contents. A WebP named `photo.jpg` still gets
skipped; a PNG named `photo.webp` still gets converted. In a queue, you can
catch the skip and move on to the next file:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\SkippedInput;

foreach (['photo.jpg', 'already.webp'] as $file) {
    try {
        $pixels = ImageInput::pixels($file);
    } catch (SkippedInput $e) {
        // $e->reason(): 'webp (already the target format)'
        // $e->slug(): 'skipped_webp'
        // $e->meta: size and container, read from the header
        continue;
    }

    // Encode or enqueue $pixels here.
}
```

`SkippedInput` extends `RuntimeException`. You can also check ahead of time
with `ImageInput::isWebp($file)`.

If you do want to re-encode a WebP, set `ImageInput::$skipWebp = false` or use
`--reencode-webp` on the command line. You'll need GD to read the source.

On the command line, a skip returns **exit code 4** and leaves the output
alone. A queue can use that to tell a skipped file from a failed conversion.

## What it supports

Transparency is kept by default, including palette PNGs and PNGs that mark
a particular colour as transparent with `tRNS`. The bundled PNG reader keeps
all eight bits of alpha. The alpha channel is then encoded losslessly in an
`ALPH` chunk alongside the lossy colour data. Opaque images use the simpler
`VP8 ` container.

Use `--alpha=flatten` to drop transparency, or `--alpha=fail` if you'd rather
send transparent images through another tool.

ICC colour profiles from PNG `iCCP` and JPEG `APP2` chunks are copied into
the WebP. `--no-icc` leaves the profile out; `--icc=FILE` supplies your own.

EXIF metadata isn't copied, and orientation tags aren't applied. If a photo
needs rotating, do that before you pass it to picowebp.

## Sizes

Quality 80, encoder defaults. "libwebp" is what `imagewebp()`, Imagick or
`cwebp` give you at their default effort — all three are libwebp and produce
byte-identical files here, so that column is what a normal WordPress host
already does today.

### Real images

Ten pictures off a laptop: phone JPEGs, phone screenshots, an app-UI capture and
flat logo artwork. Filenames are left out on purpose — format, dimensions and
size identify each row — and the label describes what the picture is, not what
it is called.

| image | format | input | picowebp | libwebp |
|---|---|---:|---:|---:|
| logo | JPEG 560×560 | 50 KB | 18 KB | 17 KB |
| app UI screenshot | PNG 2174×900 | 193 KB | 55 KB | 49 KB |
| photo | JPEG 960×1706 | 229 KB | 71 KB | 74 KB |
| photo | JPEG 1280×1280 | 275 KB | 71 KB | 76 KB |
| photo | JPEG 1280×1280 | 327 KB | 96 KB | 99 KB |
| graphic | PNG 1024×1024 | 356 KB | 51 KB | 42 KB |
| logo | PNG 1254×1254 | 442 KB | 54 KB | 54 KB |
| phone screenshot | PNG 1170×2532 | 1712 KB | 26 KB | 31 KB |
| phone screenshot, video call | PNG 1170×2532 | 4069 KB | 60 KB | 67 KB |
| phone screenshot | PNG 1170×2532 | 5275 KB | 122 KB | 127 KB |

Read the two groups separately, because they say different things.

**Photographs win.** All three JPEG photographs and all three tall phone
captures land **2.7–17.3% under libwebp**, at PSNR 0.2–1.6 dB lower. That is the
case this encoder is for, and on real photographs it is now ahead on bytes
rather than behind.

**Flat artwork loses.** Both logos, the app-UI capture and the 1024×1024
graphic come out **up to 21% larger**. This is the predictable weak spot: hard
colour edges and text are what a transform-based lossy codec is worst at, and
libwebp's rate-distortion search is a decade of tuning at exactly that content.
PSNR is a poor guide here — on the two images with transparency it is not even
comparable, because the RGB under a fully transparent pixel is arbitrary and the
two encoders simply fill it differently (that is the whole of the
35.80-vs-28.86 dB row).

### Generated fixtures

The table below is the synthetic set this encoder was developed against —
gradients, noise and graphics assembled in code, not photographs. It is
reproducible and useful for catching regressions, but it is **not** a stand-in
for real images; the table above is.

| source | input | picowebp | libwebp |
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

So on these fixtures picowebp is **1.7–5.8% under libwebp** up to about 3 MP and
**2.4–4.1% under at 12 MP**, at PSNR a hair below it (0.05–0.25 dB). Earlier
revisions of this table had it the other way round — 4–8% *over* below 3 MP and
18–19% *over* at 12 MP. The 4×4 intra modes, coefficient-probability adaptation
and skip flags all landed after that measurement, which is most of the
difference; the rest is that these rows were re-measured with the encoder as it
ships now.

Raised to maximum effort (`cwebp -m6`) libwebp claws back 1.4–5.8% depending on
the image: it ties at 12 MP (1.1% ahead of us on `huge-photo.jpg`, 1.4% behind
on `huge-photo.png`) and stays 0.5–7% behind us below that. It gets there in
2.2 seconds on the 12 MP frame where we take 377, which is the whole trade this
package exists to make.

Two honest caveats. These are synthetic images, so treat the table as evidence
about *those* images rather than a general claim — the real-image table above
is the one to trust. And it is a single quality point; the encoder's
rate-distortion behaviour at other qualities has been checked but not tabulated.

## Cost

Three separate measurements get quoted about this codec, and mixing them makes
it look either faster or slower than it is. All of them below are: this laptop,
PHP 8.4, quality 80, one process, nothing cached.

| label | what it includes |
|---|---|
| **read** | decoding the source image into an RGB buffer — `PngReader` / `JpegReader`, or GD where GD is installed |
| **encode** | turning that RGB buffer into WebP — `Vp8LossyEncoder` alone |
| **end to end** | read + encode in one process, which is what `bin/picowebp` does |

### Read (decode only)

| source read | per MP | 12 MP | notes |
|---|---:|---:|---|
| bundled JPEG, no GD | 1.5 s | 18.0 s | 53% inverse DCT, 27% upsampling + YCbCr→RGB, 6% block stores, 13% Huffman |
| bundled PNG, no GD | ~0.4 s | ~5 s | DEFLATE plus unfiltering; the cheap path |
| GD (either format) | ~0.12 s | 1.4 s | libjpeg-turbo decodes it in 0.11 s; the rest is pulling pixels out one call at a time |

### Encode (no read, no decode)

Shipped defaults, measured end to end of the encoder:

| image | encode | per MP | peak memory |
|---|---:|---:|---:|
| 0.96 MP synthetic JPEG | 25.0 s | 26.0 | 14 MB |
| 1.64 MP real JPEG | 37.4 s | 22.8 | 25 MB |
| 3.0 MP synthetic JPEG | 78.8 s | 26.3 | 24 MB |
| 12 MP synthetic JPEG | 377.5 s | 31.5 | 84 MB |

So **end to end** a 1 MP image is ~26 s and a 12 MP one is ~395 s with the
bundled reader (6.6 minutes; ~379 s if GD did the read). Against libwebp at its
default effort — `cwebp -q 80`, ~0.1 s for any of these — picowebp is roughly
**200–300× slower**. That is the entire trade this package exists to make.

### What each quality setting costs

Measured with the same two images, so the columns are comparable:

| configuration | 0.96 MP synthetic | 1.64 MP real | bytes vs defaults |
|---|---:|---:|---|
| **defaults** (B_PRED + adaptation + RD) | 26.0 s/MP | 22.8 s/MP | reference |
| `--no-bpred` | 11.4 s/MP | 9.9 s/MP | −1% synthetic, **−17% real** |
| `--no-adapt-probs` | 13.4 s/MP | 11.6 s/MP | +23% / +2% |
| `--no-rd` | 7.3 s/MP | 6.5 s/MP | +9% / **+33%** |
| `--no-bpred --no-adapt-probs --no-rd` | 2.6 s/MP | 2.0 s/MP | +40% / **+47%** |
| `--trellis` (off by default) | 61.1 s/MP | 30.7 s/MP | −4% / −14%, at PSNR −0.7 / −2.8 dB |
| libwebp, `cwebp -q 80` | ~0.1 s/MP | ~0.1 s/MP | see [Sizes](#sizes) |

Two things this table says that are easy to get wrong. 4×4 intra modes are worth
their CPU on real photographs — 17% fewer bytes at the same PSNR for 2.3× the
time — and look like a wash on the synthetic fixtures, so judge them on real
images. And the reduced configuration is where this README's older "about 2
CPU-seconds per megapixel" and "64 seconds for a 12 MP frame" figures came from:
they described **that** configuration, encoding only, and they are not the
shipped defaults.

### The resumable path

That is why there is a second, resumable path. `PlaneStore` decodes once and
stages the padded planes to disk, and the encoder then works in bands of
macroblock rows (`beginBand()` / `encodeRowRange()` / `compactReconWindow()` /
`finishFrame()`), with `exportState()` and `importState()` carrying a partly
done frame across invocations. These figures include **staging, a statistics
pass and the encoding pass** — they are not comparable to the encode-only table
above. On a 3 MP image the loop holds **23 MB** where the single-shot encoder
peaks at 24 MB, and the ticks stay small and bounded regardless of frame size.

It is not free: two passes instead of one, so about **twice the CPU** of the
single-shot encoder (79 s of encoding becomes 158 s of work at 3 MP). It buys
memory headroom and resumability, not speed. The one thing it cannot fix is
that the *decoded* image still has to fit once during staging, so cap by
megapixels before you start.

[`examples/chunked-queue.php`](examples/chunked-queue.php) runs that loop end
to end in one process, and checks that its bytes match the single-shot encoder
on every run.

Run either encoding path from a background queue so page requests remain
responsive.

## Limitations

These are the limits most likely to matter when processing a media library.
The timings below were measured on macOS with PHP 8.4, quality 80 and the
default settings.

**Large images take time.** A 12 MP frame takes 377 s to encode and peaks at
84 MB, plus up to 18 s to read the JPEG (1.5 s with GD). Split into two-second
ticks, that's ~190 ticks and ~10 minutes of wall clock. Running those
ticks back to back can still use up a shared host's CPU allowance.

Smaller images are easier to manage: 25 s for a 0.96 MP image and 37 s for a
1.64 MP one. Even so, a 1,000-image library adds up to **7–22 CPU-hours** of work,
depending on image size. Set a megapixel limit, leave time between queue ticks,
and keep the work out of page requests.

**Progressive JPEG needs GD.** The bundled reader only handles baseline JPEG.
Adding progressive support would mean about 500 lines for DC/AC first and
refinement scans, plus a buffer for the whole frame: 36 MB packed at 12 MP,
or 363 MB as PHP arrays. The inverse transform has to wait until all the
scans are read.

The extra CPU cost is estimated at +17–27%; Huffman decoding currently takes
13% of decode time. Memory is the harder problem. On a 128 MB host, that
buffer would sit on top of a peak already at 84 MB for a 12 MP image. A pure
PHP implementation would need a megapixel cap. For now, GD handles these files.

**Logos and screenshots can come out larger.** picowebp produces files up to
21% larger than libwebp on the flat artwork and app screenshots in these benchmarks.
Check the output size before replacing an original. Sometimes keeping the
original is the better choice.

**PSNR only tells you part of the story.** picowebp is 0.05–0.25 dB below libwebp
on the fixtures and 0.2–1.6 dB below on the real photographs, while producing
fewer bytes on most of them. On images with transparency it is not even
comparable: the RGB under a fully transparent pixel is arbitrary, so two
encoders that look identical on screen can "differ" by 20 dB.

The most useful improvements would be a faster inverse DCT (52% of decode
time), better trellis tuning, and progressive JPEG support. Trellis currently
saves 14% on file size at a cost of 2.8 dB of PSNR on a real photograph, so it
stays off by default.

## Tests

```sh
composer test
# or: php tests/run.php
```

The tests check the WebP bitstream, transparency, colour profiles, image
readers, resumable encoding and CLI behaviour. If `dwebp` is on `PATH`, they
also decode the output with libwebp and compare it with the source.

There are checks for things that tend to go wrong in a queue: files that are
already WebP, malformed inputs, invalid options and output paths that can't
be written. The tests also cover reader reuse and resuming an encode without
changing the resulting bytes.

The separate research tree this package came from has more reader checks.
`check-png-reader.php` compares PNG decoding with generated fixtures and GD;
`check-jpeg-reader.php` compares JPEG decoding with libjpeg across sampling
factors, restart markers, optimised tables, greyscale and odd image sizes.
Those scripts aren't included in this repository.

## Not included

There's no general-purpose lossless WebP encoder here.
`PicoWebP\Spike\Vp8lEncoder` is an internal helper for alpha channels. Used
for a whole image, its output is 123–124% of the source PNG size, where
libwebp lossless manages 68–76%.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
