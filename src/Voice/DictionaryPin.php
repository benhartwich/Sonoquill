<?php
declare(strict_types=1);

namespace Sonoquill\Voice;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for the run log and e-mails; they are escaped where they are displayed.

use Sonoquill\Settings\Options;
use Sonoquill\Support\CryptoException;

/**
 * The pinned version of the pronunciation dictionary.
 *
 * Synthesis refuses to run with a dictionary but no version (see
 * TextToSpeech::fromSettings) — rightly, but until 0.4.0 that only showed
 * after the text approval, in the middle of the automatic chain. Now the
 * approval itself is blocked with a clear message, and the latest version
 * can be pinned with one click.
 */
final class DictionaryPin
{
    private const BASE = 'https://api.elevenlabs.io/v1/pronunciation-dictionaries/';

    /** A dictionary is configured, but no version is pinned. */
    public static function isMissing(): bool
    {
        return trim(Options::get('elevenlabs_dictionary_id')) !== ''
            && trim(Options::get('elevenlabs_dictionary_version_id')) === '';
    }

    /**
     * Why the audio cannot be produced, or '' if the dictionary is fine.
     */
    public static function problem(): string
    {
        if (!self::isMissing()) {
            return '';
        }

        return __('The pronunciation dictionary is entered without a version, so speech synthesis would fail after the approval. Pin a version in the settings (“Pin latest version” next to the dictionary version) or remove the dictionary ID, then approve.', 'sonoquill');
    }

    /**
     * Asks ElevenLabs for the latest version of the configured dictionary.
     *
     * @throws \RuntimeException
     */
    public static function latestVersionId(): string
    {
        $dictionaryId = trim(Options::get('elevenlabs_dictionary_id'));
        if ($dictionaryId === '') {
            throw new \RuntimeException(__('No dictionary configured.', 'sonoquill'));
        }

        try {
            $key = Options::secret('elevenlabs_api_key');
        } catch (CryptoException $e) {
            throw new \RuntimeException(__('ElevenLabs credentials could not be read: ', 'sonoquill') . $e->getMessage(), 0, $e);
        }
        if ($key === '') {
            throw new \RuntimeException(__('No ElevenLabs API key is stored.', 'sonoquill'));
        }

        $response = wp_remote_get(self::BASE . rawurlencode($dictionaryId), [
            'timeout' => 15,
            'headers' => ['xi-api-key' => $key, 'accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            throw new \RuntimeException(__('Connection failed: ', 'sonoquill') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $version = is_array($body) ? trim((string) ($body['latest_version_id'] ?? '')) : '';

        if ($code !== 200 || $version === '') {
            /* translators: %d: HTTP status code */
            throw new \RuntimeException(sprintf(__('ElevenLabs did not report a latest version (HTTP %d).', 'sonoquill'), $code));
        }

        return $version;
    }

    /**
     * Pins the latest version and returns its ID.
     *
     * @throws \RuntimeException
     */
    public static function pinLatest(): string
    {
        $version = self::latestVersionId();

        $all = Options::all();
        $all['elevenlabs_dictionary_version_id'] = $version;
        Options::save($all);
        PronunciationDictionary::forget();

        return $version;
    }
}
