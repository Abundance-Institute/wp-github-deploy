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

        set_transient( self::PENDING_KEY, $pending, $delay_seconds + 60 );
        $this->clear_scheduled_event();
        wp_schedule_single_event( $execute_at, self::CRON_HOOK, [ $pending['batch_id'] ] );
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

        $this->clear();
        $deploy_manager = wpgd()->deploy_manager;
        $deploy_manager->deploy_now( $reason, $context );
    }

    public function is_pending(): bool {
        return (bool) get_transient( self::PENDING_KEY );
    }

    public function get_pending_info(): ?array {
        $pending = get_transient( self::PENDING_KEY );
        return $pending ? $pending : null;
    }

    public function get_time_remaining(): int|false {
        $pending = $this->get_pending_info();

        if ( ! $pending || ! isset( $pending['execute_at'] ) ) {
            return false;
        }

        $remaining = $pending['execute_at'] - time();
        return $remaining > 0 ? $remaining : false;
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
        return delete_transient( self::PENDING_KEY );
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

        set_transient( self::PENDING_KEY, $pending, $delay_seconds + 60 );

        $this->clear_scheduled_event();
        wp_schedule_single_event( $pending['execute_at'], self::CRON_HOOK, [ $pending['batch_id'] ] );

        return true;
    }
}

