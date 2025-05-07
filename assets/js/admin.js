/**
 * Admin JavaScript
 * Handles button loading states for forms
 */
jQuery(document).ready(function($) {
    'use strict';

    // Add loading state to sync button - ONLY on the sync form
    $('.forbes-product-sync-form').on('submit', function() {
        var $button = $(this).find('input[type="submit"]');
        $button.prop('disabled', true).val(forbesProductSync.loadingText || 'Syncing...');
    });

    // Add loading state to test connection button
    $('#forbes-test-form').on('submit', function() {
        var $button = $('#test-connection');
        $button.prop('disabled', true).val('Testing...');
    });

    // Handle form submission
    $(document).ready(function() {
        // Sync checkbox states between sidebar and form
        $('#sidebar-sync-metadata').on('change', function() {
            var isChecked = $(this).is(':checked');
            $('input[name="sync_metadata"]').prop('checked', isChecked);
        });

        $('#sidebar-handle-conflicts').on('change', function() {
            var isChecked = $(this).is(':checked');
            $('input[name="handle_conflicts"]').prop('checked', isChecked);
        });

        $('input[name="sync_metadata"]').on('change', function() {
            var isChecked = $(this).is(':checked');
            $('#sidebar-sync-metadata').prop('checked', isChecked);
        });

        $('input[name="handle_conflicts"]').on('change', function() {
            var isChecked = $(this).is(':checked');
            $('#sidebar-handle-conflicts').prop('checked', isChecked);
        });

        // Handle sidebar apply button
        $('#sidebar-apply-btn').on('click', function() {
            // Sync the form options from sidebar
            $('input[name="sync_metadata"]').prop('checked', $('#sidebar-sync-metadata').is(':checked'));
            $('input[name="handle_conflicts"]').prop('checked', $('#sidebar-handle-conflicts').is(':checked'));
            
            // Trigger the form submission
            $('#forbes-product-sync-form').trigger('submit');
        });

        // Handle sync form submission
        $('#forbes-product-sync-form').on('submit', function(e) {
            e.preventDefault();
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var $results = $('#attribute-comparison-results');
            
            // Get all selected terms
            var selectedTerms = [];
            $('.sync-term-checkbox:checked').each(function() {
                var $checkbox = $(this);
                var $row = $checkbox.closest('tr');
                // Extract just the term name without any icons or extra text
                var rawTermName = $row.find('.term-name').clone().children().remove().end().text().trim();
                
                selectedTerms.push({
                    attribute: $checkbox.data('attr'),
                    term: $checkbox.data('term'),
                    term_name: rawTermName,
                    row: $row
                });
            });

            if (selectedTerms.length === 0) {
                alert(forbesProductSync.i18n.noChangesSelected);
                return;
            }

            // Optimistically update UI
            selectedTerms.forEach(function(term) {
                var $row = term.row;
                $row.addClass('syncing');
                $row.find('.status').text(forbesProductSync.i18n.syncing || 'Syncing...');
                $row.find('input[type="checkbox"]').prop('disabled', true);
            });

            $button.prop('disabled', true).text(forbesProductSync.i18n.processing);
            $('#sidebar-apply-btn').prop('disabled', true);
            $results.prepend('<div class="notice notice-info syncing-notice">' + forbesProductSync.i18n.processing + '</div>');

            // Start the sync process
            $.ajax({
                url: forbesProductSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'forbes_product_sync_process_attributes',
                    nonce: forbesProductSync.nonce,
                    terms: selectedTerms.map(function(term) {
                        return {
                            attribute: term.attribute,
                            term: term.term,
                            term_name: term.term_name
                        };
                    }),
                    sync_metadata: $form.find('input[name="sync_metadata"]').is(':checked') ? 1 : 0,
                    handle_conflicts: $form.find('input[name="handle_conflicts"]').is(':checked') ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        // Update UI for successful sync
                        selectedTerms.forEach(function(term) {
                            var $row = term.row;
                            $row.removeClass('syncing').addClass('synced');
                            $row.find('.status').text(forbesProductSync.i18n.synced || 'Synced');
                            $row.find('input[type="checkbox"]').prop('checked', false).prop('disabled', false);
                        });

                        // Create notification with appropriate class based on response content
                        var noticeClass = 'notice-success';
                        var noticeId = 'sync-notice-' + new Date().getTime();
                        
                        // If message contains "with x errors", use warning class instead
                        if (response.data.message && response.data.message.indexOf('with') !== -1 && response.data.message.indexOf('errors') !== -1) {
                            noticeClass = 'notice-warning';
                            
                            // If we have errors and processed 0 terms, suggest cache refresh
                            if (response.data.message.indexOf('Processed 0 terms') !== -1) {
                                $results.prepend(
                                    '<div id="cache-refresh-notice" class="notice notice-info">' + 
                                    'Unable to find terms in the source data. This might be due to outdated cache. ' +
                                    '<a href="#" class="refresh-cache-link">Click here to refresh the cache</a> and try again.' +
                                    '</div>'
                                );
                                
                                // Add click handler for the refresh link
                                $('.refresh-cache-link').on('click', function(e) {
                                    e.preventDefault();
                                    $('#refresh-cache-btn').trigger('click');
                                    $('#cache-refresh-notice').fadeOut(400, function() { $(this).remove(); });
                                });
                            }
                        }
                        
                        $results.prepend('<div id="' + noticeId + '" class="notice ' + noticeClass + '">' + response.data.message + '</div>');
                        
                        // Auto-dismiss warnings after 5 seconds
                        if (noticeClass === 'notice-warning') {
                            setTimeout(function() {
                                $('#' + noticeId).fadeOut(400, function() { $(this).remove(); });
                            }, 5000);
                        }
                        
                        // Update sidebar
                        updateSelectedTermsCount();
                        updateSelectedTermsList();
                        
                        // If metadata sync was requested, start it in background
                        if ($form.find('input[name="sync_metadata"]').is(':checked')) {
                            startMetadataSync(selectedTerms);
                        }
                    } else {
                        // Revert UI changes on failure
                        selectedTerms.forEach(function(term) {
                            var $row = term.row;
                            $row.removeClass('syncing').addClass('error');
                            $row.find('.status').text(forbesProductSync.i18n.error || 'Error');
                            $row.find('input[type="checkbox"]').prop('disabled', false);
                        });

                        $results.prepend('<div class="notice notice-error">' + (response.data.message || forbesProductSync.i18n.error) + '</div>');
                    }
                    $button.prop('disabled', false).text(forbesProductSync.i18n.startSync);
                    updateSidebarApplyButton();
                    $('.syncing-notice').fadeOut(400, function() { $(this).remove(); });
                },
                error: function() {
                    // Revert UI changes on error
                    selectedTerms.forEach(function(term) {
                        var $row = term.row;
                        $row.removeClass('syncing').addClass('error');
                        $row.find('.status').text(forbesProductSync.i18n.error || 'Error');
                        $row.find('input[type="checkbox"]').prop('disabled', false);
                    });

                    $results.prepend('<div class="notice notice-error">' + forbesProductSync.i18n.error + '</div>');
                    $button.prop('disabled', false).text(forbesProductSync.i18n.startSync);
                    updateSidebarApplyButton();
                    $('.syncing-notice').fadeOut(400, function() { $(this).remove(); });
                }
            });
        });

        // Handle select all/none checkboxes
        $(document).on('change', '.select-all', function() {
            var isChecked = $(this).is(':checked');
            $(this).closest('table').find('tbody input[type="checkbox"]').prop('checked', isChecked);
            updateSidebarApplyButton();
            updateSelectedTermsCount();
            updateSelectedTermsList();
        });

        // Handle individual term checkbox changes
        $(document).on('change', '.sync-term-checkbox', function() {
            updateSidebarApplyButton();
            updateSelectedTermsCount();
            updateSelectedTermsList();
            
            // Update select-all checkbox state
            var $table = $(this).closest('table');
            var allChecked = $table.find('tbody .sync-term-checkbox').length === 
                              $table.find('tbody .sync-term-checkbox:checked').length;
            $table.find('thead .select-all').prop('checked', allChecked);
        });

        // Handle remove term button in sidebar
        $(document).on('click', '.term-remove', function() {
            var termId = $(this).data('term-id');
            var attrId = $(this).data('attr-id');
            
            // Uncheck the corresponding checkbox
            var $checkbox = $('.sync-term-checkbox[data-term="' + termId + '"][data-attr="' + attrId + '"]');
            $checkbox.prop('checked', false);
            
            // Update select-all checkbox state
            var $table = $checkbox.closest('table');
            $table.find('thead .select-all').prop('checked', false);
            
            // Update sidebar
            updateSidebarApplyButton();
            updateSelectedTermsCount();
            updateSelectedTermsList();
        });

        // Function to update sidebar Apply button state
        function updateSidebarApplyButton() {
            var hasCheckedTerms = $('.sync-term-checkbox:checked').length > 0;
            $('#sidebar-apply-btn').prop('disabled', !hasCheckedTerms);
        }

        // Function to update selected terms count
        function updateSelectedTermsCount() {
            var count = $('.sync-term-checkbox:checked').length;
            $('#selected-terms-count').text(count);
            
            // Show/hide no terms message
            if (count > 0) {
                $('.no-terms-message').hide();
            } else {
                $('.no-terms-message').show();
            }
        }

        // Function to update selected terms list in sidebar
        function updateSelectedTermsList() {
            var $termsList = $('.selected-terms-list');
            $termsList.empty();
            
            $('.sync-term-checkbox:checked').each(function() {
                var $checkbox = $(this);
                var termName = $checkbox.data('term-name') || $checkbox.data('term');
                var attrName = $checkbox.data('attr-name') || $checkbox.data('attr');
                var termId = $checkbox.data('term');
                var attrId = $checkbox.data('attr');
                
                var $listItem = $('<li></li>');
                $listItem.text(attrName + ': ' + termName);
                
                var $removeButton = $('<span class="term-remove dashicons dashicons-no-alt"></span>');
                $removeButton.data('term-id', termId);
                $removeButton.data('attr-id', attrId);
                
                $listItem.append($removeButton);
                $termsList.append($listItem);
            });
        }

        // Background metadata sync
        function startMetadataSync(terms) {
            var $metadataNotice = $('<div class="notice notice-info metadata-sync-notice">' + 
                (forbesProductSync.i18n.syncingMetadata || 'Syncing metadata...') + '</div>');
            $('#attribute-comparison-results').prepend($metadataNotice);
            
            processMetadataBatch(terms, 0);
        }

        function processMetadataBatch(terms, startIndex) {
            var batchSize = 5;
            var batch = terms.slice(startIndex, startIndex + batchSize);
            
            if (batch.length === 0) {
                $('.metadata-sync-notice').fadeOut(400, function() { $(this).remove(); });
                return;
            }
            
            $.ajax({
                url: forbesProductSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'forbes_product_sync_metadata',
                    nonce: forbesProductSync.nonce,
                    terms: batch.map(function(term) {
                        return {
                            attribute: term.attribute,
                            term: term.term
                        };
                    })
                },
                success: function(response) {
                    if (response.success) {
                        // Update UI for this batch
                        response.data.syncedTerms.forEach(function(term) {
                            var $row = $('tr[data-term="' + term.term + '"]');
                            $row.find('.metadata-status').text(forbesProductSync.i18n.synced || 'Synced');
                        });
                    }
                    
                    // Process next batch
                    processMetadataBatch(terms, startIndex + batchSize);
                },
                error: function() {
                    $('.metadata-sync-notice').fadeOut(400, function() { $(this).remove(); });
                }
            });
        }

        // Enhanced loading animation
        var loadingInterval, dotsInterval;
        function startLoadingBar() {
            var $bar = $('#attribute-loading-bar .attribute-progress-bar');
            var width = 10;
            $bar.css({width: width + '%'});
            loadingInterval = setInterval(function() {
                width += Math.random() * 10;
                if (width > 80) width = 80;
                $bar.css({width: width + '%'});
            }, 300);
        }
        function stopLoadingBar() {
            clearInterval(loadingInterval);
            var $bar = $('#attribute-loading-bar .attribute-progress-bar');
            $bar.css({width: '100%'});
            setTimeout(function() { $('#attribute-loading-bar').fadeOut(200); $bar.css({width: '0%'}); }, 400);
        }
        function startProcessingDots() {
            var $status = $('#get-attributes-status');
            var base = forbesProductSync.i18n.processing;
            var dots = '';
            dotsInterval = setInterval(function() {
                dots = dots.length < 3 ? dots + '.' : '';
                $status.text(base + dots);
            }, 400);
        }
        function stopProcessingDots() {
            clearInterval(dotsInterval);
            $('#get-attributes-status').html('');
        }

        // Insert loading bar markup if not present
        if ($('#attribute-loading-bar').length === 0) {
            $('#get-attributes-btn').before('<div id="attribute-loading-bar" style="display:none;margin-bottom:16px;"><div class="attribute-progress-bar"></div></div>');
        }

        // Handle Get Attributes button
        $('#get-attributes-btn').on('click', function(e, showLoading) {
            var $btn = $(this);
            var $results = $('#attribute-comparison-results');
            var $loadingBar = $('#attribute-loading-bar');
            $btn.prop('disabled', true);
            $results.empty();
            
            // Only show loading bar if explicitly requested
            if (showLoading !== false) {
                $loadingBar.show();
                startLoadingBar();
                startProcessingDots();
            }
            
            $.ajax({
                url: forbesProductSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'forbes_product_sync_get_attribute_differences',
                    nonce: forbesProductSync.nonce
                },
                success: function(response) {
                    $btn.prop('disabled', false);
                    if (showLoading !== false) {
                        stopLoadingBar();
                        stopProcessingDots();
                    }
                    if (response.success && response.data && response.data.html) {
                        $results.html(response.data.html);
                        if (response.data.hasDifferences) {
                            $('#forbes-product-sync-form').show();
                        } else {
                            $('#forbes-product-sync-form').hide();
                        }
                        
                        // Show cache timestamp if available
                        if (response.data.cache_info) {
                            $('#get-attributes-status').html('<span class="cache-info" style="color:#666;font-style:italic;">' + response.data.cache_info + '</span>');
                        }
                        
                        // Add term names as data attributes for sidebar
                        $('.sync-term-checkbox').each(function() {
                            var $checkbox = $(this);
                            var termName = $checkbox.closest('tr').find('.term-name').text().trim();
                            var attrName = $checkbox.closest('table').find('.attr-name').text().trim();
                            $checkbox.data('term-name', termName);
                            $checkbox.data('attr-name', attrName);
                        });
                        
                        // Initialize sidebar state
                        updateSidebarApplyButton();
                        updateSelectedTermsCount();
                        updateSelectedTermsList();
                    } else {
                        $results.html('<div class="notice notice-info">' + (response.data && response.data.message ? response.data.message : 'No differences found.') + '</div>');
                        $('#forbes-product-sync-form').hide();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    if (showLoading !== false) {
                        stopLoadingBar();
                        stopProcessingDots();
                    }
                    $results.html('<div class="notice notice-error">' + forbesProductSync.i18n.error + '</div>');
                    $('#forbes-product-sync-form').hide();
                }
            });
        });
        
        // Handle Refresh Cache button
        $('#refresh-cache-btn').on('click', function() {
            var $btn = $(this);
            var $getBtn = $('#get-attributes-btn');
            var $results = $('#attribute-comparison-results');
            var $loadingBar = $('#attribute-loading-bar');
            
            $btn.prop('disabled', true);
            $getBtn.prop('disabled', true);
            $results.empty();
            
            $loadingBar.show();
            startLoadingBar();
            startProcessingDots();
            
            // Add a notice that we're refreshing the cache
            $results.html('<div class="notice notice-info">' + 
                (forbesProductSync.i18n.refreshingCache || 'Refreshing attribute cache...') + 
                '</div>');
            
            $.ajax({
                url: forbesProductSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'forbes_product_sync_get_attribute_differences',
                    nonce: forbesProductSync.nonce,
                    refresh_cache: true
                },
                success: function(response) {
                    $btn.prop('disabled', false);
                    $getBtn.prop('disabled', false);
                    stopLoadingBar();
                    stopProcessingDots();
                    
                    if (response.success && response.data && response.data.html) {
                        $results.html(response.data.html);
                        if (response.data.hasDifferences) {
                            $('#forbes-product-sync-form').show();
                        } else {
                            $('#forbes-product-sync-form').hide();
                        }
                        
                        // Show cache timestamp if available
                        if (response.data.cache_info) {
                            $('#get-attributes-status').html('<span class="cache-info" style="color:#666;font-style:italic;">' + response.data.cache_info + '</span>');
                        }
                        
                        // Add term names as data attributes for sidebar
                        $('.sync-term-checkbox').each(function() {
                            var $checkbox = $(this);
                            var termName = $checkbox.closest('tr').find('.term-name').text().trim();
                            var attrName = $checkbox.closest('table').find('.attr-name').text().trim();
                            $checkbox.data('term-name', termName);
                            $checkbox.data('attr-name', attrName);
                        });
                        
                        // Initialize sidebar state
                        updateSidebarApplyButton();
                        updateSelectedTermsCount();
                        updateSelectedTermsList();
                    } else {
                        $results.html('<div class="notice notice-info">' + (response.data && response.data.message ? response.data.message : 'No differences found.') + '</div>');
                        $('#forbes-product-sync-form').hide();
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    $getBtn.prop('disabled', false);
                    stopLoadingBar();
                    stopProcessingDots();
                    $results.html('<div class="notice notice-error">' + forbesProductSync.i18n.error + '</div>');
                    $('#forbes-product-sync-form').hide();
                }
            });
        });
    });
})(jQuery); 