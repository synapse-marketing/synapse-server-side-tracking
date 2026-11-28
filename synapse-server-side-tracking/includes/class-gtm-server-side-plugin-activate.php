<?php
/**
 * Activate plugin.
 *
 * @package    GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activate plugin.
 */
class GTM_Server_Side_Plugin_Activate {
	use GTM_Server_Side_Singleton;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		$this->install();
	}

	/**
	 * First install.
	 *
	 * The plugin activates in a fully clean state: every field empty, every
	 * feature off. The only default written is the snippet placement, set to
	 * "Disable" so nothing is injected into the site until it is configured
	 * on purpose.
	 *
	 * The shield check starts from nothing: whatever record a previous life of
	 * the plugin left (a deactivation drops it; a copy replaced without one
	 * does not) is dropped, so the first pages print inline and the hourly run
	 * asks again a minute after the first load. A "yes" in that record is
	 * remembered for the purge that follows.
	 *
	 * @return void
	 */
	private function install() {
		if ( empty( get_option( GTM_SERVER_SIDE_FIELD_PLACEMENT ) ) ) {
			update_option( GTM_SERVER_SIDE_FIELD_PLACEMENT, GTM_SERVER_SIDE_FIELD_PLACEMENT_VALUE_DISABLE );
		}
		GTM_Server_Side_Shield_Check::forget();
	}
}
