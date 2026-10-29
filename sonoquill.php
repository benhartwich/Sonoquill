<?php
/**
 * Plugin Name:       Sonoquill – AI Podcast Production
 * Plugin URI:        https://github.com/benhartwich/sonoquill
 * Description:       Turns a fact script, a post or a custom source into a finished podcast episode: speaking script via language model, number and fact checking, speech synthesis with your own voice clone, assembly, mastering and a Podlove draft — with two human approvals.
 * Version:           0.5.0
 * Requires at least: 6.9
 * Requires PHP:      8.2
 * Author:            Benjamin Hartwich
 * Author URI:        https://astroblog.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sonoquill
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('AASPF_VERSION', '0.5.0');
define('AASPF_PLUGIN_FILE', __FILE__);
define('AASPF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AASPF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AASPF_DB_VERSION', '11');

/**
 * Check the PHP version first, before the autoloader.
 *
 * Composer places a platform check in vendor/autoload.php that aborts with
 * E_USER_ERROR on a PHP version that is too old — taking the whole site down
 * with it. If the server ever runs an older PHP, the plugin should quietly
 * switch itself off and say so, instead of making the website unreachable.
 */
if (version_compare(PHP_VERSION, '8.2', '<')) {
    add_action('admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p><strong>Sonoquill:</strong> %s</p></div>',
            esc_html(sprintf(
                /* translators: %s: running PHP version */
                __('The plugin requires PHP 8.2 or newer, but is running under %s. It stays deactivated.', 'sonoquill'),
                PHP_VERSION
            ))
        );
    });

    return;
}

/**
 * Without the autoloader there is no plugin. Instead of a fatal error on the
 * whole site, show a notice in the admin area.
 */
if (!is_readable(AASPF_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Sonoquill:</strong> '
            . esc_html__('The Composer autoloader is missing. Run "composer install" in the plugin directory.', 'sonoquill')
            . '</p></div>';
    });

    return;
}

require_once AASPF_PLUGIN_DIR . 'vendor/autoload.php';

/**
 * Register our own copy of Action Scheduler.
 *
 * Action Scheduler is designed so that every plugin ships its own copy; a
 * version registry decides which one runs. On this installation, WP Mail SMTP
 * and The Events Calendar also bring one along. We register ourselves instead
 * of relying on their presence — otherwise the entire job chain would come to
 * a halt as soon as one of them is deactivated.
 *
 * The call must be here and not in a hook: the registry attaches itself to
 * "plugins_loaded" with priority 0.
 */
require_once AASPF_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

// An error in this plugin must not take the website down with it.
try {
    \Sonoquill\Plugin::instance()->boot();
} catch (\Throwable $e) {
    error_log('[sonoquill] Boot failed: ' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a failed boot must leave a trace
         . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (is_admin()) {
        add_action('admin_notices', static function () use ($e): void {
            echo '<div class="notice notice-error"><p><strong>Sonoquill:</strong> '
                . esc_html__('Startup failed — ', 'sonoquill')
                . esc_html($e->getMessage()) . '</p></div>';
        });
    }
}

register_activation_hook(__FILE__, static function (): void {
    \Sonoquill\Activation::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    \Sonoquill\Activation::deactivate();
});
