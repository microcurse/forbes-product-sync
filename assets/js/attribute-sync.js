jQuery(document).ready(function($) {
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
      // Toggle all checkbox
      $('.select-all').on('change', function() {
          var isChecked = $(this).is(':checked');
          $(this).closest('table').find('tbody input[type="checkbox"]').prop('checked', isChecked);
          updateSelectedTerms();
      });
      
      // Individual term checkboxes
      $(document).on('change', '.sync-term-checkbox', function() {
          updateSelectedTerms();
      });
      
      // Update selected terms in sidebar
      updateSelectedTerms();
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
      if (confirm(forbesProductSync.i18n.confirmApply)) {
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
              alert(forbesProductSync.i18n.noChangesSelected);
              return;
          }
          
          // Prepare sync options
          var syncMetadata = $('#sidebar-sync-metadata').is(':checked');
          var handleConflicts = $('#sidebar-handle-conflicts').is(':checked');
          
          // Update form inputs
          $('input[name="sync_metadata"]').val(syncMetadata ? 1 : 0);
          $('input[name="handle_conflicts"]').val(handleConflicts ? 1 : 0);
          
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
                  $('#sidebar-apply-btn').prop('disabled', true).text(forbesProductSync.i18n.processing);
                  $results.prepend('<div class="notice notice-info">' + forbesProductSync.i18n.processing + '</div>');
              },
              success: function(response) {
                  if (response.success) {
                      $results.prepend('<div class="notice notice-success">' + response.data.message + '</div>');
                      
                      // Update UI for synced terms
                      $.each(selectedTerms, function(i, term) {
                          var $checkbox = $('.sync-term-checkbox[data-attr="' + term.attribute + '"][data-term="' + term.term + '"]');
                          var $row = $checkbox.closest('tr');
                          
                          $row.addClass('synced');
                          $row.find('.status').text(forbesProductSync.i18n.synced);
                          $checkbox.prop('checked', false);
                      });
                      
                      // Update selected terms
                      updateSelectedTerms();
                  } else {
                      $results.prepend('<div class="notice notice-error">' + response.data.message + '</div>');
                  }
                  
                  $('#sidebar-apply-btn').prop('disabled', false).text(forbesProductSync.i18n.startSync);
              },
              error: function() {
                  $results.prepend('<div class="notice notice-error">' + forbesProductSync.i18n.error + '</div>');
                  $('#sidebar-apply-btn').prop('disabled', false).text(forbesProductSync.i18n.startSync);
              }
          });
      }
  });
});