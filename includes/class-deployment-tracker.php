<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Durable per-deployment jobs: correlate exact runs and retry failures twice. */
class WPGD_Deployment_Tracker {
    private $api;
    private $settings;
    private const HOOK = 'wpgd_check_deployment';
    private const PREFIX = 'wpgd_job_';

    public function __construct( WPGD_GitHub_API $api, WPGD_Settings $settings ) {
        $this->api = $api;
        $this->settings = $settings;
        add_action( self::HOOK, [ $this, 'process' ] );
    }

    public function dispatch( string $source, array $context ): bool {
        $id = wp_generate_uuid4();
        $job = [ 'id' => $id, 'source' => $source, 'context' => $context,
            'attempt' => 0, 'created_at' => time(), 'state' => 'dispatch' ];
        update_option( self::PREFIX . $id, $job, false );
        // Persist the recovery event before making a network request.
        $this->schedule( $id, 120 );
        return $this->process( $id );
    }

    public function process( string $id ): bool {
        $key = self::PREFIX . $id;
        $job = get_option( $key );
        if ( ! is_array( $job ) ) { return false; }
        $lock = $key . '_lock';
        $locked_at = (int) get_option( $lock, 0 );
        if ( $locked_at && $locked_at < time() - 300 ) { delete_option( $lock ); }
        if ( ! add_option( $lock, time(), '', false ) ) {
            $this->schedule( $id, 120 );
            return true;
        }
        try {
            if ( $job['state'] === 'retry' && $job['retry_at'] > time() ) {
                $this->schedule( $id, $job['retry_at'] - time() );
                return true;
            }
            if ( $job['state'] === 'retry' ) {
                $job['attempt']++;
                $job['state'] = 'dispatch';
                unset( $job['run_id'] );
            }
            $tracking_id = $id . '-' . $job['attempt'];
            if ( $job['state'] === 'dispatch' ) {
                // An interrupted/ambiguous dispatch is checked for a matching run
                // before another request can be sent.
                $job['state'] = 'waiting';
                $job['dispatched_at'] = time();
                update_option( $key, $job, false );
                $this->schedule( $id, 60 );
                $result = $this->api->trigger_workflow( [ 'deployment_id' => $tracking_id ] );
                $this->record( $job, 'dispatch', $result['success'], $result['message'] );
                if ( ! $result['success'] ) {
                    $job['dispatch_error'] = $result['message'];
                    update_option( $key, $job, false );
                }
                return $result['success'];
            }
            $result = isset( $job['run_id'] )
                ? $this->api->get_tracked_run( (string) $job['run_id'] )
                : $this->api->find_tracked_run( $tracking_id );
            if ( ! $result['success'] ) {
                // Never trigger duplicate builds just because GitHub status is unavailable.
                if ( time() - $job['dispatched_at'] > 86400 ) {
                    return $this->finish( $job, false, 'Unable to verify GitHub deployment after 24 hours. Check GitHub Actions.' );
                }
                $this->schedule( $id, 120 );
                return false;
            }
            $run = $result['data'] ?? null;
            if ( ! $run ) {
                if ( time() - $job['dispatched_at'] < 300 ) { $this->schedule( $id, 60 ); return true; }
                return $this->retry_or_fail( $job, $job['dispatch_error'] ?? 'GitHub did not create a matching workflow run.' );
            }
            $job['run_id'] = $run['id'];
            update_option( $key, $job, false );
            if ( $run['status'] !== 'completed' ) {
                if ( time() - $job['dispatched_at'] > 86400 ) {
                    return $this->finish( $job, false, 'Deployment has not completed after 24 hours: ' . $run['html_url'] );
                }
                $this->schedule( $id, 60 );
                return true;
            }
            if ( $run['conclusion'] === 'success' ) {
                return $this->finish( $job, true, 'Deployment completed successfully: ' . $run['html_url'] );
            }
            if ( in_array( $run['conclusion'], [ 'failure', 'timed_out' ], true ) ) {
                return $this->retry_or_fail( $job, 'GitHub build ' . $run['conclusion'] . ': ' . $run['html_url'] );
            }
            return $this->finish( $job, false, 'Deployment ' . $run['conclusion'] . ': ' . $run['html_url'] );
        } finally {
            delete_option( $lock );
        }
    }

    private function retry_or_fail( array $job, string $message ): bool {
        if ( $job['attempt'] >= 2 ) { return $this->finish( $job, false, $message . ' Automatic retries exhausted.' ); }
        $delay = $job['attempt'] === 0 ? 120 : 600;
        $job['state'] = 'retry';
        $job['retry_at'] = time() + $delay;
        update_option( self::PREFIX . $job['id'], $job, false );
        $this->record( $job, 'retry', false, $message . ' Retry scheduled.' );
        $this->schedule( $job['id'], $delay );
        return false;
    }

    private function finish( array $job, bool $success, string $message ): bool {
        $this->record( $job, 'completed', $success, $message );
        if ( $success ) { delete_option( 'wpgd_deployment_error' ); }
        else { update_option( 'wpgd_deployment_error', $message, false ); }
        delete_option( self::PREFIX . $job['id'] );
        wp_clear_scheduled_hook( self::HOOK, [ $job['id'] ] );
        $this->api->clear_status_cache();
        return $success;
    }

    private function record( array $job, string $phase, bool $success, string $message ): void {
        $this->settings->add_to_history( [ 'source' => $job['source'],
            'context' => array_merge( $job['context'], [ 'deployment_id' => $job['id'], 'attempt' => $job['attempt'] + 1 ] ),
            'phase' => $phase, 'success' => $success, 'message' => $message ] );
    }

    private function schedule( string $id, int $delay ): void {
        // Clear the matching event only. Different deployments keep their own jobs.
        wp_clear_scheduled_hook( self::HOOK, [ $id ] );
        $result = wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, [ $id ], true );
        if ( is_wp_error( $result ) || ! $result ) {
            $message = 'Could not schedule deployment tracking: ' . $id;
            error_log( $message );
            update_option( 'wpgd_deployment_error', $message, false );
        }
    }
}
