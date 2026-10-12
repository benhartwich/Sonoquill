<?php
declare(strict_types=1);

namespace Sonoquill\Health\Checks;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking the plugin's own storage directory, also from background jobs.

use Sonoquill\Health\CheckInterface;
use Sonoquill\Health\Result;
use Sonoquill\Settings\Options;
use Sonoquill\Voice\Models;
use Sonoquill\Voice\PhonemeGuard;
use Sonoquill\Support\CryptoException;
use Sonoquill\Support\PlsDocument;
use Sonoquill\Support\PlsException;
use Sonoquill\Voice\DictionaryWriter;

/**
 * Checks the pronunciation dictionary.
 *
 * This check is meant to surface three silent failures:
 *
 * 1. **No version pinned.** Every request must pass the `version_id`
 *    explicitly. Without it, the latest version applies, and later
 *    dictionary maintenance retroactively changes the result of a re-run.
 *    This counts as an error here, not as a notice.
 * 2. **Phoneme rules on the wrong model.** ElevenLabs discards them without
 *    any error message if the model does not evaluate them. For German
 *    phonetic transcription, only Eleven v3 and v4 do (see Models).
 * 3. **Maintained but not pinned.** If a rule was added and the new version
 *    was not entered, the rule has no effect anywhere.
 */
final class DictionaryCheck implements CheckInterface
{
    private const BASE = 'https://api.elevenlabs.io/v1/pronunciation-dictionaries/';

    public function id(): string
    {
        return 'dictionary';
    }

    public function label(): string
    {
        return __('Pronunciation dictionary', 'sonoquill');
    }

    public function run(): Result
    {
        try {
            $key = Options::secret('elevenlabs_api_key');
        } catch (CryptoException $e) {
            return Result::fail(__('Credentials could not be read.', 'sonoquill'), $e->getMessage());
        }

        if ($key === '') {
            return Result::skip(__('No ElevenLabs API key configured.', 'sonoquill'));
        }

        $dictionaryId = Options::get('elevenlabs_dictionary_id');
        if ($dictionaryId === '') {
            return Result::skip(__('No dictionary configured.', 'sonoquill'));
        }

        $versionId = Options::get('elevenlabs_dictionary_version_id');
        if ($versionId === '') {
            return Result::fail(
                __('No version pinned.', 'sonoquill'),
                __('Every request must pass the version_id explicitly. Without it, the latest version applies, and later maintenance retroactively changes the result of a re-run.', 'sonoquill')
                . ' ' . __('Text approval is blocked until a version is pinned — use “Pin latest version” in the settings.', 'sonoquill')
            );
        }

        $meta = $this->fetchMeta($key, $dictionaryId);
        if ($meta instanceof Result) {
            return $meta;
        }

        $document = $this->fetchVersion($key, $dictionaryId, $versionId);
        if ($document instanceof Result) {
            return $document;
        }

        return $this->evaluate($meta, $document, $versionId);
    }

