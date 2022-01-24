<?php
/**
 * The Shop class.
 *
 * @package woocommerce-gvamax
 */

namespace EON\WooCommerce\GVAmax;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for modifying the WooCommerce frontend and store.
 * It adds forms, sensitive data, maps, videos, etc where relevant.
 */
class Shop {

	const WHATSAPP_NUMBER = '5493512359666';

	/**
	 * The single instance of the class.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var Shop
	 */
	protected static $_instance = null;

	/**
	 * Main Shop Instance.
	 *
	 * Ensures only one instance of Shop is loaded or can be loaded.
	 *
	 * @static
	 * @return Shop - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Shop Constructor.
	 */
	public function __construct() {

		$this->init_hooks();
		$this->enqueue();

	}

	/**
	 * Initializes Shop hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {

		add_action( 'woocommerce_is_purchasable', '__return_false' );

		// Product archive.
		add_action( 'astra_woo_shop_category_before', array( $this, 'header_information' ) );
		add_filter( 'astra_woo_shop_parent_category', '__return_false' );
		add_action( 'astra_woo_shop_add_to_cart_before', array( $this, 'property_information' ), 4 );
		add_action( 'astra_woo_shop_add_to_cart_before', array( $this, 'before_add_to_cart' ), 5 );
		add_action( 'astra_woo_shop_add_to_cart_before', array( $this, 'whatsapp_contact_button' ) );
		add_action( 'astra_woo_shop_add_to_cart_after', array( $this, 'after_add_to_cart' ) );

		// Single product.
		if ( ! is_admin() ) {
			add_filter( 'wc_product_sku_enabled', '__return_false' );
		}
		add_action( 'woocommerce_single_product_summary', array( $this, 'header_information' ), 5 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'property_information' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'output_contact_methods' ) );

		add_filter( 'woocommerce_product_tabs', array( $this, 'get_product_tabs' ) );

		remove_action(
			'woocommerce_after_single_product_summary',
			'woocommerce_output_related_products',
			20
		);

		// Media library.

		/**
		 * This filter is to make attachments added by this plugin pass the test
		 * of wp_attachment_is_image. Otherwise issues with other plugins such
		 * as WooCommerce occur:
		 *
		 * @see https://github.com/zzxiang/external-media-without-import
		 * @see https://github.com/zzxiang/external-media-without-import/issues/10
		 * @see https://wordpress.org/support/topic/product-gallery-image-not-working/
		 * @see http://zxtechart.com/2017/06/05/wordpress/#comment-178
		 * @see http://zxtechart.com/2017/06/05/wordpress/#comment-192
		 */
		add_filter( 'get_attached_file', array( $this, 'get_external_image' ), 10, 2 );

	}

	/**
	 * Patches get_attached_file to allow external images.
	 *
	 * @param any $file The file.
	 * @param int $attachment_id The attachment id.
	 * @return any
	 */
	public function get_external_image( $file, $attachment_id ) {
		if ( empty( $file ) ) {
			$post = get_post( $attachment_id );
			if ( 'attachment' === get_post_type( $post ) ) {
				return $post->guid;
			}
		}
		return $file;
	}

	/**
	 * Adds wrapper around add to cart button.
	 *
	 * @return void
	 */
	public function before_add_to_cart() {

		?>
		<section class="buttons">
		<?php

	}

	/**
	 * Ends wrapper around add to cart button.
	 *
	 * @return void
	 */
	public function after_add_to_cart() {

		?>
		</section>
		<?php

	}

	/**
	 * Outputs a contextual WhatsApp button.
	 *
	 * @return void
	 */
	public function whatsapp_contact_button() {

		$whatsapp_url = $this->get_whatsapp_url();

		?>

		<a class="whatsapp-product-contact-btn" href="<?php echo esc_attr( $whatsapp_url ); ?>">
			<i class="fab fa-whatsapp"></i>
			Contactar
		</a> 

		<?php

	}

	/**
	 * Retrieves the WhatsApp url with message included.
	 *
	 * @return string
	 */
	private function get_whatsapp_url() {

		$product  = \wc_get_product();
		$property = new Property( $product );

		$message = urlencode(
			"¡Hola! Me comunico porque me interesa esta propiedad: 
\"{$property->get_name()}\" ({$property->get_permalink()})
¿Podrías por favor enviarme más detalles?"
		);

		$phone_number  = $property->get_whatsapp_number();
		$whatsapp_link = "https://wa.me/$phone_number?text=$message";

		return $whatsapp_link;

	}

	/**
	 * Retrieves the WhatsApp number based on certain conditions.
	 *
	 * @return int
	 */
	private function get_whatsapp_number() {

		return self::WHATSAPP_NUMBER;

	}

	/**
	 * Enqueues styles and scripts.
	 *
	 * @return void
	 */
	private function enqueue() {

		add_action(
			'wp_enqueue_scripts',
			function() {

				if ( ! \is_woocommerce() ) {
					return;
				}

				wp_enqueue_style(
					ID . '-shop',
					plugins_url( 'public/shop.css', FILE ),
					null,
					VERSION
				);

				wp_enqueue_script(
					ID . '-shop',
					plugins_url( 'public/shop.js', FILE ),
					null,
					VERSION,
					true
				);

			}
		);

	}

	/**
	 * Adds the relevant product tabs in the single product.
	 *
	 * @param Tab[] $tabs WooCommerce tabs.
	 * @return Tab[]
	 */
	public function get_product_tabs( $tabs ) {

		$tabs['location'] = array(
			'title'    => __( 'Ubicación', 'woocommerce-gvamax' ),
			'priority' => 0,
			'callback' => array( $this, 'get_location_tab' ),
		);

		return $tabs;

	}

	public function get_location_tab() {

		$product     = \wc_get_product();
		$property    = new Property( $product );
		$coordinates = $property->get_coordinates();
		$latitude    = $coordinates['latitude'];
		$longitude   = $coordinates['longitude'];
		$map_url     = "https://maps.google.com/maps?q=$latitude,$longitude&hl=es;z=14&amp;output=embed";

		?>

		<section class="location">
			<p>
				La propiedad se encuentra en la calle 
				<b>
					<i class="fas fa-map-marker-alt"></i> 
					<?php echo esc_html( $property->get_address() ); ?>
				</b> del barrio <b><?php echo esc_html( $property->get_neighborhood() ); ?></b>
				en la ciudad de <b><?php echo esc_html( $property->get_city() ); ?></b>.
			</p>
			<iframe class="map" src="<?php echo esc_attr( $map_url ); ?>"></iframe>
		</section>

		<?php

	}

	/**
	 * Information about the type of operation and zone.
	 *
	 * @return void
	 */
	public function header_information() {

		$product  = \wc_get_product();
		$property = new Property( $product );

		?>

		<section class="header-information">
			<?php echo esc_html( $property->get_operation_name() ); ?>
			· <?php echo esc_html( $property->get_type_name() ); ?>
			· <?php echo esc_html( $property->get_primary_zone_name() ); ?>
			<?php if ( is_product() ) : ?>
			· 
			<a href="#" class="view-location" role="button">
				<i class="fas fa-map-marker-alt"></i>
				Ver ubicación
			</a>
			<?php endif; ?>
		</section>

		<?php

	}

	/**
	 * Displays property information.
	 *
	 * @return void
	 */
	public function property_information() {

		$product  = \wc_get_product();
		$property = new Property( $product );

		?>

		<section class="property-information summary-section">
			<ul>
				<li>
					<i class="fas fa-border-none"></i>
					<?php echo esc_html( $property->get_terrain_m2() ); ?> m² Terreno
				</li>
				<li>
					<i class="fab fa-microsoft"></i>
					<?php echo esc_html( $property->get_covered_m2() ); ?> m² Cubierta
				</li>
				<li>
					<i class="fas fa-door-open"></i>
					<?php echo esc_html( $property->get_environments() ); ?> Ambientes
				</li>
				<li>
					<i class="fas fa-bath"></i>
					<?php echo esc_html( $property->get_bathrooms() ); ?> Baños
				</li>
				<li>
					<i class="fas fa-bed"></i>
					<?php echo esc_html( $property->get_bedrooms() ); ?> Dormitorios
				</li>
				<li>
					<i class="fas fa-calendar-alt"></i>
					<?php echo esc_html( $property->get_antiquity() ); ?> Antigüedad
				</li>
			</ul>
			<?php if ( is_product() ) : ?>
			<a href="#" class="view-more" role="button">
				<i class="fas fa-angle-down"></i>
				Ver más
			</a>
			<?php endif; ?>
		</section>

		<?php

	}

	/**
	 * Outputs the GVAmax contact form and WhatsApp.
	 *
	 * @return void
	 */
	public function output_contact_methods() {

		$product       = \wc_get_product();
		$property_id   = $product->get_sku();
		$property_name = $product->get_name();
		$whatsapp_url  = $this->get_whatsapp_url();

		?>

		<section class="contact-form summary-section">
			<h2>Dejanos tu consulta</h2>
			<?php
				echo do_shortcode(
					"[contact-form-7 id='31292' id-inmueble='$property_id' nombre-inmueble='$property_name']"
				);
			?>
			<p style="margin:0;">ó</p>
			<a href="<?php echo esc_attr( $whatsapp_url ); ?>">
				<i class="fab fa-whatsapp"></i>
				Contactanos por WhatsApp
			</a>
		</section>

		<?php

	}

}
