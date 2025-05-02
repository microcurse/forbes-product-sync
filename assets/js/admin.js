/**
 * Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Add loading state to sync button
        $('form').on('submit', function() {
            var $button = $(this).find('input[type="submit"]');
            $button.prop('disabled', true).val(forbesProductSync.loadingText || 'Syncing...');
        });

        // Handle AJAX sync if needed
        // This is a placeholder for future AJAX implementation
    });
})(jQuery); 