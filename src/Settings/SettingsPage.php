<?php
declare(strict_types=1);

namespace Sonoquill\Settings;

use Sonoquill\Db\Schema;
use Sonoquill\Health\Registry;
use Sonoquill\Jobs\AdminActions;
use Sonoquill\Jobs\Scheduler;
use Sonoquill\Support\KeyStore;

/**
 * The settings page under Tools.
 *
 * It shows three things: the credentials, the state of the four services and
 * proof that the job chain is running.
 */
final class SettingsPage
{
    public const MENU_SLUG   = 'sonoquill';
    public const CAPABILITY  = 'manage_options';
    public const OPTION_GROUP = 'aaspf_settings_group';

    private static string $hookSuffix = '';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function addMenu(): void
    {
        $hook = add_submenu_page(
            \Sonoquill\Admin\EpisodesPage::MENU_SLUG,
            __('Sonoquill — Settings', 'sonoquill'),
            __('Settings', 'sonoquill'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [self::class, 'render']
        );

        self::$hookSuffix = is_string($hook) ? $hook : '';
    }

    public static function registerSettings(): void
    {
        register_setting(self::OPTION_GROUP, Options::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [SettingsSanitizer::class, 'sanitize'],
            'default'           => Options::defaults(),
        ]);
    }

    public static function enqueue(string $hookSuffix): void
    {
        if ($hookSuffix === '' || $hookSuffix !== self::$hookSuffix) {
            return;
        }

        wp_enqueue_style(
            'aaspf-admin',
            AASPF_PLUGIN_URL . 'assets/admin.css',
            [],
            \Sonoquill\Admin\Assets::version('assets/admin.css')
        );

        wp_enqueue_script(
            'aaspf-admin',
            AASPF_PLUGIN_URL . 'assets/admin.js',
            [],
            \Sonoquill\Admin\Assets::version('assets/admin.js'),
            true
        );

        wp_localize_script('aaspf-admin', 'aaspfAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('aaspf_health'),
            'action'  => 'aaspf_health_check',
            'strings' => [
                'running' => __('checking …', 'sonoquill'),
                'failed'  => __('Check failed.', 'sonoquill'),
            ],
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do this.', 'sonoquill'));
        }

        echo '<div class="wrap aaspf-wrap">';
        echo '<h1>' . esc_html__('Sonoquill', 'sonoquill') . '</h1>';

        settings_errors(Options::OPTION);
        AdminActions::renderNotice();
        \Sonoquill\Admin\EpisodeActions::renderNotice();

        self::renderKeyStatus();
        self::renderForm();
        self::renderHealth();
        self::renderJobs();
        self::renderStorage();

        echo '</div>';
    }

    private static function renderKeyStatus(): void
    {
        if (KeyStore::isConfigured()) {
            return;
        }

        // One suggestion per page load, otherwise every reload shows a different one.
        static $suggestion = null;
        if ($suggestion === null) {
            $suggestion = KeyStore::suggestedConfigLine();
        }

        echo '<div class="notice notice-error aaspf-keynotice">';
        echo '<p><strong>' . esc_html__('Credentials could not be stored yet.', 'sonoquill') . '</strong></p>';
        echo '<p>' . esc_html(KeyStore::missingKeyMessage()) . '</p>';
        echo '<p>' . esc_html__('Add this line to wp-config.php, above "That\'s all, stop editing!":', 'sonoquill') . '</p>';
        echo '<pre class="aaspf-config-line"><code>' . esc_html($suggestion) . '</code></pre>';
        echo '<p class="description">' . esc_html__('A new key is suggested on every page load. Once it is in wp-config.php, do not change it — otherwise the credentials already stored will no longer be readable.', 'sonoquill') . '</p>';
        echo '</div>';
    }

    private static function renderForm(): void
    {
        $values = Options::all();

        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);

