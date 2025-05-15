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
            $resultContainer.removeClass('success error').text(fps_params.test_connection_prompt);
            
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
                        $resultContainer.addClass('success').text(response.data);
                    } else {
                        $resultContainer.addClass('error').text(fps_params.test_error + response.data);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $spinner.remove();
                    $testButton.prop('disabled', false);
                    $resultContainer.addClass('error').text(fps_params.test_error + errorThrown);
                }
            });
        });
    });

})(jQuery);
