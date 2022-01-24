<?php
/**
 * The Form class.
 *
 * @package woocommerce-gvamax
 */

namespace EON\WooCommerce\GVAmax;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for handling Contact Form 7 forms and their integration
 * with GVAmax.
 */
class Form {

	/**
	 * The single instance of the class.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var Form
	 */
	protected static $_instance = null;

	/**
	 * Main Form Instance.
	 *
	 * Ensures only one instance of Form is loaded or can be loaded.
	 *
	 * @static
	 * @return Form - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Form Constructor.
	 */
	public function __construct() {

		$this->init_hooks();
		// $this->enqueue();
	}

	/**
	 * Initializes Form hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {

		add_action( 'wpcf7_before_send_mail', array( $this, 'send_form' ) );
		add_filter( 'shortcode_atts_wpcf7', array( $this, 'add_wpcf7_atts' ), 10, 3 );

	}

	/**
	 * Adds shortcode attributes to work with hidden fields.
	 *
	 * @param [type] $out
	 * @param [type] $pairs
	 * @param [type] $atts
	 * @return void
	 */
	public function add_wpcf7_atts( $out, $pairs, $atts ) {

		$new_atts = array(
			'id-inmueble',
			'nombre-inmueble',
		);

		foreach ( $new_atts as $att ) {
			if ( key_exists( $att, $atts ) ) {
				$out[ $att ] = $atts[ $att ];
			}
		}

		return $out;

	}

	/**
	 * Sends form data to GVAmax.
	 *
	 * @param WPCF7_ContactForm $form Contact Form 7 form instance.
	 * @return void
	 */
	public function send_form( $form ) {

		$name          = htmlspecialchars( wp_strip_all_tags( wp_unslash( $_POST['nombre'] ) ) );
		$phone         = htmlspecialchars( wp_strip_all_tags( wp_unslash( $_POST['telefono'] ) ) );
		$email         = htmlspecialchars( wp_strip_all_tags( wp_unslash( $_POST['correo-electronico'] ) ) );
		$message       = htmlspecialchars( wp_strip_all_tags( wp_unslash( $_POST['consulta'] ) ) );
		$property_id   = htmlspecialchars( wp_strip_all_tags( wp_unslash( $_POST['id-inmueble'] ) ) );
		$property_name = htmlspecialchars( wp_strip_all_tags( wp_unslash( $_POST['nombre-inmueble'] ) ) );

		if ( empty( $property_name ) ) {
			return;
		}

		$args = array(
			'name'    => $name,
			'phone'   => $phone,
			'email'   => $email,
			'subject' => $form->title(),
			'message' => $message,
		);

		if ( ! empty( $property_id ) ) {
			$args['property_id'] = $property_id;
		}

		GM()->api->send_inquiry( $args );

	}


}
