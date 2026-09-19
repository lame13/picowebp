<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

/**
 * Pure-PHP baseline JPEG decoder.
 *
 * JPEG has no free lunch the way PNG does: there is no zlib to lean on, so
 * this is the whole pipeline — Huffman decoding, dequantisation, an 8x8
 * inverse DCT, chroma upsampling and the colour transform, written out here.
 *
 * Scope, deliberately narrow and loudly enforced:
 *
 *   supported   baseline sequential (SOF0), 8-bit, one or three components,
 *               Huffman coding, sampling factors 1..4, restart markers,
 *               DNL-free streams, APP2 ICC profiles
 *   rejected    progressive (SOF2), 12-bit (SOF1), lossless and differential
 *               frames, arithmetic coding (SOF9+), CMYK/YCCK (four
 *               components) — each with an error that names what it hit
 *
 * Rejecting beats guessing: a half-right decoder quietly mangles pictures,
 * and this one exists for hosts that have no other decoder at all.
 *
 * Output is 8-bit RGB at display size, with no alpha (JPEG has none).
 */
final class JpegReader
{
    /** zig-zag scan order: zigzag[k] is the natural-order index of coefficient k */
    private const ZIGZAG = [
        0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5,
        12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6, 7, 14, 21, 28,
        35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51,
        58, 59, 52, 45, 38, 31, 39, 46, 53, 60, 61, 54, 47, 55, 62, 63,
    ];

    /** @var array<int,array<int,int>> quantisation tables by destination id */
    private array $quant = [];
    /** @var array<int,array<string,mixed>> */
    private array $huffDc = [];
    /** @var array<int,array<string,mixed>> */
    private array $huffAc = [];

    private int $width = 0;
    private int $height = 0;
    private int $maxH = 1;
    private int $maxV = 1;
    private int $restartInterval = 0;
    private ?string $icc = null;
    private bool $adobe = false;
    private int $adobeTransform = -1;

    /** @var array<int,array<string,mixed>> component descriptors, keyed by scan id */
    private array $components = [];
    /** @var array<int,int> component id by index */
    private array $order = [];

    /**
     * Sample planes, keyed by component index: one string per row.
     *
     * Not one string per plane, deliberately — writing a byte into a shared
     * string makes PHP copy the whole plane, so a per-block store would cost
     * O(image size) every time.
     *
     * @var array<int,array<int,string>>
     */
    private array $planes = [];
    /** @var array<int,int> */
    private array $planeW = [];
    /** @var array<int,int> */
    private array $planeH = [];
    /** @var array<int,int> */
    private array $dcPred = [];

    /** @var array<int,array<int,float>>|null */
    private static ?array $basis = null;

