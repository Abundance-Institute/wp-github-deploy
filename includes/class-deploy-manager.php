<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPGD_Deploy_Manager {

    private $api;
    private $settings;
    private $debounce;

    public function __construct( WPGD_GitHub_API $api, WPGD_Settings $settings, WPGD_Debounce_Handler $debounce ) {
        $this->api      = $api;
        $this->settings = $settings;
        $this->debounce = $debounce;
    }

    public function queue_deploy( string $trigger_source, array $context = [] ): bool {
        if ( ! $this->settings->get( 'auto_deploy', true ) ) {
            return false;
        }

        if ( $this->is_acf_batch_mode() && $trigger_source === 'post_updated' ) {
            return false;
        }

        $debounce_minutes = (int) $this->settings->get( 'debounce_minutes', 5 );

        if ( $debounce_minutes > 0 ) {
            $this->debounce->schedule( $trigger_source, $context );
            return true;
        }

        return $this->deploy_now( $trigger_source, $context );
    }

    public function deploy_now( string $reason = 'manual', array $context = [] ): bool {
        $this->api->clear_status_cache();

        $inputs = [
            'trigger_source' => $reason,
            'triggered_at'   => current_time( 'c' ),
        ];

        $result = $this->api->trigger_workflow( $inputs );

        $this->settings->add_to_history( [
            'source'  => $reason,
            'context' => $context,
            'success' => $result['success'],
            'message' => $result['message'],
        ] );

        $this->debounce->clear();
        do_action( 'wpgd_deploy_triggered', $result, $reason, $context );

        return $result['success'];
    }

    public function execute_acf_batch_deploy(): void {
        delete_transient( 'wpgd_acf_updating' );
        $this->deploy_now( 'acf_field_group_update', [
            'type' => 'batch',
            'note' => 'Triggered after ACF field group update',
        ] );
    }

    public function is_acf_batch_mode(): bool {
        if ( ! $this->settings->get( 'acf_batch', true ) ) {
            return false;
        }
        return (bool) get_transient( 'wpgd_acf_updating' );
    }

    public function enable_acf_batch_mode(): void {
        $delay = (int) $this->settings->get( 'acf_batch_delay', 10 );
        set_transient( 'wpgd_acf_updating', true, $delay + 30 );
    }

    public function schedule_acf_batch_deploy(): void {
        $delay = (int) $this->settings->get( 'acf_batch_delay', 10 );
        wp_clear_scheduled_hook( 'wpgd_acf_batch_deploy' );
        wp_schedule_single_event( time() + $delay, 'wpgd_acf_batch_deploy' );
    }

    public function is_deploy_pending(): bool {
        return $this->debounce->is_pending();
    }

    public function get_pending_deploy_time(): int|false {
        return $this->debounce->get_time_remaining();
    }

    public function get_pending_deploy_info(): ?array {
        return $this->debounce->get_pending_info();
    }

    public function cancel_pending_deploy(): bool {
        return $this->debounce->clear();
    }

    public function get_deploy_history( int $limit = 20 ): array {
        return $this->settings->get_history( $limit );
    }

    public function get_latest_status(): array {
        return $this->api->get_latest_run_status();
    }

    public function should_deploy_for_post_type( string $post_type ): bool {
        $allowed = $this->settings->get( 'post_types', [ 'post', 'page' ] );
        return in_array( $post_type, $allowed, true );
    }

    public function should_deploy_for_taxonomy(): bool {
        return (bool) $this->settings->get( 'deploy_taxonomies', true );
    }

    public function should_deploy_for_menu(): bool {
        return (bool) $this->settings->get( 'deploy_menus', true );
    }

    public function should_deploy_for_options(): bool {
        return (bool) $this->settings->get( 'deploy_options', true );
    }

    public function validate_configuration(): array {
        if ( ! $this->settings->is_configured() ) {
            return [
                'valid'   => false,
                'message' => __( 'GitHub is not properly configured. Please complete all required settings.', 'wp-github-deploy' ),
            ];
        }

        $connection = $this->api->validate_connection();
        
        return [
            'valid'   => $connection['success'],
            'message' => $connection['message'],
            'data'    => $connection['data'] ?? null,
        ];
    }
}

