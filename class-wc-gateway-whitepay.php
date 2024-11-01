<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Whitepay_Gateway extends WC_Payment_Gateway {

    /** @var bool Whether or not logging is enabled */
    public static $log_enabled = false;

    /** @var WC_Logger Logger instance */
    public static $log = false;

    /** @var Whitepay_API Whitepay API instance */
    public $api;

    /** @var array Applied fiat currencies for order creation */
    public static $applied_currencies;

    /** @var array Array of Whitepay order statuses with WP associative statuses and notes */
    public static $statuses_association;

    /** @var string Current store currency */
    public static $currency;
    
    /**
     * Constructor for the gateway
     */
    public function __construct() {
        // General settings
        $this->id                   = 'whitepay';
        $this->icon                 = plugin_dir_url( __FILE__ ) . 'assets/images/whitepay-logo-32x32.png';
        $this->has_fields           = false;
        $this->order_button_text    = __('Proceed to Whitepay', 'whitepay-for-woocommerce');
        $this->method_title         = 'Whitepay';
        $this->method_description   = sprintf(__('Whitepay Payment Getaway allows your WooCommerce shop customers to pay for their orders in cryptocurrencies: Bitcoin, Ethereum, Litecoin, etc. If you do not currently have a Whitepay account, you can set one up on <a href="%s">Whitepay.com</a>', 'whitepay-for-woocommerce'), 'https://whitepay.com/');

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        // Customer fields
        $this->title        = $this->get_option( 'title' );
        $this->description  = $this->get_option( 'description' );
        $this->enabled      = $this->get_option( 'enabled' );
        $this->debug        = 'yes' === $this->get_option( 'debug', 'no' );

        // Logging
        self::$log_enabled  = $this->debug;

        // Initialise WHitepay API
        $this->init_api();

        // Initialise applied fiat currencies for order creation
        $this->init_applied_currencies();

        // Initialise Whitepay order statuses with associated WP order statuses
        $this->init_statuses_association();

        // Init store currency
        self::$currency  = get_woocommerce_currency();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_whitepay_gateway', array($this, 'whitepay_webhook'));
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields(){
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'whitepay-for-woocommerce'),
                'label'       => __('Enable Whitepay payment plugin', 'whitepay-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __('Title', 'whitepay-for-woocommerce'),
                'type'        => 'text',
                'description' => __('This is the title that the user will see as the name of the payment method on the checkout page.', 'btcpay-for-woocommerce'),
                'default'     => __('Cryptocurrencies via Whitepay', 'whitepay-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'whitepay-for-woocommerce'),
                'type'        => 'textarea',
                'description' => __('Description of the payment method that will be displayed to the user on the checkout page.', 'whitepay-for-woocommerce'),
                'default'     => __('Pay with cryptocurrency easily and quickly.', 'whitepay-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'slug' => array(
                'title'       => __('Slug', 'whitepay-for-woocommerce'),
                'type'        => 'text',
                'description' => sprintf(__('You can get your Slug by creating a page in the <a href="%s">Payment Pages</a> section of your Whitepay account', 'whitepay-for-woocommerce'), 'https://crm.whitepay.com/payment-pages'),
                'default'     => '',
            ),
            'token' => array(
                'title'       => __('Token', 'whitepay-for-woocommerce'),
                'type'        => 'text',
                'description' => sprintf(__('You can manage your Whitepay Token within the <a href="%s">Whitepay Settings Tokens page</a>', 'whitepay-for-woocommerce'), 'https://crm.whitepay.com/settings/tokens'),
                'default'     => '',
            ),
            'webhook_token' => array(
                'title'       => __('Webhook Token', 'whitepay-for-woocommerce'),
                'type'        => 'text',
                'description' =>
                    __('Using a webhook will allow you to receive data on changes in order status (paid, cancelled) from Whitepay. For this you need to:', 'whitepay-for-woocommerce')
                    . '<br/><br/>' .
                    sprintf( __('1. On the Whitepay Payment Pages section, on the "Webhook" tab, insert in the "Webhook address" field the following URL: %s', 'whitepay-for-woocommerce' ), add_query_arg('wc-api', 'WC_Whitepay_Gateway', home_url( '/', 'https' )))
                    . '<br/>' .
                    __('2. Click "Save", copy Webhook Token and paste it in field "Webhook Token" on this page.', 'whitepay-for-woocommerce')
                    . '<br/>' .
                    __('3. On the Whitepay Settings Tokens page, under Webhook settings, check which events you want to transmit.', 'whitepay-for-woocommerce'),

            ),
            'debug' => array(
                'title'       => __('Debug Log', 'whitepay-for-woocommerce'),
                'label'       => __('Enable/Disable logging', 'whitepay-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => sprintf(__('Log Whitepay API events inside %s', 'whitepay'), '<code>' . WC_Log_Handler_File::get_log_file_path('whitepay') . '</code>'),
                'default'     => 'yes'
            ),
        );
    }

    /**
     * Process the payment
     * @param  int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        if(!$this->api::check_auth()){
            return $this->get_custom_errors('auth');
        }
        if (!array_key_exists(self::$currency, self::$applied_currencies)) {
            return $this->get_custom_errors('supported_currency');
        }

        $order = wc_get_order($order_id);

        if ($order->get_total() < self::$applied_currencies[self::$currency]['min_amount']){
            return $this->get_custom_errors('min_amount');
        } elseif ($order->get_total() > self::$applied_currencies[self::$currency]['max_amount']) {
            return $this->get_custom_errors('max_amount');
        }

        $result = $this->api::create_order($order->get_total(), self::$currency, $order->get_id(), $this->get_return_url($order), $this->get_cancel_url($order));

        if (!$result[0]) {
            return array('result' => 'fail');
        }
        $whitepay_order = $result[1]['order'];

        $order->update_meta_data('_whitepay_status',    $whitepay_order['status']);
        $order->update_meta_data('_whitepay_order_id',  $whitepay_order['id']);
        $order->update_meta_data('_whitepay_order_url', $whitepay_order['acquiring_url']);
        $order->save();

        if (!empty($whitepay_order['acquiring_url'])) {
            return array(
                'result'    => 'success',
                'redirect'  => $whitepay_order['acquiring_url']
            );
        }
    }

    /**
     * Init Whitepay API class
     */
    protected function init_api() {
        include_once dirname( __FILE__ ) . '/includes/class-whitepay-api-handler.php';

        Whitepay_API::$slug      = $this->get_option( 'slug' );
        Whitepay_API::$token     = $this->get_option( 'token' );
        Whitepay_API::$log       = get_class($this) . '::log';

        $this->api = new Whitepay_API;
    }

    /**
     * Init applied currencies
     */
    protected function init_applied_currencies() {
        $applied_currencies = array();

        $whitepay_applied_currencies = $this->api::get_order_creation_currencies();

        if($whitepay_applied_currencies){
            foreach ($whitepay_applied_currencies as $c) {
                $applied_currencies[$c['ticker']] = array(
                    'min_amount' => $c['min_amount'],
                    'max_amount' => $c['max_amount'],
                );
            }
        }

        self::$applied_currencies = $applied_currencies;
    }

    /**
     * Initialise Whitepay order statuses association
     */
    public function init_statuses_association(){
        self::$statuses_association = array(
            'COMPLETE' => array(
                'wp_status' => 'processing',
                'note'      => __('Whitepay order was successfully completed', 'whitepay-for-woocommerce'),
            ),
            'DECLINED' => array(
                'wp_status' => 'failed',
                'note'      => __('Whitepay order is declined', 'whitepay-for-woocommerce'),
            ),
            'PATIALLY_FULFILLED' => array(
                'wp_status' => 'pendingblockchain',
                'note'      => __('Whitepay order is partially paid', 'whitepay-for-woocommerce'),
            ),
        );
    }

    /**
     * Get Whitepay Getaway custom errors
     */
    public function get_custom_errors($error){
        switch ($error) {
            case 'auth':
                wc_add_notice(__('Authentication attempt on Whitepay failed. Check Slug and Token in the Whitepay Getaway plugin settings', 'whitepay-for-woocommerce'), 'error');
                break;
            case 'supported_currency':
                $supported_currencies = '';
                foreach(self::$applied_currencies as $c => $v){
                    $supported_currencies .= ' ' . $c . ',';
                }
                wc_add_notice(sprintf(__('Your store currency not supported by Whitepay. Supported currencies: %s', 'whitepay-for-woocommerce'), trim($supported_currencies, ',')), 'error');
                break;
            case 'min_amount':
                wc_add_notice(sprintf(__('Order amount must be at least %d %s', 'whitepay-for-woocommerce'), self::$applied_currencies[self::$currency]['min_amount'], self::$currency), 'error');
                break;
            case 'max_amount':
                wc_add_notice(sprintf(__('Order amount may not be greater than %d %s', 'whitepay-for-woocommerce'), self::$applied_currencies[self::$currency]['max_amount'], self::$currency), 'error');
                break;
        }

        return array('result' => 'fail');
    }

    /**
     * Get cancel url
     * @param WC_Order $order
     * @return string
     */
    public function get_cancel_url($order) {
        $return_url = $order->get_cancel_order_url();

        if (is_ssl() || get_option('woocommerce_force_ssl_checkout') == 'yes') {
            $return_url = str_replace('http:', 'https:', $return_url);
        }

        return apply_filters('woocommerce_get_cancel_url', $return_url, $order);
    }

    /**
     * Webhook to get request from Whitepay when order status changed
     */
    public function whitepay_webhook() {
        $payload = file_get_contents('php://input');
        self::log('Get Webhook');
        self::log('Payload: ' . print_r($payload, true));
        
        if (!empty($payload) && $this->webhook_signature_validate($payload)) {
            $data = json_decode($payload, true);
            
            $whitepay_order = $data['order'];   
            if (!isset($whitepay_order['external_order_id']) || empty($whitepay_order['external_order_id']) || !($wc_order = wc_get_order($whitepay_order['external_order_id']))) {
                exit;
            }
         
            $this->update_wp_order_status($wc_order, $whitepay_order['status'], $whitepay_order['completed_at']);
            
            exit; 
        }

        wp_die('Whitepay Webhook Request Fail', 'Whitepay Webhook', array('response' => 500));
    }

    /**
     * Whitepay webhook signature validation
     * @param string $payload
     */
    public function webhook_signature_validate($payload) {
        $whitepay_signature = sanitize_text_field($_SERVER['HTTP_SIGNATURE']);

        if (!$whitepay_signature || empty($whitepay_signature)) {
            return false;
        }

        $webhook_token      = $this->get_option('webhook_token');
        /*$payload_json       = json_encode(json_decode($payload), JSON_UNESCAPED_SLASHES);*/
        $payload_json       = $payload;
        $signature          = hash_hmac('sha256', $payload_json, $webhook_token);

        self::log('Whitepay signature: ' . print_r($whitepay_signature, true));
        self::log('Local signature: ' . print_r($signature, true));

        return ($signature === $whitepay_signature) ? true : false;
    }

    /**
     * Update order status
     * @param WC_Order $order
     * @param string $new_status
     * @param datetime $completed_at
     */
    public function update_wp_order_status($order, $new_status, $completed_at = null) {
        
        $current_status = $order->get_meta( '_whitepay_status' );

        if ($new_status !== $current_status) {                     
            $order->update_meta_data('_whitepay_status', $new_status);

            if(!array_key_exists($new_status, self::$statuses_association)){
                $note = sprintf(__('Whitepay order changed status to %s', 'whitepay-for-woocommerce'), $new_status);
                
                $order->add_order_note($note);
            } else {
                $note = (!empty($completed_at)) ? self::$statuses_association[$new_status]['note'] . ' at ' . $completed_at : self::$statuses_association[$new_status]['note'];
                
                $order->update_status(self::$statuses_association[$new_status]['wp_status'], $note);
                if($new_status === 'COMPLETED'){
                    $order->payment_complete();
                }
            }
        }
    }

     /**
     * Logging
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'.
     *     emergency|alert|critical|error|warning|notice|info|debug
     */
    public static function log($message, $level = 'info') {
        if (self::$log_enabled) {
            if (empty(self::$log)) {
                self::$log = wc_get_logger();
            }
            self::$log->log($level, $message, array('source' => 'whitepay'));
        }
    }
}