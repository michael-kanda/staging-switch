<?php
/**
 * Entfernt nur Einstellungen. Staging-Inhalte bleiben als normale Entwürfe erhalten.
 *
 * @package StagingSwitch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'sws_post_types', 'sws_notify_email', 'sws_default_expiry', 'sws_approval_bar', 'sws_deactivated_ids' ) as $option ) {
	delete_option( $option );
}
