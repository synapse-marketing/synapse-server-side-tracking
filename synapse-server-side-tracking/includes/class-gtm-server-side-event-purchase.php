<?php
/**
 * Data Layer Event: purchase.
 *
 * @package    GTM_Server_Side
 * @subpackage GTM_Server_Side/includes
 * @since      2.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data Layer Event: purchase.
 *
 * Since 2.2.0 an order is not "sent" because PHP printed the push. The push is
 * printed on the thank-you page with a durable event id, and the browser
 * confirms it back once Google Tag Manager has processed it (the push's
 * eventCallback sends a beacon to admin-ajax). Until that confirmation arrives
 * the push is printed again on the next views of the thank-you page, at most
 * MAX_PRINTS times, always with the same event id and transaction id, so
 * Google Analytics (transaction_id) and Meta (event_id) count the purchase
 * once. A blocked tag manager, a closed tab or a response cut mid-way no
 * longer leaves the order marked as delivered. States, in order meta:
 * prepared (the order exists), printed (the push went out at least once),
 * confirmed (the browser's tag manager processed it). Orders marked by a
 * version before 2.2.0 carry only the old "sent" stamp and are left as they
 * are.
 */
class GTM_Server_Side_Event_Purchase {
	use GTM_Server_Side_Singleton;

	/**
	 * Session transaction key.
	 *
	 * @var string
	 */
	const TRANSACTION_KEY = '_gtm_server_side_order_id';

	/**
	 * Order sent key. Since 2.2.0 written when the browser CONFIRMS the push,
	 * not when PHP prints it; kept under its old name for anything that reads it.
	 *
	 * @var string
	 */
	const TRANSACTION_SENT_KEY = '_gtm_server_side_order_sent';

	/**
	 * Order meta: the durable event id of the purchase (made once, reused on every print).
	 *
	 * @var string
	 */
	const EVENT_ID_KEY = '_synapse_ct_purchase_event_id';

	/**
	 * Order meta: prepared | printed | confirmed.
	 *
	 * @var string
	 */
	const STATE_KEY = '_synapse_ct_purchase_state';

	/**
	 * Order meta: how many times the push was printed.
	 *
	 * @var string
	 */
	const PRINTS_KEY = '_synapse_ct_purchase_prints';

	/**
	 * The push is printed at most this many times without a confirmation.
	 *
	 * @var int
	 */
	const MAX_PRINTS = 3;

	/**
	 * admin-ajax action the browser confirms through.
	 *
	 * @var string
	 */
	const ACK_ACTION = 'synapse_ct_purchase_ack';

