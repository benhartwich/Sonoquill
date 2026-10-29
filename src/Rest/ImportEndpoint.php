<?php
declare(strict_types=1);

namespace Sonoquill\Rest;

use Sonoquill\Import\SettingsImport;
use Sonoquill\Settings\Options;
use Sonoquill\Support\CryptoException;
use Sonoquill\Support\KeyStore;
use Sonoquill\Voice\DictionaryWriter;
use Sonoquill\Voice\PronunciationDictionary;

/**
 * Import of settings prepared on a platform (for example the podcast studio
 * of radioplattform.eu).
 *
 *     POST /wp-json/sonoquill/v1/import          take over settings
 *     GET  /wp-json/sonoquill/v1/import/status   current state, never credentials
 *
 * Only for users with manage_options; the platform authenticates with an
 * application password. Every import carries a version: an older version is
 * refused, the same version with the same content is answered as unchanged
 * (idempotent), the same version with different content is refused. Each
 * import is logged in the option sonoquill_import (last 20 entries, without
 * credentials). Settings that are not sent stay as they are.
 */
final class ImportEndpoint
{
    public const NAMESPACE = AuphonicWebhook::NAMESPACE;
    public const STATE_OPTION = 'sonoquill_import';
    private const LOCK_OPTION = 'sonoquill_import_lock';
    private const LOG_ENTRIES = 20;

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/import', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'import'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
        register_rest_route(self::NAMESPACE, '/import/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'status'],
            'permission_callback' => [self::class, 'authorize'],
        ]);
    }

    public static function authorize(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function import(\WP_REST_Request $request)
    {
        $result = self::handle($request->get_json_params(), (int) get_current_user_id());
        if (isset($result['error'])) {
            return new \WP_Error($result['error'][0], $result['error'][1], ['status' => $result['error'][2]] + ($result['error'][3] ?? []));
        }

        return new \WP_REST_Response($result, 200);
    }

    public static function status(): \WP_REST_Response
    {
        return new \WP_REST_Response(self::state(), 200);
    }

    /**
     * The import itself, without the REST layer (testable).
     *
     * @param mixed $payload
     *
     * @return array<string,mixed> Response body, or ['error' => [code, message, http status, extra data]].
     */
    public static function handle($payload, int $userId): array
    {
        $check = SettingsImport::validate($payload);
        if ($check['errors'] !== []) {
            return ['error' => ['sonoquill_import_invalid', __('The import does not match the expected schema.', 'sonoquill'), 400, ['fehler' => $check['errors']]]];
        }

        $state = self::storedState();
        $fingerprint = SettingsImport::fingerprint($check['values'], $check['keys'], wp_salt('auth'));
        $storedVersion = (int) ($state['version'] ?? 0);

        if ($check['version'] < $storedVersion) {
            return ['error' => ['sonoquill_import_outdated', sprintf(
                /* translators: 1: version sent, 2: version already imported */
                __('Version %1$d is older than the imported version %2$d.', 'sonoquill'),
                $check['version'],
                $storedVersion
            ), 409, ['version' => $storedVersion]]];
        }

        if ($check['version'] === $storedVersion) {
            if (hash_equals((string) ($state['pruefsumme'] ?? ''), $fingerprint)) {
                return self::response($storedVersion, true, array_map(static fn () => 'unveraendert', (array) ($state['felder'] ?? [])), self::keyState(), (string) ($state['zeit'] ?? ''));
            }

            return ['error' => ['sonoquill_import_version_taken', sprintf(
                /* translators: %d: version */
                __('Version %d was already imported with different content.', 'sonoquill'),
                $storedVersion
            ), 409, ['version' => $storedVersion]]];
        }

        if (!self::lock()) {
            return ['error' => ['sonoquill_import_busy', __('Another import is running.', 'sonoquill'), 409]];
        }

        try {
            $crypto = null;
            if ($check['keys'] !== []) {
                if (!KeyStore::isConfigured()) {
                    return ['error' => ['sonoquill_import_no_key', KeyStore::missingKeyMessage() . __(' Nothing was saved.', 'sonoquill'), 409]];
                }
                $crypto = KeyStore::crypto();
            }

            $before = Options::all();
            $merged = SettingsImport::apply($before, $check['values'], $check['keys'], $crypto);
            if ($merged['changed'] !== []) {
                Options::save($merged['settings']);
                self::afterDictionaryChange($before, $merged['settings']);
            }
        } catch (CryptoException $e) {
            return ['error' => ['sonoquill_import_crypto', $e->getMessage() . __(' Nothing was saved.', 'sonoquill'), 409]];
        } finally {
            self::unlock();
        }

        $time = gmdate('c');
        $log = (array) ($state['protokoll'] ?? []);
        $log[] = [
            'zeit'      => $time,
            'version'   => $check['version'],
            'quelle'    => $check['source'],
            'benutzer'  => $userId,
            'geaendert' => $merged['changed'],
        ];

        update_option(self::STATE_OPTION, [
            'version'    => $check['version'],
            'pruefsumme' => $fingerprint,
            'zeit'       => $time,
            'quelle'     => $check['source'],
            'felder'     => $merged['felder'],
            'protokoll'  => array_slice($log, -self::LOG_ENTRIES),
        ], false);

        /**
         * Fires after settings were imported from a platform.
         *
         * @param int          $version Imported version.
         * @param list<string> $changed Names of the settings that changed (credentials only by name).
         */
        do_action('sonoquill_settings_imported', $check['version'], $merged['changed']);

        return self::response($check['version'], $merged['changed'] === [], $merged['felder'], $merged['schluessel'], $time);
    }

    /**
     * Current state for the platform: imported version, the values the
     * import manages (without credentials) and whether each key is set.
     *
     * @return array<string,mixed>
     */
    public static function state(): array
    {
        $state = self::storedState();
        $all = Options::all();
        $get = static fn (string $field): string => (string) ($all[$field] ?? '');

        return [
            'ok'                => true,
            'plugin_version'    => defined('AASPF_VERSION') ? AASPF_VERSION : '',
            'schema'            => SettingsImport::SCHEMA,
            'version'           => (int) ($state['version'] ?? 0),
            'zeit'              => (string) ($state['zeit'] ?? ''),
            'podcast'           => [
                'name'         => $get('podcast_name'),
                'moderation'   => $get('podcast_host'),
                'schlussgruss' => $get('podcast_sign_off'),
                'sprache'      => $get('podcast_language'),
            ],
            'stimme'            => ['voice_id' => $get('elevenlabs_voice_id'), 'modell' => $get('elevenlabs_model_id')],
            'woerterbuch'       => ['id' => $get('elevenlabs_dictionary_id'), 'version' => $get('elevenlabs_dictionary_version_id')],
            'auphonic'          => ['preset' => $get('auphonic_preset')],
            'konzept_gesetzt'   => $get('podcast_concept') !== '',
            'stil_gesetzt'      => $get('style_guide') !== '',
            'schluessel'        => self::keyState(),
            'verschluesselung'  => KeyStore::isConfigured(),
            'protokoll'         => array_slice((array) ($state['protokoll'] ?? []), -10),
        ];
    }

    /**
     * @param array<string,string>                                  $fields
     * @param array<string,array{gesetzt:bool,status?:string}>      $keys
     *
     * @return array<string,mixed>
     */
    private static function response(int $version, bool $unchanged, array $fields, array $keys, string $time): array
    {
        return [
            'ok'           => true,
            'version'      => $version,
            'unveraendert' => $unchanged,
            'felder'       => $fields,
            'schluessel'   => $keys,
            'zeit'         => $time,
        ];
    }

    /** @return array<string,array{gesetzt:bool}> */
    private static function keyState(): array
    {
        $out = [];
        foreach (SettingsImport::KEYS as $field) {
            $out[$field] = ['gesetzt' => Options::hasSecret($field)];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private static function storedState(): array
    {
        $state = get_option(self::STATE_OPTION, []);

        return is_array($state) ? $state : [];
    }

    /**
     * A different dictionary or version: the local PLS file belongs to the
     * old state. It is set aside (not deleted) so that DictionaryWriter
     * loads the bound version again the next time a rule is added.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private static function afterDictionaryChange(array $before, array $after): void
    {
        $id = 'elevenlabs_dictionary_id';
        $version = 'elevenlabs_dictionary_version_id';
        if (($before[$id] ?? '') === ($after[$id] ?? '') && ($before[$version] ?? '') === ($after[$version] ?? '')) {
            return;
        }

        $path = DictionaryWriter::path();
        if (is_file($path)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Keeps the previous file next to the new state.
            @rename($path, $path . '.before-import-' . gmdate('Ymd-His'));
        }

        if (($before[$id] ?? '') !== '' && ($before[$version] ?? '') !== '') {
            delete_transient('aaspf_dict_' . md5($before[$id] . '|' . $before[$version]));
        }
        PronunciationDictionary::forget();
    }

    /** Simple lock against two imports at the same time (60 s). */
    private static function lock(): bool
    {
        $since = (int) get_option(self::LOCK_OPTION, 0);
        if ($since > 0 && time() - $since < 60) {
            return false;
        }
        update_option(self::LOCK_OPTION, time(), false);

        return true;
    }

    private static function unlock(): void
    {
        delete_option(self::LOCK_OPTION);
    }
}
