<?php
declare(strict_types=1);

namespace Sonoquill\Podlove;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Auphonic\ChapterFormat;
use Sonoquill\Db\EpisodeRepository;

/**
 * The episode as a draft in Podlove.
 *
 * Exclusively through Podlove's own PHP API. Writing directly to the Podlove
 * tables breaks with every update, so this class never does it.
 *
 * The post is created as a draft and is never published. This is the second
 * of the two human gates.
 */
final class EpisodeDraft
{

    public const POST_TYPE = 'podcast';

    /** Every episode gets this category. */
    public const CATEGORY_SLUG = 'podcast';

    /**
     * Creates or updates the post and the Podlove episode.
     *
     * @throws \RuntimeException
     */
    public static function upsert(int $episodeId): int
    {
        if (!class_exists('\\Podlove\\Model\\Episode')) {
            throw new \RuntimeException(__('Podlove is not available.', 'sonoquill'));
        }

        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            /* translators: %d: episode ID */
            throw new \RuntimeException(sprintf(__('Episode %d does not exist.', 'sonoquill'), $episodeId));
        }

        $title = trim((string) ($episode['episode_title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException(__('The episode has no title.', 'sonoquill'));
        }

        $postId = (int) ($episode['podlove_post_id'] ?? 0);
        $content = self::content($episode);

        if ($postId > 0 && get_post($postId) !== null) {
            wp_update_post([
                'ID'           => $postId,
                'post_title'   => $title,
                'post_content' => $content,
            ]);
        } else {
            $postId = (int) wp_insert_post([
                'post_type'    => self::POST_TYPE,
                // Draft, never published: the second human gate.
                'post_status'  => 'draft',
                'post_title'   => $title,
                'post_content' => $content,
            ], true);

            if ($postId <= 0) {
                throw new \RuntimeException(__('The post could not be created.', 'sonoquill'));
            }
        }

        self::ensureCategory($postId);

        $podlove = \Podlove\Model\Episode::find_or_create_by_post_id($postId);

        $slug = self::slugFor($episode);

        $chapters = ChapterFormat::toText(EpisodeRepository::decodeList($episode['chapters'] ?? null));
        $duration = (int) ($episode['duration_ms'] ?? 0);

        $podlove->update_attributes([
            'slug'     => $slug,
            'subtitle' => mb_substr((string) ($episode['description_short'] ?? ''), 0, 255),
            'summary'  => (string) ($episode['description_long'] ?? ''),
            'number'   => self::episodeNumber($podlove),
            'duration' => $duration > 0 ? gmdate('H:i:s', (int) round($duration / 1000)) : '',
            'chapters' => $chapters,
            'type'     => 'full',
        ]);

        EpisodeRepository::update($episodeId, [
            'podlove_post_id' => $postId,
            'podlove_slug'    => $slug,
        ]);

        return $postId;
    }

    /**
     * The slug of an episode: the stored one, otherwise built from the title
     * and passed through the filter `sonoquill_episode_slug`. The filter only
     * runs when the slug is first generated — the slug is also the file name,
     * and it must not change between Auphonic, the media file and Podlove.
     *
     * @param array<string,mixed> $episode
     */
    public static function slugFor(array $episode): string
    {
        $stored = (string) ($episode['podlove_slug'] ?? '');
        if ($stored !== '') {
            return $stored;
        }

        $slug = SlugBuilder::fromTitle(trim((string) ($episode['episode_title'] ?? '')), \Sonoquill\Settings\Options::filePrefix());

        /**
         * Filter: the slug (and file name) of a new episode.
         *
         * @param string              $slug    Built from the title, or prefix plus date.
         * @param array<string,mixed> $episode The episode row.
         */
        $filtered = apply_filters('sonoquill_episode_slug', $slug, $episode);
        $filtered = is_string($filtered) ? (preg_replace('/[^A-Za-z0-9_-]/', '', $filtered) ?? '') : '';

        return $filtered !== '' ? mb_substr($filtered, 0, 191) : $slug;
    }

    /**
     * The "Podcast" category is checked on every episode — otherwise the host
     * had to add it by hand afterwards. The WordPress default category, which
     * wp_insert_post sets on its own, is removed in the process.
     */
    private static function ensureCategory(int $postId): void
    {
        $term = get_term_by('slug', self::CATEGORY_SLUG, 'category');
        if (!$term instanceof \WP_Term) {
            return;
        }

        $current = array_map('intval', wp_get_post_categories($postId));
        $default = (int) get_option('default_category');
        $wanted = array_values(array_unique(array_merge(
            array_filter($current, static fn (int $id): bool => $id !== $default),
            [(int) $term->term_id]
        )));

        sort($current);
        sort($wanted);
        if ($current !== $wanted) {
            wp_set_post_categories($postId, $wanted);
        }
    }

    /**
     * Links the stored files as Podlove assets.
     *
     * @return list<string> What was linked, for the run log.
     */
    public static function attachAssets(int $episodeId): array
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null) {
            return [];
        }

