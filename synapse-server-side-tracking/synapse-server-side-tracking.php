<?php
/**
 * Main plugin file.
 *
 * @package           GTM_Server_Side
 *
 * @wordpress-plugin
 * Plugin Name:       Synapse Server-Side Tracking
 * Description:       Server-side tagging for a self-hosted Google Tag Manager server container: embeds the web GTM snippet with a custom first-party loader, enhanced ad-blocker protection and GA4 measurement recovery for hostile browser privacy modes, configures WooCommerce data layer events, and restores lost ad click IDs.
 * Version:           2.4.1
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Update URI:        https://raw.githubusercontent.com/synapse-marketing/synapse-server-side-tracking/main/updates/manifest.json
 * Author:            Synapse
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       gtm-server-side
 */

defined( 'ABSPATH' ) || exit;

/**
 * One copy at a time.
 *
 * The plugin's folder and main file carry its name, so WordPress installs this
 * copy beside one that sits in a folder of another name, and the two declare
 * the same classes. Activating this copy switches every other copy of this
 * plugin off in the same step, without running its deactivation (on a network
 * activation: on every site). The settings are the same options and pass on
 * untouched; the version change is handled on the next request like any update.
 * In the request in which that happens the other copy has already loaded, so
 * this file stands back until the next one. A copy is told by a file only this
 * plugin has, never by a name.
 */
register_activation_hook(
	__FILE__,
	static function ( $network_wide = false ) {
		$mine  = plugin_basename( __FILE__ );
		$other = static function ( $plugin ) use ( $mine ) {
			return is_string( $plugin ) && $plugin !== $mine && false !== strpos( $plugin, '/' )
				&& is_file( WP_PLUGIN_DIR . '/' . dirname( $plugin ) . '/includes/class-gtm-server-side-plugin-update.php' );
		};
		$wide  = $network_wide && is_multisite();
		foreach ( $wide ? get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) : array( 0 ) as $site ) {
			if ( $site ) {
				switch_to_blog( $site );
			}
			deactivate_plugins( array_filter( (array) get_option( 'active_plugins', array() ), $other ), true, false );
			if ( $site ) {
				restore_current_blog();
			}
		}
		if ( $wide ) {
			deactivate_plugins( array_filter( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ), $other ), true, true );
		}
	}
);

if ( defined( 'GTM_SERVER_SIDE_PATH' ) && is_file( GTM_SERVER_SIDE_PATH . 'includes/class-gtm-server-side-plugin-update.php' ) ) {
	return;
}

/**
 * Bootstrap.
 */
require plugin_dir_path( __FILE__ ) . 'bootstrap.php';

register_activation_hook( __FILE__, array( GTM_Server_Side_Plugin_Activate::class, 'instance' ) );
register_deactivation_hook( __FILE__, array( GTM_Server_Side_Plugin_Deactivate::class, 'instance' ) );

add_action( 'init', array( GTM_Server_Side_Plugin_Upgrade::class, 'instance' ) );
// On the always-hook, not the admin one: WordPress checks for updates from
// WP-Cron as well, where is_admin() is false.
add_action( 'synapse_ct', array( GTM_Server_Side_Plugin_Update::class, 'instance' ) );
add_action( 'synapse_ct', array( GTM_Server_Side_I18n::class, 'instance' ) );
add_action( 'synapse_ct', array( GTM_Server_Side_WC_Order::class, 'instance' ) );
add_action( 'synapse_ct', array( GTM_Server_Side_Frontend_Ajax::class, 'instance' ) );
add_action( 'synapse_ct', array( GTM_Server_Side_Shield_Check::class, 'instance' ) );
add_action( 'synapse_ct_admin', array( GTM_Server_Side_Admin_Settings::class, 'instance' ) );
add_action( 'synapse_ct_admin', array( GTM_Server_Side_Admin_Assets::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Frontend_Assets::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Click_Id_Restorer::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Tracking_Code::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Tracking_Gtm4wp::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_Home::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_Login::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_Register::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_ViewItem::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_ViewItemList::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_ViewCart::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_BeginCheckout::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_Purchase::class, 'instance' ) );
add_action( 'synapse_ct_frontend', array( GTM_Server_Side_Event_AddToCart::class, 'instance' ) );
