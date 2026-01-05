<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPGD_Hooks_Manager {

    private $deploy_manager;
    private $settings;

    public function __construct( WPGD_Deploy_Manager $deploy_manager, WPGD_Settings $settings ) {
        $this->deploy_manager = $deploy_manager;
        $this->settings       = $settings;

        $this->register_hooks();
    }

    private function register_hooks(): void {
        add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );
        add_action( 'delete_post', [ $this, 'on_post_delete' ], 10, 2 );
        add_action( 'created_term', [ $this, 'on_term_created' ], 10, 3 );
        add_action( 'edited_term', [ $this, 'on_term_edited' ], 10, 3 );
        add_action( 'delete_term', [ $this, 'on_term_deleted' ], 10, 4 );
        add_action( 'wp_update_nav_menu', [ $this, 'on_menu_updated' ], 10, 2 );
        add_action( 'wp_delete_nav_menu', [ $this, 'on_menu_deleted' ], 10, 1 );
        add_action( 'acf/save_post', [ $this, 'on_acf_save' ], 20, 1 );
        add_action( 'acf/update_field_group', [ $this, 'on_acf_field_group_update' ], 10, 1 );
        add_action( 'acf/options_page/save', [ $this, 'on_acf_options_save' ], 10, 2 );
        add_action( 'upgrader_process_complete', [ $this, 'on_wp_update' ], 10, 2 );

        // Scheduled posts: fires for ANY post type when future → publish
        add_action( 'future_to_publish', [ $this, 'on_scheduled_post_published' ], 10, 1 );
    }

    public function on_post_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }

        if ( ! $this->deploy_manager->should_deploy_for_post_type( $post->post_type ) ) {
            return;
        }

        // Skip scheduled post → publish (handled by future_to_publish hook)
        if ( $old_status === 'future' && $new_status === 'publish' ) {
            return;
        }

        $trigger_statuses = [ 'publish', 'private' ];
        $should_trigger = false;

        if ( in_array( $new_status, $trigger_statuses, true ) ) {
            $should_trigger = true;
        }

        if ( in_array( $old_status, $trigger_statuses, true ) && ! in_array( $new_status, $trigger_statuses, true ) ) {
            $should_trigger = true;
        }

        if ( ! $should_trigger ) {
            return;
        }

        $this->queue_deploy( 'post_updated', [
            'post_id'    => $post->ID,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'old_status' => $old_status,
            'new_status' => $new_status,
        ] );
    }

    /**
     * Handle scheduled posts being published.
     * This fires via the future_to_publish hook when ANY post type transitions
     * from 'future' (scheduled) to 'publish' status - works automatically for
     * all post types including custom ones.
     *
     * @param \WP_Post $post Post object.
     */
    public function on_scheduled_post_published( \WP_Post $post ): void {
        if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
            return;
        }

        if ( ! $this->deploy_manager->should_deploy_for_post_type( $post->post_type ) ) {
            return;
        }

        $this->queue_deploy( 'scheduled_post_published', [
            'post_id'    => $post->ID,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
            'scheduled'  => true,
        ] );
    }

    public function on_post_delete( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $this->deploy_manager->should_deploy_for_post_type( $post->post_type ) ) {
            return;
        }

        if ( $post->post_status !== 'publish' && $post->post_status !== 'private' ) {
            return;
        }

        $this->queue_deploy( 'post_deleted', [
            'post_id'    => $post_id,
            'post_type'  => $post->post_type,
            'post_title' => $post->post_title,
        ] );
    }

    public function on_term_created( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( ! $this->deploy_manager->should_deploy_for_taxonomy() ) {
            return;
        }

        if ( in_array( $taxonomy, [ 'nav_menu', 'link_category', 'post_format' ], true ) ) {
            return;
        }

        $term = get_term( $term_id, $taxonomy );

        $this->queue_deploy( 'term_created', [
            'term_id'   => $term_id,
            'taxonomy'  => $taxonomy,
            'term_name' => $term ? $term->name : '',
        ] );
    }

    public function on_term_edited( int $term_id, int $tt_id, string $taxonomy ): void {
        if ( ! $this->deploy_manager->should_deploy_for_taxonomy() ) {
            return;
        }

        if ( in_array( $taxonomy, [ 'nav_menu', 'link_category', 'post_format' ], true ) ) {
            return;
        }

        $term = get_term( $term_id, $taxonomy );

        $this->queue_deploy( 'term_updated', [
            'term_id'   => $term_id,
            'taxonomy'  => $taxonomy,
            'term_name' => $term ? $term->name : '',
        ] );
    }

    public function on_term_deleted( int $term_id, int $tt_id, string $taxonomy, $deleted_term ): void {
        if ( ! $this->deploy_manager->should_deploy_for_taxonomy() ) {
            return;
        }

        if ( in_array( $taxonomy, [ 'nav_menu', 'link_category', 'post_format' ], true ) ) {
            return;
        }

        $this->queue_deploy( 'term_deleted', [
            'term_id'   => $term_id,
            'taxonomy'  => $taxonomy,
            'term_name' => $deleted_term ? $deleted_term->name : '',
        ] );
    }

    public function on_menu_updated( int $menu_id, $menu_data = [] ): void {
        if ( ! $this->deploy_manager->should_deploy_for_menu() ) {
            return;
        }

        $menu = wp_get_nav_menu_object( $menu_id );

        $this->queue_deploy( 'menu_updated', [
            'menu_id'   => $menu_id,
            'menu_name' => $menu ? $menu->name : '',
        ] );
    }

    public function on_menu_deleted( int $menu_id ): void {
        if ( ! $this->deploy_manager->should_deploy_for_menu() ) {
            return;
        }

        $this->queue_deploy( 'menu_deleted', [
            'menu_id' => $menu_id,
        ] );
    }

    public function on_acf_save( $post_id ): void {
        if ( $this->deploy_manager->is_acf_batch_mode() ) {
            return;
        }

        if ( is_string( $post_id ) && strpos( $post_id, 'options' ) !== false ) {
            $this->on_acf_options_save( $post_id, [] );
            return;
        }
    }

    public function on_acf_field_group_update( array $field_group ): void {
        $this->deploy_manager->enable_acf_batch_mode();
        $this->deploy_manager->schedule_acf_batch_deploy();
    }

    public function on_acf_options_save( $post_id, $values ): void {
        if ( ! $this->deploy_manager->should_deploy_for_options() ) {
            return;
        }

        $this->queue_deploy( 'acf_options_updated', [
            'options_page' => $post_id,
        ] );
    }

    public function on_wp_update( $upgrader, array $options ): void {
        if ( ! $this->settings->get( 'deploy_updates', false ) ) {
            return;
        }

        $type = $options['type'] ?? '';
        $action = $options['action'] ?? '';

        if ( $action !== 'update' ) {
            return;
        }

        $this->queue_deploy( 'wp_update', [
            'update_type' => $type,
        ] );
    }

    private function queue_deploy( string $source, array $context = [] ): void {
        if ( ! apply_filters( 'wpgd_should_deploy', true, $source, $context ) ) {
            return;
        }

        $this->deploy_manager->queue_deploy( $source, $context );
    }
}

