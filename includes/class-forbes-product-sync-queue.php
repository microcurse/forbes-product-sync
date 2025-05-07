<?php
/**
 * Queue system for attribute synchronization
 *
 * @package Forbes_Product_Sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Forbes_Product_Sync_Queue {
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
     * Attributes handler instance
     *
     * @var Forbes_Product_Sync_Attributes
     */
    private $attributes;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Forbes_Product_Sync_Logger();
        $this->attributes = new Forbes_Product_Sync_Attributes();
    }

    /**
     * Initialize queue
     *
     * @param array $source_attributes
     * @param bool $dry_run
     * @return bool
     */
    public function initialize_queue($source_attributes, $dry_run = false) {
        $this->attributes->set_dry_run($dry_run);

        // Clear existing queue
        delete_option(self::QUEUE_OPTION);
        delete_option(self::QUEUE_STATUS_OPTION);

        // Initialize queue
        $queue = array(
            'items' => $source_attributes,
            'total' => count($source_attributes),
            'processed' => 0,
            'current_batch' => 0,
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
            'message' => 'Queue initialized',
            'last_updated' => current_time('mysql')
        ));

        return true;
    }

    /**
     * Process next batch
     *
     * @return array|false
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
        $results = $this->attributes->sync_attributes($batch);

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
                'Processed %d of %d items',
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
     * Complete queue
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
                'Completed processing %d items. Created: %d, Updated: %d, Skipped: %d, Errors: %d',
                $queue['total'],
                $queue['results']['created'],
                $queue['results']['updated'],
                $queue['results']['skipped'],
                $queue['results']['errors']
            )
        );

        update_option(self::QUEUE_STATUS_OPTION, array(
            'status' => 'completed',
            'message' => 'Queue processing completed',
            'last_updated' => current_time('mysql'),
            'results' => $queue['results']
        ));

        delete_option(self::QUEUE_OPTION);
    }

    /**
     * Get queue status
     *
     * @return array|false
     */
    public function get_status() {
        return get_option(self::QUEUE_STATUS_OPTION);
    }

    /**
     * Check if queue is processing
     *
     * @return bool
     */
    public function is_processing() {
        $status = $this->get_status();
        return $status && $status['status'] === 'processing';
    }

    /**
     * Check if queue is completed
     *
     * @return bool
     */
    public function is_completed() {
        $status = $this->get_status();
        return $status && $status['status'] === 'completed';
    }

    /**
     * Get queue progress
     *
     * @return array|false
     */
    public function get_progress() {
        $queue = get_option(self::QUEUE_OPTION);
        if (!$queue) {
            return false;
        }

        return array(
            'processed' => $queue['processed'],
            'total' => $queue['total'],
            'percentage' => ($queue['total'] > 0) ? round(($queue['processed'] / $queue['total']) * 100) : 0,
            'results' => $queue['results']
        );
    }
} 