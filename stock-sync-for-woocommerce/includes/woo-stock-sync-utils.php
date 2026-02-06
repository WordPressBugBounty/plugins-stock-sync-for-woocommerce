<?php

/**
 * Prevent direct access to the script.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get site role
 */
function wss_get_role() {
	return get_option( 'woo_stock_sync_role', 'primary' );
}

/**
 * Get process model
 */
function wss_process_model() {
	return get_option( 'woo_stock_sync_process_model', 'background' );
}

/**
 * Get batch size
 */
function wss_get_batch_size( $operation = '' ) {
	$size = intval( get_option( 'woo_stock_sync_batch_size', 10 ) );

	return apply_filters( 'woo_stock_sync_batch_size', $size, $operation );
}

/**
 * URL to the Primary Inventory report
 */
function wss_primary_report_url( $action = '' ) {
	$sites = woo_stock_sync_sites();

	if ( ! empty( $sites ) ) {
		$site = reset( $sites );

		return add_query_arg( [
			'page' => 'woo-stock-sync-report',
			'action' => $action,
		], $site['url'] . '/wp-admin/admin.php' );
	}

	return '';
}

/**
 * Get product types
 */
function wss_product_types( $incl = [] ) {
	$types = apply_filters( 'woo_stock_sync_product_types', [
		'simple', 'variable',
		'product-part', 'variable-product-part',
		'bundle'
	] );

	return array_merge( $types, $incl );
}

/**
 * Get product query
 */
function wss_product_query( $params = [] ) {
	$query = new WC_Product_Query();
	$query->set( 'status', [ 'publish', 'private', 'draft' ] );
	$query->set( 'type', wss_product_types() );
	$query->set( 'order', 'ASC' );
	$query->set( 'orderby', 'ID' );

	foreach ( $params as $key => $value ) {
		$query->set( $key, $value );
	}

	return $query;
}

add_filter( 'woocommerce_product_data_store_cpt_get_products_query', 'wss_product_queries', 10, 3 );
function wss_product_queries( $wp_query_args, $query_vars, $data_store_cpt ) {
	// Mismatch query
	if ( ! empty( $query_vars['_wss_stock_mismatch'] ) ) {
		$wp_query_args['meta_query'][] = [
			'key' => '_woo_stock_sync_data',
			'value' => '"status";s:8:"mismatch"',
			'compare' => 'LIKE',
		];
	}

	// Present on site query
	if ( ! empty( $query_vars['_wss_present'] ) ) {
		$site_key = $query_vars['_wss_site_key'];

		$wp_query_args['meta_query'][] = [
			'key' => sprintf( '_wss_status_%s', $site_key ),
			'value' => 'present',
			'compare' => '=',
		];
	}

	// Not present on site query
	if ( ! empty( $query_vars['_wss_not_present'] ) ) {
		$site_key = $query_vars['_wss_site_key'];

		$wp_query_args['meta_query'][] = [
			'key' => sprintf( '_wss_status_%s', $site_key ),
			'value' => 'not_present',
			'compare' => '=',
		];
	}

	return $wp_query_args;
}

/**
 * Get products with children
 */
function wss_products_with_children( $products ) {
	$products_with_children = [];

	foreach ( $products as $key => $product ) {
		$products_with_children[] = $product;

		if ( $product->get_type() === 'variable' ) {
			foreach ( $product->get_children() as $children ) {
				$children = wc_get_product( $children );
				if ( ! $children || ! $children->exists() ) {
					continue;
				}

				$products_with_children[] = $children;
			}
		}
	}

	return $products_with_children;
}

/**
 * Format site URL
 */
function wss_format_site_url( $url, $link = false ) {
	$url = rtrim( $url, '/' );
	$title = str_replace( ['https://', 'http://'], '', $url );

	if ( $link ) {
		return sprintf( '<a href="%s" target="_blank">%s</a>', $url, $title );
	}

	return $title;
}

/**
 * Format date and time
 */
