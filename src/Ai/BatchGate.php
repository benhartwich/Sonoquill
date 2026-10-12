<?php
declare(strict_types=1);

namespace Sonoquill\Ai;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin keeps episodes and segments in its own tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Episode state changes between background jobs and must always be read fresh.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Db\EpisodeRepository;
use Sonoquill\Jobs\Scheduler as JobScheduler;
use Sonoquill\Settings\Options;

/**
 * Model calls via the Batch API: half the price, but no immediate
 * result.
 *
 * Nobody in this pipeline waits for the model in real time — every step
 * is a background job anyway. So every call works like this:
 *
 * 1. The step calls complete(). If nothing exists yet for exactly this request
 *    (hash over the whole request), it is submitted as a batch and the step
 *    ends with PendingBatch — no error, no state change.
 * 2. A polling job checks every two minutes. Once the batch is done, it stores
 *    the response and triggers the step again.
 * 3. The step runs from the start and reaches the same request again — now
 *    the response is there and it carries on.
 *
 * This requires every step to be repeatable up to the model call.
 * It already was (every step is idempotent). The costs are
 * booked only when the response is read for the first time.
 */
final class BatchGate
{
    public const HOOK_POLL = 'aaspf_batch_abfrage';

    private const PREFIX = 'aaspf_batch_';
    private const INDEX = 'aaspf_batch_offen';

    /** After waiting this long a batch is considered lost (Anthropic guarantees 24 hours). */
    private const GIVE_UP_SECONDS = 26 * HOUR_IN_SECONDS;

    public static function register(): void
    {
        add_action(self::HOOK_POLL, [self::class, 'poll'], 10, 1);
    }

    public static function enabled(): bool
    {
        return Options::flag('anthropic_batch');
    }

    /**
     * Batch or direct for this call: the setting, unless the source of the
     * episode has a preference (SourcePreferences::prefersBatch) — then
     * the filter `sonoquill_use_batch` decides last.
     */
    public static function useBatch(int $episodeId, string $step): bool
    {
        $use = self::enabled();

        $episode = $episodeId > 0 ? EpisodeRepository::find($episodeId) : null;
        if ($episode !== null) {
            $preference = \Sonoquill\Source\Sources::prefersBatch($episode);
            if ($preference !== null) {
                $use = $preference;
            }
        }

        /**
         * Filter: use the Batch API for this model call?
         *
         * @param bool   $use       From the setting or the source's preference.
         * @param int    $episodeId Episode ID.
         * @param string $step      Pipeline step (redigat, metadaten, faktenpruefung, woerterbuch …).
         */
        return (bool) apply_filters('sonoquill_use_batch', $use, $episodeId, $step);
    }

    /**
     * @param string|list<array<string,mixed>> $user
     * @param array<string,mixed>              $options
     *
     * @throws PendingBatch        if the response is still pending.
     * @throws AnthropicException  on an error, either direct or within the batch.
     */
    public static function complete(
        AnthropicClient $client,
        int $episodeId,
        string $step,
        string $resumeHook,
        string $system,
        string|array $user,
        array $options = []
    ): AnthropicResponse {
        if (!self::useBatch($episodeId, $step)) {
            return $client->complete($system, $user, $options);
        }

        $payload = $client->payload($system, $user, $options);
        $hash = substr(hash('sha256', (string) wp_json_encode($payload)), 0, 24);
        $key = self::PREFIX . $hash;
        $state = get_option($key);

        if (is_array($state)) {
            if (($state['status'] ?? '') === 'fertig' && is_array($state['nachricht'] ?? null)) {
                // Costs are booked here and exactly once — even a response that
                // is discarded as unusable right afterwards has cost money.
                if (empty($state['gebucht'])) {
                    $usage = (array) ($state['nachricht']['usage'] ?? []);
                    EpisodeRepository::addCost($episodeId, Pricing::cents(
                        (string) ($state['nachricht']['model'] ?? ''),
                        (int) ($usage['input_tokens'] ?? 0),
                        (int) ($usage['output_tokens'] ?? 0),
                        (int) ($usage['cache_creation_input_tokens'] ?? 0),
                        (int) ($usage['cache_read_input_tokens'] ?? 0),
                        true
                    ));
                    $state['gebucht'] = true;
                    update_option($key, $state, false);
                }

                AnthropicClient::ensureNotRefused($state['nachricht']);

                return AnthropicClient::interpret($state['nachricht'], true, true);
            }

            if (($state['status'] ?? '') === 'fehler') {
                // The next attempt submits it again.
                delete_option($key);
                self::unindex($hash);

                throw new AnthropicException(__('Batch failed: ', 'sonoquill') . (string) ($state['fehler'] ?? __('unknown', 'sonoquill')));
            }

            /* translators: %s: batch ID */
            throw new PendingBatch(sprintf(__('Batch %s is still running.', 'sonoquill'), (string) ($state['id'] ?? '')));
        }

        $customId = substr(sprintf('e%d-%s-%s', $episodeId, preg_replace('/[^a-z0-9]/', '', strtolower($step)), $hash), 0, 64);
        $batchId = $client->createBatch($customId, $payload);

        update_option($key, [
            'id'       => $batchId,
            'custom'   => $customId,
            'status'   => 'laeuft',
            'folge'    => $episodeId,
            'schritt'  => $step,
            'hook'     => $resumeHook,
            'seit'     => time(),
        ], false);
        self::index($hash, $episodeId);

        as_schedule_single_action(time() + 90, self::HOOK_POLL, [$hash], JobScheduler::GROUP);

        EpisodeRepository::log($episodeId, $step, sprintf(
            /* translators: 1: batch ID, 2: model name */
            __('Submitted as a batch (%1$s, model %2$s). The result usually arrives within an hour, at half the price; after that it continues automatically.', 'sonoquill'),
            $batchId,
            (string) $payload['model']
        ));

        /* translators: %s: batch ID */
        throw new PendingBatch(sprintf(__('Batch %s submitted.', 'sonoquill'), $batchId));
    }

