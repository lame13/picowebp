<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

require_once __DIR__ . '/Vp8Tables.php';
require_once __DIR__ . '/AlphaCoder.php';

/**
 * Minimal VP8 boolean (arithmetic) encoder, per RFC 6386 section 7.3.
 */
final class BoolEncoder
{
    private string $buf = '';
    private int $range = 255;
    private int $bottom = 0;
    private int $bitCount = 24;

    /** @var array<int, array{0:int,1:int}>|null */
    public ?array $log = null;

    public function writeBool(int $prob, int $bit): void
    {
        if ($this->log !== null) {
            $this->log[] = [$prob, $bit];
        }
        $split = 1 + ((($this->range - 1) * $prob) >> 8);

        if ($bit) {
            $this->bottom = ($this->bottom + $split) & 0xFFFFFFFF;
            $this->range -= $split;
        } else {
            $this->range = $split;
        }

        while ($this->range < 128) {
            $this->range <<= 1;
            if ($this->bottom & 0x80000000) {
                $this->addOneToOutput();
            }
            $this->bottom = ($this->bottom << 1) & 0xFFFFFFFF;
            if (--$this->bitCount === 0) {
                $this->buf .= chr(($this->bottom >> 24) & 0xFF);
                $this->bottom &= 0xFFFFFF;
                $this->bitCount = 8;
            }
        }
    }

    /** L(n): n literal bits, MSB first, each coded with probability 128. */
    public function writeLiteral(int $value, int $bits): void
    {
        for ($i = $bits - 1; $i >= 0; $i--) {
            $this->writeBool(128, ($value >> $i) & 1);
        }
    }

    private function addOneToOutput(): void
    {
        $i = strlen($this->buf) - 1;
        while ($i >= 0 && $this->buf[$i] === "\xFF") {
            $this->buf[$i] = "\x00";
            $i--;
        }
        if ($i >= 0) {
            $this->buf[$i] = chr(ord($this->buf[$i]) + 1);
        }
    }

    public function flush(): string
    {
        $c = $this->bitCount;
        $v = $this->bottom;

        if ($v & (1 << (32 - $c))) {
            $this->addOneToOutput();
        }
        $v = ($v << ($c & 7)) & 0xFFFFFFFF;
        $c >>= 3;
        while (--$c >= 0) {
            $v = ($v << 8) & 0xFFFFFFFF;
        }
        $c = 4;
        while (--$c >= 0) {
            $this->buf .= chr(($v >> 24) & 0xFF);
            $v = ($v << 8) & 0xFFFFFFFF;
        }

        return $this->buf;
    }
}

/**
 * Pure-PHP lossy WebP encoder: single VP8 key frame, intra 16x16 and 4x4
 * prediction, no loop filter, one token partition.
 *
 * Known simplifications (documented, all bitstream-legal):
 *   - luma uses 16x16 DC/V/H/TM and 4x4 B_PRED; chroma uses DC/V/H/TM only
 *   - no loop filter (loop_filter_level = 0)
 *   - mb_no_skip_coeff = 0, so every macroblock carries residual data
 *   - coefficient probabilities are never updated (defaults are used)
 */
final class Vp8LossyEncoder
{
    private const COSPI8 = 20091;
    private const SINPI8 = 35468;

    private int $qIndex;
    private ?int $quality = null;

    /** @var array<int, array<int, array{0:int,1:int}>> */
    private array $coeffPaths = [];
    /** @var array<int, array<int, array{0:int,1:int}>> */
    private array $ymodePaths = [];
    private array $uvmodePaths = [];
    /** @var array<int, array<int, array{0:int,1:int}>> */
    private array $bmodePaths = [];

    private string $y = '';
    private string $u = '';
    private string $v = '';

    private string $ry = '';
    private string $ru = '';
    private string $rv = '';

    /**
     * Absolute (frame) row index of the first row stored in the source and
     * reconstruction planes.  Both are 0 for the whole-frame path; the chunked
     * path keeps only a sliding window of rows so that peak memory does not
     * scale with the image.
     */
    private int $srcBase = 0;
    private int $recBase = 0;

    private int $w = 0;
    private int $h = 0;
    private int $wp = 0;
    private int $hp = 0;
    private int $cw = 0;
    private int $ch = 0;
    private int $mbw = 0;
    private int $mbh = 0;
    /** Number of macroblock rows already written (chunked path). */
    private int $mbyDone = 0;

    private ?BoolEncoder $headerEnc = null;
    private ?BoolEncoder $tokenEnc = null;

    private int $qYdc = 0;
    private int $qYac = 0;
    private int $qUvdc = 0;
    private int $qUvac = 0;
    private int $qY2dc = 0;
    private int $qY2ac = 0;

    /**
     * Cheap tuning knobs (see outputs/cheap-wins-pure-php-vp8.md):
     *  - loopFilterLevel: written into the frame header; the *decoder* applies
     *    the deblocking filter, so this costs zero bytes and zero encoder time.
     *    Intra prediction still uses unfiltered samples, so the encoder's own
     *    reconstruction stays valid.
     *  - quantBias: dead-zone width of the quantiser.  0.5 = round to nearest,
     *    larger values zero out more small coefficients (smaller files).
     */
    public int $loopFilterLevel = 0;

    /**
     * Coefficient probability adaptation (the thing libwebp's method 3+ does).
     *
     * The token partition is entropy coded with the 1056 default coefficient
     * probabilities.  Encoding the frame once and counting how often each tree
     * node actually produces a 0 or a 1 gives the frame's own statistics; the
     * header can then carry new odds for the slots where they pay for
     * themselves.  On this encoder that is worth 20-30% of the whole file,
     * which is the single biggest remaining lever.
     *
     * Costs a second full encode pass (~1.9x CPU).  The reconstruction is
     * bit-identical either way -- only the token *coding* changes.
     */
    public bool $adaptProbs = true;
    /** Bits of slack before a probability update is considered worth its flag. */
    public float $probAdaptMargin = 8.0;
    /** @var array<int,int>|null 1056 adapted probabilities; null = keep defaults. */
    private ?array $coeffProbs = null;
    /** @var array<int,int> */
    private array $probN0 = [];
    /** @var array<int,int> */
    private array $probN1 = [];
    private bool $collectStats = false;
    private int $probUpdates = 0;
    private float $statsSeconds = 0.0;
    /**
     * 0.65 measured as a Pareto win over round-to-nearest (0.5) on every
     * fixture/quality tested: 10-22% fewer bytes at equal or slightly better
     * PSNR.  See outputs/cheap-wins-pure-php-vp8.md.
     */
    public float $quantBias = 0.65;
    /** DC-only prediction (false) vs. DC/V/H/TM SAD mode search (true). */
    public bool $enableModeSearch = true;

    /**
     * Enable the ten 4x4 luma subblock modes (B_DC..B_HU) as an alternative to
     * the 16x16 modes, per macroblock.  Tested per macroblock with a
     * SAD comparison; see $bpredBias.
     */
    public bool $enableBPred = true;

    /**
     * I16-vs-I4 decision bias: a macroblock goes 4x4 only when
     * i4Sad < i16Sad * bpredBias.  1.0 = pure SAD (favours 4x4 on noisy
     * content, which costs more mode+DC bits); >1 favours the 16x16 modes.
     */
    public float $bpredBias = 1.0;

    /**
     * Rate-distortion mode selection: instead of picking the prediction with
     * the smallest *error* (SAD), pick the one that minimises the trade-off
     *
     *     J = SSD(source, reconstruction) + lambda * bits
     *
     * Every candidate is therefore really transformed, quantised and
     * reconstructed, and its coefficient/mode bits are counted with the same
     * probability table the range coder uses.  This is the difference between
     * "cheapest prediction" and "cheapest *coding*", and it is what libwebp
     * does.  Costs roughly 2-3x the CPU, so it is a knob, not a law.
     */
    public bool $enableRd = true;
    /**
     * lambda = rdLambda * 40 * (qYac / 20)^2, i.e. distortion in SSD per bit
     * of rate, scaled with the luma AC quantiser (larger steps tolerate more
     * error per bit saved).
     *
     * 0.35 is the measured sweet spot at quality 80: on photo.jpg it lands at
     * 191,510 B / 35.70 dB against libwebp's 195,118 B / 35.63 dB, i.e. a
     * point libwebp's own encoder does not reach (see bench-rd.php).  Higher
     * values trade PSNR for bytes in the usual way.
     */
    public float $rdLambda = 0.35;

    /**
     * Per-plane weights on the luma lambda.  libwebp carries separate
     * lambda_i16 / lambda_i4 / lambda_uv; one global lambda over-weights the
     * chroma planes, which have a quarter of the samples and therefore a
     * quarter of the distortions that lambda is calibrated against.
     */
    public float $rdLambdaI16 = 1.0;
    public float $rdLambdaI4 = 1.0;
    public float $rdLambdaUv = 1.0;

    /**
     * Evaluate the full rate-distortion cost for only this many 4x4 modes per
     * subblock, pre-ranked by SAD.  Zero (the default) evaluates all ten.
     *
     * Measured on small-photo.jpg at quality 80: four candidates cut 28% off
     * the encode and cost 1.0% more bytes (15,576 vs 15,420), so this is a
     * CPU knob rather than a default -- the byte-optimal setting is all ten.
     */
    public int $rdPruneCandidates = 0;

    /**
     * Coefficient-level trellis quantisation.
     *
     * Every coefficient is quantised independently by a fixed dead-zone rule,
     * which optimises the *error* of one coefficient and ignores what that
     * coefficient costs to code.  The same trade-off the mode search makes one
     * level up applies one level down: a level is only worth keeping when
     *
     *     distortion increase  <  lambda * bits saved
     *
     * Trimming the tail of a block also shortens its last-coded-coefficient
     * run, which is why the win is larger than the sum of the individual
     * coefficients suggests.
     */
    public bool $enableTrellis = false;
    /** Trimming sweeps over a block; each sweep may accept several changes. */
    public int $trellisSweeps = 2;
    /** How far below the last coded coefficient the trimmer will look. */
    private const TRELLIS_TAIL = 7;
    /**
     * Spatial energy of each inverse-transform basis vector, i.e. how much
     * squared error one quantiser step of that coefficient contributes.
     * Computed once from the transform itself rather than hard-coded.
     *
     * @var array<int,float>
     */
    private static array $basisEnergy = [];

    /**
     * Fixed-point bit costs of the range coder's binary symbols:
     * $bitCost[p][b] = 256 * -log2(P(bit b | odds p)).
     *
     * Deliberately built from the *default* coefficient probabilities, not the
     * adapted ones: the mode decisions then come out identical in the
     * statistics pass and in the final pass, which is what lets the chunked
     * queue write the header (where the adapted odds live) only after a full
     * counting pass.
     *
     * @var array<int,array{0:int,1:int}>
     */
    private array $bitCost = [];

    /**
     * Precomputed symbol sums, so the rate model costs a couple of array
     * lookups per coefficient instead of walking the tree:
     *
     *  - $pathCost[$planeBandCtx][$token][$afterZero] : the token's tree path.
     *    The second index is 1 when the previous token was a zero, where the
     *    decoder re-enters at node 2 and the root step is skipped.
     *  - $catCost[$category][$extra] : the fixed-probability category bits.
     *  - $bmodeCost/$ymodeCost/$uvmodeCost : prediction-mode symbols.
     *
     * @var array<int,array<int,array{0:int,1:int}>>
     */
    private array $pathCost = [];
    /** @var array<int,array<int,int>> */
    private array $catCost = [];
    /** @var array<int,array<int,int>> */
    private array $bmodeCost = [];
    /** @var array<int,int> */
    private array $ymodeCost = [];
    /** @var array<int,int> */
    private array $uvmodeCost = [];
    /** Debug: accumulate what the RD rate model predicts for the frame. */
    public bool $measureModel = false;
    private int $modelBits = 0;

    /**
     * Subblock-mode context: the four modes above the current macroblock
     * column, persisting across macroblock rows (RFC 6386 11.4 note 3).
     *
     * @var array<int,int>
     */
    private array $bAbove = [];
    /** @var array<int,int> the four modes left of the current macroblock */
    private array $bLeft = [];
    /** Number of macroblocks coded with 4x4 luma prediction. */
    public int $mbIntra4Count = 0;
    /** Number of macroblocks whose coefficients all quantise to zero. */
    public int $mbAllZero = 0;

    /** Debug: force every 4x4 subblock to this mode (null = normal search). */
    public ?int $forceBMode = null;
    /** Debug: print the prediction/residual of the frame's first subblock. */
    public bool $dumpFirstBlock = false;
    /** Debug: emit one entry per macroblock, 1 when it is coded as skipped. */
    public ?array $skipLog = null;
    /** Debug: total SAD of the best 16x16 vs best 4x4 prediction, per frame. */
    public int $sumSad16 = 0;
    public int $sumSad4 = 0;

    /**
     * Emit mb_no_skip_coeff: every macroblock whose coefficients all quantise
     * to zero costs one cheap flag instead of sixteen EOB codes.
     *
     * Default OFF: prob_skip_false is written once, before any macroblock, so
     * a one-pass encoder has to guess it.  Measured with a fixed 200/256 it is
     * a small net loss (see docs/bpred-and-entropy-cleanups.md) on every
     * fixture; libwebp gets value out of the same flag because it picks the
     * probability from the frame's own statistics.
     */
    public bool $enableSkipFlags = true;
    /**
     * prob_skip_false, i.e. P(bit 0) = P(the macroblock does have
     * coefficients).  Fixed rather than adapted: adapting it needs a second
     * pass over the first partition, which the chunked encoder cannot do.
     */
    public int $skipProb = 128;
    /**
     * Derive prob_skip_false from the frame's own statistics instead of
     * guessing it.  The counting pass already visits every macroblock, so the
     * share of macroblocks that carry coefficients is free; a one-pass encoder
     * has to guess, and a guess is why this flag used to cost bytes.
     *
     * Safe because the flag never changes any decision: a macroblock is
     * skipped exactly when its coefficients all quantise to zero, and both
     * sides of every rate-distortion comparison carry the same flag cost, so
     * it cancels.  The reconstruction is therefore identical with and without
     * the flag, and the counting pass and the final pass skip the same set of
     * macroblocks whatever the probability is.
     */
    public bool $deriveSkipProb = true;

    /**
     * Quantiser deltas written into the frame header.  libwebp keeps chroma
     * AC finer than luma AC at the same base index; a negative uvAcDelta does
     * the same here.
     */
    public int $yDcDelta = 0;
    public int $uvDcDelta = 0;
    public int $uvAcDelta = 0;

    /**
     * Transparency, as an 8-bit plane at the *display* size in scan order, or
     * null for an opaque image.  When set, the file is written in the extended
     * format: 'VP8X' + 'ALPH' + 'VP8 '.
     *
     * The lossy VP8 bitstream has no alpha channel of its own, so transparency
     * travels beside it in its own chunk.  Without this, a transparent PNG
     * came back as a solid rectangle -- the loader threw the alpha away.
     */
    public ?string $alphaPlane = null;
    /** Filter applied to the alpha plane before coding (see AlphaCoder). */
    public int $alphaFilter = AlphaCoder::FILTER_AUTO;
    /** Optional ICC colour profile bytes, written as an 'ICCP' chunk. */
    public ?string $iccProfile = null;

