/**
 * Forbes Product Sync Admin Scripts
 */
(function($) {
    'use strict';

    // Test Connection Button
    $(document).ready(function() {
        var $testButton = $('#fps-test-connection');
        var $resultContainer = $('#fps-test-connection-result');
        
        if (!$testButton.length) {
            return;
        }
        
        $testButton.on('click', function(e) {
            e.preventDefault();
            
            $testButton.prop('disabled', true);
            // Use fps_params for text
            $resultContainer.removeClass('success error').text(fps_params.testing_connection_text || 'Testing connection...'); 
            
            // Add spinner
            var $spinner = $('<span class="fps-spinner is-active"></span>');
            $resultContainer.after($spinner);
            
            $.ajax({
                url: fps_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'fps_test_connection',
                    nonce: fps_params.nonce
                },
                success: function(response) {
                    $spinner.remove();
                    $testButton.prop('disabled', false);
                    
                    if (response.success) {
                        // Use response.data.message if available (standardized in PHP)
                        $resultContainer.addClass('success').text(response.data.message || response.data); 
                    } else {
                        // Use response.data.message if available
                        var errorMsg = fps_params.test_error_prefix || 'Error: ';
                        errorMsg += (response.data && response.data.message) ? response.data.message : (response.data || (fps_params.unknown_error || 'Unknown error.'));
                        $resultContainer.addClass('error').text(errorMsg);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $spinner.remove();
                    $testButton.prop('disabled', false);
                    var errorMsg = (fps_params.test_error_prefix || 'Error: ') + errorThrown;
                    $resultContainer.addClass('error').text(errorMsg);
                }
            });
        });
    });

    // Sync Single Product Button
    $(document).ready(function() {
        $(document).on('click', '.fps-sync-product-button', function(e) {
            e.preventDefault();

            var $button = $(this);
            var productId = $button.data('product-id');
            var productName = $button.data('product-name') || (fps_params.default_product_name || 'Product');
            var $statusSpan = $button.siblings('.fps-sync-status');
            var originalButtonText = $button.text(); 

            var syncingText = (fps_params.syncing_product_text || 'Syncing "%s"...').replace('%s', productName);
            $button.prop('disabled', true).text(syncingText);
            $statusSpan.removeClass('success error partial-success').empty().show(); // Ensure it's visible
            
            var $spinner = $('<span class="fps-spinner is-active" style="margin-left: 5px; vertical-align: middle;"></span>');
            $button.after($spinner);

            $.ajax({
                url: fps_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'fps_sync_single_product',
                    nonce: fps_params.nonce, 
                    product_id: productId,
                    product_name: productName 
                },
                success: function(response) {
                    $spinner.remove();
                    $button.prop('disabled', false).text(originalButtonText);
                    var message = '';
                    if (response.success) {
                        message = response.data.message || (productName + (fps_params.sync_success_generic || ' synced successfully.'));
                        if (response.data.status_code === 'PARTIAL_SUCCESS') {
                            $statusSpan.addClass('partial-success');
                            message = (fps_params.partial_success_text || '"%s" synced with some issues. ').replace('%s', productName) + (response.data.message || '');
                            if (response.data.variation_error_details || response.data.image_error_count) {
                                message += ' ' + (fps_params.see_console_for_details || 'See browser console for details.');
                                console.warn('Partial success details for product ' + productName + ' (ID: ' + productId + '):', response.data);
                            }
                        } else {
                             $statusSpan.addClass('success');
                        }
                        $statusSpan.text(message);
                    } else {
                        message = (fps_params.sync_error_prefix || 'Error: ') + 
                                  (response.data && response.data.message ? response.data.message : (fps_params.unknown_error || 'Unknown error.'));
                        $statusSpan.addClass('error').text(message);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $spinner.remove();
                    $button.prop('disabled', false).text(originalButtonText);
                    var message = (fps_params.sync_error_prefix || 'Error: ') + errorThrown;
                    $statusSpan.addClass('error').text(message);
                }
            });
        });
    });

    // Sync Single Attribute Button
    $(document).ready(function() {
        $(document).on('click', '.fps-sync-attribute-button', function(e) {
            e.preventDefault();

            var $button = $(this);
            var attributeId = $button.data('attribute-id');
            var attributeName = $button.data('attribute-name') || (fps_params.default_attribute_name || 'Attribute');
            var $statusSpan = $button.siblings('.fps-sync-status');
            var originalButtonText = $button.text();

            $button.prop('disabled', true).text(fps_params.syncing_text || 'Syncing...');
            $statusSpan.removeClass('success error partial-success').empty().show(); // Ensure it's visible
            
            var $spinner = $('<span class="fps-spinner is-active" style="margin-left: 5px; vertical-align: middle;"></span>');
            $button.after($spinner);

            $.ajax({
                url: fps_params.ajax_url,
                type: 'POST',
                data: {
                    action: 'fps_sync_single_attribute',
                    nonce: fps_params.nonce, 
                    attribute_id: attributeId
                },
                success: function(response) {
                    $spinner.remove();
                    $button.prop('disabled', false).text(originalButtonText); // Use original text
                    var message = '';
                    if (response.success) {
                        message = response.data.message || (attributeName + (fps_params.sync_success_generic || ' synced successfully.'));
                        $statusSpan.addClass('success').text(message);
                    } else {
                        message = (fps_params.sync_error_prefix || 'Error: ') + 
                                  (response.data && response.data.message ? response.data.message : (fps_params.unknown_error || 'Unknown error.'));
                        $statusSpan.addClass('error').text(message);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $spinner.remove();
                    $button.prop('disabled', false).text(originalButtonText); // Use original text
                    var message = (fps_params.sync_error_prefix || 'Error: ') + errorThrown;
                    $statusSpan.addClass('error').text(errorMessage);
                }
            });
        });
    });

})(jQuery);
