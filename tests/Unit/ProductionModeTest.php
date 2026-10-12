<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Health\Checks\AuphonicCheck;
use Sonoquill\Health\Result;
use Sonoquill\Pipeline\Montage;
use Sonoquill\Pipeline\Publish;
use Sonoquill\Pipeline\Scheduler;
use Sonoquill\Storage\EpisodeStorage;
use Sonoquill\Tests\Wp;
use Sonoquill\Tests\WpTestCase;

/**
 * Production mode "direct": the montage goes to Podlove without Auphonic.
 */
final class ProductionModeTest extends WpTestCase
{
    /**
     * @return list<string>
     */
    private function scheduledHooks(): array
    {
        return array_column(Wp::$scheduled, 0);
    }

    public function testDefaultModeSendsToAuphonicAfterMontage(): void
    {
        $id = $this->montageReadyEpisode(['auto_chain' => 1]);

        Montage::run($id);

        self::assertSame([Scheduler::HOOK_PRODUCTION], $this->scheduledHooks());
    }

    public function testDirectModeQueuesPublishAfterMontage(): void
    {
        $id = $this->montageReadyEpisode(['auto_chain' => 1]);
        $this->settings(['production_mode' => 'direct']);

        Montage::run($id);

        self::assertSame([Scheduler::HOOK_PUBLISH], $this->scheduledHooks());
        self::assertTrue(EpisodeRepository::decodeMap(EpisodeRepository::find($id)['montage_json'])['direct']);
    }

    public function testDirectModeDoesNotQueueTwice(): void
    {
        $id = $this->montageReadyEpisode(['auto_chain' => 1, 'podlove_post_id' => 42]);
        $this->settings(['production_mode' => 'direct']);

        Montage::run($id);

        self::assertSame([], $this->scheduledHooks());
    }

    public function testDirectPublishUsesTheMontageFile(): void
    {
        $id = $this->montageReadyEpisode(['episode_title' => 'Brief — Oktober 2026']);
        $this->settings(['production_mode' => 'direct', 'file_prefix' => 'Brief']);
        Montage::run($id);
        $episode = EpisodeRepository::find($id);

        Publish::run($id);

        $written = Wp::$uploads . '/podcasts/BriefOktober2026.mp3';
        self::assertFileExists($written);
        self::assertSame(
            file_get_contents(EpisodeStorage::absolutePath((string) $episode['mixed_audio_path'])),
            file_get_contents($written),
            'The montage MP3 is the published file; nothing is downloaded.'
        );
        self::assertSame([], Wp::$requests, 'No request to Auphonic.');
        self::assertSame('BriefOktober2026', EpisodeRepository::find($id)['podlove_slug']);

        // Without Podlove in the test run, the draft step is where it stops.
        $log = EpisodeRepository::logEntries($id);
        self::assertStringContainsString('Podlove is not available', (string) end($log)['text']);
    }

    public function testAuphonicHealthCheckIsSkippedInDirectMode(): void
    {
        $this->settings(['production_mode' => 'direct']);

        self::assertSame(Result::SKIP, (new AuphonicCheck())->run()->status);
    }
}
