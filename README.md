# picowebp

A pure-PHP WebP encoder.

picowebp turns a packed RGB buffer into a **VP8 lossy WebP key frame** using
nothing but PHP. No GD, no Imagick, no `cwebp`, no libwebp, no shelling out.
The bytes it produces are ordinary WebP files: libwebp's `dwebp`, `webpinfo`,
every browser and every image pipeline read them normally.

It exists for the case where a host has no WebP support at all: no GD with
WebP, no Imagick, no `cwebp` binary, no WASM. It is a fallback, not a
general-purpose encoder — read [Cost](#cost) before reaching for it.

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| `ext-zlib` | required — PNG's DEFLATE container and the alpha channel both need it |
| `ext-gd` | optional — faster, handles progressive JPEG, and is the only way to re-encode a WebP source; never required |

Nothing else is needed. `PngReader` and `JpegReader` are pure PHP, so a host
with no image extension at all can still go from a file on disk to WebP. GD is
used when it happens to be there, because it is quicker — but the bundled
readers are not merely a fallback: the PNG one keeps all eight bits of alpha
where libgd rounds to seven, and expands palette entries and `tRNS` keys by
the specification rather than libgd's approximation.

The encoder proper (`Vp8LossyEncoder`) takes a packed RGB string and needs
nothing but zlib. If you already have pixels, skip the readers entirely —
`encode()` is the whole interface.

## Input formats

| input | status |
|---|---|
| JPEG (`.jpg`, `.jpeg`) | ✅ supported — baseline sequential, 8-bit, greyscale or YCbCr, all sampling factors, restart markers, `APP2` ICC |
| JPEG, progressive | ❌ not implemented without GD — the bundled reader refuses it by name; where GD is installed it takes those files instead |
| PNG | ✅ supported — every colour type and bit depth, interlaced or not, palettes, `tRNS`, `iCCP` |
| Raw RGB | ✅ supported — `--rgb=WxH`, with an optional raw 8-bit alpha plane |
| WebP | ⏭️ skipped — already the format this writes; detected by content, never re-encoded by accident |
| BMP | ❌ not implemented |
| Raw Y′CbCr | ❌ not implemented |
| GIF | ❌ not implemented |
| TIFF / TIF | ❌ not implemented |

**Legend:** ✅ supported · ⏭️ skipped, nothing to do · ❌ not implemented yet.

**The remaining format limitation is progressive JPEG without GD.**  Progressive
is roughly ten scans of "here is a bit more of every coefficient", which the
baseline reader has no path for, so it refuses a progressive file by name —
saying which variant it found — rather than guessing. Where GD is installed it
takes those images instead, which covers essentially every host that runs
WordPress. Where it is not, re-save the image as baseline or feed `--rgb`. See
[Limitations](#limitations) for what adding it would cost.

The four ❌ rows *below* progressive are the direction of travel rather than a
roadmap: BMP and GIF are cheap enough that they are mostly palette plumbing,
TIFF is not, and raw Y′CbCr is there for callers who already hold subsampled
planes and would rather hand them over than re-derive them. None of them is
implemented.

### A WebP in, nothing out

An input that is already a WebP is skipped rather than converted — the job a
converter is handed has been done already, and re-encoding would burn CPU to
lose quality from pixels that are already quantised. The decision is made on
the file's **bytes**, not its extension, so `photo.jpg` that is really a WebP
is still skipped and `photo.webp` that is really a PNG is still converted.

```php
use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\SkippedInput;

try {
    $pixels = ImageInput::pixels($file);
} catch (SkippedInput $e) {
    // $e->reason() -> 'webp (already the target format)'
    // $e->slug()   -> 'skipped_webp'
    // $e->meta     -> size and container, read from the header
    continue;   // nothing to do, and nothing wrong
}
```

`SkippedInput` extends `RuntimeException`, so a caller that has never heard of
it keeps working. `ImageInput::isWebp($file)` asks the same question without an
exception, and `ImageInput::$skipWebp = false` (or `--reencode-webp` on the
command line) overrides the whole thing for the caller who really does want a
WebP coded a second time. That is the one input which needs GD, since nothing
here decodes WebP in pure PHP.

On the command line a skip is **exit code 4** with no output file written, so a
queue can tell "converted" from "there was nothing to convert".

## Install

```sh
composer require nikocodes/picowebp
```

## Quick start

```php
use PicoWebP\Vp8\ImageInput;
use PicoWebP\Vp8\Vp8LossyEncoder;

// With GD or without it: the bundled readers are pure PHP.
$pixels = ImageInput::pixels('photo.jpg');

$enc = Vp8LossyEncoder::fromQuality(80);
$enc->alphaPlane = $pixels['alpha'];   // null unless the source is translucent
$enc->iccProfile = $pixels['icc'];     // null unless the source is tagged

file_put_contents('photo.webp', $enc->encode($pixels['rgb'], $pixels['w'], $pixels['h'])['webp']);
```

Or straight from raw pixels, with no image extension in the picture at all:

```php
$enc = Vp8LossyEncoder::fromQuality(80);
$webp = $enc->encode($pixels, $width, $height)['webp'];
```

There is a runnable version of both in [`examples/`](examples).

`ImageInput::$preferBundled = true` forces the bundled readers even where GD
is installed — worth it for the exact alpha and the palette handling.
`ImageInput::$gdAvailable = false` guarantees no GD code runs at all.

## Command line

```sh
bin/picowebp photo.jpg photo.webp --quality=80
bin/picowebp pixels.raw out.webp --quality=80 --rgb=1200x800
```

`--rgb=WxH` reads the input as headerless 8-bit RGB (3 bytes per pixel, row
major, top-left first) and bypasses GD entirely. `--alpha-plane=FILE` attaches
a raw 8-bit alpha plane to such an input.

Run `bin/picowebp` with no arguments for the full flag list, including
`--alpha=keep|flatten|fail`, `--icc=FILE`, `--no-icc`, `--no-rd`,
`--no-adapt-probs`, `--bundled` (use the bundled readers even where GD
exists), `--no-gd` (never touch GD), `--reencode-webp` (code a WebP source
instead of skipping it) and the CPU/quality knobs.

## What it supports

**Input formats:** PNG and JPEG files through the bundled readers, plus raw RGB
(and an optional raw alpha plane) through the encoder itself, and WebP in —
detected and skipped. See [Input formats](#input-formats) for exactly what the
bundled readers cover.

Palette PNGs are expanded to true colour before anything reads a pixel —
libgd hands back palette *indices* from `imagecolorat()`, so an indexed PNG
used to be coded as whatever colours those small integers happened to name.

**Transparency:** a source with a usable alpha channel gets the extended
container — `VP8X` + `ALPH` + `VP8 ` — with the alpha plane coded losslessly
and byte-exactly. Fully opaque images keep the simple `VP8 ` container.
`--alpha=flatten` restores the old "drop it" behaviour, `--alpha=fail` refuses
the image so a caller can route it somewhere else.

Both the indexed-PNG case and a `tRNS` chunk on a truecolour PNG are handled;
libgd ignores the latter, which used to make those images come out opaque.

**Colour profiles:** PNG `iCCP` and JPEG `APP2` profiles are read and written
out as an `ICCP` chunk, so a tagged source stays tagged. `--no-icc` drops the
profile, `--icc=FILE` replaces it.

EXIF is not carried over, and orientation tags are not applied: pixels are
coded exactly as the decoder hands them over, which is what GD- and
Imagick-based pipelines do too.

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

Whichever path you take: everything here belongs in a queue, never inline in a
page request.

If a page request ever waits on this, you have done it wrong. Tick it from a
background queue.

## Limitations

Four things worth knowing before this goes in front of a real media library.
Everything below is measured, not estimated; the numbers come from this machine
(PHP 8.4, macOS), q80, shipped defaults.

**1. A 12 MP photo is a queue job measured in minutes, and it is not a shared
hosting job at all.** Encode-only, shipped defaults: 377 s at 84 MB peak for
one 12 MP frame, plus up to 18 s to read the JPEG (1.5 s if GD does the read).
Split into two-second ticks that is ~190 ticks and ~10 minutes of wall clock,
which a queue handles fine and a CloudLinux CPU meter will notice if the ticks
run back to back. Below 2 MP the arithmetic is much friendlier — 25 s for a
0.96 MP image, 37 s for a 1.64 MP one — but a
1,000-image library still lands in the **7–22 CPU-hour** range depending on how
big the pictures are. Cap by megapixels, spread the ticks, and never start one
in a page request.

**2. Progressive JPEG needs GD.** The bundled reader is baseline-sequential
only, and adding progressive is the single biggest piece of work left in the
codec: about 500 lines for DC/AC first and refinement scans, and a coefficient
buffer that has to hold the whole frame (36 MB packed at 12 MP, 363 MB if kept
as PHP arrays) before the inverse transform can run once at the end. It is
affordable in CPU — the Huffman layer is only 13% of decode time, so the added
scans cost roughly +17–27% — but it is expensive in memory and in the number of
ways it can be subtly wrong. On a 128 MB host, adding that buffer to a peak
that is already 84 MB at 12 MP is the difference between working and not
working, so a progressive decoder in pure PHP would realistically ship with a
megapixel cap attached. Where GD exists, it already handles those files.

**3. Flat graphics are libwebp's home turf.** On logos, app-UI captures and
flat artwork libwebp wins by up to 21% on bytes, and that is where the
transform-based approach is structurally weakest. If a conversion comes out
larger than the file it replaces, the honest answer is not to convert it —
which is what a keep-if-smaller rule is for in a WordPress pipeline.

**4. PSNR is not the same as quality.** picowebp is 0.05–0.25 dB below libwebp
on the fixtures and 0.2–1.6 dB below on the real photographs, while producing
fewer bytes on most of them. On images with transparency it is not even
comparable: the RGB under a fully transparent pixel is arbitrary, so two
encoders that look identical on screen can "differ" by 20 dB.

What would move the needle most, in order: an integer or otherwise faster
inverse DCT (52% of decode time, and it would help every JPEG), tuning the
trellis pass before anyone enables it (it currently buys 14% fewer bytes for
2.8 dB of PSNR on a real photograph, which is not a good trade), then
progressive support.

## Tests

```sh
composer test
# or: php tests/run.php
```

The suite checks bitstream structure, the alpha and ICC containers, and — when
`dwebp` is on `PATH` — decodes our own output back and measures PSNR against
the source. It runs the bundled readers and the GD path both, and it checks the
WebP-input skip from every side: the header parse for all three containers, the
exception, the staging path, and the CLI's exit code and quiet output.
It also runs the CLI against destinations that cannot be written — a missing
directory, a directory, a `--dump-yuv` path that will not open — and insists
each one exits nonzero rather than reporting a save that never happened.

The research tree this package grew out of has the heavier checks:
`check-png-reader.php` proves the PNG reader against independently generated
ground truth (and against libgd where libgd is exact), and
`check-jpeg-reader.php` proves the JPEG reader against libjpeg across
subsampling, restart markers, optimised tables, greyscale and odd geometry.

## Not included

The `PicoWebP\Spike\Vp8lEncoder` class is an internal helper: it supplies the
headerless lossless stream that `ALPH` chunks need. It is not a PNG
replacement — as a general lossless encoder it emits 123–124% of the source
PNG where libwebp lossless manages 68–76%.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
