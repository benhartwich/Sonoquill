<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Podlove\EpisodeDraft;
use Sonoquill\Tests\WpTestCase;

/**
 * The filter sonoquill_episode_slug for newly generated slugs.
 */
final class EpisodeSlugFilterTest extends WpTestCase
{
    public function testFilterReceivesTheEpisodeAndIsSanitised(): void
    {
        $this->settings(['file_prefix' => 'Brief']);
        $seen = null;
        add_filter('sonoquill_episode_slug', static function (string $slug, array $episode) use (&$seen): string {
            $seen = [$slug, $episode['id']];

            return 'lyst/' . $episode['id'] . ' ok';
        }, 10, 2);

        $slug = EpisodeDraft::slugFor(['id' => 5, 'episode_title' => 'Лист']);

        self::assertSame(['BriefLyst', 5], $seen);
        self::assertSame('lyst5ok', $slug);
    }

    public function testStoredSlugIsNotFilteredAgain(): void
    {
        add_filter('sonoquill_episode_slug', static fn (): string => 'other');

        self::assertSame('Kept', EpisodeDraft::slugFor(['id' => 1, 'podlove_slug' => 'Kept', 'episode_title' => 'x']));
    }

    public function testEmptyFilterResultKeepsTheBuiltSlug(): void
    {
        $this->settings(['file_prefix' => 'Brief']);
        add_filter('sonoquill_episode_slug', static fn (): string => '///');

        self::assertSame('BriefHallo', EpisodeDraft::slugFor(['id' => 1, 'episode_title' => 'Hallo']));
    }
}