        self::renderSection(
            __('Podcast', 'sonoquill'),
            __('Details used in prompts, show notes, notices and file names.', 'sonoquill'),
            [
                self::textRow('podcast_name', __('Podcast name', 'sonoquill'), $values, sprintf(
                    /* translators: %s: derived name */
                    __('Leave empty to use the title from Podlove or the site name. Currently: %s', 'sonoquill'),
                    Options::podcastName()
                )),
                self::selectRow('podcast_language', __('Podcast language', 'sonoquill'), $values, self::languageChoices($values), __('Determines the prompts and the number matching. Bundled prompts exist for German and English; for other languages the English prompts apply until you save your own (Prompts page or prompt directory, subfolder per language code). The number check only understands German and English figures.', 'sonoquill')),
                self::checkboxRow('number_gate', __('Number check', 'sonoquill'), $values, __('Compare every figure in the spoken script with the source and lock approval on deviations. Only works for German and English; for other languages it is always off.', 'sonoquill')),
                self::textRow('podcast_host', __('Host', 'sonoquill'), $values, __('Whose voice reads as the clone. Appears in the AI notice ({host}) and in the greeting.', 'sonoquill')),
                self::textRow('podcast_editor', __('Editor', 'sonoquill'), $values, __('Optional. Who writes the drafts; mentioned in the greeting ({editor}).', 'sonoquill')),
                self::textRow('podcast_sign_off', __('Sign-off', 'sonoquill'), $values, __('Optional. A fixed farewell at the end of every episode ({sign_off}).', 'sonoquill')),
                self::textRow('file_prefix', __('File name prefix', 'sonoquill'), $values, sprintf(
                    /* translators: %s: example */
                    __('Letters and digits only. Empty: derived from the name. Example: %s', 'sonoquill'),
                    Options::filePrefix() . 'Oktober2026.mp3'
                )),
            ]
        );

        self::renderSection(
            __('ElevenLabs', 'sonoquill'),
            __('Speech synthesis and Voice Changer.', 'sonoquill'),
            [
                self::secretRow('elevenlabs_api_key', __('API key', 'sonoquill'), $values),
                self::textRow('elevenlabs_voice_id', __('Voice ID', 'sonoquill'), $values, __('ID of the voice clone.', 'sonoquill')),
                self::textRow('elevenlabs_model_id', __('Model', 'sonoquill'), $values, __('Recommended: eleven_v4 — like eleven_v3 it applies phonetic (IPA) pronunciation rules in German too; eleven_multilingual_v2 ignores them.', 'sonoquill')),
                self::textRow('elevenlabs_dictionary_id', __('Dictionary ID', 'sonoquill'), $values, __('There is exactly one dictionary, which grows through new versions. If it is created anew instead of extended, all existing bindings point to nothing.', 'sonoquill')),
                self::textRow('elevenlabs_dictionary_version_id', __('Dictionary version', 'sonoquill'), $values, __('Required. Passed explicitly with every request so that later maintenance does not retroactively change the result of a rerun.', 'sonoquill')),
            ]
        );

        self::renderSection(
            __('Anthropic', 'sonoquill'),
            __('Editing the fact script into the spoken script.', 'sonoquill'),
            [
                self::secretRow('anthropic_api_key', __('API key', 'sonoquill'), $values),
                self::textRow('anthropic_model', __('Model', 'sonoquill'), $values, __('For extraction, script, editing, fact check, metadata and dictionary. Since 25.09.2026 claude-opus-5-5: better than Opus 5 and 20 % cheaper.', 'sonoquill')),
                self::textRow('anthropic_model_research', __('Model for web research', 'sonoquill'), $values, __('Research is supplementary; claude-sonnet-5 costs less than half.', 'sonoquill')),
                self::textRow('anthropic_model_factcheck', __('Model for the fact check', 'sonoquill'), $values, __('Optional. Empty: the main model.', 'sonoquill')),
                self::textRow('anthropic_model_metadata', __('Model for the metadata', 'sonoquill'), $values, __('Optional. Empty: the main model. Title, descriptions and chapters also work well with a smaller model.', 'sonoquill')),
                self::textRow('anthropic_model_dictionary', __('Model for dictionary suggestions', 'sonoquill'), $values, __('Optional. Empty: the main model.', 'sonoquill')),
                self::checkboxRow('anthropic_batch', __('Batch API', 'sonoquill'), $values, __('Model calls at half price via the Batch API. The result usually arrives within an hour instead of immediately; the chain then continues on its own. Web research always runs directly.', 'sonoquill')),
                self::textRow('anthropic_workspace_id', __('Workspace ID', 'sonoquill'), $values, __('Only needed if the key is identity-bound — then the API requires the anthropic-workspace-id header. Leave empty if the key is already bound to a workspace.', 'sonoquill')),
            ]
        );

