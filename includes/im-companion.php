<?php
namespace AiutomaDev\Includes;

if (!defined('ABSPATH')) {
    exit;
}

class Im_Companion {

    public static function init() {
        add_filter('aiutoma_im_dev_extension_active', '__return_true');
        add_filter('aiutoma_im_acting_user_id', [__CLASS__, 'filter_acting_user_id']);
        add_filter('aiutoma_im_selected_user_id', [__CLASS__, 'filter_selected_user_id']);
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

        return Mcp_Companion::get_main_admin_id();
    }

    /**
     * If Instant Messaging user selection is not set, default to main admin.
     *
     * @param int $user_id Default user ID.
     * @return int
     */
    public static function filter_selected_user_id($user_id) {
        $saved_acting_user = (int) get_option('aiutoma_im_acting_user', 0);
        if ($saved_acting_user > 0) {
            return $saved_acting_user;
        }

        $main_admin_id = Mcp_Companion::get_main_admin_id();
        return $main_admin_id > 0 ? $main_admin_id : $user_id;
    }
}
