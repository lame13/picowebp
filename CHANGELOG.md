# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-20

First release. picowebp writes VP8 lossy WebP key frames in pure PHP — no GD,
no Imagick, no `cwebp`, no libwebp, no shelling out — for the host that has no
WebP support at all.

### Added

**Encoder**

- `PicoWebP\Vp8\Vp8LossyEncoder` — takes a packed RGB string and returns a
  complete WebP file. `fromQuality()` / `qIndexForQuality()` map a 0–100
  quality onto the VP8 quantiser.
- 16×16 and 4×4 intra prediction (all ten `B_PRED` modes) under a
  rate-distortion mode search, coefficient-probability adaptation, macroblock
  skip flags, and an optional per-coefficient trellis pass — off by default,
  because it buys ~14% fewer bytes at ~2.8 dB of PSNR on a real photograph.
- CPU/bytes knobs at both the API and the CLI: `enableRd`, `rdLambda`,
  `rdPruneCandidates`, `adaptProbs`, `enableBPred`, `enableSkipFlags`,
  `enableTrellis`, and the `--no-rd`, `--no-adapt-probs`, `--prune`,
  `--trellis`, `--no-skip` flags that go with them.

**Container features**

- Alpha: a translucent source is written as `VP8X` + `ALPH` + `VP8 `, with the
  alpha plane coded losslessly and byte-exactly; fully opaque images keep the
  simple `VP8 ` container. `--alpha=keep|flatten|fail`, `--alpha-filter=0..3`.
- Colour profiles: PNG `iCCP` and JPEG `APP2` profiles are carried through to
  an `ICCP` chunk. `--icc=FILE`, `--no-icc`.

**Bundled readers — pure PHP, no image extension required**

- `PngReader` — every colour type and bit depth, interlaced or not, palettes
  and `tRNS`, `iCCP`. Palette images are expanded to true colour before
  anything reads a pixel, and all eight bits of alpha are kept where libgd
  rounds to seven.
- `JpegReader` — baseline sequential 8-bit JPEG, greyscale or YCbCr, every
  sampling factor, restart markers, `APP2` ICC.
- `ImageInput` — one entry point over both, plus GD where GD happens to be
  installed: `pixels()`, `load()`, `isWebp()`, `mayHaveAlpha()`,
  `iccProfile()`, and the `$preferBundled` / `$gdAvailable` switches.

**An input that is already a WebP**

- `ImageMeta` sniffs `RIFF....WEBP` on the file's **bytes**, not its
  extension, and reports real geometry for the `VP8X`, `VP8 ` and `VP8L`
  containers.
- `ImageInput::pixels()` throws `SkippedInput` — a `RuntimeException`, so a
  caller that has never heard of it keeps working — carrying `reason()`,
  `slug()` and `meta`. `--reencode-webp` overrides it, and that path needs GD.

**Command line**

- `bin/picowebp in.jpg out.webp`, with `--rgb=WxH` and `--alpha-plane=FILE`
  for headerless input, `--dump-yuv=FILE`, `--json`, `--bundled`, `--no-gd`,
  `--reencode-webp`.
- Exit codes a queue can act on: `0` encoded, `1` error, `3` `--alpha=fail`
  refused, `4` skipped. A destination that cannot be written — a missing
  directory, a directory, an unwritable `--dump-yuv` path — is a failure with
  a message rather than a save that never happened.

**Resumable path**

- `PlaneStore` decodes once and stages the padded planes to disk; the encoder
  then works in bands of macroblock rows — `beginBand()`, `encodeRowRange()`,
  `compactReconWindow()`, `finishFrame()` — with `exportState()` /
  `importState()` carrying a part-done frame across invocations. Output is
  byte-identical to the single-shot encoder. It costs about twice the CPU and
  buys memory headroom and resumability, not speed.

**Everything else**

- `PicoWebP\Spike\Vp8lEncoder`, the internal lossless helper that supplies the
  headerless stream an `ALPH` chunk needs. Not a general-purpose lossless
  encoder, and not a PNG replacement.
- `examples/encode.php` and `examples/chunked-queue.php`.
- `php tests/run.php` (also `composer test`): bitstream structure, the alpha
  and ICC containers, PSNR against the source when `dwebp` is on `PATH`, both
  the bundled readers and the GD path, the WebP-skip route end to end, and the
  CLI's failure exit codes.

### Notes

- Requires PHP 8.1+ and `ext-zlib`. `ext-gd` is optional: it is faster where it
  exists, and it is the only route to a progressive JPEG or to re-encoding a
  WebP source.
- Progressive JPEG is not decoded without GD. The bundled reader refuses it by
  name, saying which variant it found, rather than guessing.
- BMP, GIF, TIFF/TIF and raw Y′CbCr inputs are not implemented.
- This is a fallback encoder, not a libwebp replacement. Expect roughly
  200–300× libwebp's CPU, a 377 s / 84 MB queue job for one 12 MP frame, and up
  to 21% larger files on flat logos and UI artwork. The measured tables, and
  what would move them, are in the README.

[1.0.0]: https://github.com/lame13/picowebp/releases/tag/1.0.0
