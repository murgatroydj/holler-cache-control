jQuery(document).ready(function($) {
    // Update cache status in admin bar
    function updateCacheStatus() {
        $.ajax({
            url: hollerCacheControl.ajax_url,
            type: 'POST',
            data: {
                action: 'holler_cache_control_status',
                nonce: hollerCacheControl.nonce
            },
            success: function(response) {
                if (!response.success) {
                    return;
                }

                const status = response.data;
                
                // Update Nginx status
                if (status.nginx) {
                    const $nginxStatus = $('#wp-admin-bar-nginx-status');
                    if ($nginxStatus.length) {
                        const text = status.nginx.active ? 'Running' : 'Not Active';
                        $nginxStatus.find('.ab-item').text('Nginx Cache: ' + text);
                    }
                }
                
                // Update Redis status
                if (status.redis) {
                    const $redisStatus = $('#wp-admin-bar-redis-status');
                    if ($redisStatus.length) {
                        const text = status.redis.active ? 'Running' : 'Not Active';
                        $redisStatus.find('.ab-item').text('Redis Cache: ' + text);
                    }
                }

                // Update Cloudflare status
                if (status.cloudflare) {
                    const $cfStatus = $('#wp-admin-bar-cloudflare-status');
                    if ($cfStatus.length) {
                        const text = status.cloudflare.active ? 'Connected' : 'Not Connected';
                        $cfStatus.find('.ab-item').text('Cloudflare Cache: ' + text);
                    }
                }

                // Update Cloudflare APO status
                if (status.cloudflare_apo) {
                    const $apoStatus = $('#wp-admin-bar-cloudflare-apo-status');
                    if ($apoStatus.length) {
                        const text = status.cloudflare_apo.active ? 'Enabled' : 'Disabled';
                        $apoStatus.find('.ab-item').text('Cloudflare APO: ' + text);
                    }
                }
            }
        });
    }

    // Initial cache status update
    updateCacheStatus();

    // Update cache status periodically (every 30 seconds)
    setInterval(updateCacheStatus, 30000);

    // Show notice function
    function showNotice(message, type = 'success') {
        console.log('Showing notice:', message, type); // Debug log
        
        const notice = $('<div class="notice is-dismissible"></div>')
            .addClass(type === 'success' ? 'notice-success' : 'notice-error')
            .append($('<p></p>').text(message));
            
        // Remove any existing notices
        $('.holler-cache-control-notice').remove();
        
        // If we're in the admin area
        if ($('body.wp-admin').length) {
            // Add the new notice before the cache status grid
            $('.cache-status-grid').before(notice.addClass('holler-cache-control-notice'));
        } else {
            // We're on the front end, show notice at the top of the page
            notice.addClass('holler-cache-control-notice')
                .css({
                    'position': 'fixed',
                    'top': '32px', // Below admin bar
                    'right': '20px',
                    'z-index': '99999',
                    'max-width': '300px',
                    'background': '#fff',
                    'box-shadow': '0 1px 3px rgba(0,0,0,0.2)',
                    'margin': '0'
                })
                .appendTo('body');
            
            // Auto-dismiss after 3 seconds on front end
            setTimeout(function() {
                notice.fadeOut(200, function() {
                    $(this).remove();
                });
            }, 3000);
        }
        
        // Make the notice dismissible
        notice.append(
            $('<button type="button" class="notice-dismiss">' +
              '<span class="screen-reader-text">Dismiss this notice.</span>' +
              '</button>').click(function() {
                $(this).parent().fadeOut(200, function() {
                    $(this).remove();
                });
            })
        );
    }

    // Purge cache function
    function purgeCache(type, $button) {
        console.log('Purging cache:', type); // Debug log
        const originalText = $button.text();
        
        $button.prop('disabled', true).text(hollerCacheControl.i18n.purging);
        
        $.ajax({
            url: hollerCacheControl.ajax_url,
            type: 'POST',
            data: {
                action: 'holler_cache_control_purge',
                cache_type: type,
                nonce: hollerCacheControl.nonce
            },
            success: function(response) {
                console.log('Purge response:', response); // Debug log
                if (response.success) {
                    showNotice(response.data, 'success');
                    $button.text(hollerCacheControl.i18n.purged);
                } else {
                    // Check if the message contains "cleared" or "purged" - these are actually successes
                    const message = response.data.toLowerCase();
                    if (message.includes('cleared') || message.includes('purged')) {
                        showNotice(response.data, 'success');
                        $button.text(hollerCacheControl.i18n.purged);
                    } else {
                        showNotice(hollerCacheControl.i18n.error + response.data, 'error');
                        $button.text(originalText);
                    }
                }
                
                // Reset button text after delay
                setTimeout(function() {
                    $button.prop('disabled', false).text(originalText);
                }, 2000);
                
                // Refresh cache status after purge
                setTimeout(updateCacheStatus, 500);
            },
            error: function(xhr, status, error) {
                console.error('Purge error:', error); // Debug log
                showNotice(hollerCacheControl.i18n.error + error, 'error');
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    // Handle purge button clicks (both admin bar and tools page)
    function handlePurgeClick(e) {
        e.preventDefault();
        e.stopPropagation(); // Prevent event from bubbling up
        
        const $button = $(this);
        const type = $button.data('cache-type');
        
        // Skip if no valid type found
        if (!type) {
            console.error('No cache type found for button:', $button);
            return;
        }
        
        let confirmMessage = hollerCacheControl.i18n['confirm_purge_' + type] || 
                           hollerCacheControl.i18n.confirm_purge_all;
        
        if (confirm(confirmMessage)) {
            purgeCache(type, $button);
        }
    }

    // Attach click handlers to all purge buttons
    $(document).on('click', '.purge-cache', handlePurgeClick);

    // Handle settings form submission
    $('#holler-cache-control-settings').submit(function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitButton = $form.find('input[type="submit"]');
        const originalText = $submitButton.val();

        $submitButton.val('Saving...').prop('disabled', true);

        $.ajax({
            url: hollerCacheControl.ajax_url,
            type: 'POST',
            data: $form.serialize() + '&action=holler_cache_control_save_settings&nonce=' + hollerCacheControl.nonce,
            success: function(response) {
                if (response.success) {
                    showNotice('Settings saved successfully!', 'success');
                    $submitButton.val('Saved!');
                } else {
                    showNotice('Error saving settings: ' + (response.data || 'Unknown error'), 'error');
                    $submitButton.val('Error: ' + (response.data || 'Unknown error'));
                }
                setTimeout(function() {
                    $submitButton.val(originalText).prop('disabled', false);
                }, 2000);
            },
            error: function(xhr, status, error) {
                showNotice('Error saving settings: ' + error, 'error');
                $submitButton.val('Error: ' + error);
                setTimeout(function() {
                    $submitButton.val(originalText).prop('disabled', false);
                }, 2000);
            }
        });
    });
});
