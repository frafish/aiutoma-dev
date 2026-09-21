<?php
namespace AiutomaDev\Includes;

if (!defined('ABSPATH')) {
    exit;
}

class Mcp_Companion {

    public static function init() {
        // 1. Enable WordPress core Application Passwords over plain HTTP for local development
        add_filter('wp_is_application_passwords_available', '__return_true');
        add_filter('wp_is_application_passwords_available_for_user', '__return_true');

        // 2. Establish administrator user context for MCP requests with valid API Key
        add_filter('determine_current_user', [__CLASS__, 'determine_current_user_for_mcp'], 30);

        // 3. MCP REST permission check handling
        add_filter('aiutoma_mcp_permission_check', [__CLASS__, 'filter_mcp_permission_check'], 10, 2);

        // 4. Permit developer abilities (execute-php, run-wp-cli, modify-file, db-query) over MCP
        add_filter('aiutoma_mcp_allow_blocked_ability', '__return_true');

        // 5. Fallback acting user & prompt user selection defaults
        add_filter('aiutoma_mcp_acting_user_id', [__CLASS__, 'filter_acting_user_id']);
        add_filter('aiutoma_mcp_selected_user_id', [__CLASS__, 'filter_selected_user_id']);

        // 6. Suppress standard missing-application-password warning in admin UI
        add_filter('aiutoma_mcp_show_app_password_notice', '__return_false');

        // 7. Suppress credential customizer in MCP admin UI (DEV uses API Key bypass)
        add_filter('aiutoma_mcp_show_credential_customizer', '__return_false');

        // 8. Suppress Basic Auth in mcp.json (DEV uses X-MCP-API-Key exclusively)
        add_filter('aiutoma_mcp_include_basic_auth_header', '__return_false');

        // 9. Render friendly developer notice in MCP admin UI
        add_action('aiutoma_mcp_notices', [__CLASS__, 'render_dev_mcp_notice']);

        // 10. Streamline prompt authentication to single API Key header (no Application Passwords or bypass mentions)
        add_filter('aiutoma_mcp_prompt_auth_section', [__CLASS__, 'filter_prompt_auth_section'], 10, 2);

        // 11. Render file safety and autonomous Safe Mode URL recovery instructions in system prompt
        add_action('aiutoma_mcp_prompt_instructions', [__CLASS__, 'render_dev_prompt_instructions']);
    }

    /**
     * Check if the incoming request is targeting Aiutoma MCP endpoints.
     *
     * @return bool
     */
    public static function is_mcp_request() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $rest_route = isset($_GET['rest_route']) ? (string) $_GET['rest_route'] : '';

        if (strpos($uri, '/aiutoma/v1/mcp') !== false || strpos($uri, '/aiutoma/v1/mcp-adapter') !== false) {
            return true;
        }

        if (strpos($rest_route, '/aiutoma/v1/mcp') !== false || strpos($rest_route, '/aiutoma/v1/mcp-adapter') !== false) {
            return true;
        }

        if (isset($_SERVER['PATH_INFO']) && (strpos($_SERVER['PATH_INFO'], '/aiutoma/v1/mcp') !== false || strpos($_SERVER['PATH_INFO'], '/aiutoma/v1/mcp-adapter') !== false)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a valid MCP API Key was provided in headers or request parameters.
     *
     * @return bool
     */
    public static function has_valid_mcp_api_key() {
        $api_key = '';
        if (!empty($_SERVER['HTTP_X_MCP_API_KEY'])) {
            $api_key = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_MCP_API_KEY']));
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (!empty($headers['X-MCP-API-Key'])) {
                $api_key = sanitize_text_field($headers['X-MCP-API-Key']);
            } elseif (!empty($headers['x-mcp-api-key'])) {
                $api_key = sanitize_text_field($headers['x-mcp-api-key']);
            }
        }
        if (empty($api_key) && !empty($_REQUEST['token'])) {
            $api_key = sanitize_text_field(wp_unslash($_REQUEST['token']));
        }

        $saved_token = get_option('aiutoma_mcp_token', '');
        return !empty($saved_token) && !empty($api_key) && hash_equals($saved_token, $api_key);
    }

    /**
     * Determine current user for MCP requests by providing administrator context when API Key is valid.
     *
     * @param int|false $user_id Current user ID or false.
     * @return int|false
     */
    public static function determine_current_user_for_mcp($user_id) {
        if (!empty($user_id)) {
            return $user_id;
        }

        if (self::is_mcp_request() && self::has_valid_mcp_api_key()) {
            $admin_id = self::get_main_admin_id();
            if ($admin_id > 0) {
                return $admin_id;
            }
        }

        return $user_id;
    }

    /**
     * Permission callback filter for MCP requests.
     *
     * @param bool $allowed Current permission status.
     * @param \WP_REST_Request|null $request REST request object.
     * @return bool
     */
    public static function filter_mcp_permission_check($allowed, $request = null) {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (self::has_valid_mcp_api_key()) {
            $admin_id = self::get_main_admin_id();
            if ($admin_id > 0) {
                wp_set_current_user($admin_id);
                return true;
            }
        }

        return $allowed;
    }

    /**
     * Retrieve the primary administrator user ID.
     *
     * @return int
     */
    public static function get_main_admin_id() {
        // 1. Try User ID 1 if they have administrator capability
        $user1 = get_userdata(1);
        if ($user1 && $user1->has_cap('manage_options')) {
            return 1;
        }

        // 2. Query the earliest registered administrator
        $admins = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'user_registered',
            'order'   => 'ASC',
        ]);
        if (!empty($admins)) {
            return (int) $admins[0]->ID;
        }