function wss_format_datetime( $timestamp ) {
	$time = get_date_from_gmt( date( 'Y-m-d H:i:s', $timestamp ), get_option( 'time_format' ) );
	$date = get_date_from_gmt( date( 'Y-m-d H:i:s', $timestamp ), get_option( 'date_format' ) );

	return sprintf( '%s - %s', $date, $time );
}

/**
 * Check if role is primary inventory
 */
function wss_is_primary() {
	return wss_get_role() === 'primary';
}

/**
 * Check if role is secondary inventory
 */
function wss_is_secondary() {
	return wss_get_role() === 'secondary';
}

/**
 * URL to admin edit product page
 */
function wss_product_url( $product_id ) {
	return add_query_arg( [
		'post' => $product_id,
		'action' => 'edit',
	], admin_url( 'post.php' ) );
}

/**
 * URL to admin order page
 */
function wss_order_url( $order_id ) {
	return add_query_arg( [
		'post' => $order_id,
		'action' => 'edit',
	], admin_url( 'post.php' ) );
}

/**
 * Get product title by ID
 */
function wss_product_title( $product_id ) {
	$product = wc_get_product( $product_id );

	if ( $product ) {
		return $product->get_name();
	}

	return null;
}

/**
 * General checks whether or not syncing should proceed
 */
function wss_should_sync( $product ) {
	// Unsupported WooCommerce in use, abort
	if ( ! woo_stock_sync_version_check() ) {
		return false;
	}

	// Stock sync not enabled
	if ( get_option( 'woo_stock_sync_enabled', 'yes' ) !== 'yes' ) {
		return false;
	}

	// Inventory change originated from Primary Inventory, do not create new job
	if ( wss_request_role() === 'primary' ) {
		return false;
	}

	// This is Secondary Inventory and change was triggered by Stock Sync request, do not create new job
	if ( wss_is_secondary() && wss_request() ) {
		return false;
	}

	// Product not managing inventory
	if ( ! $product->managing_stock() ) {
		return false;
	}

	// Allow 3rd party plugins to determine whether or not sync the stock
	if ( ! apply_filters( 'woo_stock_sync_should_sync', true, $product, 'stock_qty' ) ) {
		return false;
	}

	return true;
}

/**
 * Check if request is Woo Stock Sync request
 */
function wss_request() {
	return isset( $GLOBALS['woo_stock_sync_request'] ) && $GLOBALS['woo_stock_sync_request'];
}

/**
 * Get request source role (Primary / Inventory)
 */
function wss_request_role() {
	return isset( $GLOBALS['woo_stock_sync_request_role'] ) ? $GLOBALS['woo_stock_sync_request_role'] : false;
}

/**
 * Dispatch sync job or add it to queue if batch sync is enabled
 */
function wss_dispatch_sync( $data ) {
	if ( isset( $GLOBALS['wss_immediate_process'] ) ) {
		$process = new Woo_Stock_Sync_Process();
		$process->data( [
			'bulk_changes' => [
				$data
			]
		] );
		$process->dispatch();
	} else {
		if ( ! isset( $GLOBALS['wss_bulk_sync'] ) ) {
			$GLOBALS['wss_bulk_sync'] = [
				'bulk_changes' => [],
			];
		}

		$GLOBALS['wss_bulk_sync']['bulk_changes'][] = $data;
	}
}

/**
 * Mark request as Woo Stock Sync request
 */
add_filter( "woocommerce_rest_pre_insert_product_object", 'wss_mark_request', 10, 3 );
add_filter( "woocommerce_rest_pre_insert_product_variation_object", 'wss_mark_request', 10, 3 );
function wss_mark_request( $product, $request, $creating ) {
	if ( $request->get_param( 'woo_stock_sync' ) == '1' ) {
		$GLOBALS['woo_stock_sync_request'] = true;
	}

	if ( $request->get_param( 'woo_stock_sync_source_role' ) ) {
		$GLOBALS['woo_stock_sync_request_role'] = $request->get_param( 'woo_stock_sync_source_role' );
	}

	return $product;
}

/**
 * Store information about which order triggered stock change
 */
