jQuery(document).ready(function($) {
    // Update cache status in admin bar
    function updateCacheStatus() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'holler_cache_control_status',
                _wpnonce: hollerCacheControl.nonces.status
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
            url: ajaxurl,
            type: 'POST',
            data: $form.serialize() + '&action=holler_cache_control_save_settings&_wpnonce=' + hollerCacheControl.nonces.settings,
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

    // Handle purge button clicks
    $('.holler-purge-cache').on('click', function(e) {
        e.preventDefault();
        var cacheType = $(this).data('cache-type');
        purgeCache(cacheType, $(this));
    });

    // Purge cache function
    function purgeCache(cacheType, $button) {
        console.log('Purging cache:', cacheType);
        const originalText = $button.text();
        $button.prop('disabled', true).text(hollerCacheControl.i18n.purging);

        // Get the correct nonce based on cache type
        const nonceKey = cacheType.replace('-', '_');
        const nonce = hollerCacheControl.nonces[nonceKey];
        
        if (!nonce) {
            console.error('No nonce found for cache type:', cacheType);
            showNotice('Error: Invalid cache type', 'error');
            $button.prop('disabled', false).text(originalText);
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'holler_purge_' + cacheType,
                type: cacheType,
                _wpnonce: nonce
            },
            success: function(response) {
                console.log('Purge response:', response);
                if (response.success) {
                    showNotice(response.data, 'success');
                } else {
                    showNotice(response.data || 'Unknown error occurred', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('Purge error:', error);
                showNotice(error || 'Failed to purge cache', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
                if (typeof updateCacheStatus === 'function') {
                    updateCacheStatus();
                }
            }
        });
    }

    // Show notice
    function showNotice(message, type) {
        console.log('Showing notice:', message, type);
        var notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        
        // Add dismiss button and functionality
        var dismissButton = $('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
        notice.append(dismissButton);
        
        dismissButton.on('click', function() {
            notice.fadeOut(300, function() { $(this).remove(); });
        });

        // Different container based on admin/front-end
        var $container;
        if (hollerCacheControl.isAdmin) {
            $container = $('.wrap h1');
            if ($container.length === 0) {
                $container = $('#wpbody-content');
            }
            $container.after(notice);
        } else {
            $container = $('#holler-cache-notice-container');
            if ($container.length) {
                $container.append(notice);
            }
        }
        
        // Auto dismiss after 5 seconds
        setTimeout(function() {
            notice.fadeOut(300, function() { $(this).remove(); });
        }, 5000);
    }

    // Update cache status if on admin page
    if ($('.holler-cache-control-wrap').length) {
        function updateCacheStatus() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'holler_cache_status',
                    _wpnonce: hollerCacheControl.nonces.status
                },
                success: function(response) {
                    if (response.success) {
                        Object.keys(response.data).forEach(function(type) {
                            var status = response.data[type];
                            var $statusElement = $('#holler-' + type + '-status');
                            if ($statusElement.length) {
                                $statusElement.html(status.active ? '✅' : '❌');
                            }
                        });
                    }
                }
            });
        }
        
        // Initial update
        updateCacheStatus();
        
        // Update every 30 seconds
        setInterval(updateCacheStatus, 30000);
    }

    // Purge all caches function
    function purgeAllCaches($button) {
        console.log('Purging all caches'); // Debug log
        const originalText = $button.text();
        $button.prop('disabled', true).text(hollerCacheControl.i18n.purging);

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'holler_purge_all_caches',
                _ajax_nonce: hollerCacheControl.nonces.all_caches
            },
            success: function(response) {
                console.log('Purge response:', response); // Debug log
                if (response.success) {
                    showNotice('Success: ' + response.data, 'success');
                } else {
                    showNotice(response.data || 'Unknown error occurred', 'error');
                }
            },
            error: function(xhr, status, error) {
                console.log('Purge error:', error); // Debug log
                showNotice(error || 'Failed to purge cache', 'error');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
                updateCacheStatus(); // Update status after purge
            }
        });
    }

    // Handle purge all caches button clicks
    $('.holler-purge-all-caches').on('click', function(e) {
        e.preventDefault();
        let confirmMessage = hollerCacheControl.i18n.confirm_purge_all;
        if (confirm(confirmMessage)) {
            purgeAllCaches($(this));
        }
    });
});
