<?php
/**
 * The API class.
 *
 * @package woocommerce-eikon
 */

namespace EON\WooCommerce\Eikon;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for interacting with the Eikon API.
 */
class API {

	const AUTH_ENDPOINT     = 'https://wsconcretto20210927163457.azurewebsites.net/api/login/authenticate';
	const PRODUCTS_ENDPOINT = 'https://wsconcretto20210927163457.azurewebsites.net/api/Xproductos';

	const ENDPOINT_TIMEOUT_IN_SECONDS          = 60;
	const ENDPOINT_CACHE_EXPIRATION_IN_SECONDS = 300;

	/**
	 * The single instance of the class.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var API
	 */
	protected static $_instance = null;

	/**
	 * Main API Instance.
	 *
	 * Ensures only one instance of API is loaded or can be loaded.
	 *
	 * @static
	 * @return API - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * API Constructor.
	 */
	public function __construct() {

		$this->account_id   = EK()->settings->get( 'account_id' );
		$this->access_token = EK()->settings->get( 'access_token' );

	}

	/**
	 * Gets products from Eikon API.
	 *
	 * @param int $limit Limits the amount of products to get, useful for debugging.
	 * @return Product[]
	 */
	public function get_products( $limit = PHP_INT_MAX ) {

		$cached_products = get_transient( 'wc_eikon_products' );

		if ( $cached_products ) {

			return array_slice( $cached_products, 0, $limit );

		}

		$token = $this->get_auth_token();

		if ( empty( $token ) ) {

			return null;

		}

		/*
		$response = wp_remote_get(
			self::PRODUCTS_ENDPOINT,
			array(
				'headers' => array(
					'Authorization' => "Bearer $token",
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {

			return null;

		}
		$products = json_decode( wp_remote_retrieve_body( $response ), true );

		*/
		$products = json_decode( file_get_contents( DIR . '/sample.json' ), true );

		$products = array_map(
			function( $product ) {

				foreach ( array_keys( $product ) as $key ) {
					$product[ $key ] = trim( $product[ $key ] );
				}

				return array(
					'sku'             => $product['codigo'],
					'name'            => $product['decripcion'],
					'stock'           => intval( $product['existencia'] ),
					'price'           => round( $product['precio'], 2 ),
					'wholesale_price' => round( $product['precio_mayorista'], 2 ),
					'brand'           => $product['marca_descripcion'],
					'category'        => $product['rubro_descripcion'],
					'subcategory'     => $product['familia_descripcion'],
				);

			},
			$products
		);

		$products = array_filter(
			$products,
			function( $product ) {

				return ! ( '0000' === $product['sku'] || '*' === $product['sku'][0] );

			}
		);

		set_transient( 'wc_eikon_products', $products, self::ENDPOINT_CACHE_EXPIRATION_IN_SECONDS );

		return array_slice( $products, 0, $limit );

	}

	/**
	 * Gets auth token form Eikon API.
	 *
	 * @return string
	 */
	private function get_auth_token() {

		$response = wp_remote_post(
			self::AUTH_ENDPOINT,
			array(
				'body' => array(
					'Username' => $this->account_id,
					'Password' => $this->access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {

			return null;

		}

		return trim( wp_remote_retrieve_body( $response ), '"' );

	}

}
