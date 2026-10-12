<?php
declare(strict_types=1);

namespace Sonoquill\Tests;

use PHPUnit\Framework\TestCase;
use Sonoquill\Settings\Options;
use Sonoquill\Source\Sources;

/**
 * Base for tests that run code calling WordPress functions: fresh options,
 * hooks, HTTP stubs and an empty in-memory episodes table per test.
 */
abstract class WpTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Wp::reset();
        Sources::reset();
    }

    /**
     * @param array<string,mixed> $values
     */
    protected function settings(array $values): void
    {
        $stored = get_option(Options::OPTION, []);
        update_option(Options::OPTION, array_merge(is_array($stored) ? $stored : [], $values));
    }

    protected function tearDown(): void
    {
        if (Wp::$uploads !== '' && is_dir(Wp::$uploads)) {
            exec('rm -rf ' . escapeshellarg(Wp::$uploads));
        }
        parent::tearDown();
    }

    /**
     * An approved episode with segments whose audio exists, ready for
     * Montage::run() on the PHP path (no ffmpeg needed).
     *
     * @param array<string,mixed> $fields
     */
    protected function montageReadyEpisode(array $fields = [], int $segments = 2): int
    {
        $this->settings(['montage_mode' => 'php']);
        $id = $this->episode($fields + [
            'status'        => \Sonoquill\Db\EpisodeStatus::TEXT_APPROVED,
            'episode_title' => 'Test',
        ]);

        $tone = dirname(__DIR__) . '/tests/fixtures/audio/tone-mono.mp3';
        $ms = (int) \Sonoquill\Audio\AudioEngine::durationMs($tone);
        for ($i = 0; $i < $segments; $i++) {
            $relative = \Sonoquill\Storage\EpisodeStorage::segmentRelativePath($id, $i);
            \Sonoquill\Storage\EpisodeStorage::write($relative, (string) file_get_contents($tone));
            \Sonoquill\Segments\SegmentRepository::insert([
                'episode_id'    => $id,
                'idx'           => $i,
                'text'          => 'Segment ' . $i,
                'kind'          => $i === 0 ? 'intro' : 'text',
                'chapter_title' => $i === 0 ? 'Start' : '',
                'audio_path'    => $relative,
                'source'        => 'tts',
                'duration_ms'   => $ms,
                'status'        => 'erzeugt',
            ]);
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $fields
     */
    protected function episode(array $fields = []): int
    {
        return \Sonoquill\Db\EpisodeRepository::create($fields);
    }
}
