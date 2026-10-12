<?php
declare(strict_types=1);

namespace Sonoquill\Source;

/**
 * Optional per-episode preferences of a source.
 *
 * Kept separate from Source so that add-ons implementing Source directly
 * keep working: the core only asks a source that implements this interface,
 * otherwise the settings apply. AbstractSource implements it with neutral
 * defaults, so a source extending it only overrides what it needs.
 */
interface SourcePreferences
{
    /**
     * Should the hard number gate (NumberDiff) run for this episode?
     *
     * A source whose text is not a fact script — a personal letter, a
     * story — can switch it off. Fact check and ScriptGuard still run.
     *
     * @param array<string,mixed> $episode
     */
    public function usesNumberGate(array $episode): bool;

    /**
     * Batch API for the model calls of this episode: true, false, or null
     * for "as configured" (setting `anthropic_batch`). A source that
     * produces a daily episode at a fixed time cannot wait an hour.
     *
     * @param array<string,mixed> $episode
     */
    public function prefersBatch(array $episode): ?bool;
}
