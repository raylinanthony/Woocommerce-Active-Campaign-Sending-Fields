<?php 
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');


class WooACFTFields{

	public $plugin_name = 'woo_actf';    
	private $address_fields_match = [
		'shipping_city'=> 'CITY',
		'shipping_address_1'=> 'ADDRESS',
		'shipping_postcode'=> 'ZIP_CODE',
		'shipping_state'=> 'STATE',
		'state'=> 'BILLING_STATE',
		'postcode'=> 'BILLING_ZIP_CODE',
		'address_1'=> 'BILLING_ADDRESS',
		'city'=> 'BILLING_CITY',
		//'total' => $order->get_total(),
		'sku' => 'SKU',
		'tag' => 'PRODUCT_TAG',
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

		add_action( 'init',array($this, 'woo_init' ));
		add_action( 'woocommerce_product_options_general_product_data',array($this,  'product_create_custom_field' ));
		add_action( 'woocommerce_process_product_meta', array($this, 'product_save_custom_field' ));

		add_filter( 'woocommerce_get_sections_integration', array($this,'acft_fields_fn' ));
		add_filter( 'woocommerce_get_settings_integration', array($this,'wcslider_all_settings'), 10, 2 );
		add_filter( 'woocommerce_general_settings',  array($this,'add_active_campaign_setting') );

	}
	
	public function woo_init(){
		$this->send_data();
		/*var_dump( $this->get_customer_total_order() ); die;*/
		//get_option( 'woo_ac_key'); die;
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

	public function get_customer_total_order() {
		$customer_orders = get_posts( array(
			'numberposts' => - 1,
			'meta_key'    => '_customer_user',
			'meta_value'  => get_current_user_id(),
			'post_type'   => array( 'shop_order' ),
			'post_status' => array( 'wc-completed' )
		) );

		$total = 0;
		$order_total = 0;
		foreach ( $customer_orders as $customer_order ) {
			$order = wc_get_order( $customer_order );
			$total += $order->get_total(); 
			$order_total++;
		}

		return array(wc_price($total), $order_total);
	}

	public function add_active_campaign_setting( $settings ) {

		$updated_settings = array();

		foreach ( $settings as $section ) {

			if ( isset( $section['id'] ) && 'general_options' == $section['id'] &&
				isset( $section['type'] ) && 'sectionend' == $section['type'] ) {

				$updated_settings[] = array(
					'name'     => __( 'Active Campaign Key', 'woo_actf' ), 
					'id'       => 'woo_ac_key',
					'type'     => 'text',
					'css'      => 'min-width:300px;', 
					'desc'     => __( 'Insert here your Active Campaign Key', 'woo_actf' ),
				);
		}

		$updated_settings[] = $section;
	}

	return $updated_settings;
}


public function send_data(){

	$order_id = 52;
	$arr_order = [];
	$skus = [];
	$tags = [];


	$order = new WC_Order( $order_id );
	$items = $order->get_items();
	$user_orders_data = $this->get_customer_total_order();
	$address = $order->get_address();


	$post_data = array(
		'api_key'      => 'ba85018585854429fe6ee4dc90fabb54ca36931ca11a838370d7629be65868028baa75bc', 
		'api_action'   => 'contact_sync', 
		'api_output'   => 'json',
		'email'                    => $address['email'],
		'first_name'               => $address['first_name'],
		'last_name'                => $address['last_name'], 
		'phone'                    => $address['phone'],
		'customer_acct_name'       => $address['address_1'].', '.$address['state'],
		'tags'                     => 'api'

	);

	foreach ( $items as $item ) {
		$product = $order->get_product_from_item( $item );
	//	$product = wc_get_product( $item->get_product_id() );
		$skus[] = $product->get_sku(); 
		$tags[] = $product->get_meta( 'prod_tag' ); 
	}

	$arr_order['total'] = $order->get_total();
	$arr_order['sku'] = implode(', ', $skus);
	$arr_order['tag'] = implode(', ', $tags);
	$arr_order['customer_total_purchased'] = $user_orders_data[0];
	$arr_order['customer_amount_purchased'] = $user_orders_data[1];


	
	if(is_admin()) return;

	//echo '<pre>',print_r($order->get_address()); die;
	//if(empty(get_option( 'woo_ac_key'))) return;

	// By default, this sample code is designed to get the result from your ActiveCampaign installation and print out the result
	$url = 'http://3aces66266.api-us1.com';



	foreach ($arr_order as $key => $val) {
		if($this->address_fields_match[$key]){
			$post_data['field[%'.$this->address_fields_match[$key].'%,0]' ] = $val;
		}
	}

	foreach ($address as $key => $val) {
		if($this->address_fields_match[$key]){
			$post_data['field[%'.$this->address_fields_match[$key].'%,0]' ] = $val;
		}
	}

	echo '<pre>',print_r($post_data); die;
 
	$api = $url . '/admin/api.php?api_action=contact_sync';

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

	var_dump($response);
	die;
}



} 