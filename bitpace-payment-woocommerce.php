<?php

/**
 * Plugin Name: Bitpace Payment WooCommerce
 * Plugin URI: https://www.bitpace.com
 * Author Name: Garen Agbulut
 * Author Email: garenagbulut@gmail.com
 * Description: This plugin allows for getting coin payments
 * Version: 1.0.0
 * License: 1.0.0
 * License URL: http://www.gnu.org/licenses/gpl-2.0.txt
 * text-domain: bitpace-payment
 */

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

add_action('plugins_loaded', 'bitpace_payment_init');

function bitpace_payment_init() {
    include_once dirname(__FILE__) . '/library/bitpace-payment-api.php';

    if (class_exists('WC_Payment_Gateway')) {
        class WC_Bitpace_Payment_Gateway extends WC_Payment_Gateway
        {
            private $allowedCurrencies = array(
                'RUB' ,'EUR' ,'NOK' ,'USD' ,'TRY'
            );

            public function __construct()
            {
                $this->id = 'bitpace_payment';
                $this->icon = apply_filters('woocommerce_bitpace_icon', plugin_dir_path( __FILE__ ) . 'assets/icon.png');
                $this->has_fields = true;
                $this->method_title = 'Bitpace Coin Payment';
                $this->method_description = 'Make Payment with (BTC, LTC, XRP, ETH, BCH)';

                $this->init_form_fields();
                $this->title = 'Bitpace Coin Payment';

                $this->init_settings();

                if ($this->is_valid_for_use()) {
                    $this->enabled = $this->get_option( 'enabled' );
                } else {
                    $this->enabled = 'no';
                }

                $this->bitpace_test_mode = 'yes' === $this->get_option( 'bitpace_test_mode' );
                $this->bitpace_merchant_code = $this->get_option('bitpace_merchant_code');
                $this->bitpace_merchant_password = $this->get_option('bitpace_merchant_password');
                $this->bitpace_callback_secret = $this->get_option('bitpace_callback_secret');

                add_action('init', 'bitpace_callback');
                add_action('woocommerce_api_wc_bitpace_callback', array($this, 'bitpace_callback'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ));
            }

            public function init_form_fields()
            {
                $this->form_fields = array(
                    'enabled' => array(
                        'title'       => 'Enable/Disable',
                        'label'       => 'Enable Bitpace Coin Payment',
                        'type'        => 'checkbox',
                        'description' => '',
                        'default'     => 'no'
                    ),
                    'bitpace_merchant_code' => array(
                        'title'       => 'Merchant Code',
                        'required'    => true,
                        'type'        => 'text'
                    ),
                    'bitpace_merchant_password' => array(
                        'title'       => 'Merchant Password',
                        'required'    => true,
                        'type'        => 'password'
                    ),
                    'bitpace_callback_secret' => array(
                        'title'       => 'Callback Secret',
                        'required'    => true,
                        'type'        => 'text'
                    ),
                    'bitpace_test_mode' => array(
                        'title'       => 'Test Mode',
                        'type'        => 'checkbox',
                        'label'       => 'Enable Test Mode',
                        'default'     => 'no'
                    )
                );
            }

            public function bitpace_callback()
            {
                $rawData = file_get_contents('php://input');
                $data = json_decode($rawData, true);
                $bitpace_order_id = $data['order_id'];

                if (!isset($bitpace_order_id) || empty($bitpace_order_id)) {
                    return;
                }

                global $woocommerce;

                $bitpaceApi = new BitpacePaymentApi();
                $order_id = $bitpaceApi->getOrderId($bitpace_order_id);

                if (is_null($order_id)) {
                    return;
                }

                $order = wc_get_order( $order_id );

                $callback_info = [
                    'bitpace_order_id' => $bitpace_order_id,
                    'status' => $data['status'],
                    'type' => $data['type'],
                    'customer_reference_id' => $data['customer_reference_id'],
                    'order_amount' => $data['order_amount'],
                    'fee_amount' => $data['fee_amount'],
                    'currency' => $data['currency'],
                    'cryptocurrency_amount' => $data['cryptocurrency_amount'],
                    'cryptocurrency' => $data['cryptocurrency'],
                    'blockchain_tx_id' => $data['blockchain_tx_id']
                ];

                $callback_secret = get_option('woocommerce_bitpace_payment_settings')['bitpace_callback_secret'];
                $callback_hash = hash('sha256', $rawData . $callback_secret);

                if ($callback_hash != $_SERVER['HTTP_SIGNATURE']) {
                    return;
                }

                if ($callback_info['status'] == 'COMPLETED' || $callback_info['status'] == 'ACCEPTED') {
                    $order->update_status('completed');
                    $bitpaceApi->updateOrder($callback_info);
                } elseif ($callback_info['status'] == 'FAILED') {
                    $order->update_status('failed');
                }
            }

            public function process_payment( $order_id )
            {
                global $woocommerce;

                $order = wc_get_order( $order_id );
                $amount = $order->get_total();
                $currency = get_woocommerce_currency();
                $merchantCustomerId = $order->get_user_id();

                $successUrl = $order->get_checkout_order_received_url();
                $failUrl = $order->get_checkout_payment_url();

                $request_data = array(
                    'order_amount' => number_format($amount, 2, '.', ','),
                    'return_url' => $successUrl,
                    'failure_url' => $failUrl,
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'customer' => array(
                        'reference_id' => $this->generateCustomerReference($merchantCustomerId),
                        'first_name' => $order->get_billing_first_name(),
                        'last_name' => $order->get_billing_last_name(),
                        'email' => $order->get_billing_email()
                    )
                );

                $bitpaceApi = new BitpacePaymentApi();
                $response = $bitpaceApi->createFixedDepositUrl($request_data);

                if (isset($response->data) && isset($response->data->payment_url)) {
                    $bitpaceApi->insertOrder($order, $response->data);

                    return array(
                        'result' => 'success',
                        'redirect' => $response->data->payment_url
                    );
                } else {
                    wc_add_notice(  'An error occured', 'error' );
                }
            }

            public function is_valid_for_use () {
                return in_array(get_woocommerce_currency(), $this->allowedCurrencies);
            }

            private function generateCustomerReference($customerId)
            {
                return $customerId . microtime(true) * 10000;
            }
        }
    }
}

add_filter('woocommerce_payment_gateways', 'bitpace_add_to_gateways');

function bitpace_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Bitpace_Payment_Gateway';
    return $gateways;
}

register_activation_hook(__FILE__, 'bitpacecom_plugin_activated');
register_deactivation_hook(__FILE__, 'bitpacecom_plugin_deactivated');

function bitpacecom_plugin_activated()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'bitpace_order';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
                    `id` int auto_increment,
                    `order_id` int(11) NOT NULL,
                    `bitpace_order_id` varchar(64) DEFAULT NULL,
                    `blockchain_tx_id` varchar(100) DEFAULT NULL,
                    `cryptocurrency_amount` decimal(12,6) DEFAULT NULL,
                    `cryptocurrency` varchar(8) DEFAULT NULL,
                    `total` decimal(10,2) DEFAULT NULL,
                    `currency_code` varchar(5) DEFAULT NULL,
                    `payment_url` varchar(200) NOT NULL,
                    `date_added` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `date_modified` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(`id`)
                ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta($sql);
}

function bitpacecom_plugin_deactivated()
{
    global $wpdb;

    delete_option('woocommerce_bitpace_payment_settings');

    $table_name = $wpdb->prefix . 'bitpace_order';

    $sql = "DROP TABLE IF EXISTS $table_name;";
    $wpdb->query($sql);
    flush_rewrite_rules();
}