	/**
	 * Check order created or not.
	 *
	 * @var bool
	 */
	private $is_order_created = false;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! GTM_Server_Side_WC_Helpers::instance()->is_enable_ecommerce() ) {
			return;
		}

		add_action( 'woocommerce_new_order', array( $this, 'woocommerce_new_order' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'woocommerce_new_order' ) );
		add_action( 'wp_footer', array( $this, 'wp_footer' ) );
		// The confirmation (ack) is not registered here: this class starts on
		// front-end requests only, and admin-ajax is not one of them. See
		// GTM_Server_Side_Frontend_Ajax.
	}

	/**
	 * New order create.
	 *
	 * @param  int $order_id Order id.
	 * @return void
	 */
	public function woocommerce_new_order( $order_id ) {
		if ( $this->is_order_created ) {
			return;
		}
		$this->is_order_created = true;

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set(
				self::TRANSACTION_KEY,
				(int) $order_id
			);
		}

		$order = wc_get_order( (int) $order_id );
		if ( $order && is_callable( array( $order, 'get_meta' ) ) ) {
			$this->prepare( $order );
		}
	}

	/**
	 * WP footer hook.
	 *
	 * @return void
	 */
	public function wp_footer() {
		/* phpcs:ignore
		if ( ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		*/

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		$order_id = (int) WC()->session->get(
			self::TRANSACTION_KEY
		);

		if ( empty( $order_id ) ) {
			return;
		}

		WC()->session->__unset(
			self::TRANSACTION_KEY
		);

		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}

		if ( 'print' !== $this->decision( $order ) ) {
			return;
		}

		$data_layer = array(
			'event'          => GTM_Server_Side_Helpers::get_data_layer_event_name( 'purchase' ),
			'event_id'       => $this->event_id_for( $order ),
			'ecomm_pagetype' => 'purchase',
			'ecommerce'      => array(
				'transaction_id'  => esc_attr( $order->get_order_number() ),
				'affiliation'     => '',
				'value'           => GTM_Server_Side_WC_Helpers::instance()->formatted_price( $order->get_total() ),
				'tax'             => GTM_Server_Side_WC_Helpers::instance()->formatted_price( $order->get_total_tax() ),
				'shipping'        => GTM_Server_Side_WC_Helpers::instance()->formatted_price( $order->get_shipping_total() ),
				'currency'        => esc_attr( $order->get_currency() ),
				'coupon'          => esc_attr( join( ',', $order->get_coupon_codes() ) ),
				'discount_amount' => GTM_Server_Side_WC_Helpers::instance()->formatted_price( $order->get_discount_total() ),
				'items'           => GTM_Server_Side_WC_Helpers::instance()->get_order_data_layer_items( $order->get_items() ),
			),
		);

		if ( GTM_Server_Side_Helpers::is_enable_data_layer_custom_event_name() ) {
			$data_layer['cart_state'] = GTM_Server_Side_State_Helpers::instance()->get_order_data( $order );
		}

		if ( GTM_Server_Side_WC_Helpers::instance()->is_enable_user_data() ) {
			$data_layer['user_data'] = GTM_Server_Side_WC_Helpers::instance()->get_order_user_data( $order );

			$customer_id                             = (int) $order->get_customer_id();
			$data_layer['user_data']['new_customer'] = 'true';

			if ( $customer_id > 0 && ! GTM_Server_Side_WC_Helpers::instance()->is_new_customer( $customer_id ) ) {
				$data_layer['user_data']['new_customer'] = 'false';
			}
		}

		$this->print_push( $data_layer, $this->ack_fields( $order ) );
		$this->after_print( $order );
	}

	/**
	 * The order meta that describe where the purchase stands, read once.
	 *
	 * @param  WC_Order $order Order.
	 * @return array {legacy_sent: bool, state: string, prints: int}
	 */
	public function facts( $order ) {
		return array(
			'legacy_sent' => (bool) $order->meta_exists( self::TRANSACTION_SENT_KEY ),
			'state'       => (string) $order->get_meta( self::STATE_KEY ),
			'prints'      => (int) $order->get_meta( self::PRINTS_KEY ),
		);
	}

	/**
	 * Print the push for this order now, or skip it.
	 *
	 * @param  WC_Order $order Order.
	 * @return string 'print' | 'skip'
	 */
	public function decision( $order ) {
		$facts = $this->facts( $order );
		return self::decide( $facts['legacy_sent'], $facts['state'], $facts['prints'] );
	}

	/**
	 * The rule, on stored facts alone: confirmed = done; marked "sent" by a
	 * version before 2.2.0 (no state at all) = done, that stamp meant printed and
	 * the order is weeks old by the time this runs; MAX_PRINTS prints without a
	 * confirmation = give up (the state stays "printed", visible in the order
	 * meta); anything else prints.
	 *
	 * @param  bool   $legacy_sent The old "sent" stamp exists.
	 * @param  string $state       prepared | printed | confirmed | ''.
	 * @param  int    $prints      Prints so far.
	 * @return string 'print' | 'skip'
	 */
	public static function decide( $legacy_sent, $state, $prints ) {
		if ( 'confirmed' === $state ) {
			return 'skip';
		}
		if ( '' === $state && $legacy_sent ) {
			return 'skip';
		}
		if ( (int) $prints >= self::MAX_PRINTS ) {
			return 'skip';
		}
		return 'print';
	}

	/**
	 * A new order: its event id and the state "prepared". Never touches an order
	 * that already has a state or the old "sent" stamp.
	 *
	 * @param  WC_Order $order Order.
	 * @return void
	 */
	public function prepare( $order ) {
		$facts = $this->facts( $order );
		if ( '' !== $facts['state'] || $facts['legacy_sent'] ) {
			return;
		}
		$this->event_id_for( $order );
		$order->update_meta_data( self::STATE_KEY, 'prepared' );
		$order->save_meta_data();
	}

	/**
	 * The order's event id: stored once, the same on every print.
	 *
	 * @param  WC_Order $order Order.
	 * @return string
	 */
	public function event_id_for( $order ) {
		$stored = (string) $order->get_meta( self::EVENT_ID_KEY );
		if ( '' !== $stored ) {
			return $stored;
		}
		$event_id = self::event_id( $order->get_id(), $order->get_order_key() );
		$order->update_meta_data( self::EVENT_ID_KEY, $event_id );
		$order->save_meta_data();
		return $event_id;
	}

	/**
	 * The event id: keyed with the site's salt, 32 hex characters, the same for
	 * the same order on the same site - durable even if the meta were lost.
	 *
	 * @param  int    $order_id  Order id.
	 * @param  string $order_key Order key.
	 * @return string
	 */
	public static function event_id( $order_id, $order_key ) {
		return wp_hash( 'synapse-ct-purchase|' . (int) $order_id . '|' . (string) $order_key );
	}

	/**
	 * The token the browser sends back: keyed with the site's salt, bound to the
	 * order and its event id. Only the page the buyer saw carries it.
	 *
	 * @param  int    $order_id Order id.
	 * @param  string $event_id Event id.
	 * @return string
	 */
	public static function ack_token( $order_id, $event_id ) {
		return wp_hash( 'synapse-ct-ack|' . (int) $order_id . '|' . (string) $event_id );
	}

	/**
	 * What the printed block needs to confirm the push.
	 *
	 * @param  WC_Order $order Order.
	 * @return array
	 */
	public function ack_fields( $order ) {
		return array(
			'url'    => admin_url( 'admin-ajax.php' ),
			'action' => self::ACK_ACTION,
			'order'  => (string) $order->get_id(),
			't'      => self::ack_token( $order->get_id(), $this->event_id_for( $order ) ),
		);
	}

	/**
	 * After a print: state "printed", one more print counted.
	 *
	 * @param  WC_Order $order Order.
	 * @return void
	 */
	public function after_print( $order ) {
		$facts = $this->facts( $order );
		$order->update_meta_data( self::STATE_KEY, 'printed' );
		$order->update_meta_data( self::PRINTS_KEY, $facts['prints'] + 1 );
		$order->save_meta_data();
	}

	/**
	 * The push, with the confirmation in its eventCallback: Google Tag Manager
	 * calls it once every tag the event triggered has finished (or after
	 * eventTimeout), and never when it is not on the page at all.
	 *
	 * @param  array $data_layer The push.
	 * @param  array $ack        url, action, order, t.
	 * @return void
	 */
	public function print_push( $data_layer, $ack ) {
		?>
		<script type="text/javascript">
			( function () {
				var purchase = <?php echo GTM_Server_Side_Helpers::array_to_json( $data_layer ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
				var ack = <?php echo wp_json_encode( $ack ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
				window.dataLayer = window.dataLayer || [];
				dataLayer.push( { ecommerce: null } );
				purchase.eventTimeout = 2000;
				purchase.eventCallback = function () {
					try {
						var body = new FormData();
						body.append( 'action', ack.action );
						body.append( 'order', ack.order );
						body.append( 't', ack.t );
						if ( ! ( navigator.sendBeacon && navigator.sendBeacon( ack.url, body ) ) ) {
							fetch( ack.url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true } ).catch( function () {} );
						}
					} catch ( e ) {}
				};
				dataLayer.push( purchase );
			} )();
		</script>
		<?php
	}

	/**
	 * admin-ajax: the browser confirms the push.
	 *
	 * @return void
	 */
	public function ack() {
		$order_id = isset( $_POST['order'] ) ? (int) $_POST['order'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token    = isset( $_POST['t'] ) ? (string) wp_unslash( $_POST['t'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result   = $this->confirm( $order_id, $token );
		if ( 'refused' === $result ) {
			wp_send_json_error( null, 400 );
		}
		wp_send_json_success( array( 'state' => $result ) );
	}

	/**
	 * Confirm one order from its token. The token is keyed with the site's salt
	 * and bound to the order's stored event id, so only the page the buyer saw
	 * can confirm it; a second confirmation changes nothing.
	 *
	 * @param  int    $order_id Order id.
	 * @param  string $token    Token from the page.
	 * @return string 'confirmed' | 'already' | 'refused'
	 */
	public function confirm( $order_id, $token ) {
		$order_id = (int) $order_id;
		$token    = (string) $token;
		if ( $order_id <= 0 || ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return 'refused';
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_callable( array( $order, 'get_meta' ) ) ) {
			return 'refused';
		}
		$event_id = (string) $order->get_meta( self::EVENT_ID_KEY );
		if ( '' === $event_id || ! hash_equals( self::ack_token( $order_id, $event_id ), $token ) ) {
			return 'refused';
		}
		if ( 'confirmed' === (string) $order->get_meta( self::STATE_KEY ) ) {
			return 'already';
		}
		$order->update_meta_data( self::STATE_KEY, 'confirmed' );
		$order->update_meta_data( self::TRANSACTION_SENT_KEY, wp_date( 'Y-m-d H:i:s' ) );
		$order->save_meta_data();
		return 'confirmed';
	}
}
