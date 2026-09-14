<?php
namespace AiutomaDev\Includes;

if (!defined('ABSPATH')) {
    exit;
}

class Developer_Abilities {

    public static function register_all() {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        if (!class_exists('\Aiutoma\Modules\Ai\Abilities')) {
            return;
        }

        self::register_execute_php();
        self::register_run_wp_cli();
        self::register_modify_file();
        self::register_db_query();
        self::register_dev_manage_users();
    }

    private static function register_execute_php() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/execute-php', [
            'category' => 'aiutoma',
            'label' => __('Execute PHP Code', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Execute arbitrary PHP code within the WordPress environment. Use this to create posts, manage taxonomies, update settings, or call any native WordPress function. Do NOT include opening <?php tags. Return a value to receive it in the response.', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                require_once(ABSPATH . 'wp-admin/includes/post.php');
                require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');

                $code = $input['code'];

                if (preg_match('/\b(die|exit)\s*\(/i', $code)) {
                    return new \WP_Error('security_error', __('The use of die() or exit() is blocked as it will break the AI API response loop.', 'aiutoma-dev'));
                }

                global $aiutoma_dev_is_executing;
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                $aiutoma_dev_is_executing = true;

                $recorder = new Change_Recorder();
                $recorder->start_recording();

                global $aiutoma_dev_shutdown_registered;
                if (!$aiutoma_dev_shutdown_registered) {
                    register_shutdown_function(function () use ($recorder) {
                        global $aiutoma_dev_is_executing;
                        if ($aiutoma_dev_is_executing) {
                            $changes = $recorder->stop_recording();
                            if (!empty($changes)) {
                                $backup_dir = class_exists('\Aiutoma\Modules\Ai\Ai') ? \Aiutoma\Modules\Ai\Ai::get_storage_dir() . '/playground_backups' : wp_upload_dir()['basedir'] . '/aiutoma/playground_backups';
                                if (!is_dir($backup_dir)) wp_mkdir_p($backup_dir);
                                file_put_contents($backup_dir . '/php_eval_fatal_' . time() . '.json', json_encode(['changes' => $changes]));
                            }
                            $error = error_get_last();
                            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                                while (ob_get_level()) {
                                    ob_end_clean();
                                }
                                wp_send_json_error('Fatal Error during PHP execution: ' . $error['message'] . ' on line ' . $error['line']);
                            }
                        }
                    });
                    $aiutoma_dev_shutdown_registered = true;
                }