        self::renderSection(
            __('Auphonic', 'sonoquill'),
            __('Production of the final file.', 'sonoquill'),
            [
                self::selectRow('production_mode', __('Production', 'sonoquill'), $values, [
                    'auphonic' => __('Auphonic masters the episode', 'sonoquill'),
                    'direct'   => __('Directly: the montage goes to Podlove as it is', 'sonoquill'),
                ], __('Directly skips Auphonic: no loudness normalisation and, without ffmpeg, no opener, bridges or outro. Chapters and duration come from the montage.', 'sonoquill')),
                self::secretRow('auphonic_api_key', __('API token', 'sonoquill'), $values),
                self::textRow('auphonic_preset', __('Preset UUID', 'sonoquill'), $values, __('Speech recognition must be switched off in the preset — chapters and transcript come from the plugin.', 'sonoquill')),
            ]
        );

        self::renderSection(
            __('Disclosure', 'sonoquill'),
            __('Appears at the end of every episode\'s show notes. The text is copied when an episode is created — so a later change only affects new episodes and does not rewrite drafts that already exist.', 'sonoquill'),
            [
                self::textareaRow(
                    'ai_disclosure_text',
                    __('Notice in the show notes', 'sonoquill'),
                    $values,
                    __('Leaving it empty suppresses the notice entirely.', 'sonoquill')
                ),
                self::checkboxRow(
                    'ai_disclosure_in_audio',
                    __('Notice in the audio as well', 'sonoquill'),
                    $values,
                    __('Applies to episodes created afterwards. The sentence is spoken as a separate segment before the sign-off.', 'sonoquill')
                ),
                self::textareaRow(
                    'ai_disclosure_audio_text',
                    __('Spoken notice', 'sonoquill'),
                    $values,
                    __('Spoken verbatim. Write out abbreviations such as “AI”.', 'sonoquill')
                ),
            ]
        );

        self::renderSection(
            __('Notifications', 'sonoquill'),
            '',
            [
                self::textRow('notify_email', __('Notify', 'sonoquill'), $values, sprintf(
                    /* translators: %s: address of the site admin */
                    __('Separate multiple addresses with commas. Empty means: the admin address (%s). E-mails are sent when a text approval or an audio approval is pending and on errors — only for episodes that proceed automatically.', 'sonoquill'),
                    (string) get_option('admin_email')
                )),
                self::checkboxRow('mail_approval_links', __('Approve by e-mail', 'sonoquill'), $values, __('The “text awaiting approval” mail contains a signed link: it opens a confirmation page, one more click approves — without logging in. The link stops working after 48 hours or as soon as the script is edited. Only when the checks are clean. Anyone who can read the mail can approve.', 'sonoquill')),
            ]
        );

        /**
         * Action: add-ons insert their own sections here, using
         * SettingsPage::renderSection() and the *Row() helpers.
         */
        do_action('sonoquill_settings_sections', $values);

        self::renderSection(
            __('Opener and outro', 'sonoquill'),
            __('The assembly places the audio logo before the episode and the outro after it, in stereo, and shifts chapters and transcript accordingly. The files are uploaded below.', 'sonoquill'),
            [
                self::checkboxRow('music_enabled', __('Use', 'sonoquill'), $values, __('Mix opener and outro into every new assembly.', 'sonoquill')),
            ]
        );

        self::renderSection(
            __('Tools', 'sonoquill'),
            __('External programs for the assembly.', 'sonoquill'),
            [
                self::selectRow('montage_mode', __('Assembly', 'sonoquill'), $values, [
                    'auto'   => __('automatic', 'sonoquill'),
                    'ffmpeg' => __('ffmpeg on this server', 'sonoquill'),
                    'php'    => __('PHP, music via Auphonic', 'sonoquill'),
                ], sprintf(
                    /* translators: %s: why the current path is active */
                    __('With ffmpeg, the plugin mixes the music itself (bridges fade under the next chapter). Without it, segments are joined in PHP and Auphonic adds opener, bridges and outro. Currently: %s', 'sonoquill'),
                    \Sonoquill\Audio\AudioEngine::reason()
                )),
                self::textRow('ffmpeg_path', __('ffmpeg', 'sonoquill'), $values, __('Program name or absolute path.', 'sonoquill')),
                self::textRow('ffprobe_path', __('ffprobe', 'sonoquill'), $values, __('Needed for the measured segment duration.', 'sonoquill')),
                self::textRow(
                    'paragraph_pause_ms',
                    __('Pause between paragraphs (ms)', 'sonoquill'),
                    $values,
                    __('Without this pause one paragraph runs into the next. The model\'s own sentence pauses are two to four tenths of a second — the paragraph pause should be longer.', 'sonoquill')
                ),
                self::textRow(
                    'chapter_pause_ms',
                    __('Pause at chapter boundaries (ms)', 'sonoquill'),
                    $values,
                    __('Inserted as silence, not as a break tag — only this keeps the chapter time exact. Both pauses are filled with room tone from the recording itself and faded in and out softly at the edges.', 'sonoquill')
                ),
                self::checkboxRow(
                    'delete_data_on_uninstall',
                    __('Delete everything on uninstall', 'sonoquill'),
                    $values,
                    __('Removes tables and settings when the plugin is deleted. Off by default.', 'sonoquill')
                ),
            ]
        );