add_filter( 'woocommerce_can_restock_refunded_items', 'wss_store_source_order', 10, 2 );
add_filter( 'woocommerce_can_restore_order_stock', 'wss_store_source_order', 10, 2 );
add_filter( 'woocommerce_can_reduce_order_stock', 'wss_store_source_order', 10, 2 );
function wss_store_source_order( $return, $order ) {
	$GLOBALS['wcs_source_order_id'] = $order->get_id();

	return $return;
}

/**
 * Get current stock directly from the DB
 */
function wss_current_stock( $product_id_with_stock ) {
	global $wpdb;

	return wc_stock_amount(
		$wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key='_stock';",
				$product_id_with_stock
			)
		)
	);
}

/**
 * Get site data by key
 */
function wss_site_by_key( $key ) {
	$sites = woo_stock_sync_sites();

	foreach ( $sites as $site ) {
		if ( $site['key'] === $key ) {
			return $site;
		}
	}

	return false;
}

/**
 * Reset stock sync status for a product
 */
function wss_reset_sync_status( $product_id, $site_key ) {
	$data = (array) get_post_meta( $product_id, '_woo_stock_sync_data', true );

	$data[$site_key] = [];

	update_post_meta( $product_id, '_woo_stock_sync_data', $data );

	// Also save status to separate meta row to ease
	// status filtering. In future it would be wise
	// to atomize all data instead of serialized blob
	update_post_meta( $product_id, '_wss_status_' . $site_key, 'not_present' );

	return true;
}

/**
 * Update product meta data with stock quantity of external site
 * 
 * @param WC_Product $product
 * @param string $site_key
 * @param int $ext_qty
 * @param int $ext_id
 * @param string $ext_name
 * @param int $ext_parent_id
 * 
 * @return void
 */
function wss_update_ext_qty( $product, $site_key, $ext_qty, $ext_id, $ext_name, $ext_parent_id = null ) {
	if ( is_int( $product ) ) {
		$product_id = $product;
		$product = wc_get_product( $product_id );
	} else {
		$product_id = $product->get_id();
	}

	$data = (array) get_post_meta( $product_id, '_woo_stock_sync_data', true );

	$data[$site_key] = [
		'id' => $ext_id,
		'name' => $ext_name,
		'qty' => $ext_qty,
		'parent_id' => $ext_parent_id,
		'status' => ( $ext_qty != $product->get_stock_quantity( 'edit' ) ) ? 'mismatch' : 'ok',
	];

	update_post_meta( $product_id, '_woo_stock_sync_data', $data );

	// Also save status to separate meta row to ease
	// status filtering. In future it would be wise
	// to atomize all data instead of serialized blob
	update_post_meta( $product_id, '_wss_status_' . $site_key, 'present' );
}

/**
 * Update mismatch data for a product
 * 
 * @param WC_Product $product
 * @param string $site_key
 * 
 * @return void
 */
function wss_update_mismatch_data( $product, $site_key ) {
	if ( is_int( $product ) ) {
		$product_id = $product;
		$product = wc_get_product( $product_id );
	} else {
		$product_id = $product->get_id();
	}

	$data = (array) get_post_meta( $product_id, '_woo_stock_sync_data', true );

	$current_status = isset( $data[$site_key]['status'] ) ? $data[$site_key]['status'] : null;
	$current_qty = isset( $data[$site_key]['qty'] ) ? $data[$site_key]['qty'] : null;

	$new_status = ( $current_qty === null || $current_qty != $product->get_stock_quantity( 'edit' ) ) ? 'mismatch' : 'ok';

	if ( $current_status != $new_status ) {
		$data[$site_key]['status'] = $new_status;

		update_post_meta( $product_id, '_woo_stock_sync_data', $data );
	}
}

/**
 * Get site keys
 */
function wss_get_site_keys() {
	return array_map( function( $site ) {
		return $site['key'];
	}, woo_stock_sync_sites() );
}

/**
 * Get other sites which are to be sync
 */
