<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

require_once __DIR__ . '/ImageInput.php';

/**
 * Staged, padded Y/U/V planes for the chunked encoder.
 *
 * The whole-frame encoder holds a full 1.5 bytes/pixel YUV copy of the image
 * (plus the reconstruction).  For a 12 MP photo that is ~18 MB on top of the
 * decoder's own buffer.  The chunked path instead writes the padded planes to
 * disk once, then every chunk process fseeks to the rows it needs and keeps
 * only that band in memory.  The padding rules are copied from
 * Vp8LossyEncoder::loadRgb() and are bit-exact with it.
 */
final class PlaneStore
{
    private const Y_FILE = 'planes.y';
    private const U_FILE = 'planes.u';
    private const V_FILE = 'planes.v';
    private const A_FILE = 'planes.a';
    private const ICC_FILE = 'icc.bin';
    public const META_FILE = 'meta.json';

    /** @var array<string,int|string> */
    private array $meta;

    /** @param array<string,mixed> $meta */
    public function __construct(private string $dir, array $meta)
    {
        $this->meta = $meta;
    }

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return $this->meta;
    }

    public static function open(string $dir): self
    {
        $meta = json_decode((string) file_get_contents($dir . '/' . self::META_FILE), true);
        if (!is_array($meta)) {
            throw new \RuntimeException("no staging meta in $dir");
        }

        return new self($dir, $meta);
    }

    /**
     * Decode the source image once and stream the padded planes to disk.
     *
     * Peak memory is the decoded RGB buffer (three bytes per pixel) plus one
     * chroma row pair; the plane geometry itself never lands in memory.  This
     * is the one step of the chunked path that still scales with the image,
     * which is why a job queue wants to cap megapixels here.
     *
     * @return array<string,mixed> the meta that was written
     */
    public static function prepare(string $imagePath, string $dir): array
    {
        $meta = ImageMeta::read($imagePath);
        [$w, $h] = [$meta->width, $meta->height];
        // Shared with ImageInput, so single-shot and staged encoding always
        // see the same pixels: palette indices resolved, tRNS applied, raw
        // alpha, and whichever reader is appropriate for the host.
        $pixels = ImageInput::pixels($imagePath);
        $rgbSource = $pixels['rgb'];
        $alphaSource = $pixels['alpha'];
        // Transparency travels in its own chunk, not in the colour planes, so
        // it is staged in its own file.  It used to be dropped here silently.
        $probeAlpha = $alphaSource !== null;

        $wp = (int) (($w + 15) & ~15);
        $hp = (int) (($h + 15) & ~15);
        $cw = $wp >> 1;
        $ch = $hp >> 1;
        $chromaVisible = ($w + 1) >> 1;
        $visibleChromaRows = (int) (($h + 1) >> 1);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("cannot create $dir");
        }
        $yF = fopen($dir . '/' . self::Y_FILE, 'wb');
        $uF = fopen($dir . '/' . self::U_FILE, 'wb');
        $vF = fopen($dir . '/' . self::V_FILE, 'wb');
        if ($yF === false || $uF === false || $vF === false) {
            throw new \RuntimeException("cannot write planes in $dir");
        }
        $aF = $probeAlpha ? fopen($dir . '/' . self::A_FILE . '.tmp', 'wb') : false;
        $alphaSeen = false;

        // Chroma row c averages luma rows (2c, 2c+1), the same block libwebp's
        // ConvertARGBToUV_C averages.  Keep this in lock-step with loadRgb(),
        // otherwise the bitstream is not chunk-identical.
        $pendingA = null;
        $pendingC = -1;
        $lastRow = '';
        $lastLumaRow = '';
        $chromaWritten = 0;
        for ($r = 0; $r < $h; $r++) {
            $row = substr($rgbSource, $r * $w * 3, $w * 3);
            $luma = '';
            for ($i = 0; $i < $w; $i++) {
                $o = $i * 3;
                $luma .= chr((16839 * ord($row[$o]) + 33059 * ord($row[$o + 1]) + 6420 * ord($row[$o + 2]) + (16 << 16) + 32768) >> 16);
            }
            $luma .= str_repeat($luma[$w - 1], $wp - $w);
            fwrite($yF, $luma);
            $lastLumaRow = $luma;

            if ($aF !== false) {
                $alphaRow = substr((string) $alphaSource, $r * $w, $w);
                if (strspn($alphaRow, "\xff") !== $w) {
                    $alphaSeen = true;
                }
                fwrite($aF, $alphaRow);
            }

            if (($r & 1) === 0) {
                $pendingA = $row;
                $pendingC = $r >> 1;
            } elseif ($pendingA !== null && $pendingC === ($r >> 1)) {
                $chroma = self::chromaRow($pendingA, $row, $w, $cw, $chromaVisible);
                fwrite($uF, $chroma[0]);
                fwrite($vF, $chroma[1]);
                $chromaWritten++;
                $pendingA = null;
            }
            $lastRow = $row;
        }
        // Rows whose pair falls off the bottom edge clamp to the last luma row
        // (this also covers the chroma padding rows).
        $edge = self::chromaRow($lastRow, $lastRow, $w, $cw, $chromaVisible);
        for ($c = $chromaWritten; $c < $ch; $c++) {
            fwrite($uF, $edge[0]);
            fwrite($vF, $edge[1]);
        }
        unset($rgbSource, $alphaSource);
        if ($aF !== false) {
            fclose($aF);
            if ($alphaSeen) {
                rename($dir . '/' . self::A_FILE . '.tmp', $dir . '/' . self::A_FILE);
            } else {
                unlink($dir . '/' . self::A_FILE . '.tmp');
            }
        }

        // Edge replication below the image and to the right of the visible
        // chroma columns, matching loadRgb().
        for ($r = $h; $r < $hp; $r++) {
            fwrite($yF, $lastLumaRow);
        }
        fclose($yF);
        fclose($uF);
        fclose($vF);

        // The colour profile is metadata for the container, not for the
        // planes: stage it beside them so the closing tick can still attach it
        // without holding the whole image.
        $iccBytes = 0;
        if ($meta->icc !== null) {
            file_put_contents($dir . '/' . self::ICC_FILE, $meta->icc);
            $iccBytes = strlen($meta->icc);
        }

        $report = [
            'source' => $imagePath,
            'source_bytes' => filesize($imagePath),
            'w' => $w,
            'h' => $h,
            'wp' => $wp,
            'hp' => $hp,
            'cw' => $cw,
            'ch' => $ch,
            'mbw' => $wp >> 4,
            'mbh' => $hp >> 4,
            'has_alpha' => $alphaSeen,
            'has_icc' => $iccBytes > 0,
            'icc_bytes' => $iccBytes,
            'plane_bytes' => [
                'y' => filesize($dir . '/' . self::Y_FILE),
                'u' => filesize($dir . '/' . self::U_FILE),
                'v' => filesize($dir . '/' . self::V_FILE),
            ],
        ];
        file_put_contents($dir . '/' . self::META_FILE, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report;
    }

    /**
     * The staged ICC profile, or null when the source had none.
     */
    public function iccProfile(): ?string
    {
        if (empty($this->meta['has_icc'])) {
            return null;
        }
        $path = $this->dir . '/' . self::ICC_FILE;
        if (!is_file($path)) {
            return null;
        }

        return (string) file_get_contents($path);
    }

    /**
     * The staged alpha plane (display size, one byte per pixel), or null when
     * the source is fully opaque.
     */
    public function alphaPlane(): ?string
    {
        if (empty($this->meta['has_alpha'])) {
            return null;
        }
        $path = $this->dir . '/' . self::A_FILE;
        if (!is_file($path)) {
            return null;
        }

        return (string) file_get_contents($path);
    }

    /**
     * Read the planes for macroblock rows [$topMby, $topMby + $mbRows).
     *
     * @return array{0:string,1:string,2:string} [y, u, v]
     */
    public function readBand(int $topMby, int $mbRows): array
    {
        $wp = (int) $this->meta['wp'];
        $cw = (int) $this->meta['cw'];
        $y = self::readRows($this->dir . '/' . self::Y_FILE, $topMby << 4, $mbRows * 16, $wp);
        $u = self::readRows($this->dir . '/' . self::U_FILE, $topMby << 3, $mbRows * 8, $cw);
        $v = self::readRows($this->dir . '/' . self::V_FILE, $topMby << 3, $mbRows * 8, $cw);

        return [$y, $u, $v];
    }

    private static function readRows(string $file, int $firstRow, int $rows, int $stride): string
    {
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("cannot read $file");
        }
        fseek($fh, $firstRow * $stride);
        $data = (string) fread($fh, $rows * $stride);
        fclose($fh);
        if (strlen($data) !== $rows * $stride) {
            throw new \RuntimeException("short read in $file");
        }

        return $data;
    }

    /**
     * @return array{0:string,1:string} [u row, v row] for one chroma row
     */
    private static function chromaRow(string $rowA, string $rowB, int $w, int $cw, int $chromaVisible): array
    {
        $u = '';
        $v = '';
        for ($i = 0; $i < $chromaVisible; $i++) {
            $i0 = (($i << 1) < $w ? ($i << 1) : $w - 1) * 3;
            $i1 = (((($i << 1) + 1) < $w) ? (($i << 1) + 1) : $w - 1) * 3;
            $sumU = 0;
            $sumV = 0;
            foreach ([[$rowA, $i0], [$rowA, $i1], [$rowB, $i0], [$rowB, $i1]] as [$row, $o]) {
                $r = ord($row[$o]);
                $g = ord($row[$o + 1]);
                $b = ord($row[$o + 2]);
                $sumU += -9719 * $r - 19081 * $g + 28800 * $b;
                $sumV += 28800 * $r - 24116 * $g - 4684 * $b;
            }
            $uu = ((($sumU + 2) >> 2) + (128 << 16) + 32768) >> 16;
            $vv = ((($sumV + 2) >> 2) + (128 << 16) + 32768) >> 16;
            $u .= chr($uu < 0 ? 0 : ($uu > 255 ? 255 : $uu));
            $v .= chr($vv < 0 ? 0 : ($vv > 255 ? 255 : $vv));
        }

        return [
            $u . str_repeat($u[$chromaVisible - 1], $cw - $chromaVisible),
            $v . str_repeat($v[$chromaVisible - 1], $cw - $chromaVisible),
        ];
    }
}
