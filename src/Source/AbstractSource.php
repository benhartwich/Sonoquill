<?php
declare(strict_types=1);

namespace Sonoquill\Source;

/**
 * Neutral defaults for everything a simple source does not need.
 */
abstract class AbstractSource implements Source, SourcePreferences
{
    public function usesNumberGate(array $episode): bool
    {
        return true;
    }

    public function prefersBatch(array $episode): ?bool
    {
        return null;
    }

    public function preparesText(): bool
    {
        return false;
    }

    public function runningNotice(array $episode): array
    {
        return [
            /* translators: %s: label of the episode source */
            sprintf(__('%s is being prepared', 'sonoquill'), $this->label()),
            __('This runs in the background. Once the text is ready, it continues on its own.', 'sonoquill'),
        ];
    }

    public function staleAfterSeconds(): int
    {
        return 0;
    }

    public function resume(int $episodeId): string
    {
        return '';
    }

    public function panelTitle(array $episode): string
    {
        return '';
    }

    public function renderPanel(array $episode): void
    {
    }

    public function renderAside(array $episode): void
    {
    }

    public function figure(array $episode): ?array
    {
        return null;
    }

    public function checklist(array $episode, array $acknowledged): array
    {
        return [];
    }

    public function blockingKeys(array $episode): array
    {
        return [];
    }

    public function shownotesHtml(array $episode): string
    {
        return '';
    }

    public function mailLines(array $episode): array
    {
        return [];
    }

    public function shortName(array $episode): string
    {
        return '';
    }
}
