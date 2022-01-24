<?php
/**
 * The API class.
 *
 * @package woocommerce-gvamax
 */

namespace EON\WooCommerce\GVAmax;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for interacting with the GVAmax API.
 */
class API {

	const PROPERTY_ENDPOINT     = 'https://gvamax.com.ar/Api/Inmuebles/Get_Inmuebles.asp';
	const IMAGE_ENDPOINT        = 'https://gvamax.com.ar/Api/Images';
	const FORM_ENDPOINT         = 'https://gvamax.com.ar/Labs/iFrames/Form/frmContactov6.asp';
	const INQUIRY_ENDPOINT      = 'https://gvamax.com.ar/Api/CRM/CRM_SaveClienteV2.asp';
	const ZONE_ENDPOINT         = 'https://gvamax.com.ar/Api/zonas/Get_Zonas.asp';
	const ZONE_LIMITS_ENDPOINT  = 'https://gvamax.com.ar/Api/Zonas/Get_Zonas_Limites.asp';
	const CONTACT_INFO_ENDPOINT = 'https://gvamax.com.ar/Api/Inmuebles/Get_Inmuebles_ContactData.asp';

	const ENDPOINT_TIMEOUT_IN_SECONDS          = 60;
	const ENDPOINT_CACHE_EXPIRATION_IN_SECONDS = 3600;

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

