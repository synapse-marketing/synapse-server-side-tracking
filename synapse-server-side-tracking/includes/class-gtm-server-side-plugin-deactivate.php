<?php
/**
 * _Deactivate plugin.
 *
 * @package    GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * _Deactivate plugin.
 */
class GTM_Server_Side_Plugin_Deactivate {
	use GTM_Server_Side_Singleton;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		// The upgrade routine clears the daily event of the retired edge sender
		// health check. Clearing it here as well means a deactivation never
		// leaves an orphaned event behind.
		wp_clear_scheduled_hook( 'synapse_ct_edge_health' );
		// The hourly shield check: its event cleared, its record dropped (an
		// expired "no", never a deleted option) and, when the record said "yes",
		// the page caches purged, so no cached page printed for the loader
		// outlives the plugin that stood behind it. A reactivation starts inline
		// and asks again a minute later.
		GTM_Server_Side_Shield_Check::retire();
	}
}