    /**
     * @return array{w:int,h:int,rgb:string,alpha:null,icc:?string}
     */
    public static function read(string $path): array
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("cannot read image: $path");
        }

        return (new self())->decode($bytes);
    }

    /**
     * A cheap yes/no plus a reason, for callers that want to fall back to
     * another decoder without catching an exception.
     *
     * @return array{ok:bool,reason:string}
     */
    public static function supported(string $path): array
    {
        try {
            (new self())->parse((string) @file_get_contents($path));

            return ['ok' => true, 'reason' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * @return array{w:int,h:int,rgb:string,alpha:null,icc:?string}
     */
    public function decode(string $bytes): array
    {
        // parse() walks the markers and decodes every scan as it reaches it,
        // so the component planes are complete by the time it returns.
        $this->parse($bytes);

        return [
            'w' => $this->width,
            'h' => $this->height,
            'rgb' => $this->toRgb(),
            'alpha' => null,
            'icc' => $this->icc,
        ];
    }

    // ------------------------------------------------------------- parsing

    /**
     * Walk the marker segments, decoding each scan as it is reached.
     *
     * @return int the offset just past the last marker consumed
     */
    private function parse(string $bytes): int
    {
        $n = strlen($bytes);
        if ($n < 4 || $bytes[0] !== "\xff" || $bytes[1] !== "\xd8") {
            throw new \InvalidArgumentException('not a JPEG file');
        }
        $this->reset();

        $position = 2;
        while ($position + 2 <= $n) {
            if ($bytes[$position] !== "\xff") {
                $position++;
                continue;
            }
            $marker = ord($bytes[$position + 1]);
            $position += 2;
            if ($marker === 0xD9) {
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($position + 2 > $n) {
                break;
            }
            $length = (ord($bytes[$position]) << 8) | ord($bytes[$position + 1]);
            if ($length < 2 || $position + $length > $n) {
                throw new \InvalidArgumentException('truncated JPEG segment');
            }
            $segment = substr($bytes, $position + 2, $length - 2);
            $position += $length;

            switch ($marker) {
                case 0xDB:
                    $this->readQuantisationTables($segment);
                    break;
                case 0xC4:
                    $this->readHuffmanTables($segment);
                    break;
                case 0xDD:
                    $this->restartInterval = (ord($segment[0]) << 8) | ord($segment[1]);
                    break;
                case 0xE2:
                    $this->readIccSegment($segment);
                    break;
                case 0xEE:
                    if (str_starts_with($segment, 'Adobe')) {
                        $this->adobe = true;
                        $this->adobeTransform = strlen($segment) > 11 ? ord($segment[11]) : -1;
                    }
                    break;
                case 0xC0:
                case 0xC1:
                case 0xC2:
                case 0xC3:
                case 0xC5:
                case 0xC6:
                case 0xC7:
                case 0xC9:
                case 0xCA:
                case 0xCB:
                case 0xCD:
                case 0xCE:
                case 0xCF:
                    $this->readFrame($marker, $segment);
                    break;
                case 0xDA:
                    $this->decodeScan($bytes, $segment, $position);
                    break;
                default:
                    break; // APPn, COM, DRI-less metadata: ignored
            }
        }

        if ($this->width === 0 || $this->components === []) {
            throw new \InvalidArgumentException('JPEG has no frame header');
        }

        return $position;
    }

    private function reset(): void
    {
        $this->quant = [];
        $this->huffDc = [];
        $this->huffAc = [];
        $this->components = [];
        $this->order = [];
        $this->planes = [];
        $this->planeW = [];
        $this->planeH = [];
        $this->restartInterval = 0;
        $this->icc = null;
        $this->adobe = false;
        $this->adobeTransform = -1;
        $this->bit = null;
        $this->width = 0;
        $this->height = 0;
        $this->maxH = 1;
        $this->maxV = 1;
    }

    private function readQuantisationTables(string $segment): void
    {
        $p = 0;
        $len = strlen($segment);
        while ($p < $len) {
            $spec = ord($segment[$p++]);
            $precision = $spec >> 4;
            $id = $spec & 15;
            if ($precision > 1) {
                throw new \InvalidArgumentException('bad JPEG quantisation table precision');
            }
            $table = array_fill(0, 64, 0);
            for ($k = 0; $k < 64; $k++) {
                $value = $precision === 1
                    ? ((ord($segment[$p]) << 8) | ord($segment[$p + 1]))
                    : ord($segment[$p]);
                $p += $precision === 1 ? 2 : 1;
                $table[self::ZIGZAG[$k]] = $value;
            }
            $this->quant[$id] = $table;
        }
    }

    private function readHuffmanTables(string $segment): void
    {
        $p = 0;
        $len = strlen($segment);
        while ($p < $len) {
            $spec = ord($segment[$p++]);
            $class = $spec >> 4;
            $id = $spec & 15;
            $bits = [];
            $total = 0;
            for ($i = 0; $i < 16; $i++) {
                $bits[$i + 1] = ord($segment[$p++]);
                $total += $bits[$i + 1];
            }
            $values = [];
            for ($i = 0; $i < $total; $i++) {
                $values[] = ord($segment[$p++]);
            }
            $table = self::buildHuffman($bits, $values);
            if ($class === 0) {
                $this->huffDc[$id] = $table;
            } else {
                $this->huffAc[$id] = $table;
            }
        }
    }

    /**
     * @param array<int,int> $bits counts of codes of each length 1..16
     * @param array<int,int> $values the symbols, in code order
     * @return array<string,mixed>
     */
    private static function buildHuffman(array $bits, array $values): array
    {
        $fast = array_fill(0, 256, null);
        $mincode = array_fill(0, 17, 0);
        $maxcode = array_fill(0, 17, -1);
        $valptr = array_fill(0, 17, 0);
        $code = 0;
        $k = 0;
        for ($len = 1; $len <= 16; $len++) {
            $count = $bits[$len];
            if ($count > 0) {
                $mincode[$len] = $code;
                $maxcode[$len] = $code + $count - 1;
                $valptr[$len] = $k;
                if ($len <= 8) {
                    // Pre-expand every shorter code over the 8-bit prefix
                    // space: one table lookup per symbol instead of eight
                    // bit-at-a-time steps.
                    $fill = 1 << (8 - $len);
                    for ($j = 0; $j < $count; $j++) {
                        $symbol = $values[$k + $j];
                        $prefix = ($code + $j) << (8 - $len);
                        for ($f = 0; $f < $fill; $f++) {
                            $fast[$prefix | $f] = ($len << 8) | $symbol;
                        }
                    }
                }
            }
            $code = ($code + $count) << 1;
            $k += $count;
        }

        return ['fast' => $fast, 'mincode' => $mincode, 'maxcode' => $maxcode, 'valptr' => $valptr, 'values' => $values];
    }

    private function readFrame(int $marker, string $segment): void
    {
        $names = [
            0xC0 => 'baseline sequential', 0xC1 => 'extended sequential', 0xC2 => 'progressive',
            0xC3 => 'lossless', 0xC5 => 'differential sequential', 0xC6 => 'differential progressive',
            0xC7 => 'differential lossless', 0xC9 => 'arithmetic sequential', 0xCA => 'arithmetic progressive',
            0xCB => 'arithmetic lossless', 0xCD => 'differential arithmetic sequential',
            0xCE => 'differential arithmetic progressive', 0xCF => 'differential arithmetic lossless',
        ];
        $name = $names[$marker] ?? 'unknown';
        if ($marker !== 0xC0) {
            throw new \RuntimeException(
                "this JPEG is $name; the bundled decoder handles baseline sequential only "
                . '(install ext-gd, or re-save the image as baseline)'
            );
        }
        $precision = ord($segment[0]);
        if ($precision !== 8) {
            throw new \RuntimeException("this JPEG uses {$precision}-bit samples; the bundled decoder handles 8-bit only");
        }
        $this->height = (ord($segment[1]) << 8) | ord($segment[2]);
        $this->width = (ord($segment[3]) << 8) | ord($segment[4]);
        $count = ord($segment[5]);
        if ($count !== 1 && $count !== 3) {
            throw new \RuntimeException(
                "this JPEG has $count colour components; the bundled decoder handles greyscale and YCbCr only"
            );
        }
        if ($this->width < 1 || $this->height < 1) {
            throw new \InvalidArgumentException('JPEG has zero dimensions');
        }

        $p = 6;
        for ($i = 0; $i < $count; $i++) {
            $id = ord($segment[$p]);
            $hv = ord($segment[$p + 1]);
            $h = $hv >> 4;
            $v = $hv & 15;
            $tq = ord($segment[$p + 2]);
            $p += 3;
            if ($h < 1 || $h > 4 || $v < 1 || $v > 4) {
                throw new \InvalidArgumentException('bad JPEG sampling factors');
            }
            $this->maxH = max($this->maxH, $h);
            $this->maxV = max($this->maxV, $v);
            $this->components[$id] = ['id' => $id, 'h' => $h, 'v' => $v, 'tq' => $tq];
            $this->order[] = $id;
        }
        foreach ($this->components as $component) {
            if ($this->maxH % $component['h'] !== 0 || $this->maxV % $component['v'] !== 0) {
                throw new \RuntimeException(
                    'this JPEG uses sampling factors that are not whole multiples '
                    . "({$component['h']}x{$component['v']} in a {$this->maxH}x{$this->maxV} frame)"
                );
            }
        }
        // Frames precede any scan, so the planes can be sized here.
        $this->ensurePlanes();
    }

    private function readIccSegment(string $segment): void
    {
        if (!str_starts_with($segment, "ICC_PROFILE\x00") || strlen($segment) < 14) {
            return;
        }
        $seq = ord($segment[12]);
        $count = ord($segment[13]);
        if ($seq < 1 || $count < 1 || $seq > $count) {
            return;
        }
        // Single-segment profiles are the overwhelming majority; anything
        // bigger is assembled by ImageMeta, which reads the whole marker list.
        if ($count === 1) {
            $this->icc = substr($segment, 14);
        }
    }

    // -------------------------------------------------------- entropy layer

    /**
     * Decode one scan.  The entropy-coded bytes run until the next real
     * marker; 0xFF00 is a stuffed 0xFF and RSTn only re-aligns the bit
     * stream, so both are stripped here and the byte offsets of the restarts
     * are handed to the bit reader.
     */
    private function decodeScan(string $bytes, string $segment, int &$position): void
    {
        $count = ord($segment[0]);
        $scan = [];
        $p = 1;
        for ($i = 0; $i < $count; $i++) {
            $id = ord($segment[$p]);
            $tables = ord($segment[$p + 1]);
            $p += 2;
            if (!isset($this->components[$id])) {
                throw new \InvalidArgumentException('JPEG scan names an unknown component');
            }
            $scan[] = $id;
            $this->components[$id]['td'] = $tables >> 4;
            $this->components[$id]['ta'] = $tables & 15;
        }
        $clean = '';
        $n = strlen($bytes);
        while ($position < $n) {
            $byte = $bytes[$position];
            if ($byte !== "\xff") {
                $clean .= $byte;
                $position++;
                continue;
            }
            $next = $position + 1 < $n ? $bytes[$position + 1] : '';
            if ($next === "\x00") {
                $clean .= "\xff";
                $position += 2;
                continue;
            }
            if ($next === '') {
                $position++;
                break;
            }
            $marker = ord($next);
            if ($marker >= 0xD0 && $marker <= 0xD7) {
                $position += 2;
                continue;
            }
            break; // a real marker ends the scan
        }

        $this->bit = new JpegBitReader($clean);
        $this->decodeInterleaved($scan);
    }

    private ?JpegBitReader $bit = null;

    private function decodeInterleaved(array $scan): void
    {
        $mcuW = intdiv($this->width + 8 * $this->maxH - 1, 8 * $this->maxH);
        $mcuH = intdiv($this->height + 8 * $this->maxV - 1, 8 * $this->maxV);
        $interleaved = count($scan) > 1;
        if (!$interleaved) {
            $index = array_search($scan[0], $this->order, true);
            $mcuW = intdiv($this->planeW[$index] + 7, 8);
            $mcuH = intdiv($this->planeH[$index] + 7, 8);
        }
        $huffDc = [];
        $huffAc = [];
        $quant = [];
        foreach ($scan as $id) {
            $c = $this->components[$id];
            if (!isset($this->huffDc[$c['td']], $this->huffAc[$c['ta']])) {
                throw new \InvalidArgumentException('JPEG scan references a missing Huffman table');
            }
            $huffDc[$id] = $this->huffDc[$c['td']];
            $huffAc[$id] = $this->huffAc[$c['ta']];
            if (!isset($this->quant[$c['tq']])) {
                throw new \InvalidArgumentException('JPEG frame references a missing quantisation table');
            }
            $quant[$id] = $this->quant[$c['tq']];
        }

        $predictors = array_fill_keys($scan, 0);
        $sinceRestart = 0;
        for ($my = 0; $my < $mcuH; $my++) {
            for ($mx = 0; $mx < $mcuW; $mx++) {
                if ($this->restartInterval > 0 && $sinceRestart === $this->restartInterval) {
                    $this->bit->byteAlign();
                    $predictors = array_fill_keys($scan, 0);
                    $sinceRestart = 0;
                }
                foreach ($scan as $id) {
                    $component = $this->components[$id];
                    $index = array_search($id, $this->order, true);
                    // A single-component scan has one block per MCU, even
                    // when that component has larger sampling factors.
                    $blocksH = $interleaved ? $component['h'] : 1;
                    $blocksV = $interleaved ? $component['v'] : 1;
                    for ($v = 0; $v < $blocksV; $v++) {
                        for ($h = 0; $h < $blocksH; $h++) {
                            $bx = $mx * $blocksH + $h;
                            $by = $my * $blocksV + $v;
                            $block = $this->decodeBlock($huffDc[$id], $huffAc[$id], $quant[$id], $predictors[$id]);
                            $predictors[$id] = $block['dc'];
                            $this->storeBlock($index, $bx, $by, $block['samples']);
                        }
                    }
                }
                $sinceRestart++;
            }
        }
    }

    /**
     * One 8x8 block: Huffman-decode, dequantise and inverse-transform.
     *
     * @param array<string,mixed> $dcTable
     * @param array<string,mixed> $acTable
     * @param array<int,int> $quantTable in natural order
     * @return array{dc:int,samples:array<int,int>}
     */
    private function decodeBlock(array $dcTable, array $acTable, array $quantTable, int $predictor): array
    {
        $coefficients = array_fill(0, 64, 0.0);
        $size = $this->bit->huffman($dcTable);
        $diff = $size === 0 ? 0 : $this->bit->receiveExtend($size);
        $dc = $predictor + $diff;
        $coefficients[0] = $dc * $quantTable[0];

        $k = 1;
        $nonZero = $dc !== 0;
        while ($k < 64) {
            $symbol = $this->bit->huffman($acTable);
            $run = $symbol >> 4;
            $size = $symbol & 15;
            if ($size === 0) {
                if ($run === 15) {
                    $k += 16;
                    continue;
                }
                break; // end of block
            }
            $k += $run;
            if ($k > 63) {
                throw new \InvalidArgumentException('JPEG block runs past coefficient 63');
            }
            $natural = self::ZIGZAG[$k];
            $coefficients[$natural] = $this->bit->receiveExtend($size) * $quantTable[$natural];
            $nonZero = true;
            $k++;
        }

        return ['dc' => $dc, 'samples' => self::idct($coefficients, $nonZero)];
    }

    /**
     * Separable 8x8 inverse DCT on the dequantised coefficients, then level
     * shift and clamp.  A block whose only non-zero coefficient is DC becomes
     * a flat patch, which is worth a special case because it is common.
     *
     * @param array<int,float> $coefficients natural order, row-major
     * @return array<int,int> 64 samples, 0..255
     */
    private static function idct(array $coefficients, bool $nonZero): array
    {
        if (!$nonZero) {
            $level = (int) round($coefficients[0] / 8) + 128;
            $level = $level < 0 ? 0 : ($level > 255 ? 255 : $level);

            return array_fill(0, 64, $level);
        }

        $basis = self::basis();
        $tmp = array_fill(0, 64, 0.0);
        // Coefficients arrive in "natural order": index = vertical * 8 +
        // horizontal.  Pass one fixes the horizontal frequency and runs down
        // the vertical ones, so tmp[y][u] holds one output row per frequency.
        for ($u = 0; $u < 8; $u++) {
            $column = [
                $coefficients[$u], $coefficients[8 + $u], $coefficients[16 + $u], $coefficients[24 + $u],
                $coefficients[32 + $u], $coefficients[40 + $u], $coefficients[48 + $u], $coefficients[56 + $u],
            ];
            for ($y = 0; $y < 8; $y++) {
                $sum = $column[0] * $basis[$y][0];
                for ($v = 1; $v < 8; $v++) {
                    if ($column[$v] !== 0.0) {
                        $sum += $column[$v] * $basis[$y][$v];
                    }
                }
                $tmp[($y << 3) | $u] = $sum;
            }
        }

        // Pass two walks across the horizontal frequencies of each output row.
        $out = array_fill(0, 64, 0);
        for ($y = 0; $y < 8; $y++) {
            $row = [];
            for ($u = 0; $u < 8; $u++) {
                $row[$u] = $tmp[($y << 3) | $u];
            }
            for ($x = 0; $x < 8; $x++) {
                $sum = $row[0] * $basis[$x][0];
                for ($u = 1; $u < 8; $u++) {
                    if ($row[$u] !== 0.0) {
                        $sum += $row[$u] * $basis[$x][$u];
                    }
                }
                $level = (int) round($sum) + 128;
                $out[($y << 3) | $x] = $level < 0 ? 0 : ($level > 255 ? 255 : $level);
            }
        }

        return $out;
    }

    /** @return array<int,array<int,float>> */
    private static function basis(): array
    {
        if (self::$basis === null) {
            $b = [];
            for ($x = 0; $x < 8; $x++) {
                for ($u = 0; $u < 8; $u++) {
                    $c = $u === 0 ? M_SQRT1_2 : 1.0;
                    $b[$x][$u] = 0.5 * $c * cos((2 * $x + 1) * $u * M_PI / 16);
                }
            }
            self::$basis = $b;
        }

        return self::$basis;
    }

    // ---------------------------------------------------------- reassembly

    private function ensurePlanes(): void
    {
        foreach ($this->order as $index => $id) {
            $c = $this->components[$id];
            $cw = intdiv($this->width * $c['h'] + $this->maxH - 1, $this->maxH);
            $ch = intdiv($this->height * $c['v'] + $this->maxV - 1, $this->maxV);
            $this->planeW[$index] = $cw;
            $this->planeH[$index] = $ch;
            $this->planes[$index] = array_fill(0, $ch, str_repeat("\x00", $cw));
        }
    }

    /** @param array<int,int> $samples */
    private function storeBlock(int $index, int $bx, int $by, array $samples): void
    {
        $cw = $this->planeW[$index];
        $ch = $this->planeH[$index];
        $x0 = $bx * 8;
        $y0 = $by * 8;
        $limit = min(8, $cw - $x0);
        if ($limit <= 0) {
            return;
        }
        for ($y = 0; $y < 8; $y++) {
            $py = $y0 + $y;
            if ($py >= $ch) {
                break;
            }
            $slice = '';
            $rowBase = $y << 3;
            for ($x = 0; $x < $limit; $x++) {
                $slice .= chr($samples[$rowBase | $x]);
            }
            $this->planes[$index][$py] = substr_replace($this->planes[$index][$py], $slice, $x0, $limit);
        }
    }

    /**
     * Upsample each component to display size and convert to RGB.
     */
    private function toRgb(): string
    {
        $planes = [];
        foreach ($this->order as $index => $id) {
            $c = $this->components[$id];
            $fx = intdiv($this->maxH, $c['h']);
            $fy = intdiv($this->maxV, $c['v']);
            $planes[$index] = $this->upsample(
                implode('', $this->planes[$index]),
                $this->planeW[$index],
                $this->planeH[$index],
                $fx,
                $fy
            );
        }

        $w = $this->width;
        $h = $this->height;
        if (count($planes) === 1) {
            $y = $planes[0];
            $rgb = '';
            for ($i = 0, $n = $w * $h; $i < $n; $i++) {
                $rgb .= $y[$i] . $y[$i] . $y[$i];
            }

            return $rgb;
        }

        $y = $planes[0];
        $cb = $planes[1];
        $cr = $planes[2];

        // Three components are usually YCbCr, but an Adobe APP14 marker with
        // transform 0, or component ids that spell "RGB", means the samples
        // are already red/green/blue.
        if (($this->adobe && $this->adobeTransform === 0) || $this->looksLikeRgb()) {
            $rgb = '';
            for ($i = 0, $n = $w * $h; $i < $n; $i++) {
                $rgb .= $y[$i] . $cb[$i] . $cr[$i];
            }

            return $rgb;
        }

        $rgb = '';
        for ($i = 0, $n = $w * $h; $i < $n; $i++) {
            $yy = ord($y[$i]);
            $u = ord($cb[$i]) - 128;
            $v = ord($cr[$i]) - 128;
            $r = $yy + (91881 * $v >> 16);
            $g = $yy - ((22554 * $u + 46802 * $v) >> 16);
            $b = $yy + (116130 * $u >> 16);
            $rgb .= chr($r < 0 ? 0 : ($r > 255 ? 255 : $r))
                . chr($g < 0 ? 0 : ($g > 255 ? 255 : $g))
                . chr($b < 0 ? 0 : ($b > 255 ? 255 : $b));
        }

        return $rgb;
    }

    /**
     * Nearest-neighbour for 1x, libjpeg's "fancy" triangle filter for 2x.
     * Chroma is low frequency, so the triangle filter is what keeps 4:2:0
     * looking smooth instead of blocky at the 2x2 seams.
     */
    private function upsample(string $plane, int $cw, int $ch, int $fx, int $fy): string
    {
        if ($fx === 1 && $fy === 1 && $cw === $this->width && $ch === $this->height) {
            return $plane;
        }

        // horizontal
        if ($fx === 1) {
            $horizontal = $plane;
            $hW = $cw;
        } elseif ($fx === 2) {
            $hW = $this->width;
            $horizontal = '';
            for ($y = 0; $y < $ch; $y++) {
                $base = $y * $cw;
                $prev = ord($plane[$base]);
                $row = '';
                for ($x = 0; $x < $cw; $x++) {
                    $current = ord($plane[$base + $x]);
                    $next = $x + 1 < $cw ? ord($plane[$base + $x + 1]) : $current;
                    $row .= chr((3 * $current + $prev + 2) >> 2) . chr((3 * $current + $next + 2) >> 2);
                    $prev = $current;
                }
                // Doubling ceil(w/2) samples can overshoot by one when the
                // display width is odd; every row must be exactly w wide or
                // the vertical pass reads the wrong stride.
                $horizontal .= substr($row, 0, $this->width);
            }
        } else {
            $hW = $this->width;
            $horizontal = '';
            for ($y = 0; $y < $ch; $y++) {
                $base = $y * $cw;
                for ($x = 0; $x < $this->width; $x++) {
                    $sx = intdiv($x, $fx);
                    $horizontal .= $plane[$base + $sx];
                }
            }
        }

        if ($fy === 1) {
            return $horizontal;
        }

        if ($fy === 2) {
            $out = '';
            for ($y = 0; $y < $this->height; $y++) {
                $row = intdiv($y, 2);
                $other = $y % 2 === 0 ? max(0, $row - 1) : min($ch - 1, $row + 1);
                $a = substr($horizontal, $row * $hW, $hW);
                $b = substr($horizontal, $other * $hW, $hW);
                for ($x = 0; $x < $hW; $x++) {
                    $out .= chr((3 * ord($a[$x]) + ord($b[$x]) + 2) >> 2);
                }
            }

            return $out;
        }

        $out = '';
        for ($y = 0; $y < $this->height; $y++) {
            $src = min($ch - 1, intdiv($y, $fy));
            $out .= substr($horizontal, $src * $hW, $hW);
        }

        return $out;
    }

    /**
     * Component ids 'R', 'G', 'B' mean the three samples are already RGB.
     * libjpeg applies the same rule, and files like that exist in the wild
     * despite not being in the standard.
     */
    private function looksLikeRgb(): bool
    {
        return count($this->order) === 3
            && $this->order[0] === 0x52 && $this->order[1] === 0x47 && $this->order[2] === 0x42;
    }
}

/**
 * Bit reader over one scan's entropy-coded bytes, with the restart markers
 * already removed but their byte offsets remembered so the decoder can
 * re-align where the encoder did.
 */
final class JpegBitReader
{
    private string $data;
    private int $length;
    private int $position = 0;
    private int $accumulator = 0;
    private int $available = 0;
    public function __construct(string $data)
    {
        $this->data = $data;
        $this->length = strlen($data);
    }

    private function fill(int $bits): void
    {
        while ($this->available < $bits) {
            $byte = $this->position < $this->length ? ord($this->data[$this->position++]) : 0;
            $this->accumulator = ($this->accumulator << 8) | $byte;
            $this->available += 8;
        }
    }

    private function take(int $bits): int
    {
        if ($bits === 0) {
            return 0;
        }
        $this->fill($bits);
        $this->available -= $bits;
        $value = ($this->accumulator >> $this->available) & ((1 << $bits) - 1);
        $this->accumulator &= (1 << $this->available) - 1;

        return $value;
    }

    /** The encoder pads to a byte boundary before each restart marker. */
    public function byteAlign(): void
    {
        $drop = $this->available % 8;
        if ($drop !== 0) {
            $this->available -= $drop;
            $this->accumulator &= (1 << $this->available) - 1;
        }
    }

    /** @param array<string,mixed> $table */
    public function huffman(array $table): int
    {
        $this->fill(8);
        $prefix = ($this->accumulator >> ($this->available - 8)) & 0xFF;
        $entry = $table['fast'][$prefix];
        if ($entry !== null) {
            $this->available -= $entry >> 8;
            $this->accumulator &= (1 << $this->available) - 1;

            return $entry & 0xFF;
        }

        // Longer than eight bits: walk the remaining lengths.
        $code = $this->take(8);
        for ($length = 9; $length <= 16; $length++) {
            $code = ($code << 1) | $this->take(1);
            if ($table['maxcode'][$length] >= 0 && $code <= $table['maxcode'][$length]) {
                return $table['values'][$table['valptr'][$length] + $code - $table['mincode'][$length]];
            }
        }

        throw new \InvalidArgumentException('invalid JPEG Huffman code');
    }

    /** Extend a magnitude-coded value to its signed representation. */
    public function receiveExtend(int $size): int
    {
        if ($size === 0) {
            return 0;
        }
        $value = $this->take($size);
        if ($value < (1 << ($size - 1))) {
            $value -= (1 << $size) - 1;
        }

        return $value;
    }
}