        $postId = (int) ($episode['podlove_post_id'] ?? 0);
        if ($postId <= 0) {
            return [];
        }

        $podlove = \Podlove\Model\Episode::find_or_create_by_post_id($postId);
        $done = [];

        foreach (['mp3', 'vtt'] as $extension) {
            // The asset is found by file type; installations number their assets differently.
            $asset = MediaStore::asset($extension);
            $path = MediaStore::path((string) $episode['podlove_slug'], $extension);
            if ($asset === null || !is_readable($path)) {
                continue;
            }

            $mediaFile = \Podlove\Model\MediaFile::find_or_create_by_episode_id_and_episode_asset_id(
                $podlove->id,
                $asset['id']
            );

            $mediaFile->size = (string) filesize($path);
            $mediaFile->save();

            $done[] = sprintf('%s (%s)', $extension, size_format((int) filesize($path)));
        }

        return $done;
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function content(array $episode): string
    {
        $parts = [];

        $long = trim((string) ($episode['description_long'] ?? ''));
        if ($long !== '') {
            $parts[] = $long;
        }

        $chapters = EpisodeRepository::decodeList($episode['chapters'] ?? null);
        if ($chapters !== []) {
            $lines = [];
            foreach ($chapters as $chapter) {
                $lines[] = sprintf(
                    '%s — %s',
                    ChapterFormat::timecode((int) ($chapter['start_ms'] ?? 0)),
                    (string) ($chapter['titel'] ?? '')
                );
            }
            $parts[] = "<h3>Kapitel</h3>\n<ul><li>" . implode("</li>\n<li>", array_map('esc_html', $lines)) . '</li></ul>';
        }

        $sources = \Sonoquill\Source\Sources::forEpisode($episode)->shownotesHtml($episode);
        if ($sources !== '') {
            $parts[] = $sources;
        }

        // The disclosure comes at the end, set apart from the content.
        $disclosure = trim((string) ($episode['ai_disclosure_text'] ?? ''));
        if ($disclosure !== '') {
            $parts[] = "<hr />\n<p class=\"sonoquill-disclosure\"><small>"
                . nl2br(esc_html($disclosure))
                . '</small></p>';
        }

        return implode("\n\n", $parts);
    }

    /**
     * The episode number.
     *
     * Podlove already assigns it itself when the post is created. If you then
     * asked again for the next free number, the one just assigned would be
     * counted and the episode would move up by one — exactly that happened
     * with the first draft (68 instead of 67). An existing number is therefore
     * kept, and a new one is fetched only if there is none.
     */
    private static function episodeNumber(\Podlove\Model\Episode $podlove): string
    {
        $existing = trim((string) ($podlove->number ?? ''));
        if ($existing !== '' && $existing !== '0') {
            return $existing;
        }

        if (!method_exists('\\Podlove\\Model\\Episode', 'get_next_episode_number')) {
            return '';
        }

        $next = \Podlove\Model\Episode::get_next_episode_number();

        return is_numeric($next) ? (string) (int) $next : '';
    }
}
