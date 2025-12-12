<?php
/**
 * Settings Page Template
 *
 * @package WP_GitHub_Deploy
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get current values
$github_token    = $this->get_masked_token();
$github_owner    = $this->settings->get( 'github_owner' );
$github_repo     = $this->settings->get( 'github_repo' );
$github_workflow = $this->settings->get( 'github_workflow', 'deploy.yml' );
$github_branch   = $this->settings->get( 'github_branch', 'main' );

$auto_deploy       = $this->settings->get( 'auto_deploy', true );
$debounce_minutes  = $this->settings->get( 'debounce_minutes', 5 );
$post_types        = $this->settings->get( 'post_types', [ 'post', 'page' ] );
$deploy_taxonomies = $this->settings->get( 'deploy_taxonomies', true );
$deploy_menus      = $this->settings->get( 'deploy_menus', true );
$deploy_options    = $this->settings->get( 'deploy_options', true );
$deploy_updates    = $this->settings->get( 'deploy_updates', false );

$acf_batch       = $this->settings->get( 'acf_batch', true );
$acf_batch_delay = $this->settings->get( 'acf_batch_delay', 10 );

$available_post_types = $this->settings->get_available_post_types();
$is_configured        = $this->settings->is_configured();
$has_acf              = class_exists( 'ACF' );

// Get pending deploy info
$pending_info   = $this->debounce->get_pending_info();
$time_remaining = $this->debounce->get_time_remaining();
$pending_count  = $this->debounce->get_pending_count();

// Get latest run status
$latest_run = null;
if ( $is_configured ) {
    $run_result = $this->api->get_latest_run_status();
    $latest_run = $run_result['success'] ? ( $run_result['data'] ?? null ) : null;
}

// Get deploy history
$history = $this->settings->get_history( 10 );
?>

<div class="wrap wpgd-wrap">
    
    <div class="wpgd-header">
        <h1>
            <span class="dashicons dashicons-cloud-upload"></span>
            <?php esc_html_e( 'GitHub Deploy', 'wp-github-deploy' ); ?>
        </h1>
        
        <?php if ( $is_configured ) : ?>
            <button type="button" id="wpgd-deploy-now" class="wpgd-button wpgd-button-primary wpgd-button-large">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Deploy Now', 'wp-github-deploy' ); ?>
            </button>
        <?php endif; ?>
    </div>

    <?php settings_errors( 'wpgd_settings' ); ?>

    <?php if ( $pending_info && $time_remaining ) : ?>
        <div id="wpgd-pending-banner" class="wpgd-pending-banner">
            <div class="wpgd-pending-info">
                <span class="dashicons dashicons-clock"></span>
                <div class="wpgd-pending-text">
                    <strong><?php esc_html_e( 'Deploy Pending', 'wp-github-deploy' ); ?></strong>
                    <span><?php printf( esc_html( _n( '%d change queued', '%d changes queued', $pending_count, 'wp-github-deploy' ) ), $pending_count ); ?></span>
                </div>
            </div>
            <div style="display: flex; align-items: center;">
                <?php
                $minutes = floor( $time_remaining / 60 );
                $secs    = $time_remaining % 60;
                ?>
                <span id="wpgd-countdown" class="wpgd-countdown" data-seconds="<?php echo esc_attr( $time_remaining ); ?>">
                    <?php echo esc_html( sprintf( '%d:%02d', $minutes, $secs ) ); ?>
                </span>
                <button type="button" id="wpgd-cancel-deploy" class="wpgd-button wpgd-button-danger">
                    <?php esc_html_e( 'Cancel', 'wp-github-deploy' ); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Status Card -->
    <?php if ( $is_configured ) : ?>
        <div class="wpgd-status-card <?php echo $latest_run && $latest_run['conclusion'] === 'failure' ? 'is-error' : ''; ?>">
            <div class="wpgd-status-row">
                <span class="wpgd-status-icon success"><span class="dashicons dashicons-yes-alt"></span></span>
                <span class="wpgd-status-label"><?php esc_html_e( 'Connected to:', 'wp-github-deploy' ); ?></span>
                <span class="wpgd-status-value">
                    <a href="<?php echo esc_url( "https://github.com/{$github_owner}/{$github_repo}" ); ?>" target="_blank">
                        <?php echo esc_html( "{$github_owner}/{$github_repo}" ); ?>
                    </a>
                    (<?php echo esc_html( $github_branch ); ?> branch)
                </span>
            </div>
            
            <?php if ( $latest_run ) : ?>
                <div class="wpgd-status-row">
                    <?php
                    $status_class = 'info';
                    $status_icon  = 'dashicons-info';
                    $status_text  = ucfirst( $latest_run['status'] );
                    
                    if ( $latest_run['conclusion'] ) {
                        if ( $latest_run['conclusion'] === 'success' ) {
                            $status_class = 'success';
                            $status_icon  = 'dashicons-yes-alt';
                            $status_text  = __( 'Success', 'wp-github-deploy' );
                        } else {
                            $status_class = 'error';
                            $status_icon  = 'dashicons-warning';
                            $status_text  = ucfirst( $latest_run['conclusion'] );
                        }
                    }
                    ?>
                    <span class="wpgd-status-icon <?php echo esc_attr( $status_class ); ?>">
                        <span class="dashicons <?php echo esc_attr( $status_icon ); ?>"></span>
                    </span>
                    <span class="wpgd-status-label"><?php esc_html_e( 'Last deploy:', 'wp-github-deploy' ); ?></span>
                    <span class="wpgd-status-value">
                        <?php echo esc_html( $this->format_date( $latest_run['updated_at'] ) ); ?> - 
                        <a href="<?php echo esc_url( $latest_run['html_url'] ); ?>" target="_blank">
                            <?php echo esc_html( $status_text ); ?>
                        </a>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <div class="wpgd-status-card is-warning">
            <div class="wpgd-status-row">
                <span class="wpgd-status-icon pending"><span class="dashicons dashicons-warning"></span></span>
                <span class="wpgd-status-label"><?php esc_html_e( 'Not configured', 'wp-github-deploy' ); ?></span>
                <span class="wpgd-status-value"><?php esc_html_e( 'Please complete the GitHub configuration below.', 'wp-github-deploy' ); ?></span>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'wpgd_settings' ); ?>

        <!-- GitHub Configuration -->
        <div class="wpgd-card">
            <div class="wpgd-card-header">
                <span class="dashicons dashicons-admin-network"></span>
                <h2><?php esc_html_e( 'GitHub Configuration', 'wp-github-deploy' ); ?></h2>
            </div>
            <div class="wpgd-card-body">
                <div class="wpgd-form-row">
                    <label for="wpgd-github-token"><?php esc_html_e( 'Personal Access Token', 'wp-github-deploy' ); ?></label>
                    <div class="wpgd-token-field">
                        <input 
                            type="password" 
                            id="wpgd-github-token" 
                            name="wpgd_github_token" 
                            value="<?php echo esc_attr( $github_token ); ?>"
                            placeholder="<?php echo $github_token ? '' : 'ghp_xxxxxxxxxxxxxxxxxxxx'; ?>"
                            autocomplete="new-password"
                        >
                        <button type="button" id="wpgd-toggle-token" class="wpgd-button wpgd-button-secondary">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                        <button type="button" id="wpgd-validate-connection" class="wpgd-button wpgd-button-secondary">
                            <?php esc_html_e( 'Validate', 'wp-github-deploy' ); ?>
                        </button>
                    </div>
                    <p class="description">
                        <?php 
                        printf(
                            /* translators: %s: GitHub token settings URL */
                            esc_html__( 'Create a token at %s with "repo" and "workflow" scopes.', 'wp-github-deploy' ),
                            '<a href="https://github.com/settings/tokens" target="_blank">GitHub Settings</a>'
                        ); 
                        ?>
                    </p>
                    <div id="wpgd-validation-result" class="wpgd-validation-result"></div>
                </div>

                <div class="wpgd-form-row">
                    <label for="wpgd-github-owner"><?php esc_html_e( 'Repository Owner', 'wp-github-deploy' ); ?></label>
                    <input 
                        type="text" 
                        id="wpgd-github-owner" 
                        name="wpgd_github_owner" 
                        value="<?php echo esc_attr( $github_owner ); ?>"
                        placeholder="username or organization"
                    >
                    <p class="description"><?php esc_html_e( 'GitHub username or organization name.', 'wp-github-deploy' ); ?></p>
                </div>

                <div class="wpgd-form-row">
                    <label for="wpgd-github-repo"><?php esc_html_e( 'Repository Name', 'wp-github-deploy' ); ?></label>
                    <input 
                        type="text" 
                        id="wpgd-github-repo" 
                        name="wpgd_github_repo" 
                        value="<?php echo esc_attr( $github_repo ); ?>"
                        placeholder="my-website"
                    >
                </div>

                <div class="wpgd-form-row">
                    <label for="wpgd-github-workflow"><?php esc_html_e( 'Workflow File', 'wp-github-deploy' ); ?></label>
                    <input 
                        type="text" 
                        id="wpgd-github-workflow" 
                        name="wpgd_github_workflow" 
                        value="<?php echo esc_attr( $github_workflow ); ?>"
                        placeholder="deploy.yml"
                    >
                    <p class="description"><?php esc_html_e( 'The workflow filename in .github/workflows/', 'wp-github-deploy' ); ?></p>
                </div>

                <div class="wpgd-form-row">
                    <label for="wpgd-github-branch"><?php esc_html_e( 'Branch', 'wp-github-deploy' ); ?></label>
                    <input 
                        type="text" 
                        id="wpgd-github-branch" 
                        name="wpgd_github_branch" 
                        value="<?php echo esc_attr( $github_branch ); ?>"
                        placeholder="main"
                    >
                    <p class="description"><?php esc_html_e( 'The branch to trigger the workflow on.', 'wp-github-deploy' ); ?></p>
                </div>
            </div>
        </div>

        <!-- Deploy Settings -->
        <div class="wpgd-card">
            <div class="wpgd-card-header">
                <span class="dashicons dashicons-admin-settings"></span>
                <h2><?php esc_html_e( 'Deploy Settings', 'wp-github-deploy' ); ?></h2>
            </div>
            <div class="wpgd-card-body">
                <div class="wpgd-form-row">
                    <label class="wpgd-toggle">
                        <input 
                            type="checkbox" 
                            id="wpgd-auto-deploy" 
                            name="wpgd_auto_deploy" 
                            value="1" 
                            <?php checked( $auto_deploy ); ?>
                        >
                        <span class="wpgd-toggle-switch"></span>
                        <span class="wpgd-toggle-label"><?php esc_html_e( 'Enable Automatic Deploys', 'wp-github-deploy' ); ?></span>
                    </label>
                    <p class="description"><?php esc_html_e( 'Automatically trigger deploys when content changes.', 'wp-github-deploy' ); ?></p>
                </div>

                <div class="wpgd-auto-deploy-settings" style="<?php echo $auto_deploy ? '' : 'display: none;'; ?>">
                    <div class="wpgd-form-row">
                        <label for="wpgd-debounce-minutes"><?php esc_html_e( 'Debounce Time (minutes)', 'wp-github-deploy' ); ?></label>
                        <input 
                            type="number" 
                            id="wpgd-debounce-minutes" 
                            name="wpgd_debounce_minutes" 
                            value="<?php echo esc_attr( $debounce_minutes ); ?>"
                            min="0"
                            max="60"
                        >
                        <p class="description">
                            <?php esc_html_e( 'Wait this many minutes after changes before deploying. Set to 0 for immediate deploys.', 'wp-github-deploy' ); ?>
                        </p>
                    </div>

                    <div class="wpgd-form-row">
                        <label><?php esc_html_e( 'Deploy on changes to:', 'wp-github-deploy' ); ?></label>
                        <div class="wpgd-checkbox-grid">
                            <?php foreach ( $available_post_types as $slug => $label ) : ?>
                                <label>
                                    <input 
                                        type="checkbox" 
                                        name="wpgd_post_types[]" 
                                        value="<?php echo esc_attr( $slug ); ?>"
                                        <?php checked( in_array( $slug, $post_types, true ) ); ?>
                                    >
                                    <?php echo esc_html( $label ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="wpgd-form-row">
                        <div class="wpgd-checkbox-grid">
                            <label>
                                <input 
                                    type="checkbox" 
                                    name="wpgd_deploy_taxonomies" 
                                    value="1"
                                    <?php checked( $deploy_taxonomies ); ?>
                                >
                                <?php esc_html_e( 'Categories & Tags', 'wp-github-deploy' ); ?>
                            </label>
                            <label>
                                <input 
                                    type="checkbox" 
                                    name="wpgd_deploy_menus" 
                                    value="1"
                                    <?php checked( $deploy_menus ); ?>
                                >
                                <?php esc_html_e( 'Navigation Menus', 'wp-github-deploy' ); ?>
                            </label>
                            <label>
                                <input 
                                    type="checkbox" 
                                    name="wpgd_deploy_options" 
                                    value="1"
                                    <?php checked( $deploy_options ); ?>
                                >
                                <?php esc_html_e( 'ACF Options Pages', 'wp-github-deploy' ); ?>
                            </label>
                            <label>
                                <input 
                                    type="checkbox" 
                                    name="wpgd_deploy_updates" 
                                    value="1"
                                    <?php checked( $deploy_updates ); ?>
                                >
                                <?php esc_html_e( 'Theme/Plugin Updates', 'wp-github-deploy' ); ?>
                            </label>
                        </div>
                    </div>

                    <?php if ( $has_acf ) : ?>
                        <div class="wpgd-acf-section">
                            <h3>
                                <span class="dashicons dashicons-database"></span>
                                <?php esc_html_e( 'ACF Integration', 'wp-github-deploy' ); ?>
                            </h3>
                            
                            <div class="wpgd-form-row">
                                <label>
                                    <input 
                                        type="checkbox" 
                                        name="wpgd_acf_batch" 
                                        value="1"
                                        <?php checked( $acf_batch ); ?>
                                    >
                                    <?php esc_html_e( 'Batch Field Group Updates', 'wp-github-deploy' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'When updating a field group, deploy once instead of for each affected post.', 'wp-github-deploy' ); ?>
                                </p>
                            </div>

                            <div class="wpgd-form-row">
                                <label for="wpgd-acf-batch-delay"><?php esc_html_e( 'ACF Batch Delay (seconds)', 'wp-github-deploy' ); ?></label>
                                <input 
                                    type="number" 
                                    id="wpgd-acf-batch-delay" 
                                    name="wpgd_acf_batch_delay" 
                                    value="<?php echo esc_attr( $acf_batch_delay ); ?>"
                                    min="5"
                                    max="120"
                                >
                                <p class="description">
                                    <?php esc_html_e( 'Wait this many seconds after ACF field group update before deploying.', 'wp-github-deploy' ); ?>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wpgd-form-footer">
                <button type="submit" name="wpgd_save_settings" class="wpgd-button wpgd-button-primary">
                    <span class="dashicons dashicons-saved"></span>
                    <?php esc_html_e( 'Save Settings', 'wp-github-deploy' ); ?>
                </button>
            </div>
        </div>
    </form>

    <!-- Deploy History -->
    <div class="wpgd-card">
        <div class="wpgd-card-header">
            <span class="dashicons dashicons-backup"></span>
            <h2><?php esc_html_e( 'Recent Deploys', 'wp-github-deploy' ); ?></h2>
        </div>
        <div class="wpgd-card-body" style="padding: 0;">
            <?php if ( empty( $history ) ) : ?>
                <p class="wpgd-history-empty"><?php esc_html_e( 'No deploys yet.', 'wp-github-deploy' ); ?></p>
            <?php else : ?>
                <table class="wpgd-history">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'wp-github-deploy' ); ?></th>
                            <th><?php esc_html_e( 'Trigger', 'wp-github-deploy' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'wp-github-deploy' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $history as $deploy ) : ?>
                            <tr>
                                <td><?php echo esc_html( $this->format_date( $deploy['timestamp'] ) ); ?></td>
                                <td>
                                    <?php 
                                    echo esc_html( $this->get_source_label( $deploy['source'] ?? 'unknown' ) );
                                    
                                    // Show additional context
                                    $context = $deploy['context'] ?? [];
                                    if ( ! empty( $context['post_title'] ) ) {
                                        echo ' - <em>' . esc_html( $context['post_title'] ) . '</em>';
                                    } elseif ( ! empty( $context['source_count'] ) ) {
                                        echo ' <small>(' . esc_html( $context['source_count'] ) . ' changes)</small>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if ( $deploy['success'] ) : ?>
                                        <span class="wpgd-status-badge success">
                                            <span class="dashicons dashicons-yes"></span>
                                            <?php esc_html_e( 'Success', 'wp-github-deploy' ); ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="wpgd-status-badge error" title="<?php echo esc_attr( $deploy['message'] ?? '' ); ?>">
                                            <span class="dashicons dashicons-no"></span>
                                            <?php esc_html_e( 'Failed', 'wp-github-deploy' ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="wpgd-history-actions" style="padding: 12px 20px;">
                    <button type="button" id="wpgd-clear-history" class="wpgd-button wpgd-button-secondary">
                        <span class="dashicons dashicons-trash"></span>
                        <?php esc_html_e( 'Clear History', 'wp-github-deploy' ); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

