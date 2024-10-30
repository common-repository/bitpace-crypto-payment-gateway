<?php

class BitpacePaymentApi
{
    private $options;
    private $database;
    private $table_name;

    public function __construct()
    {
        $this->options = get_option('woocommerce_bitpace_payment_settings');
        $this->database = $GLOBALS['wpdb'];
        $this->table_name = $this->database->prefix . 'bitpace_order';
    }

    public function createFixedDepositUrl($data)
    {
        $authorization_token = $this->getAuthorizationToken();

        if ($this->options['bitpace_test_mode'] == 'yes') {
            $url = 'https://api-sandbox.bitpace.com/api/v1/fixed-deposit/url';
        } else {
            $url = 'https://api.bitpace.com/api/v1/fixed-deposit/url';
        }

        return $this->makeRequest($data, $authorization_token, $url);
    }

    public function getAuthorizationToken()
    {
        $request_data = array(
            'merchant_code' => $this->options['bitpace_merchant_code'],
            'password' => $this->options['bitpace_merchant_password']
        );

        if ($this->options['bitpace_test_mode'] == 'yes') {
            $url = 'https://api-sandbox.bitpace.com/api/v1/auth/token';
        } else {
            $url = 'https://api.bitpace.com/api/v1/auth/token';
        }

        $result = $this->makeRequest($request_data, false, $url);

        if (isset($result->data)) {
            return $result->data->token;
        }

        return false;
    }

    public function getOrderId($bitpace_order_id)
    {
        $fieldName  = 'order_id';

        $query = $this->database->prepare("
                    SELECT {$fieldName} FROM {$this->table_name} 
                    WHERE  bitpace_order_id = %d ORDER BY order_id DESC LIMIT 1;
                    ", $bitpace_order_id
        );

        $result = $this->database->get_col($query);

        if (isset($result[0])) {
            return $result[0];
        } else {
            return null;
        }
    }

    public function updateOrder($order_info)
    {
        $this->database->update(
            $this->table_name,
            array(
                'blockchain_tx_id' => $order_info->blockchain_tx_id,
                'cryptocurrency_amount' => $order_info->cryptocurrency_amount,
                'cryptocurrency' => $order_info->cryptocurrency
            ),
            array(
                'bitpace_order_id' => $order_info->bitpace_order_id
            )
        );
    }

    public function insertOrder($order, $response_data)
    {
        $this->database->insert(
            $this->table_name,
            array(
                'bitpace_order_id' => $response_data->order_id,
                'order_id' => $order->get_id(),
                'currency_code' => $order->get_currency(),
                'total' => $order->get_total(),
                'payment_url' => $response_data->payment_url
            ),
            array(
                '%d',
                '%d',
                '%s',
                '%s',
                '%s'
            )
        );
    }

    public function makeRequest($data, $authorization_token, $url)
    {
        $args = [
            'headers' => [
                "Content-Type" => "application/json",
            ],
            'body' => json_encode($data)
        ];

        if ($authorization_token) {
            $args['headers']['Authorization'] = $authorization_token;
        }

        $result = wp_remote_post($url, $args);
        return json_decode($result['body']);
    }
}