		$this->account_id   = GM()->settings->get( 'account_id' );
		$this->access_token = GM()->settings->get( 'access_token' );

	}

	/**
	 * Returns the GVAmax properties or a specific one.
	 *
	 * @param Array $parameters The endpoint parameters.
	 * @return Property[]
	 */
	public function get_properties( $parameters = array() ) {

		return $this->query_endpoint( self::PROPERTY_ENDPOINT, $parameters );

	}

	/**
	 * Returns the GVAmax image URLs for a property.
	 *
	 * @param int $property_id The GVAmax property ID.
	 * @return Array
	 */
	public function get_property_contact_information( $property_id ) {

		$contact_information = $this->query_endpoint(
			self::CONTACT_INFO_ENDPOINT,
			array(
				'idInmueble' => $property_id,
			)
		);

		if ( empty( $contact_information ) || ! count( $contact_information ) ) {
			return null;
		}

		return $contact_information[0];

	}

	/**
	 * Returns the GVAmax image URLs for a property.
	 *
	 * @param int $property_id The GVAmax property ID.
	 * @return URL[]
	 */
	public function get_property_image_urls( $property_id ) {

		$images = $this->query_endpoint(
			self::IMAGE_ENDPOINT,
			array(
				'idprop' => $property_id,
			)
		);

		if ( ! $images ) {
			return null;
		}

		// We flatten the images because the array has non-numeric keys.
		$flattened_images = array();

		array_walk_recursive(
			$images,
			function( $url ) use ( &$flattened_images ) {
				// We also replace http with https because the endpoint provides http URLs.
				// We also add a dummy parameter ?ext=.jpg to bypass media_siload_image() security.
				$flattened_images[] = preg_replace( '/^http:/i', 'https:', $url ) . '?ext=.jpg';
			}
		);

		return $flattened_images;

	}

	/**
	 * Returns the contact form URL from GVAmax.
	 *
	 * @param int $property_id Property id.
	 * @return string
	 */
	public function get_contact_form_url( $property_id ) {

		return $this->get_endpoint(
			self::FORM_ENDPOINT,
			array(
				'idProp' => $property_id,
				'c'      => 1,
				'g'      => 1,
				'u'      => 1,
				'color'  => '0,107,146',
				'key'    => 'KOXSWKURTYDGHFFG',
			)
		);

	}

	/**
	 * Returns the zones the property belongs to.
	 *
	 * @param Property $property The GVAmax property as returned by the property endpoint.
	 * @return Zone[]
	 */
	public function get_property_zones( $property ) {

		$zones    = $this->get_zones();
		$zones_in = array();

		foreach ( $zones as $zone ) {
			$in_zone = $this->property_in_zone( $property, $zone );
			if ( $in_zone ) {
				$zones_in[] = $zone;
			}
		}

		return $zones_in;

	}

	/**
	 * Returns wether or not the property is inside a certain zone.
	 *
	 * @param Property $property The GVAmax property as returned by the property endpoint.
	 * @param Zone     $zone The GVAmax zone as returned by the zone endpoint.
	 * @return boolean
	 */
	private function property_in_zone( $property, $zone ) {

		return H()->is_point_in_polygon(
			$property['coord'],
			$this->get_zone_limits( $zone['id'] )
		);

	}

	/**
	 * Returns the zone limits polygon.
	 *
	 * @param int $zone_id The zone id as provided by the zones endpoint.
	 * @return string[]
	 */
	private function get_zone_limits( $zone_id ) {

		$limits = $this->query_endpoint(
			self::ZONE_LIMITS_ENDPOINT,
			array(
				'idzona' => $zone_id,
			)
		);

		$limits = array_map(
			function( $limit ) {
				return $limit['point'];
			},
			$limits
		);

		$flattened_limits = array();

		array_walk_recursive(
			$limits,
			function( $a ) use ( &$flattened_limits ) {
				$flattened_limits[] = $a;
			}
		);

		return $flattened_limits;

	}

	/**
	 * Returns the GVAmax zones.
	 *
	 * @param Array $parameters The endpoint parameters.
	 * @return Zone[]
	 */
	public function get_zones( $parameters = array() ) {

		return $this->query_endpoint( self::ZONE_ENDPOINT, $parameters );

	}

	/**
	 * Sends GVAmax inquiry.
	 *
	 * @param Array $parameters The endpoint parameters.
	 * @return void
	 */
	public function send_inquiry( $parameters ) {

		$parameters = array(
			'idinm'      => $this->account_id,
			'nom'        => $parameters['name'],
			'cel'        => $parameters['phone'],
			'email'      => $parameters['email'],
			'asunto'     => $parameters['subject'],
			'detalle'    => $parameters['message'],
			'inmueble'   => $parameters['property_id'],
			'accion'     => 6,
			'cond'       => 1,
			'folder'     => 1,
			'grupo'      => 1,
			'canal'      => 1,
			'canalpro'   => -1,
			'canaliden'  => -1,
			'lastAccion' => 6,
			'user'       => 1,
		);

		$url = $this->get_endpoint( self::INQUIRY_ENDPOINT, $parameters );

		wp_remote_get(
			$url,
			array(
				'timeout' => self::ENDPOINT_TIMEOUT_IN_SECONDS,
			)
		);

	}

	/**
	 * Returns a JSON response from and endpoint.
	 * It caches its result in a transient for faster
	 * performance for the same request.
	 *
	 * @param const $endpoint The endpoint constant.
	 * @param Array $parameters The endpoint parameters. Auth parameters are added automatically.
	 * @return Array
	 */
	private function query_endpoint( $endpoint, $parameters = array() ) {

		$url       = $this->get_endpoint( $endpoint, $parameters );
		$transient = get_transient( 'wc_gvamax_' . hash( 'md5', $url ) );

		if ( $transient ) {
			return $transient;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::ENDPOINT_TIMEOUT_IN_SECONDS,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		// This is a patch because the GVAmax image endpoint incorrectly sends text/html as its content type.
		if ( self::IMAGE_ENDPOINT === $endpoint ) {
			$body = wp_strip_all_tags( $body );
		}

		$json         = json_decode( $body, true );
		$json_errored = json_last_error() !== JSON_ERROR_NONE;

		if ( $json_errored ) {
			return false;
		}

		set_transient(
			'wc_gvamax_' . hash( 'md5', $url ),
			$json,
			self::ENDPOINT_CACHE_EXPIRATION_IN_SECONDS
		);

		return $json;

	}

	/**
	 * Returns the GVAmax endpoint with authorization headers and allows
	 * parametrization.
	 *
	 * @param const $endpoint The endpoint constant.
	 * @param Array $parameters The endpoint parameters. Auth parameters are added automatically.
	 * @return string
	 */
	private function get_endpoint( $endpoint, $parameters = array() ) {

		return add_query_arg(
			array_merge(
				array(
					array(
						'id'    => $this->account_id,
						'token' => $this->access_token,
					),
				),
				$parameters
			),
			$endpoint
		);

	}

}
