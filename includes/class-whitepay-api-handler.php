<?php

if (!defined( 'ABSPATH')) {
	exit;
}

/**
 * Class for API requests to Whitepay.
 */
class Whitepay_API {

	/** @var string Whitepay API url */
    public static $api_url = 'https://api.whitepay.com/';

    /** @var string Whitepay Slug */
    public static $slug;

	/** @var string Whitepay Token */
	public static $token;

    /** @var string/array Log variable function */
    public static $log;

    /**
     * Call the $log variable function
     * @param string $message Log message
     * @param string $level   Optional. Default 'info'.
     *     emergency|alert|critical|error|warning|notice|info|debug
     */
    public static function log($message, $level = 'info') {
        return call_user_func(self::$log, $message, $level);
    }

	/**
	 * Request to Whitepay API
	 * @param string $endpoint
     * @param bool $dont_use_slug
	 * @param array  $params
	 * @param string $method
	 * @return array
	 */
	public static function send_request($endpoint, $dont_use_slug = false, $params = array(), $method = 'GET') {
		$request = array(
			'method'  => $method,
			'headers' => array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . self::$token
			)
		);

        $url = self::$api_url . $endpoint;
		if (!$dont_use_slug) {
            $url .= self::$slug;
        }

		if (in_array($method, array('POST', 'PUT'))) {
            $request['body'] = json_encode($params);
		} else {
			$url = add_query_arg($params, $url);
		}

		$response = wp_remote_request(esc_url_raw($url), $request);

        if (is_wp_error($response)) {
            self::log('WP error: ' . $response->get_error_message());
            return array(false, $response->get_error_message());
        } else {
            $result = json_decode($response['body'], true);

            $response_code      = $response['response']['code'];
            $response_message   = !empty($response['response']['message']) ? $response['response']['message'] : '';

            if (in_array($response_code, array(200, 201), true)) {
                return array(true, $result);
            } else {
                $message = self::get_response_error_message($response_code, $response_message);
                self::log($message);

                return array(false, $response_code);
            }
        }
	}

	/**
	 * Check if authentication is successful
	 * @return bool|string
	 */
	public static function check_auth() {
	    // If there will be a separate method on Whitepay to check authentication - change the endpoint
		$result = self::send_request('private-api/order-statuses', true);

		if (!$result[0]) {
			return false;
		}

		return true;
	}

    /**
     * Return response error message
     * @return string
     */
    public static function get_response_error_message ($response_code, $response_message) {
        $errors = array(
            400 => 'API response error: ' . $response_message,
            401 => 'Authorization error. Check your Token.',
        );

        if (array_key_exists($response_code, $errors)) {
            $message = $errors[$response_code];
        } else {
            $message = 'Unknown response error: ' . $response_code;
        }

        return $message;
    }

    /**
     * Get all applied fiat currencies for order creation
     * @return array
     */
	public static function get_order_creation_currencies () {
        $result = self::send_request('currencies/crypto-order-target-currencies', true);

        if (!$result[0]) {
            return false;
        }

        return $result[1]['currencies'];
    }

	/**
	 * Create a new order
	 * @param  int    $amount
	 * @param  string $currency
     * @param  string $order_id
	 * @param  string $return_url
	 * @param  string $cancel_url
	 * @return array
	 */
    public static function create_order ($amount = null, $currency = null, $order_id = null, $return_url = null, $cancel_url = null) {

        $params = array(
            'amount' => (float)$amount,
            'currency' => $currency,
            'external_order_id' => (string)$order_id,
        );

        // Return from acquiring page
        if (!is_null($return_url)) {
            $params['return_url'] = $return_url;
        }
        if (!is_null($cancel_url)) {
            $params['cancel_url'] = $cancel_url;
        }

        $result = self::send_request('private-api/crypto-orders/', false, $params, 'POST');

        return $result;
    }
}
