<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPGD_GitHub_API {

    private $settings;
    private const API_BASE = 'https://api.github.com';

    public function __construct( WPGD_Settings $settings ) {
        $this->settings = $settings;
    }

    private function get_headers(): array {
        $token = $this->settings->get_token();
        
        return [
            'Authorization'        => 'Bearer ' . $token,
            'Accept'               => 'application/vnd.github.v3+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'Content-Type'         => 'application/json',
        ];
    }

    public function trigger_workflow( array $inputs = [] ): array {
        $owner    = $this->settings->get( 'github_owner' );
        $repo     = $this->settings->get( 'github_repo' );
        $workflow = $this->settings->get( 'github_workflow' );
        $branch   = $this->settings->get( 'github_branch', 'main' );

        if ( empty( $owner ) || empty( $repo ) || empty( $workflow ) ) {
            return [
                'success' => false,
                'message' => __( 'GitHub configuration is incomplete. Please check your settings.', 'wp-github-deploy' ),
            ];
        }

        $url = self::API_BASE . "/repos/{$owner}/{$repo}/actions/workflows/{$workflow}/dispatches";

        $body = [
            'ref' => $branch,
        ];

        // Add custom inputs if provided
        $custom_inputs = $this->settings->get( 'workflow_inputs', [] );
        if ( ! empty( $custom_inputs ) || ! empty( $inputs ) ) {
            $body['inputs'] = array_merge( $custom_inputs, $inputs );
        }

        $response = wp_remote_post( $url, [
            'headers' => $this->get_headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code( $response );

        if ( $response_code === 204 ) {
            return [
                'success' => true,
                'message' => __( 'Workflow triggered successfully!', 'wp-github-deploy' ),
            ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $error_message = $body['message'] ?? __( 'Unknown error occurred', 'wp-github-deploy' );

        return [
            'success' => false,
            'message' => sprintf(
                /* translators: %1$d: HTTP status code, %2$s: Error message */
                __( 'GitHub API error (%1$d): %2$s', 'wp-github-deploy' ),
                $response_code,
                $error_message
            ),
        ];
    }

    public function validate_connection(): array {
        $owner    = $this->settings->get( 'github_owner' );
        $repo     = $this->settings->get( 'github_repo' );
        $workflow = $this->settings->get( 'github_workflow' );
        $token    = $this->settings->get_token();

        if ( empty( $token ) ) {
            return [
                'success' => false,
                'message' => __( 'GitHub token is not configured.', 'wp-github-deploy' ),
            ];
        }

        if ( empty( $owner ) || empty( $repo ) ) {
            return [
                'success' => false,
                'message' => __( 'Repository owner and name are required.', 'wp-github-deploy' ),
            ];
        }

        $repo_result = $this->get_repository();
        if ( ! $repo_result['success'] ) {
            return $repo_result;
        }

        if ( ! empty( $workflow ) ) {
            $workflow_result = $this->get_workflow();
            if ( ! $workflow_result['success'] ) {
                return $workflow_result;
            }
        }

        return [
            'success' => true,
            'message' => __( 'Successfully connected to GitHub!', 'wp-github-deploy' ),
            'data'    => [
                'repo'     => $repo_result['data'] ?? null,
                'workflow' => $workflow_result['data'] ?? null,
            ],
        ];
    }

    public function get_repository(): array {
        $owner = $this->settings->get( 'github_owner' );
        $repo  = $this->settings->get( 'github_repo' );

        $url = self::API_BASE . "/repos/{$owner}/{$repo}";

        $response = wp_remote_get( $url, [
            'headers' => $this->get_headers(),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $response_code === 200 ) {
            return [
                'success' => true,
                'data'    => [
                    'full_name'   => $body['full_name'] ?? '',
                    'private'     => $body['private'] ?? false,
                    'description' => $body['description'] ?? '',
                ],
            ];
        }

        if ( $response_code === 404 ) {
            return [
                'success' => false,
                'message' => __( 'Repository not found. Check owner and repository name.', 'wp-github-deploy' ),
            ];
        }

        if ( $response_code === 401 ) {
            return [
                'success' => false,
                'message' => __( 'Invalid GitHub token or insufficient permissions.', 'wp-github-deploy' ),
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? __( 'Failed to access repository.', 'wp-github-deploy' ),
        ];
    }

    public function get_workflow(): array {
        $owner    = $this->settings->get( 'github_owner' );
        $repo     = $this->settings->get( 'github_repo' );
        $workflow = $this->settings->get( 'github_workflow' );

        $url = self::API_BASE . "/repos/{$owner}/{$repo}/actions/workflows/{$workflow}";

        $response = wp_remote_get( $url, [
            'headers' => $this->get_headers(),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $response_code === 200 ) {
            return [
                'success' => true,
                'data'    => [
                    'id'    => $body['id'] ?? 0,
                    'name'  => $body['name'] ?? '',
                    'state' => $body['state'] ?? '',
                    'path'  => $body['path'] ?? '',
                ],
            ];
        }

        if ( $response_code === 404 ) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %s: Workflow filename */
                    __( 'Workflow "%s" not found in the repository.', 'wp-github-deploy' ),
                    $workflow
                ),
            ];
        }

        return [
            'success' => false,
            'message' => $body['message'] ?? __( 'Failed to access workflow.', 'wp-github-deploy' ),
        ];
    }

    public function get_latest_run_status(): array {
        $cached = get_transient( 'wpgd_last_deploy_status' );
        if ( $cached !== false ) {
            return $cached;
        }

        $owner    = $this->settings->get( 'github_owner' );
        $repo     = $this->settings->get( 'github_repo' );
        $workflow = $this->settings->get( 'github_workflow' );
        $branch   = $this->settings->get( 'github_branch', 'main' );

        $url = self::API_BASE . "/repos/{$owner}/{$repo}/actions/workflows/{$workflow}/runs";
        $url = add_query_arg( [
            'branch'   => $branch,
            'per_page' => 1,
        ], $url );

        $response = wp_remote_get( $url, [
            'headers' => $this->get_headers(),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $response_code !== 200 ) {
            return [
                'success' => false,
                'message' => $body['message'] ?? __( 'Failed to fetch workflow runs.', 'wp-github-deploy' ),
            ];
        }

        $runs = $body['workflow_runs'] ?? [];

        if ( empty( $runs ) ) {
            return [
                'success' => true,
                'data'    => null,
                'message' => __( 'No workflow runs found.', 'wp-github-deploy' ),
            ];
        }

        $latest_run = $runs[0];
        $result = [
            'success' => true,
            'data'    => [
                'id'         => $latest_run['id'],
                'status'     => $latest_run['status'],
                'conclusion' => $latest_run['conclusion'],
                'created_at' => $latest_run['created_at'],
                'updated_at' => $latest_run['updated_at'],
                'html_url'   => $latest_run['html_url'],
            ],
        ];

        set_transient( 'wpgd_last_deploy_status', $result, 5 * MINUTE_IN_SECONDS );

        return $result;
    }

    public function clear_status_cache(): void {
        delete_transient( 'wpgd_last_deploy_status' );
    }
}

