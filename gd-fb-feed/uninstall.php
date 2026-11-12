<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'gdff_settings' );
delete_option( 'gdff_cache' );
delete_option( 'gdff_last_error' );
delete_transient( 'gdff_refresh_lock' );
wp_clear_scheduled_hook( 'gdff_refresh_event' );
