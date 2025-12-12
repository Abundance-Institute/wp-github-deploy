<?php
/**
 * Plugin Name: Easy Github Deploy
 * Plugin URI: https://github.com/jaredlambert/easy-github-deploy
 * Description: Trigger GitHub Actions workflow deployments when WordPress content changes. Perfect for headless WordPress sites.
 * Version: 1.0.0
 * Author: Jared Lambert
 * Author URI: https://jaredlambert.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: easy-github-deploy
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPGD_VERSION', '1.0.0' );
define( 'WPGD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPGD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPGD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

final class WP_GitHub_Deploy {

    private static $instance = null;

    public $api;
    public $settings;
    public $deploy_manager;
    public $debounce;
    public $hooks_manager;
    public $admin;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_components();
        $this->register_hooks();
    }

    private function load_dependencies() {
        require_once WPGD_PLUGIN_DIR . 'includes/class-github-api.php';
        require_once WPGD_PLUGIN_DIR . 'includes/class-settings.php';
        require_once WPGD_PLUGIN_DIR . 'includes/class-deploy-manager.php';
        require_once WPGD_PLUGIN_DIR . 'includes/class-debounce-handler.php';
        require_once WPGD_PLUGIN_DIR . 'includes/class-hooks-manager.php';
        
        if ( is_admin() ) {
            require_once WPGD_PLUGIN_DIR . 'admin/class-admin-page.php';
        }
    }

    private function init_components() {
        $this->settings       = new WPGD_Settings();
        $this->api            = new WPGD_GitHub_API( $this->settings );
        $this->debounce       = new WPGD_Debounce_Handler( $this->settings );
        $this->deploy_manager = new WPGD_Deploy_Manager( $this->api, $this->settings, $this->debounce );
        $this->hooks_manager  = new WPGD_Hooks_Manager( $this->deploy_manager, $this->settings );
        
        if ( is_admin() ) {
            $this->admin = new WPGD_Admin_Page( $this->settings, $this->api, $this->deploy_manager, $this->debounce );
        }
    }

    private function register_hooks() {
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
        
        // Register cron hook
        add_action( 'wpgd_execute_deploy', [ $this->debounce, 'execute_scheduled_deploy' ] );
        add_action( 'wpgd_acf_batch_deploy', [ $this->deploy_manager, 'execute_acf_batch_deploy' ] );
    }

    public function activate() {
        $this->settings->set_defaults();
        wp_clear_scheduled_hook( 'wpgd_execute_deploy' );
        flush_rewrite_rules();
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'wpgd_execute_deploy' );
        wp_clear_scheduled_hook( 'wpgd_acf_batch_deploy' );
        delete_transient( 'wpgd_pending_deploy' );
        delete_transient( 'wpgd_acf_updating' );
        delete_transient( 'wpgd_last_deploy_status' );
    }
}

function wpgd() {
    return WP_GitHub_Deploy::instance();
}

add_action( 'plugins_loaded', 'wpgd' );

