<?php
namespace AiutomaDev\Includes;

if (!defined('ABSPATH')) {
    exit;
}

class Mcp_Companion {

    public static function init() {
        add_filter('aiutoma_mcp_dev_extension_active', '__return_true');
        add_filter('aiutoma_mcp_acting_user_id', [__CLASS__, 'filter_acting_user_id']);
        add_filter('aiutoma_mcp_selected_user_id', [__CLASS__, 'filter_selected_user_id']);
    }

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
}
