<?php
namespace AiutomaDev\Includes;

if (!defined('ABSPATH')) {
    exit;
}

class Safe_Mode {

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_rest_route']);
        add_action('aiutoma_playground_toolbar_actions', [__CLASS__, 'render_toolbar_button']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('aiutoma_settings_table_rows', [__CLASS__, 'render_settings_row']);
        add_filter('aiutoma_chat_tool_instructions', [__CLASS__, 'filter_chat_instructions']);
        add_filter('aiutoma_playground_settings', [__CLASS__, 'filter_playground_settings']);

        add_filter('aiutoma_enable_safe_mode_ui', '__return_true');
        add_filter('aiutoma_is_safe_mode_active', [__CLASS__, 'is_ai_safe_active']);
        add_action('aiutoma_enable_safe_mode', [__CLASS__, 'enable']);
        add_action('aiutoma_disable_safe_mode', [__CLASS__, 'disable']);
        add_action('aiutoma_deactivated', [__CLASS__, 'cleanup']);
        add_action('aiutoma_site_error_detected', [__CLASS__, 'on_site_error']);
        add_action('aiutoma_site_healthy', [__CLASS__, 'on_site_healthy']);
        add_action('aiutoma_playground_sidebar_bottom', [__CLASS__, 'render_sidebar_ui']);

        if (!self::is_active()) {
            self::enable();
        }
    }

