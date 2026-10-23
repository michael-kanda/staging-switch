<?php
/**
 * Plugin Name:       Staging Switch
 * Description:       Staging-Duplikat für jede Seite oder jeden Beitrag. Staging ist immer noindex/nofollow, per geheimem Link freigebbar und mit einem Klick live schaltbar.
 * Version:           1.0.3
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Michael Kanda
 * License:           GPL-2.0-or-later
 * Text Domain:       staging-switch
 *
 * @package StagingSwitch
 */

defined( 'ABSPATH' ) || exit;

define( 'SWS_VERSION', '1.0.3' );
define( 'SWS_FILE', __FILE__ );
define( 'SWS_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-sws-core.php';
require_once __DIR__ . '/includes/class-sws-frontend.php';
require_once __DIR__ . '/includes/class-sws-admin.php';

register_activation_hook( __FILE__, array( 'SWS_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SWS_Core', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		SWS_Core::init();
		SWS_Frontend::init();
		if ( is_admin() ) {
			SWS_Admin::init();
		}
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=staging-switch' ) ) . '">' . esc_html__( 'Einstellungen', 'staging-switch' ) . '</a>' );
		return $links;
	}
);
