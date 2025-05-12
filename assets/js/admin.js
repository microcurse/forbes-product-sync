/**
 * Forbes Product Sync Admin JavaScript
 */
jQuery(document).ready(function($) {
    
    // --- General Admin Logic ---
    
    // --- Settings Page Logic ---
    var $testConnectionBtn = $('#test-connection');
    if ($testConnectionBtn.length) {
        $testConnectionBtn.on('click', function() {
            var $button = $(this);
            var $result = $('#connection-result');
            
            $button.prop('disabled', true).text(forbesProductSync.i18n.processing || 'Testing...');
            $result.html('');
            
            $.ajax({
                url: forbesProductSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'forbes_product_sync_test_connection',
                    nonce: forbesProductSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span class="notice-success">' + response.data.message + '</span>');
                    } else {
                        $result.html('<span class="notice-error">' + response.data.message + '</span>');
                    }
                    $button.prop('disabled', false).text(forbesProductSync.i18n.testConnection || 'Test Connection');
                },
                error: function() {
                    $result.html('<span class="notice-error">' + forbesProductSync.i18n.error + '</span>');
                    $button.prop('disabled', false).text(forbesProductSync.i18n.testConnection || 'Test Connection');
                }
            });
        });
    }
    
    // --- Dashboard Page Logic ---
    var $syncProductsBtn = $('#sync-products');
    if ($syncProductsBtn.length) {
        $syncProductsBtn.on('click', function() {
            if (confirm(forbesProductSync.i18n.confirmSync || 'Are you sure you want to start syncing products?')) {
                var $button = $(this);
                $button.prop('disabled', true).text(forbesProductSync.i18n.downloadingProducts || 'Preparing...');
                
                $.ajax({
                    url: forbesProductSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'forbes_product_sync_init_products',
                        nonce: forbesProductSync.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.redirect) {
                                window.location.href = response.data.redirect;
                            } else {
                                location.reload(); // Reload if no specific redirect
                            }
                        } else {
                            alert(response.data.message || forbesProductSync.i18n.error);
                            $button.prop('disabled', false).text(forbesProductSync.i18n.syncProducts || 'Sync Products');
                        }
                    },
                    error: function() {
                        alert(forbesProductSync.i18n.error);
                        $button.prop('disabled', false).text(forbesProductSync.i18n.syncProducts || 'Sync Products');
                    }
                });
            }
        });
    }
    
    var $cancelSyncBtn = $('#cancel-sync');
    if ($cancelSyncBtn.length) {
        $cancelSyncBtn.on('click', function() {
            if (confirm(forbesProductSync.i18n.confirmCancel || 'Are you sure you want to cancel the sync?')) {
                var $button = $(this);
                $button.prop('disabled', true).text(forbesProductSync.i18n.cancelling || 'Cancelling...');
                
                $.ajax({
                    url: forbesProductSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'forbes_product_sync_cancel',
                        nonce: forbesProductSync.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || forbesProductSync.i18n.error);
                            $button.prop('disabled', false).text(forbesProductSync.i18n.cancel || 'Cancel Sync');
                        }
                    },
                    error: function() {
                        alert(forbesProductSync.i18n.error);
                        $button.prop('disabled', false).text(forbesProductSync.i18n.cancel || 'Cancel Sync');
                    }
                });
            }
        });
    }

    // --- Attribute Sync Page Logic ---
    var $attributeSyncPage = $('.forbes-product-sync-main:has(#attribute-comparison-results)');
    var $attributeSyncMainPage = $('.forbes-product-sync-main:not(:has(#attribute-comparison-results))');
    
    // Handle main attribute sync page (summary dashboard)
    if ($attributeSyncMainPage.length) {
        var $syncSummaryDashboard = $('#sync-summary-dashboard');
        var $attributeStatusTable = $('#attribute-status-table');
        var $loadSummaryBtn = $('#load-sync-summary');
        
        // Load sync summary data on page load
        $(document).ready(function() {
            loadSyncSummary();
            
            // Refresh button click handler
            if ($loadSummaryBtn.length) {
                $loadSummaryBtn.on('click', function() {
                    loadSyncSummary();
                });
            }
        });
        
        // Function to load sync summary data via AJAX
        function loadSyncSummary() {
            if (!$syncSummaryDashboard.length) {
                return;
            }
            
            // Show loading state
            $syncSummaryDashboard.addClass('loading');
            $('.summary-loading-indicator').show();
            $('#sync-error-message').hide();
            
            $.ajax({
                url: forbesProductSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'forbes_product_sync_get_sync_summary',
                    nonce: forbesProductSync.nonce
                },
                success: function(response) {
                    $syncSummaryDashboard.removeClass('loading');
                    $('.summary-loading-indicator').hide();
                    
                    if (response.success) {
                        var data = response.data;
                        var stats = data.stats;
                        
                        // Update stats in the dashboard
                        $('.new-attributes-count').text(stats.attributes ? stats.attributes.new : 0);
                        $('.modified-attributes-count').text(stats.attributes ? stats.attributes.modified : 0);
                        $('.new-terms-count').text(stats.terms ? stats.terms.new : 0);
                        $('.modified-terms-count').text(stats.terms ? stats.terms.modified : 0);
                        $('.total-differences-count').text(stats.total_differences || 0);
                        
                        // Update last sync time
                        if (data.last_update) {
                            $('.last-sync-time').text(data.last_update);
                        } else {
                            $('.last-sync-time').text('Never');
                        }
                        
                        // Populate attribute status table if it exists
                        if ($attributeStatusTable.length && data.attributes_status) {
                            var tableHtml = '';
                            
                            // Check if attributes_status is an array and has items
                            if (Array.isArray(data.attributes_status) && data.attributes_status.length > 0) {
                                $.each(data.attributes_status, function(i, attr) {
                                    var statusClass = '';
                                    var statusText = '';
                                    
                                    switch(attr.status) {
                                        case 'new':
                                            statusClass = 'status-info';
                                            statusText = 'New';
                                            break;
                                        case 'updated':
                                            statusClass = 'status-warning';
                                            statusText = 'Modified';
                                            break;
                                        case 'ok':
                                            statusClass = 'status-success';
                                            statusText = 'Synced';
                                            break;
                                        case 'missing_local':
                                            statusClass = 'status-error';
                                            statusText = 'Missing';
                                            break;
                                        default:
                                            statusClass = 'status-none';
                                            statusText = 'Unknown';
                                    }
                                    
                                    tableHtml += '<tr>' +
                                        '<td>' + attr.name + '</td>' +
                                        '<td><span class="status-badge ' + statusClass + '">' + statusText + '</span></td>' +
                                        '<td>' + attr.terms_count + '</td>' +
                                        '<td>' + 
                                            (attr.terms_new > 0 ? '<span class="status-badge status-info">' + attr.terms_new + ' new</span> ' : '') +
                                            (attr.terms_modified > 0 ? '<span class="status-badge status-warning">' + attr.terms_modified + ' modified</span> ' : '') +
                                            (attr.terms_ok > 0 ? '<span class="status-badge status-success">' + attr.terms_ok + ' synced</span> ' : '') +
                                        '</td>' +
                                    '</tr>';
                                });
                            } else {
                                tableHtml = '<tr><td colspan="4">No attribute data available</td></tr>';
                            }
                            
                            $attributeStatusTable.find('tbody').html(tableHtml);
                        }
                        
                        // Hide any error messages
                        $('#sync-summary-error').hide();
                        $('#sync-error-message').hide();
                    } else {
                        // Show error message
                        var errorMsg = response.data && response.data.message ? response.data.message : 'Error loading sync summary data.';
                        
                        // Use any stats that were returned, even in an error response
                        if (response.data && response.data.stats) {
                            var stats = response.data.stats;
                            $('.new-attributes-count').text(stats.attributes ? stats.attributes.new : 0);
                            $('.modified-attributes-count').text(stats.attributes ? stats.attributes.modified : 0);
                            $('.new-terms-count').text(stats.terms ? stats.terms.new : 0);
                            $('.modified-terms-count').text(stats.terms ? stats.terms.modified : 0);
                            $('.total-differences-count').text(stats.total_differences || 0);
                        }
                        
                        // If the error is related to timeouts, offer a refresh button
                        if (response.data && response.data.error_code && 
                            (response.data.error_code === 'http_request_failed' || response.data.error_code === 'too_many_errors')) {
                            
                            // Display error in the new error section with a retry button
                            $('#error-message-text').text(errorMsg);
                            $('#sync-error-message').show().append(
                                '<p><button type="button" id="retry-attributes-fetch" class="button button-secondary">Retry Fetch Attributes</button></p>'
                            );
                            
                            // Add handler for retry button
                            $('#retry-attributes-fetch').on('click', function() {
                                // Force clear cache and retry
                                forceRefreshAttributeCache().then(function() {
                                    loadSyncSummary();
                                });
                            });
                        } else {
                            // Display standard error
                            $('#error-message-text').text(errorMsg);
                            $('#sync-error-message').show();
                        }
                        
                        $('#sync-summary-error').hide();
                    }
                },
                error: function(xhr, status, error) {
                    $syncSummaryDashboard.removeClass('loading');
                    $('.summary-loading-indicator').hide();
                    console.error('AJAX Error:', status, error);
                    
                    // Always show the error message section for AJAX errors
                    var errorMessage = 'Error connecting to the server';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMessage = xhr.responseJSON.data.message;
                    } else if (error) {
                        errorMessage += ': ' + error;
                    } else if (status) {
                        errorMessage += ': ' + status;
                    }
                    
                    // Display error message with retry button
                    $('#error-message-text').text(errorMessage);
                    $('#sync-error-message').show().append(
                        '<p><button type="button" id="retry-attributes-fetch" class="button button-secondary">Retry Fetch Attributes</button></p>'
                    );
                    
                    // Add handler for retry button
                    $('#retry-attributes-fetch').on('click', function() {
                        $(this).prop('disabled', true).text('Retrying...');
                        setTimeout(function() {
                            loadSyncSummary();
                        }, 1000);
                    });
                    
                    $('#sync-summary-error').hide();
                },
                timeout: 90000 // 90-second timeout for long-running attribute requests
            });
        }
        
        // Function to force refresh attributes cache
        function forceRefreshAttributeCache() {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: forbesProductSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'forbes_product_sync_force_refresh_attributes',
                        nonce: forbesProductSync.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('Attribute cache refreshed successfully');
                            resolve(response);
                        } else {
                            console.error('Error refreshing attribute cache:', response.data.message);
                            reject(response.data.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error refreshing cache:', status, error);
                        reject(error);
                    }
                });
            });
        }
    }
    
    // Handle attribute comparison page
    if ($attributeSyncPage.length) {
        var $loadBtn = $('#load-attributes');
        var $refreshBtn = $('#refresh-cache-btn');
        var $forceRefreshBtn = $('#force-refresh-attributes');
        var $results = $('#attribute-comparison-results');
        var $loadingBar = $('#attribute-loading-bar');
        var $sidebar = $('#sync-sidebar');
        
        // Auto-load attributes when page loads if #attribute-comparison-results exists
        // This implies we are on the compare view.
        $(document).ready(function() {
            if ($results.length) { // Only auto-load if the results container is on the page
                setTimeout(function() {
                    loadAttributes(false);
                }, 300);
            }
        });
        
        // Bind these buttons if they exist.
        if ($loadBtn.length) {
            $loadBtn.on('click', function() {
                loadAttributes(false);
            });
        }
        if ($refreshBtn.length) {
            $refreshBtn.on('click', function() {
                loadAttributes(true);
            });
        }
        
        // Force refresh WooCommerce attributes (remains as is)
        $forceRefreshBtn.on('click', function() {
            var $button = $(this);
            $button.prop('disabled', true).text('Refreshing...');
            
            // Show a notification that refresh is happening
            $('#sidebar-notifications').html('<div class="notice notice-info"><p>Refreshing data from source...</p></div>');
            
            $.ajax({
                url: forbesProductSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'forbes_product_sync_force_refresh_attributes',
                    nonce: forbesProductSync.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#sidebar-notifications').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        setTimeout(function() {
                            loadAttributes(true); // Reload attributes with refresh=true
                        }, 1000);
                    } else {
                        $('#sidebar-notifications').html('<div class="notice notice-error"><p>' + (response.data.message || 'Error refreshing attributes') + '</p></div>');
                        $button.prop('disabled', false).text('Force Refresh Cache');
                    }
                },
                error: function() {
                    $('#sidebar-notifications').html('<div class="notice notice-error"><p>Error refreshing attributes. Please try again.</p></div>');
                    $button.prop('disabled', false).text('Force Refresh Cache');
                }
            });
        });
        
        // Function to load attributes
        function loadAttributes(refreshCache) {
            if ($loadBtn.length) $loadBtn.prop('disabled', true);
            if ($refreshBtn.length) $refreshBtn.prop('disabled', true);
            if ($loadingBar.length) $loadingBar.removeClass('hidden');
            $results.html(''); // Clear previous results
            $('#sidebar-notifications').html('');
            
            $('#sidebar-notifications').html('<div class="notice notice-info"><p>' + (forbesProductSync.i18n.refreshingCache || 'Loading attribute data...') + '</p></div>');
            if ($sidebar.length) $sidebar.removeClass('hidden');
            
            $.ajax({
                url: forbesProductSync.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'forbes_product_sync_get_attribute_differences',
                    nonce: forbesProductSync.nonce,
                    refresh_cache: refreshCache ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        $results.html(response.data.html);
                        initAttributeTable(); // Initialize table interactions
                        
                        if (response.data.count && (response.data.count.total_differences > 0 || response.data.count.new_attributes > 0 || response.data.count.new_terms > 0) ) {
                            $('#sidebar-notifications').html('<div class="notice notice-info"><p>' + 
                                (response.data.count.total_differences || (response.data.count.new_attributes + response.data.count.new_terms)) + 
                                ' differences found. Review and select terms to sync.' +
                            '</p></div>');
                        } else {
                            $('#sidebar-notifications').html('<div class="notice notice-success"><p>No differences found or all attributes are in sync.</p></div>');
                        }
                    } else {
                        var errorMessage = response.data && response.data.message ? response.data.message : 'Failed to load attribute data.';
                        $results.html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
                        $('#sidebar-notifications').html('<div class="notice notice-error"><p>' + errorMessage + '</p></div>');
                    }
                    if ($loadBtn.length) $loadBtn.prop('disabled', false);
                    if ($refreshBtn.length) $refreshBtn.prop('disabled', false);
                    if ($loadingBar.length) $loadingBar.addClass('hidden');
                },
                error: function(xhr, status, error) {
                    $results.html('<div class="notice notice-error"><p>' + forbesProductSync.i18n.error + '</p></div>');
                    $('#sidebar-notifications').html('<div class="notice notice-error"><p>' + forbesProductSync.i18n.error + ': ' + error + '</p></div>');
                    if ($loadBtn.length) $loadBtn.prop('disabled', false);
                    if ($refreshBtn.length) $refreshBtn.prop('disabled', false);
                    if ($loadingBar.length) $loadingBar.addClass('hidden');
                }
            });
        }
        
        // Initialize attribute table interactions
        function initAttributeTable() {
            // Remove previous event handlers to prevent duplicates
            $(document).off('change', '#select-all-attributes');
            $(document).off('change', '.select-attribute-terms');
            $(document).off('change', '.sync-term-checkbox');
            $(document).off('click', '.attribute-toggle');
            $(document).off('click', '.term-remove'); // For items in sidebar
            $('#sidebar-sync-metadata').off('change');
            $('#sidebar-handle-conflicts').off('change');

            // Initialize attribute toggle buttons
            initAttributeToggle();
            
            // Global "Select all" checkbox
            $('#select-all-attributes').on('change', function() {
                var isChecked = $(this).is(':checked');
                $('.select-attribute-terms, .sync-term-checkbox').prop('checked', isChecked).prop('indeterminate', false);
                
                $('.select-attribute-terms').each(function() {
                    var attrSlug = $(this).data('attr-slug');
                    // Ensure hidden input for "all" flag exists and update it
                    $('input.attr-all-selected[name="selected_attributes[' + attrSlug + '][all]"]').val(isChecked ? '1' : '0');
                });

                updateSelectedTerms();
            });
            
            // Per-attribute "Select all" checkbox functionality
            $(document).on('change', '.select-attribute-terms', function() {
                var isChecked = $(this).is(':checked');
                var attrId = $(this).data('attr-id');
                var attrSlug = $(this).data('attr-slug');
                
                $('tr.attribute-group-term[data-attr-id="' + attrId + '"] .sync-term-checkbox').prop('checked', isChecked);
                $('input.attr-all-selected[name="selected_attributes[' + attrSlug + '][all]"]').val(isChecked ? '1' : '0');
                
                $(this).prop('indeterminate', false); // No longer indeterminate if manually clicked
                updateSelectedTerms();
                updateGlobalSelectAllStatus();
            });
            
            // Individual term checkboxes
            $(document).on('change', '.sync-term-checkbox', function() {
                var attrId = $(this).data('attr-id');
                updateAttributeSelectAllStatus(attrId); // Update its parent attribute's select-all
                updateSelectedTerms();
                // updateGlobalSelectAllStatus() is called by updateAttributeSelectAllStatus
            });

            // Term removal from sidebar
            $(document).on('click', '.term-remove', function() {
                var $item = $(this).closest('li');
                var attr = $item.data('attr');
                var term = $item.data('term');
                
                var $checkbox = $('.sync-term-checkbox[data-attr="' + attr + '"][data-term="' + term + '"]');
                $checkbox.prop('checked', false);
                
                var attrId = $checkbox.data('attr-id');
                if (attrId !== undefined) {
                    updateAttributeSelectAllStatus(attrId);
                }
                updateSelectedTerms();
            });

            // Sidebar sync options
            $('#sidebar-sync-metadata').on('change', function() {
                $('input[name="sync_metadata"]').val($(this).is(':checked') ? '1' : '0');
            });
            
            $('#sidebar-handle-conflicts').on('change', function() {
                $('input[name="handle_conflicts"]').val($(this).is(':checked') ? '1' : '0');
            });
            
            // Initial state updates
            updateAllAttributeSelectAllStatuses();
            updateSelectedTerms(); // This will also update button state and global select all via helpers
        }
        
        // Helper function to update the "Select all" checkbox for an attribute
        function updateAttributeSelectAllStatus(attrId) {
            var $selectAllCheckbox = $('.select-attribute-terms[data-attr-id="' + attrId + '"]');
            if (!$selectAllCheckbox.length) return;

            var $termCheckboxes = $('tr.attribute-group-term[data-attr-id="' + attrId + '"] .sync-term-checkbox');
            var totalTerms = $termCheckboxes.length;
            var checkedTerms = $termCheckboxes.filter(':checked').length;
            
            if (totalTerms === 0) { // No terms for this attribute
                $selectAllCheckbox.prop('checked', false);
                $selectAllCheckbox.prop('indeterminate', false);
            } else if (checkedTerms === 0) {
                $selectAllCheckbox.prop('checked', false);
                $selectAllCheckbox.prop('indeterminate', false);
            } else if (checkedTerms === totalTerms) {
                $selectAllCheckbox.prop('checked', true);
                $selectAllCheckbox.prop('indeterminate', false);
            } else {
                $selectAllCheckbox.prop('checked', false); // Or keep checked if you prefer then indeterminate
                $selectAllCheckbox.prop('indeterminate', true);
            }
            updateGlobalSelectAllStatus();
        }

        function updateAllAttributeSelectAllStatuses() {
            $('.select-attribute-terms').each(function() {
                var attrId = $(this).data('attr-id');
                if (attrId !== undefined) {
                    updateAttributeSelectAllStatus(attrId);
                }
            });
        }

        function updateGlobalSelectAllStatus() {
            var $globalSelectAll = $('#select-all-attributes');
            if (!$globalSelectAll.length) return;

            var $allAttrCheckboxes = $('.select-attribute-terms');
            var totalAttrs = $allAttrCheckboxes.length;
            if (totalAttrs === 0) {
                 $globalSelectAll.prop('checked', false).prop('indeterminate', false);
                 return;
            }

            var checkedAttrs = $allAttrCheckboxes.filter(function() { return $(this).prop('checked') && !$(this).prop('indeterminate'); }).length;
            var indeterminateAttrs = $allAttrCheckboxes.filter(function() { return $(this).prop('indeterminate'); }).length;
            var allTermsCheckboxes = $('.sync-term-checkbox');
            var allTermsChecked = allTermsCheckboxes.filter(':checked').length;


            if (indeterminateAttrs > 0 || (allTermsChecked > 0 && allTermsChecked < allTermsCheckboxes.length && checkedAttrs < totalAttrs) ) {
                $globalSelectAll.prop('indeterminate', true);
                $globalSelectAll.prop('checked', false);
            } else if (checkedAttrs === totalAttrs && allTermsChecked === allTermsCheckboxes.length && totalAttrs > 0) { // All attributes fully checked
                $globalSelectAll.prop('indeterminate', false);
                $globalSelectAll.prop('checked', true);
            } else { // Handles case where all are unchecked, or partially checked but not enough for global check
                $globalSelectAll.prop('indeterminate', false);
                $globalSelectAll.prop('checked', false);
                if (allTermsChecked > 0 && allTermsChecked === allTermsCheckboxes.length && totalAttrs === 0) { // Edge case: only terms, no distinct attributes shown with .select-attribute-terms
                     $globalSelectAll.prop('checked', true);
                } else if (allTermsChecked === 0) {
                    $globalSelectAll.prop('checked', false);
                }

            }
        }
        
        // Initialize attribute toggle functionality
        function initAttributeToggle() {
            $(document).on('click', '.attribute-toggle', function() {
                var $headerRow = $(this).closest('tr.attribute-group-header');
                var attrId = $headerRow.data('attr-id');
                var $termRows = $('tr.attribute-group-term[data-attr-id="' + attrId + '"]');
                
                if ($termRows.first().is(':visible')) {
                    $termRows.hide();
                    $(this).removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
                } else {
                    $termRows.show();
                    $(this).removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
                }
            });
        }
        
        // Update selected terms count and list
        function updateSelectedTerms() {
            var selectedTermsData = [];
            var $selectedList = $('.selected-terms-list');
            var $noTermsMsg = $('.no-terms-message');
            var $formInputs = $('#selected-terms-form-inputs');
            
            // Clear previous form inputs
            $formInputs.empty();
            
            $('.sync-term-checkbox:checked').each(function() {
                var $checkbox = $(this);
                // Ensure data attributes are present on the checkbox from PHP generation
                var termName = $checkbox.data('term-name') || $checkbox.closest('tr').find('.term-name-display').text().trim();
                var attrName = $checkbox.data('attr-name') || $checkbox.closest('tr.attribute-group-term').prevAll('tr.attribute-group-header:first').find('strong').text().trim();
                var attr = $checkbox.data('attr');
                var term = $checkbox.data('term');

                selectedTermsData.push({
                    attr: attr, // slug of attribute
                    term: term, // slug of term
                    termName: termName,
                    attrName: attrName
                });
                
                // Add hidden form input for each selected term
                $formInputs.append('<input type="hidden" name="selected_terms[' + attr + '][' + term + ']" value="1">');
            });
            
            $('#selected-terms-count, #selected-count').text(selectedTermsData.length);
            $('#apply-changes-btn').prop('disabled', selectedTermsData.length === 0);
            
            $selectedList.empty();
            if (selectedTermsData.length > 0) {
                if($noTermsMsg && $noTermsMsg.length) $noTermsMsg.hide();
                $.each(selectedTermsData, function(i, term) {
                    $selectedList.append(
                        '<li data-attr="' + term.attr + '" data-term="' + term.term + '">' +
                        '<span class="term-name">' + term.termName + '</span>' +
                        '<span class="attr-name">(' + term.attrName + ')</span>' +
                        '<span class="term-remove dashicons dashicons-no-alt" title="Remove"></span>' +
                        '</li>'
                    );
                });
            } else {
                if($noTermsMsg && $noTermsMsg.length) $noTermsMsg.show();
                
                // If no terms selected, show a message in the list
                $selectedList.append('<li class="no-terms-selected">No terms selected</li>');
            }
        }
        
        // Scroll to top button
        $('#scroll-to-top').on('click', function() {
            $('html, body').animate({ scrollTop: 0 }, 'fast');
            return false;
        });
    }

    // --- Batch Processing Logic (Generic) ---
    var $batchProcessForm = $('#batch-process-form'); // Re-declare for clarity, might need adjustment if IDs clash
    if ($batchProcessForm.length) {
        var batchProcessing = {
            isProcessing: false,
            progressBar: $('#batch-progress-bar'),
            progressText: $('#batch-progress-text'),
            startBtn: $('#batch-start-btn'),
            cancelBtn: $('#batch-cancel-btn'),
            statusContainer: $('#batch-status-container'),
            noticesContainer: $('#batch-notices'),
            
            init: function() {
                this.startBtn.on('click', this.startProcessing.bind(this));
                this.cancelBtn.on('click', this.cancelProcessing.bind(this));
                this.checkStatus(); // Check if already processing on page load
            },
            
            checkStatus: function() {
                 $.ajax({
                    url: forbesProductSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'forbes_product_sync_check_status', // Assume a general status check endpoint
                        nonce: forbesProductSync.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.is_processing) {
                            this.isProcessing = true;
                            this.updateUI(response.data.progress);
                            this.processNextBatch(); // Continue processing if found
                        }
                    }.bind(this)
                });
            },
            
            startProcessing: function(e) {
                e.preventDefault();
                if (this.isProcessing) return;
                if (!confirm(forbesProductSync.i18n.confirmSync || 'Are you sure?')) return;
                
                this.isProcessing = true;
                this.startBtn.prop('disabled', true);
                this.cancelBtn.prop('disabled', false);
                this.statusContainer.removeClass('hidden');
                this.noticesContainer.html(''); // Clear previous notices
                
                // Determine type and initiate
                var batchType = $batchProcessForm.data('type'); // e.g., 'attributes' or 'products'
                var initAction = 'forbes_product_sync_init_' + batchType; // Construct action name
                
                $.ajax({
                    url: forbesProductSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: initAction,
                        nonce: forbesProductSync.nonce
                        // Add any other needed data for initiation (e.g., dry_run)
                    },
                    success: function(response) {
                        if (response.success) {
                            this.updateUI(response.data.progress);
                            this.processNextBatch();
                        } else {
                            this.showError(response.data.message);
                            this.resetUI();
                        }
                    }.bind(this),
                    error: function() {
                        this.showError(forbesProductSync.i18n.error);
                        this.resetUI();
                    }.bind(this)
                });
            },
            
            processNextBatch: function() {
                if (!this.isProcessing) return;
                
                $.ajax({
                    url: forbesProductSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'forbes_product_sync_process_batch',
                        nonce: forbesProductSync.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.complete) {
                                this.completeProcessing(response.data);
                            } else {
                                this.updateUI(response.data.progress);
                                setTimeout(this.processNextBatch.bind(this), 500); // Process next batch
                            }
                        } else {
                            this.showError(response.data.message);
                            this.resetUI();
                        }
                    }.bind(this),
                    error: function() {
                        this.showError(forbesProductSync.i18n.error);
                        this.resetUI();
                    }.bind(this)
                });
            },
            
            cancelProcessing: function(e) {
                e.preventDefault();
                if (!this.isProcessing) return;
                if (!confirm(forbesProductSync.i18n.confirmCancel || 'Cancel sync?')) return;
                
                this.isProcessing = false; // Stop further batch requests
                this.cancelBtn.prop('disabled', true).text(forbesProductSync.i18n.cancelling || 'Cancelling...');
                
                $.ajax({
                    url: forbesProductSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'forbes_product_sync_cancel', // General cancel endpoint
                        nonce: forbesProductSync.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            this.showSuccess(response.data.message || forbesProductSync.i18n.canceled || 'Cancelled');
                        }
                        this.resetUI(true); // Reset UI but keep status hidden if cancelled
                    }.bind(this),
                    error: function() {
                         this.showError(forbesProductSync.i18n.error);
                         this.resetUI(true);
                    }.bind(this)
                });
            },
            
            completeProcessing: function(data) {
                this.isProcessing = false;
                this.startBtn.prop('disabled', false);
                this.cancelBtn.prop('disabled', true);
                var message = data.message || forbesProductSync.i18n.completed || 'Completed!';
                this.showSuccess(message);
                this.updateUI({ percent: 100, message: forbesProductSync.i18n.completed || 'Completed!' });
                // Optional: Reload page or update final stats
                // setTimeout(function() { location.reload(); }, 2000);
            },
            
            updateUI: function(progress) {
                if (!progress) return;
                var percent = progress.percent || 0;
                this.progressBar.css('width', percent + '%');
                this.progressText.text(progress.message || '');
            },
            
            resetUI: function(keepStatusHidden = false) {
                this.isProcessing = false;
                this.startBtn.prop('disabled', false).text(forbesProductSync.i18n.startSync || 'Start Sync');
                this.cancelBtn.prop('disabled', true).text(forbesProductSync.i18n.cancel || 'Cancel');
                if (!keepStatusHidden) {
                     this.statusContainer.addClass('hidden');
                }
                this.progressBar.css('width', '0%');
                this.progressText.text('');
            },
            
            showError: function(message) {
                 this.noticesContainer.html('<div class="notice notice-error"><p>' + (message || forbesProductSync.i18n.error) + '</p></div>');
            },
            
            showSuccess: function(message) {
                 this.noticesContainer.html('<div class="notice notice-success"><p>' + message + '</p></div>');
            }
        };
        
        if ($batchProcessForm.length > 0) {
            batchProcessing.init();
        }
    } // End Batch Processing Logic

}); 