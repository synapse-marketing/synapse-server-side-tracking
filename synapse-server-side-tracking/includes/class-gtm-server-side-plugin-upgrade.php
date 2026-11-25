<?php
/**
 * Upgrade plugin.
 *
 * @package    GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 * @since      2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Upgrade plugin.
 */
class GTM_Server_Side_Plugin_Upgrade {
	use GTM_Server_Side_Singleton;

	/**
	 * First version without the GA4 recovery checkbox. Sites whose stored
	 * version is older than this (or missing) get the migration once.
	 *
	 * @var string
	 */
	const GA4_SWITCH_REMOVED_IN = '2.1.0-beta.1';

	/**
	 * Option of the GA4 recovery checkbox removed in 2.1.0. Read here, never
	 * written or deleted, so reinstalling 2.0.x finds it as it was.
	 *
	 * @var string
	 */
	const LEGACY_GA4_FALLBACK = 'synapse_ct_ga4_fallback';

	/**
	 * Where a GA4 Measurement ID is kept whose recovery checkbox was off.
	 *
	 * @var string
	 */
	const GA4_FALLBACK_ID_DISABLED = 'synapse_ct_ga4_fallback_id_disabled';

	/**
	 * WP-Cron hook of the edge sender health check removed in 2.1.0.
	 *
	 * @var string
	 */
	const LEGACY_EDGE_HEALTH_HOOK = 'synapse_ct_edge_health';

	/**
	 * Init.
	 *
	 * Runs the migrations once per version change and stamps the current
	 * plugin version. Migrations between Synapse versions belong here; there
	 * are deliberately NO migrations from any other plugin - Synapse always
	 * starts from its own clean options.
	 *
	 * Rollback rule: a migration never deletes an option. 2.1.0 removed five
	 * settings (synapse_ct_data_rescue, synapse_ct_edge_sender,
	 * synapse_ct_enhanced_adblocker, synapse_ct_ga4_fallback and the stored
	 * result synapse_ct_edge_health), and all of them stay in the database
	 * untouched, so reinstalling 2.0.3 restores exactly what the site had.
	 *
	 * @return void
	 */
	public function init() {
		$stored = get_option( GTM_SERVER_SIDE_FIELD_VERSION );

		if ( $stored === get_gtm_server_side_version() ) {
			return;
		}

		if ( self::is_older_than( $stored, self::GA4_SWITCH_REMOVED_IN ) ) {
			self::migrate_ga4_switch();
		}

		// The edge sender health check is gone, and a scheduled event whose
		// callback no longer exists would still fire every day. Its stored
		// result option is left alone (rollback rule above).
		wp_clear_scheduled_hook( self::LEGACY_EDGE_HEALTH_HOOK );

		// 2.3.0: a version change (an update, a downgrade and back) drops the
		// shield check's record: the pages print inline until the next hourly
		// run says "yes" again for this version's own address. Never deleted.
		GTM_Server_Side_Shield_Check::forget();

		update_option( GTM_SERVER_SIDE_FIELD_VERSION, get_gtm_server_side_version(), false );
	}

	/**
	 * Whether a stored plugin version is older than the given one.
	 *
	 * A missing or empty stored version counts as older: the migrations are
	 * written to be no-ops on a fresh install, so running them is always safe.
	 *
	 * @param  mixed  $stored  Stored version option.
	 * @param  string $version Version to compare with.
	 * @return bool
	 */
	private static function is_older_than( $stored, $version ) {
		if ( ! is_string( $stored ) || '' === $stored ) {
			return true;
		}

		return version_compare( $stored, $version, '<' );
	}

	/**
	 * 2.1.0: the GA4 recovery checkbox is gone and the Measurement ID alone
	 * switches recovery on.
	 *
	 * A site that had an ID stored with the checkbox off would start
	 * recovering the moment it updated. To keep its behaviour identical, the
	 * ID is copied to GA4_FALLBACK_ID_DISABLED and the field is cleared. The
	 * checkbox option itself is not touched.
	 *
	 * @return void
	 */
	private static function migrate_ga4_switch() {
		$id = get_option( GTM_SERVER_SIDE_FIELD_GA4_FALLBACK_ID, '' );
		if ( '' === trim( (string) $id ) ) {
			return;
		}

		if ( GTM_SERVER_SIDE_FIELD_VALUE_YES === get_option( self::LEGACY_GA4_FALLBACK ) ) {
			return;
		}

		update_option( self::GA4_FALLBACK_ID_DISABLED, $id, false );
		update_option( GTM_SERVER_SIDE_FIELD_GA4_FALLBACK_ID, '' );
	}
}
