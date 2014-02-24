<?php
/*
Plugin Name: Mpower Payment Gateway
Plugin URL: http://www.savekirk.com/mpower
Description: An Mpower Payments gateway for Easy Digital Downloads
Version: 1.0
Author: Saviour Kirk Agbenyegah
Author URI: savekirk@gmail.com
*/

//registers the gateway
function mp_edd_register_gateway($gateways) {
	$gateways['mpowerpayments'] = array('admin_label' => 'Mpower Payments', 'checkout_label'=>__('Mpower Payments','mp_edd'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'mp_edd_register_gateway');


// Disable the credit card form
add_action( 'edd_mpowerpayments_cc_form', '__return_false' );

//Add the Ghana cedis currency
function mp_edd_ghana_cedis($currency) {
	$currency['GHc'] = __('Ghana Cedis(GHc)','mp_edd');
	return $currency;
}
add_filter( 'edd_currencies', 'mp_edd_ghana_cedis' );

//add mpower payments logo
function mp_mpower_payment_logo($icons) {
	$p_url = plugins_url( '')."/mpowerpayments/mpower.png";
	$icons[$p_url] = __('Mpower Payments','mp_edd');
	return $icons;
}
add_filter( 'edd_accepted_payment_icons', 'mp_mpower_payment_logo');

// processes the payment
function mp_edd_process_payment( $purchase_data ) {

	global $edd_options;

	/**********************************
	* set transaction mode
	**********************************/

	if ( edd_is_test_mode() ) {
		//test url
		$mp_url = 'https://app.mpowerpayments.com/sandbox-api/v1/checkout-invoice/create';
		$headers = array(
			'Accept' => 'application/x-www-form-urlencoded',
		    'MP-Public-Key' => $edd_options['public_test_api_key'],
		    'MP-Private-Key' => $edd_options['private_test_api_key'],
		    'MP-Master-Key' => $edd_options['master_key'],
		    'MP-Token' => $edd_options['test_token'],
		    'MP-Mode' => $edd_options['test'],
		    'User-Agent' => "MPower Checkout Wordpress plugin" ); 
	} else {
		//live url
		$mp_url = 'https://app.mpowerpayments.com/api/v1/checkout-invoice/create';
		$headers = array(
			'Accept' => 'application/x-www-form-urlencoded',
		    'MP-Public-Key' => $edd_options['public_live_api_key'],
		    'MP-Private-Key' => $edd_options['private_live_api_key'],
		    'MP-Master-Key' => $edd_options['master_key'],
		    'MP-Token' => $edd_options['live_token'],
		    'MP-Mode' => $edd_options['live'],
		    'User-Agent' => "MPower Checkout Wordpress plugin" ); 
	}




	
	// check for any stored errors
	$errors = edd_get_errors();
	if ( ! $errors ) {

		$purchase_summary = edd_get_purchase_summary( $purchase_data );

		/****************************************
		* setup the payment details to be stored
		****************************************/

		$payment = array(
			'price'        => $purchase_data['price'],
			'date'         => $purchase_data['date'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency'     => $edd_options['currency'],
			'downloads'    => $purchase_data['downloads'],
			'cart_details' => $purchase_data['cart_details'],
			'user_info'    => $purchase_data['user_info'],
			'status'       => 'pending'
		);

		// record the pending payment
		$payment = edd_insert_payment( $payment );

		$items = array();
		/**********************************
		*Redirect to mpower for payment
		**********************************/
		//format product details to according to mpower api
		$id = 0;
		foreach ($purchase_data['cart_details'] as $pdk => $pd) {
			$items['item_'.$pd['id']] = array (
				'name' => $pd['name'],
				'quantity' => intval($pd['quantity']),
				'unit_price' => intval($pd['price']),
				'total_price' => round($pd['price']*$pd['quantity'], 2),
				'description' => NULL );
			$id++;
		}
		
		$mpower_data = array(
			'invoice' => array(
				'items' => $items,
				'taxes' => array(),
				'total_amount' => $purchase_data['price'],
				'decription' => NULL),
			'actions' => array (
				'cancel_url' =>  edd_get_failed_transaction_uri(),
				'return_url' => get_permalink( $edd_options['success_page']) ),
			'store' => array(
        		'name' => NULL,
        		'tagline' => NULL,
        		'postal_address' => NULL ,
        		'phone' => NULL,
       	 		'logo_url' => NULL,
       			'website_url' => get_site_url() 
     							 ),
     	    'custom_data' => $purchase_data['user_info'],
			);
		$jdata = json_encode($mpower_data);

		//send the data over to mpower payment api for authentication
		$response = wp_remote_post($mp_url, array('headers' => $headers,'body'=> $jdata) );
		if(!is_wp_error($response)){
		$response = json_decode($response['body'],true);
		}

		// check if the data submitted is correct and was successful
		if ($response['response_code'] == 00) {
			$receipt_url = $response['response_text'];
			//update the payment status to complete
			edd_update_payment_status( $payment, 'complete' );
			//redirect user to mpower payment site for further transactions
			wp_redirect($receipt_url);
			exit();
		}
		
	} else {
		$fail = true; // errors were detected
	}

	if ( $fail !== false ) {
		// if errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
	}
}
add_action( 'edd_gateway_mpowerpayments', 'mp_edd_process_payment' );


// adds the settings to the Payment Gateways section
function mp_edd_add_settings( $settings ) {

	$mpower_gateway_settings = array(
		array(
			'id' => 'mpower_gateway_settings',
			'name' => '<strong>' . __( 'Mpower Payments Gateway Settings', 'mp_edd' ) . '</strong>',
			'desc' => __( 'Configure the gateway settings', 'mp_edd' ),
			'type' => 'header'
		),
		array(
			'id' => 'master_key',
			'name' => __( 'Master key', 'mp_edd' ),
			'desc' => __( 'Enter your Master key, found in the Mpower Payments Integration Setup', 'mp_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'test_api_keys',
			'name' => '<strong>' . __( 'Test Api Keys', 'mp_edd' ) . '</strong>',
			'desc' => __( 'Configure the test api keys found in the Mpower Payments Integration Setup', 'mp_edd' ),
			'type' => 'header'
		),
		array(
			'id' => 'public_test_api_key',
			'name' => __( 'Public API Key', 'mp_edd' ),
			'desc' => __( 'Enter your Public Test API Key', 'mp_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'private_test_api_key',
			'name' => __( 'Private API Key', 'mp_edd' ),
			'desc' => __( 'Enter your Private Test API Key', 'mp_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'test_token',
			'name' => __( 'Token', 'mp_edd' ),
			'desc' => __( 'Enter your Test Token', 'mp_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'live_api_keys',
			'name' => '<strong>' . __( 'Live Api Keys', 'mp_edd' ) . '</strong>',
			'desc' => __( 'Configure the live api keys found in the Mpower Payments Integration Setup', 'mp_edd' ),
			'type' => 'header'
		),
		array(
			'id' => 'public_live_api_key',
			'name' => __( 'Public API Key', 'mp_edd' ),
			'desc' => __( 'Enter your Public Live API Key', 'mp_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'private_live_api_key',
			'name' => __( 'Private API Key', 'mp_edd' ),
			'desc' => __( 'Enter your Private Live API Key', 'mp_edd' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'live_token',
			'name' => __( 'Token', 'mp_edd' ),
			'desc' => __( 'Enter your Live Token', 'mp_edd' ),
			'type' => 'text',
			'size' => 'regular'
		)
	);

	return array_merge( $settings, $mpower_gateway_settings );
}
add_filter( 'edd_settings_gateways', 'mp_edd_add_settings' );
