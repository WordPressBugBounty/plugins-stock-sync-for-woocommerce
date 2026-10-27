<?php

/**
 * Prevent direct access to the script.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Stock_Sync_Api_Request {
	public $errors = [];
	public $timings = [];
	public $keyed_sites = [];
	public $changes = [];
	public $start = false;
	public $end = false;
	public $idempotency_key = '';
	public $is_retry = false;
	public $response;

	/**
	 * Constructor
	 */
	public function __construct() {
	}

	/**
	 * Push multiple changes in one request
	 */
	public function push_multiple( $data, $sites, $idempotency_key = false, $is_retry = false ) {
		update_option( 'wss_last_sync', time() );

		$this->is_retry = $is_retry;

		if ( ! $idempotency_key ) {
			$idempotency_key = sprintf( 'wss_%s', uniqid() );
		}
		$this->idempotency_key = $idempotency_key;

		$params = $this->get_request_params( $data );

		// No products with SKUs, skip
		if ( ! $params ) {
			return true;
		}

		// No sites defined, skip
		if ( empty( $sites ) ) {
			return true;
		}

		$responses = $this->push_requests( $params, $sites );

		foreach ( $responses as $site_key => $response ) {
			$this->handle_response( $response, $site_key, $params );
		}

		return $responses;
	}

	/**
	 * Push using Requests library
	 * 
	 * @return array
	 */
	public function push_requests( $data, $sites ) {
		$requests = [];
		$this->timings = [];
		$this->keyed_sites = [];
		$timeout = $this->get_time_limit();
		foreach ( $sites as $site ) {
			$this->timings[$site['key']]['start'] = microtime( true );

			$request = [
				'url' => $this->build_url( $site ),
				'type' => 'POST',
				'data' => json_encode( $data ),
				'headers' => [
					'Content-Type' => 'application/json;charset=utf-8',
					'Accept' => 'application/json',
				],
				'options' => [
					'cookies' => [],
					'verify' => false,
					'timeout' => ( $timeout - 1 ),
				]
			];

			$request = $this->authenticate( $request, $site );

			$requests[$site['key']] = $request;
			$this->keyed_sites[$site['key']] = $site;
		}

		$options = [
			'complete' => function ( $response, $id ) {
				if ( isset( $this->timings[$id]['start'] ) ) {
					$this->timings[$id]['duration'] = microtime( true ) - $this->timings[$id]['start'];
				}
			},
		];

		$this->start = microtime( true );
		try {
			$responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
		} catch ( \Exception $e ) {
			// Fill responses with the exception so we can handle them in handle_response
			$responses = [];
			foreach ( $requests as $site_key => $request ) {
				$responses[$site_key] = $e;
			}
		}
		$this->end = microtime( true );

		// Reset time limit to allow time for processing
		set_time_limit( $timeout );

		foreach ( $responses as $key => $response ) {
			$responses[$key] = $this->parse_response( $response, $key );
		}

		return $responses;
	}

	/**
	 * Get time limit for requests
	 */
	protected function get_time_limit() {
		$php_limit = (int) ini_get( 'max_execution_time' );
		if ( $php_limit === 0 ) {
			$php_limit = 120;
		}

		return apply_filters( 'woo_stock_sync_api_timeout', $php_limit );
	}

	/**
	 * Handle response
	 */
	protected function handle_response( $response, $site_key, $data ) {
		foreach ( $data['update'] as $row ) {
			$level = '';
			$product_errors = [];
			$sku = $row['sku_param'];
			$local_product_id = $row['local_id'];

			if ( $response['error'] === false ) {
				$ext_product = isset( $response['products'][$sku] ) ? $response['products'][$sku] : false;

				if ( ! isset( $ext_product->error ) && isset( $ext_product->id ) ) {
					wss_update_ext_qty(
						$local_product_id,
						$site_key,
						$ext_product->stock_quantity,
						$ext_product->id,
						$ext_product->name,
						$ext_product->parent_id
					);

					$level = 'ok';
				} else {
					$error_msg = isset( $ext_product->error->message ) ? $ext_product->error->message : 'N/A';
					$error_code = isset( $ext_product->error->code ) ? $ext_product->error->code : 'n_a';

					if ( $error_code === 'woocommerce_rest_product_invalid_sku' ) {
						$level = 'warning';
						$product_errors['not_found'] = __( "Product not found by SKU", 'woo-stock-sync' );

						wss_reset_sync_status( $local_product_id, $site_key );
					} else {
						$level = 'error';
						$product_errors[$error_code] = $error_msg;

						wss_update_mismatch_data( $local_product_id, $site_key );
					}
				}
			} else {
				$product_errors['connection_error'] = $response['error'];
				$level = 'error';

				wss_update_mismatch_data( $local_product_id, $site_key );
			}

			if ( wss_is_primary() ) {
				if ( isset( $row['log_id'] ) && ! empty( $row['log_id'] ) ) {
					$total_duration = $this->is_retry ? false : ( $this->end - $this->start );

					Woo_Stock_Sync_Logger::log_update(
						$row['log_id'],
						$site_key,
						empty( $product_errors ),
						$product_errors,
						$level,
						$response['error_data'],
						( isset( $this->timings[$site_key]['duration'] ) ? $this->timings[$site_key]['duration'] : false ),
						$total_duration
					);
				}
			} else {
				if ( $response['error'] ) {
					// Log complete failure
					if ( ! isset( $GLOBALS['wss_log_ids'][$this->idempotency_key] ) ) {
						$log_id = Woo_Stock_Sync_Logger::log(
							sprintf( __( 'Failed to sync multiple changes. Changes:<br>%s<br>Errors: %s', 'woo-stock-sync' ), implode( '<br>', $this->changes ), $response['error'] ),
							'error',
							null,
							[
								'source' => get_site_url(),
								'source_desc' => isset( $row['source_desc'] ) ? $row['source_desc'] : null,
								'source_url' => isset( $row['source_url'] ) ? $row['source_url'] : null,
							]
						);
					} else {
						$log_id = $GLOBALS['wss_log_ids'][$this->idempotency_key];
					}

					// Add error data for the site
					Woo_Stock_Sync_Logger::log_update( $log_id, $site_key, false, [ $response['error'] ], 'error', $response['error_data'] );

					// This will be used to handle retry, otherwise it would cause
					// two entries in the log
					if ( ! isset( $GLOBALS['wss_log_ids'] ) ) {
						$GLOBALS['wss_log_ids'] = [];
					}
					$GLOBALS['wss_log_ids'][$this->idempotency_key] = $log_id;
				} else {
					// Clear previous error if the retry succeeded
					if ( isset( $GLOBALS['wss_log_ids'][$this->idempotency_key] ) ) {
						$log_id = $GLOBALS['wss_log_ids'][$this->idempotency_key];
						Woo_Stock_Sync_Logger::delete( $log_id );
					}
				}
			}
		}
	}

	/**
	 * Parse response
	 * 
	 * @param \WpOrg\Requests\Response|\WpOrg\Requests\Exception $response
	 * @param string $site_key
	 * 
	 * @return array
	 */
	protected function parse_response( $response, $site_key ) {
		$is_exception = ( $response instanceof \Exception );
		$response_code = false;
		$body = false;
		$raw_body = '';
		$error = $is_exception ? $response->getMessage() : false;
		$error_data = [
			'code' => false,
			'headers' => [],
			'body' => '',
		];
		$headers = [];
		$products = [];

		if ( ! $is_exception ) {
			$response_code = (int) $response->status_code;
			$raw_body = $response->body;
			if ( 0 === strpos( bin2hex( $raw_body ), 'efbbbf' ) ) {
				$raw_body = substr( $body, 3 );
			}
			$body = json_decode( $raw_body );
			$headers = array_map( function( $value ) {
				if ( is_array( $value ) ) {
					$value = implode( ',', $value );
				}

				return $value;
			}, $response->headers->getAll() );

			if ( $body && is_object( $body ) && isset( $body->update ) && is_array( $body->update ) ) {
				foreach ( $body->update as $product ) {
					if ( isset( $product->sku ) && ! empty( $product->sku ) ) {
						$products[$product->sku] = $product;
					}
				}
			}

			if ( $response_code !== 200 || JSON_ERROR_NONE !== json_last_error() ) {
				$error = sprintf( __( "Invalid response code %s", 'woo-stock-sync' ), $response_code );
				$error_data = [
					'code' => $response_code,
					'headers' => $headers,
					'body' => substr( $raw_body, 0, 65535 ),
				];

				if ( is_object( $body ) && isset( $body->code, $body->message ) && ! empty( $body->message ) ) {
					$error = sprintf( '%s (%s)', $body->message, $body->code );
				}

				if (JSON_ERROR_NONE !== json_last_error()) {
					$error = 'Invalid JSON returned';
					if ( function_exists( 'json_last_error_msg' ) ) {
						$error .= sprintf( ': %s', json_last_error_msg());
					}
				}
			}
		}

		$site = $this->keyed_sites[$site_key];

		return [
			'formatted_url' => $site['formatted_url'],
			'is_exception' => $is_exception,
			'error' => $error,
			'error_data' => $error_data,
			'response_code' => $response_code,
			'body' => $body,
			'products' => $products,
			'raw_body' => $raw_body,
			'headers' => $headers,
		];
	}

	/**
	 * Add authentication parameters
	 */
	protected function authenticate( $request, $site ) {
		$is_ssl = 'https://' === \substr( $request['url'], 0, 8 );

		if ( ! $is_ssl ) {
			$request['url'] = $this->get_oauth_params( $request, $site );
		} else {
			$request['url'] = $this->get_basic_auth_params( $request, $site );
		}

		return $request;
	}

	/**
	 * Get oauth parameters
	 */
	protected function get_oauth_params( $request, $site ) {
		$oauth = new \Automattic\WooCommerce\HttpClient\OAuth(
			$request['url'],
			$site['api_key'],
			$site['api_secret'],
			'wc/v2',
			$request['type'],
			[],
			time()
		);

		$params = (array) $oauth->getParameters();
		foreach ( $params as $key => $param ) {
			$params[$key] = urlencode( $param );
		}

		return add_query_arg( $params, $request['url'] );
	}

	/**
	 * Get basic auth parameters
	 */
	protected function get_basic_auth_params( $request, $site ) {
		return add_query_arg( [
			'consumer_key' => $site['api_key'],
			'consumer_secret' => $site['api_secret'],
		], $request['url'] );
	}

	/**
	 * Build URL
	 */
	protected function build_url( $site ) {
		$url = rtrim( $site['url'], '/' ) . '/wp-json/wc/v2/stock-sync-batch';

		return $url;
	}

	/**
	 * Get request parameters
	 */
	protected function get_request_params( $data ) {
		// We need to pass these params for each row due to REST controller
		// batch update logic
		$params_defaults = [
			'woo_stock_sync' => '1',
			'woo_stock_sync_source' => get_site_url(),
			'woo_stock_sync_source_role' => wss_get_role(),
			'context' => 'edit',
		];

		$params = ['update' => []];
		$this->changes = [];
		foreach ( $data as $row ) {
			$product = $row['product'];
			$sku = (string) $product->get_sku( 'edit' );

			if ( strlen( $sku ) > 0 ) {
				$row_params = array_merge( $params_defaults, [
					'sku_param' => $sku,
					'source_desc' => isset( $row['source_desc'] ) ? $row['source_desc'] : null,
					'source_url' => isset( $row['source_url'] ) ? $row['source_url'] : null,
					'log_id' => isset( $row['log_id'] ) ? $row['log_id'] : null,
					'remote_addr' => isset( $row['remote_addr'] ) ? $row['remote_addr'] : null,
					'request_uri' => isset( $row['request_uri'] ) ? $row['request_uri'] : null,
					'referer' => isset( $row['referer'] ) ? $row['referer'] : null,
					'is_cli' => isset( $row['is_cli'] ) ? $row['is_cli'] : null,
					'user_id' => isset( $row['user_id'] ) ? $row['user_id'] : null,
					'username' => isset( $row['username'] ) ? $row['username'] : null,
					'local_id' => $product->get_id(),
				] );

				switch ( $row['operation'] ) {
					case 'set':
						$row_params['stock_quantity'] = $row['value'];
						$this->changes[] = sprintf( '%s set %d', $product->get_formatted_name(), $row['value'] );
						break;
					case 'increase':
						$row_params['inventory_delta'] = absint( $row['value'] );
						$this->changes[] = sprintf( '%s increase by %d', $product->get_formatted_name(), absint( $row['value'] ) );
						break;
					case 'decrease':
						$row_params['inventory_delta'] = -1 * absint( $row['value'] );
						$this->changes[] = sprintf( '%s decrease by %d', $product->get_formatted_name(), absint( $row['value'] ) );
						break;
				}

				$params['update'][] = $row_params;
			}
		}

		// No products with SKUs, skip
		if ( empty( $params['update'] ) ) {
			return false;
		}

		$params['woo_stock_sync'] = '1';
		$params['woo_stock_sync_source'] = get_site_url();
		$params['woo_stock_sync_source_role'] = wss_get_role();
		$params['context'] = 'edit';
		$params['idempotency_key'] = $this->idempotency_key;

		return apply_filters( 'wss_params_push_multiple', $params, $data, null );
	}

	/**
	 * Update sync status for products
	 */
	public function update( $site, $products ) {
		update_option( 'wss_last_sync', time() );

		$client = $this->get_api_client( $site );

		// Create an array of SKUs
		$skus = [];
		foreach ( $products as $product ) {
			$sku = (string) $product->get_sku( 'edit' );

			if ( strlen( $sku ) > 0 ) {
				$skus[] = $sku;
			}
		}

		$ext_products = [];

		// Fetch external products by SKU
		try {
			$client->get( 'products', [
				'sku' => implode( ',', $skus ),
				'per_page' => 100,
				'context' => 'edit',
			] );
		} catch ( \Exception $e ) {
			$this->errors['exception_update'] = sprintf( __( "Exception while trying to update sync status. Message: %s", 'woo-stock-sync' ), $e->getMessage() );

			if ( is_a( $e, 'Automattic\WooCommerce\HttpClient\HttpClientException' ) ) {
				$this->response = $e->getResponse();
			}

			return false;
		}

		$response = $client->http->getResponse();
		if ( $response->getCode() === 200 ) {
			$body = $response->getBody();
			if ( 0 === strpos( bin2hex( $body ), 'efbbbf' ) ) {
				$body = substr( $body, 3 );
			}
			$results = json_decode( $body );

			foreach ( $results as $result ) {
				$ext_products[$result->sku] = $result;
			}
		} else {
			$this->errors['invalid_response'] = sprintf( __( "Invalid response by API. HTTP status code: %s", 'woo-stock-sync' ), $response->getCode() );

			return false;
		}

		// Check which products have corresponding external product
		foreach ( $products as $product ) {
			$sku = (string) $product->get_sku( 'edit' );

			if ( strlen( $sku ) > 0 && isset( $ext_products[$sku] ) ) {
				$ext_product = $ext_products[$sku];

				wss_update_ext_qty(
					$product,
					$site['key'],
					$ext_product->stock_quantity,
					$ext_product->id,
					$ext_product->name,
					$ext_product->parent_id
				);
			} else {
				// Reset current data
				wss_reset_sync_status( $product->get_id(), $site['key'] );
			}
		}

		return true;
	}

	/**
	 * Get API client
	 */
	private function get_api_client( $site ) {
		return Woo_Stock_Sync_Api_Client::create( $site['url'], $site['api_key'], $site['api_secret'] );
	}
}
