<?php
/**
 * Batch processing handler
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Forbes_Product_Sync_Batch_Processor
 * Handles batch processing of large amounts of data
 */
class Forbes_Product_Sync_Batch_Processor {
    /**
     * Queue option name
     */
    const QUEUE_OPTION = 'forbes_product_sync_queue';

    /**
     * Queue status option name
     */
    const QUEUE_STATUS_OPTION = 'forbes_product_sync_queue_status';

    /**
     * Batch size
     */
    const BATCH_SIZE = 50;
    
    /**
     * Logger instance
     *
     * @var Forbes_Product_Sync_Logger
     */
    private $logger;
    
    /**
     * Attributes handler
     *
     * @var Forbes_Product_Sync_Attributes_Handler
     */
    private $attributes_handler;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = Forbes_Product_Sync_Logger::instance();
        $this->attributes_handler = new Forbes_Product_Sync_Attributes_Handler();
    }
    
    /**
     * Initialize queue for attribute synchronization
     *
     * @param array $source_attributes Source attributes to process
     * @return bool Success
     */
    public function initialize_attributes_queue($source_attributes) {
        // Clear existing queue
        delete_option(self::QUEUE_OPTION);
        delete_option(self::QUEUE_STATUS_OPTION);

        // Initialize queue
        $queue = array(
            'items' => $source_attributes,
            'total' => count($source_attributes),
            'processed' => 0,
            'current_batch' => 0,
            'type' => 'attributes',
            'results' => array(
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0
            )
        );

        update_option(self::QUEUE_OPTION, $queue);
        update_option(self::QUEUE_STATUS_OPTION, array(
            'status' => 'initialized',
            'message' => sprintf(
                __('Queue initialized with %d attributes', 'forbes-product-sync'),
                count($source_attributes)
            ),
            'last_updated' => current_time('mysql')
        ));

        return true;
    }
    
    /**
     * Initialize queue for product synchronization
     *
     * @param array $source_products Source products to process
     * @return bool Success
     */
    public function initialize_products_queue($source_products) {
        // Clear existing queue
        delete_option(self::QUEUE_OPTION);
        delete_option(self::QUEUE_STATUS_OPTION);

        // Initialize queue
        $queue = array(
            'items' => $source_products,
            'total' => count($source_products),
            'processed' => 0,
            'current_batch' => 0,
            'type' => 'products',
            'results' => array(
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0
            )
        );

        update_option(self::QUEUE_OPTION, $queue);
        update_option(self::QUEUE_STATUS_OPTION, array(
            'status' => 'initialized',
            'message' => sprintf(
                __('Queue initialized with %d products', 'forbes-product-sync'),
                count($source_products)
            ),
            'last_updated' => current_time('mysql')
        ));

        return true;
    }
    
    /**
     * Process next batch
     *
     * @return array|false Batch processing results or false if queue is complete
     */
    public function process_next_batch() {
        $queue = get_option(self::QUEUE_OPTION);
        if (!$queue) {
            return false;
        }

        $start = $queue['current_batch'] * self::BATCH_SIZE;
        $batch = array_slice($queue['items'], $start, self::BATCH_SIZE);

        if (empty($batch)) {
            $this->complete_queue();
            return false;
        }

        // Process batch
        if ($queue['type'] === 'attributes') {
            $results = $this->attributes_handler->sync_attributes($batch);
        } else {
            // For future implementation
            $results = array(
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0
            );
        }

        // Update queue
        $queue['processed'] += count($batch);
        $queue['current_batch']++;
        $queue['results']['created'] += $results['created'];
        $queue['results']['updated'] += $results['updated'];
        $queue['results']['skipped'] += $results['skipped'];
        $queue['results']['errors'] += $results['errors'];

        update_option(self::QUEUE_OPTION, $queue);
        update_option(self::QUEUE_STATUS_OPTION, array(
            'status' => 'processing',
            'message' => sprintf(
                __('Processed %d of %d items', 'forbes-product-sync'),
                $queue['processed'],
                $queue['total']
            ),
            'last_updated' => current_time('mysql')
        ));

        return array(
            'processed' => $queue['processed'],
            'total' => $queue['total'],
            'current_batch' => $queue['current_batch'],
            'results' => $results
        );
    }
    
    /**
     * Complete queue processing
     *
     * @return void
     */
    private function complete_queue() {
        $queue = get_option(self::QUEUE_OPTION);
        if (!$queue) {
            return;
        }

        $this->logger->log_sync(
            'Queue Complete',
            'success',
            sprintf(
                __('Completed processing %d items. Created: %d, Updated: %d, Skipped: %d, Errors: %d', 'forbes-product-sync'),
                $queue['total'],
                $queue['results']['created'],
                $queue['results']['updated'],
                $queue['results']['skipped'],
                $queue['results']['errors']
            )
        );

        update_option(self::QUEUE_STATUS_OPTION, array(
            'status' => 'completed',
            'message' => __('Queue processing completed', 'forbes-product-sync'),
            'last_updated' => current_time('mysql'),
            'results' => $queue['results']
        ));

        delete_option(self::QUEUE_OPTION);
    }
    
    /**
     * Get queue status
     *
     * @return array|false Queue status or false if no queue exists
     */
    public function get_status() {
        return get_option(self::QUEUE_STATUS_OPTION);
    }
    
    /**
     * Check if queue is processing
     *
     * @return bool Processing status
     */
    public function is_processing() {
        $status = $this->get_status();
        return $status && $status['status'] === 'processing';
    }
    
    /**
     * Check if queue is completed
     *
     * @return bool Completion status
     */
    public function is_completed() {
        $status = $this->get_status();
        return $status && $status['status'] === 'completed';
    }
    
    /**
     * Get queue progress
     *
     * @return array Progress information
     */
    public function get_progress() {
        $queue = get_option(self::QUEUE_OPTION);
        $status = $this->get_status();
        
        if (!$queue || !$status) {
            return array(
                'total' => 0,
                'processed' => 0,
                'percent' => 0,
                'status' => 'idle',
                'message' => __('No active queue', 'forbes-product-sync'),
                'results' => array()
            );
        }
        
        return array(
            'total' => $queue['total'],
            'processed' => $queue['processed'],
            'percent' => $queue['total'] > 0 ? floor(($queue['processed'] / $queue['total']) * 100) : 0,
            'status' => $status['status'],
            'message' => $status['message'],
            'results' => $queue['results']
        );
    }
    
    /**
     * Cancel current queue
     *
     * @return bool Success
     */
    public function cancel_queue() {
        delete_option(self::QUEUE_OPTION);
        update_option(self::QUEUE_STATUS_OPTION, array(
            'status' => 'cancelled',
            'message' => __('Queue processing cancelled', 'forbes-product-sync'),
            'last_updated' => current_time('mysql')
        ));
        
        return true;
    }
} 