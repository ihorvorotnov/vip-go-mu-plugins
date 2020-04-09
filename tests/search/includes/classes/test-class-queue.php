<?php

namespace Automattic\VIP\Search;

class Queue_Test extends \WP_UnitTestCase {
	public function setUp() {
		global $wpdb;

		wp_cache_flush();

		if ( ! defined( 'VIP_SEARCH_ENABLE_ASYNC_INDEXING' ) ) {
			define( 'VIP_SEARCH_ENABLE_ASYNC_INDEXING', true );
		}

		require_once __DIR__ . '/../../../../search/search.php';

		$this->es = \Automattic\VIP\Search\Search::instance();

		$this->queue = $this->es->queue;

		$this->queue->schema->prepare_table();

		$this->queue->empty_queue();
	}

	public function test_deduplication_of_repeat_indexing() {
		global $wpdb;

		$table_name = $this->queue->schema->get_table_name();

		$objects = array(
			array(
				'id' => 1,
				'type' => 'post',
			),
			array(
				'id' => 1,
				'type' => 'user',
			),
		);

		// How many times to requeue each object
		$times = 10;

		foreach ( $objects as $object ) {
			for ( $i = 0; $i < $times; $i++ ) {
				$this->queue->queue_object( $object['id'], $object['type'] );
			}

			// Now it should only exist once
			$results = $wpdb->get_results( 
				$wpdb->prepare( 
					"SELECT * FROM `{$table_name}` WHERE `object_id` = %d AND `object_type` = %s AND `status` = 'queued'", // Cannot prepare table name. @codingStandardsIgnoreLine
					$object['id'],
					$object['type']
				)
			);

			$this->assertCount( 1, $results );
		}
	}

	public function test_rate_limiting_of_repeat_indexing() {
		global $wpdb;

		$table_name = $this->queue->schema->get_table_name();

		$objects = array(
			array(
				'id' => 1,
				'type' => 'post',
			),
			array(
				'id' => 1,
				'type' => 'user',
			),
		);

		foreach ( $objects as $object ) {
			$now = time();

			// Insert a job, and set it to running
			$this->queue->queue_object( $object['id'], $object['type'] );
			$this->queue->get_batch_jobs( 10 ); // Sets it to running
			$this->queue->set_last_index_time( $object['id'], $object['type'], $now );
			
			// Requeue the job
			$this->queue->queue_object( $object['id'], $object['type'] );

			// Since it was already running, we should now have a new queued entry with a start_time
			// that is now() + min interval - this is the rate limit

			$row = $wpdb->get_row( 
				$wpdb->prepare( 
					"SELECT `start_time` FROM `{$table_name}` WHERE `object_id` = %d AND `object_type` = %s AND `status` = 'queued'", // Cannot prepare table name. @codingStandardsIgnoreLine
					$object['id'],
					$object['type']
				)
			);

			$expected_start_time = gmdate( 'Y-m-d H:i:s', $now + $this->queue->get_index_interval_time( $object['id'], $object['type'] ) );

			$this->assertEquals( $expected_start_time, $row->start_time );
		}
	}

	public function test_get_batch_jobs() {
		global $wpdb;

		$table_name = $this->queue->schema->get_table_name();

		$objects = array(
			array(
				'id' => 1,
				'type' => 'post',
			),
			array(
				'id' => 2,
				'type' => 'post',
			),
			array(
				'id' => 3,
				'type' => 'post',
			),
			array(
				'id' => 1000,
				'type' => 'user',
			),
		);

		$now = time();

		// Insert first job, set it to running, so that we get some queued objects that are rate limited
		$this->queue->queue_object( $objects[0]['id'], $objects[0]['type'] );
		$this->queue->set_last_index_time( $objects[0]['id'], $objects[0]['type'], $now );
		$this->queue->update_job( $objects[0]['id'], array( 'status' => 'running' ) );

		// Insert the jobs
		foreach ( $objects as $object ) {
			$this->queue->queue_object( $object['id'], $object['type'] );
		}
			
		$jobs = $this->queue->get_batch_jobs( 10 );

		$object_ids = wp_list_pluck( $jobs, 'object_id' );

		// Should not have received post 1, because it is currently running, then scheduled again for the future
		$expected_object_ids = array( 2, 3, 1000 );

		$this->assertEquals( $expected_object_ids, $object_ids );

		// And each of those should be now marked as "running"
		$ids_escaped = array_map( 'esc_sql', $expected_object_ids );

		$ids_where_string = implode( ', ', $ids_escaped );

		$not_running_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE `id` IN ({$ids_where_string}) AND `status` != 'running'" ); // Cannot prepare table name, already escaped. @codingStandardsIgnoreLine

		$this->assertEquals( 0, $not_running_count );
	}
}
