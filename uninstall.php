<?php
/**
 * Uninstall handler. Removes plugin options + transients.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'social_publisher_settings' );
delete_option( 'social_publisher_log' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_social_publisher\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_timeout\_social_publisher\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_social_publisher_skip','_social_publisher_last_run','_social_publisher_last_result')" );