function woo_stock_sync_sites() {
	$sites = [];

	for ( $i = 0; $i < apply_filters( 'woo_stock_sync_supported_api_credentials', 1 ); $i++ ) {
		$url = woo_stock_sync_api_credentials_field_value( 'woo_stock_sync_url', $i );
		$formatted_url = wss_format_site_url( $url );
		$api_key = woo_stock_sync_api_credentials_field_value( 'woo_stock_sync_api_key', $i );
		$api_secret = woo_stock_sync_api_credentials_field_value( 'woo_stock_sync_api_secret', $i );

		if ( ! empty( $url ) && ! empty( $api_key ) && ! empty( $api_secret ) ) {
			$sites[$i] = [
				'i' => $i,
				'key' => sanitize_key( $url ),
				'url' => $url,
				'formatted_url' => $formatted_url,
				'letter' => strtoupper( substr( $formatted_url, 0, 1 ) ),
				'api_key' => $api_key,
				'api_secret' => $api_secret,
			];
		}
	}

	return $sites;
}

/**
 * Check if WooCommerce 4.0 or higher is running
 */
function woo_stock_sync_version_check() {
	if ( class_exists( 'WooCommerce' ) ) {
		global $woocommerce;

		if ( version_compare( $woocommerce->version, '4.0', '>=' ) ) {
			return TRUE;
		}
	}

	return FALSE;
}

/**
 * Format API credentials field name
 */
function woo_stock_sync_api_credentials_field_name( $name, $i ) {
  if ( $i == 0 ) {
    return $name;
  }

  return sprintf( '%s_%d', $name, $i );
}

/**
 * Get API credentials field value
 */
function woo_stock_sync_api_credentials_field_value( $name, $i, $default = '' ) {
  if ( $i == 0 ) {
    $value_key = $name;
  } else {
    $value_key = sprintf( '%s_%d', $name, $i );
  }


  return get_option( $value_key, $default );
}

/**
 * Converts products into JSON suitable for Vue.js
 */
function wss_product_to_json( $product, $flush_cache = false, $variation_indent = true ) {
	if ( $flush_cache ) {
		// Flush post meta cache for this product so we get fresh quantities
		wp_cache_delete( $product->get_id(), 'post_meta' );
	}

	$sites_qty = [];
	$data = (array) get_post_meta( $product->get_id(), '_woo_stock_sync_data', true );
	foreach ( woo_stock_sync_sites() as $site ) {
		$site_data = [
			'url' => null,
			'qty' => null,
			'processing' => false,
		];

		if ( isset( $data[$site['key']], $data[$site['key']]['id'] ) ) {
			$url_id = $data[$site['key']]['id'];
			if ( $data[$site['key']]['parent_id'] ) {
				$url_id = $data[$site['key']]['parent_id'];
			}

			$ext_url = add_query_arg( [
				'post' => $url_id,
				'action' => 'edit',
			], rtrim( $site['url'], '/' ) . '/wp-admin/post.php' );

			$site_data = [
				'id' => $data[$site['key']]['id'],
				'url' => $ext_url,
				'qty' => $data[$site['key']]['qty'],
				'processing' => false,
			];
		}

		$sites_qty[$site['key']] = $site_data;
	}

	return [
		'title' => wss_get_product_title( $product, $variation_indent ),
		'id' => $product->get_id(),
		'sku' => $product->get_sku( 'edit' ),
		'qty' => $product->get_stock_quantity( 'edit' ),
		'managing_stock' => $product->managing_stock(),
		'editing_qty' => false,
		'site_qtys' => $sites_qty,
		'status' => 'default',
		'status_qty' => 'default',
	];
}

/**
 * Get product title by ID
 */
function wss_get_product_title( $product, $indent = true ) {
	$sku = $product->get_sku( 'edit' );

	if ( $product->get_type() === 'variation' ) {
		if ( $indent ) {
			$name = str_repeat( '&nbsp;', 5 ) . wc_get_formatted_variation( $product, true, false, false );
		} else {
			$name = $product->get_name();
		}
		
		if ( strlen( $sku ) > 0 ) {
			$name = sprintf( '%s (%s)', $name, $sku );
		}

		$edit_id = $product->get_parent_id();
	} else {
		$name = $product->get_name();
		if ( strlen( $sku ) > 0 ) {
			$name = sprintf( '%s (%s)', $name, $sku );
		}

		$edit_id = $product->get_id();
	}

	return sprintf(
		'<a href="%s" target="_blank">%s</a>',
		esc_url( wss_product_url( $edit_id ) ),
		esc_html( $name )
	);
}

