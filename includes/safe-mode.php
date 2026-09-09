<?php
namespace AiutomaDev\Includes;

if (!defined('ABSPATH')) {
    exit;
}

class Safe_Mode {

    public static function init() {
        add_filter('aiutoma_enable_safe_mode_ui', '__return_true');
        add_filter('aiutoma_is_safe_mode_active', [__CLASS__, 'is_ai_safe_active']);
        add_filter('aiutoma_toggle_safe_mode_response', [__CLASS__, 'handle_toggle_response'], 10, 2);
        add_action('aiutoma_enable_safe_mode', [__CLASS__, 'enable']);
        add_action('aiutoma_disable_safe_mode', [__CLASS__, 'disable']);
        add_action('aiutoma_deactivated', [__CLASS__, 'cleanup']);
        add_action('aiutoma_site_error_detected', [__CLASS__, 'on_site_error']);
        add_action('aiutoma_site_healthy', [__CLASS__, 'on_site_healthy']);

        if (!self::is_active()) {
            self::enable();
        }
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

    public static function handle_toggle_response($response, $request) {
        $flag_file = ABSPATH . '.aiutoma_safe';
        $force = $request->get_param('force');

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
}
