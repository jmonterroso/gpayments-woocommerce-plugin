<?php

class WC_GPayments_Connection extends WC_Payment_Gateway_CC {

	function __construct() {

		// global ID
		$this->id = "wc-4gpayments";

		// Show Title
		$this->method_title = __( "4GPayments", 'wc-4gpayments' );

		// Show Description
		$this->method_description = __( "Plugin para conectar 4Geeks Payments con WooCommerce. Si no tienes cuenta aún, créala en https://4geeks.io/payments", 'wc-4gpayments' );

		// vertical tab title
		$this->title = __( "4GPayments", 'wc-4gpayments' );


		$this->icon = null;

		$this->has_fields = true;

		// support default form with credit card
		$this->supports = array( 'default_credit_card_form' );

		// setting defines
		$this->init_form_fields();

		// load time variable setting
		$this->init_settings();

		// Turn these settings into variables we can use
		foreach ( $this->settings as $setting_key => $value ) {
			$this->$setting_key = $value;
		}

		// Save settings
		if ( is_admin() ) {
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		}
	} // Here is the  End __construct()


	// administration fields for specific Gateway
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'		=> __( 'Enable / Disable', 'wc-4gpayments' ),
				'label'		=> __( 'Enable this payment gateway', 'wc-4gpayments' ),
				'type'		=> 'checkbox',
				'default'	=> 'no',
			),
			'title' => array(
				'title'		=> __( 'Title', '4gpayments' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'Payment title of checkout process.', 'wc-4gpayments' ),
				'default'	=> __( 'Credit / Debit card', '4gpayments' ),
			),
			'description' => array(
				'title'		=> __( 'Description', '4gpayments' ),
				'type'		=> 'text',
				'default'	=> __( 'Pagar con tu tarjeta de débito o crédito.', 'wc-4gpayments' ),
				'css'		=> 'max-width:450px;'
			),
			'entity_description' => array(
				'title'		=> __( 'Detalle bancario', 'wc-4gpayments' ),
				'type'		=> 'text',
				'default' 	=> '4GP',
				'desc_tip'	=> __( 'Descripcion para el estado de cuenta de la tarjeta del cliete.', '4gpayments' ),
			),
			'charge_description' => array(
				'title'		=> __( '4GP Descripcion del cargo', 'wc-4gpayments' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'Es la descripcion por defecto de un cargo a la tarjeta de tu cliente', '4gpayments' ),
			),
			'client_id' => array(
				'title'		=> __( '4GP Client ID', 'wc-4gpayments' ),
				'type'		=> 'text',
				'desc_tip'	=> __( 'API Client ID provisto por 4Geeks Payments.', 'wc-4gpayments' ),
			),
			'client_secret' => array(
				'title'		=> __( '4GP Client Secret', 'wc-4gpayments' ),
				'type'		=> 'password',
				'desc_tip'	=> __( 'API Client Secret provisto por 4Geeks Payments.', 'wc-4gpayments' ),
			)
		);
	}

	// Response handled for payment gateway
	public function process_payment( $order_id ) {
		global $woocommerce;

		$customer_order = new WC_Order( $order_id );

		//API Auth URL
		$api_auth_url = 'https://api.payments.4geeks.io/authentication/token/';	

		//API base URL
		$api_url = 'https://api.payments.4geeks.io/v1/charges/simple/create/';

		$data_to_send = array("grant_type" => "client_credentials", 
								"client_id" => $this->client_id,
								"client_secret" => $this->client_secret );


		$response_token = wp_remote_post( $api_auth_url, array(
				'method' => 'POST',
				'timeout' => 90,
				'blocking' => true,
				'headers' => array('content-type' => 'application/json'),
				'body' => json_encode($data_to_send, true)
			) );

		$api_token = json_decode( wp_remote_retrieve_body($response_token), true)['access_token'];


		// This is where the fun stuff begins
		$payload = array(
			"amount"             	=> $customer_order->get_total(),
			"description"           => $this->description,
			"entity_description"    => "4GP",
			"currency"           	=> get_woocommerce_currency(),
			"credit_card_number"    => str_replace( array(' ', '-' ), '', $_POST['wc-4gpayments-card-number'] ),
			"credit_card_security_code_number" => str_replace( array(' ', '-' ), '', $_POST['wc-4gpayments-card-cvc'] ),
			"exp_month" 			=> substr($_POST['wc-4gpayments-card-expiry'], 0, 2),
			"exp_year" 				=> "20" . substr($_POST['wc-4gpayments-card-expiry'], -2),

		);

		// Send this payload to 4GP for processing
		$response = wp_remote_post( $api_url, array(
			'method'    => 'POST',
			'body'      => json_encode($payload, true),
			'timeout'   => 90,
			'blocking' => true,
			'headers' => array('authorization' => 'bearer ' . $api_token, 'content-type' => 'application/json'),
		 ) );


		if ( is_wp_error( $response ) )
			throw new Exception( __( 'Hubo un problema para comunicarse con el procesador de pagos...', 'wc-4gpayments' ) );

		if ( empty( $response['body'] ) )
			throw new Exception( __( 'La respuesta no obtuvo nada.', 'wc-4gpayments' ) );

		// get body response while get not error
		$responde_code = wp_remote_retrieve_response_code( $response );


		// 1 or 4 means the transaction was a success
		if ( $responde_code == 201 ) {
			// Payment successful
			$customer_order->add_order_note( __( 'Pago completo.', 'wc-4gpayments' ) );

			// paid order marked
			$customer_order->payment_complete();

			// this is important part for empty cart
			$woocommerce->cart->empty_cart();

			// Redirect to thank you page
			return array(
				'result'   => 'success',
				'redirect' => $this->get_return_url( $customer_order ),
			);
		} else {
			//transiction fail
			wc_add_notice( 'Error al procesar el pago', 'error' );
			$customer_order->add_order_note( 'Error: '. 'Error al procesar el pago' );
		}

	}

	// Validate fields
	public function validate_fields() {
		return true;
	}

	public function do_ssl_check() {
		if( $this->enabled == "yes" ) {
			if( get_option( 'woocommerce_force_ssl_checkout' ) == "no" ) {
				echo "<div class=\"error\"><p>". sprintf( __( "<strong>%s</strong> is enabled and WooCommerce is not forcing the SSL certificate on your checkout page. Please ensure that you have a valid SSL certificate and that you are <a href=\"%s\">forcing the checkout pages to be secured.</a>" ), $this->method_title, admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) ."</p></div>";
			}
		}
	}

}
?>
