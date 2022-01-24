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
	 * @return Product[]
	 */
	public function get_products() {

		$token = $this->get_auth_token();

		if ( empty( $token ) ) {

			return null;

		}

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

		return json_decode( wp_remote_retrieve_body( $response ), true );

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
