<?php
/**
 * Ajax for frontend.
 *
 * @package    GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ajax for frontend.
 */
class GTM_Server_Side_Frontend_Ajax {
	use GTM_Server_Side_Singleton;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		if ( GTM_Server_Side_Helpers::is_enable_data_layer_custom_event_name() ) {
			add_action( 'wp_ajax_gtm_server_side_state_cart_data', array( $this, 'state_cart_data' ) );
			add_action( 'wp_ajax_nopriv_gtm_server_side_state_cart_data', array( $this, 'state_cart_data' ) );
		}

		// The browser's confirmation of a purchase push arrives through
		// admin-ajax, where is_admin() is true and the front-end classes are
		// never started. A handler registered in the purchase class is never
		// reached (WordPress answers every confirmation with 400), so it is
		// registered here, on the always-hook, under the condition the purchase
		// class itself starts on.
		if ( GTM_Server_Side_WC_Helpers::instance()->is_enable_ecommerce() ) {
			add_action( 'wp_ajax_' . GTM_Server_Side_Event_Purchase::ACK_ACTION, array( $this, 'purchase_ack' ) );
			add_action( 'wp_ajax_nopriv_' . GTM_Server_Side_Event_Purchase::ACK_ACTION, array( $this, 'purchase_ack' ) );
		}
	}

	/**
	 * Ajax: the browser confirms a purchase push.
	 *
	 * @return void
	 */
	public function purchase_ack() {
		GTM_Server_Side_Event_Purchase::instance()->ack();
	}

	/**
	 * Ajax for cart data.
	 *
	 * @return void
	 */
	public function state_cart_data() {
		check_ajax_referer( GTM_SERVER_SIDE_AJAX_SECURITY, 'security' );

		// WooCommerce may be absent entirely: this endpoint is registered from
		// the plugin's own data-layer setting, not from a WooCommerce check.
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error();
		}
		$cart = WC()->cart;
		$data = GTM_Server_Side_State_Helpers::instance()->get_cart_data( $cart );

		wp_send_json_success( $data );
	}
}
