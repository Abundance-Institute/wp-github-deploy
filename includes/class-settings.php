<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPGD_Settings {

    private const PREFIX = 'wpgd_';

    private const DEFAULTS = [
        'github_token'       => '',
        'github_owner'       => '',
        'github_repo'        => '',
        'github_workflow'    => 'deploy.yml',
        'github_branch'      => 'main',
        'workflow_inputs'    => [],
        'auto_deploy'        => true,
        'debounce_minutes'   => 5,
        'post_types'         => [ 'post', 'page' ],
        'deploy_taxonomies'  => true,
        'deploy_menus'       => true,
        'deploy_options'     => true,
        'deploy_updates'     => false,
        'acf_batch'          => true,
        'acf_batch_delay'    => 10,
        'deploy_history'     => [],
    ];

    private $cache = [];

    public function __construct() {
        $this->load_cache();
    }

    private function load_cache(): void {
        foreach ( array_keys( self::DEFAULTS ) as $key ) {
            $this->cache[ $key ] = get_option( self::PREFIX . $key, self::DEFAULTS[ $key ] );
        }
    }

    public function get( string $key, $default = null ) {
        if ( isset( $this->cache[ $key ] ) ) {
            return $this->cache[ $key ];
        }

        $value = get_option( self::PREFIX . $key, $default ?? ( self::DEFAULTS[ $key ] ?? null ) );
        $this->cache[ $key ] = $value;

        return $value;
    }

    public function set( string $key, $value ): bool {
        $this->cache[ $key ] = $value;
        return update_option( self::PREFIX . $key, $value );
    }

    public function delete( string $key ): bool {
        unset( $this->cache[ $key ] );
        return delete_option( self::PREFIX . $key );
    }

    public function set_defaults(): void {
        foreach ( self::DEFAULTS as $key => $value ) {
            if ( get_option( self::PREFIX . $key ) === false ) {
                add_option( self::PREFIX . $key, $value );
            }
        }
    }

    public function get_all(): array {
        $settings = [];
        foreach ( array_keys( self::DEFAULTS ) as $key ) {
            $settings[ $key ] = $this->get( $key );
        }
        return $settings;
    }

    public function update_all( array $settings ): bool {
        $success = true;
        foreach ( $settings as $key => $value ) {
            if ( array_key_exists( $key, self::DEFAULTS ) ) {
                if ( ! $this->set( $key, $value ) ) {
                    $success = false;
                }
            }
        }
        return $success;
    }

    public function encrypt_token( string $token ): string {
        if ( empty( $token ) ) {
            return '';
        }

        $key = wp_salt( 'auth' );
        $iv = substr( md5( $key ), 0, 16 );
        
        if ( function_exists( 'openssl_encrypt' ) ) {
            return base64_encode( openssl_encrypt( $token, 'AES-256-CBC', $key, 0, $iv ) );
        }

        return base64_encode( $token );
    }

    public function decrypt_token( string $encrypted ): string {
        if ( empty( $encrypted ) ) {
            return '';
        }

        $key = wp_salt( 'auth' );
        $iv = substr( md5( $key ), 0, 16 );
        
        if ( function_exists( 'openssl_encrypt' ) ) {
            $decrypted = openssl_decrypt( base64_decode( $encrypted ), 'AES-256-CBC', $key, 0, $iv );
            return $decrypted !== false ? $decrypted : '';
        }

        return base64_decode( $encrypted );
    }

    public function save_token( string $token ): bool {
        $encrypted = $this->encrypt_token( $token );
        return $this->set( 'github_token', $encrypted );
    }

    public function get_token(): string {
        $encrypted = $this->get( 'github_token' );
        return $this->decrypt_token( $encrypted );
    }

    public function is_configured(): bool {
        return ! empty( $this->get_token() ) 
            && ! empty( $this->get( 'github_owner' ) )
            && ! empty( $this->get( 'github_repo' ) )
            && ! empty( $this->get( 'github_workflow' ) );
    }

    public function get_available_post_types(): array {
        $post_types = get_post_types( [
            'public' => true,
        ], 'objects' );

        $available = [];
        foreach ( $post_types as $slug => $post_type ) {
            if ( $slug === 'attachment' ) {
                continue;
            }
            $available[ $slug ] = $post_type->label;
        }

        return $available;
    }

    public function add_to_history( array $deploy ): void {
        $history = $this->get( 'deploy_history', [] );

        array_unshift( $history, array_merge( [
            'timestamp' => current_time( 'timestamp' ),
            'date'      => current_time( 'mysql' ),
        ], $deploy ) );

        $history = array_slice( $history, 0, 50 );

        $this->set( 'deploy_history', $history );
    }

    public function get_history( int $limit = 20 ): array {
        $history = $this->get( 'deploy_history', [] );
        return array_slice( $history, 0, $limit );
    }

    public function clear_history(): void {
        $this->set( 'deploy_history', [] );
    }

    public function export(): array {
        $settings = $this->get_all();
        unset( $settings['github_token'] );
        unset( $settings['deploy_history'] );
        
        return $settings;
    }

    public function import( array $settings ): bool {
        unset( $settings['github_token'] );
        unset( $settings['deploy_history'] );
        
        return $this->update_all( $settings );
    }
}

