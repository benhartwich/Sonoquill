<?php
declare(strict_types=1);

namespace Sonoquill\Import;

use Sonoquill\Settings\Options;
use Sonoquill\Support\Crypto;
use Sonoquill\Support\CryptoException;

/**
 * Settings handed over by a platform (for example the podcast studio of
 * radioplattform.eu): concept, style, voice, pronunciation dictionary,
 * Auphonic preset and the three credentials.
 *
 * Pure logic without WordPress: the strict schema check and the merge into
 * the stored settings. The REST endpoint (Rest\ImportEndpoint) handles
 * permissions, versions, idempotency and the log.
 *
 * Rules:
 * - Unknown keys are rejected, nothing is stored from an invalid payload.
 * - Only fields that are sent change; everything else in aaspf_settings stays.
 * - Credentials are stored encrypted with the existing Crypto and are never
 *   returned; the result only says whether a key is set ("gesetzt").
 *
 * The payload uses the German field names of the platform contract (schema 1).
 */
final class SettingsImport
{
    public const SCHEMA = 1;

    /** Payload path => settings field. */
    public const FIELDS = [
        'podcast.name'         => 'podcast_name',
        'podcast.moderation'   => 'podcast_host',
        'podcast.schlussgruss' => 'podcast_sign_off',
        'podcast.sprache'      => 'podcast_language',
        'konzept'              => 'podcast_concept',
        'stil'                 => 'style_guide',
        'stimme.voice_id'      => 'elevenlabs_voice_id',
        'stimme.modell'        => 'elevenlabs_model_id',
        'woerterbuch.id'       => 'elevenlabs_dictionary_id',
        'woerterbuch.version'  => 'elevenlabs_dictionary_version_id',
        'auphonic.preset'      => 'auphonic_preset',
    ];

    /** Payload key under "schluessel" => secret settings field. */
    public const KEYS = [
        'elevenlabs' => 'elevenlabs_api_key',
        'anthropic'  => 'anthropic_api_key',
        'auphonic'   => 'auphonic_api_key',
    ];

    private const TOP_LEVEL = ['schema', 'version', 'quelle', 'podcast', 'konzept', 'stil', 'stimme', 'woerterbuch', 'auphonic', 'schluessel'];

    private const OBJECTS = [
        'podcast'     => ['name', 'moderation', 'schlussgruss', 'sprache'],
        'stimme'      => ['voice_id', 'modell'],
        'woerterbuch' => ['id', 'version'],
        'auphonic'    => ['preset'],
        'schluessel'  => ['elevenlabs', 'anthropic', 'auphonic'],
    ];

    /** Single-line text fields: maximum length. */
    private const LINES = ['podcast.name' => 200, 'podcast.moderation' => 200, 'podcast.schlussgruss' => 300];

    /** Multi-line text fields: maximum length. */
    private const TEXTS = ['konzept' => 20000, 'stil' => 20000];

    /** Identifiers: pattern. */
    private const IDS = [
        'stimme.voice_id'     => '/^[A-Za-z0-9]{8,64}$/',
        'stimme.modell'       => '/^[a-z0-9_]{3,64}$/',
        'woerterbuch.id'      => '/^[A-Za-z0-9]{8,64}$/',
        'woerterbuch.version' => '/^[A-Za-z0-9]{8,64}$/',
        'auphonic.preset'     => '/^[A-Za-z0-9]{6,64}$/',
    ];

    /** Credentials: printable, no whitespace, plausible length. */
    private const KEY_PATTERN = '/^[A-Za-z0-9_\-.:]{16,400}$/';

    /**
     * Checks the payload strictly and returns the normalised values.
     *
     * @param mixed $payload
     *
     * @return array{errors:list<string>,version:int,source:string,values:array<string,string>,keys:array<string,string>}
     */
    public static function validate($payload): array
    {
        $errors = [];
        $values = [];
        $keys = [];

        if (!is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            return ['errors' => ['The request body must be a JSON object.'], 'version' => 0, 'source' => '', 'values' => [], 'keys' => []];
        }

        foreach (array_keys($payload) as $key) {
            if (!in_array($key, self::TOP_LEVEL, true)) {
                $errors[] = sprintf('Unknown field "%s".', (string) $key);
            }
        }

        if (($payload['schema'] ?? null) !== self::SCHEMA) {
            $errors[] = sprintf('Field "schema" must be %d.', self::SCHEMA);
        }

        $version = $payload['version'] ?? null;
        if (!is_int($version) || $version < 1 || $version > 2147483647) {
            $errors[] = 'Field "version" must be a positive integer.';
        }

        $source = $payload['quelle'] ?? '';
        if (!is_string($source) || preg_match('/^[a-z0-9_.-]{0,40}$/', $source) !== 1) {
            $errors[] = 'Field "quelle" must be a short identifier.';
            $source = '';
        }

        foreach (self::OBJECTS as $object => $allowed) {
            if (!array_key_exists($object, $payload)) {
                continue;
            }
            $value = $payload[$object];
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                $errors[] = sprintf('Field "%s" must be an object.', $object);
                continue;
            }
            foreach (array_keys($value) as $key) {
                if (!in_array($key, $allowed, true)) {
                    $errors[] = sprintf('Unknown field "%s.%s".', $object, (string) $key);
                }
            }
        }

        if (isset($payload['woerterbuch']) && is_array($payload['woerterbuch'])
            && (isset($payload['woerterbuch']['id']) xor isset($payload['woerterbuch']['version']))) {
            $errors[] = 'Fields "woerterbuch.id" and "woerterbuch.version" belong together — a dictionary is only used with a pinned version.';
        }

