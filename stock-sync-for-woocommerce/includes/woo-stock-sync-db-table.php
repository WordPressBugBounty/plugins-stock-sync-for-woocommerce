<?php

/**
 * Prevent direct access to the script.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wss_db_version;
$wss_db_version = '1.6';

register_activation_hook( WOO_STOCK_SYNC_FILE, function() {
	wporg_wss_install_db_table();
} );

/**
 * Create / update tables
 */
function wporg_wss_install_db_table() {
	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

	global $wpdb;
	global $wss_db_version;

	$charset_collate = $wpdb->get_charset_collate();
	
	$table_name = $wpdb->prefix . 'wss_log';
	$sql_log_table = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		product_id bigint(20) unsigned,
		type varchar(255) default '',
		message text,
		data longtext,
		has_error smallint(1) default 0,
		created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

	dbDelta( $sql_log_table );

	$table_name = $wpdb->prefix . 'wss_requests';
	$sql_request_table = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		idempotency_key varchar(100) default '',
		status varchar(20) default '',
		data longtext,
		created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
		updated_at datetime DEFAULT '0000-00-00 00:00:00',
		completed_at datetime DEFAULT '0000-00-00 00:00:00',
		PRIMARY KEY  (id),
		UNIQUE KEY idempotency_key (idempotency_key)
	) $charset_collate;";

	dbDelta( $sql_request_table );

	update_option( 'wss_db_version', $wss_db_version );
}

/**
 * Update log table - add "has_error" column
 */
add_action( 'plugins_loaded', function() {
	global $wss_db_version;

	if ( get_option( 'wss_db_version' ) != $wss_db_version ) {
		wporg_wss_install_db_table();
	}
} );
