<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

/** Test-only counterpart of BoolEncoder (RFC 6386 section 7.3). */

final class BoolDecoder
{
    private string $in;
    private int $pos = 0;
    private int $range = 255;
    private int $value = 0;
    private int $bitCount = 0;

    public bool $overrun = false;
    /** Set to [] to capture the exact (prob, bit) sequence being decoded. */
    public ?array $log = null;

    public function bytesConsumed(): int
    {
        return $this->pos;
    }

    public function size(): int
    {
        return strlen($this->in);
    }

    public function __construct(string $data)
    {
        $this->in = $data;
        for ($i = 0; $i < 2; $i++) {
            $this->value = (($this->value << 8) | $this->nextByte()) & 0xFFFFFFFF;
        }
    }

    private function nextByte(): int
    {
        if ($this->pos >= strlen($this->in)) {
            $this->overrun = true;

            return 0;
        }

        return ord($this->in[$this->pos++]);
    }

    public function readBool(int $prob): int
    {
        $split = 1 + ((($this->range - 1) * $prob) >> 8);
        $bigSplit = ($split << 8) & 0xFFFFFFFF;

        if ($this->value >= $bigSplit) {
            $ret = 1;
            $this->range -= $split;
            $this->value = ($this->value - $bigSplit) & 0xFFFFFFFF;
        } else {
            $ret = 0;
            $this->range = $split;
        }
        if ($this->log !== null) {
            $this->log[] = [$prob, $ret];
        }

        while ($this->range < 128) {
            $this->value = ($this->value << 1) & 0xFFFF;
            $this->range <<= 1;
            if (++$this->bitCount === 8) {
                $this->bitCount = 0;
                $this->value |= $this->nextByte();
            }
        }

        return $ret;
    }

    public function readLiteral(int $bits): int
    {
        $v = 0;
        while ($bits--) {
            $v = ($v << 1) + $this->readBool(128);
        }

        return $v;
    }
}
