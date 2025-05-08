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
    if ($attributeSyncPage.length) {
        var $loadBtn = $('#load-attributes');
        var $refreshBtn = $('#refresh-cache-btn');
        var $results = $('#attribute-comparison-results');
        var $loadingBar = $('#attribute-loading-bar');
        var $sidebar = $('#sync-sidebar');
        
        // Load attributes
        $loadBtn.on('click', function() {
            loadAttributes(false);
        });
        
        // Refresh cache and load attributes
        $refreshBtn.on('click', function() {
            loadAttributes(true);
        });
        
        // Function to load attributes
        function loadAttributes(refreshCache) {
            $loadBtn.prop('disabled', true);
            $refreshBtn.prop('disabled', true);
            $loadingBar.removeClass('hidden');
            $results.html('');
            $sidebar.addClass('hidden');
            
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
                        initAttributeTable();
                        
                        // Show sidebar if there are differences
                        if (response.data.count && (response.data.count.total_differences > 0)) {
                            $sidebar.removeClass('hidden');
                        }
                    } else {
                        $results.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                    }
                    $loadBtn.prop('disabled', false);
                    $refreshBtn.prop('disabled', false);
                    $loadingBar.addClass('hidden');
                },
                error: function() {
                    $results.html('<div class="notice notice-error"><p>' + forbesProductSync.i18n.error + '</p></div>');
                    $loadBtn.prop('disabled', false);
                    $refreshBtn.prop('disabled', false);
                    $loadingBar.addClass('hidden');
                }
            });
        }
        
        // Initialize attribute table interactions
        function initAttributeTable() {
            // Remove previous event handlers to prevent duplicates
            $(document).off('change', '.select-attribute-terms');
            
            // Per-attribute "Select all" checkbox functionality
            $(document).on('change', '.select-attribute-terms', function() {
                var isChecked = $(this).is(':checked');
                var attrId = $(this).data('attr-id');
                
                // Select only terms within this attribute group
                $('tr.attribute-group-term[data-attr-id="' + attrId + '"] .sync-term-checkbox').prop('checked', isChecked);
                
                updateSelectedTerms();
            });
            
            // Individual term checkboxes
            $(document).on('change', '.sync-term-checkbox', function() {
                var $checkbox = $(this);
                var attrId = $checkbox.data('attr-id');
                
                // Update the "Select all" checkbox for this attribute
                updateAttributeSelectAll(attrId);
                
                updateSelectedTerms();
            });
            
            // Initialize attribute toggle buttons
            initAttributeToggle();
            
            // Update selected terms in sidebar
            updateSelectedTerms();
        }
        
        // Helper function to update the "Select all" checkbox for an attribute
        function updateAttributeSelectAll(attrId) {
            var $selectAllCheckbox = $('.select-attribute-terms[data-attr-id="' + attrId + '"]');
            if ($selectAllCheckbox.length) {
                var $checkboxes = $('tr.attribute-group-term[data-attr-id="' + attrId + '"] .sync-term-checkbox');
                var $checkedBoxes = $checkboxes.filter(':checked');
                
                // If all checkboxes are checked, check the "Select all" checkbox
                // If some are checked, set to indeterminate state
                // If none are checked, uncheck the "Select all" checkbox
                if ($checkedBoxes.length === 0) {
                    $selectAllCheckbox.prop('checked', false);
                    $selectAllCheckbox.prop('indeterminate', false);
                } else if ($checkedBoxes.length === $checkboxes.length) {
                    $selectAllCheckbox.prop('checked', true);
                    $selectAllCheckbox.prop('indeterminate', false);
                } else {
                    $selectAllCheckbox.prop('checked', false);
                    $selectAllCheckbox.prop('indeterminate', true);
                }
            }
        }
        
        // Initialize attribute toggle functionality
        function initAttributeToggle() {
            // Toggle attribute terms visibility
            $(document).on('click', '.attribute-toggle', function() {
                var $headerRow = $(this).closest('tr.attribute-group-header');
                var attrId = $headerRow.data('attr-id');
                var $termRows = $('tr.attribute-group-term[data-attr-id="' + attrId + '"]');
                
                if ($termRows.first().is(':visible')) {
                    // Hide terms
                    $termRows.hide();
                    $(this).removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
                } else {
                    // Show terms
                    $termRows.show();
                    $(this).removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
                }
            });
        }
        
        // Update selected terms count and list
        function updateSelectedTerms() {
            var selectedTerms = [];
            var $selectedList = $('.selected-terms-list');
            var $noTermsMsg = $('.no-terms-message');
            
            // Get all selected terms
            $('.sync-term-checkbox:checked').each(function() {
                var $checkbox = $(this);
                var $row = $checkbox.closest('tr');
                var termName = $row.find('.term-name').text().trim();
                var attrName = $checkbox.data('attr-name');
                
                selectedTerms.push({
                    attr: $checkbox.data('attr'),
                    term: $checkbox.data('term'),
                    termName: termName,
                    attrName: attrName
                });
            });
            
            // Update count
            $('#selected-terms-count').text(selectedTerms.length);
            
            // Enable/disable apply button
            $('#sidebar-apply-btn').prop('disabled', selectedTerms.length === 0);
            
            // Update selected terms list
            $selectedList.empty();
            
            if (selectedTerms.length > 0) {
                $noTermsMsg.hide();
                
                $.each(selectedTerms, function(i, term) {
                    $selectedList.append(
                        '<li data-attr="' + term.attr + '" data-term="' + term.term + '">' +
                        '<span class="term-name">' + term.termName + '</span>' +
                        '<span class="attr-name">(' + term.attrName + ')</span>' +
                        '<span class="term-remove dashicons dashicons-no-alt"></span>' +
                        '</li>'
                    );
                });
            } else {
                $noTermsMsg.show();
            }
            
            // Term removal from sidebar
            $('.term-remove').on('click', function() {
                var $item = $(this).closest('li');
                var attr = $item.data('attr');
                var term = $item.data('term');
                
                // Uncheck the corresponding checkbox
                $('.sync-term-checkbox[data-attr="' + attr + '"][data-term="' + term + '"]').prop('checked', false);
                
                // Update selected terms
                updateSelectedTerms();
            });
        }
        
        // Apply changes button
        $('#sidebar-apply-btn').on('click', function() {
            if (confirm(forbesProductSync.i18n.confirmApply || 'Are you sure you want to apply these changes?')) {
                var selectedTerms = [];
                
                // Get all selected terms
                $('.sync-term-checkbox:checked').each(function() {
                    var $checkbox = $(this);
                    
                    selectedTerms.push({
                        attribute: $checkbox.data('attr'),
                        term: $checkbox.data('term'),
                        term_name: $checkbox.data('term-name')
                    });
                });
                
                if (selectedTerms.length === 0) {
                    alert(forbesProductSync.i18n.noChangesSelected || 'Please select at least one change to apply.');
                    return;
                }
                
                // Prepare sync options
                var syncMetadata = $('#sidebar-sync-metadata').is(':checked');
                var handleConflicts = $('#sidebar-handle-conflicts').is(':checked');
                
                // Update form inputs (if form exists)
                $('#forbes-product-sync-form input[name="sync_metadata"]').val(syncMetadata ? 1 : 0);
                $('#forbes-product-sync-form input[name="handle_conflicts"]').val(handleConflicts ? 1 : 0);
                
                // Submit form through AJAX
                $.ajax({
                    url: forbesProductSync.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'forbes_product_sync_process_attributes',
                        nonce: forbesProductSync.nonce,
                        terms: selectedTerms,
                        sync_metadata: syncMetadata ? 1 : 0,
                        handle_conflicts: handleConflicts ? 1 : 0
                    },
                    beforeSend: function() {
                        $('#sidebar-apply-btn').prop('disabled', true).text(forbesProductSync.i18n.processing || 'Processing...');
                        $results.prepend('<div class="notice notice-info">' + (forbesProductSync.i18n.processing || 'Processing...') + '</div>');
                    },
                    success: function(response) {
                        if (response.success) {
                            $results.prepend('<div class="notice notice-success">' + response.data.message + '</div>');
                            
                            // Update UI for synced terms
                            $.each(selectedTerms, function(i, term) {
                                var $checkbox = $('.sync-term-checkbox[data-attr="' + term.attribute + '"][data-term="' + term.term + '"]');
                                var $row = $checkbox.closest('tr');
                                
                                $row.addClass('synced');
                                $row.find('.status').text(forbesProductSync.i18n.synced || 'Synced');
                                $checkbox.prop('checked', false);
                            });
                            
                            // Update selected terms
                            updateSelectedTerms();
                        } else {
                            $results.prepend('<div class="notice notice-error">' + response.data.message + '</div>');
                        }
                        
                        $('#sidebar-apply-btn').prop('disabled', false).text(forbesProductSync.i18n.startSync || 'Apply Changes');
                    },
                    error: function() {
                        $results.prepend('<div class="notice notice-error">' + forbesProductSync.i18n.error + '</div>');
                        $('#sidebar-apply-btn').prop('disabled', false).text(forbesProductSync.i18n.startSync || 'Apply Changes');
                    }
                });
            }
        });
    } // End Attribute Sync Page Logic

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