/**
 * WP GitHub Deploy - Admin JavaScript
 */

(function($) {
    'use strict';

    const WPGD = {
        init: function() {
            this.bindEvents();
            this.startStatusPolling();
        },

        bindEvents: function() {
            $(document).on('click', '#wpgd-deploy-now', this.handleDeployNow.bind(this));

            $(document).on('click', '#wpgd-cancel-deploy', this.handleCancelDeploy.bind(this));

            $(document).on('click', '#wpgd-validate-connection', this.handleValidateConnection.bind(this));

            $(document).on('click', '#wpgd-clear-history', this.handleClearHistory.bind(this));

            $(document).on('change', '#wpgd-auto-deploy', this.toggleAutoDeploySettings.bind(this));

            $(document).on('click', '#wpgd-toggle-token', this.toggleTokenVisibility.bind(this));

            $(document).on('click', '#wpgd-select-all-types', this.handleSelectAllTypes.bind(this));

            $(document).on('click', '#wpgd-deselect-all-types', this.handleDeselectAllTypes.bind(this));
        },

        handleDeployNow: function(e) {
            e.preventDefault();

            if (!confirm(wpgdAdmin.strings.confirmDeploy)) {
                return;
            }

            const $button = $(e.currentTarget);
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="wpgd-spinner"></span> ' + wpgdAdmin.strings.deploying
            );

            $.ajax({
                url: wpgdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpgd_deploy_now',
                    nonce: wpgdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPGD.showNotice('success', response.data.message);
                        WPGD.updateStatus();
                        // Reload to show updated history
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        WPGD.showNotice('error', wpgdAdmin.strings.deployError + ' ' + response.data.message);
                    }
                },
                error: function() {
                    WPGD.showNotice('error', wpgdAdmin.strings.deployError + ' Network error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        handleCancelDeploy: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="wpgd-spinner"></span> ' + wpgdAdmin.strings.cancelling
            );

            $.ajax({
                url: wpgdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpgd_cancel_deploy',
                    nonce: wpgdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPGD.showNotice('success', response.data.message);
                        WPGD.hidePendingBanner();
                    } else {
                        WPGD.showNotice('error', response.data.message);
                    }
                },
                error: function() {
                    WPGD.showNotice('error', 'Network error');
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        handleValidateConnection: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const $result = $('#wpgd-validation-result');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="wpgd-spinner"></span> ' + wpgdAdmin.strings.validating
            );
            $result.removeClass('is-success is-error').hide();

            $.ajax({
                url: wpgdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpgd_validate_connection',
                    nonce: wpgdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.addClass('is-success').html(
                            '<span class="dashicons dashicons-yes-alt"></span> ' + response.data.message
                        ).show();
                    } else {
                        $result.addClass('is-error').html(
                            '<span class="dashicons dashicons-warning"></span> ' + response.data.message
                        ).show();
                    }
                },
                error: function() {
                    $result.addClass('is-error').html(
                        '<span class="dashicons dashicons-warning"></span> Network error'
                    ).show();
                },
                complete: function() {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        handleClearHistory: function(e) {
            e.preventDefault();

            if (!confirm(wpgdAdmin.strings.confirmClear)) {
                return;
            }

            const $button = $(e.currentTarget);

            $.ajax({
                url: wpgdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpgd_clear_history',
                    nonce: wpgdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    }
                }
            });
        },

        toggleAutoDeploySettings: function(e) {
            const isChecked = $(e.currentTarget).is(':checked');
            $('.wpgd-auto-deploy-settings').toggle(isChecked);
        },

        toggleTokenVisibility: function(e) {
            e.preventDefault();
            const $input = $('#wpgd-github-token');
            const $button = $(e.currentTarget);
            
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $button.find('.dashicons').removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                $input.attr('type', 'password');
                $button.find('.dashicons').removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
        },

        handleSelectAllTypes: function(e) {
            e.preventDefault();
            $('#wpgd-post-types-grid input[type="checkbox"]').prop('checked', true);
        },

        handleDeselectAllTypes: function(e) {
            e.preventDefault();
            $('#wpgd-post-types-grid input[type="checkbox"]').prop('checked', false);
        },

        startStatusPolling: function() {
            // Only poll if there's a pending deploy
            if ($('#wpgd-pending-banner').length) {
                this.statusInterval = setInterval(this.updateStatus.bind(this), 5000);
                this.countdownInterval = setInterval(this.updateCountdown.bind(this), 1000);
            }
        },

        updateStatus: function() {
            $.ajax({
                url: wpgdAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wpgd_get_status',
                    nonce: wpgdAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        const data = response.data;
                        
                        if (data.pending && data.time_remaining > 0) {
                            WPGD.showPendingBanner(data.time_remaining, data.pending_count);
                        } else {
                            WPGD.hidePendingBanner();
                            // If deploy just executed, reload to see results
                            if (WPGD.hadPendingDeploy) {
                                location.reload();
                            }
                        }
                        
                        WPGD.hadPendingDeploy = !!data.pending;
                    }
                }
            });
        },

        updateCountdown: function() {
            const $countdown = $('#wpgd-countdown');
            if (!$countdown.length) return;

            let seconds = parseInt($countdown.data('seconds'), 10);
            if (isNaN(seconds) || seconds <= 0) return;

            seconds--;
            $countdown.data('seconds', seconds);

            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            $countdown.text(minutes + ':' + (secs < 10 ? '0' : '') + secs);

            if (seconds <= 0) {
                this.updateStatus();
            }
        },

        showPendingBanner: function(timeRemaining, changeCount) {
            let $banner = $('#wpgd-pending-banner');
            
            if (!$banner.length) {
                // Create banner if it doesn't exist
                const minutes = Math.floor(timeRemaining / 60);
                const secs = timeRemaining % 60;
                const timeStr = minutes + ':' + (secs < 10 ? '0' : '') + secs;
                
                $banner = $(`
                    <div id="wpgd-pending-banner" class="wpgd-pending-banner">
                        <div class="wpgd-pending-info">
                            <span class="dashicons dashicons-clock"></span>
                            <div class="wpgd-pending-text">
                                <strong>Deploy Pending</strong>
                                <span>${changeCount} change(s) queued</span>
                            </div>
                        </div>
                        <div style="display: flex; align-items: center;">
                            <span id="wpgd-countdown" class="wpgd-countdown" data-seconds="${timeRemaining}">${timeStr}</span>
                            <button type="button" id="wpgd-cancel-deploy" class="wpgd-button wpgd-button-danger">
                                Cancel
                            </button>
                        </div>
                    </div>
                `);
                
                $('.wpgd-header').after($banner);
                this.countdownInterval = setInterval(this.updateCountdown.bind(this), 1000);
            } else {
                // Update existing banner
                const minutes = Math.floor(timeRemaining / 60);
                const secs = timeRemaining % 60;
                $('#wpgd-countdown')
                    .data('seconds', timeRemaining)
                    .text(minutes + ':' + (secs < 10 ? '0' : '') + secs);
                $banner.find('.wpgd-pending-text span').text(changeCount + ' change(s) queued');
            }
        },

        hidePendingBanner: function() {
            $('#wpgd-pending-banner').slideUp(300, function() {
                $(this).remove();
            });
            
            if (this.countdownInterval) {
                clearInterval(this.countdownInterval);
            }
        },

        showNotice: function(type, message) {
            const $notice = $(`
                <div class="notice notice-${type} is-dismissible">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `);

            $('.wpgd-wrap .notice').remove();

            $('.wpgd-wrap h1').first().after($notice);

            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut(200, function() {
                    $(this).remove();
                });
            });

            // Auto-dismiss after 5 seconds for success
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut(200, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
        },

        hadPendingDeploy: false
    };

    $(document).ready(function() {
        WPGD.init();
    });

})(jQuery);

