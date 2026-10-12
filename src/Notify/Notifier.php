<?php
declare(strict_types=1);

namespace Sonoquill\Notify;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin keeps episodes and segments in its own tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Episode state changes between background jobs and must always be read fresh.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().

use Sonoquill\Admin\EpisodesPage;
use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Db\EpisodeStatus;
use Sonoquill\Settings\Options;
use Sonoquill\Support\RunLog;

/**
 * E-mails to the person who guards the two gates.
 *
 * Three occasions: the text is awaiting approval, the audio is awaiting approval,
 * something went wrong. All three only for episodes that run on automatically —
 * anyone who clicks through an episode by hand is sitting in front of it anyway.
 *
 * Mail is sent via wp_mail, i.e. via WP Mail SMTP, like every other e-mail
 * of the website. Every mail contains the link to the episode view. Only if
 * the setting `mail_approval_links` is on, the text mail also carries a signed
 * approval link (see ApprovalLink); otherwise approval happens in the backend.
 */
final class Notifier
{
    /** Spoken characters per minute, in case there is no finished episode to measure yet. */
    private const FALLBACK_CHARS_PER_MINUTE = 850;

    /** Prevents logging a mail from triggering another mail itself. */
    private static bool $sending = false;

    /**
     * After editing, fact check and metadata: is the text waiting now?
     *
     * Called at the end of each of these steps. The mail only goes out once all
     * three are done, and only once per episode.
     */
    public static function maybeTextReady(int $episodeId): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null || !self::isTextReady($episode)) {
            return;
        }

        if ((int) ($episode['auto_chain'] ?? 0) === 1 && empty($episode['text_notified_at'])) {
            self::sendTextReady($episodeId, $episode);
        }

        // After the mail: an add-on that approves right away should not
        // leave behind a mail that asks for an approval already given.
        self::fireTextReady($episodeId, $episode);
    }

    /** Option prefix: hash of the script for which `sonoquill_text_ready` last fired. */
    public const TEXT_READY_OPTION = 'aaspf_text_ready_';

    /**
     * Spoken script, fact check and title are all there and the text is waiting.
     *
     * @param array<string,mixed> $episode
     */
    public static function isTextReady(array $episode): bool
    {
        if (!in_array((string) ($episode['status'] ?? ''), [EpisodeStatus::AWAITING_TEXT, EpisodeStatus::GATE_FAILED], true)) {
            return false;
        }

        return trim((string) ($episode['script_text'] ?? '')) !== ''
            && ($episode['factcheck_json'] ?? null) !== null
            && trim((string) ($episode['episode_title'] ?? '')) !== '';
    }

    /**
     * Fires `sonoquill_text_ready` once per version of the spoken script —
     * for every episode, not only those that run automatically.
     *
     * @param array<string,mixed> $episode
     */
    private static function fireTextReady(int $episodeId, array $episode): void
    {
        $hash = md5((string) $episode['script_text']);
        if (get_option(self::TEXT_READY_OPTION . $episodeId) === $hash) {
            return;
        }
        update_option(self::TEXT_READY_OPTION . $episodeId, $hash, false);

        /**
         * Action: the text of an episode is complete (spoken script, title,
         * fact check) and waits for approval. Fires once per script version.
         *
         * @param int    $episodeId Episode ID.
         * @param string $status    EpisodeStatus::AWAITING_TEXT, or GATE_FAILED if checks block approval.
         */
        do_action('sonoquill_text_ready', $episodeId, (string) $episode['status']);
    }

    /**
     * @param array<string,mixed> $episode
     */
    private static function sendTextReady(int $episodeId, array $episode): void
    {
        $status = (string) $episode['status'];

        $diff = EpisodeRepository::decodeMap($episode['diff_json'] ?? null);
        $facts = EpisodeRepository::decodeList($episode['factcheck_json'] ?? null);
        $severe = count(array_filter($facts, static fn (array $f): bool => ($f['schwere'] ?? '') === 'hoch'));
        $chars = mb_strlen((string) $episode['script_text']);

        $version = self::version($episode, 'redigat', '/^Sprechskript erzeugt/u');

        $lines = [];
        /* translators: %s: episode title */
        $lines[] = sprintf(__('The episode “%s” has been written and is awaiting your approval.', 'sonoquill'), (string) $episode['episode_title']);
        if ($version > 1) {
            /* translators: %d: version number of the episode text */
            $lines[] = sprintf(__('This is version %d of this episode. Earlier mails about the text or audio approval of this episode are therefore outdated.', 'sonoquill'), $version);
        }
        $lines[] = '';
        /* translators: 1: number of characters in the speech script, 2: estimated spoken minutes */
        $lines[] = sprintf(__('Length: %1$s characters of speech script, about %2$d minutes spoken.', 'sonoquill'), number_format_i18n($chars), self::minutes($chars));
        $lines[] = isset($diff['aus'])
            ? __('Number diff: switched off for this episode — please check the figures yourself.', 'sonoquill')
            : sprintf(
                /* translators: 1: number of invented figures, 2: number of missing figures, 3: number of changed figures */
                __('Number diff: %1$d invented, %2$d missing, %3$d changed.', 'sonoquill'),
                count((array) ($diff['erfunden'] ?? [])),
                count((array) ($diff['fehlt'] ?? [])),
                count((array) ($diff['geaendert'] ?? []))
            );
        /* translators: 1: total number of fact check findings, 2: number of severe findings */
        $lines[] = sprintf(__('Fact check: %1$d findings, %2$d of them severe.', 'sonoquill'), count($facts), $severe);

        foreach (\Sonoquill\Source\Sources::forEpisode($episode)->mailLines($episode) as $line) {
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = $status === EpisodeStatus::GATE_FAILED
            ? __('Approval is locked until the open issues have been resolved or confirmed.', 'sonoquill')
            : __('The checks are clean. After approval everything runs on its own up to the Podlove draft; the audio approval comes as a separate mail.', 'sonoquill');
        $lines[] = '';
        $lines[] = __('To approve: ', 'sonoquill') . self::url($episodeId, 'skript');
        if ($status === EpisodeStatus::AWAITING_TEXT && \Sonoquill\Admin\MailApproval::enabled()) {
            $lines[] = __('Approve directly (asks once more, valid for 48 hours, void once the script is edited): ', 'sonoquill')
                . \Sonoquill\Pipeline\ApprovalLink::url($episodeId, (string) $episode['script_text']);
        }

        $subject = sprintf(
            /* translators: 1: approval status label, 2: short episode name, 3: version number, 4: estimated spoken minutes */
            __('%1$s: %2$s · Version %3$d · ≈ %4$d min', 'sonoquill'),
            $status === EpisodeStatus::GATE_FAILED ? __('Text locked, please review', 'sonoquill') : __('Text awaiting approval', 'sonoquill'),
            self::shortName($episode),
            max(1, $version),
            self::minutes($chars)
        );

        if (self::send($subject, implode("\n", $lines))) {
            EpisodeRepository::update($episodeId, ['text_notified_at' => current_time('mysql')]);
            self::log($episodeId, __('Notification about the pending text approval sent.', 'sonoquill'));
        }
    }

    /**
     * The Podlove draft exists, the mastered version is waiting.
     */
    public static function audioReady(int $episodeId, string $warning = ''): void
    {
        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null || (int) ($episode['auto_chain'] ?? 0) !== 1 || !empty($episode['audio_notified_at'])) {
            return;
        }

        $chapters = EpisodeRepository::decodeList($episode['chapters'] ?? null);
        $duration = (int) ($episode['duration_ms'] ?? 0);

        $version = self::version($episode, 'podlove', '/^Entwurf #\d+ angelegt/u');

        $lines = [
            /* translators: %s: episode title */
            sprintf(__('The episode “%s” has been mastered at Auphonic and is ready as a Podlove draft.', 'sonoquill'), (string) $episode['episode_title']),
        ];
        if ($warning !== '') {
            $lines[] = '';
            $lines[] = $warning;
        }
        if ($version > 1) {
            /* translators: %d: version number of the audio */
            $lines[] = sprintf(__('This is version %d of the audio. Earlier mails about the audio approval of this episode are therefore outdated; the Podlove draft now contains this version.', 'sonoquill'), $version);
        }
        $lines = array_merge($lines, [
            '',
            /* translators: 1: duration minutes, 2: duration seconds, 3: number of chapters */
            sprintf(__('Duration: %1$d:%2$02d minutes, %3$d chapters.', 'sonoquill'), intdiv($duration, 60000), intdiv($duration % 60000, 1000), count($chapters)),
            '',
            __('Listen and approve: ', 'sonoquill') . self::url($episodeId),
            '',
            __('Publishing is then done by hand in Podlove.', 'sonoquill'),
        ]);

        $subject = sprintf(
            /* translators: 1: short episode name, 2: version number, 3: duration minutes, 4: duration seconds */
            __('Audio awaiting approval: %1$s · Version %2$d · %3$d:%4$02d', 'sonoquill'),
            self::shortName($episode),
            max(1, $version),
            intdiv($duration, 60000),
            intdiv($duration % 60000, 1000)
        );

        if (self::send($subject, implode("\n", $lines))) {
            EpisodeRepository::update($episodeId, ['audio_notified_at' => current_time('mysql')]);
            self::log($episodeId, __('Notification about the pending audio approval sent.', 'sonoquill'));
        }
    }

    /**
     * A step has failed. At most one mail per episode and step
     * within six hours — a run that keeps repeating should not flood
     * the inbox.
     */
    public static function maybeFailure(int $episodeId, string $step, string $message): void
    {
        if (self::$sending || !RunLog::isFailure($message)) {
            return;
        }

        $episode = EpisodeRepository::find($episodeId);
        if ($episode === null || (int) ($episode['auto_chain'] ?? 0) !== 1) {
            return;
        }

        $throttle = 'aaspf_fehlermail_' . $episodeId . '_' . md5($step);
        if (get_transient($throttle)) {
            return;
        }
        set_transient($throttle, 1, 6 * HOUR_IN_SECONDS);

        $title = trim((string) ($episode['episode_title'] ?? ''));
        if ($title === '') {
            /* translators: %d: episode ID */
            $title = trim((string) ($episode['source_filename'] ?? '')) ?: sprintf(__('Episode %d', 'sonoquill'), $episodeId);
        }

        $body = implode("\n", [
            /* translators: %s: episode title */
            sprintf(__('A step got stuck on “%s”.', 'sonoquill'), $title),
            '',
            /* translators: %s: name of the failed step */
            sprintf(__('Step: %s', 'sonoquill'), $step),
            /* translators: %s: error message */
            sprintf(__('Message: %s', 'sonoquill'), $message),
            '',
            __('The episode view has a “Resume run” button: ', 'sonoquill') . self::url($episodeId),
        ]);

        /* translators: 1: short episode name, 2: name of the failed step */
        if (self::send(sprintf(__('Error in %1$s · step %2$s', 'sonoquill'), self::shortName($episode), $step), $body)) {
            /* translators: %s: name of the failed step */
            self::log($episodeId, sprintf(__('Notification about the error in step “%s” sent.', 'sonoquill'), $step));
        }
    }

    public static function plain(string $subject, string $body): bool
    {
        return self::send($subject, $body);
    }

    /**
     * @return list<string>
     */
    public static function recipients(): array
    {
        $configured = array_filter(array_map('trim', explode(',', Options::get('notify_email'))), 'is_email');

        return $configured !== [] ? array_values($configured) : [(string) get_option('admin_email')];
    }

    private static function send(string $subject, string $body): bool
    {
        self::$sending = true;

        try {
            $ok = wp_mail(
                self::recipients(),
                '[Sonoquill] ' . $subject,
                $body . __("\n\n— Sonoquill on ", 'sonoquill') . wp_parse_url(home_url(), PHP_URL_HOST),
                ['Content-Type: text/plain; charset=UTF-8']
            );
        } finally {
            self::$sending = false;
        }

        return (bool) $ok;
    }

    private static function log(int $episodeId, string $text): void
    {
        self::$sending = true;
        try {
            EpisodeRepository::log($episodeId, 'mail', $text);
        } finally {
            self::$sending = false;
        }
    }

    /**
     * Which version this is: counted from the log entries of a step.
     *
     * @param array<string,mixed> $episode
     */
    private static function version(array $episode, string $step, string $pattern): int
    {
        $count = 0;
        foreach (EpisodeRepository::decodeList($episode['run_log'] ?? null) as $entry) {
            if (($entry['schritt'] ?? '') === $step && preg_match($pattern, (string) ($entry['text'] ?? '')) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * "October 2026" for a sky preview, otherwise the title, shortened.
     *
     * @param array<string,mixed> $episode
     */
    private static function shortName(array $episode): string
    {
        $fromSource = \Sonoquill\Source\Sources::forEpisode($episode)->shortName($episode);
        if ($fromSource !== '') {
            return $fromSource;
        }

        $title = trim((string) ($episode['episode_title'] ?? ''));

        /* translators: %d: episode ID */
        return mb_strimwidth($title !== '' ? $title : sprintf(__('Episode %d', 'sonoquill'), (int) $episode['id']), 0, 60, '…');
    }

    private static function url(int $episodeId, string $tab = ''): string
    {
        return \Sonoquill\Admin\Urls::episode($episodeId, $tab);
    }

    /**
     * Speaking duration, estimated from the pace of the most recent finished episode.
     */
    public static function estimateMinutes(int $chars): int
    {
        return self::minutes($chars);
    }

    private static function minutes(int $chars): int
    {
        global $wpdb;

        $table = \Sonoquill\Db\Schema::episodesTable();
        $row = $wpdb->get_row(
            "SELECT CHAR_LENGTH(script_text) AS zeichen, duration_ms FROM {$table} WHERE duration_ms > 60000 AND script_text IS NOT NULL ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );

        $perMinute = self::FALLBACK_CHARS_PER_MINUTE;
        if (is_array($row) && (int) $row['zeichen'] > 0) {
            $perMinute = (int) $row['zeichen'] / ((int) $row['duration_ms'] / 60000);
        }

        return max(1, (int) round($chars / max(1, $perMinute)));
    }
}
