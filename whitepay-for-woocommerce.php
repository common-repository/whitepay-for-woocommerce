<?php
/**
 * Plugin Name: Whitepay for WooCommerce
 * Plugin URI:  https://whitepay.com/
 * Description: А payment getaway that allows your customers to pay using cryptocurrency through Whitepay.com
 * Author:      Whitepay
 * Author URI:  https://whitepay.com/
 * Version:     1.0.1
 * License:     GPLv3+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: whitepay-for-woocommerce
 * Domain Path: /languages/
 */

/** New order status ID for pending payment by Whitepay */
const WHITEPAY_ORDER_STATUS_ID  = 'wc-pendingblockchain';

/**
 * Plugin init
 */
function whitepay_init() {
    if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        // Init Whitepay Getaway class
        require_once 'class-wc-gateway-whitepay.php';
        add_filter('woocommerce_payment_gateways', 'whitepay_register_gateway_class');

        // Register and add new order status
        if(!in_array(WHITEPAY_ORDER_STATUS_ID, wc_get_order_statuses())){
            add_action('init', 'whitepay_wc_register_blockchain_status');
            add_filter('woocommerce_valid_order_statuses_for_payment', 'whitepay_wc_status_valid_for_payment', 10, 2);
            add_filter('wc_order_statuses', 'whitepay_wc_add_status');
        }

        // Add Whitepay meta data to order
        add_action('woocommerce_admin_order_data_after_order_details', 'add_whitepay_order_meta');
        add_action('woocommerce_order_details_after_order_table', 'add_whitepay_order_meta');

        // Add Whitepay meta data to email
        add_filter( 'woocommerce_email_order_meta_fields', 'whitepay_email_order_meta_fields', 10, 3 );
        add_filter( 'woocommerce_email_actions', 'whitepay_register_email_action' );
        add_action( 'woocommerce_email', 'whitepay_add_email_triggers' );

        // Init localisation
        load_plugin_textdomain('whitepay-for-woocommerce', false, dirname(plugin_basename( __FILE__ )) . '/languages/');
    }
}
add_action('plugins_loaded', 'whitepay_init');

/**
 * Аdd WC_Whitepay_Gateway class to existing gateways
 * @param array $gateways
 * @return array
 */
function whitepay_register_gateway_class($gateways) {
    $gateways[] = 'WC_Whitepay_Gateway';
    return $gateways;
}

/**
 * Register new order status
 */
function whitepay_wc_register_blockchain_status() {
    register_post_status(WHITEPAY_ORDER_STATUS_ID, array(
        'label'                     => __('Pending blockchain payment', 'whitepay'),
        'public'                    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop('Pending blockchain <span class="count">(%s)</span>', 'Pending blockchain <span class="count">(%s)</span>'),
    ));
}

/**
 * Register new order status as valid for payment.
 */
function whitepay_wc_status_valid_for_payment($statuses, $order) {
    $statuses[] = WHITEPAY_ORDER_STATUS_ID;
    return $statuses;
}

/**
 * Add registered status to list of WC Order statuses
 * @param array $wc_statuses_arr all order statuses
 * @return array
 */
function whitepay_wc_add_status($wc_statuses_arr) {
    $new_statuses_arr = array();
    foreach ($wc_statuses_arr as $id => $label) {
        $new_statuses_arr[$id] = $label;

        if ('wc-pending' === $id) {
            $new_statuses_arr[WHITEPAY_ORDER_STATUS_ID] = __('Pending blockchain payment', 'whitepay');
        }
    }

    return $new_statuses_arr;
}

/**
 * Add to order Whitepay meta
 * @param WC_Order $order WC order instance
 */
function add_whitepay_order_meta ($order) {
    if ($order->get_payment_method() == 'whitepay') {
        $whitepay_order_id      = $order->get_meta('_whitepay_order_id');
        $whitepay_order_link    = $order->get_meta('_whitepay_order_url');
        ?>

        <br class="clear"/>
        <h3><?php echo esc_html( __('Whitepay Payment Data', 'whitepay-for-woocommerce')); ?></h3>
        <div class="">
            <p>Whitepay Order Id: <br/><?php echo esc_html($whitepay_order_id); ?></p>
            <p>Whitepay Order Link: <a href="<?php echo esc_url($whitepay_order_link); ?>"><?php echo esc_url($whitepay_order_link); ?></p>
        </div>
        <?php
    }
}

/**
 * Add Whitepay order data to email
 * @param $fields
 * @param $sent_to_admin
 * @param $order
 * @return mixed
 */
function whitepay_email_order_meta_fields($fields, $sent_to_admin, $order) {
    if ($order->get_payment_method() == 'whitepay') {
        $fields['whitepay_payment_data'] = array(
            'label' => __('Whitepay Payment Data', 'whitepay-for-woocommerce'),
            'value' => $order->get_meta('_whitepay_order_url'),
        );
    }

    return $fields;
}

/**
 * Register new email action
 * @param $email_actions
 * @return mixed
 */
function whitepay_register_email_action($email_actions) {
    $email_actions[] = 'wc_status_pendingblockchain_changing_notification';

    return $email_actions;
}

/**
 * Add email triggers
 * @param $wc_emails
 */
function whitepay_add_email_triggers($wc_emails) {
    $emails = $wc_emails->get_emails();
    $processing_order_emails = apply_filters('whitepay_processing_order_emails', ['WC_Email_New_Order', 'WC_Email_Customer_Processing_Order']);

    foreach ($processing_order_emails as $email_class) {
        if (isset($emails[ $email_class ])) {
            $email = $emails[$email_class];
            add_action('wc_status_pendingblockchain_changing_notification', array($email, 'trigger'));
        }
    }
}