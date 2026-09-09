<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPGD_Debounce_Handler {

    private $settings;
    private const PENDING_KEY = 'wpgd_pending_deploy';
    private const CRON_HOOK = 'wpgd_execute_deploy';

    public function __construct( WPGD_Settings $settings ) {
        $this->settings = $settings;
    }

    public function schedule( string $source, array $context = [] ): void {
        $debounce_minutes = (int) $this->settings->get( 'debounce_minutes', 5 );
        $delay_seconds = $debounce_minutes * 60;

        $now = time();
        $execute_at = $now + $delay_seconds;

        $pending = $this->get_pending_info();

        if ( $pending ) {
            $pending['sources'][] = [
                'type'      => $source,
                'context'   => $context,
                'timestamp' => $now,
            ];
            $pending['execute_at'] = $execute_at;
            $pending['updated_at'] = $now;
        } else {
            $pending = [
                'batch_id'     => wp_generate_uuid4(),
                'scheduled_at' => $now,
                'execute_at'   => $execute_at,
                'updated_at'   => $now,
                'sources'      => [
                    [
                        'type'      => $source,
                        'context'   => $context,
                        'timestamp' => $now,
                    ],
                ],
            ];
        }

        $this->set_pending_info( $pending );
        $this->clear_scheduled_event();
        $result = wp_schedule_single_event( $execute_at, self::CRON_HOOK, [ $pending['batch_id'] ], true );
        if ( is_wp_error( $result ) || ! $result ) {
            update_option( 'wpgd_deployment_error', 'Could not schedule the pending deployment. Use Deploy Now.', false );
        }
        do_action( 'wpgd_deploy_scheduled', $pending );
    }

    public function execute_scheduled_deploy( string $batch_id = '' ): void {
        $pending = $this->get_pending_info();

        if ( $pending && ! empty( $batch_id ) && $pending['batch_id'] !== $batch_id ) {
            return;
        }

        if ( ! $pending ) {
            return;
        }

        $sources = $pending['sources'] ?? [];
        $source_types = array_unique( array_column( $sources, 'type' ) );

        $context = [
            'type'         => 'batched',
            'batch_id'     => $pending['batch_id'],
            'source_count' => count( $sources ),
            'source_types' => $source_types,
            'sources'      => array_slice( $sources, 0, 10 ),
        ];

        $reason = count( $source_types ) === 1 
            ? $source_types[0] 
            : sprintf( 'batched (%d changes)', count( $sources ) );

        $deploy_manager = wpgd()->deploy_manager;
        $deploy_manager->deploy_now( $reason, $context );
    }

    public function is_pending(): bool {
        return null !== $this->get_pending_info();
    }

    public function get_pending_info(): ?array {
        $pending = get_option( self::PENDING_KEY, null );

        if ( is_array( $pending ) && isset( $pending['batch_id'] ) ) {
            return $pending;
        }

        $legacy_pending = get_transient( self::PENDING_KEY );

        if ( $legacy_pending ) {
            $this->set_pending_info( $legacy_pending );
            delete_transient( self::PENDING_KEY );
            return $legacy_pending;
        }

        return null;
    }

    public function get_time_remaining(): int|false {
        $pending = $this->get_pending_info();

        if ( ! $pending || ! isset( $pending['execute_at'] ) ) {
            return false;
        }

        $remaining = $pending['execute_at'] - time();
        return max( 0, $remaining );
    }

    public function get_formatted_time_remaining(): string {
        $seconds = $this->get_time_remaining();

        if ( $seconds === false ) {
            return '';
        }

        $minutes = floor( $seconds / 60 );
        $secs = $seconds % 60;

        return sprintf( '%d:%02d', $minutes, $secs );
    }

    public function get_pending_count(): int {
        $pending = $this->get_pending_info();

        if ( ! $pending || ! isset( $pending['sources'] ) ) {
            return 0;
        }

        return count( $pending['sources'] );
    }

    public function clear(): bool {
        $this->clear_scheduled_event();
        $deleted_option = delete_option( self::PENDING_KEY );
        $deleted_transient = delete_transient( self::PENDING_KEY );

        return $deleted_option || $deleted_transient;
    }

    private function clear_scheduled_event(): void {
        $pending = $this->get_pending_info();

        if ( $pending && isset( $pending['batch_id'] ) ) {
            wp_clear_scheduled_hook( self::CRON_HOOK, [ $pending['batch_id'] ] );
        }

        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public function get_next_scheduled(): int|false {
        $pending = $this->get_pending_info();

        if ( $pending && isset( $pending['batch_id'] ) ) {
            return wp_next_scheduled( self::CRON_HOOK, [ $pending['batch_id'] ] );
        }
        
        return wp_next_scheduled( self::CRON_HOOK );
    }

    public function reschedule( int $delay_seconds ): bool {
        $pending = $this->get_pending_info();

        if ( ! $pending ) {
            return false;
        }

        $pending['execute_at'] = time() + $delay_seconds;
        $pending['updated_at'] = time();

        $this->set_pending_info( $pending );

        $this->clear_scheduled_event();
        wp_schedule_single_event( $pending['execute_at'], self::CRON_HOOK, [ $pending['batch_id'] ] );

        return true;
    }

    private function set_pending_info( array $pending ): void {
        // Pending deploys must survive external cron gaps; expiring them early drops valid deploys.
        update_option( self::PENDING_KEY, $pending, false );
    }
}
