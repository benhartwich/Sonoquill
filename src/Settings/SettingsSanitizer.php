<?php
declare(strict_types=1);

namespace Sonoquill\Settings;

use Sonoquill\Support\Crypto;
use Sonoquill\Support\CryptoException;
use Sonoquill\Support\KeyStore;

/**
 * Validates and encrypts the submitted settings.
 *
 * Two rules determine the behaviour:
 *
 * 1. An empty secret field means "leave unchanged", not "delete".
 *    Otherwise every save of the remaining fields would wipe the credentials.
 *    Each field has its own checkbox for deleting it.
 * 2. If the encryption key is missing, nothing is saved at all. There is
 *    no silent fallback to plain text.
 */
final class SettingsSanitizer
{
    /** Multi-line fields. sanitize_text_field would discard the line breaks. */
    private const TEXTAREA_FIELDS = [
        'ai_disclosure_text',
        'ai_disclosure_audio_text',
    ];

    /** Checkboxes: if missing from the request, the box is unchecked. */
    private const CHECKBOX_FIELDS = [
        'delete_data_on_uninstall',
        'anthropic_batch',
        'music_enabled',
        'number_gate',
    ];

    /** Fields that are stored in plain text. */
    private const TEXT_FIELDS = [
        'podcast_name',
        'podcast_host',
        'podcast_editor',
        'podcast_sign_off',
        'file_prefix',
        'podcast_language',
        'elevenlabs_voice_id',
        'elevenlabs_model_id',
        'elevenlabs_dictionary_id',
        'elevenlabs_dictionary_version_id',
        'anthropic_model',
        'anthropic_model_research',
        'anthropic_workspace_id',
        'auphonic_preset',
        'prompt_dir',
        'voice_stability',
        'voice_similarity',
        'voice_style',
        'voice_speed',
        'voice_speaker_boost',
        'tts_output_format',
        'chapter_pause_ms',
        'paragraph_pause_ms',
        'montage_mode',
        'ffmpeg_path',
        'ffprobe_path',
    ];

    /** Decimal numbers with a dot or comma, each with bounds. */
    private const NUMBER_FIELDS = [];

    /**
     * The fields of one kind, extended by those of the add-ons.
     *
     * Filter `sonoquill_settings_fields`: an array with the keys
     * text, textarea, checkbox (each a list of field names) and number
     * (field name => [min, max]).
     *
     * @return array<int|string,mixed>
     */
    private static function fields(string $kind): array
    {
        $core = match ($kind) {
            'text'     => self::TEXT_FIELDS,
            'textarea' => self::TEXTAREA_FIELDS,
            'checkbox' => self::CHECKBOX_FIELDS,
            'number'   => self::NUMBER_FIELDS,
            default    => [],
        };

        $extra = (array) apply_filters('sonoquill_settings_fields', []);
        $added = (array) ($extra[$kind] ?? []);

        return $kind === 'number' ? $core + $added : array_values(array_unique(array_merge($core, $added)));
    }

    /**
     * @param mixed $input
     *
     * @return array<string,mixed>
     */
    public static function sanitize($input): array
    {
        $current = Options::all();

        if (!is_array($input)) {
            return $current;
        }

        $out = $current;

        foreach (self::fields('text') as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = sanitize_text_field((string) $input[$field]);
            }
        }

        foreach (self::fields('textarea') as $field) {
            if (array_key_exists($field, $input)) {
                $out[$field] = sanitize_textarea_field((string) $input[$field]);
            }
        }

        foreach (self::fields('checkbox') as $field) {
            $out[$field] = !empty($input[$field]);
        }

        foreach (self::fields('number') as $field => [$min, $max]) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $raw = str_replace(',', '.', trim((string) $input[$field]));
            if (is_numeric($raw) && (float) $raw >= $min && (float) $raw <= $max) {
                $out[$field] = $raw;
            } else {
                /* translators: %s: name of the settings field */
                add_settings_error(Options::OPTION, 'aaspf_number_' . $field, sprintf(__('Invalid value for %s — the previous value is kept.', 'sonoquill'), $field), 'warning');
            }
        }

        // Any language code, normalised ("pt_BR" → "pt-br"); anything else means "same as the site".
        if (array_key_exists('podcast_language', $input)) {
            $out['podcast_language'] = Options::normalizeLanguage((string) $input['podcast_language']);
        }

        if (array_key_exists('notify_email', $input)) {
            $mails = array_filter(array_map('trim', explode(',', (string) $input['notify_email'])));
            $valid = array_values(array_filter(array_map('sanitize_email', $mails), 'is_email'));
            if (count($valid) !== count($mails)) {
                add_settings_error(Options::OPTION, 'aaspf_notify_email', __('At least one e-mail address was invalid and has been discarded.', 'sonoquill'), 'warning');
            }
            $out['notify_email'] = implode(', ', $valid);
        }

        // Collect newly entered secrets before anything is written.
        $newSecrets = [];
        foreach (Options::SECRET_FIELDS as $field) {
            $value = isset($input[$field]) ? trim((string) $input[$field]) : '';
            // An already encrypted value is not a newly entered key but the
            // stored one that was passed through (for example via
            // Options::save in an admin request). Encrypted a second time it
            // would be unusable — exactly that happened on 26.09.2026.
            if ($value !== '' && !Crypto::looksEncrypted($value)) {
                $newSecrets[$field] = $value;
            }
        }

        if ($newSecrets !== []) {
            if (!KeyStore::isConfigured()) {
                add_settings_error(
                    Options::OPTION,
                    'aaspf_missing_key',
                    KeyStore::missingKeyMessage() . __(' Nothing was saved.', 'sonoquill'),
                    'error'
                );

                return $current;
            }

            try {
                $crypto = KeyStore::crypto();
            } catch (CryptoException $e) {
                add_settings_error(Options::OPTION, 'aaspf_broken_key', $e->getMessage() . __(' Nothing was saved.', 'sonoquill'), 'error');

                return $current;
            }

            foreach ($newSecrets as $field => $value) {
                try {
                    $out[$field] = $crypto->encrypt($value);
                } catch (CryptoException $e) {
                    add_settings_error(Options::OPTION, 'aaspf_encrypt_failed', $e->getMessage(), 'error');

                    return $current;
                }
            }
        }

        // Deleting only takes effect if no new value was entered in the same submission.
        foreach (Options::SECRET_FIELDS as $field) {
            if (!isset($newSecrets[$field]) && !empty($input['clear_' . $field])) {
                $out[$field] = '';
            }
        }

        return $out;
    }
}