/**
 * Transform sync results into an array 
 */
function wss_transform_results( $entry, $site ) {
	$results = isset( $entry->data->sync_results ) ? (array) $entry->data->sync_results : [];
	$data = isset( $results[$site['key']] ) ? $results[$site['key']] : false;
	$url = wss_format_site_url( $site['url'] );
	$errors = isset( $data->errors ) ? (array) $data->errors : [];
	$errors_str = implode( "\n", $errors );
	$msg = '';
	$level = '';

	// Results not available, show N/A in the log
	if ( $data === false ) {
		$msg = __( 'Not available', 'woo-stock-sync' );
		$level = 'na';
	}
	// Everything OK
	else if ( isset( $data->result ) && $data->result ) {
		$level = 'success';
		$msg = __( 'OK', 'woo-stock-sync' );
	}
	// Level is set, use it directly
	else if ( isset( $data->level ) && ! empty( $data->level ) ) {
		$level = $data->level;
		$msg = $errors_str;
	}
	// @legacy
	// Not found error, level is 'warning'
	else if ( count( (array) $data->errors ) === 1 && isset( $data->errors->not_found ) ) {
		$level = 'warning';
		$msg = $errors_str;
	}
	// @legacy
	// General error
	else {
		$level = 'error';
		$msg = $errors_str;
	}

	return (object) [
		'site' => $site['letter'],
		'level' => $level,
		'msg' => sprintf( '%s: %s', $url, $msg ),
	];
}

/**
 * Options for status filter
 */
function wss_status_filter_options() {
	$options = [
		'all' => __( 'All products', 'woo-stock-sync' ),
		'mismatch' => __( 'Stock mismatching', 'woo-stock-sync' ),
		'present' => [
			'label' => __( 'Present on site', 'woo-stock-sync' ),
			'options' => [],
		],
		'not_present' => [
			'label' => __( 'Not present on site', 'woo-stock-sync' ),
			'options' => [],
		],
	];

	foreach ( woo_stock_sync_sites() as $site ) {
		$options['present']['options']['present_' . $site['key']] = sprintf( __( 'Present: %s', 'woo-stock-sync' ), $site['formatted_url'] );
		$options['not_present']['options']['not_present_' . $site['key']] = sprintf( __( 'Not present: %s', 'woo-stock-sync' ), $site['formatted_url'] );
	}

	return $options;
}

/**
 * Get Pro version URL
 */
function wss_get_pro_url() {
	return 'https://wptrio.com/products/stock-sync-pro/';
}

/**
 * Augment log data with user ID, IP address etc.
 */
function wss_augment_log_data( $data ) {
	$host = ( empty( $_SERVER['HTTPS'] ) ? 'http' : 'https' ) . "://{$_SERVER['HTTP_HOST']}";

	$augment = [
		'remote_addr' => isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '',
		'request_uri' => '',
		'referer' => sprintf( '%s%s', $host, wp_get_raw_referer() ),
		'is_cli' => defined( 'WP_CLI' ) && WP_CLI,
		'user_id' => 0,
		'username' => __( 'Anonymous', 'woo-stock-sync' ),
	];

	if ( isset( $_SERVER['REQUEST_URI'] ) ) {
		$augment['request_uri'] = sprintf( '%s%s', $host, $_SERVER['REQUEST_URI'] );
	}

	if ( ( $user = wp_get_current_user() ) ) {
		$augment['user_id'] = $user->ID;
		$augment['username'] = $user->user_login;
	}

	// Clip all data to 250 characters
	foreach ( $augment as $key => $value ) {
		$augment[$key] = substr( $value, 0, 250 );
	}

	return array_merge( $data, $augment );
}