                ob_start();
                try {
                    $result = eval($code);
                    $output = ob_get_clean();
                    $aiutoma_dev_is_executing = false;

                    $changes = $recorder->stop_recording();
                    $backup_file = null;
                    if (!empty($changes)) {
                        $backup_dir = class_exists('\Aiutoma\Modules\Ai\Ai') ? \Aiutoma\Modules\Ai\Ai::get_storage_dir() . '/playground_backups' : wp_upload_dir()['basedir'] . '/aiutoma/playground_backups';
                        if (!is_dir($backup_dir)) wp_mkdir_p($backup_dir);
                        $backup_file = $backup_dir . '/php_eval_' . time() . '.json';
                        file_put_contents($backup_file, wp_json_encode(['code' => $code, 'changes' => $changes]));
                    }

                    $response = [
                        'success' => true,
                        'result' => $result,
                        'output' => $output
                    ];
                    if ($backup_file) {
                        $response['backup_file'] = basename($backup_file);
                    }
                    return $response;
                } catch (\Throwable $e) {
                    $aiutoma_dev_is_executing = false;
                    ob_end_clean();
                    $changes = $recorder->stop_recording();
                    if (!empty($changes)) {
                        $backup_dir = class_exists('\Aiutoma\Modules\Ai\Ai') ? \Aiutoma\Modules\Ai\Ai::get_storage_dir() . '/playground_backups' : wp_upload_dir()['basedir'] . '/aiutoma/playground_backups';
                        if (!is_dir($backup_dir)) wp_mkdir_p($backup_dir);
                        file_put_contents($backup_dir . '/php_eval_error_' . time() . '.json', wp_json_encode(['code' => $code, 'changes' => $changes, 'error' => $e->getMessage()]));
                    }
                    return new \WP_Error('php_error', $e->getMessage() . ' on line ' . $e->getLine());
                }
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'code' => [
                        'type' => 'string',
                        'description' => 'The raw PHP code snippet to execute. Do not include <?php tags.'
                    ]
                ],
                'required' => ['code']
            ]
        ]);
    }

    private static function register_run_wp_cli() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/run-wp-cli', [
            'category' => 'aiutoma',
            'label' => __('Run WP-CLI Command', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Runs a WP-CLI command on the server synchronously. Provide the arguments as an array of strings (e.g. ["plugin", "list", "--format=json"]).', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                if (!function_exists('exec')) {
                    return new \WP_Error('process_execution_disabled', __('Process execution (exec) is disabled on this server.', 'aiutoma-dev'));
                }

                $wp_path = null;
                $common_paths = ['/usr/local/bin/wp', '/usr/bin/wp', '/bin/wp'];
                foreach ($common_paths as $path) {
                    if (is_file($path) && is_executable($path)) {
                        $wp_path = $path;
                        break;
                    }
                }

                if (!$wp_path) {
                    exec('which wp 2>/dev/null', $output, $return_var);
                    if ($return_var === 0 && !empty($output[0])) {
                        $wp_path = trim($output[0]);
                    }
                }

                if (!$wp_path) {
                    return new \WP_Error('wp_cli_not_found', __('WP-CLI is not installed or not executable on this server.', 'aiutoma-dev'));
                }

                $args = $input['args'];
                if (!in_array('--allow-root', $args, true)) {
                    array_unshift($args, '--allow-root');
                }

                $cmd_args = array_map('escapeshellarg', $args);
                $cmd = escapeshellarg($wp_path) . ' ' . implode(' ', $cmd_args) . ' 2>&1';

                $output = [];
                $return_var = 0;
                exec('cd ' . escapeshellarg(ABSPATH) . ' && ' . $cmd, $output, $return_var);

                return [
                    'success' => $return_var === 0,
                    'exit_code' => $return_var,
                    'output' => implode("\n", $output)
                ];
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'args' => [
                        'type' => 'array',
                        'description' => 'Arguments to pass to wp (e.g. ["plugin", "list", "--format=json"]).',
                        'items' => ['type' => 'string']
                    ]
                ],
                'required' => ['args']
            ]
        ]);
    }

    private static function register_modify_file() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/modify-file', [
            'category' => 'aiutoma',
            'label' => __('Modifying File', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Writes or overwrites a file on the server with the provided content. You MUST always provide the full \'content\' parameter. To read a file, use the read-file tool instead. Safely restricted to wp-content.', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                $path = wp_normalize_path($input['path']);
                $content = $input['content'];

                $allowed_dir = wp_normalize_path(WP_CONTENT_DIR);
                if (strpos($path, $allowed_dir) !== 0 || strpos($path, '..') !== false) {
                    return new \WP_Error('security_error', __('You can only modify files inside the wp-content directory.', 'aiutoma-dev'));
                }

                $dir = dirname($path);
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                }

                if (file_put_contents($path, $content) === false) {
                    return new \WP_Error('file_error', __('Failed to write to file. Check permissions.', 'aiutoma-dev'));
                }

                return ['success' => true, 'message' => "File $path successfully modified."];
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Absolute path of the file to modify.'],
                    'content' => ['type' => 'string', 'description' => 'The complete content to write into the file. It will completely overwrite the existing content, so make sure to provide the full file contents.']
                ],
                'required' => ['path', 'content']
            ]
        ]);
    }

    private static function register_db_query() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/db-query', [
            'category' => 'aiutoma',
            'label' => __('Execute DB Query', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Execute raw SQL queries. Support SELECT, UPDATE, DELETE. Limit results.', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                global $wpdb;
                $query = $input['query'];

                $query_upper = strtoupper(trim($query));

                if (strpos($query_upper, 'SELECT ') === 0 || strpos($query_upper, 'SHOW ') === 0) {
                    $result = $wpdb->get_results($query, ARRAY_A);
                } else {
                    $result = $wpdb->query($query);
                    if ($result !== false) {
                        $result = ['success' => true, 'affected_rows' => $result];
                    }
                }

                if ($wpdb->last_error) {
                    return new \WP_Error('db_error', $wpdb->last_error);
                }

                if (is_array($result) && !isset($result['success']) && count($result) > 100) {
                    $result = array_slice($result, 0, 100);
                    $result[] = ['_warning' => 'Results truncated to 100 rows to prevent memory exhaustion. Please use a LIMIT clause or more specific WHERE conditions.'];
                }

                return $result !== null ? $result : ['success' => true];
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Raw SQL query']
                ],
                'required' => ['query']
            ]
        ]);
    }

    private static function register_dev_manage_users() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/dev-manage-users', [
            'category' => 'aiutoma',
            'label' => __('Manage Users & Roles (Dev)', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Full developer user, role, and capability management. Allows creating users, updating user accounts/roles/passwords, deleting users, creating/removing custom roles, and adding/removing capabilities.', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                $action = $input['action'] ?? 'get_users';
                $args = $input['args'] ?? [];

                if ($action === 'get_users') {
                    $users = get_users($args);
                    $data = [];
                    foreach ($users as $u) {
                        $data[] = [
                            'id' => $u->ID,
                            'user_login' => $u->user_login,
                            'display_name' => $u->display_name,
                            'user_email' => $u->user_email,
                            'user_nicename' => $u->user_nicename,
                            'roles' => (array) $u->roles,
                        ];
                    }
                    return ['success' => true, 'total' => count($data), 'users' => $data];
                } elseif ($action === 'create_user') {
                    if (!current_user_can('create_users')) {
                        return new \WP_Error('unauthorized', __('You do not have permission to create users.', 'aiutoma-dev'));
                    }
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    if (isset($args['role']) && $args['role'] === 'administrator' && !current_user_can('manage_options')) {
                        return new \WP_Error('unauthorized', __('Only administrators can create administrator accounts.', 'aiutoma-dev'));
                    }
                    $user_id = wp_insert_user($args);
                    if (is_wp_error($user_id)) {
                        return $user_id;
                    }
                    return ['success' => true, 'user_id' => $user_id, 'message' => 'User created successfully.'];
                } elseif ($action === 'update_user') {
                    $user_id = intval($args['ID'] ?? ($args['id'] ?? ($args['user_id'] ?? 0)));
                    if (!$user_id) {
                        return new \WP_Error('missing_id', __('User ID is required.', 'aiutoma-dev'));
                    }
                    if (!current_user_can('edit_user', $user_id)) {
                        return new \WP_Error('unauthorized', __('You do not have permission to edit this user.', 'aiutoma-dev'));
                    }
                    if (isset($args['role'])) {
                        if (!current_user_can('promote_users')) {
                            return new \WP_Error('unauthorized', __('You do not have permission to change user roles.', 'aiutoma-dev'));
                        }
                        if ($args['role'] === 'administrator' && !current_user_can('manage_options')) {
                            return new \WP_Error('unauthorized', __('Only administrators can assign administrator role.', 'aiutoma-dev'));
                        }
                    }
                    $args['ID'] = $user_id;
                    $updated = wp_update_user($args);
                    if (is_wp_error($updated)) {
                        return $updated;
                    }
                    if (!empty($args['meta']) && is_array($args['meta'])) {
                        foreach ($args['meta'] as $m_key => $m_val) {
                            update_user_meta($user_id, sanitize_key($m_key), $m_val);
                        }
                    }
                    return ['success' => true, 'user_id' => $user_id, 'message' => 'User updated successfully.'];
                } elseif ($action === 'delete_user') {
                    if (!current_user_can('delete_users')) {
                        return new \WP_Error('unauthorized', __('You do not have permission to delete users.', 'aiutoma-dev'));
                    }
                    $user_id = intval($args['user_id'] ?? ($args['ID'] ?? ($args['id'] ?? 0)));
                    if (!$user_id) {
                        return new \WP_Error('missing_id', __('User ID is required.', 'aiutoma-dev'));
                    }
                    if ($user_id === get_current_user_id()) {
                        return new \WP_Error('invalid_target', __('You cannot delete your own active user account.', 'aiutoma-dev'));
                    }
                    if (!current_user_can('delete_user', $user_id)) {
                        return new \WP_Error('unauthorized', __('You do not have permission to delete this specific user.', 'aiutoma-dev'));
                    }
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    $reassign = isset($args['reassign']) ? intval($args['reassign']) : null;
                    $result = wp_delete_user($user_id, $reassign);
                    if (!$result) {
                        return new \WP_Error('delete_failed', __('Failed to delete user.', 'aiutoma-dev'));
                    }
                    return ['success' => true, 'message' => "User ID {$user_id} deleted successfully."];
                } elseif ($action === 'set_user_role') {
                    if (!current_user_can('promote_users')) {
                        return new \WP_Error('unauthorized', __('You do not have permission to change user roles.', 'aiutoma-dev'));
                    }
                    $user_id = intval($args['user_id'] ?? ($args['ID'] ?? 0));
                    $user = get_user_by('id', $user_id);
                    if (!$user) {
                        return new \WP_Error('user_not_found', __('User not found.', 'aiutoma-dev'));
                    }
                    $role = sanitize_key($args['role'] ?? '');
                    if ($role === 'administrator' && !current_user_can('manage_options')) {
                        return new \WP_Error('unauthorized', __('Only administrators can assign administrator role.', 'aiutoma-dev'));
                    }
                    $user->set_role($role);
                    return ['success' => true, 'message' => "Role for user {$user_id} set to {$role}."];
                } elseif ($action === 'add_role') {
                    if (!current_user_can('manage_options')) {
                        return new \WP_Error('unauthorized', __('Only administrators can create roles.', 'aiutoma-dev'));
                    }
                    if (empty($args['role']) || empty($args['display_name'])) {
                        return new \WP_Error('missing_args', __('Role slug and display name are required.', 'aiutoma-dev'));
                    }
                    $role_slug = sanitize_key($args['role']);
                    $display_name = sanitize_text_field($args['display_name']);
                    $caps = isset($args['capabilities']) && is_array($args['capabilities']) ? $args['capabilities'] : [];
                    $result = add_role($role_slug, $display_name, $caps);
                    if (!$result) {
                        return new \WP_Error('role_exists', __('Role already exists or could not be created.', 'aiutoma-dev'));
                    }
                    return ['success' => true, 'message' => "Role {$role_slug} created successfully."];
                } elseif ($action === 'remove_role') {
                    if (!current_user_can('manage_options')) {
                        return new \WP_Error('unauthorized', __('Only administrators can delete roles.', 'aiutoma-dev'));
                    }
                    $role_slug = sanitize_key($args['role'] ?? '');
                    if (in_array($role_slug, ['administrator'], true)) {
                        return new \WP_Error('protected_role', __('The administrator role cannot be deleted.', 'aiutoma-dev'));
                    }
                    remove_role($role_slug);
                    return ['success' => true, 'message' => "Role {$role_slug} removed."];
                } elseif ($action === 'add_cap' || $action === 'remove_cap') {
                    if (!current_user_can('manage_options')) {
                        return new \WP_Error('unauthorized', __('Only administrators can modify capabilities.', 'aiutoma-dev'));
                    }
                    $role = get_role(sanitize_key($args['role'] ?? ''));
                    if (!$role) {
                        return new \WP_Error('invalid_role', __('Role not found.', 'aiutoma-dev'));
                    }
                    $cap = sanitize_key($args['cap'] ?? ($args['capability'] ?? ''));
                    if (!$cap) {
                        return new \WP_Error('missing_cap', __('Capability name is required.', 'aiutoma-dev'));
                    }
                    if ($action === 'add_cap') {
                        $role->add_cap($cap);
                        return ['success' => true, 'message' => "Capability {$cap} added to role {$args['role']}."];
                    } else {
                        $role->remove_cap($cap);
                        return ['success' => true, 'message' => "Capability {$cap} removed from role {$args['role']}."];
                    }
                }

                return new \WP_Error('invalid_action', __('Unsupported action.', 'aiutoma-dev'));
            },
            'permission_callback' => function () {
                return current_user_can('manage_options') || current_user_can('promote_users');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'enum' => ['get_users', 'create_user', 'update_user', 'delete_user', 'set_user_role', 'add_role', 'remove_role', 'add_cap', 'remove_cap'],
                        'description' => 'Action to perform: get_users, create_user, update_user, delete_user, set_user_role, add_role, remove_role, add_cap, remove_cap.'
                    ],
                    'args' => [
                        'type' => 'object',
                        'description' => 'Arguments for the action. For create_user: {"user_login":"name", "user_email":"a@b.com", "role":"editor"}. For update_user: {"ID":2, "role":"author"}. For delete_user: {"user_id":2, "reassign":1}. For add_role: {"role":"manager", "display_name":"Manager"}. For add_cap: {"role":"manager", "cap":"edit_posts"}.'
                    ]
                ],
                'required' => ['action']
            ]
        ]);
    }
}

