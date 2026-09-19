<?php

declare(strict_types=1);

namespace PicoWebP\Vp8;

require_once __DIR__ . '/ImageMeta.php';

/**
 * Thrown when a source turns out to be something this package should leave
 * alone rather than convert.
 *
 * There is one such case today, and it is not a failure: **the input is
 * already a WebP.**  The job a converter is normally handed — "make this
 * smaller than the PNG/JPEG it is now" — is already done, and re-encoding it
 * would cost CPU to lose quality on already-coded pixels.  So the loaders
 * report it as a skip and let the caller decide:
 *
 * ```php
 * try {
 *     $pixels = ImageInput::pixels($file);
 * } catch (SkippedInput $e) {
 *     // Nothing to do, and nothing wrong: $e->reason() says why.
 *     continue;
 * }
 * ```
 *
 * It extends RuntimeException so that a caller which does not care about the
 * distinction keeps working exactly as it did, and `ImageInput::$skipWebp`
 * turns the whole behaviour off for the caller who really does want to
 * re-encode a WebP (which needs GD to decode it).
 */
final class SkippedInput extends \RuntimeException
{
    public function __construct(
        string $message,
        /** The path that was skipped. */
        public readonly string $path = '',
        /** What the file actually is, when it was inspected. */
        public readonly ?ImageMeta $meta = null
    ) {
        parent::__construct($message);
    }

    public static function webp(string $path, ?ImageMeta $meta = null): self
    {
        return new self(
            basename($path) . ' is already a WebP; there is nothing to convert',
            $path,
            $meta
        );
    }

    /**
     * The machine-readable reason, for callers building their own report or
     * storing the outcome of a queued job.
     */
    public function reason(): string
    {
        return 'webp (already the target format)';
    }

    /** A short slug for logs and job statuses. */
    public function slug(): string
    {
        return 'skipped_webp';
    }
}
