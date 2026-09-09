<?php
/**
 * Plugin Name:       Aiutoma Dev
 * Plugin URI:        https://aiutoma.com
 * Description:       Official Developer Companion for Aiutoma. Unlocks advanced developer abilities (Execute PHP Code, Modify Files, Run WP-CLI) with automatic change recording, CodeMirror editing, and 1-click rollbacks.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Aiutoma Team
 * Author URI:        https://aiutoma.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aiutoma-dev
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AIUTOMA_DEV_VERSION', '1.0.0');
define('AIUTOMA_DEV_PATH', plugin_dir_path(__FILE__));
define('AIUTOMA_DEV_URL', plugin_dir_url(__FILE__));

// Require helper files
require_once AIUTOMA_DEV_PATH . 'includes/change-recorder.php';
require_once AIUTOMA_DEV_PATH . 'includes/developer-abilities.php';
require_once AIUTOMA_DEV_PATH . 'includes/safe-mode.php';
require_once AIUTOMA_DEV_PATH . 'includes/mcp-companion.php';
require_once AIUTOMA_DEV_PATH . 'includes/im-companion.php';

// Initialize developer companion modules
\AiutomaDev\Includes\Safe_Mode::init();
\AiutomaDev\Includes\Mcp_Companion::init();
\AiutomaDev\Includes\Im_Companion::init();
add_filter('aiutoma_automation_dev_extension_active', '__return_true');

// Clean up MU plugins on deactivation
register_deactivation_hook(__FILE__, function () {
    \AiutomaDev\Includes\Safe_Mode::cleanup();
});

// Check if core plugin is active
add_action('admin_init', function () {
    if (is_admin() && current_user_can('activate_plugins') && !defined('AIUTOMA_VERSION')) {
        add_action('admin_notices', function () {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e('Aiutoma Dev', 'aiutoma-dev'); ?>:</strong>
                    <?php esc_html_e('Please ensure the core Aiutoma plugin is active to utilize developer abilities.', 'aiutoma-dev'); ?>
                </p>
            </div>
            <?php
        });
    }
});

// Hook into Aiutoma ability registration
add_action('aiutoma_register_abilities', function () {
    \AiutomaDev\Includes\Developer_Abilities::register_all();
});

// Fallback hook for direct Abilities API initialization
add_action('wp_abilities_api_init', function () {
    \AiutomaDev\Includes\Developer_Abilities::register_all();
}, 20);

// Enable PHP CodeMirror in Aiutoma Playground
add_filter('aiutoma_enable_php_codemirror', '__return_true');