        foreach (self::FIELDS as $path => $field) {
            $raw = self::pick($payload, $path);
            if ($raw === null) {
                continue;
            }
            if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
                $errors[] = sprintf('Field "%s" must be a UTF-8 string.', $path);
                continue;
            }

            if (isset(self::LINES[$path])) {
                $clean = self::line($raw);
                if ($clean === '' || mb_strlen($clean) > self::LINES[$path]) {
                    $errors[] = sprintf('Field "%s" must have 1 to %d characters.', $path, self::LINES[$path]);
                    continue;
                }
                $values[$field] = $clean;
            } elseif (isset(self::TEXTS[$path])) {
                $clean = self::text($raw);
                if ($clean === '' || mb_strlen($clean) > self::TEXTS[$path]) {
                    $errors[] = sprintf('Field "%s" must have 1 to %d characters.', $path, self::TEXTS[$path]);
                    continue;
                }
                $values[$field] = $clean;
            } elseif (isset(self::IDS[$path])) {
                if (preg_match(self::IDS[$path], $raw) !== 1) {
                    $errors[] = sprintf('Field "%s" has an invalid format.', $path);
                    continue;
                }
                $values[$field] = $raw;
            } elseif ($path === 'podcast.sprache') {
                $language = Options::normalizeLanguage($raw);
                if ($language === '') {
                    $errors[] = 'Field "podcast.sprache" must be a language code such as "de".';
                    continue;
                }
                $values[$field] = $language;
            }
        }

        foreach (self::KEYS as $name => $field) {
            $raw = self::pick($payload, 'schluessel.' . $name);
            if ($raw === null) {
                continue;
            }
            if (!is_string($raw) || preg_match(self::KEY_PATTERN, $raw) !== 1) {
                // Never echo the value itself.
                $errors[] = sprintf('Credential "schluessel.%s" has an invalid format.', $name);
                continue;
            }
            $keys[$field] = $raw;
        }

        return [
            'errors'  => $errors,
            'version' => is_int($version) ? $version : 0,
            'source'  => is_string($source) ? $source : '',
            'values'  => $errors === [] ? $values : [],
            'keys'    => $errors === [] ? $keys : [],
        ];
    }

    /**
     * Merges validated values into the stored settings.
     *
     * Status per field: "geaendert", "unveraendert" or "nicht_gesendet".
     * Credentials: ['gesetzt' => bool, 'status' => …] — never the value.
     *
     * @param array<string,mixed>  $current All stored settings (Options::all()).
     * @param array<string,string> $values
     * @param array<string,string> $keys    Plain-text credentials (validated).
     *
     * @return array{settings:array<string,mixed>,felder:array<string,string>,schluessel:array<string,array{gesetzt:bool,status:string}>,changed:list<string>}
     *
     * @throws CryptoException if credentials are sent but cannot be encrypted.
     */
    public static function apply(array $current, array $values, array $keys, ?Crypto $crypto): array
    {
        $settings = $current;
        $fields = [];
        $changed = [];

        foreach (self::FIELDS as $field) {
            if (!array_key_exists($field, $values)) {
                $fields[$field] = 'nicht_gesendet';
                continue;
            }
            if ((string) ($current[$field] ?? '') === $values[$field]) {
                $fields[$field] = 'unveraendert';
                continue;
            }
            $settings[$field] = $values[$field];
            $fields[$field] = 'geaendert';
            $changed[] = $field;
        }

        if ($keys !== [] && $crypto === null) {
            throw new CryptoException('The encryption key is missing — no credentials were stored.');
        }

        $secrets = [];
        foreach (self::KEYS as $field) {
            $stored = (string) ($current[$field] ?? '');
            if (!array_key_exists($field, $keys)) {
                $secrets[$field] = ['gesetzt' => $stored !== '', 'status' => 'nicht_gesendet'];
                continue;
            }
            if ($stored !== '' && Crypto::looksEncrypted($stored) && self::decrypts($crypto, $stored, $keys[$field])) {
                $secrets[$field] = ['gesetzt' => true, 'status' => 'unveraendert'];
                continue;
            }
            $settings[$field] = $crypto->encrypt($keys[$field]);
            $secrets[$field] = ['gesetzt' => true, 'status' => 'geaendert'];
            $changed[] = $field;
        }

        return ['settings' => $settings, 'felder' => $fields, 'schluessel' => $secrets, 'changed' => $changed];
    }

    /**
     * Fingerprint of a payload for idempotency: HMAC over the normalised
     * values and credentials, so the credentials themselves are never stored.
     *
     * @param array<string,string> $values
     * @param array<string,string> $keys
     */
    public static function fingerprint(array $values, array $keys, string $secret): string
    {
        ksort($values);
        ksort($keys);

        return hash_hmac('sha256', (string) json_encode([$values, $keys]), $secret);
    }

    /** Does the stored envelope contain exactly this plaintext? */
    private static function decrypts(?Crypto $crypto, string $envelope, string $plaintext): bool
    {
        if ($crypto === null) {
            return false;
        }

        try {
            return hash_equals($crypto->decrypt($envelope), $plaintext);
        } catch (CryptoException $e) {
            // Stored with another key or damaged: overwrite it.
            return false;
        }
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return mixed
     */
    private static function pick(array $payload, string $path)
    {
        $value = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return $value;
    }

    /** One line: no tags, no control characters, single spaces. */
    private static function line(string $value): string
    {
        $value = strip_tags($value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** Multi-line text: no tags, line breaks kept, other control characters removed. */
    private static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", strip_tags($value));
        $value = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $value));
    }
}
