<?php
declare(strict_types=1);

namespace Sonoquill\Health\Checks;

use Sonoquill\Health\CheckInterface;
use Sonoquill\Health\Result;
use Sonoquill\Settings\Options;
use Sonoquill\Support\CryptoException;

/**
 * Checks Auphonic via /api/user.json and also reports the remaining credit.
 *
 * If a preset UUID is configured, it additionally checks whether that preset
 * actually exists in the account — otherwise a typo there would only show up
 * during audio post-production, when the production is aborted.
 */
final class AuphonicCheck implements CheckInterface
{
    private const USER_ENDPOINT   = 'https://auphonic.com/api/user.json';
    private const PRESET_ENDPOINT = 'https://auphonic.com/api/preset/%s.json';

    public function id(): string
    {
        return 'auphonic';
    }

    public function label(): string
    {
        return 'Auphonic';
    }

    public function run(): Result
    {
        if (\Sonoquill\Settings\Options::directProduction()) {
            return Result::skip(__('Not needed: the production mode is "direct", the montage goes to Podlove without Auphonic.', 'sonoquill'));
        }

        try {
            $token = Options::secret('auphonic_api_key');
        } catch (CryptoException $e) {
            return Result::fail(__('Credentials could not be read.', 'sonoquill'), $e->getMessage());
        }

        if ($token === '') {
            return Result::skip(__('No API token configured.', 'sonoquill'));
        }

        $response = wp_remote_get(self::USER_ENDPOINT, [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return Result::fail(__('Not reachable.', 'sonoquill'), $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($code === 401 || $code === 403) {
            /* translators: %d: HTTP status code */
            return Result::fail(sprintf(__('API token not accepted (HTTP %d).', 'sonoquill'), $code));
        }

        if ($code !== 200 || !is_array($body) || !isset($body['data'])) {
            /* translators: %d: HTTP status code */
            return Result::fail(sprintf(__('Unexpected response (HTTP %d).', 'sonoquill'), $code));
        }

        $data = is_array($body['data']) ? $body['data'] : [];
        $user = isset($data['username']) ? (string) $data['username'] : '';
        $credit = isset($data['credits']) ? (float) $data['credits'] : null;

        $detail = $credit !== null
            /* translators: %s: remaining Auphonic credit in hours */
            ? sprintf(__('Remaining credit: %s hours.', 'sonoquill'), number_format_i18n($credit, 2))
            : '';

        $preset = Options::get('auphonic_preset');
        if ($preset === '') {
            return Result::warn(
                /* translators: %s: Auphonic username */
                sprintf(__('Reachable as "%s", but no preset configured.', 'sonoquill'), $user),
                $detail
            );
        }

        $presetResult = $this->checkPreset($token, $preset);
        if ($presetResult !== null) {
            return $presetResult;
        }

        // Free monthly credits (recurring) without purchased ones (onetime): in
        // that case Auphonic appends its own jingle. This happened with the
        // October 2026 episode, version 3. One episode needs about half an hour.
        $paid = isset($data['onetime_credits']) ? (float) $data['onetime_credits'] : null;
        if ($paid !== null && $paid < 0.6) {
            return Result::warn(
                /* translators: %s: remaining purchased Auphonic credit in hours */
                sprintf(__('Only %s hours of purchased credits left — Auphonic appends its jingle when using free credits.', 'sonoquill'), number_format_i18n($paid, 2)),
                $detail . __(' One episode needs about half an hour. Buy more credits at Auphonic.', 'sonoquill')
            );
        }

        /* translators: %s: Auphonic username */
        return Result::ok(sprintf(__('Reachable as "%s", preset found.', 'sonoquill'), $user), $detail);
    }

    /**
     * @return Result|null null means: the preset is fine.
     */
    private function checkPreset(string $token, string $preset): ?Result
    {
        $response = wp_remote_get(sprintf(self::PRESET_ENDPOINT, rawurlencode($preset)), [
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return Result::warn(__('Account reachable, but the preset could not be checked.', 'sonoquill'), $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            /* translators: %s: Auphonic preset UUID */
            return Result::warn(sprintf(__('Account reachable, but preset "%s" does not exist.', 'sonoquill'), $preset));
        }

        if ($code !== 200) {
            /* translators: %d: HTTP status code */
            return Result::warn(sprintf(__('Account reachable, preset request responds with HTTP %d.', 'sonoquill'), $code));
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $preset = is_array($data) ? (array) ($data['data'] ?? []) : [];

        // Speech recognition must be switched off. Chapter marks and
        // the transcript are generated from the TTS timestamps and are exact;
        // the ASR variant costs credit and is worse — which is why the current
        // feed contains misspelled names of the observatory and of a guest.
        if (!empty($preset['speech_recognition'])) {
            return Result::warn(
                __('Speech recognition is switched on in the preset.', 'sonoquill'),
                __('It costs credit and produces a faulty transcript. Chapters and transcript come from the assembly step and are exact. Switch it off in the Auphonic preset.', 'sonoquill')
            );
        }

        return null;
    }
}