    public static function register_rest_route() {
        register_rest_route('aiutoma/v1', '/toggle-safe-mode', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_toggle_response'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);
    }

    public static function render_toolbar_button() {
        $is_safe = self::is_ai_safe_active();
        ?>
        <button type="button" id="aiutoma-toggle-safe-mode" class="button button-secondary aiutoma-session-btn <?php echo $is_safe ? 'aiutoma-safe-mode-active' : ''; ?>" title="<?php esc_attr_e('Toggle AI Safe Mode', 'aiutoma-dev'); ?>" data-active="<?php echo $is_safe ? '1' : '0'; ?>">
            <span class="dashicons dashicons-shield"></span>
        </button>
        <?php
    }

    public static function enqueue_assets($hook) {
        if (strpos($hook, 'aiutoma') !== false || (isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'aiutoma')) {
            wp_enqueue_style('aiutoma-dev-safe-mode', AIUTOMA_DEV_URL . 'assets/css/dev-safe-mode.css', [], AIUTOMA_DEV_VERSION);
            wp_enqueue_script('aiutoma-dev-safe-mode', AIUTOMA_DEV_URL . 'assets/js/dev-safe-mode.js', ['jquery'], AIUTOMA_DEV_VERSION, true);
        }
    }

    public static function render_settings_row() {
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Developer Mode', 'aiutoma-dev'); ?></th>
            <td>
                <p style="color: #00a32a; font-weight: 600;">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php printf(esc_html__('Developer Mode is active (v%s).', 'aiutoma-dev'), esc_html(AIUTOMA_DEV_VERSION)); ?>
                </p>
                <p class="description"><?php esc_html_e('Developer abilities are unlocked with interactive review and rollbacks.', 'aiutoma-dev'); ?></p>
            </td>
        </tr>
        <?php
    }

    public static function filter_chat_instructions($default_instructions) {
        return "Developer tools (execute-php, modify-file, run-wp-cli) are active; use them when needed for custom code execution or file edits after user confirmation. ";
    }

    public static function filter_playground_settings($settings) {
        if (is_array($settings)) {
            $settings['hasDevExtension'] = true;
        }
        return $settings;
    }

    public static function get_mu_plugin_path() {
        $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        return trailingslashit($mu_dir) . 'aiutoma-safe-mode.php';
    }

    public static function get_theme_dir() {
        return get_theme_root() . '/aiutoma-safe-theme';
    }

    public static function is_active() {
        return file_exists(self::get_mu_plugin_path());
    }

    public static function is_ai_safe_active() {
        return file_exists(ABSPATH . '.aiutoma_safe');
    }

    public static function on_site_error() {
        file_put_contents(ABSPATH . '.aiutoma_safe', '1');
    }

    public static function on_site_healthy() {
        if (file_exists(ABSPATH . '.aiutoma_safe')) {
            @unlink(ABSPATH . '.aiutoma_safe');
        }
    }

    public static function enable() {
        $theme_dir = self::get_theme_dir();
        if (!is_dir($theme_dir)) {
            wp_mkdir_p($theme_dir);
            file_put_contents($theme_dir . '/style.css', "/*\nTheme Name: Aiutoma Safe Theme\n*/");
            file_put_contents($theme_dir . '/index.php', "");
        }

        $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        if (!is_dir($mu_dir)) {
            wp_mkdir_p($mu_dir);
        }
        $plugin_file = self::get_mu_plugin_path();

        $code = "<?php\n" .
            "/*\n" .
            "Plugin Name: Aiutoma Developer Safe Mode (MU)\n" .
            "Description: Developer Safe Mode: Forces empty theme and disables other plugins on the Playground page.\n" .
            "Author: Aiutoma Team\n" .
            "*/\n" .
            "if (!defined('ABSPATH')) exit;\n\n" .
            "\$is_playground_page = isset(\$_GET['page']) && \$_GET['page'] === 'aiutoma';\n" .
            "\$is_ai_rest = strpos(\$_SERVER['REQUEST_URI'] ?? '', '/aiutoma/v1/ai') !== false;\n" .
            "\$is_mcp = strpos(\$_SERVER['REQUEST_URI'] ?? '', '/aiutoma/v1/mcp') !== false;\n" .
            "\$is_toggle_rest = strpos(\$_SERVER['REQUEST_URI'] ?? '', '/aiutoma/v1/toggle-safe-mode') !== false;\n" .
            "\$is_cron = strpos(\$_SERVER['REQUEST_URI'] ?? '', '/wp-cron.php') !== false;\n" .
            "\$is_login = strpos(\$_SERVER['REQUEST_URI'] ?? '', 'wp-login.php') !== false;\n" .
            "\$saved_token = get_option('aiutoma_mcp_token', '');\n" .
            "\$valid_token = !empty(\$saved_token) && isset(\$_REQUEST['token']) && \$_REQUEST['token'] === \$saved_token;\n" .
            "\$explicit_enforce = isset(\$_REQUEST['aiutoma_enforce_safe_mode']) && \$_REQUEST['aiutoma_enforce_safe_mode'] === '1' && (!\$is_login || \$valid_token);\n" .
            "\$enforce_ai = file_exists(ABSPATH . '.aiutoma_safe') || \$explicit_enforce;\n\n" .
            "// Autonomous Cron Crash Recovery\n" .
            "if (\$is_cron) {\n" .
            "    \$cron_flag = ABSPATH . '.aiutoma_cron_running';\n" .
            "    if (file_exists(\$cron_flag)) {\n" .
            "        \$enforce_ai = true;\n" .
            "    }\n" .
            "    file_put_contents(\$cron_flag, '1');\n" .
            "    register_shutdown_function(function() use (\$cron_flag) {\n" .
            "        \$error = error_get_last();\n" .
            "        if (\$error === null || !in_array(\$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {\n" .
            "            @unlink(\$cron_flag);\n" .
            "        }\n" .
            "    });\n" .
            "}\n\n" .
            "\$is_ai_redirect = (\$_SERVER['REQUEST_URI'] ?? '') === '/aiutoma' || (\$_SERVER['REQUEST_URI'] ?? '') === '/aiutoma/';\n" .
            "if (\$is_ai_redirect) {\n" .
            "    header('Location: /wp-admin/admin.php?page=aiutoma');\n" .
            "    exit;\n" .
            "}\n\n" .
            "if (\$is_playground_page || \$explicit_enforce || ((\$is_ai_rest || \$is_mcp || \$is_toggle_rest || \$is_cron || \$is_login) && \$enforce_ai)) {\n" .
            "    add_filter('option_active_plugins', function(\$plugins) {\n" .
            "        \$allowed = [];\n" .
            "        \$allow_list = get_option('aiutoma_safe_mode_allowlist', []);\n" .
            "        if (!is_array(\$allow_list)) \$allow_list = [];\n" .
            "        \$core_list = ['aiutoma/aiutoma.php', 'aiutoma-dev/aiutoma-dev.php'];\n" .
            "        \$allow_list = array_merge(\$allow_list, \$core_list);\n" .
            "        foreach (\$plugins as \$plugin) {\n" .
            "            if (in_array(\$plugin, \$allow_list) || strpos(\$plugin, 'aiutoma') !== false || strpos(\$plugin, 'ai-provider') !== false) {\n" .
            "                \$allowed[] = \$plugin;\n" .
            "            }\n" .
            "        }\n" .
            "        return \$allowed;\n" .
            "    });\n" .
            "    add_filter('option_active_sitewide_plugins', function(\$plugins) {\n" .
            "        \$allowed = [];\n" .
            "        \$allow_list = get_option('aiutoma_safe_mode_allowlist', []);\n" .
            "        if (!is_array(\$allow_list)) \$allow_list = [];\n" .
            "        \$core_list = ['aiutoma/aiutoma.php', 'aiutoma-dev/aiutoma-dev.php'];\n" .
            "        \$allow_list = array_merge(\$allow_list, \$core_list);\n" .
            "        if (is_array(\$plugins)) {\n" .
            "            foreach (\$plugins as \$plugin => \$time) {\n" .
            "                if (in_array(\$plugin, \$allow_list) || strpos(\$plugin, 'aiutoma') !== false || strpos(\$plugin, 'ai-provider') !== false) {\n" .
            "                    \$allowed[\$plugin] = \$time;\n" .
            "                }\n" .
            "            }\n" .
            "        }\n" .
            "        return \$allowed;\n" .
            "    });\n" .
            "    add_filter('stylesheet', function(\$theme) { return 'aiutoma-safe-theme'; });\n" .
            "    add_filter('template', function(\$theme) { return 'aiutoma-safe-theme'; });\n" .
            "    if (\$is_login) {\n" .
            "        add_action('login_form', function() {\n" .
            "            echo '<input type=\"hidden\" name=\"aiutoma_enforce_safe_mode\" value=\"1\" />';\n" .
            "            if (isset(\$_REQUEST['token'])) {\n" .
            "                echo '<input type=\"hidden\" name=\"token\" value=\"' . esc_attr(\$_REQUEST['token']) . '\" />';\n" .
            "            }\n" .
            "        });\n" .
            "    }\n" .
            "}\n";

        return (bool) file_put_contents($plugin_file, $code);
    }

    public static function disable() {
        $flag_file = ABSPATH . '.aiutoma_safe';
        if (file_exists($flag_file)) {
            @unlink($flag_file);
        }
        return true;
    }

    public static function cleanup() {
        $plugin_file = self::get_mu_plugin_path();
        if (file_exists($plugin_file)) {
            @unlink($plugin_file);
        }
        $theme_dir = self::get_theme_dir();
        if (is_dir($theme_dir)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            global $wp_filesystem;
            if ($wp_filesystem) {
                $wp_filesystem->rmdir($theme_dir, true);
            }
        }
        if (file_exists(ABSPATH . '.aiutoma_safe')) {
            @unlink(ABSPATH . '.aiutoma_safe');
        }
    }

    public static function handle_toggle_response($request_or_response = null, $maybe_request = null) {
        $request = ($maybe_request instanceof \WP_REST_Request) ? $maybe_request : (($request_or_response instanceof \WP_REST_Request) ? $request_or_response : null);
        $flag_file = ABSPATH . '.aiutoma_safe';
        $force = ($request && method_exists($request, 'get_param')) ? $request->get_param('force') : null;

        if ($force === 'enable') {
            file_put_contents($flag_file, '1');
            return new \WP_REST_Response(['success' => true, 'safe_mode' => true], 200);
        } elseif ($force === 'disable') {
            if (file_exists($flag_file)) @unlink($flag_file);
            return new \WP_REST_Response(['success' => true, 'safe_mode' => false], 200);
        }

        if (file_exists($flag_file)) {
            @unlink($flag_file);
            return new \WP_REST_Response(['success' => true, 'safe_mode' => false], 200);
        } else {
            file_put_contents($flag_file, '1');
            return new \WP_REST_Response(['success' => true, 'safe_mode' => true], 200);
        }
    }

    public static function get_emergency_login_url() {
        $safe_token = get_option('aiutoma_mcp_token');
        if (empty($safe_token)) {
            $safe_token = wp_generate_password(24, false);
            update_option('aiutoma_mcp_token', $safe_token);
        }
        $playground_url = admin_url('admin.php?page=aiutoma');
        return add_query_arg(['aiutoma_enforce_safe_mode' => '1', 'token' => $safe_token], wp_login_url($playground_url));
    }

    public static function render_sidebar_ui() {
        $safe_mode_url = self::get_emergency_login_url();
        $is_safe = self::is_ai_safe_active();
        ?>
        <div class="card aiutoma-safemode-card" style="display: none;">
            <details>
                <summary class="aiutoma-card-summary-wrap">
                    <h2>
                        <span class="dashicons dashicons-shield"></span>
                        <?php esc_html_e('Safe Mode', 'aiutoma-dev'); ?>
                    </h2>
                </summary>
                <div class="aiutoma-safemode-info" style="padding: 0 15px 15px 15px;">
                    <p><?php esc_html_e('The Playground UI runs in a strictly isolated environment (Safe Mode). All other plugins and the active theme are temporarily disabled on this page to ensure maximum stability and prevent third-party fatal errors from crashing the chat.', 'aiutoma-dev'); ?></p>
                    <p><strong><?php esc_html_e('AI Auto-Recovery:', 'aiutoma-dev'); ?></strong> <?php esc_html_e('By default, the AI executes tasks with ALL plugins loaded, so it can access WooCommerce, WPML, etc. freely. If a third-party plugin causes a Fatal Error (Error 500) during execution, the system will automatically enforce Strict Safe Mode on the AI to recover without breaking the chat.', 'aiutoma-dev'); ?></p>
                    <p><strong><?php esc_html_e('Post-Task Verification:', 'aiutoma-dev'); ?></strong> <?php esc_html_e('After every critical task (like editing PHP or Database), the system verifies the frontend. If the site is broken, it immediately alerts the AI to fix it or prompts you to rollback.', 'aiutoma-dev'); ?></p>
                    <div id="aiutoma-safemode-status-wrap" style="margin-top: 10px; padding: 10px; background: #f0f0f1; border-left: 4px solid #72aee6; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong><?php esc_html_e('Current AI Status:', 'aiutoma-dev'); ?></strong> <span id="aiutoma-safemode-status"><?php echo $is_safe ? esc_html__('Strict Safe Mode Enforced (.aiutoma_safe)', 'aiutoma-dev') : esc_html__('Native (All Plugins Active)', 'aiutoma-dev'); ?></span>
                        </div>
                    </div>
                </div>
            </details>
        </div>

        <div class="notice notice-error inline" style="margin-left: 0; margin-top: 40px;">
            <p><strong><?php esc_html_e('Emergency Safe Mode Login', 'aiutoma-dev'); ?>:</strong> <?php esc_html_e('If a plugin or theme causes a fatal 500 error that locks you out of the WordPress admin, use this URL to safely log in with all plugins/themes disabled:', 'aiutoma-dev'); ?></p>
            <p style="background: #fff; padding: 10px; font-weight: bold; overflow-x: auto;">
                <a href="<?php echo esc_url($safe_mode_url); ?>" target="_blank" style="text-decoration: none;">
                    <?php echo esc_url($safe_mode_url); ?>
                </a>
            </p>
            <p><em><?php esc_html_e('Save this URL somewhere safe. The unique token prevents bots from bypassing system protections (like Wordfence 2FA) by forcing safe mode.', 'aiutoma-dev'); ?></em></p>
        </div>
        <?php
    }
}

