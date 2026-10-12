<?php
declare(strict_types=1);

namespace Sonoquill\Pipeline;

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;

/**
 * The text approval — the first of the two human gates — as a public API.
 *
 * Used by the approve button in the episode view, by the signed link in the
 * notification mail and by add-ons. Whoever calls it is responsible for
 * having a person behind the decision; the checks are the same for all.
 *
 *     if (!\Sonoquill\Pipeline\Approval::approveText($id, 'my-addon')) {
 *         error_log(\Sonoquill\Pipeline\Approval::lastError());
 *     }
 */
final class Approval
{
    private static string $lastError = '';

    private static string $lastNotice = '';

    private static ?string $lastAutomatic = null;

    /**
     * Approves the text of an episode and, for episodes that run
     * automatically, starts the chain (segments, synthesis …).
     *
     * @param string $actor      Who approves — a display name or a context like "e-mail link". Goes into the run log and the action.
     * @param bool   $ignoreGate Approve although the number and content checks block. Only for callers that
     *                           have their own human review; the run log records it.
     *
     * @return bool False if nothing was approved; the reason is in lastError().
     */
    public static function approveText(int $id, string $actor = 'admin', bool $ignoreGate = false): bool
    {
        self::$lastError = '';
        self::$lastNotice = '';
        self::$lastAutomatic = null;

        $episode = EpisodeRepository::find($id);
        if ($episode === null) {
            /* translators: %d: episode ID */
            return self::fail(sprintf(__('Episode %d does not exist.', 'sonoquill'), $id));
        }

        if (!in_array((string) $episode['status'], EpisodeStatus::BEFORE_TEXT_APPROVAL, true)) {
            return self::fail(__('The text of this episode has already been approved.', 'sonoquill'));
        }

        if (trim((string) ($episode['script_text'] ?? '')) === '') {
            return self::fail(__('The episode has no spoken script yet.', 'sonoquill'));
        }

        $blockers = self::blockers();
        if ($blockers !== '') {
            return self::fail($blockers);
        }

        $result = Gate::evaluate($id);
        if ($result['blocking'] && !$ignoreGate) {
            return self::fail(__('Approval is locked as long as deviations are open.', 'sonoquill'));
        }

        EpisodeRepository::update($id, [
            'status'           => EpisodeStatus::TEXT_APPROVED,
            'text_approved_at' => current_time('mysql'),
        ]);

        EpisodeRepository::log($id, 'freigabe', sprintf(
            /* translators: %s: display name of the approving user */
            __('Text approved by %s.', 'sonoquill'),
            $actor
        ) . ($result['blocking'] ? ' ' . __('The open deviations were deliberately overridden.', 'sonoquill') : ''));

        /**
         * Action: the text of an episode has been approved.
         *
         * @param int    $id    Episode ID.
         * @param string $actor Who approved (display name, "e-mail link", an add-on's name …).
         */
        do_action('sonoquill_text_approved', $id, $actor);

        try {
            self::$lastAutomatic = AutoChain::afterTextApproval($id);
        } catch (\Throwable $e) {
            EpisodeRepository::log($id, 'automatik', __('Aborted: ', 'sonoquill') . $e->getMessage());
            self::$lastNotice = __('The text is approved, but the chain could not be triggered automatically: ', 'sonoquill') . $e->getMessage();
        }

        return true;
    }

    /**
     * Problems outside the episode that would make everything after the
     * approval fail. Checked before approving, so that nothing is approved
     * that cannot be produced. Empty: nothing in the way.
     */
    public static function blockers(): string
    {
        return '';
    }

    /** Why the last approveText() call did not approve. */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** A warning from the last successful approval (chain not started), or ''. */
    public static function lastNotice(): string
    {
        return self::$lastNotice;
    }

    /** What the automatic chain did after the last approval, or null if the episode does not run automatically. */
    public static function lastAutomatic(): ?string
    {
        return self::$lastAutomatic;
    }

    private static function fail(string $message): bool
    {
        self::$lastError = $message;

        return false;
    }
}