    /**
     * @return array<string,mixed>|Result Result only on failure.
     */
    private function fetchMeta(string $key, string $dictionaryId)
    {
        $response = wp_remote_get(self::BASE . rawurlencode($dictionaryId), [
            'timeout' => 10,
            'headers' => ['xi-api-key' => $key, 'accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return Result::fail(__('Not reachable.', 'sonoquill'), $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 401) {
            return Result::fail(__('API key not accepted (HTTP 401).', 'sonoquill'));
        }

        if ($code === 404) {
            /* translators: %s: dictionary ID */
            return Result::fail(sprintf(__('Dictionary "%s" does not exist.', 'sonoquill'), $dictionaryId));
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($body)) {
            /* translators: %d: HTTP status code */
            return Result::fail(sprintf(__('Unexpected response (HTTP %d).', 'sonoquill'), $code));
        }

        return $body;
    }

    /**
     * @return PlsDocument|Result Result only on failure.
     */
    private function fetchVersion(string $key, string $dictionaryId, string $versionId)
    {
        $url = self::BASE . rawurlencode($dictionaryId) . '/' . rawurlencode($versionId) . '/download';

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => ['xi-api-key' => $key],
        ]);

        if (is_wp_error($response)) {
            return Result::fail(__('Pinned version could not be retrieved.', 'sonoquill'), $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code === 404) {
            return Result::fail(
                /* translators: %s: pinned dictionary version ID */
                sprintf(__('The pinned version "%s" does not exist.', 'sonoquill'), $versionId),
                __('Was the dictionary created anew instead of extended? Then all existing pins point to nothing.', 'sonoquill')
            );
        }

        if ($code !== 200) {
            /* translators: %d: HTTP status code */
            return Result::fail(sprintf(__('Pinned version responds with HTTP %d.', 'sonoquill'), $code));
        }

        try {
            return PlsDocument::fromString((string) wp_remote_retrieve_body($response));
        } catch (PlsException $e) {
            return Result::fail(__('The pinned version is not a readable PLS file.', 'sonoquill'), $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function evaluate(array $meta, PlsDocument $document, string $versionId): Result
    {
        $name = isset($meta['name']) ? (string) $meta['name'] : '—';
        $latest = isset($meta['latest_version_id']) ? (string) $meta['latest_version_id'] : '';
        $latestRules = isset($meta['latest_version_rules_num']) ? (int) $meta['latest_version_rules_num'] : null;

        $detail = sprintf(
            /* translators: 1: dictionary name, 2: number of rules, 3: number of phonetic transcription rules, 4: number of terms */
            __('Dictionary "%1$s", pinned version: %2$d rules, %3$d of them as phonetic transcription. %4$d terms for the system prompt.', 'sonoquill'),
            $name,
            $document->ruleCount(),
            $document->phonemeRuleCount(),
            count($document->graphemes())
        );

        // The local file is the source of truth. If it is not writable,
        // every automatic maintenance run fails — silently, if nobody is
        // watching.
        $localProblem = self::localFileProblem();
        if ($localProblem !== '') {
            return Result::warn(__('The local PLS file is not writable.', 'sonoquill'), $detail . ' ' . $localProblem);
        }

        $model = Options::get('elevenlabs_model_id');

        if ($document->phonemeRuleCount() > 0 && !Models::evaluatesPhonemes($model)) {
            return Result::warn(
                /* translators: 1: number of phonetic transcription rules, 2: model ID */
                sprintf(__('%1$d phonetic transcription rules are silently discarded by model "%2$s".', 'sonoquill'), $document->phonemeRuleCount(), $model),
                $detail . __(' Only alias rules take effect on this model.', 'sonoquill')
            );
        }

        if ($document->phonemeRuleCount() > 0 && !Models::evaluatesPhonemesBeyondEnglish($model)) {
            return Result::warn(
                /* translators: %s: model ID */
                sprintf(__('Model "%s" evaluates phonetic transcription for English only.', 'sonoquill'), $model),
                $detail . __(' Phonetic transcription beyond English requires eleven_v4 or eleven_v3.', 'sonoquill')
            );
        }

        // Rules that the voice renders incorrectly. The guard catches them on
        // write; this is about rules that are already in the dictionary.
        $unbrauchbar = [];
        foreach ($document->rules() as $rule) {
            if ((string) $rule['type'] !== 'phoneme') {
                continue;
            }
            if (Models::needsCoarseIpa($model) && !PhonemeGuard::istSauber((string) $rule['value'])) {
                $unbrauchbar[] = (string) $rule['grapheme'];
            }
        }

        if ($unbrauchbar !== []) {
            return Result::warn(
                /* translators: %d: number of affected rules */
                sprintf(__('%d rules contain characters that the voice mispronounces.', 'sonoquill'), count($unbrauchbar)),
                $detail . sprintf(
                    /* translators: %s: comma-separated list of affected terms */
                    __(' Affected: %s. The phonetic transcription must not contain ◌̯, ◌̩ or ç.', 'sonoquill'),
                    implode(', ', $unbrauchbar)
                )
            );
        }

        if ($latest !== '' && $latest !== $versionId) {
            return Result::warn(
                __('There is a newer version than the pinned one.', 'sonoquill'),
                $detail . sprintf(
                    /* translators: %s: number of rules in the latest version, or "?" if unknown */
                    __(' The latest version has %s rules. Until it is entered, every rule added since then has no effect anywhere.', 'sonoquill'),
                    $latestRules !== null ? (string) $latestRules : '?'
                )
            );
        }

        /* translators: %s: path of the local PLS file */
        return Result::ok(__('Pinned and up to date.', 'sonoquill'), $detail . ' ' . sprintf(__('Local file: %s', 'sonoquill'), DictionaryWriter::path()));
    }

    /**
     * An empty string means: everything is fine.
     */
    private static function localFileProblem(): string
    {
        $path = DictionaryWriter::path();

        if (file_exists($path)) {
            return is_writable($path)
                ? ''
                /* translators: %s: path of the local PLS file */
                : sprintf(__('The file %s belongs to another user and could not be modified.', 'sonoquill'), $path);
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            /* translators: %s: directory path */
            return sprintf(__('The directory %s does not exist.', 'sonoquill'), $directory);
        }

        return is_writable($directory)
            ? ''
            /* translators: %s: directory path */
            : sprintf(__('The directory %s is not writable by the web server.', 'sonoquill'), $directory);
    }
}