        // 3. Fallback to any user with manage_options
        $managers = get_users([
            'capability' => 'manage_options',
            'number'     => 1,
            'orderby'    => 'ID',
            'order'      => 'ASC',
        ]);
        if (!empty($managers)) {
            return (int) $managers[0]->ID;
        }

        return 0;
    }

    /**
     * If acting user ID is not explicitly configured, fallback to main admin.
     *
     * @param int $acting_user_id Current acting user ID (0 if not configured).
     * @return int
     */
    public static function filter_acting_user_id($acting_user_id) {
        if (!empty($acting_user_id)) {
            return (int) $acting_user_id;
        }

        return self::get_main_admin_id();
    }

    /**
     * If Customize Prompt Credentials are not set, default prompt user selection to main admin.
     *
     * @param int $user_id Default user ID (e.g. current logged in user).
     * @return int
     */
    public static function filter_selected_user_id($user_id) {
        $saved_acting_user = (int) get_option('aiutoma_mcp_acting_user', 0);
        if ($saved_acting_user > 0) {
            return $saved_acting_user;
        }

        $main_admin_id = self::get_main_admin_id();
        return $main_admin_id > 0 ? $main_admin_id : $user_id;
    }

    /**
     * Render an informative notice on the MCP page explaining API Key authentication in dev mode.
     */
    public static function render_dev_mcp_notice() {
        $admin_id = self::get_main_admin_id();
        $admin_user = get_userdata($admin_id);
        $admin_name = $admin_user ? $admin_user->user_login : 'admin';
        ?>
        <div class="notice notice-info" style="margin: 15px 0 10px; border-left: 4px solid #00a32a;">
            <p style="display: flex; align-items: center; gap: 8px; margin: 0.5em 0;">
                <span class="dashicons dashicons-shield-alt" style="color: #00a32a; font-size: 20px; width: 20px; height: 20px;"></span>
                <span>
                    <strong><?php esc_html_e('Developer Extension Active (Aiutoma Dev):', 'aiutoma-dev'); ?></strong>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: username */
                        __('MCP connections are authenticated as administrator (%s) using your API Key. Application Passwords are not required.', 'aiutoma-dev'),
                        $admin_name
                    ));
                    ?>
                </span>
            </p>
        </div>
        <?php
    }

    /**
     * Replace prompt authentication section with a single, clean API Key header.
     * Completely removes Application Passwords and bypass explanations from the AI prompt.
     *
     * @param string $default_content Default auth section content.
     * @param string $token MCP API Key token.
     * @return string
     */
    public static function filter_prompt_auth_section($default_content, $token) {
        return '<span id="aiutoma_prompt_api_key_line"><strong>' . esc_html__('Authentication Header:', 'aiutoma-dev') . '</strong> <code>X-MCP-API-Key: ' . esc_html($token) . '</code></span>';
    }

    /**
     * Render safety rules and autonomous safe mode recovery instructions in the system prompt.
     */
    public static function render_dev_prompt_instructions() {
        $safe_endpoint = esc_url(get_site_url() . '/wp-json/aiutoma/v1/mcp?aiutoma_enforce_safe_mode=1');
        ?>
        <br><br>
        <strong>Safety &amp; Recovery Rules:</strong><br>
        - <strong>NEVER</strong> modify core WordPress files; if necessary, utilize and modify the active theme's <code>functions.php</code>. Always use structured abilities to interact with WordPress data and settings safely.<br>
        - <strong>Autonomous Safe Mode Recovery:</strong> If the site or REST API returns an HTTP 500 error (e.g. caused by a fatal error in a third-party plugin or active theme), append <code>?aiutoma_enforce_safe_mode=1</code> to the endpoint URL (<code><?php echo $safe_endpoint; ?></code>). This activates Safe Mode, bypassing broken plugins and themes so you can continue operating and fix the issue safely.
        <?php
    }
}
