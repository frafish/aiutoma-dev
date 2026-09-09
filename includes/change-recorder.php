<?php
namespace AiutomaDev\Includes;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key

class Change_Recorder {

    private $post_before = [];
    private $meta_before = [];
    private $option_before = [];

    private $changes = [];
    private $is_recording = false;

    public function start_recording() {
        $this->is_recording = true;
        $this->changes = [];

        add_action('pre_post_update', [$this, 'on_pre_post_update'], 10, 2);
        add_action('save_post', [$this, 'on_save_post'], 10, 3);
        add_action('before_delete_post', [$this, 'on_before_delete_post'], 10, 1);
        add_action('deleted_post', [$this, 'on_deleted_post'], 10, 1);

        add_action('update_post_meta', [$this, 'on_update_post_meta'], 10, 4);
        add_action('updated_post_meta', [$this, 'on_updated_post_meta'], 10, 4);
        add_action('added_post_meta', [$this, 'on_added_post_meta'], 10, 4);
        add_action('deleted_post_meta', [$this, 'on_deleted_post_meta'], 10, 4);

        add_action('updated_option', [$this, 'on_updated_option'], 10, 3);
        add_action('added_option', [$this, 'on_added_option'], 10, 2);
        add_action('deleted_option', [$this, 'on_deleted_option'], 10, 1);
    }

    public function stop_recording() {
        $this->is_recording = false;

        remove_action('pre_post_update', [$this, 'on_pre_post_update']);
        remove_action('save_post', [$this, 'on_save_post']);
        remove_action('before_delete_post', [$this, 'on_before_delete_post']);
        remove_action('deleted_post', [$this, 'on_deleted_post']);

        remove_action('update_post_meta', [$this, 'on_update_post_meta']);
        remove_action('updated_post_meta', [$this, 'on_updated_post_meta']);
        remove_action('added_post_meta', [$this, 'on_added_post_meta']);
        remove_action('deleted_post_meta', [$this, 'on_deleted_post_meta']);

        remove_action('updated_option', [$this, 'on_updated_option']);
        remove_action('added_option', [$this, 'on_added_option']);
        remove_action('deleted_option', [$this, 'on_deleted_option']);

        return $this->changes;
    }

    public function get_changes() {
        return $this->changes;
    }

    private function snapshot_post($post_id) {
        $post = get_post($post_id, ARRAY_A);
        if (!$post) return null;
        return [
            'post_title' => $post['post_title'],
            'post_content' => $post['post_content'],
            'post_excerpt' => $post['post_excerpt'],
            'post_status' => $post['post_status'],
            'post_type' => $post['post_type'],
        ];
    }

    public function on_pre_post_update($post_id, $data) {
        if (!$this->is_recording) return;
        $this->post_before[$post_id] = $this->snapshot_post($post_id);
    }

    public function on_save_post($post_id, $post, $update) {
        if (!$this->is_recording || wp_is_post_revision($post_id)) return;
        
        $after = $this->snapshot_post($post_id);
        $before = $this->post_before[$post_id] ?? null;
        
        $this->changes[] = [
            'type' => 'post',
            'action' => $update ? 'update' : 'create',
            'id' => $post_id,
            'before' => $before,
            'after' => $after
        ];
        unset($this->post_before[$post_id]);
    }

    public function on_before_delete_post($post_id) {
        if (!$this->is_recording) return;
        $this->post_before[$post_id] = $this->snapshot_post($post_id);
    }

    public function on_deleted_post($post_id) {
        if (!$this->is_recording) return;
        $before = $this->post_before[$post_id] ?? null;
        $this->changes[] = [
            'type' => 'post',
            'action' => 'delete',
            'id' => $post_id,
            'before' => $before,
            'after' => null
        ];
        unset($this->post_before[$post_id]);
    }

    public function on_update_post_meta($meta_id, $post_id, $meta_key, $meta_value) {
        if (!$this->is_recording) return;
        $this->meta_before[$meta_id] = get_post_meta($post_id, $meta_key, true);
    }

    public function on_updated_post_meta($meta_id, $post_id, $meta_key, $meta_value) {
        if (!$this->is_recording) return;
        $before = $this->meta_before[$meta_id] ?? null;
        $this->changes[] = [
            'type' => 'post_meta',
            'action' => 'update',
            'post_id' => $post_id,
            'meta_key' => $meta_key,
            'before' => $before,
            'after' => $meta_value
        ];
        unset($this->meta_before[$meta_id]);
    }

    public function on_added_post_meta($meta_id, $post_id, $meta_key, $meta_value) {
        if (!$this->is_recording) return;
        $this->changes[] = [
            'type' => 'post_meta',
            'action' => 'create',
            'post_id' => $post_id,
            'meta_key' => $meta_key,
            'after' => $meta_value
        ];
    }

    public function on_deleted_post_meta($meta_ids, $post_id, $meta_key, $meta_value) {
        if (!$this->is_recording) return;
        $this->changes[] = [
            'type' => 'post_meta',
            'action' => 'delete',
            'post_id' => $post_id,
            'meta_key' => $meta_key,
            'before' => get_post_meta($post_id, $meta_key, true)
        ];
    }

    public function on_updated_option($option, $old_value, $value) {
        if (!$this->is_recording) return;
        $this->changes[] = [
            'type' => 'option',
            'action' => 'update',
            'option_name' => $option,
            'before' => $old_value,
            'after' => $value
        ];
    }

    public function on_added_option($option, $value) {
        if (!$this->is_recording) return;
        $this->changes[] = [
            'type' => 'option',
            'action' => 'create',
            'option_name' => $option,
            'after' => $value
        ];
    }

    public function on_deleted_option($option) {
        if (!$this->is_recording) return;
        $this->changes[] = [
            'type' => 'option',
            'action' => 'delete',
            'option_name' => $option,
            'before' => get_option($option)
        ];
    }
}

// Backward compatibility alias if needed
if (!class_exists('\Aiutoma\Modules\Ai\Classes\Change_Recorder')) {
    class_alias(Change_Recorder::class, '\Aiutoma\Modules\Ai\Classes\Change_Recorder');
}
