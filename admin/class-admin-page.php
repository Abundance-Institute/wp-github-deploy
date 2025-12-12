<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPGD_Admin_Page {

    private $settings;
    private $api;
    private $deploy_manager;
    private $debounce;
    private const MENU_SLUG = 'wp-github-deploy';

    public function __construct( 
        WPGD_Settings $settings, 
        WPGD_GitHub_API $api, 
        WPGD_Deploy_Manager $deploy_manager,
        WPGD_Debounce_Handler $debounce
    ) {
        $this->settings       = $settings;
        $this->api            = $api;
        $this->deploy_manager = $deploy_manager;
        $this->debounce       = $debounce;

        $this->register_hooks();
    }

    private function register_hooks(): void {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_wpgd_deploy_now', [ $this, 'ajax_deploy_now' ] );
        add_action( 'wp_ajax_wpgd_cancel_deploy', [ $this, 'ajax_cancel_deploy' ] );
        add_action( 'wp_ajax_wpgd_validate_connection', [ $this, 'ajax_validate_connection' ] );
        add_action( 'wp_ajax_wpgd_get_status', [ $this, 'ajax_get_status' ] );
        add_action( 'wp_ajax_wpgd_clear_history', [ $this, 'ajax_clear_history' ] );
        add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );
        add_filter( 'plugin_action_links_' . WPGD_PLUGIN_BASENAME, [ $this, 'add_settings_link' ] );
    }

    public function add_menu_page(): void {
        add_options_page(
            __( 'GitHub Deploy', 'wp-github-deploy' ),
            __( 'GitHub Deploy', 'wp-github-deploy' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'settings_page_' . self::MENU_SLUG ) {
            return;
        }

        wp_enqueue_style(
            'wpgd-admin',
            WPGD_PLUGIN_URL . 'admin/assets/admin.css',
            [],
            WPGD_VERSION
        );

        wp_enqueue_script(
            'wpgd-admin',
            WPGD_PLUGIN_URL . 'admin/assets/admin.js',
            [ 'jquery' ],
            WPGD_VERSION,
            true
        );

        wp_localize_script( 'wpgd-admin', 'wpgdAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wpgd_admin' ),
            'strings' => [
                'deploying'       => __( 'Deploying...', 'wp-github-deploy' ),
                'deploySuccess'   => __( 'Deploy triggered successfully!', 'wp-github-deploy' ),
                'deployError'     => __( 'Deploy failed:', 'wp-github-deploy' ),
                'cancelling'      => __( 'Cancelling...', 'wp-github-deploy' ),
                'cancelled'       => __( 'Pending deploy cancelled.', 'wp-github-deploy' ),
                'validating'      => __( 'Validating...', 'wp-github-deploy' ),
                'connected'       => __( 'Connected!', 'wp-github-deploy' ),
                'confirmClear'    => __( 'Are you sure you want to clear all deploy history?', 'wp-github-deploy' ),
                'confirmDeploy'   => __( 'Are you sure you want to trigger a deploy now?', 'wp-github-deploy' ),
            ],
        ] );
    }

    public function register_settings(): void {
        if ( isset( $_POST['wpgd_save_settings'] ) && check_admin_referer( 'wpgd_settings' ) ) {
            $this->save_settings();
        }
    }

    private function save_settings(): void {
        if ( isset( $_POST['wpgd_github_token'] ) && ! empty( $_POST['wpgd_github_token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_POST['wpgd_github_token'] ) );
            if ( strpos( $token, '••••' ) === false ) {
                $this->settings->save_token( $token );
            }
        }

        $this->settings->set( 'github_owner', sanitize_text_field( wp_unslash( $_POST['wpgd_github_owner'] ?? '' ) ) );
        $this->settings->set( 'github_repo', sanitize_text_field( wp_unslash( $_POST['wpgd_github_repo'] ?? '' ) ) );
        $this->settings->set( 'github_workflow', sanitize_text_field( wp_unslash( $_POST['wpgd_github_workflow'] ?? 'deploy.yml' ) ) );
        $this->settings->set( 'github_branch', sanitize_text_field( wp_unslash( $_POST['wpgd_github_branch'] ?? 'main' ) ) );

        $this->settings->set( 'auto_deploy', isset( $_POST['wpgd_auto_deploy'] ) );
        $this->settings->set( 'debounce_minutes', absint( $_POST['wpgd_debounce_minutes'] ?? 5 ) );

        $post_types = isset( $_POST['wpgd_post_types'] ) 
            ? array_map( 'sanitize_key', (array) $_POST['wpgd_post_types'] )
            : [];
        $this->settings->set( 'post_types', $post_types );

        $this->settings->set( 'deploy_taxonomies', isset( $_POST['wpgd_deploy_taxonomies'] ) );
        $this->settings->set( 'deploy_menus', isset( $_POST['wpgd_deploy_menus'] ) );
        $this->settings->set( 'deploy_options', isset( $_POST['wpgd_deploy_options'] ) );
        $this->settings->set( 'deploy_updates', isset( $_POST['wpgd_deploy_updates'] ) );

        $this->settings->set( 'acf_batch', isset( $_POST['wpgd_acf_batch'] ) );
        $this->settings->set( 'acf_batch_delay', absint( $_POST['wpgd_acf_batch_delay'] ?? 10 ) );

        add_settings_error( 'wpgd_settings', 'settings_saved', __( 'Settings saved successfully.', 'wp-github-deploy' ), 'success' );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        include WPGD_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public function ajax_deploy_now(): void {
        check_ajax_referer( 'wpgd_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp-github-deploy' ) ] );
        }

        $result = $this->deploy_manager->deploy_now( 'manual', [
            'user_id' => get_current_user_id(),
        ] );

        if ( $result ) {
            wp_send_json_success( [ 'message' => __( 'Deploy triggered successfully!', 'wp-github-deploy' ) ] );
        } else {
            $history = $this->settings->get_history( 1 );
            $message = ! empty( $history[0]['message'] ) ? $history[0]['message'] : __( 'Deploy failed.', 'wp-github-deploy' );
            wp_send_json_error( [ 'message' => $message ] );
        }
    }

    public function ajax_cancel_deploy(): void {
        check_ajax_referer( 'wpgd_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp-github-deploy' ) ] );
        }

        $result = $this->deploy_manager->cancel_pending_deploy();

        if ( $result ) {
            wp_send_json_success( [ 'message' => __( 'Pending deploy cancelled.', 'wp-github-deploy' ) ] );
        } else {
            wp_send_json_error( [ 'message' => __( 'No pending deploy to cancel.', 'wp-github-deploy' ) ] );
        }
    }

    public function ajax_validate_connection(): void {
        check_ajax_referer( 'wpgd_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp-github-deploy' ) ] );
        }

        $result = $this->deploy_manager->validate_configuration();

        if ( $result['valid'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_get_status(): void {
        check_ajax_referer( 'wpgd_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp-github-deploy' ) ] );
        }

        $pending = $this->debounce->get_pending_info();
        $latest_run = $this->api->get_latest_run_status();

        wp_send_json_success( [
            'pending'        => $pending,
            'time_remaining' => $this->debounce->get_time_remaining(),
            'pending_count'  => $this->debounce->get_pending_count(),
            'latest_run'     => $latest_run['data'] ?? null,
            'is_configured'  => $this->settings->is_configured(),
        ] );
    }

    public function ajax_clear_history(): void {
        check_ajax_referer( 'wpgd_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wp-github-deploy' ) ] );
        }

        $this->settings->clear_history();
        wp_send_json_success( [ 'message' => __( 'History cleared.', 'wp-github-deploy' ) ] );
    }

    public function show_admin_notices(): void {
        settings_errors( 'wpgd_settings' );

        $screen = get_current_screen();
        if ( $screen && $screen->id !== 'settings_page_' . self::MENU_SLUG ) {
            if ( ! $this->settings->is_configured() ) {
                echo '<div class="notice notice-warning"><p>';
                printf(
                    /* translators: %s: Settings page URL */
                    __( 'WP GitHub Deploy is not configured. <a href="%s">Configure now</a>', 'wp-github-deploy' ),
                    esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) )
                );
                echo '</p></div>';
            }
        }
    }

    public function add_settings_link( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'options-general.php?page=' . self::MENU_SLUG ),
            __( 'Settings', 'wp-github-deploy' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function get_masked_token(): string {
        $token = $this->settings->get_token();
        if ( empty( $token ) ) {
            return '';
        }
        $length = strlen( $token );
        if ( $length <= 8 ) {
            return str_repeat( '•', $length );
        }
        return substr( $token, 0, 4 ) . str_repeat( '•', $length - 8 ) . substr( $token, -4 );
    }

    public function format_date( $timestamp ): string {
        if ( is_string( $timestamp ) && strtotime( $timestamp ) ) {
            $timestamp = strtotime( $timestamp );
        }

        $diff = time() - $timestamp;

        if ( $diff < 60 ) {
            return __( 'Just now', 'wp-github-deploy' );
        }
        
        if ( $diff < HOUR_IN_SECONDS ) {
            $minutes = floor( $diff / MINUTE_IN_SECONDS );
            return sprintf( _n( '%d minute ago', '%d minutes ago', $minutes, 'wp-github-deploy' ), $minutes );
        }

        if ( $diff < DAY_IN_SECONDS ) {
            $hours = floor( $diff / HOUR_IN_SECONDS );
            return sprintf( _n( '%d hour ago', '%d hours ago', $hours, 'wp-github-deploy' ), $hours );
        }

        return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
    }

    public function get_source_label( string $source ): string {
        $labels = [
            'manual'                 => __( 'Manual deploy', 'wp-github-deploy' ),
            'post_updated'           => __( 'Post updated', 'wp-github-deploy' ),
            'post_deleted'           => __( 'Post deleted', 'wp-github-deploy' ),
            'term_created'           => __( 'Term created', 'wp-github-deploy' ),
            'term_updated'           => __( 'Term updated', 'wp-github-deploy' ),
            'term_deleted'           => __( 'Term deleted', 'wp-github-deploy' ),
            'menu_updated'           => __( 'Menu updated', 'wp-github-deploy' ),
            'menu_deleted'           => __( 'Menu deleted', 'wp-github-deploy' ),
            'acf_options_updated'    => __( 'ACF Options saved', 'wp-github-deploy' ),
            'acf_field_group_update' => __( 'ACF Field Group update', 'wp-github-deploy' ),
            'wp_update'              => __( 'WordPress update', 'wp-github-deploy' ),
        ];

        if ( strpos( $source, 'batched' ) !== false ) {
            return __( 'Batched changes', 'wp-github-deploy' );
        }

        return $labels[ $source ] ?? $source;
    }
}

