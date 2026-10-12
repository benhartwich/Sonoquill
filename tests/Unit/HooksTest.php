<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Notify\Notifier;
use Sonoquill\Pipeline\Montage;
use Sonoquill\Tests\Wp;
use Sonoquill\Tests\WpTestCase;

/**
 * Actions for add-ons: status changes, text ready, montage done.
 */
final class HooksTest extends WpTestCase
{
    public function testStatusChangeIsAnnouncedCentrally(): void
    {
        $id = $this->episode(['status' => EpisodeStatus::PARSED]);

        EpisodeRepository::update($id, ['status' => EpisodeStatus::REDIGAT_RUNNING]);

        self::assertSame([[$id, EpisodeStatus::PARSED, EpisodeStatus::REDIGAT_RUNNING]], Wp::fired('sonoquill_status_changed'));
    }

    public function testSameStatusOrOtherFieldsAreNotAnnounced(): void
    {
        $id = $this->episode(['status' => EpisodeStatus::PARSED]);

        EpisodeRepository::update($id, ['status' => EpisodeStatus::PARSED]);
        EpisodeRepository::update($id, ['script_text' => 'x']);

        self::assertSame([], Wp::fired('sonoquill_status_changed'));
    }

    public function testTextReadyFiresOncePerScriptVersion(): void
    {
        $id = $this->episode([
            'status'         => EpisodeStatus::AWAITING_TEXT,
            'script_text'    => 'Hallo.',
            'episode_title'  => 'Titel',
            'factcheck_json' => '[]',
        ]);

        Notifier::maybeTextReady($id);
        Notifier::maybeTextReady($id);
        self::assertSame([[$id, EpisodeStatus::AWAITING_TEXT]], Wp::fired('sonoquill_text_ready'));
        self::assertSame([], Wp::$mails, 'No mail for an episode that does not run automatically.');

        EpisodeRepository::update($id, ['script_text' => 'Hallo, neu.']);
        Notifier::maybeTextReady($id);
        self::assertCount(2, Wp::fired('sonoquill_text_ready'));
    }

    public function testTextReadyWaitsForAllThreeParts(): void
    {
        $id = $this->episode([
            'status'        => EpisodeStatus::AWAITING_TEXT,
            'script_text'   => 'Hallo.',
            'episode_title' => 'Titel',
        ]);

        Notifier::maybeTextReady($id);

        self::assertSame([], Wp::fired('sonoquill_text_ready'), 'The fact check is still missing.');
    }

    public function testMontageDoneCarriesTheRelativePath(): void
    {
        $id = $this->montageReadyEpisode();

        Montage::run($id);

        self::assertSame([[$id, $id . '/folge.mp3']], Wp::fired('sonoquill_montage_done'));
        self::assertSame($id . '/folge.mp3', EpisodeRepository::find($id)['mixed_audio_path']);
    }
}