        submit_button();
        echo '</form>';

        self::renderMusicUpload();
    }

    /**
     * Upload and preview opener and outro. A separate form, because
     * options.php does not accept files.
     */
    private static function renderMusicUpload(): void
    {
        echo '<h2>' . esc_html__('Opener, outro and separators: files', 'sonoquill') . '</h2>';
        echo '<p class="description">' . esc_html__('Separators are short musical bridges between chapters (about 8 to 12 seconds). They start shortly after the last word; the next chapter begins 1.5 seconds before their end while the assembly fades them out. Up to five, used in rotation. Trim silence at the end of the file beforehand. Without separators the silent chapter pause is used.', 'sonoquill') . '</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(\Sonoquill\Admin\EpisodeActions::ACTION_MUSIC);
        echo '<input type="hidden" name="action" value="' . esc_attr(\Sonoquill\Admin\EpisodeActions::ACTION_MUSIC) . '">';
        echo '<table class="form-table" role="presentation"><tbody>';

        $slots = [
            'opener' => __('Opener (audio logo)', 'sonoquill'),
            'outro'  => __('Outro', 'sonoquill'),
        ];
        foreach (\Sonoquill\Audio\MusicBed::SEPARATOR_SLOTS as $n => $slot) {
            /* translators: %d: separator number */
            $slots[$slot] = sprintf(__('Separator %d', 'sonoquill'), $n + 1);
        }

        foreach ($slots as $slot => $label) {
            $file = \Sonoquill\Audio\MusicBed::file($slot);
            echo '<tr><th scope="row"><label for="aaspf-musik-' . esc_attr($slot) . '">' . esc_html($label) . '</label></th><td>';
            if (is_readable($file)) {
                echo '<audio controls preload="none" src="' . esc_url(\Sonoquill\Audio\MusicBed::url($slot)) . '"></audio>';
                $ms = \Sonoquill\Audio\AudioEngine::durationMs($file);
                /* translators: 1: duration of the audio file in seconds, 2: upload date */
                echo '<p class="description">' . esc_html(sprintf(__('%1$s seconds, uploaded on %2$s.', 'sonoquill'), number_format_i18n(($ms ?? 0) / 1000, 1), wp_date('d.m.Y', (int) filemtime($file)))) . '</p>';
                echo '<p><label><input type="checkbox" name="entfernen_' . esc_attr($slot) . '" value="1"> ' . esc_html__('Remove file', 'sonoquill') . '</label></p>';
            } else {
                echo '<p class="description">' . esc_html__('No file yet.', 'sonoquill') . '</p>';
            }
            echo '<input type="file" id="aaspf-musik-' . esc_attr($slot) . '" name="' . esc_attr($slot) . '" accept="audio/mpeg,.mp3">';
            echo '</td></tr>';
        }

        echo '</tbody></table>';
        submit_button(__('Upload files', 'sonoquill'), 'secondary');
        echo '</form>';
    }

    /**
     * @param string[] $rows
     */
    public static function renderSection(string $title, string $description, array $rows): void
    {
        echo '<h2>' . esc_html($title) . '</h2>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '<table class="form-table" role="presentation"><tbody>';
        echo wp_kses(implode('', $rows), \Sonoquill\Admin\Html::allowed());
        echo '</tbody></table>';
    }

    /**
     * Choices for the podcast language. Any language code works; the list
     * offers common ones, keeps a stored code that is not in it and can be
     * extended with the filter `sonoquill_podcast_languages` (code => name).
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,string>
     */
    private static function languageChoices(array $values): array
    {
        $choices = [
            /* translators: %s: name of the site language (Deutsch or English) */
            ''   => sprintf(__('same as the site (%s)', 'sonoquill'), str_starts_with(function_exists('get_locale') ? (string) get_locale() : 'en_US', 'de') ? 'Deutsch' : 'English'),
            'de' => 'Deutsch',
            'en' => 'English',
            'cs' => 'Čeština',
            'es' => 'Español',
            'fr' => 'Français',
            'it' => 'Italiano',
            'nl' => 'Nederlands',
            'pl' => 'Polski',
            'pt' => 'Português',
            'uk' => 'Українська',
        ];
        $choices = (array) apply_filters('sonoquill_podcast_languages', $choices);

        $stored = Options::normalizeLanguage((string) ($values['podcast_language'] ?? ''));
        if ($stored !== '' && !isset($choices[$stored])) {
            $choices[$stored] = $stored;
        }

        return array_map('strval', $choices);
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function textRow(string $field, string $label, array $values, string $description = ''): string
    {
        $id = 'aaspf-' . $field;
        $html  = '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        $html .= '<input type="text" class="regular-text" id="' . esc_attr($id) . '"';
        $html .= ' name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '"';
        $html .= ' value="' . esc_attr((string) ($values[$field] ?? '')) . '">';
        if ($description !== '') {
            $html .= '<p class="description">' . esc_html($description) . '</p>';
        }

        return $html . '</td></tr>';
    }

    /**
     * @param array<string,mixed> $values
     */
    /**
     * @param array<string,mixed>  $values
     * @param array<string,string> $choices value => display text
     */
    public static function selectRow(string $field, string $label, array $values, array $choices, string $description = ''): string
    {
        $id = 'aaspf-' . $field;
        $html = '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        $html .= '<select id="' . esc_attr($id) . '" name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '">';
        foreach ($choices as $value => $text) {
            $html .= '<option value="' . esc_attr((string) $value) . '"' . selected((string) ($values[$field] ?? ''), (string) $value, false) . '>' . esc_html($text) . '</option>';
        }
        $html .= '</select>';
        if ($description !== '') {
            $html .= '<p class="description">' . esc_html($description) . '</p>';
        }

        return $html . '</td></tr>';
    }

    public static function checkboxRow(string $field, string $label, array $values, string $description = ''): string
    {
        $id = 'aaspf-' . $field;
        $html  = '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        $html .= '<label for="' . esc_attr($id) . '"><input type="checkbox" id="' . esc_attr($id) . '"';
        $html .= ' name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '" value="1"';
        $html .= checked(!empty($values[$field]), true, false) . '> ';
        $html .= esc_html($description);

        return $html . '</label></td></tr>';
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function textareaRow(string $field, string $label, array $values, string $description = ''): string
    {
        $id = 'aaspf-' . $field;
        $html  = '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        $html .= '<textarea id="' . esc_attr($id) . '" rows="5" class="large-text"';
        $html .= ' name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '">';
        $html .= esc_textarea((string) ($values[$field] ?? ''));
        $html .= '</textarea>';
        if ($description !== '') {
            $html .= '<p class="description">' . esc_html($description) . '</p>';
        }

        return $html . '</td></tr>';
    }

    /**
     * Secret field: always empty, with an indication of the stored state.
     *
     * @param array<string,mixed> $values
     */
    private static function secretRow(string $field, string $label, array $values): string
    {
        $id = 'aaspf-' . $field;
        $stored = isset($values[$field]) && $values[$field] !== '';

        $html  = '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>';
        $html .= '<input type="password" class="regular-text" autocomplete="new-password" id="' . esc_attr($id) . '"';
        $html .= ' name="' . esc_attr(Options::OPTION . '[' . $field . ']') . '" value="">';

        if ($stored) {
            $html .= ' <span class="aaspf-badge aaspf-badge-ok">' . esc_html__('saved', 'sonoquill') . '</span>';
            $html .= '<p class="description">' . esc_html__('Leaving it empty means: unchanged. To replace it, simply enter the new value.', 'sonoquill') . '</p>';
            $html .= '<label><input type="checkbox" name="' . esc_attr(Options::OPTION . '[clear_' . $field . ']') . '" value="1"> ';
            $html .= esc_html__('delete stored value', 'sonoquill') . '</label>';
        } else {
            $html .= ' <span class="aaspf-badge aaspf-badge-skip">' . esc_html__('not set', 'sonoquill') . '</span>';
        }

        return $html . '</td></tr>';
    }

    private static function renderHealth(): void
    {
        echo '<h2>' . esc_html__('Service status', 'sonoquill') . '</h2>';
        echo '<p class="description">' . esc_html__('Each check runs as a separate request. None of them uses up credit.', 'sonoquill') . '</p>';

        echo '<table class="widefat striped aaspf-health"><thead><tr>';
        echo '<th scope="col">' . esc_html__('Service', 'sonoquill') . '</th>';
        echo '<th scope="col">' . esc_html__('Status', 'sonoquill') . '</th>';
        echo '</tr></thead><tbody>';

        foreach (Registry::all() as $id => $check) {
            echo '<tr data-aaspf-check="' . esc_attr($id) . '">';
            echo '<td><strong>' . esc_html($check->label()) . '</strong></td>';
            echo '<td class="aaspf-health-cell"><span class="aaspf-status aaspf-status-pending">' . esc_html__('not checked yet', 'sonoquill') . '</span></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><button type="button" class="button" id="aaspf-run-health">' . esc_html__('Check now', 'sonoquill') . '</button></p>';
    }

    private static function renderJobs(): void
    {
        $version = Scheduler::activeVersion();
        $state   = Scheduler::state();

        echo '<h2>' . esc_html__('Background processing', 'sonoquill') . '</h2>';

        if ($version === null) {
            echo '<p class="aaspf-status aaspf-status-fail">' . esc_html__('Action Scheduler is not loaded.', 'sonoquill') . '</p>';
        } else {
            echo '<p>' . sprintf(
                /* translators: %s: version number */
                esc_html__('Action Scheduler %s is active.', 'sonoquill'),
                '<code>' . esc_html($version) . '</code>'
            ) . '</p>';
        }

        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Test job scheduled', 'sonoquill') . '</th><td>';
        if (!empty($state['requested_at'])) {
            echo esc_html(self::formatTime((int) $state['requested_at']));
            if (!empty($state['action_id'])) {
                echo ' <code>#' . esc_html((string) (int) $state['action_id']) . '</code>';
            }
        } else {
            echo esc_html__('never', 'sonoquill');
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Test job executed', 'sonoquill') . '</th><td>';
        $ranMatches = !empty($state['ran_at'])
            && isset($state['token'], $state['ran_token'])
            && hash_equals((string) $state['token'], (string) $state['ran_token']);

        if ($ranMatches) {
            echo '<span class="aaspf-status aaspf-status-ok">' . esc_html(self::formatTime((int) $state['ran_at'])) . '</span>';
        } elseif (!empty($state['requested_at'])) {
            echo '<span class="aaspf-status aaspf-status-warn">' . esc_html__('still pending — Action Scheduler processes the queue via WP-Cron, which takes up to a minute.', 'sonoquill') . '</span>';
        } else {
            echo esc_html__('never', 'sonoquill');
        }
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(AdminActions::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(AdminActions::ACTION) . '">';
        echo '<p>';
        submit_button(__('Schedule test job', 'sonoquill'), 'secondary', 'submit', false);
        echo '</p>';
        echo '</form>';
    }

    private static function renderStorage(): void
    {
        echo '<h2>' . esc_html__('Database', 'sonoquill') . '</h2>';
        echo '<table class="widefat striped"><tbody>';

        foreach (Schema::status() as $table => $exists) {
            echo '<tr><th scope="row"><code>' . esc_html($table) . '</code></th><td>';
            if ($exists) {
                echo '<span class="aaspf-status aaspf-status-ok">' . esc_html__('present', 'sonoquill') . '</span>';
            } else {
                echo '<span class="aaspf-status aaspf-status-fail">' . esc_html__('missing', 'sonoquill') . '</span>';
            }
            echo '</td></tr>';
        }

        echo '<tr><th scope="row">' . esc_html__('Schema version', 'sonoquill') . '</th><td><code>';
        echo esc_html((string) get_option('aaspf_db_version', '—'));
        echo '</code></td></tr>';
        echo '</tbody></table>';
    }

    private static function formatTime(int $timestamp): string
    {
        return wp_date(
            (string) get_option('date_format') . ' ' . (string) get_option('time_format') . ':s',
            $timestamp
        ) ?: (string) $timestamp;
    }
}
