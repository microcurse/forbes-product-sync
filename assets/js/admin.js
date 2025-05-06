/**
 * Admin JavaScript
 * Handles button loading states for forms
 */
(function($) {
    'use strict';

    $(document).ready(function() {
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
    });
})(jQuery); 