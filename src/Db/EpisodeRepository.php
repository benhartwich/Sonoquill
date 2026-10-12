<?php
declare(strict_types=1);

namespace Sonoquill\Db;

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin keeps episodes and segments in its own tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Episode state changes between background jobs and must always be read fresh.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only table names from $wpdb->prefix are interpolated; all values go through $wpdb->prepare().

/**
 * Access to the episodes table.
 *
 * Deliberately narrow: create, read, update individual fields, log.
 * Everything else belongs in the classes that carry out the respective step.
 */
final class EpisodeRepository
{
    /**
     * @param array<string,mixed> $fields
     */
    public static function create(array $fields = []): int
    {
        global $wpdb;

        $now = current_time('mysql');
        $data = array_merge([
            'status'     => EpisodeStatus::NEW,
            'created_at' => $now,
            'updated_at' => $now,
        ], $fields);

        $wpdb->insert(Schema::episodesTable(), $data);

        return (int) $wpdb->insert_id;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(int $id): ?array
    {
        global $wpdb;

        $table = Schema::episodesTable();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function recent(int $limit = 25): array
    {
        global $wpdb;

        $table = Schema::episodesTable();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $fields
     */
    public static function update(int $id, array $fields): void
    {
        global $wpdb;

        // Status changes are announced centrally, whichever step makes them.
        $old = null;
        if (array_key_exists('status', $fields)) {
            $before = self::find($id);
            $old = $before !== null ? (string) ($before['status'] ?? '') : null;
        }

        $fields['updated_at'] = current_time('mysql');
        $wpdb->update(Schema::episodesTable(), $fields, ['id' => $id]);

        if ($old !== null && $old !== (string) $fields['status'] && function_exists('do_action')) {
            /**
             * Action: the status of an episode has changed.
             *
             * @param int    $id  Episode ID.
             * @param string $old Previous status (EpisodeStatus constant).
             * @param string $new New status.
             */
            do_action('sonoquill_status_changed', $id, $old, (string) $fields['status']);
        }
    }

    /**
     * Keeps a running total of model costs.
     *
     * Otherwise a twelve-thousand-character text that was accidentally
     * generated three times would only be noticed on the invoice.
     */
    public static function addCost(int $id, float $cents): void
    {
        global $wpdb;

        $table = Schema::episodesTable();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET cost_cents = cost_cents + %f, updated_at = %s WHERE id = %d",
            $cents,
            current_time('mysql'),
            $id
        ));
    }

    public static function delete(int $id): void
    {
        global $wpdb;

        $wpdb->delete(Schema::segmentsTable(), ['episode_id' => $id]);
        $wpdb->delete(Schema::episodesTable(), ['id' => $id]);
        delete_option(\Sonoquill\Notify\Notifier::TEXT_READY_OPTION . $id);
    }

    /**
     * Appends a line to the run log.
     *
     * With one episode per month, traceability is worth more than
     * storage space. The log is stored as JSON lines in the episode row.
     *
     * @param array<string,mixed> $context
     */
    public static function log(int $id, string $step, string $message, array $context = []): void
    {
        $entry = [
            'zeit'    => current_time('mysql'),
            'schritt' => $step,
            'text'    => $message,
        ];

        if ($context !== []) {
            $entry['details'] = $context;
        }

        $episode = self::find($id);
        $log = self::decodeList($episode['run_log'] ?? null);
        $log[] = $entry;

        self::update($id, ['run_log' => (string) wp_json_encode($log)]);

        // A failure in an episode that keeps running unattended should not
        // go unnoticed until the next time someone looks at the backend.
        \Sonoquill\Notify\Notifier::maybeFailure($id, $step, $message);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function logEntries(int $id): array
    {
        $episode = self::find($id);

        return self::decodeList($episode['run_log'] ?? null);
    }

    /**
     * @param mixed $raw
     *
     * @return list<array<string,mixed>>
     */
    public static function decodeList($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param mixed $raw
     *
     * @return array<string,mixed>
     */
    public static function decodeMap($raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
