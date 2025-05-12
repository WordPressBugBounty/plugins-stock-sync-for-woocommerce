<?php

/**
 * Prevent direct access to the script.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Stock_Sync_Logger {
	const LEVEL_OK = 0;
	const LEVEL_WARN = 1;
	const LEVEL_ERROR = 2;
	const LEVELS = [
		'ok' => self::LEVEL_OK,
		'warning' => self::LEVEL_WARN,
		'error' => self::LEVEL_ERROR,
	];

	/**
	 * Get table name
	 */
	private static function table() {
		global $wpdb;

		return $wpdb->prefix . 'wss_log';
	}

	/**
	 * Check if log table exists
	 */
	public static function table_exists() {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) == $table;
	}

	/**
	 * Insert log message
	 */
	public static function log( $message, $type = 'default', $product_id = null, $data = null, $state = false ) {
		global $wpdb;

		// Fill in sites data
		if ( $product_id && $state === 'queued' ) {
			if ( ! isset( $data['sync_results'] ) ) {
				$data['sync_results'] = [];
			}

			foreach ( wss_get_site_keys() as $site_key ) {
				$data['sync_results'][$site_key] = [
					'level' => 'info',
					'errors' => [
						'info' => __( 'Queued', 'woo-stock-sync' ),
					],
				];
			}
		}

		$wpdb->insert( 
			self::table(), 
			[
				'product_id' => $product_id, 
				'type' => $type, 
				'message' => $message, 
				'data' => json_encode( $data ),
				'created_at' => date( 'Y-m-d H:i:s' ),
				'has_error' => ( $type === 'error' ? self::LEVEL_ERROR : self::LEVEL_OK ),
			],
			[ 
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
			] 
		);

		return $wpdb->insert_id;
	}

	/**
	 * Update log message about whether or not site syncing succeeded
	 */
	public static function log_update( $id, $site_keys, $result, $errors = [], $level = '' ) {
		if ( empty( $id ) ) {
			return;
		}

		// Single site is being updated
		if ( ! is_array( $site_keys ) ) {
			$site_keys = [ $site_keys ];
		}

		$record = self::get( $id );

		if ( $record ) {
			if ( ! isset( $record->data->sync_results ) ) {
				$record->data->sync_results = [];
			}

			// Cast to array
			$record->data->sync_results = (array) $record->data->sync_results;

			foreach ( $site_keys as $site_key ) {
				$record->data->sync_results[$site_key] = [
					'level' => $level,
					'result' => $result,
					'errors' => $errors,
				];
			}

			// Check if there has been errors
			$error_lvl = self::LEVEL_OK;
			foreach ( $record->data->sync_results as $site_key => $data ) {
				$data = (array) $data;
				if ( isset( $data['level'] ) && is_string( $data['level'] ) && isset( self::LEVELS[$data['level']] ) ) {
					if ( $error_lvl < self::LEVELS[$data['level']] ) {
						$error_lvl = self::LEVELS[$data['level']];
					}
				}
			}

			if ( $record->type === 'error' ) {
				$error_lvl = self::LEVEL_ERROR;
			}

			self::update( $id, [
				'data' => $record->data,
				'has_error' => $error_lvl,
			] );
		}
	}

	/**
	 * Insert log update into database
	 */
	public static function update( $id, $values ) {
		global $wpdb;

		$table = self::table();

		if ( isset( $values['data'] ) && ! is_scalar( $values['data'] ) ) {
			$values['data'] = json_encode( $values['data'] );
		}

		$values['has_error'] = ( isset( $values['has_error'] ) && $values['has_error'] ) ? $values['has_error'] : 0;

		$wpdb->update(
			$table,
			$values,
			['id' => $id],
			['%s', '%d'],
			'%d'
		);
	}

	/**
	 * Get single log record
	 */
	public static function get( $id ) {
		if ( empty( $id ) ) {
			return null;
		}

		global $wpdb;

		$table = self::table();

		$query = $wpdb->prepare( 
			"SELECT * FROM {$table} WHERE id = %d",
			$id
		);

		$result = $wpdb->get_row( $query );

		if ( $result ) {
			$result = self::unserialize( $result );

			return $result;
		}

		return null;
	}

	/**
	 * Get last error record ID
	 */
	public static function last_error_log_id() {
		global $wpdb;

		$table = self::table();

		$query = "SELECT id FROM {$table} WHERE has_error >= 1 ORDER BY id DESC LIMIT 1";

		return $wpdb->get_var( $query );
	}

	/**
	 * Get error log messages
	 */
	public static function get_errors( $from_log_id, $level = 'error' ) {
		global $wpdb;

		$table = self::table();

		$query = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE has_error >= %d AND id > %d ORDER BY id DESC LIMIT %d, %d",
			self::LEVELS[$level],
			$from_log_id,
			0,
			10
		);

		return self::query( $query, 10 );
	}

	/**
	 * Get log messages
	 */
	public static function get_all( $page, $per_page, $product_id = null, $log_level = 0 ) {
		global $wpdb;

		$table = self::table();

		$offset = ( $page - 1 ) * $per_page;

		$query = $wpdb->prepare( 
			"SELECT * FROM {$table} WHERE has_error >= %d ORDER BY id DESC LIMIT %d, %d",
			$log_level,
			$offset,
			$per_page
		);

		if ( $product_id ) {
			$query = $wpdb->prepare( 
				"SELECT * FROM {$table} WHERE product_id = %d AND has_error >= %d ORDER BY id DESC LIMIT %d, %d",
				$product_id,
				$log_level,
				$offset,
				$per_page
			);
		}

		return self::query( $query, $per_page );
	}

	/**
	 * Delete records
	 */
	public static function delete_records( $retention ) {
		global $wpdb;

		$table = self::table();

		$date = time() - intval( $retention );
		$date = date( 'Y-m-d H:i:s', $date );

		$query = $wpdb->prepare(
			"DELETE FROM {$table} WHERE created_at < %s",
			$date
		);

		return $wpdb->query( $query );
	}

	/**
	 * Query records
	 */
	private static function query( $query, $per_page ) {
		global $wpdb;

		$table = self::table();

		$results = $wpdb->get_results( $query );

		$results = array_map( function( $record ) {
			$record = self::unserialize( $record );

			return $record;
		}, $results );

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$max_num_pages = ceil( $total / $per_page );

		return (object) [
			'logs' => $results,
			'total' => $total,
			'max_num_pages' => $max_num_pages,
		];
	}

	/**
	 * Unserialize data in log record
	 */
	private static function unserialize( $record ) {
		if ( isset( $record->data ) && ! empty( $record->data ) ) {
			$record->data = json_decode( $record->data );
		} else {
			$record->data = [];
		}

		return $record;
	}

	/**
	 * Rows for the log entry table
	 */
	public static function entry_rows( $log ) {
		$rows = [];

		$rows[] = [
			'label' => __( 'Date', 'woo-stock-sync' ),
			'value' => esc_html( wss_format_datetime( strtotime( $log->created_at ) ) ),
		];

		$rows['msg'] = [
			'label' => __( 'Message', 'woo-stock-sync' ),
			'value' => wp_kses_post( $log->message ),
		];

		if ( isset( $log->data->source_desc ) ) {
			$rows[] = [
				'label' => __( 'Description', 'woo-stock-sync' ),
				'value' => esc_html( $log->data->source_desc ),
				'url' => isset( $log->data->source_url ) ? $log->data->source_url : false
			];
		}

		if ( isset( $log->data->source ) ) {
			$rows[] = [
				'label' => __( 'Site', 'woo-stock-sync' ),
				'value' => esc_html( wss_format_site_url( $log->data->source ) ),
			];
		}

		if ( isset( $log->data->username ) ) {
			$rows[] = [
				'label' => __( 'User', 'woo-stock-sync' ),
				'value' => ! empty( $log->data->username ) ? esc_html( sprintf( '%s (%s)', $log->data->username, $log->data->user_id ) ) : __( 'Anonymous', 'woo-stock-sync' ),
			];
		}

		if ( isset( $log->data->request_uri ) ) {
			$rows[] = [
				'label' => __( 'URL', 'woo-stock-sync' ),
				'value' => esc_html( $log->data->request_uri ),
			];
		}

		if ( isset( $log->data->referer ) ) {
			$rows[] = [
				'label' => __( 'Referer URL', 'woo-stock-sync' ),
				'value' => esc_html( $log->data->referer ),
			];
		}

		if ( isset( $log->data->remote_addr ) ) {
			$rows[] = [
				'label' => __( 'IP address', 'woo-stock-sync' ),
				'value' => esc_html( $log->data->remote_addr ),
			];
		}

		if ( isset( $log->data->is_cli ) ) {
			$rows[] = [
				'label' => __( 'CLI', 'woo-stock-sync' ),
				'value' => $log->data->is_cli ? __( 'Yes', 'woo-stock-sync' ) : __( 'No', 'woo-stock-sync' ),
			];
		}

		return $rows;
	}

	/**
	 * Clear logs
	 */
	public static function clear_logs() {
		global $wpdb;

		$table = self::table();

		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