    /**
     * Polling job: is the batch done?
     */
    public static function poll(string $hash): void
    {
        $key = self::PREFIX . $hash;
        $state = get_option($key);
        if (!is_array($state) || ($state['status'] ?? '') !== 'laeuft') {
            return;
        }

        $episodeId = (int) $state['folge'];

        try {
            $client = AnthropicClient::fromSettings();
            $batch = $client->retrieveBatch((string) $state['id']);
        } catch (\Throwable $e) {
            // Network down or API briefly unreachable: try again later.
            as_schedule_single_action(time() + 300, self::HOOK_POLL, [$hash], JobScheduler::GROUP);

            return;
        }

        if (($batch['processing_status'] ?? '') !== 'ended') {
            if (time() - (int) $state['seit'] > self::GIVE_UP_SECONDS) {
                self::finish($key, $hash, $state + ['status' => 'fehler', 'fehler' => __('no result after 26 hours', 'sonoquill')]);

                return;
            }

            as_schedule_single_action(time() + 120, self::HOOK_POLL, [$hash], JobScheduler::GROUP);

            return;
        }

        try {
            $rows = $client->batchResults((string) ($batch['results_url'] ?? ''));
        } catch (\Throwable $e) {
            as_schedule_single_action(time() + 300, self::HOOK_POLL, [$hash], JobScheduler::GROUP);

            return;
        }

        $result = null;
        foreach ($rows as $row) {
            if (($row['custom_id'] ?? '') === $state['custom']) {
                $result = (array) ($row['result'] ?? []);
                break;
            }
        }

        if ($result !== null && ($result['type'] ?? '') === 'succeeded' && is_array($result['message'] ?? null)) {
            $state['status'] = 'fertig';
            $state['nachricht'] = $result['message'];
            $state['fertig'] = time();

            EpisodeRepository::log($episodeId, (string) $state['schritt'], sprintf(
                /* translators: %s: human-readable waiting time since submission */
                __('Batch result arrived after %s.', 'sonoquill'),
                human_time_diff((int) $state['seit'])
            ));
        } else {
            $state['status'] = 'fehler';
            $state['fehler'] = $result === null
                ? __('Request missing from the result', 'sonoquill')
                : (string) ($result['type'] ?? __('unknown', 'sonoquill')) . (isset($result['error']['error']['message']) ? ': ' . $result['error']['error']['message'] : '');
        }

        self::finish($key, $hash, $state);
    }

    /**
     * Is this episode waiting for a batch? Then it is not stuck, it is waiting.
     *
     * @return array{schritt:string,seit:int}|null
     */
    public static function pendingFor(int $episodeId): ?array
    {
        $index = get_option(self::INDEX, []);
        if (!is_array($index)) {
            return null;
        }

        foreach ($index as $hash => $id) {
            if ((int) $id !== $episodeId) {
                continue;
            }
            $state = get_option(self::PREFIX . $hash);
            if (is_array($state) && ($state['status'] ?? '') === 'laeuft') {
                return ['schritt' => (string) $state['schritt'], 'seit' => (int) $state['seit']];
            }
        }

        return null;
    }

    /**
     * Remove collected responses after one week. For the daily run.
     */
    public static function cleanup(): void
    {
        global $wpdb;

        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(self::PREFIX) . '%'
        ));

        foreach ((array) $names as $name) {
            if ($name === self::INDEX) {
                continue;
            }
            $state = get_option((string) $name);
            if (is_array($state) && ($state['status'] ?? '') === 'fertig' && time() - (int) ($state['fertig'] ?? 0) > WEEK_IN_SECONDS) {
                delete_option((string) $name);
            }
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private static function finish(string $key, string $hash, array $state): void
    {
        update_option($key, $state, false);
        self::unindex($hash);

        if (($state['status'] ?? '') === 'fehler') {
            EpisodeRepository::log((int) $state['folge'], (string) $state['schritt'], __('Batch failed: ', 'sonoquill') . (string) $state['fehler']);
        }

        // Trigger the step again: on success it reads the response,
        // on an error it resubmits or reports it.
        \Sonoquill\Pipeline\Scheduler::queueHook((string) $state['hook'], (int) $state['folge']);
    }

    private static function index(string $hash, int $episodeId): void
    {
        $index = get_option(self::INDEX, []);
        $index = is_array($index) ? $index : [];
        $index[$hash] = $episodeId;
        update_option(self::INDEX, $index, false);
    }

    private static function unindex(string $hash): void
    {
        $index = get_option(self::INDEX, []);
        if (is_array($index) && isset($index[$hash])) {
            unset($index[$hash]);
            update_option(self::INDEX, $index, false);
        }
    }
}
