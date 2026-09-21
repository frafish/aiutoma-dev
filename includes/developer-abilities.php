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
        self::register_scaffold_theme();
        self::register_create_pdf();
        self::register_read_file();
        self::register_list_directory();
        self::register_manage_plugins();
        self::register_manage_themes();
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

    private static function split_basic_command_args($command) {
        $args = [];
        $current = '';
        $in_quote = null;
        $len = strlen($command);
        for ($i = 0; $i < $len; $i++) {
            $char = $command[$i];
            if ($in_quote !== null) {
                if ($char === $in_quote) {
                    $in_quote = null;
                } else {
                    $current .= $char;
                }
            } elseif (($char === '"' || $char === "'") && ($current === '' || str_ends_with($current, '='))) {
                $in_quote = $char;
            } elseif (preg_match('/\s/', $char)) {
                if ($current !== '') {
                    $args[] = $current;
                    $current = '';
                }
            } else {
                $current .= $char;
            }
        }
        if ($current !== '') {
            $args[] = $current;
        }
        return $args;
    }

    private static function strip_matching_outer_quotes($value) {
        $len = strlen($value);
        if ($len < 2) {
            return $value;
        }
        $first = $value[0];
        $last = $value[$len - 1];
        if (($first === '"' || $first === "'") && $first === $last) {
            return substr($value, 1, -1);
        }
        return $value;
    }

    private static function split_post_content_command_args($command, $post_content_index) {
        $marker = '--post_content=';
        $prefix = trim(substr($command, 0, $post_content_index));
        $tail = trim(substr($command, $post_content_index + strlen($marker)));
        $prefix_args = self::split_basic_command_args($prefix);
        if ($tail === '') {
            return array_merge($prefix_args, [$marker]);
        }
        $quote = $tail[0];
        if ($quote === '"' || $quote === "'") {
            $closing = strpos($tail, $quote, 1);
            if ($closing !== false) {
                $post_content = substr($tail, 1, $closing - 1);
                $suffix = trim(substr($tail, $closing + 1));
                return array_merge($prefix_args, ["{$marker}{$post_content}"], self::split_basic_command_args($suffix));
            }
        }
        return array_merge($prefix_args, [$marker . self::strip_matching_outer_quotes($tail)]);
    }

    public static function split_command_args($command) {
        $pos = strpos($command, '--post_content=');
        if ($pos !== false) {
            return self::split_post_content_command_args($command, $pos);
        }
        return self::split_basic_command_args($command);
    }

    private static function get_unsupported_wp_cli_option_message($args) {
        foreach ($args as $arg) {
            if (preg_match('/^[\x{2010}-\x{2015}]\S+/u', $arg)) {
                return sprintf(
                    __('Unsupported WP-CLI option "%s": use ASCII hyphens, for example "--porcelain", not a typographic dash.', 'aiutoma-dev'),
                    $arg
                );
            }
        }
        return null;
    }

    private static function find_wp_cli_binary() {
        $common_paths = [
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            '/bin/wp',
            rtrim(ABSPATH, '/') . '/wp-cli.phar',
        ];
        foreach ($common_paths as $path) {
            if (is_file($path) && (is_executable($path) || str_ends_with($path, '.phar'))) {
                return $path;
            }
        }
        if (function_exists('exec')) {
            $output = [];
            $return_var = 0;
            exec('which wp 2>/dev/null', $output, $return_var);
            if ($return_var === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
        }
        return null;
    }

    private static function register_run_wp_cli() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/run-wp-cli', [
            'category' => 'aiutoma',
            'label' => __('Run WP-CLI Command', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Runs a WP-CLI command on the server synchronously. Examples: "plugin install woocommerce --activate", "option get blogname", "user list". Accepts either `command` (string without "wp" prefix) or `args` (array of strings).', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                if (!function_exists('exec')) {
                    return new \WP_Error('process_execution_disabled', __('Process execution (exec) is disabled on this server.', 'aiutoma-dev'));
                }

                $wp_path = self::find_wp_cli_binary();
                if (!$wp_path) {
                    return new \WP_Error('wp_cli_not_found', __('WP-CLI is not installed or not executable on this server.', 'aiutoma-dev'));
                }

                $is_command_mode = false;
                if (!empty($input['command']) && is_string($input['command'])) {
                    $raw_command = trim($input['command']);
                    if (str_starts_with($raw_command, 'wp ')) {
                        $raw_command = substr($raw_command, 3);
                    }
                    $args = self::split_command_args($raw_command);
                    $is_command_mode = true;
                } elseif (!empty($input['args']) && is_array($input['args'])) {
                    $args = $input['args'];
                } else {
                    return new \WP_Error('missing_args', __('Either command (string) or args (array) must be provided.', 'aiutoma-dev'));
                }

                $unsupported_dash = self::get_unsupported_wp_cli_option_message($args);
                if ($unsupported_dash) {
                    return new \WP_Error('unsupported_option', $unsupported_dash);
                }

                if (!in_array('--allow-root', $args, true)) {
                    array_unshift($args, '--allow-root');
                }

                $cmd_args = array_map('escapeshellarg', $args);
                $executable = str_ends_with($wp_path, '.phar')
                    ? escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($wp_path)
                    : escapeshellarg($wp_path);

                $cmd = $executable . ' ' . implode(' ', $cmd_args) . ' 2>&1';

                $output = [];
                $return_var = 0;
                exec('cd ' . escapeshellarg(ABSPATH) . ' && ' . $cmd, $output, $return_var);

                $output_str = implode("\n", $output);

                if ($is_command_mode) {
                    if ($return_var !== 0) {
                        return new \WP_Error('wp_cli_failed', $output_str ?: sprintf(__('WP-CLI exited with code %d', 'aiutoma-dev'), $return_var));
                    }
                    return $output_str !== '' ? $output_str : __('Command completed with no output.', 'aiutoma-dev');
                }

                return [
                    'success' => $return_var === 0,
                    'exit_code' => $return_var,
                    'output' => $output_str
                ];
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'command' => [
                        'type' => 'string',
                        'description' => 'The WP-CLI command to run (without the "wp" prefix). Example: "plugin list --status=active"'
                    ],
                    'args' => [
                        'type' => 'array',
                        'description' => 'Legacy array of arguments to pass to wp (e.g. ["plugin", "list", "--format=json"]).',
                        'items' => ['type' => 'string']
                    ],
                    'nameOrPath' => [
                        'type' => 'string',
                        'description' => 'The site name or file system path to the site (defaults to current WordPress installation).'
                    ]
                ]
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

    private static function derive_theme_slug($input) {
        $slug = strtolower($input);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
        return trim($slug, '-');
    }

    private static function render_theme_style_css($name, $slug) {
        return "/*
Theme Name: {$name}
Description: A custom block theme scaffolded by Aiutoma.
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 7.2
Version: 0.1.0
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: {$slug}
Tags: full-site-editing, block-patterns, block-styles, wide-blocks, accessibility-ready, style-variations
*/

/* WordPress inserts margin-block-start: var(--wp--style--block-gap) between the
   template's top-level sections (header part, main, footer part) — a gap that
   exists even when no markup asks for it. Zero it so sections butt edge-to-edge
   and own their vertical rhythm via padding; this does not affect block gaps
   inside nested layouts. */
.wp-site-blocks > * + * {
	margin-block-start: 0;
}

/* With that gap gone, main carries its own vertical padding so templates this
   theme does not author (WooCommerce shop, product, cart…) still clear the
   header and footer. A descendant selector, not a child one: WooCommerce wraps
   the single-product main in an extra group. Templates built from full-bleed
   sections opt out with is-flush and let the sections own the rhythm. */
.wp-site-blocks main {
	padding-block: var(--wp--preset--spacing--60) var(--wp--preset--spacing--70);
}
.wp-site-blocks main.is-flush {
	padding-block: 0;
}
";
    }

    private static function render_child_theme_style_css($name, $slug, $parent_slug) {
        return "/*
Theme Name: {$name}
Description: A child theme of {$parent_slug}, scaffolded by Aiutoma.
Template: {$parent_slug}
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 7.2
Version: 0.1.0
License: GNU General Public License v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Text Domain: {$slug}
Tags: full-site-editing, block-patterns, block-styles, wide-blocks, accessibility-ready, style-variations
*/
";
    }

    private static function render_theme_json() {
        return wp_json_encode([
            '$schema' => "https://schemas.wp.org/wp/6.7/theme.json",
            'version' => 3,
            'settings' => [
                'appearanceTools' => true,
                'layout' => [
                    'contentSize' => "1000px",
                    'wideSize' => "1280px"
                ],
                'useRootPaddingAwareAlignments' => true
            ],
            'styles' => [
                'spacing' => [
                    'padding' => [
                        'top' => "0px",
                        'right' => "clamp(1.25rem, 5vw, 3rem)",
                        'bottom' => "0px",
                        'left' => "clamp(1.25rem, 5vw, 3rem)"
                    ]
                ]
            ],
            'customTemplates' => [
                [
                    'name' => "page-no-title",
                    'title' => "Page (no title)",
                    'postTypes' => ["page"]
                ]
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function render_child_theme_json() {
        return wp_json_encode([
            '$schema' => "https://schemas.wp.org/wp/6.7/theme.json",
            'version' => 3
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private static function render_theme_functions_php($name, $slug) {
        return "<?php
/**
 * {$name} theme functions.
 *
 * @package {$slug}
 */

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style(
		'{$slug}-style',
		get_parent_theme_file_uri( 'style.css' ),
		array(),
		wp_get_theme()->get( 'Version' )
	);
} );

add_action( 'after_setup_theme', function () {
	add_editor_style( 'style.css' );
} );
";
    }

    private static function render_child_theme_functions_php($name, $slug, $parent_slug) {
        return "<?php
/**
 * {$name} child theme functions.
 *
 * @package {$slug}
 */

add_action( 'wp_enqueue_scripts', function () {
	// Parents that enqueue their stylesheet via get_stylesheet_uri() would load
	// the child's near-empty style.css instead — enqueue the parent's directly.
	wp_enqueue_style(
		'{$parent_slug}-parent-style',
		get_template_directory_uri() . '/style.css',
		array(),
		wp_get_theme( get_template() )->get( 'Version' )
	);
	wp_enqueue_style(
		'{$slug}-style',
		get_stylesheet_directory_uri() . '/style.css',
		array( '{$parent_slug}-parent-style' ),
		wp_get_theme()->get( 'Version' )
	);
} );

add_action( 'after_setup_theme', function () {
	add_editor_style( 'style.css' );
} );
";
    }

    private static function register_scaffold_theme() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/scaffold-theme', [
            'category' => 'aiutoma',
            'label' => __('Scaffold Block Theme', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Scaffold a minimal block theme or child theme with standard templates, theme.json, and starter styles into wp-content/themes/<slug>/ and optionally activate it.', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                $raw_name = isset($input['name']) ? trim((string)$input['name']) : '';
                if ($raw_name === '') {
                    return new \WP_Error('invalid_name', __('Theme name must not be empty.', 'aiutoma-dev'));
                }

                $raw_slug = isset($input['slug']) && trim((string)$input['slug']) !== ''
                    ? trim((string)$input['slug'])
                    : self::derive_theme_slug($raw_name);

                if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $raw_slug)) {
                    return new \WP_Error('invalid_slug', __('Theme slug must contain only lowercase letters, digits and dashes, and start with a letter or digit.', 'aiutoma-dev'));
                }

                $themes_dir = WP_CONTENT_DIR . '/themes';
                if (!is_dir($themes_dir)) {
                    return new \WP_Error('missing_themes_dir', sprintf(__('wp-content/themes directory not found: %s', 'aiutoma-dev'), $themes_dir));
                }

                $parent_slug = isset($input['parentTheme']) && trim((string)$input['parentTheme']) !== ''
                    ? trim((string)$input['parentTheme'])
                    : null;

                if ($parent_slug !== null) {
                    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $parent_slug)) {
                        return new \WP_Error('invalid_parent_slug', __('Parent theme slug must contain only lowercase letters, digits and dashes, and start with a letter or digit.', 'aiutoma-dev'));
                    }
                    if ($parent_slug === $raw_slug) {
                        return new \WP_Error('invalid_parent', __('parentTheme must be different from the child theme slug. Pick a distinct slug for the child theme.', 'aiutoma-dev'));
                    }

                    $parent_dir = $themes_dir . '/' . $parent_slug;
                    $parent_style = $parent_dir . '/style.css';
                    if (!is_file($parent_style)) {
                        return new \WP_Error('parent_not_found', sprintf(__('Parent theme \'%s\' is not installed at wp-content/themes/%s/.', 'aiutoma-dev'), $parent_slug, $parent_slug));
                    }

                    $parent_content = file_get_contents($parent_style);
                    if (preg_match('/^[ \t\/*#@]*Template:[ \t]*(\S+)/im', $parent_content, $m)) {
                        return new \WP_Error('grandchild_unsupported', sprintf(__("'%s' is itself a child theme of '%s' — WordPress does not support grandchild themes. Use parentTheme: '%s' instead.", 'aiutoma-dev'), $parent_slug, $m[1], $m[1]));
                    }
                }

                $target_theme_dir = $themes_dir . '/' . $raw_slug;
                if (is_dir($target_theme_dir)) {
                    return new \WP_Error('theme_exists', sprintf(__('A theme already exists at wp-content/themes/%s. Choose a different slug or remove the existing directory first.', 'aiutoma-dev'), $raw_slug));
                }

                $template_index = "<!-- wp:template-part {\"slug\":\"header\"} /-->\n\n<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\">\n\t<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n\t<div class=\"wp-block-group\">\n\t\t<!-- wp:query {\"queryId\":1,\"query\":{\"perPage\":10,\"pages\":0,\"offset\":0,\"postType\":\"post\",\"order\":\"desc\",\"orderBy\":\"date\",\"inherit\":true}} -->\n\t\t<div class=\"wp-block-query\">\n\t\t\t<!-- wp:post-template -->\n\t\t\t\t<!-- wp:post-title {\"isLink\":true,\"level\":2} /-->\n\t\t\t\t<!-- wp:post-date /-->\n\t\t\t\t<!-- wp:post-excerpt /-->\n\t\t\t<!-- /wp:post-template -->\n\n\t\t\t<!-- wp:query-pagination -->\n\t\t\t\t<!-- wp:query-pagination-previous /-->\n\t\t\t\t<!-- wp:query-pagination-numbers /-->\n\t\t\t\t<!-- wp:query-pagination-next /-->\n\t\t\t<!-- /wp:query-pagination -->\n\n\t\t\t<!-- wp:query-no-results -->\n\t\t\t\t<!-- wp:paragraph -->\n\t\t\t\t<p>No posts were found.</p>\n\t\t\t\t<!-- /wp:paragraph -->\n\t\t\t<!-- /wp:query-no-results -->\n\t\t</div>\n\t\t<!-- /wp:query -->\n\t</div>\n\t<!-- /wp:group -->\n</main>\n<!-- /wp:group -->\n\n<!-- wp:template-part {\"slug\":\"footer\"} /-->\n";
                $template_single = "<!-- wp:template-part {\"slug\":\"header\"} /-->\n\n<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\">\n\t<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n\t<div class=\"wp-block-group\">\n\t\t<!-- wp:post-title {\"level\":1} /-->\n\t\t<!-- wp:post-date /-->\n\t\t<!-- wp:post-featured-image /-->\n\t</div>\n\t<!-- /wp:group -->\n\n\t<!-- wp:post-content {\"layout\":{\"type\":\"constrained\"}} /-->\n\n\t<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n\t<div class=\"wp-block-group\">\n\t\t<!-- wp:post-comments-form /-->\n\t</div>\n\t<!-- /wp:group -->\n</main>\n<!-- /wp:group -->\n\n<!-- wp:template-part {\"slug\":\"footer\"} /-->\n";
                $template_page = "<!-- wp:template-part {\"slug\":\"header\"} /-->\n\n<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\">\n\t<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n\t<div class=\"wp-block-group\">\n\t\t<!-- wp:post-title {\"level\":1} /-->\n\t</div>\n\t<!-- /wp:group -->\n\n\t<!-- wp:post-content {\"layout\":{\"type\":\"constrained\"}} /-->\n</main>\n<!-- /wp:group -->\n\n<!-- wp:template-part {\"slug\":\"footer\"} /-->\n";
                $template_page_no_title = "<!-- wp:template-part {\"slug\":\"header\"} /-->\n\n<!-- wp:group {\"tagName\":\"main\",\"className\":\"is-flush\"} -->\n<main class=\"wp-block-group is-flush\">\n\t<!-- wp:post-content {\"layout\":{\"type\":\"constrained\"}} /-->\n</main>\n<!-- /wp:group -->\n\n<!-- wp:template-part {\"slug\":\"footer\"} /-->\n";
                $template_archive = "<!-- wp:template-part {\"slug\":\"header\"} /-->\n\n<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\">\n\t<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n\t<div class=\"wp-block-group\">\n\t\t<!-- wp:query-title {\"type\":\"archive\"} /-->\n\t\t<!-- wp:term-description /-->\n\n\t\t<!-- wp:query {\"queryId\":1,\"query\":{\"perPage\":10,\"pages\":0,\"offset\":0,\"postType\":\"post\",\"order\":\"desc\",\"orderBy\":\"date\",\"inherit\":true}} -->\n\t\t<div class=\"wp-block-query\">\n\t\t\t<!-- wp:post-template -->\n\t\t\t\t<!-- wp:post-title {\"isLink\":true,\"level\":2} /-->\n\t\t\t\t<!-- wp:post-date /-->\n\t\t\t\t<!-- wp:post-excerpt /-->\n\t\t\t<!-- /wp:post-template -->\n\n\t\t\t<!-- wp:query-pagination -->\n\t\t\t\t<!-- wp:query-pagination-previous /-->\n\t\t\t\t<!-- wp:query-pagination-numbers /-->\n\t\t\t\t<!-- wp:query-pagination-next /-->\n\t\t\t<!-- /wp:query-pagination -->\n\n\t\t\t<!-- wp:query-no-results -->\n\t\t\t\t<!-- wp:paragraph -->\n\t\t\t\t<p>No posts were found.</p>\n\t\t\t\t<!-- /wp:paragraph -->\n\t\t\t<!-- /wp:query-no-results -->\n\t\t</div>\n\t\t<!-- /wp:query -->\n\t</div>\n\t<!-- /wp:group -->\n</main>\n<!-- /wp:group -->\n\n<!-- wp:template-part {\"slug\":\"footer\"} /-->\n";
                $template_404 = "<!-- wp:template-part {\"slug\":\"header\"} /-->\n\n<!-- wp:group {\"tagName\":\"main\"} -->\n<main class=\"wp-block-group\">\n\t<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n\t<div class=\"wp-block-group\">\n\t\t<!-- wp:heading {\"level\":1} -->\n\t\t<h1 class=\"wp-block-heading\">Page not found</h1>\n\t\t<!-- /wp:heading -->\n\n\t\t<!-- wp:paragraph -->\n\t\t<p>The page you were looking for doesn't exist. Try a search instead.</p>\n\t\t<!-- /wp:paragraph -->\n\n\t\t<!-- wp:search {\"label\":\"Search\",\"showLabel\":false,\"buttonText\":\"Search\"} /-->\n\t</div>\n\t<!-- /wp:group -->\n</main>\n<!-- /wp:group -->\n\n<!-- wp:template-part {\"slug\":\"footer\"} /-->\n";
                $part_header = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\">\n\t<!-- wp:group {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"space-between\"},\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|40\",\"bottom\":\"var:preset|spacing|40\"}}}} -->\n\t<div class=\"wp-block-group\" style=\"padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40)\">\n\t\t<!-- wp:site-title {\"level\":0} /-->\n\t\t<!-- wp:navigation /-->\n\t</div>\n\t<!-- /wp:group -->\n</div>\n<!-- /wp:group -->\n";
                $part_footer = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"},\"style\":{\"spacing\":{\"padding\":{\"top\":\"var:preset|spacing|50\",\"bottom\":\"var:preset|spacing|50\"}}}} -->\n<div class=\"wp-block-group\" style=\"padding-top:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50)\">\n\t<!-- wp:group {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} -->\n\t<div class=\"wp-block-group\">\n\t\t<!-- wp:paragraph -->\n\t\t<p>©</p>\n\t\t<!-- /wp:paragraph -->\n\t\t<!-- wp:site-title {\"isLink\":false,\"level\":0} /-->\n\t</div>\n\t<!-- /wp:group -->\n</div>\n<!-- /wp:group -->\n";

                $files = [];
                if ($parent_slug !== null) {
                    wp_mkdir_p($target_theme_dir);
                    $files = [
                        ['style.css', self::render_child_theme_style_css($raw_name, $raw_slug, $parent_slug)],
                        ['theme.json', self::render_child_theme_json()],
                        ['functions.php', self::render_child_theme_functions_php($raw_name, $raw_slug, $parent_slug)]
                    ];
                } else {
                    wp_mkdir_p($target_theme_dir . '/templates');
                    wp_mkdir_p($target_theme_dir . '/parts');
                    wp_mkdir_p($target_theme_dir . '/assets/fonts');
                    wp_mkdir_p($target_theme_dir . '/patterns');

                    $files = [
                        ['style.css', self::render_theme_style_css($raw_name, $raw_slug)],
                        ['theme.json', self::render_theme_json()],
                        ['functions.php', self::render_theme_functions_php($raw_name, $raw_slug)],
                        ['templates/index.html', $template_index],
                        ['templates/single.html', $template_single],
                        ['templates/page.html', $template_page],
                        ['templates/page-no-title.html', $template_page_no_title],
                        ['templates/archive.html', $template_archive],
                        ['templates/404.html', $template_404],
                        ['parts/header.html', $part_header],
                        ['parts/footer.html', $part_footer]
                    ];
                }

                foreach ($files as $file_entry) {
                    $target_file = $target_theme_dir . '/' . $file_entry[0];
                    $target_parent = dirname($target_file);
                    if (!is_dir($target_parent)) {
                        wp_mkdir_p($target_parent);
                    }
                    file_put_contents($target_file, $file_entry[1]);
                }

                $do_activate = !isset($input['activate']) || (bool)$input['activate'];
                $activation_result = null;
                if ($do_activate) {
                    switch_theme($raw_slug);
                    $current_theme = wp_get_theme();
                    if ($current_theme->get_stylesheet() === $raw_slug) {
                        $activation_result = ['ok' => true, 'message' => sprintf(__('Switched to \'%s\' theme.', 'aiutoma-dev'), $raw_name)];
                    } else {
                        $activation_result = ['ok' => false, 'message' => __('Automatic activation could not complete.', 'aiutoma-dev')];
                    }
                }

                $summary_lines = [];
                if ($parent_slug !== null) {
                    $summary_lines[] = sprintf("Child theme '%s' of '%s' scaffolded at wp-content/themes/%s/.", $raw_name, $parent_slug, $raw_slug);
                    $summary_lines[] = "";
                    $summary_lines[] = "Created files:";
                    foreach ($files as $f) {
                        $summary_lines[] = "  " . $f[0];
                    }
                    $summary_lines[] = "";
                    $summary_lines[] = sprintf("Templates, parts, patterns, theme.json settings, and styles inherit from '%s'.", $parent_slug);
                    $summary_lines[] = "Override by creating files at the same relative path inside the child theme; put CSS and theme.json changes in the child, never in the parent.";
                    $summary_lines[] = "";
                    $summary_lines[] = "These files already contain standard WordPress headers.";
                    $summary_lines[] = "Read a file before editing it — do not assume its contents.";
                    $summary_lines[] = "";
                } else {
                    $summary_lines[] = sprintf("Block theme '%s' scaffolded at wp-content/themes/%s/.", $raw_name, $raw_slug);
                    $summary_lines[] = "";
                    $summary_lines[] = "Created files:";
                    foreach ($files as $f) {
                        $summary_lines[] = "  " . $f[0];
                    }
                    $summary_lines[] = "";
                    $summary_lines[] = "Empty directories:";
                    $summary_lines[] = "  assets/fonts/";
                    $summary_lines[] = "  patterns/";
                    $summary_lines[] = "";
                    $summary_lines[] = "These files already contain standard WordPress headers and starter content.";
                    $summary_lines[] = "Read a file before editing it — do not assume its contents.";
                    $summary_lines[] = "";
                }

                if (!$do_activate) {
                    $summary_lines[] = "Activate with: wp theme activate " . $raw_slug;
                } elseif ($activation_result && $activation_result['ok']) {
                    $summary_lines[] = "Activated: " . $activation_result['message'];
                } else {
                    $msg = $activation_result ? $activation_result['message'] : 'skipped';
                    $summary_lines[] = "Activation skipped: " . $msg;
                    $summary_lines[] = "Activate manually with: wp theme activate " . $raw_slug;
                }

                return implode("\n", $summary_lines);
            },
            'permission_callback' => function () {
                return current_user_can('manage_options') && current_user_can('switch_themes');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'nameOrPath' => [
                        'type' => 'string',
                        'description' => 'The site name or filesystem path of the site to scaffold the theme into (defaults to current WordPress site).'
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Display name of the theme (e.g. "Acme Studio"). Used in style.css Theme Name header.'
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'Optional theme slug (lowercase letters, digits, dashes). Used as directory name and text domain. Derived from name when omitted.'
                    ],
                    'parentTheme' => [
                        'type' => 'string',
                        'description' => 'Slug of an installed theme to use as the parent (e.g. "twentytwentyfour"). When set, scaffolds a CHILD theme instead of a blank theme. The parent must already exist under wp-content/themes/.'
                    ],
                    'activate' => [
                        'type' => 'boolean',
                        'description' => 'Whether to activate the theme after scaffolding via switch_theme(). Defaults to true. Set to false to leave the theme inactive.'
                    ]
                ],
                'required' => ['name']
            ]
        ]);
    }

    private static function register_create_pdf() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/create-pdf', [
            'category' => 'aiutoma',
            'label' => __('Create PDF from HTML', 'aiutoma-dev'),
            'description' => __('Convert HTML and CSS into a PDF document using a lightweight embedded PDF engine. Saves the file in WordPress uploads and returns the file path, public URL, file size, and page count.', 'aiutoma-dev'),
            'meta' => [
                'show_in_rest' => true,
                'mcp' => ['public' => true]
            ],
            'execute_callback' => function ($input) {
                // 1. Conflict prevention: check if Dompdf is already loaded by another plugin
                if (!class_exists('\Dompdf\Dompdf')) {
                    // 2. Lazy loading: load vendor autoloader ONLY on-demand when creating a PDF
                    $autoload_file = defined('AIUTOMA_DEV_PATH') ? AIUTOMA_DEV_PATH . 'vendor/autoload.php' : plugin_dir_path(dirname(__DIR__)) . 'vendor/autoload.php';
                    if (file_exists($autoload_file)) {
                        require_once $autoload_file;
                    }
                }

                if (!class_exists('\Dompdf\Dompdf')) {
                    return new \WP_Error('dompdf_missing', __('PDF generation library is not installed or loaded in aiutoma-dev.', 'aiutoma-dev'));
                }

                $raw_html = (string)($input['html'] ?? '');
                if (trim($raw_html) === '') {
                    return new \WP_Error('missing_html', __('HTML content is required to generate a PDF.', 'aiutoma-dev'));
                }

                $css = (string)($input['css'] ?? '');
                $format = !empty($input['format']) ? sanitize_text_field($input['format']) : 'A4';
                $orientation = !empty($input['orientation']) && strtolower($input['orientation']) === 'landscape' ? 'landscape' : 'portrait';
                $margin = !empty($input['margin']) ? sanitize_text_field($input['margin']) : '15mm';
                $page_numbers = !empty($input['page_numbers']);

                // Prepare @page rules and CSS
                $page_css = "@page { margin: {$margin}; }";
                if (!empty($input['margin_top'])) {
                    $mt = sanitize_text_field($input['margin_top']);
                    $page_css .= " @page { margin-top: {$mt}; }";
                }
                if (!empty($input['margin_right'])) {
                    $mr = sanitize_text_field($input['margin_right']);
                    $page_css .= " @page { margin-right: {$mr}; }";
                }
                if (!empty($input['margin_bottom'])) {
                    $mb = sanitize_text_field($input['margin_bottom']);
                    $page_css .= " @page { margin-bottom: {$mb}; }";
                }
                if (!empty($input['margin_left'])) {
                    $ml = sanitize_text_field($input['margin_left']);
                    $page_css .= " @page { margin-left: {$ml}; }";
                }

                $combined_css = $page_css . "\n" . $css;

                // Build complete HTML structure if not already a full document
                if (!preg_match('/<html[\s>]/i', $raw_html)) {
                    $full_html = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"UTF-8\">\n";
                    $full_html .= "<style>\nbody { font-family: Helvetica, Arial, sans-serif; color: #222; }\n" . $combined_css . "\n</style>\n";
                    $full_html .= "</head>\n<body>\n" . $raw_html . "\n</body>\n</html>";
                } else {
                    // Inject CSS into existing <head> if present, otherwise prepend
                    if (stripos($raw_html, '</head>') !== false) {
                        $full_html = str_ireplace('</head>', "<style>\n" . $combined_css . "\n</style>\n</head>", $raw_html);
                    } else {
                        $full_html = "<style>\n" . $combined_css . "\n</style>\n" . $raw_html;
                    }
                }

                $to_media_library = !empty($input['add_to_media_library']);
                $upload_dir = wp_upload_dir();

                // Generate sanitized filename
                $raw_filename = !empty($input['filename']) ? sanitize_file_name($input['filename']) : '';
                if (empty($raw_filename)) {
                    $raw_filename = 'doc_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false) . '.pdf';
                }
                if (!str_ends_with(strtolower($raw_filename), '.pdf')) {
                    $raw_filename .= '.pdf';
                }

                if ($to_media_library) {
                    // Standard WordPress Media Library path (uploads/YYYY/MM/)
                    $target_dir = wp_normalize_path($upload_dir['path']);
                    if (!wp_mkdir_p($target_dir)) {
                        return new \WP_Error('directory_creation_failed', __('Unable to create WordPress uploads directory.', 'aiutoma-dev'));
                    }
                    $target_path = $target_dir . '/' . $raw_filename;
                    $target_url = $upload_dir['url'] . '/' . $raw_filename;
                } else {
                    // Private storage directory protected from direct HTTP URL access
                    $target_dir = wp_normalize_path($upload_dir['basedir'] . '/aiutoma/pdf');
                    if (!wp_mkdir_p($target_dir)) {
                        return new \WP_Error('directory_creation_failed', __('Unable to create private PDF storage directory.', 'aiutoma-dev'));
                    }

                    // Security: protect private directory from direct HTTP access
                    $htaccess_file = $target_dir . '/.htaccess';
                    if (!file_exists($htaccess_file)) {
                        $htaccess_rules = "# Deny direct public access to private generated PDFs\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
                        @file_put_contents($htaccess_file, $htaccess_rules);
                    }

                    $index_file = $target_dir . '/index.php';
                    if (!file_exists($index_file)) {
                        @file_put_contents($index_file, "<?php\nhttp_response_code(403);\nexit('Direct access forbidden.');\n");
                    }

                    $target_path = $target_dir . '/' . $raw_filename;
                    $target_url = null; // Private: direct HTTP access is forbidden
                }

                try {
                    $options = new \Dompdf\Options();
                    $options->set('isRemoteEnabled', true);
                    $options->set('isHtml5ParserEnabled', true);
                    $options->set('defaultFont', 'Helvetica');
                    $options->set('tempDir', get_temp_dir());

                    $dompdf = new \Dompdf\Dompdf($options);
                    $dompdf->setPaper($format, $orientation);
                    $dompdf->loadHtml($full_html, 'UTF-8');
                    $dompdf->render();

                    if ($page_numbers) {
                        $canvas = $dompdf->getCanvas();
                        $canvas->page_text(
                            $canvas->get_width() - 90,
                            $canvas->get_height() - 35,
                            '{PAGE_NUM} / {PAGE_COUNT}',
                            null,
                            9,
                            [0.4, 0.4, 0.4]
                        );
                    }

                    $pdf_content = $dompdf->output();
                    if (empty($pdf_content)) {
                        return new \WP_Error('empty_pdf', __('PDF generation produced an empty file.', 'aiutoma-dev'));
                    }

                    $written = file_put_contents($target_path, $pdf_content);
                    if ($written === false) {
                        return new \WP_Error('write_failed', __('Failed to write PDF file to disk.', 'aiutoma-dev'));
                    }

                    $filesize = filesize($target_path);
                    $page_count = $dompdf->getCanvas()->get_page_count();

                    $media_id = null;
                    if ($to_media_library) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        require_once ABSPATH . 'wp-admin/includes/media.php';

                        $attachment_post = [
                            'guid'           => $target_url,
                            'post_mime_type' => 'application/pdf',
                            'post_title'     => preg_replace('/\.[^.]+$/', '', $raw_filename),
                            'post_content'   => '',
                            'post_status'    => 'inherit'
                        ];

                        $inserted_id = wp_insert_attachment($attachment_post, $target_path);
                        if (!is_wp_error($inserted_id) && $inserted_id > 0) {
                            $media_id = $inserted_id;
                            wp_update_attachment_metadata($media_id, wp_generate_attachment_metadata($media_id, $target_path));
                        }
                    }

                    return [
                        'success' => true,
                        'media_id' => $media_id,
                        'file_path' => $target_path,
                        'url' => $target_url,
                        'filename' => $raw_filename,
                        'size' => $filesize,
                        'size_formatted' => size_format($filesize),
                        'pages' => $page_count,
                        'in_media_library' => $to_media_library
                    ];
                } catch (\Throwable $e) {
                    return new \WP_Error('pdf_error', $e->getMessage());
                }
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'html' => [
                        'type' => 'string',
                        'description' => 'The HTML body or full document content to render into the PDF.'
                    ],
                    'css' => [
                        'type' => 'string',
                        'description' => 'Optional custom CSS stylesheet or styling rules to apply to the document.'
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Optional filename for the generated PDF (e.g. "report-march-2026.pdf"). If omitted, an auto-timestamped name is generated.'
                    ],
                    'add_to_media_library' => [
                        'type' => 'boolean',
                        'description' => 'Whether to save and publish the PDF in the standard WordPress Media Library (uploads/YYYY/MM/) with a public URL. Defaults to false (saving in a secure private directory protected from direct HTTP access).'
                    ],
                    'format' => [
                        'type' => 'string',
                        'description' => 'Page format (e.g. "A4", "Letter", "Legal", "A3", "A5"). Defaults to "A4".'
                    ],
                    'orientation' => [
                        'type' => 'string',
                        'description' => 'Page orientation: "portrait" (default) or "landscape".'
                    ],
                    'margin' => [
                        'type' => 'string',
                        'description' => 'Default page margin for all sides (e.g. "15mm", "20px", "1in"). Defaults to "15mm".'
                    ],
                    'margin_top' => [
                        'type' => 'string',
                        'description' => 'Optional specific top page margin (e.g. "20mm").'
                    ],
                    'margin_right' => [
                        'type' => 'string',
                        'description' => 'Optional specific right page margin (e.g. "15mm").'
                    ],
                    'margin_bottom' => [
                        'type' => 'string',
                        'description' => 'Optional specific bottom page margin (e.g. "20mm").'
                    ],
                    'margin_left' => [
                        'type' => 'string',
                        'description' => 'Optional specific left page margin (e.g. "15mm").'
                    ],
                    'page_numbers' => [
                        'type' => 'boolean',
                        'description' => 'Whether to automatically render running page numbers ("X / Y") at the bottom of each page. Defaults to false.'
                    ]
                ],
                'required' => ['html']
            ]
        ]);
    }

    private static function register_read_file() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/read-file', [
            'category' => 'aiutoma',
            'label' => __('Read File', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => false,
                'mcp' => ['public' => true]
            ],
            'description' => __('Read a file from the server.', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                $path = $input['path'];
                if (!file_exists($path)) {
                    return new \WP_Error('file_error', 'File not found: ' . $path);
                }
                $content = file_get_contents($path);
                if ($content === false) {
                    return new \WP_Error('file_error', 'Failed to read file: ' . $path);
                }
                return ['success' => true, 'content' => $content];
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Absolute file path']
                ],
                'required' => ['path']
            ]
        ]);
    }

    private static function register_list_directory() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/list-directory', [
            'category' => 'aiutoma',
            'label' => __('List Directory', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => false,
                'mcp' => ['public' => true]
            ],
            'description' => __('List files and folders in a directory.', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                $path = $input['path'];
                if (!is_dir($path)) {
                    return new \WP_Error('dir_error', 'Directory not found: ' . $path);
                }
                $files = scandir($path);
                if ($files === false) {
                    return new \WP_Error('dir_error', 'Failed to read directory: ' . $path);
                }
                return ['success' => true, 'files' => array_values(array_diff($files, ['.', '..']))];
            },
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Absolute directory path']
                ],
                'required' => ['path']
            ]
        ]);
    }

    private static function register_manage_plugins() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/manage-plugins', [
            'category' => 'aiutoma',
            'label' => __('Manage Plugins', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Manage WordPress plugins safely (list, install, activate, deactivate, delete).', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                if (!function_exists('get_plugins')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }

                $action = $input['action'];
                $slug = isset($input['slug']) ? sanitize_text_field($input['slug']) : '';

                if ($action === 'list') {
                    $all_plugins = get_plugins();
                    $active_plugins = get_option('active_plugins', []);
                    $data = [];
                    foreach ($all_plugins as $path => $info) {
                        $data[] = [
                            'path' => $path,
                            'name' => $info['Name'],
                            'version' => $info['Version'],
                            'status' => in_array($path, $active_plugins) ? 'active' : 'inactive'
                        ];
                    }
                    return ['success' => true, 'plugins' => $data];
                }

                if (empty($slug)) {
                    return new \WP_Error('missing_slug', 'Plugin slug/path is required for this action.');
                }

                $plugin_file = $slug;
                if (strpos($plugin_file, '.php') === false && $action !== 'install') {
                    $plugins = get_plugins();
                    foreach ($plugins as $path => $p) {
                        if (strpos($path, $slug . '/') === 0 || $path === $slug . '.php') {
                            $plugin_file = $path;
                            break;
                        }
                    }
                }

                if ($action === 'activate') {
                    $result = activate_plugin($plugin_file);
                    if (is_wp_error($result)) return $result;
                    return ['success' => true, 'message' => "Plugin $plugin_file activated."];
                } elseif ($action === 'deactivate') {
                    deactivate_plugins($plugin_file);
                    return ['success' => true, 'message' => "Plugin $plugin_file deactivated."];
                } elseif ($action === 'delete') {
                    deactivate_plugins($plugin_file);
                    $result = delete_plugins([$plugin_file]);
                    if (is_wp_error($result)) return $result;
                    return ['success' => true, 'message' => "Plugin $plugin_file deleted."];
                } elseif ($action === 'install' || $action === 'update' || $action === 'rollback') {
                    include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                    include_once ABSPATH . 'wp-admin/includes/file.php';
                    include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                    include_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';

                    if ($action === 'update' && empty($input['version'])) {
                        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
                        $result = $upgrader->upgrade($plugin_file);
                        if (is_wp_error($result) || $result === false) {
                            return new \WP_Error('update_failed', 'Failed to update plugin.');
                        }
                        return ['success' => true, 'message' => "Plugin $plugin_file updated successfully."];
                    }

                    $api = plugins_api('plugin_information', ['slug' => $slug]);
                    if (is_wp_error($api)) return $api;

                    $download_link = $api->download_link;
                    $version = $input['version'] ?? '';

                    if ($action === 'rollback' || (!empty($version) && $action === 'update')) {
                        if (empty($version)) return new \WP_Error('missing_version', 'Version is required for rollback.');
                        if (!isset($api->versions) || !isset($api->versions[$version])) {
                            return new \WP_Error('invalid_version', "Version $version not found in WordPress repository for $slug.");
                        }
                        $download_link = $api->versions[$version];
                    }

                    $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
                    $install_args = [];
                    if ($action === 'rollback' || $action === 'update') {
                        $install_args['clear_destination'] = true;
                    }

                    $result = $upgrader->install($download_link, $install_args);

                    if (is_wp_error($result) || $result === false) {
                        return new \WP_Error('action_failed', "Failed to $action plugin.");
                    }
                    return ['success' => true, 'message' => "Plugin $slug successfully processed ($action" . (!empty($version) ? " to version $version" : "") . ")."];
                }

                return new \WP_Error('invalid_action', 'Unsupported action.');
            },
            'permission_callback' => function () {
                return current_user_can('activate_plugins');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'install', 'activate', 'deactivate', 'delete', 'update', 'rollback'], 'description' => 'Action to perform'],
                    'slug' => ['type' => 'string', 'description' => 'Plugin directory slug (e.g., "woocommerce") or full path (e.g., "woocommerce/woocommerce.php"). Not needed for list action.'],
                    'version' => ['type' => 'string', 'description' => 'Specific version to rollback/update to.']
                ],
                'required' => ['action']
            ]
        ]);
    }

    private static function register_manage_themes() {
        \Aiutoma\Modules\Ai\Abilities::register('aiutoma/manage-themes', [
            'category' => 'aiutoma',
            'label' => __('Manage Themes', 'aiutoma-dev'),
            'meta' => [
                'requires_confirmation' => true,
                'mcp' => ['public' => true]
            ],
            'description' => __('Manage WordPress themes safely (list, activate).', 'aiutoma-dev'),
            'execute_callback' => function ($input) {
                $action = $input['action'];
                $slug = isset($input['slug']) ? sanitize_text_field($input['slug']) : '';

                if ($action === 'list') {
                    $themes = wp_get_themes();
                    $active = wp_get_theme()->get_stylesheet();
                    $data = [];
                    foreach ($themes as $stylesheet => $theme) {
                        $data[] = [
                            'slug' => $stylesheet,
                            'name' => $theme->get('Name'),
                            'version' => $theme->get('Version'),
                            'status' => ($stylesheet === $active) ? 'active' : 'inactive'
                        ];
                    }
                    return ['success' => true, 'themes' => $data];
                } elseif ($action === 'activate') {
                    if (empty($slug)) return new \WP_Error('missing_slug', 'Theme slug is required.');
                    switch_theme($slug);
                    return ['success' => true, 'message' => "Theme $slug activated."];
                }
                return new \WP_Error('invalid_action', 'Unsupported action.');
            },
            'permission_callback' => function () {
                return current_user_can('switch_themes');
            },
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'activate'], 'description' => 'Action to perform'],
                    'slug' => ['type' => 'string', 'description' => 'Theme slug. Not needed for list action.']
                ],
                'required' => ['action']
            ]
        ]);
    }

    public static function handle_rollback($handled, $data, $backup_file) {
        if (!is_array($data) || empty($data['action'])) {
            return $handled;
        }

        if ($data['action'] === 'db-query') {
            global $wpdb;
            $table = $data['table'] ?? '';
            if (!empty($data['rows']) && is_array($data['rows'])) {
                if (($data['type'] ?? '') === 'UPDATE') {
                    foreach ($data['rows'] as $row) {
                        $common_pks = ['ID', 'id', 'post_id', 'meta_id', 'umeta_id', 'term_id', 'option_id', 'comment_ID'];
                        $pk = array_key_first($row);
                        foreach ($common_pks as $p) {
                            if (isset($row[$p])) {
                                $pk = $p;
                                break;
                            }
                        }
                        if ($pk) {
                            $wpdb->update($table, $row, [$pk => $row[$pk]]);
                        }
                    }
                } elseif (($data['type'] ?? '') === 'DELETE') {
                    foreach ($data['rows'] as $row) {
                        $wpdb->insert($table, $row);
                    }
                }
            }
            return true;
        }

        if ($data['action'] === 'execute-php-rollback' || $data['action'] === 'global-rollback' || $data['action'] === 'cron-rollback') {
            if (!empty($data['options'])) {
                foreach ($data['options'] as $opt => $val) {
                    if ($val === false) delete_option($opt);
                    else update_option($opt, $val);
                }
            }
            if (!empty($data['posts'])) {
                foreach ($data['posts'] as $post_id => $post_data) {
                    if (is_array($post_data)) {
                        wp_update_post($post_data);
                    } elseif (is_object($post_data)) {
                        wp_update_post(get_object_vars($post_data));
                    }
                }
            }
            if (!empty($data['db_changes'])) {
                global $wpdb;
                foreach ($data['db_changes'] as $change) {
                    $table = $change['table'];
                    $type = $change['type'];
                    if ($type === 'UPDATE') {
                        foreach ($change['rows'] as $row) {
                            $common_pks = ['ID', 'id', 'post_id', 'meta_id', 'umeta_id', 'term_id', 'option_id', 'comment_ID'];
                            $pk = array_key_first($row);
                            foreach ($common_pks as $p) {
                                if (isset($row[$p])) {
                                    $pk = $p;
                                    break;
                                }
                            }
                            if ($pk) {
                                $wpdb->update($table, $row, [$pk => $row[$pk]]);
                            }
                        }
                    } elseif ($type === 'DELETE') {
                        foreach ($change['rows'] as $row) {
                            $wpdb->insert($table, $row);
                        }
                    }
                }
            }
            return true;
        }

        return $handled;
    }

    public static function display_backup_item($item, $data, $filename) {
        if (!is_array($data) || empty($data['action'])) {
            return $item;
        }

        $desc = '';
        $extra_html = '';

        if ($data['action'] === 'modify-file') {
            $content_base = defined('WP_CONTENT_DIR') ? constant('WP_CONTENT_DIR') : dirname(wp_upload_dir()['basedir']);
            $rel_path = str_replace(wp_normalize_path($content_base), '', wp_normalize_path($data['original_path'] ?? ''));
            $desc = 'Modified file: ' . ltrim($rel_path, '/');
        } elseif ($data['action'] === 'db-query') {
            $desc = 'DB ' . ($data['type'] ?? '') . ' on table: ' . ($data['table'] ?? '');
            if (!empty($data['query'])) {
                $extra_html .= '<li><strong>Query:</strong> <code>' . esc_html(strlen($data['query']) > 100 ? substr($data['query'], 0, 100) . '...' : $data['query']) . '</code></li>';
            }
        } elseif ($data['action'] === 'execute-php-rollback') {
            $details = [];
            if (!empty($data['files'])) {
                $details[] = count($data['files']) . ' files';
                $file_links = [];
                foreach ($data['files'] as $i => $f) {
                    $base = basename($f['path']);
                    if (!empty($f['is_new'])) {
                        $file_links[] = esc_html($base) . ' (New)';
                    } else {
                        $dl_url = rest_url('aiutoma/v1/download-ai-backup?id=' . $filename . '&type=file&index=' . $i . '&_wpnonce=' . wp_create_nonce('wp_rest'));
                        $file_links[] = esc_html($base) . ' <a href="' . esc_url($dl_url) . '" target="_blank" title="Download Original">(Download)</a>';
                    }
                }
                $extra_html .= '<li><strong>Files:</strong> ' . implode(', ', $file_links) . '</li>';
            }
            if (!empty($data['db_changes'])) {
                $details[] = count($data['db_changes']) . ' DB changes';
                $tables = array_unique(array_column($data['db_changes'], 'table'));
                $dl_url = rest_url('aiutoma/v1/download-ai-backup?id=' . $filename . '&type=sql&_wpnonce=' . wp_create_nonce('wp_rest'));
                $extra_html .= '<li><strong>Tables:</strong> ' . esc_html(implode(', ', $tables)) . ' <a href="' . esc_url($dl_url) . '" target="_blank" title="Download SQL Dump">(Download SQL)</a></li>';
            }
            if (!empty($data['options'])) {
                $details[] = count($data['options']) . ' options';
                $extra_html .= '<li><strong>Options:</strong> ' . esc_html(implode(', ', array_keys($data['options']))) . '</li>';
            }
            if (!empty($data['posts'])) {
                $details[] = count($data['posts']) . ' posts';
                $extra_html .= '<li><strong>Posts:</strong> ' . esc_html(implode(', ', array_keys($data['posts']))) . '</li>';
            }
            $desc = 'AI Action Rollback' . (!empty($details) ? ' (' . implode(', ', $details) . ')' : '');
        } elseif ($data['action'] === 'plugin-backup') {
            $desc = 'Plugin Backup: ' . esc_html($data['slug'] ?? '');
            if (!empty($data['zip_path'])) {
                $extra_html .= '<li><strong>File:</strong> ' . esc_html(basename($data['zip_path'])) . '</li>';
            }
        }

        if ($desc) {
            return [
                'desc' => $desc,
                'extra_html' => $extra_html
            ];
        }

        return $item;
    }

    public static function register_rest_routes() {
        register_rest_route('aiutoma/v1', '/download-ai-backup', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'download_ai_backup'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ]);
    }

    public static function download_ai_backup($request) {
        $backup_id = $request->get_param('id');
        $type = $request->get_param('type'); // 'file' or 'sql'
        $index = (int)$request->get_param('index');

        if (!$backup_id || !$type) {
            return new \WP_Error('invalid_params', 'Missing required parameters.', ['status' => 400]);
        }

        $upload_dir = wp_upload_dir();
        $backup_dir = class_exists('\Aiutoma\Modules\Ai\Ai') ? \Aiutoma\Modules\Ai\Ai::get_storage_dir() . '/backup' : $upload_dir['basedir'] . '/aiutoma/backup';
        $json_file = $backup_dir . '/' . basename($backup_id);

        if (!file_exists($json_file)) {
            return new \WP_Error('not_found', 'Backup not found.', ['status' => 404]);
        }

        $data = json_decode(file_get_contents($json_file), true);
        if (!$data) {
            return new \WP_Error('invalid_backup', 'Invalid backup file.', ['status' => 500]);
        }

        if ($type === 'file') {
            if (!isset($data['files'][$index])) {
                return new \WP_Error('not_found', 'File backup not found.', ['status' => 404]);
            }
            $file_info = $data['files'][$index];
            if (!empty($file_info['is_new'])) {
                return new \WP_Error('not_found', 'This file was created by AI, no previous version exists.', ['status' => 404]);
            }
            $physical = $backup_dir . '/' . basename($file_info['physical_backup']);
            if (!file_exists($physical)) {
                return new \WP_Error('not_found', 'Physical backup file not found.', ['status' => 404]);
            }

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($file_info['path']) . '"');
            header('Content-Length: ' . filesize($physical));
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
            readfile($physical);
            exit;
        } elseif ($type === 'sql') {
            if (!isset($data['db_changes'])) {
                return new \WP_Error('not_found', 'No DB changes in this backup.', ['status' => 404]);
            }

            $sql_dump = "-- AI Action Rollback SQL Dump\n";
            $sql_dump .= "-- Original Backup ID: " . $backup_id . "\n\n";

            foreach ($data['db_changes'] as $change) {
                if ($change['type'] === 'UPDATE' || $change['type'] === 'DELETE' || $change['type'] === 'INSERT') {
                    $table = $change['table'];
                    $sql_dump .= "-- Restore original rows for table: {$table}\n";
                    if (!empty($change['rows'])) {
                        foreach ($change['rows'] as $row) {
                            $cols = array_keys($row);
                            $vals = array_map(function ($v) {
                                if ($v === null) return 'NULL';
                                return "'" . esc_sql($v) . "'";
                            }, array_values($row));
                            $sql_dump .= "REPLACE INTO `{$table}` (`" . implode("`, `", $cols) . "`) VALUES (" . implode(", ", $vals) . ");\n";
                        }
                    }
                    $sql_dump .= "\n";
                }
            }

            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="rollback_' . $backup_id . '.sql"');
            echo $sql_dump;
            exit;
        }

        return new \WP_Error('invalid_type', 'Invalid download type.', ['status' => 400]);
    }
}

add_filter('aiutoma_handle_custom_rollback', ['\\AiutomaDev\\Includes\\Developer_Abilities', 'handle_rollback'], 10, 3);
add_filter('aiutoma_backup_item_display', ['\\AiutomaDev\\Includes\\Developer_Abilities', 'display_backup_item'], 10, 3);
add_action('rest_api_init', ['\\AiutomaDev\\Includes\\Developer_Abilities', 'register_rest_routes']);


