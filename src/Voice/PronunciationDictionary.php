<?php
declare(strict_types=1);

namespace Sonoquill\Voice;

use Sonoquill\Settings\Options;
use Sonoquill\Support\CryptoException;
use Sonoquill\Support\PlsDocument;
use Sonoquill\Support\PlsException;

/**
 * Loads the pinned version of the pronunciation dictionary.
 *
 * Always the explicitly configured version, never the latest: later
 * maintenance must not retroactively change the result of a re-run.
 *
 * The result is cached, with the version ID in the cache key. A new version
 * therefore invalidates the cache on its own, without anyone having to
 * remember to do so.
 */
final class PronunciationDictionary
{
    private const CACHE_PREFIX = 'aaspf_dict_';
    private const CACHE_TTL    = 6 * HOUR_IN_SECONDS;

    /**
     * The graphemes of the pinned version, for the system prompt.
     *
     * An empty array means: no dictionary configured or not reachable.
     * That is no reason to abort — the edit then runs without the list, and
     * the UI says so.
     *
     * @return list<string>
     */
    public static function graphemes(): array
    {
        $document = self::document();

        return $document === null ? [] : $document->graphemes();
    }

    /**
     * Discards the cache of the pinned version.
     *
     * Needed after a rule has been added and the new version has been pinned.
     */
    public static function forget(): void
    {
        $id = Options::get('elevenlabs_dictionary_id');
        $version = Options::get('elevenlabs_dictionary_version_id');

        if ($id !== '' && $version !== '') {
            delete_transient(self::CACHE_PREFIX . md5($id . '|' . $version));
        }
    }

    public static function document(): ?PlsDocument
    {
        $id = Options::get('elevenlabs_dictionary_id');
        $version = Options::get('elevenlabs_dictionary_version_id');

        if ($id === '' || $version === '') {
            return null;
        }

        $cacheKey = self::CACHE_PREFIX . md5($id . '|' . $version);
        $cached = get_transient($cacheKey);

        if (is_string($cached) && $cached !== '') {
            try {
                return PlsDocument::fromString($cached);
            } catch (PlsException $e) {
                delete_transient($cacheKey);
            }
        }

        try {
            $key = Options::secret('elevenlabs_api_key');
        } catch (CryptoException $e) {
            return null;
        }

        if ($key === '') {
            return null;
        }

        $url = sprintf(
            'https://api.elevenlabs.io/v1/pronunciation-dictionaries/%s/%s/download',
            rawurlencode($id),
            rawurlencode($version)
        );

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => ['xi-api-key' => $key],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = (string) wp_remote_retrieve_body($response);

        try {
            $document = PlsDocument::fromString($body);
        } catch (PlsException $e) {
            return null;
        }

        set_transient($cacheKey, $body, self::CACHE_TTL);

        return $document;
    }

    /**
     * The section appended to the system prompt at runtime.
     *
     * It states the hard reason for the rules: the WebVTT transcript is
     * generated from the same text that is spoken. A phonetic respelling in
     * the script ends up in the published transcript; a dictionary rule
     * does not.
     */
    public static function promptSection(): string
    {
        $graphemes = self::graphemes();

        if (!\Sonoquill\Settings\Options::isGerman()) {
            if ($graphemes === []) {
                return "## PRONUNCIATION DICTIONARY\n\n"
                    . "No pronunciation dictionary is connected at the moment.\n\n"
                    . "Still, do not write any phonetic spelling or transcription into the text. "
                    . "Whatever is spelled phonetically ends up like that in the published transcript.\n";
            }

            return "## PRONUNCIATION DICTIONARY\n\n"
                . "The pronunciation of these terms is already stored and will be spoken correctly:\n\n"
                . implode(', ', $graphemes) . "\n\n"
                . "Write them exactly in this spelling. The rules only match exactly and only at word "
                . "boundaries — an inflected or compound form no longer matches. Use the base form where possible.\n\n"
                . "Do not write any phonetic spelling into the text, not even for terms missing from the list. "
                . "Whatever is spelled phonetically ends up like that in the published transcript; a dictionary "
                . "rule stays invisible there.\n";
        }

        if ($graphemes === []) {
            return "## AUSSPRACHEWÖRTERBUCH\n\n"
                . "Es ist derzeit kein Aussprachewörterbuch eingebunden.\n\n"
                . "Schreibe trotzdem keine eigene Lautschrift und keine phonetische Umschreibung "
                . "in den Text. Was phonetisch umschrieben wird, steht anschließend so im "
                . "veröffentlichten Transkript.\n";
        }

        return "## AUSSPRACHEWÖRTERBUCH\n\n"
            . "Für diese Begriffe ist die Aussprache bereits hinterlegt. Sie werden beim "
            . "Sprechen automatisch richtig ausgesprochen:\n\n"
            . implode(', ', $graphemes) . "\n\n"
            . "Schreibe sie genau in dieser Schreibweise. Die Regeln greifen nur bei exakter "
            . "Übereinstimmung und nur an Wortgrenzen — eine gebeugte oder zusammengesetzte "
            . "Form greift nicht mehr. Wo eine ungebeugte Form möglich ist, verwende sie.\n\n"
            . "Schreibe keine eigene Lautschrift und keine phonetische Umschreibung in den Text, "
            . "auch nicht für Begriffe, die nicht in der Liste stehen. Was phonetisch umschrieben "
            . "wird, steht anschließend so im veröffentlichten Transkript; eine Wörterbuchregel "
            . "dagegen bleibt dort unsichtbar.\n";
    }
}
