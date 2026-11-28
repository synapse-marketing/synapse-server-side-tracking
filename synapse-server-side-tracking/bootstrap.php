<?php
/**
 * Bootstrap file.
 *
 * @package    synapse
 */

defined( 'ABSPATH' ) || exit;

// Definitions.
define( 'GTM_SERVER_SIDE_PATH', plugin_dir_path( __FILE__ ) );
define( 'GTM_SERVER_SIDE_URL', plugin_dir_url( __FILE__ ) );

define( 'GTM_SERVER_SIDE_AJAX_SECURITY', 'synapse-ct-admin__xyz' );

define( 'GTM_SERVER_SIDE_ADMIN_SLUG', 'synapse-server-side-tracking' );

define( 'GTM_SERVER_SIDE_DATA_LAYER_CUSTOM_EVENT_NAME', '_synapse' );

define( 'GTM_SERVER_SIDE_FIELD_VERSION', 'synapse_ct_version' );
define( 'GTM_SERVER_SIDE_TRANSLATION_DOMAIN', 'gtm-server-side' );

// Tab: General.
define( 'GTM_SERVER_SIDE_FIELD_PLACEMENT', 'synapse_ct_placement' );
define( 'GTM_SERVER_SIDE_FIELD_WEB_CONTAINER_ID', 'synapse_ct_web_container_id' );
define( 'GTM_SERVER_SIDE_FIELD_WEB_CONTAINER_URL', 'synapse_ct_web_container_url' );
define( 'GTM_SERVER_SIDE_FIELD_WEB_IDENTIFIER', 'synapse_ct_web_identifier' );
// GA4 measurement recovery is on exactly when this ID is set. The retired
// settings have no constant; the upgrade routine reads the one it needs,
// and none of their options is ever deleted.
define( 'GTM_SERVER_SIDE_FIELD_GA4_FALLBACK_ID', 'synapse_ct_ga4_fallback_id' );
define( 'GTM_SERVER_SIDE_FIELD_CLICK_ID_RESTORER_GOOGLE', 'synapse_ct_click_id_restorer_google' );
// Values for field `GTM_SERVER_SIDE_FIELD_PLACEMENT`.
define( 'GTM_SERVER_SIDE_FIELD_PLACEMENT_VALUE_CODE', 'code' );
define( 'GTM_SERVER_SIDE_FIELD_PLACEMENT_VALUE_PLUGIN', 'plugin' );
define( 'GTM_SERVER_SIDE_FIELD_PLACEMENT_VALUE_DISABLE', 'disable' );
define( 'GTM_SERVER_SIDE_FIELD_PLACEMENT_VALUE_GTM_CONSENT', 'gtm_consent' );

define( 'GTM_SERVER_SIDE_FIELD_GTM_EXCLUDE_ROLES', 'synapse_ct_gtm_exclude_roles' );
define( 'GTM_SERVER_SIDE_FIELD_GTM_EXCLUDE_LIST_ROLES', 'synapse_ct_gtm_exclude_list_roles' );

// Tab: Data Layer.
define( 'GTM_SERVER_SIDE_FIELD_DATA_LAYER_ECOMMERCE', 'synapse_ct_data_layer_ecommerce' );
define( 'GTM_SERVER_SIDE_FIELD_DATA_LAYER_USER_DATA', 'synapse_ct_data_layer_user_data' );
define( 'GTM_SERVER_SIDE_FIELD_DATA_LAYER_CUSTOM_EVENT_NAME', 'synapse_ct_data_layer_custom_event_name' );

define( 'GTM_SERVER_SIDE_FIELD_VALUE_YES', 'yes' );

// Settings.
define( 'GTM_SERVER_SIDE_ADMIN_GROUP', 'gtm-server-side-admin-group' );
define( 'GTM_SERVER_SIDE_ADMIN_GROUP_GENERAL', 'gtm-server-side-admin-group-general' );
define( 'GTM_SERVER_SIDE_ADMIN_GROUP_DATA_LAYER', 'gtm-server-side-admin-group-data-layer' );

// Autoload plugin classes.
spl_autoload_register(
	function ( $class ) {
		if ( 0 === strpos( $class, 'GTM_Server_Side' ) ) {
			$file_name = 'class-' . str_replace( '_', '-', strtolower( $class ) );
			include_once GTM_SERVER_SIDE_PATH . 'includes' . DIRECTORY_SEPARATOR . $file_name . '.php';
		}
	}
);


// Create custom hooks.
add_action(
	'plugins_loaded',
	function () {
		do_action( 'synapse_ct' );
		if ( is_admin() ) {
			do_action( 'synapse_ct_admin' );
		} else {
			do_action( 'synapse_ct_frontend' );
		}
	},
	-1
);


/**
 * Return gtm server side version
 *
 * @return string
 */
function get_gtm_server_side_version() {
	static $version;

	if ( null === $version ) {
		$plugin  = get_file_data(
			GTM_SERVER_SIDE_PATH . 'synapse-server-side-tracking.php',
			array(
				'version' => 'Version',
			),
			false
		);
		$version = $plugin['version'];
	}

	return $version;
}