    /** @var array<int,int> */
    private array $above = [];
    /** @var array<int,int> */
    private array $left = [];
    /**
     * Above-context flags for the current macroblock row, indexed by macroblock
     * column.  The block above a macroblock lives in the previous row, so this
     * must be stored *per column*: within one macroblock the "above" flags are
     * progressively replaced by that macroblock's own rows (row 1 reads row 0,
     * and so on), but the next macroblock in the row still needs the flags from
     * the row above.
     *
     * @var array<int, array<int,int>>
     */
    private array $aboveRow = [];

    /** Optional per-macroblock quantised-coefficient trace (debug/benchmark). */
    public array $trace = [];
    public bool $traceEnabled = false;
    /** @var array<int,string> */
    public array $ctxTrace = [];
    /** @var array<int,array<string,mixed>> */
    public array $blockLog = [];
    /** Set to [] to capture the first partition's exact (prob, bit) sequence. */
    public ?array $symbolLog = null;
    /** Debug: (above, left, chosen) subblock-mode context per written symbol. */
    public ?array $bmodeCtxLog = null;
    /** @var array<int, array{0:int,1:int}> */
    public array $tokenSymbolLog = [];

    public function __construct(int $qIndex = 40)
    {
        $this->qIndex = max(0, min(127, $qIndex));

        // Precompute tree paths (node, bit) for every token, once.
        $tokens = [
            0, 1, 2, 3, 4,             // DCT_0 .. DCT_4
            5, 6, 7, 8, 9, 10,         // cat1 .. cat6
            11,                        // EOB
        ];
        foreach ($tokens as $t) {
            $this->coeffPaths[$t] = $this->findPath(Vp8Tables::COEFF_TREE, 0, $t, []);
        }
        foreach ([0, 1, 2, 3, 4] as $mode) { // + B_PRED for the keyframe luma tree
            $this->ymodePaths[$mode] = $this->findPath(Vp8Tables::YMODE_TREE, 0, $mode, []);
            $this->uvmodePaths[$mode] = $this->findPath(Vp8Tables::UVMODE_TREE, 0, $mode, []);
        }
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9] as $mode) { // B_DC .. B_HU
            $this->bmodePaths[$mode] = $this->findPath(Vp8Tables::BMODE_TREE, 0, $mode, []);
        }

        $this->setupQuantizers();
        $this->initBitCosts();
    }

    /** Precompute the fixed-point cost of every binary symbol (see $bitCost). */
    private function initBitCosts(): void
    {
        $cost = [];
        for ($p = 1; $p <= 255; $p++) {
            $cost[$p] = [
                (int) round(-256.0 * log($p / 256.0, 2.0)),
                (int) round(-256.0 * log(1.0 - $p / 256.0, 2.0)),
            ];
        }
        $this->bitCost = $cost;

        // Token tree paths, per (plane, coefficient band, non-zero context).
        $defaults = Vp8Tables::DEFAULT_COEFF_PROBS;
        $this->pathCost = [];
        for ($plane = 0; $plane < 4; $plane++) {
            for ($band = 0; $band < 8; $band++) {
                for ($ctx = 0; $ctx < 3; $ctx++) {
                    $base = $plane * 264 + $band * 33 + $ctx * 11;
                    $idx = ($plane * 8 + $band) * 3 + $ctx;
                    for ($token = 0; $token <= 11; $token++) {
                        $full = 0;
                        $afterZero = 0;
                        foreach ($this->coeffPaths[$token] as $k => $step) {
                            $c = $cost[$defaults[$base + ($step[0] >> 1)]][$step[1]];
                            $full += $c;
                            if ($k > 0) {
                                $afterZero += $c;
                            }
                        }
                        $this->pathCost[$idx][$token] = [$full, $afterZero];
                    }
                }
            }
        }

        // Category extra bits: fixed probabilities, independent of the block.
        $this->catCost = [];
        for ($ci = 0; $ci < 6; $ci++) {
            $nbits = $ci === 5 ? 11 : $ci + 1;
            $pcat = Vp8Tables::PCAT[$ci];
            $tab = [];
            for ($extra = 0; $extra < (1 << $nbits); $extra++) {
                $bits = 0;
                for ($i = $nbits - 1; $i >= 0; $i--) {
                    $bits += $cost[$pcat[$nbits - 1 - $i]][($extra >> $i) & 1];
                }
                $tab[$extra] = $bits;
            }
            $this->catCost[$ci] = $tab;
        }

        // Prediction-mode symbols.
        $this->ymodeCost = [];
        foreach ($this->ymodePaths as $mode => $path) {
            $this->ymodeCost[$mode] = $this->symbolPathCost($path, Vp8Tables::YMODE_PROB);
        }
        $this->uvmodeCost = [];
        foreach ($this->uvmodePaths as $mode => $path) {
            $this->uvmodeCost[$mode] = $this->symbolPathCost($path, Vp8Tables::UVMODE_PROB);
        }
        $this->bmodeCost = [];
        for ($al = 0; $al < 100; $al++) {
            for ($mode = 0; $mode < 10; $mode++) {
                $bits = 0;
                foreach ($this->bmodePaths[$mode] as $step) {
                    $bits += $cost[Vp8Tables::KF_BMODE_PROB[$al * 9 + ($step[0] >> 1)]][$step[1]];
                }
                $this->bmodeCost[$al][$mode] = $bits;
            }
        }
    }

    /** @param array<int,array{0:int,1:int}> $path */
    private function symbolPathCost(array $path, array $probs): int
    {
        $bits = 0;
        foreach ($path as $step) {
            $bits += $this->bitCost[$probs[$step[0] >> 1]][$step[1]];
        }

        return $bits;
    }

    /**
     * Derive the per-plane quantiser steps from the base index and the header
     * deltas.  Called from the constructor and again at initFrame(), so the
     * deltas can be set after construction.
     */
    private function writeQuantDelta(BoolEncoder $header, int $delta): void
    {
        if ($delta === 0) {
            $header->writeLiteral(0, 1);

            return;
        }
        $header->writeLiteral(1, 1);
        $header->writeLiteral(abs($delta) & 0x0F, 4);   // magnitude
        $header->writeLiteral($delta < 0 ? 1 : 0, 1);   // sign
    }

    private function setupQuantizers(): void
    {
        $q = $this->qIndex;
        $dc = Vp8Tables::DC_QLOOKUP;
        $ac = Vp8Tables::AC_QLOOKUP;
        $clamp = static fn(int $i): int => max(0, min(127, $i));
        $this->qYdc = $dc[$clamp($q + $this->yDcDelta)];
        $this->qYac = $ac[$q];
        $this->qUvdc = $dc[$clamp($q + $this->uvDcDelta)];
        $this->qUvac = $ac[$clamp($q + $this->uvAcDelta)];
        $this->qY2dc = $dc[$q] * 2;
        $this->qY2ac = max(8, intdiv($ac[$q] * 155, 100));
    }

    /**
     * libwebp's quality -> base quantiser index curve, measured with
     * `cwebp -q Q -segments 1` + `webpinfo -bitstream_info` ("Base Q"), so that
     * "quality 80" here means the same quantiser libwebp picks for quality 80
     * when it is restricted to a single segment (this encoder has one global
     * quantiser, no segmentation).
     *
     * @var array<int,int>
     */
    public const QUALITY_TO_QINDEX = [
        0 => 127, 5 => 86, 10 => 75, 15 => 68, 20 => 62, 25 => 57, 30 => 52,
        35 => 48, 40 => 45, 45 => 41, 50 => 38, 55 => 36, 60 => 33, 65 => 30,
        70 => 28, 75 => 26, 80 => 19, 85 => 14, 90 => 9, 95 => 4, 98 => 1,
        100 => 0,
    ];

    /** Linear interpolation over QUALITY_TO_QINDEX. */
    public static function qIndexForQuality(int $quality): int
    {
        $quality = max(0, min(100, $quality));
        $points = self::QUALITY_TO_QINDEX;
        $prevQ = 0;
        foreach ($points as $q => $index) {
            if ($q === $quality) {
                return $index;
            }
            if ($q > $quality) {
                $span = $q - $prevQ;
                $t = ($quality - $prevQ) / $span;
                $value = $points[$prevQ] + $t * ($index - $points[$prevQ]);

                return (int) round($value);
            }
            $prevQ = $q;
        }

        return 0;
    }

    /** Build an encoder from a libwebp-style quality setting (0-100). */
    public static function fromQuality(int $quality): self
    {
        $encoder = new self(self::qIndexForQuality($quality));
        $encoder->quality = max(0, min(100, $quality));

        return $encoder;
    }

    public function qIndex(): int
    {
        return $this->qIndex;
    }

    public function quality(): ?int
    {
        return $this->quality;
    }

    /**
     * @return array<int, array{0:int,1:int}>
     */
    private function findPath(array $tree, int $node, int $target, array $acc): array
    {
        foreach ([0, 1] as $b) {
            $child = $tree[$node + $b];
            $acc2 = $acc;
            $acc2[] = [$node, $b];
            if ($child <= 0) {
                if (-$child === $target) {
                    return $acc2;
                }
            } else {
                $r = $this->findPath($tree, $child, $target, $acc2);
                if ($r !== []) {
                    return $r;
                }
            }
        }

        return [];
    }

    /** Convert an RGB byte string into padded Y/U/V planes. */
    public function loadRgb(string $rgb, int $w, int $h): void
    {
        $this->w = $w;
        $this->h = $h;
        $this->setupQuantizers();
        $this->wp = (int) (($w + 15) & ~15);
        $this->hp = (int) (($h + 15) & ~15);
        $this->cw = $this->wp >> 1;
        $this->ch = $this->hp >> 1;

        $y = str_repeat("\x00", $this->wp * $this->hp);
        $u = str_repeat("\x00", $this->cw * $this->ch);
        $v = str_repeat("\x00", $this->cw * $this->ch);

        for ($j = 0; $j < $h; $j++) {
            $srcBase = $j * $w * 3;
            $yBase = $j * $this->wp;
            for ($i = 0; $i < $w; $i++) {
                $o = $srcBase + $i * 3;
                $r = ord($rgb[$o]);
                $g = ord($rgb[$o + 1]);
                $b = ord($rgb[$o + 2]);
                // libwebp stores *limited-range* BT.601 (studio swing): Y in
                // [16,235], chroma in [16,240].  The decoder inverts exactly
                // that, so a full-range plane here decodes ~1.16x too bright --
                // worth ~7 dB end to end.
                $y[$yBase + $i] = chr((16839 * $r + 33059 * $g + 6420 * $b + (16 << 16) + 32768) >> 16);
            }
        }

        // Chroma pass over padded geometry: average the 2x2 luma co-sited block.
        // libwebp's ConvertARGBToUV_C averages the block at rows (2c, 2c+1);
        // this loop used to walk the padded geometry and let the second write
        // of each chroma row win, which shifted every chroma row half a row.
        for ($cj = 0; $cj < $this->ch; $cj++) {
            $jA = ($cj << 1) < $h ? ($cj << 1) : $h - 1;
            $jB = (($cj << 1) + 1) < $h ? (($cj << 1) + 1) : $h - 1;
            $cBase = $cj * $this->cw;
            for ($i = 0; $i < $this->cw; $i++) {
                $i0 = ($i << 1) < $w ? ($i << 1) : $w - 1;
                $i1 = (($i << 1) + 1) < $w ? (($i << 1) + 1) : $w - 1;
                $sumU = 0;
                $sumV = 0;
                foreach ([[$i0, $jA], [$i1, $jA], [$i0, $jB], [$i1, $jB]] as $pt) {
                    $o = $pt[1] * $w * 3 + $pt[0] * 3;
                    $r = ord($rgb[$o]);
                    $g = ord($rgb[$o + 1]);
                    $b = ord($rgb[$o + 2]);
                    $sumU += -9719 * $r - 19081 * $g + 28800 * $b;
                    $sumV += 28800 * $r - 24116 * $g - 4684 * $b;
                }
                $uu = ((($sumU + 2) >> 2) + (128 << 16) + 32768) >> 16;
                $vv = ((($sumV + 2) >> 2) + (128 << 16) + 32768) >> 16;
                $u[$cBase + $i] = chr($uu < 0 ? 0 : ($uu > 255 ? 255 : $uu));
                $v[$cBase + $i] = chr($vv < 0 ? 0 : ($vv > 255 ? 255 : $vv));
            }
        }

        // Pad luma by edge replication.
        for ($j = $h; $j < $this->hp; $j++) {
            $rowSrc = ($h - 1) * $this->wp;
            $rowDst = $j * $this->wp;
            for ($i = 0; $i < $this->wp; $i++) {
                $y[$rowDst + $i] = $y[$rowSrc + $i];
            }
        }
        for ($j = 0; $j < $this->hp; $j++) {
            $base = $j * $this->wp;
            $last = $y[$base + $w - 1];
            for ($i = $w; $i < $this->wp; $i++) {
                $y[$base + $i] = $last;
            }
        }
        $chromaVisible = ($w + 1) >> 1;
        for ($j = 0; $j < $this->ch; $j++) {
            $base = $j * $this->cw;
            $lastU = $u[$base + $chromaVisible - 1];
            $lastV = $v[$base + $chromaVisible - 1];
            for ($i = $chromaVisible; $i < $this->cw; $i++) {
                $u[$base + $i] = $lastU;
                $v[$base + $i] = $lastV;
            }
        }

        $this->y = $y;
        $this->u = $u;
        $this->v = $v;
        $this->ry = str_repeat("\x00", $this->wp * $this->hp);
        $this->ru = str_repeat("\x00", $this->cw * $this->ch);
        $this->rv = str_repeat("\x00", $this->cw * $this->ch);
    }

    private function clamp255(int $x): int
    {
        return $x < 0 ? 0 : ($x > 255 ? 255 : $x);
    }

    private function fdct4x4(array $in): array
    {
        $tmp = [];
        for ($i = 0; $i < 4; $i++) {
            $r = $i * 4;
            $a1 = ($in[$r] + $in[$r + 3]) * 8;
            $b1 = ($in[$r + 1] + $in[$r + 2]) * 8;
            $c1 = ($in[$r + 1] - $in[$r + 2]) * 8;
            $d1 = ($in[$r] - $in[$r + 3]) * 8;
            $tmp[$r] = $a1 + $b1;
            $tmp[$r + 2] = $a1 - $b1;
            $tmp[$r + 1] = (($c1 * 2217 + $d1 * 5352 + 14500) >> 12);
            $tmp[$r + 3] = (($d1 * 2217 - $c1 * 5352 + 7500) >> 12);
        }

        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $a1 = $tmp[$i] + $tmp[$i + 12];
            $b1 = $tmp[$i + 4] + $tmp[$i + 8];
            $c1 = $tmp[$i + 4] - $tmp[$i + 8];
            $d1 = $tmp[$i] - $tmp[$i + 12];
            $out[$i] = ($a1 + $b1 + 7) >> 4;
            $out[$i + 8] = ($a1 - $b1 + 7) >> 4;
            $out[$i + 4] = (($c1 * 2217 + $d1 * 5352 + 12000) >> 16) + ($d1 !== 0 ? 1 : 0);
            $out[$i + 12] = (($d1 * 2217 - $c1 * 5352 + 51000) >> 16);
        }

        return $out;
    }

    private function idct4x4(array $c): array
    {
        $tmp = [];
        for ($i = 0; $i < 4; $i++) {
            $ip0 = $c[$i];
            $ip4 = $c[$i + 4];
            $ip8 = $c[$i + 8];
            $ip12 = $c[$i + 12];

            $a1 = $ip0 + $ip8;
            $b1 = $ip0 - $ip8;
            $t1 = ($ip4 * self::SINPI8) >> 16;
            $t2 = $ip12 + (($ip12 * self::COSPI8) >> 16);
            $c1 = $t1 - $t2;
            $t1 = $ip4 + (($ip4 * self::COSPI8) >> 16);
            $t2 = ($ip12 * self::SINPI8) >> 16;
            $d1 = $t1 + $t2;

            $tmp[$i] = $a1 + $d1;
            $tmp[$i + 12] = $a1 - $d1;
            $tmp[$i + 4] = $b1 + $c1;
            $tmp[$i + 8] = $b1 - $c1;
        }

        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $r = $i * 4;
            $ip0 = $tmp[$r];
            $ip1 = $tmp[$r + 1];
            $ip2 = $tmp[$r + 2];
            $ip3 = $tmp[$r + 3];

            $a1 = $ip0 + $ip2;
            $b1 = $ip0 - $ip2;
            $t1 = ($ip1 * self::SINPI8) >> 16;
            $t2 = $ip3 + (($ip3 * self::COSPI8) >> 16);
            $c1 = $t1 - $t2;
            $t1 = $ip1 + (($ip1 * self::COSPI8) >> 16);
            $t2 = ($ip3 * self::SINPI8) >> 16;
            $d1 = $t1 + $t2;

            $out[$r] = ($a1 + $d1 + 4) >> 3;
            $out[$r + 3] = ($a1 - $d1 + 4) >> 3;
            $out[$r + 1] = ($b1 + $c1 + 4) >> 3;
            $out[$r + 2] = ($b1 - $c1 + 4) >> 3;
        }

        return $out;
    }

    /** Forward Walsh-Hadamard: C = H4 * D * H4 / 2 (the transpose of the spec IWHT). */
    private function fwht4x4(array $d): array
    {
        $tmp = [];
        for ($i = 0; $i < 4; $i++) {
            $x0 = $d[$i];
            $x1 = $d[$i + 4];
            $x2 = $d[$i + 8];
            $x3 = $d[$i + 12];
            $tmp[$i] = $x0 + $x1 + $x2 + $x3;
            $tmp[$i + 4] = $x0 + $x1 - $x2 - $x3;
            $tmp[$i + 8] = $x0 - $x1 - $x2 + $x3;
            $tmp[$i + 12] = $x0 - $x1 + $x2 - $x3;
        }

        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $r = $i * 4;
            $x0 = $tmp[$r];
            $x1 = $tmp[$r + 1];
            $x2 = $tmp[$r + 2];
            $x3 = $tmp[$r + 3];
            $v0 = $x0 + $x1 + $x2 + $x3;
            $v1 = $x0 + $x1 - $x2 - $x3;
            $v2 = $x0 - $x1 - $x2 + $x3;
            $v3 = $x0 - $x1 + $x2 - $x3;
            $out[$r] = intdiv($v0 + ($v0 >= 0 ? 1 : -1), 2);
            $out[$r + 1] = intdiv($v1 + ($v1 >= 0 ? 1 : -1), 2);
            $out[$r + 2] = intdiv($v2 + ($v2 >= 0 ? 1 : -1), 2);
            $out[$r + 3] = intdiv($v3 + ($v3 >= 0 ? 1 : -1), 2);
        }

        return $out;
    }

    private function iwht4x4(array $c): array
    {
        $tmp = [];
        for ($i = 0; $i < 4; $i++) {
            $x0 = $c[$i];
            $x1 = $c[$i + 4];
            $x2 = $c[$i + 8];
            $x3 = $c[$i + 12];
            $a1 = $x0 + $x3;
            $b1 = $x1 + $x2;
            $c1 = $x1 - $x2;
            $d1 = $x0 - $x3;
            $tmp[$i] = $a1 + $b1;
            $tmp[$i + 4] = $c1 + $d1;
            $tmp[$i + 8] = $a1 - $b1;
            $tmp[$i + 12] = $d1 - $c1;
        }

        $out = [];
        for ($i = 0; $i < 4; $i++) {
            $r = $i * 4;
            $a1 = $tmp[$r] + $tmp[$r + 3];
            $b1 = $tmp[$r + 1] + $tmp[$r + 2];
            $c1 = $tmp[$r + 1] - $tmp[$r + 2];
            $d1 = $tmp[$r] - $tmp[$r + 3];
            $a2 = $a1 + $b1;
            $b2 = $c1 + $d1;
            $c2 = $a1 - $b1;
            $d2 = $d1 - $c1;
            $out[$r] = ($a2 + 3) >> 3;
            $out[$r + 1] = ($b2 + 3) >> 3;
            $out[$r + 2] = ($c2 + 3) >> 3;
            $out[$r + 3] = ($d2 + 3) >> 3;
        }

        return $out;
    }

    private function quant(int $coeff, int $factor): int
    {
        // q = floor((|c| + f - theta*f) / f): theta = 0.5 is round-to-nearest
        // (matching the original behaviour), theta > 0.5 widens the zero bin.
        $abs = $coeff < 0 ? -$coeff : $coeff;
        $offset = (int) ($factor * $this->quantBias);
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset > $factor) {
            $offset = $factor;
        }
        $q = intdiv($abs + $factor - $offset, $factor);

        return $coeff < 0 ? -$q : $q;
    }

    /**
     * Plain DC prediction for one block (no neighbour scoring).
     *
     * @return array<int,int>
     */
    private function dcPrediction(string $plane, int $bx, int $by, bool $hasAbove, bool $hasLeft, int $size): array
    {
        $stride = $plane === 'y' ? $this->wp : $this->cw;
        $rec = $plane === 'y' ? $this->ry : ($plane === 'u' ? $this->ru : $this->rv);
        $base = $this->recBaseFor($plane);
        $sum = 0;
        if ($hasAbove) {
            $row = ($by - 1 - $base) * $stride;
            for ($i = 0; $i < $size; $i++) {
                $sum += ord($rec[$row + $bx + $i]);
            }
        }
        if ($hasLeft) {
            for ($i = 0; $i < $size; $i++) {
                $sum += ord($rec[($by + $i - $base) * $stride + $bx - 1]);
            }
        }
        if ($hasAbove && $hasLeft) {
            $dc = ($sum + $size) >> ($size === 16 ? 5 : 4);
        } elseif ($hasAbove || $hasLeft) {
            $dc = ($sum + ($size >> 1)) >> ($size === 16 ? 4 : 3);
        } else {
            $dc = 128;
        }

        return array_fill(0, $size * $size, $dc);
    }

    /**
     * Gather the prediction neighbourhood for one block and score the four
     * 16x16/8x8 intra modes (DC, V, H, TM) with SAD against the source.
     *
     * @return array{0:array<int,int>,1:array<int,int>,2:array<int,int>,3:int,4:int} [sadPerMode, above, left, dcValue, cornerValue]
     */
    private function scoreModes(string $plane, string $src, int $bx, int $by, bool $hasAbove, bool $hasLeft, int $size): array
    {
        $stride = $plane === 'y' ? $this->wp : $this->cw;
        $rec = $plane === 'y' ? $this->ry : ($plane === 'u' ? $this->ru : $this->rv);
        $base = $this->recBaseFor($plane);
        $above = [];
        $left = [];
        for ($i = 0; $i < $size; $i++) {
            $above[$i] = $hasAbove ? ord($rec[($by - 1 - $base) * $stride + $bx + $i]) : 127;
            $left[$i] = $hasLeft ? ord($rec[($by + $i - $base) * $stride + $bx - 1]) : 129;
        }
        $corner = $hasAbove ? ($hasLeft ? ord($rec[($by - 1 - $base) * $stride + $bx - 1]) : 129) : 127;

        $sumAbove = 0;
        $sumLeft = 0;
        for ($i = 0; $i < $size; $i++) {
            $sumAbove += $above[$i];
            $sumLeft += $left[$i];
        }
        $dcShift = $size === 16 ? 5 : 4;
        $oneShift = $size === 16 ? 4 : 3;
        if ($hasAbove && $hasLeft) {
            $dc = ($sumAbove + $sumLeft + $size) >> $dcShift;
        } elseif ($hasAbove) {
            $dc = ($sumAbove + ($size >> 1)) >> $oneShift;
        } elseif ($hasLeft) {
            $dc = ($sumLeft + ($size >> 1)) >> $oneShift;
        } else {
            $dc = 128;
        }

        $sad = [0, 0, 0, 0];
        $srcBase = $this->srcBaseFor($plane);
        for ($r = 0; $r < $size; $r++) {
            $rowBase = ($by + $r - $srcBase) * $stride + $bx;
            $lv = $left[$r];
            for ($c = 0; $c < $size; $c++) {
                $v = ord($src[$rowBase + $c]);
                $d = $v - $dc;
                $sad[0] += $d < 0 ? -$d : $d;
                $d = $v - $above[$c];
                $sad[1] += $d < 0 ? -$d : $d;
                $d = $v - $lv;
                $sad[2] += $d < 0 ? -$d : $d;
                $t = $lv + $above[$c] - $corner;
                $t = $t < 0 ? 0 : ($t > 255 ? 255 : $t);
                $d = $v - $t;
                $sad[3] += $d < 0 ? -$d : $d;
            }
        }

        return [$sad, $above, $left, $dc, $corner];
    }

    /**
     * @param array<int,int> $above
     * @param array<int,int> $left
     * @return array<int,int>
     */
    private function buildPrediction(int $size, int $mode, array $above, array $left, int $dc, int $corner): array
    {
        $out = [];
        switch ($mode) {
            case 0:
                return array_fill(0, $size * $size, $dc);
            case 1:
                for ($r = 0; $r < $size; $r++) {
                    for ($c = 0; $c < $size; $c++) {
                        $out[$r * $size + $c] = $above[$c];
                    }
                }

                return $out;
            case 2:
                for ($r = 0; $r < $size; $r++) {
                    $lv = $left[$r];
                    for ($c = 0; $c < $size; $c++) {
                        $out[$r * $size + $c] = $lv;
                    }
                }

                return $out;
            default:
                for ($r = 0; $r < $size; $r++) {
                    $lv = $left[$r];
                    for ($c = 0; $c < $size; $c++) {
                        $t = $lv + $above[$c] - $corner;
                        $out[$r * $size + $c] = $t < 0 ? 0 : ($t > 255 ? 255 : $t);
                    }
                }

                return $out;
        }
    }

    private static function avg3(int $x, int $y, int $z): int
    {
        return ($x + $y + $y + $z + 2) >> 2;
    }

    private static function avg2(int $x, int $y): int
    {
        return ($x + $y + 1) >> 1;
    }

    /**
     * Build the sixteen prediction samples for one 4x4 subblock mode.
     *
     * RFC 6386 12.3.  $Ax has nine entries with $Ax[0] = A[-1] (the pixel
     * above-left) and $Ax[1..8] = A[0..7]; $Lx has five with $Lx[0] = L[-1]
     * (== A[-1]) and $Lx[1..4] = L[0..3]; $E is the nine-entry edge vector
     * E[0..8] = L[3], L[2], L[1], L[0], A[-1], A[0], A[1], A[2], A[3].
     *
     * @param array<int,int> $Ax
     * @param array<int,int> $Lx
     * @param array<int,int> $E
     * @return array<int,int> row-major 4x4 prediction
     */
    private function bpredBuild(int $mode, array $Ax, array $Lx, array $E): array
    {
        $B = array_fill(0, 16, 0);

        switch ($mode) {
            case 0: // B_DC_PRED
                $v = 4;
                for ($i = 1; $i <= 4; $i++) {
                    $v += $Ax[$i] + $Lx[$i];
                }
                $v >>= 3;

                return array_fill(0, 16, $v);

            case 1: // B_TM_PRED
                for ($r = 0; $r < 4; $r++) {
                    $lv = $Lx[$r + 1] - $Ax[0];
                    for ($c = 0; $c < 4; $c++) {
                        $B[($r << 2) + $c] = $this->clamp255($lv + $Ax[$c + 1]);
                    }
                }

                return $B;

            case 2: // B_VE_PRED
                for ($c = 0; $c < 4; $c++) {
                    $v = self::avg3($Ax[$c], $Ax[$c + 1], $Ax[$c + 2]);
                    $B[$c] = $v;
                    $B[4 + $c] = $v;
                    $B[8 + $c] = $v;
                    $B[12 + $c] = $v;
                }

                return $B;

            case 3: // B_HE_PRED
                for ($r = 0; $r < 4; $r++) {
                    $v = $r === 3
                        ? self::avg3($Lx[3], $Lx[4], $Lx[4])
                        : self::avg3($Lx[$r], $Lx[$r + 1], $Lx[$r + 2]);
                    $o = $r << 2;
                    $B[$o] = $v;
                    $B[$o + 1] = $v;
                    $B[$o + 2] = $v;
                    $B[$o + 3] = $v;
                }

                return $B;

            case 4: // B_LD_PRED
                $B[0] = self::avg3($Ax[1], $Ax[2], $Ax[3]);
                $B[1] = $B[4] = self::avg3($Ax[2], $Ax[3], $Ax[4]);
                $B[2] = $B[5] = $B[8] = self::avg3($Ax[3], $Ax[4], $Ax[5]);
                $B[3] = $B[6] = $B[9] = $B[12] = self::avg3($Ax[4], $Ax[5], $Ax[6]);
                $B[7] = $B[10] = $B[13] = self::avg3($Ax[5], $Ax[6], $Ax[7]);
                $B[11] = $B[14] = self::avg3($Ax[6], $Ax[7], $Ax[8]);
                $B[15] = self::avg3($Ax[7], $Ax[8], $Ax[8]);

                return $B;

            case 5: // B_RD_PRED
                $B[12] = self::avg3($E[0], $E[1], $E[2]);
                $B[13] = $B[8] = self::avg3($E[1], $E[2], $E[3]);
                $B[14] = $B[9] = $B[4] = self::avg3($E[2], $E[3], $E[4]);
                $B[15] = $B[10] = $B[5] = $B[0] = self::avg3($E[3], $E[4], $E[5]);
                $B[11] = $B[6] = $B[1] = self::avg3($E[4], $E[5], $E[6]);
                $B[7] = $B[2] = self::avg3($E[5], $E[6], $E[7]);
                $B[3] = self::avg3($E[6], $E[7], $E[8]);

                return $B;

            case 6: // B_VR_PRED
                $B[12] = self::avg3($E[1], $E[2], $E[3]);
                $B[8] = self::avg3($E[2], $E[3], $E[4]);
                $B[13] = $B[4] = self::avg3($E[3], $E[4], $E[5]);
                $B[9] = $B[0] = self::avg2($E[4], $E[5]);
                $B[14] = $B[5] = self::avg3($E[4], $E[5], $E[6]);
                $B[10] = $B[1] = self::avg2($E[5], $E[6]);
                $B[15] = $B[6] = self::avg3($E[5], $E[6], $E[7]);
                $B[11] = $B[2] = self::avg2($E[6], $E[7]);
                $B[7] = self::avg3($E[6], $E[7], $E[8]);
                $B[3] = self::avg2($E[7], $E[8]);

                return $B;

            case 7: // B_VL_PRED
                $B[0] = self::avg2($Ax[1], $Ax[2]);
                $B[4] = self::avg3($Ax[1], $Ax[2], $Ax[3]);
                $B[8] = $B[1] = self::avg2($Ax[2], $Ax[3]);
                $B[5] = $B[12] = self::avg3($Ax[2], $Ax[3], $Ax[4]);
                $B[9] = $B[2] = self::avg2($Ax[3], $Ax[4]);
                $B[13] = $B[6] = self::avg3($Ax[3], $Ax[4], $Ax[5]);
                $B[10] = $B[3] = self::avg2($Ax[4], $Ax[5]);
                $B[14] = $B[7] = self::avg3($Ax[4], $Ax[5], $Ax[6]);
                $B[11] = self::avg3($Ax[5], $Ax[6], $Ax[7]);
                $B[15] = self::avg3($Ax[6], $Ax[7], $Ax[8]);

                return $B;

            case 8: // B_HD_PRED
                $B[12] = self::avg2($E[0], $E[1]);
                $B[13] = self::avg3($E[0], $E[1], $E[2]);
                $B[8] = $B[14] = self::avg2($E[1], $E[2]);
                $B[9] = $B[15] = self::avg3($E[1], $E[2], $E[3]);
                $B[10] = $B[4] = self::avg2($E[2], $E[3]);
                $B[11] = $B[5] = self::avg3($E[2], $E[3], $E[4]);
                $B[6] = $B[0] = self::avg2($E[3], $E[4]);
                $B[7] = $B[1] = self::avg3($E[3], $E[4], $E[5]);
                $B[2] = self::avg3($E[4], $E[5], $E[6]);
                $B[3] = self::avg3($E[5], $E[6], $E[7]);

                return $B;

            default: // B_HU_PRED (9)
                $B[0] = self::avg2($Lx[1], $Lx[2]);
                $B[1] = self::avg3($Lx[1], $Lx[2], $Lx[3]);
                $B[2] = $B[4] = self::avg2($Lx[2], $Lx[3]);
                $B[3] = $B[5] = self::avg3($Lx[2], $Lx[3], $Lx[4]);
                $B[6] = $B[8] = self::avg2($Lx[3], $Lx[4]);
                $B[7] = $B[9] = self::avg3($Lx[3], $Lx[4], $Lx[4]);
                $B[10] = $B[11] = $B[12] = $B[13] = $B[14] = $B[15] = $Lx[4];

                return $B;
        }
    }

    /**
     * Encode one macroblock's luma with the ten 4x4 subblock modes.
     *
     * Subblocks are predicted, transformed, quantised and RECONSTRUCTED in
     * raster order so each one predicts from reconstructed neighbours,
     * including the ones inside this macroblock.  A 4x4 macroblock omits Y2
     * (RFC 6386 13.2), so coefficient 0 is coded inside every subblock, using
     * the luma DC quantiser, and the coefficient probabilities come from
     * plane 3 ("Y beginning at coefficient 0").
     *
     * @return array{0:array<int,array<int,int>>,1:array<int,int>,2:int,3:int,4:int}
     *         [blocks, modes, SAD total, priced bits (1/256 bit), SSD total]
     */
    private function encodeMbIntra4x4(int $px, int $py, int $mbx, bool $hasAbove, bool $hasLeft, array &$Lcost, array &$Acost): array
    {
        $wp = $this->wp;
        $recBase = $this->recBase;
        $srcBase = $this->srcBase;
        $ry = &$this->ry;

        // Above-right samples for the right-edge subblock column: the
        // macroblock above-right's first four pixels, the rightmost above
        // pixel replicated at the frame edge, or 127 on the top row.
        if (!$hasAbove) {
            $extra = [127, 127, 127, 127];
        } elseif ($mbx >= $this->mbw - 1) {
            $v = ord($ry[($py - 1 - $recBase) * $wp + $px + 15]);
            $extra = [$v, $v, $v, $v];
        } else {
            $o = ($py - 1 - $recBase) * $wp + $px + 16;
            $extra = [ord($ry[$o]), ord($ry[$o + 1]), ord($ry[$o + 2]), ord($ry[$o + 3])];
        }

        $modes = array_fill(0, 16, 0);
        $blocks = array_fill(0, 16, []);
        $sadTotal = 0;
        $rateBits = 0;
        $ssdTotal = 0;

        for ($b = 0; $b < 16; $b++) {
            $br = $b >> 2;
            $bc = $b & 3;
            $bx = $px + ($bc << 2);
            $by = $py + ($br << 2);
            $aboveRow = ($by - 1 - $recBase) * $wp;

            // ---- the sample row above this subblock: A[-1] then A[0..7] ----
            // On the top macroblock row of the frame there is no row above:
            // RFC 6386 12.3 gives every one of those samples the value 127,
            // including the above-right ones.
            $P = 127;
            $Ax = [127, 127, 127, 127, 127, 127, 127, 127, 127];
            if (!($br === 0 && !$hasAbove)) {
                // bc > 0 reads inside this macroblock, so it is always there;
                // bc == 0 steps into the macroblock to the left.
                $P = ($bc > 0 || $hasLeft) ? ord($ry[$aboveRow + $bx - 1]) : 129;
                $Ax[0] = $P;
                for ($i = 1; $i <= 4; $i++) {
                    $Ax[$i] = ord($ry[$aboveRow + $bx + $i - 1]);
                }
                if ($bc < 3) {
                    for ($i = 5; $i <= 8; $i++) {
                        $Ax[$i] = ord($ry[$aboveRow + $bx + $i - 1]);
                    }
                } else {
                    $Ax[5] = $extra[0];
                    $Ax[6] = $extra[1];
                    $Ax[7] = $extra[2];
                    $Ax[8] = $extra[3];
                }
            }

            // ---- L[0..3] ----
            $Lx = [$P, 0, 0, 0, 0];
            if ($bc > 0 || $hasLeft) {
                for ($i = 1; $i <= 4; $i++) {
                    $Lx[$i] = ord($ry[($by + $i - 1 - $recBase) * $wp + $bx - 1]);
                }
            } else {
                for ($i = 1; $i <= 4; $i++) {
                    $Lx[$i] = 129;
                }
            }

            $E = [$Lx[4], $Lx[3], $Lx[2], $Lx[1], $P, $Ax[1], $Ax[2], $Ax[3], $Ax[4]];

            // ---- source samples ----
            $srcRow = ($by - $srcBase) * $wp + $bx;
            $s = [];
            for ($r = 0; $r < 4; $r++) {
                $o = $srcRow + $r * $wp;
                $rr = $r << 2;
                $s[$rr] = ord($this->y[$o]);
                $s[$rr + 1] = ord($this->y[$o + 1]);
                $s[$rr + 2] = ord($this->y[$o + 2]);
                $s[$rr + 3] = ord($this->y[$o + 3]);
            }

            $candidates = $this->forceBMode === null ? [0, 1, 2, 3, 4, 5, 6, 7, 8, 9] : [$this->forceBMode];
            if ($this->forceBMode === null && $this->rdPruneCandidates > 0
                && count($candidates) > $this->rdPruneCandidates) {
                $candidates = $this->pruneBySad($candidates, $s, $Ax, $Lx, $E);
            }

            if ($this->enableRd) {
                // ---- mode search: rate-distortion, i.e. J = SSD + lambda*R ----
                // Every one of the ten predictors is really transformed,
                // quantised and reconstructed, then priced with the same
                // probabilities the range coder will use.  SAD alone cannot
                // see that a mode which predicts better may cost more bits.
                $lambda = $this->lambdaFor() * $this->rdLambdaI4;
                $bestJ = null;
                $bestMode = 0;
                $bestPred = [];
                $bestQ = [];
                $bestRec = [];
                $bestBits = 0;
                $bestSad = 0;
                $bestSsd = 0;
                foreach ($candidates as $m) {
                    [$j, $blkBits, $B, $q, $rec, $sad, $ssdBlk] = $this->evalSubblock(
                        $b, $s, $Ax, $Lx, $E, $Lcost, $Acost, $lambda, $m, $mbx, $modes
                    );
                    if ($bestJ === null || $j < $bestJ) {
                        $bestJ = $j;
                        $bestMode = $m;
                        $bestPred = $B;
                        $bestQ = $q;
                        $bestRec = $rec;
                        $bestBits = $blkBits;
                        $bestSad = $sad;
                        $bestSsd = $ssdBlk;
                    }
                }
                $modes[$b] = $bestMode;
                $sadTotal += $bestSad;
                $rateBits += $bestBits;
                $ssdTotal += $bestSsd;
                $blocks[$b] = $bestQ;
                // Advance the priced left/above contexts to match the winner.
                $this->blockRate($Lcost, $Acost, $bestQ, 3, 0, $b >> 2, $b & 3);

                if ($this->dumpFirstBlock && $px === 0 && $py === 0 && $b === 0) {
                    printf(
                        "[block 0 rd] mode=%d J=%.1f bits=%.2f q=[%s] recon=[%s]\n",
                        $bestMode,
                        $bestJ,
                        $bestBits / 256.0,
                        implode(',', $bestQ),
                        implode(',', $bestRec)
                    );
                }
                for ($r = 0; $r < 4; $r++) {
                    $dstRow = ($by + $r - $recBase) * $wp + $bx;
                    $rr = $r << 2;
                    $ry[$dstRow] = chr($bestRec[$rr]);
                    $ry[$dstRow + 1] = chr($bestRec[$rr + 1]);
                    $ry[$dstRow + 2] = chr($bestRec[$rr + 2]);
                    $ry[$dstRow + 3] = chr($bestRec[$rr + 3]);
                }

                continue;
            }

            // ---- mode search: SAD against all ten predictors ----
            $bestMode = 0;
            $bestPred = [];
            $bestSad = PHP_INT_MAX;
            foreach ($candidates as $m) {
                $B = $this->bpredBuild($m, $Ax, $Lx, $E);
                $sad = 0;
                for ($k = 0; $k < 16; $k++) {
                    $d = $s[$k] - $B[$k];
                    $sad += $d < 0 ? -$d : $d;
                }
                if ($sad < $bestSad) {
                    $bestSad = $sad;
                    $bestMode = $m;
                    $bestPred = $B;
                }
            }
            $modes[$b] = $bestMode;
            $sadTotal += $bestSad;

            // ---- residual, DCT, quantise (DC uses the luma DC quantiser) ----
            $res = [];
            for ($k = 0; $k < 16; $k++) {
                $res[$k] = $s[$k] - $bestPred[$k];
            }
            $co = $this->fdct4x4($res);
            $q = [];
            $dq = [];
            for ($i = 0; $i < 16; $i++) {
                $f = $i === 0 ? $this->qYdc : $this->qYac;
                $qq = $this->quant($co[$i], $f);
                $q[$i] = $qq;
                $dq[$i] = $qq * $f;
            }
            $blocks[$b] = $q;

            // ---- reconstruct now: the next subblock predicts from this ----
            $idct = $this->idct4x4($dq);
            if ($this->dumpFirstBlock && $px === 0 && $py === 0 && $b === 0) {
                printf(
                    "[block 0] mode=%d P=%d A=[%s] L=[%s] E=[%s]\n  pred=[%s]\n  src=[%s]\n  res=[%s]\n  co=[%s]\n  q=[%s]\n  dq=[%s]\n  idct=[%s]\n  recon=[%s]\n",
                    $bestMode,
                    $P,
                    implode(',', array_slice($Ax, 1)),
                    implode(',', array_slice($Lx, 1)),
                    implode(',', $E),
                    implode(',', $bestPred),
                    implode(',', $s),
                    implode(',', $res),
                    implode(',', $co),
                    implode(',', $q),
                    implode(',', $dq),
                    implode(',', $idct),
                    implode(',', array_map(fn($i) => $this->clamp255($bestPred[$i] + $idct[$i]), range(0, 15)))
                );
            }
            for ($r = 0; $r < 4; $r++) {
                $dstRow = ($by + $r - $recBase) * $wp + $bx;
                $rr = $r << 2;
                $ry[$dstRow] = chr($this->clamp255($bestPred[$rr] + $idct[$rr]));
                $ry[$dstRow + 1] = chr($this->clamp255($bestPred[$rr + 1] + $idct[$rr + 1]));
                $ry[$dstRow + 2] = chr($this->clamp255($bestPred[$rr + 2] + $idct[$rr + 2]));
                $ry[$dstRow + 3] = chr($this->clamp255($bestPred[$rr + 3] + $idct[$rr + 3]));
            }
        }

        return [$blocks, $modes, $sadTotal, $rateBits, $ssdTotal];
    }

    /**
     * Write the sixteen subblock modes of a 4x4 macroblock, using the
     * (above, left) mode pair as the probability context (RFC 6386 11.4).
     *
     * @param array<int,int> $modes
     */
    private function writeBPredModes(BoolEncoder $header, int $mbx, array $modes): void
    {
        for ($b = 0; $b < 16; $b++) {
            $br = $b >> 2;
            $bc = $b & 3;
            $A = $br === 0 ? $this->bAbove[($mbx << 2) + $bc] : $modes[(($br - 1) << 2) + $bc];
            $L = $bc === 0 ? $this->bLeft[$br] : $modes[($br << 2) + $bc - 1];
            $base = ($A * 10 + $L) * 9;
            if ($this->bmodeCtxLog !== null) {
                $this->bmodeCtxLog[] = [$mbx, $b, $A, $L, $modes[$b]];
            }
            foreach ($this->bmodePaths[$modes[$b]] as $step) {
                $header->writeBool(Vp8Tables::KF_BMODE_PROB[$base + ($step[0] >> 1)], $step[1]);
            }
        }
    }

    /**
     * Update the subblock-mode context after a macroblock.
     *
     * @param array<int,int> $modes
     */
    private function updateBModeContext(int $mbx, array $modes): void
    {
        for ($j = 0; $j < 4; $j++) {
            $this->bAbove[($mbx << 2) + $j] = $modes[12 + $j];
            $this->bLeft[$j] = $modes[($j << 2) + 3];
        }
    }

    /**
     * True when any of the macroblock's quantised coefficients is non-zero,
     * i.e. the macroblock cannot be coded with the skip flag.  For 16x16
     * macroblocks the luma DCs live in $y2q, so both are checked.
     *
     * @param array<int,array<int,int>> $lumaQ
     * @param array<int,int>|null $y2q
     * @param array<string,array<int,array<int,int>>> $chromaQ
     */
    private function blocksHaveCoeffs(array $lumaQ, ?array $y2q, array $chromaQ): bool
    {
        foreach ($lumaQ as $blk) {
            foreach ($blk as $v) {
                if ($v !== 0) {
                    return true;
                }
            }
        }
        if ($y2q !== null) {
            foreach ($y2q as $v) {
                if ($v !== 0) {
                    return true;
                }
            }
        }
        foreach ($chromaQ as $plane) {
            foreach ($plane as $blk) {
                foreach ($blk as $v) {
                    if ($v !== 0) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Encode one coefficient block, updating left/above contexts.
     *
     * @param array<int,int> $coeffs natural-order quantised coefficients
     */
    private function writeBlock(
        array $coeffs,
        int $plane,
        int $firstCoeff,
        int $leftIdx,
        int $aboveIdx,
        BoolEncoder $out
    ): void {
        $scan = Vp8Tables::ZIGZAG;
        $bands = Vp8Tables::COEFF_BANDS;
        $probs = $this->coeffProbs ?? Vp8Tables::DEFAULT_COEFF_PROBS;
        $stats = $this->collectStats;
        // In the counting pass the symbols themselves are irrelevant -- only
        // which bit went through which node matters -- so skip the range coder.
        $dry = $stats;

        $last = -1;
        for ($c = 15; $c >= $firstCoeff; $c--) {
            if ($coeffs[$scan[$c]] !== 0) {
                $last = $c;
                break;
            }
        }

        $ctx = $this->left[$leftIdx] + $this->above[$aboveIdx];
        $planeBase = $plane * 264; // 8 bands * 3 ctx * 11 nodes

        if ($this->measureModel) {
            // Price this block with the RD model, using the contexts as they
            // stand before it is coded, and accumulate the prediction.
            $Lc = $this->left;
            $Ac = $this->above;
            $this->modelBits += $this->blockRate($Lc, $Ac, $coeffs, $plane, $firstCoeff, $leftIdx, $aboveIdx);
        }

        if ($this->traceEnabled) {
            $this->ctxTrace[] = sprintf(
                'mb%-3d plane=%d first=%d L%d=%d A%d=%d ctx=%d',
                count($this->trace) - 1,
                $plane,
                $firstCoeff,
                $leftIdx,
                $this->left[$leftIdx],
                $aboveIdx,
                $this->above[$aboveIdx],
                $ctx
            );
            $this->blockLog[] = [
                'symbolAt' => $out->log !== null ? count($out->log) : -1,
                'plane' => $plane,
                'first' => $firstCoeff,
                'left' => $leftIdx,
                'above' => $aboveIdx,
                'ctx' => $ctx,
                'last' => $last,
                'coeffs' => array_values($coeffs),
            ];
        }

        // After a DCT_0 token the decoder skips the EOB branch entirely
        // (RFC 6386, 13.2: "if the preceding coefficient is a DCT_0, decoding
        // will skip the first branch").  So the EOB bit may only be written
        // when the previous token was *not* a zero, and a trailing zero run
        // must be padded out with DCT_0 tokens instead of an EOB.
        $afterZero = false;

        for ($c = $firstCoeff; $c <= 15; $c++) {
            $base = $planeBase + ($bands[$c] * 33) + ($ctx * 11);

            if (!$afterZero && $c > $last) {
                if ($stats) {
                    $this->probN0[$base]++;
                } else {
                    $out->writeBool($probs[$base], 0); // EOB
                }
                break;
            }

            $v = $coeffs[$scan[$c]];
            $isZero = $v === 0;

            if ($isZero) {
                $path = $this->coeffPaths[0]; // DCT_0: node0 bit 1, node2 bit 0
                $ctx = 0;
            } else {
                $abs = $v < 0 ? -$v : $v;
                if ($abs > 2048) {
                    $v = $v < 0 ? -2048 : 2048;
                    $abs = 2048;
                }
                // token ids are DCT_0..DCT_4 = 0..4, i.e. token == |value| for
                // small values; cat1..cat6 follow at 5..10.
                $token = $abs <= 4 ? $abs : 5;
                if ($abs > 4) {
                    foreach (Vp8Tables::TOKEN_CAT_BASE as $ci => $catBase) {
                        $hi = $ci === 5 ? 2048 : Vp8Tables::TOKEN_CAT_BASE[$ci + 1] - 1;
                        if ($abs >= $catBase && $abs <= $hi) {
                            $token = 5 + $ci;
                            break;
                        }
                    }
                }
                $path = $this->coeffPaths[$token];
            }

            // Right after a zero the decoder re-enters at tree node 2, so the
            // root (EOB) step must be dropped.
            $steps = $afterZero ? array_slice($path, 1) : $path;
            foreach ($steps as $step) {
                $pi = $base + ($step[0] >> 1);
                if ($stats) {
                    if ($step[1] === 1) {
                        $this->probN1[$pi]++;
                    } else {
                        $this->probN0[$pi]++;
                    }
                } else {
                    $out->writeBool($probs[$pi], $step[1]);
                }
            }

            if (!$isZero) {
                $ctx = $abs === 1 ? 1 : 2;
                // category extra bits and the sign are fixed-probability
                // symbols, outside the 1056 slots, so the counting pass can
                // skip emitting them
                if (!$dry) {
                    if ($token >= 5) {
                        $ci = $token - 5;
                        $extra = $abs - Vp8Tables::TOKEN_CAT_BASE[$ci];
                        $nbits = $ci + 1;
                        if ($ci === 5) {
                            $nbits = 11;
                        }
                        $pcat = Vp8Tables::PCAT[$ci];
                        for ($b = $nbits - 1; $b >= 0; $b--) {
                            $out->writeBool($pcat[$nbits - 1 - $b], ($extra >> $b) & 1);
                        }
                    }
                    $out->writeBool(128, $v < 0 ? 1 : 0);
                }
            }

            $afterZero = $isZero;
        }

        $has = $last >= 0 ? 1 : 0;
        $this->left[$leftIdx] = $has;
        $this->above[$aboveIdx] = $has;
    }

    /**
     * @return array{webp:string,y:string,u:string,v:string,w:int,h:int,mb:int,seconds:float,bytes:int}
     */
    /**
     * Whole-frame convenience path: header + every macroblock row + trailer.
     *
     * @return array<string,mixed>
     */
    public function encode(string $rgb, int $w, int $h): array
    {
        $t0 = microtime(true);
        $this->initFrame($w, $h);
        $this->loadRgb($rgb, $w, $h);

        if ($this->adaptProbs) {
            $srcY = $this->y;
            $srcU = $this->u;
            $srcV = $this->v;
            $this->probUpdates = 0;
            // A statistics pass: the identical encode, with the symbols thrown
            // away and the per-context 0/1 counts kept.  One pass is enough --
            // the symbol stream is a function of the coefficients and contexts,
            // not of the probabilities, so a second pass counts the same thing.
            $this->initFrame($w, $h);
            // initFrame() blanks the reconstruction planes; put them back at
            // full size so that (a) both passes agree and (b) a rejected
            // candidate can be rolled back out of the plane.
            $this->ry = str_repeat("\x00", $this->wp * $this->hp);
            $this->ru = str_repeat("\x00", $this->cw * $this->ch);
            $this->rv = str_repeat("\x00", $this->cw * $this->ch);
            $this->y = $srcY;
            $this->u = $srcU;
            $this->v = $srcV;
            $this->probN0 = array_fill(0, 1056, 0);
            $this->probN1 = array_fill(0, 1056, 0);
            $this->collectStats = true;
            $this->encodeRowRange(0, $this->mbh);
            $this->collectStats = false;
            $this->coeffProbs = $this->adaptProbabilities();
            $this->deriveSkipProbability();
            $this->statsSeconds = microtime(true) - $t0;

            // Final pass: the same frame again, coded with the frame's own
            // odds.  Prediction and quantisation are untouched, so the
            // reconstruction is bit-identical to the unadapted encode.
            $this->initFrame($w, $h);
            $this->y = $srcY;
            $this->u = $srcU;
            $this->v = $srcV;
            $this->ry = str_repeat("\x00", $this->wp * $this->hp);
            $this->ru = str_repeat("\x00", $this->cw * $this->ch);
            $this->rv = str_repeat("\x00", $this->cw * $this->ch);
        }

        $this->encodeRowRange(0, $this->mbh);
        $out = $this->finishFrame();
        $out['seconds'] = microtime(true) - $t0;
        $out['prob_updates'] = $this->probUpdates;
        $out['seconds_stats'] = round($this->statsSeconds, 3);

        return $out;
    }

    /** Number of coefficient probability slots the header updates. */
    public function probUpdates(): int
    {
        return $this->probUpdates;
    }

    // ------------------------------------------------ chunked stats-phase API

    /**
     * Start (or continue, via importState/exportState) a statistics pass: the
     * encoder runs normally but only counts symbols.  The chunked queue runs a
     * whole frame this way before it can write the first byte of the header,
     * because the header is where the adapted probabilities live.
     */
    public function enableStats(): void
    {
        if (count($this->probN0) !== 1056) {
            $this->probN0 = array_fill(0, 1056, 0);
            $this->probN1 = array_fill(0, 1056, 0);
        }
        $this->collectStats = true;
    }

    /** @param array<int,int> $n0 @param array<int,int> $n1 */
    public function statsRestore(array $n0, array $n1): void
    {
        $this->probN0 = $n0;
        $this->probN1 = $n1;
    }

    /** Derive the probability table from the counts of a finished stats pass. */
    public function adaptFromStats(): int
    {
        $this->collectStats = false;
        $this->coeffProbs = $this->adaptProbabilities();

        return $this->probUpdates;
    }

    /**
     * prob_skip_false from the frame's own statistics: the share of
     * macroblocks that carry at least one coefficient.
     *
     * The counting pass visits every macroblock anyway, and the skip decision
     * depends only on the quantised coefficients -- never on this probability
     * -- so the counting pass and the final pass skip exactly the same
     * macroblocks and the reconstruction is unaffected.
     */
    public function deriveSkipProbability(): ?int
    {
        if (!$this->enableSkipFlags || !$this->deriveSkipProb) {
            return null;
        }
        $mb = $this->mbw * $this->mbh;
        if ($mb <= 0) {
            return null;
        }
        $withCoeffs = $mb - $this->mbAllZero;
        $this->skipProb = max(1, min(255, (int) round(256 * $withCoeffs / $mb)));

        return $this->skipProb;
    }

    /** @param array<int,int> $probs full 1056-entry table */
    public function setCoeffProbs(array $probs): void
    {
        $this->coeffProbs = $probs;
        $this->probUpdates = 0;
        $defaults = Vp8Tables::DEFAULT_COEFF_PROBS;
        for ($i = 0; $i < 1056; $i++) {
            if (($probs[$i] ?? $defaults[$i]) !== $defaults[$i]) {
                $this->probUpdates++;
            }
        }
    }

    /** @return array<int,int>|null */
    public function coeffProbs(): ?array
    {
        return $this->coeffProbs;
    }

    /**
     * The (node, bit) path of one coefficient token through the token tree.
     * Exposed for the rate-model test in check-rate.php.
     *
     * @return array<int,array{0:int,1:int}>
     */
    public function debugTokenPath(int $token): array
    {
        return $this->coeffPaths[$token] ?? [];
    }

    /**
     * Turn this frame's symbol counts into a set of probability updates.
     *
     * VP8 lets a key frame carry new odds for any of the 1056 coefficient
     * probability slots; each costs a flag plus eight literal bits.  A slot is
     * only worth updating when the frame coded enough symbols through it for
     * the new odds to pay for the signalling.
     *
     * @return array<int,int> the full 1056-entry table (defaults where unchanged)
     */
    private function adaptProbabilities(): array
    {
        $def = Vp8Tables::DEFAULT_COEFF_PROBS;
        $upd = Vp8Tables::COEFF_UPDATE_PROBS;
        $out = $def;
        $updates = 0;
        for ($i = 0; $i < 1056; $i++) {
            $n0 = $this->probN0[$i];
            $n1 = $this->probN1[$i];
            $total = $n0 + $n1;
            if ($total < 16) {
                continue;
            }
            // p is the probability that the bit is 0, in 1..255 (the range
            // coder splits at p/256).
            $p = (int) round(256 * $n0 / $total);
            if ($p < 8) {
                $p = 8;
            } elseif ($p > 247) {
                $p = 247;
            }
            $old = $def[$i];
            if ($p === $old) {
                continue;
            }
            $save = self::symbolBits($n0, $n1, $old) - self::symbolBits($n0, $n1, $p);
            // what the update flag itself costs, versus leaving it clear
            $flag = (-log(1 - $upd[$i] / 256, 2) + 8) - -log($upd[$i] / 256, 2);
            if ($save > $flag + $this->probAdaptMargin) {
                $out[$i] = $p;
                $updates++;
            }
        }
        $this->probUpdates = $updates;

        return $out;
    }

    /** Estimated cost, in bits, of coding $n0 zeros and $n1 ones at odds $p. */
    private static function symbolBits(int $n0, int $n1, int $p): float
    {
        $q0 = $p / 256.0;
        $q1 = 1.0 - $q0;
        $bits = 0.0;
        if ($n0 > 0) {
            $bits -= $n0 * log($q0, 2);
        }
        if ($n1 > 0) {
            $bits -= $n1 * log($q1, 2);
        }

        return $bits;
    }

    /**
     * Reset per-frame state and write the frame header (first partition bits
     * that precede the macroblock data).  Safe to call before loading either a
     * whole-frame RGB buffer or the first source band.
     */
    public function initFrame(int $w, int $h): void
    {
        $this->w = $w;
        $this->h = $h;
        // re-derive the quantiser steps: the header deltas may have been set
        // after the constructor ran
        $this->setupQuantizers();
        $this->wp = (int) (($w + 15) & ~15);
        $this->hp = (int) (($h + 15) & ~15);
        $this->cw = $this->wp >> 1;
        $this->ch = $this->hp >> 1;
        $this->mbw = $this->wp >> 4;
        $this->mbh = $this->hp >> 4;
        $this->mbyDone = 0;
        $this->srcBase = 0;
        $this->recBase = 0;
        $this->y = '';
        $this->u = '';
        $this->v = '';
        $this->ry = '';
        $this->ru = '';
        $this->rv = '';

        $header = new BoolEncoder();
        $tokens = new BoolEncoder();
        if ($this->symbolLog !== null) {
            $header->log = [];
            $tokens->log = [];
        }

        // ---- frame header (key frame) ----
        $header->writeLiteral(0, 1);   // color_space
        $header->writeLiteral(0, 1);   // clamping_type (0 = clamp required)
        $header->writeLiteral(0, 1);   // segmentation_enabled
        $header->writeLiteral(0, 1);   // filter_type (0 = normal filter)
        $header->writeLiteral($this->loopFilterLevel & 0x3F, 6); // loop_filter_level
        $header->writeLiteral(0, 3);   // sharpness_level
        $header->writeLiteral(0, 1);   // mb_lf_adjustments
        $header->writeLiteral(0, 2);   // log2_nbr_of_dct_partitions -> 1 partition
        $header->writeLiteral($this->qIndex, 7); // y_ac_qi
        // quantiser deltas: y1 DC, y2 DC, y2 AC, uv DC, uv AC
        $this->writeQuantDelta($header, $this->yDcDelta);
        $this->writeQuantDelta($header, 0);
        $this->writeQuantDelta($header, 0);
        $this->writeQuantDelta($header, $this->uvDcDelta);
        $this->writeQuantDelta($header, $this->uvAcDelta);
        $header->writeLiteral(1, 1);   // refresh_entropy_probs

        $update = Vp8Tables::COEFF_UPDATE_PROBS;
        $defaults = Vp8Tables::DEFAULT_COEFF_PROBS;
        for ($i = 0; $i < 1056; $i++) {
            $p = $this->coeffProbs[$i] ?? $defaults[$i];
            if ($p === $defaults[$i]) {
                $header->writeBool($update[$i], 0); // keep the default probability
            } else {
                $header->writeBool($update[$i], 1);
                $header->writeLiteral($p & 0xFF, 8);
            }
        }
        if ($this->enableSkipFlags) {
            $header->writeLiteral(1, 1);                    // mb_no_skip_coeff
            $header->writeLiteral($this->skipProb & 0xFF, 8); // prob_skip_false
        } else {
            $header->writeLiteral(0, 1);
        }

        $this->above = array_fill(0, 9, 0);
        $this->left = array_fill(0, 9, 0);
        $this->aboveRow = [];
        // Subblock-mode context row: four entries per macroblock column, all
        // B_DC_PRED (0) at the top of the frame (RFC 6386 11.4 note 3).
        $this->bAbove = array_fill(0, max(1, $this->mbw) * 4, 0);
        $this->bLeft = [0, 0, 0, 0];
        $this->mbIntra4Count = 0;
        $this->mbAllZero = 0;
        $this->modelBits = 0;
        // Per-macroblock debug traces: reset here so they describe the *current*
        // pass.  The probability-adaptation pass would otherwise append a
        // second copy of every macroblock.
        $this->trace = [];
        $this->blockLog = [];
        $this->ctxTrace = [];
        $this->bmodeCtxLog = $this->bmodeCtxLog === null ? null : [];
        $this->headerEnc = $header;
        $this->tokenEnc = $tokens;
    }

    /**
     * Install the source pixels for one band of macroblock rows.
     *
     * $y/$u/$v must contain exactly $mbRows*16 (resp. $mbRows*8) rows of the
     * padded frame geometry starting at luma row $topMby*16.  The reconstruction
     * window grows to cover the band; only the row above the band is retained
     * from earlier calls.
     */
    public function beginBand(int $topMby, int $mbRows, string $y, string $u, string $v): void
    {
        $this->srcBase = $topMby << 4;
        $this->y = $y;
        $this->u = $u;
        $this->v = $v;

        if ($this->mbyDone === 0 && $this->ry === '') {
            // Dummy row above the frame; never read because hasAbove is false.
            $this->recBase = -1;
            $this->ry = str_repeat("\x00", $this->wp);
            $this->ru = str_repeat("\x00", $this->cw);
            $this->rv = str_repeat("\x00", $this->cw);
        }
        $this->ry .= str_repeat("\x00", $mbRows * 16 * $this->wp);
        $this->ru .= str_repeat("\x00", $mbRows * 8 * $this->cw);
        $this->rv .= str_repeat("\x00", $mbRows * 8 * $this->cw);
    }

    /** Drop everything but the row above the next band from the recon window. */
    public function compactReconWindow(): void
    {
        if ($this->mbyDone === 0) {
            return;
        }
        $lumaRow = ($this->mbyDone << 4) - 1;
        $chromaRow = $lumaRow >> 1;
        $ryStr = substr($this->ry, ($lumaRow - $this->recBase) * $this->wp, $this->wp);
        $ruStr = substr($this->ru, ($chromaRow - ($this->recBase >> 1)) * $this->cw, $this->cw);
        $rvStr = substr($this->rv, ($chromaRow - ($this->recBase >> 1)) * $this->cw, $this->cw);
        $this->recBase = $lumaRow;
        $this->ry = $ryStr;
        $this->ru = $ruStr;
        $this->rv = $rvStr;
    }

    /** Encode macroblock rows [$from, $from + $count). */
    public function encodeRowRange(int $from, int $count): void
    {
        for ($mby = $from; $mby < $from + $count; $mby++) {
            $this->encodeMbRow($mby);
        }
    }

    /** Serialise everything a later process needs to continue the frame. */
    public function exportState(): array
    {
        return [
            'mbyDone' => $this->mbyDone,
            'aboveRow' => $this->aboveRow,
            'bAbove' => $this->bAbove,
            'mbIntra4Count' => $this->mbIntra4Count,
            'mbAllZero' => $this->mbAllZero,
            'skipProb' => $this->skipProb,
            'header' => serialize($this->headerEnc),
            'tokens' => serialize($this->tokenEnc),
            'recBase' => $this->recBase,
            'ry' => $this->ry,
            'ru' => $this->ru,
            'rv' => $this->rv,
            // probability-adaptation state: the counts have to survive between
            // queue ticks, and the second pass needs the table the header carries
            'statsN0' => $this->probN0,
            'statsN1' => $this->probN1,
            'collectStats' => $this->collectStats,
        ];
    }

    /** @param array<string,mixed> $state */
    public function importState(array $state): void
    {
        $this->mbyDone = (int) $state['mbyDone'];
        $this->aboveRow = $state['aboveRow'];
        $this->bAbove = $state['bAbove'] ?? [];
        $this->mbIntra4Count = (int) ($state['mbIntra4Count'] ?? 0);
        $this->mbAllZero = (int) ($state['mbAllZero'] ?? 0);
        if (isset($state['skipProb'])) {
            $this->skipProb = (int) $state['skipProb'];
        }
        $this->headerEnc = unserialize((string) $state['header']);
        $this->tokenEnc = unserialize((string) $state['tokens']);
        $this->recBase = (int) $state['recBase'];
        $this->ry = (string) $state['ry'];
        $this->ru = (string) $state['ru'];
        $this->rv = (string) $state['rv'];
        if (isset($state['statsN0'])) {
            $this->probN0 = $state['statsN0'];
            $this->probN1 = $state['statsN1'];
            $this->collectStats = (bool) ($state['collectStats'] ?? false);
        }
    }

    private function recBaseFor(string $plane): int
    {
        return $plane === 'y' ? $this->recBase : ($this->recBase >> 1);
    }

    private function srcBaseFor(string $plane): int
    {
        return $plane === 'y' ? $this->srcBase : ($this->srcBase >> 1);
    }

    /** Encode one macroblock row (all macroblocks, left to right). */
    // ------------------------------------------------------ rate-distortion

    /** SSD-per-bit weight for J = D + lambda*R (see $rdLambda). */
    private function lambdaFor(): float
    {
        $q = $this->qYac / 20.0;

        return $this->rdLambda * 40.0 * $q * $q;
    }

    /**
     * Bits -- in 1/256 bit units -- that writeBlock() would spend on one
     * coefficient block, using the default coefficient probabilities.
     *
     * $coeffs are the natural-order quantised coefficients; $L/$A are the
     * nine-entry left/above coefficient contexts and are advanced exactly as
     * writeBlock() advances them, so a whole macroblock can be priced in
     * stream order.
     *
     * @param array<int,int> $L
     * @param array<int,int> $A
     * @param array<int,int> $coeffs
     */
    private function blockRate(array &$L, array &$A, array $coeffs, int $plane, int $firstCoeff, int $leftIdx, int $aboveIdx): int
    {
        $scan = Vp8Tables::ZIGZAG;
        $bands = Vp8Tables::COEFF_BANDS;
        $pathCost = $this->pathCost;
        $catCost = $this->catCost;

        $last = -1;
        for ($c = 15; $c >= $firstCoeff; $c--) {
            if ($coeffs[$scan[$c]] !== 0) {
                $last = $c;
                break;
            }
        }
        $ctx = $L[$leftIdx] + $A[$aboveIdx];
        $planeBase = $plane * 24; // 8 bands * 3 contexts
        $bits = 0;
        $afterZero = false;

        for ($c = $firstCoeff; $c <= 15; $c++) {
            $idx = $planeBase + ($bands[$c] * 3) + $ctx;

            if (!$afterZero && $c > $last) {
                $bits += $pathCost[$idx][11][0]; // EOB
                break;
            }

            $v = $coeffs[$scan[$c]];
            $isZero = $v === 0;
            $token = 0;
            if ($isZero) {
                $ctx = 0;
            } else {
                $abs = $v < 0 ? -$v : $v;
                if ($abs > 2048) {
                    $v = $v < 0 ? -2048 : 2048;
                    $abs = 2048;
                }
                $token = $abs <= 4 ? $abs : 5;
                if ($abs > 4) {
                    foreach (Vp8Tables::TOKEN_CAT_BASE as $ci => $catBase) {
                        $hi = $ci === 5 ? 2048 : Vp8Tables::TOKEN_CAT_BASE[$ci + 1] - 1;
                        if ($abs >= $catBase && $abs <= $hi) {
                            $token = 5 + $ci;
                            break;
                        }
                    }
                }
            }
            $bits += $pathCost[$idx][$token][$afterZero ? 1 : 0];

            if (!$isZero) {
                $ctx = $abs === 1 ? 1 : 2;
                if ($token >= 5) {
                    $ci = $token - 5;
                    $extra = $abs - Vp8Tables::TOKEN_CAT_BASE[$ci];
                    $bits += $catCost[$ci][$extra];
                }
                $bits += 256; // sign bit, always coded at odds 1/2
            }

            $afterZero = $isZero;
        }

        $has = $last >= 0 ? 1 : 0;
        $L[$leftIdx] = $has;
        $A[$aboveIdx] = $has;

        return $bits;
    }

    /**
     * How much squared error one quantiser step of each coefficient is worth
     * in the spatial domain: the squared norm of that coefficient's inverse
     * transform basis vector.
     *
     * Derived from the transform itself (the inverse transform of a unit
     * coefficient), so it cannot drift out of step with the transform the way
     * a hard-coded table could.
     *
     * @return array<int,float>
     */
    private function basisEnergyFor(): array
    {
        if (self::$basisEnergy === []) {
            $energy = [];
            // Probe with a large impulse: the inverse transform ends in a
            // rounded >>3, so feeding it a unit coefficient rounds almost
            // everything to zero and the measured energies come out as noise.
            // The transform is linear, so probing at 256 and dividing by
            // 256^2 recovers the per-step energy.
            $amp = 256;
            for ($c = 0; $c < 16; $c++) {
                $unit = array_fill(0, 16, 0);
                $unit[$c] = $amp;
                $basis = $this->idct4x4($unit);
                $sum = 0.0;
                foreach ($basis as $v) {
                    $sum += $v * $v;
                }
                $energy[$c] = $sum / ($amp * $amp);
            }
            self::$basisEnergy = $energy;
        }

        return self::$basisEnergy;
    }

    /**
     * Trim one coefficient block against the cost of coding it.
     *
     * The rate is priced by the same model that prices the mode decisions, so
     * a level is dropped only when the bits it saves outweigh the error it
     * adds.  Only the *levels* change: the caller rebuilds the dequantised
     * block and the reconstruction from what this returns, which is what keeps
     * the decoder's output identical to the encoder's own reconstruction.
     *
     * $q is in natural order; the rate walk uses scan order, exactly as
     * writeBlock() does.  $L/$A are the entering contexts and are not modified.
     *
     * @param array<int,int> $q
     * @param array<int,int> $L
     * @param array<int,int> $A
     * @return array<int,int>
     */
    private function trellisBlock(
        array $q,
        int $fDc,
        int $fAc,
        int $firstCoeff,
        array $L,
        array $A,
        int $leftIdx,
        int $aboveIdx,
        int $plane,
        float $lambda
    ): array {
        if (!$this->enableTrellis || $lambda <= 0.0) {
            return $q;
        }
        $scan = Vp8Tables::ZIGZAG;
        $energy = $this->basisEnergyFor();
        // The win concentrates in the tail: zeroing a low-order coefficient in
        // the middle of a block saves its own bits, while zeroing the last
        // non-zero coefficient also removes every trailing DCT_0 token.  Search
        // the tail window only -- the rest of the block is never worth the CPU.
        $last = -1;
        for ($c = 15; $c >= $firstCoeff; $c--) {
            if ($q[$scan[$c]] !== 0) {
                $last = $c;
                break;
            }
        }
        if ($last < 0) {
            return $q;
        }
        $floor = max($firstCoeff, $last - self::TRELLIS_TAIL);
        $Lbase = $L;
        $Abase = $A;
        $base = $this->blockRate($Lbase, $Abase, $q, $plane, $firstCoeff, $leftIdx, $aboveIdx);

        for ($sweep = 0; $sweep < $this->trellisSweeps; $sweep++) {
            $changed = false;
            for ($c = $last; $c >= $floor; $c--) {
                $i = $scan[$c];
                $v = $q[$i];
                if ($v === 0) {
                    continue;
                }
                $f = $c === 0 ? $fDc : $fAc;
                $step = $v > 0 ? 1 : -1;
                foreach ([$v - $step, 0] as $cand) {
                    if ($cand === $v) {
                        continue;
                    }
                    $d = ($v - $cand) * $f;
                    $deltaD = $d * $d * $energy[$i];
                    $trial = $q;
                    $trial[$i] = $cand;
                    $Lt = $L;
                    $At = $A;
                    $bits = $this->blockRate($Lt, $At, $trial, $plane, $firstCoeff, $leftIdx, $aboveIdx);
                    if ($deltaD + $lambda * ($bits - $base) / 256.0 < 0.0) {
                        $q = $trial;
                        $base = $bits;
                        $changed = true;
                        break;
                    }
                }
            }
            if (!$changed) {
                break;
            }
        }

        return $q;
    }

    /** Cost of the luma 16x16 (or B_PRED) mode symbols. */
    private function ymodeBitCost(int $mode): int
    {
        return $this->ymodeCost[$mode];
    }

    /** Cost of the chroma mode symbols. */
    private function uvmodeBitCost(int $mode): int
    {
        return $this->uvmodeCost[$mode];
    }

    /**
     * Cost of one 4x4 subblock mode symbol, given the subblock modes already
     * chosen in this macroblock (the (above, left) pair is the context).
     *
     * @param array<int,int> $modes
     */
    private function bmodeBitCost(int $mbx, int $b, array $modes, int $mode): int
    {
        $br = $b >> 2;
        $bc = $b & 3;
        $A = $br === 0 ? $this->bAbove[($mbx << 2) + $bc] : $modes[(($br - 1) << 2) + $bc];
        $L = $bc === 0 ? $this->bLeft[$br] : $modes[($br << 2) + $bc - 1];

        return $this->bmodeCost[$A * 10 + $L][$mode];
    }

    /**
     * Transform, quantise and reconstruct one 16x16 luma macroblock for a
     * given prediction, without touching the reconstruction plane.
     *
     * @param array<int,int> $pred row-major 16x16 prediction
     * @return array{0:array<int,array<int,int>>,1:array<int,int>,2:array<int,int>,3:int}
     *         [quantised luma blocks, quantised Y2, reconstruction, SSD]
     */
    private function quantiseLuma16(int $px, int $py, array $pred, array $Lc, array $Ac, float $lambda): array
    {
        $srcBase = $this->srcBase;
        $wp = $this->wp;
        $dcs = [];
        $acBlocks = [];
        for ($b = 0; $b < 16; $b++) {
            $bx = $px + (($b & 3) << 2);
            $by = $py + (($b >> 2) << 2);
            $res = [];
            for ($r = 0; $r < 4; $r++) {
                $srcRow = ($by + $r - $srcBase) * $wp + $bx;
                $rr = $r << 2;
                $pr = ((($by - $py) + $r) << 4) + ($bx - $px);
                $res[$rr] = ord($this->y[$srcRow]) - $pred[$pr];
                $res[$rr + 1] = ord($this->y[$srcRow + 1]) - $pred[$pr + 1];
                $res[$rr + 2] = ord($this->y[$srcRow + 2]) - $pred[$pr + 2];
                $res[$rr + 3] = ord($this->y[$srcRow + 3]) - $pred[$pr + 3];
            }
            $co = $this->fdct4x4($res);
            $dcs[$b] = $co[0];
            $co[0] = 0;
            $acBlocks[$b] = $co;
        }

        $y2raw = $this->fwht4x4($dcs);
        $y2q = [];
        for ($i = 0; $i < 16; $i++) {
            $y2q[$i] = $this->quant($y2raw[$i], $i === 0 ? $this->qY2dc : $this->qY2ac);
        }
        $y2q = $this->trellisBlock($y2q, $this->qY2dc, $this->qY2ac, 0, $Lc, $Ac, 8, 8, 1, $lambda);
        $y2dq = [];
        for ($i = 0; $i < 16; $i++) {
            $y2dq[$i] = $y2q[$i] * ($i === 0 ? $this->qY2dc : $this->qY2ac);
        }
        $reconDc = $this->iwht4x4($y2dq);

        $lumaQ = [];
        $recon = array_fill(0, 256, 0);
        $ssd = 0;
        // The rate model needs the coefficient contexts these blocks will be
        // coded against, so they advance block by block exactly as they do in
        // the token partition.
        $Lrun = $Lc;
        $Arun = $Ac;
        for ($b = 0; $b < 16; $b++) {
            $blk = $acBlocks[$b];
            $q = [0];
            for ($i = 1; $i < 16; $i++) {
                $q[$i] = $this->quant($blk[$i], $this->qYac);
            }
            $q = $this->trellisBlock($q, 0, $this->qYac, 1, $Lrun, $Arun, $b >> 2, $b & 3, 0, $lambda);
            $this->blockRate($Lrun, $Arun, $q, 0, 1, $b >> 2, $b & 3);
            $dq = [$reconDc[$b]];
            for ($i = 1; $i < 16; $i++) {
                $dq[$i] = $q[$i] * $this->qYac;
            }
            $lumaQ[$b] = $q;
            $res = $this->idct4x4($dq);
            $bx = $px + (($b & 3) << 2);
            $by = $py + (($b >> 2) << 2);
            for ($r = 0; $r < 4; $r++) {
                $srcRow = ($by + $r - $srcBase) * $wp + $bx;
                $pr = ((($by - $py) + $r) << 4) + ($bx - $px);
                $rr = $r << 2;
                for ($k = 0; $k < 4; $k++) {
                    $v = $this->clamp255($pred[$pr + $k] + $res[$rr + $k]);
                    $recon[$pr + $k] = $v;
                    $d = ord($this->y[$srcRow + $k]) - $v;
                    $ssd += $d * $d;
                }
            }
        }

        return [$lumaQ, $y2q, $recon, $ssd];
    }

    /** Copy a 16x16 reconstruction buffer into the luma plane. */
    private function blitReconY(int $px, int $py, array $recon): void
    {
        $wp = $this->wp;
        for ($r = 0; $r < 16; $r++) {
            $dstRow = ($py + $r - $this->recBase) * $wp + $px;
            $rr = $r << 4;
            $this->ry[$dstRow] = chr($recon[$rr]);
            $this->ry[$dstRow + 1] = chr($recon[$rr + 1]);
            $this->ry[$dstRow + 2] = chr($recon[$rr + 2]);
            $this->ry[$dstRow + 3] = chr($recon[$rr + 3]);
            $this->ry[$dstRow + 4] = chr($recon[$rr + 4]);
            $this->ry[$dstRow + 5] = chr($recon[$rr + 5]);
            $this->ry[$dstRow + 6] = chr($recon[$rr + 6]);
            $this->ry[$dstRow + 7] = chr($recon[$rr + 7]);
            $this->ry[$dstRow + 8] = chr($recon[$rr + 8]);
            $this->ry[$dstRow + 9] = chr($recon[$rr + 9]);
            $this->ry[$dstRow + 10] = chr($recon[$rr + 10]);
            $this->ry[$dstRow + 11] = chr($recon[$rr + 11]);
            $this->ry[$dstRow + 12] = chr($recon[$rr + 12]);
            $this->ry[$dstRow + 13] = chr($recon[$rr + 13]);
            $this->ry[$dstRow + 14] = chr($recon[$rr + 14]);
            $this->ry[$dstRow + 15] = chr($recon[$rr + 15]);
        }
    }

    /**
     * Transform, quantise and price one 8x8 chroma macroblock.
     *
     * @param array<int,int> $pred row-major 8x8 prediction
     * @return array{0:array<int,array<int,int>>,1:int} [quantised blocks, SSD]
     */
    private function quantiseChroma(
        string $plane,
        int $cx,
        int $cy,
        array $pred,
        array $Lc,
        array $Ac,
        float $lambda
    ): array {
        $srcPlane = $plane === 'u' ? $this->u : $this->v;
        $srcBaseC = $this->srcBaseFor($plane);
        $cw = $this->cw;
        $blocks = [];
        $ssd = 0;
        $Lrun = $Lc;
        $Arun = $Ac;
        for ($b = 0; $b < 4; $b++) {
            $bx = $cx + (($b & 1) << 2);
            $by = $cy + (($b >> 1) << 2);
            $res = [];
            for ($r = 0; $r < 4; $r++) {
                $srcRow = ($by + $r - $srcBaseC) * $cw + $bx;
                $rr = $r << 2;
                $pr = ((($by - $cy) + $r) << 3) + ($bx - $cx);
                $res[$rr] = ord($srcPlane[$srcRow]) - $pred[$pr];
                $res[$rr + 1] = ord($srcPlane[$srcRow + 1]) - $pred[$pr + 1];
                $res[$rr + 2] = ord($srcPlane[$srcRow + 2]) - $pred[$pr + 2];
                $res[$rr + 3] = ord($srcPlane[$srcRow + 3]) - $pred[$pr + 3];
            }
            $co = $this->fdct4x4($res);
            $q = [];
            for ($i = 0; $i < 16; $i++) {
                $q[$i] = $this->quant($co[$i], $i === 0 ? $this->qUvdc : $this->qUvac);
            }
            // U occupies context slots 4..5 and V slots 6..7 of the coefficient
            // context arrays; within one plane the four blocks advance them in
            // raster order.
            $base = $plane === 'u' ? 4 : 6;
            $q = $this->trellisBlock(
                $q,
                $this->qUvdc,
                $this->qUvac,
                0,
                $Lrun,
                $Arun,
                $base + ($b >> 1),
                $base + ($b & 1),
                2,
                $lambda
            );
            $this->blockRate($Lrun, $Arun, $q, 2, 0, $base + ($b >> 1), $base + ($b & 1));
            $dq = [];
            for ($i = 0; $i < 16; $i++) {
                $dq[$i] = $q[$i] * ($i === 0 ? $this->qUvdc : $this->qUvac);
            }
            $blocks[$b] = $q;
            $rec = $this->idct4x4($dq);
            for ($r = 0; $r < 4; $r++) {
                $srcRow = ($by + $r - $srcBaseC) * $cw + $bx;
                $pr = ((($by - $cy) + $r) << 3) + ($bx - $cx);
                $rr = $r << 2;
                for ($k = 0; $k < 4; $k++) {
                    $v = $this->clamp255($pred[$pr + $k] + $rec[$rr + $k]);
                    $d = ord($srcPlane[$srcRow + $k]) - $v;
                    $ssd += $d * $d;
                }
            }
        }

        return [$blocks, $ssd];
    }

    /**
     * Rank 4x4 candidate modes by SAD and keep the best few for the full
     * rate-distortion evaluation.  Prediction plus sixteen absolute
     * differences costs a fraction of a transform, quantise and inverse
     * transform, and it ranks the candidates well enough that the winner is
     * almost always inside the short list.
     *
     * @param array<int,int> $candidates
     * @param array<int,int> $s
     * @param array<int,int> $Ax
     * @param array<int,int> $Lx
     * @param array<int,int> $E
     * @return array<int,int>
     */
    private function pruneBySad(array $candidates, array $s, array $Ax, array $Lx, array $E): array
    {
        $scored = [];
        foreach ($candidates as $m) {
            $B = $this->bpredBuild($m, $Ax, $Lx, $E);
            $sad = 0;
            for ($k = 0; $k < 16; $k++) {
                $d = $s[$k] - $B[$k];
                $sad += $d < 0 ? -$d : $d;
            }
            $scored[] = [$sad, $m];
        }
        sort($scored);
        $keep = [];
        $n = min($this->rdPruneCandidates, count($scored));
        for ($i = 0; $i < $n; $i++) {
            $keep[] = $scored[$i][1];
        }
        sort($keep);

        return $keep;
    }

    /**
     * Price one 4x4 luma subblock for one candidate prediction mode:
     * predict, transform, quantise, reconstruct, then
     *
     *     J = SSD(source, reconstruction) + lambda * bits
     *
     *     J = SSD(source, reconstruction) + lambda * bits
     *
     * The bit count is the subblock-mode symbol plus the coefficient block,
     * both priced with the contexts as they stand *entering* this subblock
     * (the caller advances them with the winner's non-zero flags).
     *
     * @param array<int,int> $s source samples, row-major 4x4
     * @param array<int,int> $Ax
     * @param array<int,int> $Lx
     * @param array<int,int> $E
     * @param array<int,int> $Lcost
     * @param array<int,int> $Acost
     * @param array<int,int> $modes
     * @return array{0:float,1:int,2:array<int,int>,3:array<int,int>,4:array<int,int>,5:int,6:int}
     *         [J, bits, prediction, quantised coefficients, reconstruction, SAD, SSD]
     */
    private function evalSubblock(
        int $b,
        array $s,
        array $Ax,
        array $Lx,
        array $E,
        array $Lcost,
        array $Acost,
        float $lambda,
        int $mode,
        int $mbx,
        array $modes
    ): array {
        $B = $this->bpredBuild($mode, $Ax, $Lx, $E);
        $res = [];
        $sad = 0;
        for ($k = 0; $k < 16; $k++) {
            $d = $s[$k] - $B[$k];
            $res[$k] = $d;
            $sad += $d < 0 ? -$d : $d;
        }
        $co = $this->fdct4x4($res);
        $q = [];
        for ($i = 0; $i < 16; $i++) {
            $q[$i] = $this->quant($co[$i], $i === 0 ? $this->qYdc : $this->qYac);
        }
        $q = $this->trellisBlock($q, $this->qYdc, $this->qYac, 0, $Lcost, $Acost, $b >> 2, $b & 3, 3, $lambda);
        $dq = [];
        for ($i = 0; $i < 16; $i++) {
            $dq[$i] = $q[$i] * ($i === 0 ? $this->qYdc : $this->qYac);
        }
        $idct = $this->idct4x4($dq);
        $rec = [];
        $ssd = 0;
        for ($k = 0; $k < 16; $k++) {
            $v = $this->clamp255($B[$k] + $idct[$k]);
            $rec[$k] = $v;
            $d = $s[$k] - $v;
            $ssd += $d * $d;
        }
        $bits = $this->blockRate($Lcost, $Acost, $q, 3, 0, $b >> 2, $b & 3);
        $bits += $this->bmodeBitCost($mbx, $b, $modes, $mode);

        return [$ssd + $lambda * $bits / 256.0, $bits, $B, $q, $rec, $sad, $ssd];
    }

    private function encodeMbRow(int $mby): void
    {
        $header = $this->headerEnc;
        $tokens = $this->tokenEnc;
        if ($header === null || $tokens === null) {
            throw new \LogicException('initFrame() must run before encoding rows');
        }

        $mbw = $this->mbw;
        $ymodeProb = Vp8Tables::YMODE_PROB;
        $uvProb = Vp8Tables::UVMODE_PROB;
        $srcBaseY = $this->srcBase;
        $recBaseY = $this->recBase;

        $this->left = array_fill(0, 9, 0);
        // The four left subblock-mode predictors reset at the start of every
        // macroblock row (RFC 6386 11.4 note 3).
        $this->bLeft = [0, 0, 0, 0];
        for ($mbx = 0; $mbw > $mbx; $mbx++) {
                // Contexts: left comes from the macroblock just coded in this
                // row, above from the same column in the previous row.
                $this->above = $this->aboveRow[$mbx] ?? array_fill(0, 9, 0);
                $px = $mbx << 4;
                $py = $mby << 4;

                $hasAbove = $mby > 0;
                $hasLeft = $mbx > 0;

                $cxMb = $mbx << 3;
                $cyMb = $mby << 3;

                // ---- luma mode decision -------------------------------------
                // The token partition is coded in stream order, so the price of
                // a candidate depends on the coefficients coded before it.
                // $Lc/$Ac track that state through the two decision stages.
                $lambdaBase = $this->enableRd ? $this->lambdaFor() : 0.0;
                $lambdaI16 = $lambdaBase * $this->rdLambdaI16;
                $lambdaI4 = $lambdaBase * $this->rdLambdaI4;
                $lambdaUv = $lambdaBase * $this->rdLambdaUv;
                $Lc = $this->left;
                $Ac = $this->above;
                $skipCost = $this->enableSkipFlags ? $this->bitCost[$this->skipProb][1] : 0;

                // ---- 16x16 prediction: SAD for all four modes ----
                [$sadY, $aboveY, $leftY, $dcY, $cornerY] = $this->scoreModes('y', $this->y, $px, $py, $hasAbove, $hasLeft, 16);
                $yMode16 = 0;
                $i16Sad = $sadY[0];
                if ($this->enableModeSearch) {
                    for ($m = 1; $m < 4; $m++) {
                        if ($sadY[$m] < $i16Sad) {
                            $i16Sad = $sadY[$m];
                            $yMode16 = $m;
                        }
                    }
                }

                // ---- 4x4 luma: one RD search per subblock ----
                // encodeMbIntra4x4() reconstructs into the plane as it goes
                // (each subblock predicts from the one before it), so a 4x4
                // macroblock that loses the comparison has to be rolled back.
                $i4Blocks = null;
                $i4Modes = null;
                $i4Sad = PHP_INT_MAX;
                $i4J = null;
                $i4Bits = 0;
                $i4L = $Lc;
                $i4A = $Ac;
                $savedY = null;
                $row0 = ($py - $recBaseY) * $this->wp + $px;
                if ($this->enableBPred) {
                    $savedY = '';
                    for ($r = 0; $r < 16; $r++) {
                        $savedY .= substr($this->ry, $row0 + $r * $this->wp, 16);
                    }
                    [$i4Blocks, $i4Modes, $i4Sad, $i4Bits, $i4Ssd] = $this->encodeMbIntra4x4(
                        $px, $py, $mbx, $hasAbove, $hasLeft, $i4L, $i4A
                    );
                    $i4Bits += $this->ymodeBitCost(4) + $skipCost;
                    $i4J = $i4Ssd + $lambdaI4 * $i4Bits / 256.0;
                    $this->sumSad4 += $i4Sad;
                }

                // ---- 16x16 candidates: transform, quantise, reconstruct, price ----
                $best16 = null;
                $cands16 = $this->enableRd
                    ? ($this->enableModeSearch ? [0, 1, 2, 3] : [0])
                    : [$yMode16];
                foreach ($cands16 as $m) {
                    $pred = $this->buildPrediction(16, $m, $aboveY, $leftY, $dcY, $cornerY);
                    [$lq, $y2, $recon16, $ssd] = $this->quantiseLuma16($px, $py, $pred, $Lc, $Ac, $lambdaI16);
                    $L = $Lc;
                    $A = $Ac;
                    $bits = $this->ymodeBitCost($m) + $skipCost;
                    $bits += $this->blockRate($L, $A, $y2, 1, 0, 8, 8);
                    for ($b = 0; $b < 16; $b++) {
                        $bits += $this->blockRate($L, $A, $lq[$b], 0, 1, $b >> 2, $b & 3);
                    }
                    $j = $ssd + $lambdaI16 * $bits / 256.0;
                    if ($best16 === null || $j < $best16['j']) {
                        $best16 = [
                            'j' => $j,
                            'mode' => $m,
                            'lumaQ' => $lq,
                            'y2q' => $y2,
                            'recon' => $recon16,
                            'bits' => $bits,
                            'L' => $L,
                            'A' => $A,
                        ];
                    }
                }
                $this->sumSad16 += $i16Sad;

                // ---- pick the luma coding mode ----
                $useI4 = $this->enableBPred && ($this->enableRd
                    ? $i4J < $best16['j']
                    : $i4Sad < $i16Sad * $this->bpredBias);

                $y2q = null;
                if ($useI4) {
                    $yMode = 4;
                    $predY = [];
                    $lumaQ = $i4Blocks;
                    $LcAfter = $i4L;
                    $AcAfter = $i4A;
                } else {
                    $yMode = $best16['mode'];
                    $predY = $this->buildPrediction(16, $yMode, $aboveY, $leftY, $dcY, $cornerY);
                    $lumaQ = $best16['lumaQ'];
                    $y2q = $best16['y2q'];
                    $LcAfter = $best16['L'];
                    $AcAfter = $best16['A'];
                    if ($savedY !== null) {
                        // undo the rejected 4x4 reconstruction
                        for ($r = 0; $r < 16; $r++) {
                            $dst = $row0 + $r * $this->wp;
                            $src = $r << 4;
                            for ($k = 0; $k < 16; $k++) {
                                $this->ry[$dst + $k] = $savedY[$src + $k];
                            }
                        }
                    }
                    $this->blitReconY($px, $py, $best16['recon']);
                }

                // ---- chroma prediction: one shared mode for U and V ----
                [$sadU, $aboveU, $leftU, $dcU, $cornerU] = $this->scoreModes('u', $this->u, $cxMb, $cyMb, $hasAbove, $hasLeft, 8);
                [$sadV, $aboveV, $leftV, $dcV, $cornerV] = $this->scoreModes('v', $this->v, $cxMb, $cyMb, $hasAbove, $hasLeft, 8);
                $uvMode = 0;
                if ($this->enableModeSearch) {
                    for ($m = 1; $m < 4; $m++) {
                        if ($sadU[$m] + $sadV[$m] < $sadU[$uvMode] + $sadV[$uvMode]) {
                            $uvMode = $m;
                        }
                    }
                }

                // ---- chroma: one shared mode for U and V ----
                $uvCands = $this->enableRd
                    ? ($this->enableModeSearch ? [0, 1, 2, 3] : [0])
                    : [$uvMode];
                $bestUv = null;
                foreach ($uvCands as $m) {
                    $pu = $this->buildPrediction(8, $m, $aboveU, $leftU, $dcU, $cornerU);
                    $pv = $this->buildPrediction(8, $m, $aboveV, $leftV, $dcV, $cornerV);
                    [$qu, $ssdUc] = $this->quantiseChroma('u', $cxMb, $cyMb, $pu, $LcAfter, $AcAfter, $lambdaUv);
                    [$qv, $ssdVc] = $this->quantiseChroma('v', $cxMb, $cyMb, $pv, $LcAfter, $AcAfter, $lambdaUv);
                    $L = $LcAfter;
                    $A = $AcAfter;
                    $bits = $this->uvmodeBitCost($m) + $skipCost;
                    for ($b = 0; $b < 4; $b++) {
                        $bits += $this->blockRate($L, $A, $qu[$b], 2, 0, 4 + ($b >> 1), 4 + ($b & 1));
                    }
                    for ($b = 0; $b < 4; $b++) {
                        $bits += $this->blockRate($L, $A, $qv[$b], 2, 0, 6 + ($b >> 1), 6 + ($b & 1));
                    }
                    $j = $ssdUc + $ssdVc + $lambdaUv * $bits / 256.0;
                    if ($bestUv === null || $j < $bestUv['j']) {
                        $bestUv = ['j' => $j, 'mode' => $m, 'pu' => $pu, 'pv' => $pv, 'qu' => $qu, 'qv' => $qv];
                    }
                }
                $uvMode = $bestUv['mode'];
                $predU = $bestUv['pu'];
                $predV = $bestUv['pv'];
                $chromaQ = ['u' => $bestUv['qu'], 'v' => $bestUv['qv']];

                // ---- chroma: reconstruct the winning candidate ----
                foreach (['u', 'v'] as $plane) {
                    $recPlane = $plane === 'u' ? $this->ru : $this->rv;
                    $cx = $cxMb;
                    $cy = $cyMb;
                    $predBlock = $plane === 'u' ? $predU : $predV;
                    $recBaseC = $this->recBaseFor($plane);
                    $blocks = $chromaQ[$plane];
                    for ($b = 0; $b < 4; $b++) {
                        $bx = $cx + (($b & 1) << 2);
                        $by = $cy + (($b >> 1) << 2);
                        $q = $blocks[$b];
                        $dq = [];
                        for ($i = 0; $i < 16; $i++) {
                            $dq[$i] = $q[$i] * ($i === 0 ? $this->qUvdc : $this->qUvac);
                        }
                        $res = $this->idct4x4($dq);
                        for ($r = 0; $r < 4; $r++) {
                            $dstRow = ($by + $r - $recBaseC) * $this->cw + $bx;
                            $rr = $r << 2;
                            $pr = (($by - $cy) + $r) << 3;
                            $pc = $bx - $cx;
                            $recPlane[$dstRow] = chr($this->clamp255($predBlock[$pr + $pc] + $res[$rr]));
                            $recPlane[$dstRow + 1] = chr($this->clamp255($predBlock[$pr + $pc + 1] + $res[$rr + 1]));
                            $recPlane[$dstRow + 2] = chr($this->clamp255($predBlock[$pr + $pc + 2] + $res[$rr + 2]));
                            $recPlane[$dstRow + 3] = chr($this->clamp255($predBlock[$pr + $pc + 3] + $res[$rr + 3]));
                        }
                    }
                    if ($plane === 'u') {
                        $this->ru = $recPlane;
                    } else {
                        $this->rv = $recPlane;
                    }
                }

                // ---- does this macroblock carry any coefficient at all? ----
                $hasCoeffs = $this->blocksHaveCoeffs($lumaQ, $y2q, $chromaQ);
                if (!$hasCoeffs) {
                    $this->mbAllZero++;
                }
                $skip = $this->enableSkipFlags && !$hasCoeffs;

                // ---- MB header: skip flag, luma mode (+ 16 submodes), chroma ----
                // The skip flag precedes the modes in the bitstream, so the
                // coefficients have to be quantised before this point.
                if ($this->enableSkipFlags) {
                    $header->writeBool($this->skipProb, $skip ? 1 : 0);
                    if ($this->skipLog !== null) {
                        $this->skipLog[] = $skip ? 1 : 0;
                    }
                }
                if ($useI4) {
                    foreach ($this->ymodePaths[4] as $step) {
                        $header->writeBool($ymodeProb[$step[0] >> 1], $step[1]);
                    }
                    $this->writeBPredModes($header, $mbx, $i4Modes);
                    // The mode context must advance for every macroblock,
                    // skipped or not: skipped macroblocks still code modes.
                    if ($this->enableBPred) {
                        $this->updateBModeContext($mbx, $i4Modes);
                    }
                    $this->mbIntra4Count++;
                } else {
                    foreach ($this->ymodePaths[$yMode] as $step) {
                        $header->writeBool($ymodeProb[$step[0] >> 1], $step[1]);
                    }
                    if ($this->enableBPred) {
                        $this->updateBModeContext($mbx, array_fill(0, 16, Vp8Tables::YMODE_TO_BMODE[$yMode]));
                    }
                }
                foreach ($this->uvmodePaths[$uvMode] as $step) {
                    $header->writeBool($uvProb[$step[0] >> 1], $step[1]);
                }

                // ---- token partition: [Y2], 16 Y, then 4 U, then 4 V ----
                if ($this->traceEnabled) {
                    $this->trace[] = [
                        'y2' => $y2q,
                        'y' => $lumaQ,
                        'u' => $chromaQ['u'],
                        'v' => $chromaQ['v'],
                        'i4' => $useI4,
                        'bmodes' => $i4Modes,
                    ];
                }
                if ($skip) {
                    // A skipped macroblock carries no coefficients, and the
                    // decoder clears the Y/U/V non-zero contexts for it.  The
                    // Y2 (DC) context is only cleared for 16x16 macroblocks:
                    // 4x4 macroblocks have no Y2 block, so it survives them.
                    for ($i = 0; $i < 8; $i++) {
                        $this->left[$i] = 0;
                        $this->above[$i] = 0;
                    }
                    if (!$useI4) {
                        $this->left[8] = 0;
                        $this->above[8] = 0;
                    }
                } else {
                    if ($useI4) {
                        // No Y2 block: every subblock carries its own DC,
                        // coded against the "Y with coefficient 0" plane.
                        for ($b = 0; $b < 16; $b++) {
                            $this->writeBlock($lumaQ[$b], 3, 0, $b >> 2, $b & 3, $tokens);
                        }
                    } else {
                        $this->writeBlock($y2q, 1, 0, 8, 8, $tokens);
                        for ($b = 0; $b < 16; $b++) {
                            $this->writeBlock($lumaQ[$b], 0, 1, $b >> 2, $b & 3, $tokens);
                        }
                    }
                    for ($b = 0; $b < 4; $b++) {
                        $this->writeBlock($chromaQ['u'][$b], 2, 0, 4 + ($b >> 1), 4 + ($b & 1), $tokens);
                    }
                    for ($b = 0; $b < 4; $b++) {
                        $this->writeBlock($chromaQ['v'][$b], 2, 0, 6 + ($b >> 1), 6 + ($b & 1), $tokens);
                    }
                }

                $this->aboveRow[$mbx] = $this->above;
            }
        $this->mbyDone = $mby + 1;
    }

    /**
     * Close the frame: flush both partitions and wrap them in the RIFF/WebP
     * container.
     *
     * @return array<string,mixed>
     */
    public function finishFrame(): array
    {
        $header = $this->headerEnc;
        $tokens = $this->tokenEnc;
        if ($header === null || $tokens === null) {
            throw new \LogicException('initFrame() must run before finishFrame()');
        }

        if ($this->symbolLog !== null) {
            $this->tokenSymbolLog = $tokens->log ?? [];
        }
        $first = $header->flush();
        $tokenData = $tokens->flush();

        // The two size fields are the *display* size; the padded geometry only
        // exists inside the encoder/decoder.  Writing the padded size here used
        // to hand back a 1504-tall image for a 1500-tall source.
        $payload = self::frameTag(strlen($first)) . "\x9d\x01\x2a"
            . chr($this->w & 0xFF) . chr(($this->w >> 8) & 0x3F)
            . chr($this->h & 0xFF) . chr(($this->h >> 8) & 0x3F)
            . $first . $tokenData;

        $webp = self::riffWrap($payload, $this->w, $this->h, $this->alphaPlane, $this->iccProfile, $this->alphaFilter);

        if ($this->symbolLog !== null) {
            $this->symbolLog = $header->log ?? [];
        }

        $fullRecon = $this->recBase === 0 && strlen($this->ry) >= $this->wp * $this->hp;

        return [
            'webp' => $webp,
            'y' => $fullRecon ? substr($this->ry, 0, $this->wp * $this->hp) : '',
            'u' => $fullRecon ? $this->ru : '',
            'v' => $fullRecon ? $this->rv : '',
            'w' => $this->wp,
            'h' => $this->hp,
            'mb' => $this->mbw * $this->mbh,
            'mbIntra4' => $this->mbIntra4Count,
            'mbAllZero' => $this->mbAllZero,
            'firstBytes' => strlen($first),
            'tokenBytes' => strlen($tokenData),
            'modelBits' => $this->modelBits,
            'bytes' => strlen($webp),
        ];
    }

    /**
     * Re-slice a padded reconstruction (the 'y'/'u'/'v' keys of encode() /
     * finishFrame()) into the plane layout `dwebp -yuv` writes: luma at the
     * *display* size w x h, chroma at ceil(w/2) x ceil(h/2).
     *
     * The bitstream carries the display size, so the decoder crops; the
     * encoder's own planes stay padded.  Verifiers have to compare like with
     * like.
     *
     * @param array<string,mixed> $res
     */
    public static function displayPlanes(array $res, int $w, int $h): string
    {
        $pw = (int) $res['w'];
        $cw = $pw >> 1;
        $cwd = ($w + 1) >> 1;
        $chd = ($h + 1) >> 1;
        $y = (string) $res['y'];
        $u = (string) $res['u'];
        $v = (string) $res['v'];
        if ($y === '' || $u === '' || $v === '') {
            return '';
        }
        $outY = '';
        for ($j = 0; $j < $h; $j++) {
            $outY .= substr($y, $j * $pw, $w);
        }
        $outU = '';
        $outV = '';
        for ($j = 0; $j < $chd; $j++) {
            $outU .= substr($u, $j * $cw, $cwd);
            $outV .= substr($v, $j * $cw, $cwd);
        }

        return $outY . $outU . $outV;
    }

    private static function frameTag(int $firstPartSize): string
    {
        // NB: bit 0 is *clear* for a key frame.  The RFC prose reads
        // "key_frame = tmp & 0x1", but its own reference decoder (and libvpx,
        // libwebp, ffmpeg) treat the flag as inverted: `is_keyframe = !bit0`.
        $tmp = 0 | (0 << 1) | (1 << 4) | ($firstPartSize << 5);

        return chr($tmp & 0xFF) . chr(($tmp >> 8) & 0xFF) . chr(($tmp >> 16) & 0xFF);
    }

    /**
     * Wrap the colour bitstream in RIFF.
     *
     * A bare 'VP8 ' chunk is the simple format and stays byte-identical to what
     * this encoder produced before.  Transparency or an ICC profile needs the
     * extended format instead: 'VP8X', then the optional 'ICCP' and 'ALPH'
     * chunks, then the colour data -- that order is mandated because the chunks
     * a decoder needs for reconstruction must come first.
     */
    private static function riffWrap(
        string $payload,
        int $w = 0,
        int $h = 0,
        ?string $alphaPlane = null,
        ?string $iccProfile = null,
        int $alphaFilter = AlphaCoder::FILTER_AUTO
    ): string {
        $chunks = '';
        if ($alphaPlane !== null || $iccProfile !== null) {
            $flags = ($iccProfile !== null ? 0x20 : 0x00) | ($alphaPlane !== null ? 0x10 : 0x00);
            $wm1 = $w - 1;
            $hm1 = $h - 1;
            $canvas = chr($flags) . "\x00\x00\x00"
                . chr($wm1 & 0xFF) . chr(($wm1 >> 8) & 0xFF) . chr(($wm1 >> 16) & 0xFF)
                . chr($hm1 & 0xFF) . chr(($hm1 >> 8) & 0xFF) . chr(($hm1 >> 16) & 0xFF);
            $chunks .= self::riffChunk('VP8X', $canvas);
            if ($iccProfile !== null) {
                $chunks .= self::riffChunk('ICCP', $iccProfile);
            }
            if ($alphaPlane !== null) {
                $chunks .= self::riffChunk('ALPH', AlphaCoder::encode($alphaPlane, $w, $h, $alphaFilter));
            }
        }
        $chunks .= self::riffChunk('VP8 ', $payload);

        return 'RIFF' . pack('V', 4 + strlen($chunks)) . 'WEBP' . $chunks;
    }

    private static function riffChunk(string $fourcc, string $payload): string
    {
        return $fourcc . pack('V', strlen($payload)) . $payload . ((strlen($payload) & 1) ? "\x00" : '');
    }
}
