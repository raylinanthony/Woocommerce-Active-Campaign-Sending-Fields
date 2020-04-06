<?php 
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');


class WooACFTFields{

	public $plugin_name = 'woo_actf';    
	private $shipping_fields_match = [
		'city'=> 'CITY',
		'address_1'=> 'ADDRESS',
		'postcode'=> 'ZIP_CODE',
		'state'=> 'STATE', 
	];
	private $address_fields_match = [		
		'state'=> 'BILLING_STATE',
		'postcode'=> 'BILLING_ZIP_CODE',
		'address_1'=> 'BILLING_ADDRESS',
		'city'=> 'BILLING_CITY',
		//'total' => $order->get_total(), 
		//'tag' => 'PRODUCT_TAG',
		'customer_amount_purchased' => 'CUSTOMER_AMOUNT_PURCHASED',
		'customer_total_purchased' => 'CUSTOMER_TOTAL_PURCHASED' 
	];

	public function __construct(){
		@session_start();
		$this->run();	 

	}

	public static function init() {
		$class = __CLASS__;
		new $class;
	}
	public function run(){
		/**---------------
		Activating a new my sizes tab in the account page
		----------------------**/ 

		//add_action( 'init',array($this, 'woo_init' ));
		add_action( 'woocommerce_product_options_general_product_data',array($this,  'product_create_custom_field' ));
		add_action( 'woocommerce_process_product_meta', array($this, 'product_save_custom_field' ));
		add_action( 'woocommerce_order_status_processing', array($this,'wc_send_order' ),10,1);

		//add_filter( 'woocommerce_get_sections_integration', array($this,'acft_fields_fn' )); 
		add_filter( 'woocommerce_get_settings_general',  array($this,'add_active_campaign_setting') );

	}
	
	public function woo_init(){
		//$this->send_data();		 
	}

	public function product_create_custom_field() {
		$args = array(
			'id' => 'prod_tag',
			'label' => __( 'Product Tag', 'woo_actf' ),
			'class' => 'prod_tag_field',
			'desc_tip' => true,
			'description' => __( 'Enter a tagnem ex: "Bottle 1"', 'woo_actf' ),
		);
		woocommerce_wp_text_input( $args );
	}


	public function product_save_custom_field( $post_id ) {

		$product = wc_get_product( $post_id );

		$title = isset( $_POST['prod_tag'] ) ? $_POST['prod_tag'] : '';
		$product->update_meta_data( 'prod_tag', sanitize_text_field( $title ) );
		$product->save();
	}

	public function get_customer_total_order($user_id) {
 
		$customer_orders = get_posts( array(
			'numberposts' => - 1,
			'meta_key'    => '_customer_user',
			'meta_value'  => $user_id,
			'post_type'   => array( 'shop_order' ),
			'post_status' => array( 'wc-processing', 'wc-completed' )
		) );

		$total = 0;
		$order_total = 0;
		foreach ( $customer_orders as $customer_order ) {
			$order = wc_get_order( $customer_order );
			$total += $order->get_total(); 
			$order_total++;
		}

		return array(strip_tags(number_format($total,2)), $order_total);
	}

	public function add_active_campaign_setting( $settings ) {

		$updated_settings = array();

		foreach ( $settings as $section ) {

			if ( isset( $section['id'] ) && 'general_options' == $section['id'] &&
				isset( $section['type'] ) && 'sectionend' == $section['type'] ) {
				$updated_settings[] = array(
					'name'     => __( 'Active Campaign Account Name', 'woo_actf' ), 
					'id'       => 'woo_ac_account_name',
					'type'     => 'text',
					'css'      => 'min-width:300px;', 
					'desc'     => __( 'Ej: myaccountname', 'woo_actf' ),
				);
			$updated_settings[] = array(
				'name'     => __( 'Active Campaign Key', 'woo_actf' ), 
				'id'       => 'woo_ac_key',
				'type'     => 'text',
				'css'      => 'min-width:300px;', 
				'desc'     => __( 'Search in your Active Campaign Account: Settings -> Developer', 'woo_actf' ),
			);
				$updated_settings[] = array(
				'name'     => __( 'Active Campaign Default Tag', 'woo_actf' ), 
				'id'       => 'woo_ac_tag',
				'type'     => 'text',
				'css'      => 'min-width:300px;',  
			);
		}

		$updated_settings[] = $section;
	}

	return $updated_settings;
}


public function wc_send_order($order_id){
	

	if(empty($woo_actf_key = get_option( 'woo_ac_key'))) return;
	if(empty($woo_actf_accountname = get_option( 'woo_ac_account_name'))) return;

	$woo_ac_tag = get_option( 'woo_ac_tag');
	$arr_order = [];
	$skus = [];
	$tags = [$woo_ac_tag];


	$order = new WC_Order( $order_id );
	$items = $order->get_items();
	$user_orders_data = $this->get_customer_total_order($order->get_user_id());
	$address = $order->get_address();
	$shipping = $order->get_address('shipping');

	$post_data = array(
		'api_key'      => trim($woo_actf_key), 
		'api_action'   => 'contact_sync', 
		'api_output'   => 'json',
		'email'                    => $address['email'],
		'first_name'               => $address['first_name'],
		'last_name'                => $address['last_name'], 
		'phone'                    => $address['phone'],
		'customer_acct_name'       => get_bloginfo( 'name'),	 

	);

	foreach ( $items as $item ) {
		$product = $order->get_product_from_item( $item ); 
		$tags[] = $product->get_sku(); 
		$tags[] = $product->get_meta( 'prod_tag' ); 
	}

	$arr_order['total'] = $order->get_total(); 
	$post_data['tags'] = implode(', ', $tags);
	$arr_order['customer_total_purchased'] = $user_orders_data[0];
	$arr_order['customer_amount_purchased'] = $user_orders_data[1];

 


	$url = 'http://'.$woo_actf_accountname.'.api-us1.com';


	foreach ($arr_order as $key => $val) {
		if(!empty($val) and $this->address_fields_match[$key]){
			$post_data['field[%'.$this->address_fields_match[$key].'%,0]' ] = $val;
		}
	}
	foreach ($shipping as $key => $val) {
		if(!empty($val) and $this->shipping_fields_match[$key]){
			$post_data['field[%'.$this->shipping_fields_match[$key].'%,0]' ] = $val;
		}
	}
	foreach ($address as $key => $val) {
		if(!empty($val) and $this->address_fields_match[$key]){
			$post_data['field[%'.$this->address_fields_match[$key].'%,0]' ] = $val;
		}
	} 

	$api = $url . '/admin/api.php?api_action=contact_sync';

// echo '<pre>', print_r($post_data); die;
	
	$request = curl_init($api);  
	curl_setopt($request, CURLOPT_HEADER, 0);  
	curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);  
	curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($post_data)); 
	curl_setopt($request, CURLOPT_FOLLOWLOCATION, true);

	$response = (string)curl_exec($request);  

	curl_close($request);  

	if ( !$response ) {
		die(__('Nothing was returned. Do you have a connection to Email Marketing server?','woo_actf'));
	} 
}



} 