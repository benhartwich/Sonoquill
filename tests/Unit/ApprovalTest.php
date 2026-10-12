<?php
declare(strict_types=1);

namespace Sonoquill\Tests\Unit;

use Sonoquill\Admin\MailApproval;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Notify\Notifier;
use Sonoquill\Pipeline\Approval;
use Sonoquill\Pipeline\ApprovalLink;
use Sonoquill\Tests\Wp;
use Sonoquill\Tests\WpTestCase;

/**
 * Public approval API and the signed approval link from the mail.
 */
final class ApprovalTest extends WpTestCase
{
    protected function tearDown(): void
    {
        $_GET = $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    private function cleanEpisode(array $fields = []): int
    {
        $this->settings(['podcast_language' => 'de']);

        return $this->episode($fields + [
            'status'         => EpisodeStatus::AWAITING_TEXT,
            'source_text'    => 'Am 23. September.',
            'script_text'    => 'Am dreiundzwanzigsten September.',
            'episode_title'  => 'Herbst',
            'factcheck_json' => '[]',
        ]);
    }

    public function testApprovesACleanText(): void
    {
        $id = $this->cleanEpisode();

        self::assertTrue(Approval::approveText($id, 'Jane'));

        self::assertSame(EpisodeStatus::TEXT_APPROVED, EpisodeRepository::find($id)['status']);
        self::assertSame([[$id, 'Jane']], Wp::fired('sonoquill_text_approved'));
        self::assertContains([$id, EpisodeStatus::AWAITING_TEXT, EpisodeStatus::TEXT_APPROVED], Wp::fired('sonoquill_status_changed'));
        self::assertNull(Approval::lastAutomatic());
    }

    public function testRespectsTheGateUnlessIgnored(): void
    {
        $id = $this->cleanEpisode(['script_text' => 'Am dreiundzwanzigsten September, siebenundvierzig Grad.']);

        self::assertFalse(Approval::approveText($id));
        self::assertStringContainsString('locked', Approval::lastError());
        self::assertSame([], Wp::fired('sonoquill_text_approved'));

        self::assertTrue(Approval::approveText($id, 'addon', true));
        self::assertSame(EpisodeStatus::TEXT_APPROVED, EpisodeRepository::find($id)['status']);
    }

    public function testDoesNotApproveTwice(): void
    {
        $id = $this->cleanEpisode();
        Approval::approveText($id);

        self::assertFalse(Approval::approveText($id));
        self::assertStringContainsString('already been approved', Approval::lastError());
        self::assertCount(1, Wp::fired('sonoquill_text_approved'));
    }

    public function testLinkIsBoundToScriptAndTime(): void
    {
        $now = 1_800_000_000;
        $p = ApprovalLink::params(7, 'Skript', $now);

        self::assertSame('', ApprovalLink::verify(7, $p['h'], $p['x'], $p['s'], 'Skript', $now + 3600));
        self::assertNotSame('', ApprovalLink::verify(8, $p['h'], $p['x'], $p['s'], 'Skript', $now), 'Other episode.');
        self::assertNotSame('', ApprovalLink::verify(7, $p['h'], $p['x'] + 3600, $p['s'], 'Skript', $now), 'Expiry tampered with.');
        self::assertStringContainsString('expired', ApprovalLink::verify(7, $p['h'], $p['x'], $p['s'], 'Skript', $now + 49 * 3600));
        self::assertStringContainsString('changed', ApprovalLink::verify(7, $p['h'], $p['x'], $p['s'], 'Skript, bearbeitet', $now));
    }

    public function testMailContainsTheLinkOnlyWhenEnabled(): void
    {
        $id = $this->cleanEpisode(['auto_chain' => 1]);
        Notifier::maybeTextReady($id);
        self::assertStringNotContainsString(ApprovalLink::ACTION, Wp::$mails[0][2]);

        $this->settings(['mail_approval_links' => true]);
        $id = $this->cleanEpisode(['auto_chain' => 1]);
        Notifier::maybeTextReady($id);
        self::assertStringContainsString('action=' . ApprovalLink::ACTION, Wp::$mails[1][2]);
    }

    public function testGetOnlyShowsAConfirmationPagePostApproves(): void
    {
        $this->settings(['mail_approval_links' => true]);
        $id = $this->cleanEpisode();
        $params = ApprovalLink::params($id, 'Am dreiundzwanzigsten September.');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = $params;
        $page = $this->page();
        self::assertSame(200, $page->status);
        self::assertStringContainsString('method="post"', $page->html);
        self::assertSame(EpisodeStatus::AWAITING_TEXT, EpisodeRepository::find($id)['status'], 'A link scanner must not approve.');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $params;
        self::assertSame(200, $this->page()->status);
        self::assertSame(EpisodeStatus::TEXT_APPROVED, EpisodeRepository::find($id)['status']);
        self::assertSame([[$id, 'e-mail link']], Wp::fired('sonoquill_text_approved'));
    }

    public function testLinkIsRefusedWhenSwitchedOff(): void
    {
        $id = $this->cleanEpisode();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ApprovalLink::params($id, 'Am dreiundzwanzigsten September.');

        self::assertSame(403, $this->page()->status);
        self::assertSame(EpisodeStatus::AWAITING_TEXT, EpisodeRepository::find($id)['status']);
    }

    private function page(): \WpDieException
    {
        try {
            MailApproval::handle();
        } catch (\WpDieException $e) {
            return $e;
        }
        self::fail('handle() must end with wp_die().');
    }
}
