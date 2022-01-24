<?php
/**
 * The Importer class.
 *
 * @package woocommerce-eikon
 */

namespace EON\WooCommerce\Eikon;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for importing (create or update) WooCommerce
 * products with Eikon information.
 */
class Importer {

	const CRON_ID                       = 'wc_eikon_cron';
	const CRON_INTERVAL                 = 'wc_eikon_cron_interval';
	const CRON_INTERVAL_TIME_IN_SECONDS = 60;
	const MAX_EXECUTION_TIME_IN_SECONDS = 120;

	/**
	 * The single instance of the class.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var Importer
	 */
	protected static $_instance = null;

	/**
	 * Main Importer Instance.
	 *
	 * Ensures only one instance of Importer is loaded or can be loaded.
	 *
	 * @static
	 * @return Importer - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Importer Constructor.
	 */
	public function __construct() {

		$system_cron_enabled = EK()->settings->get( 'enable_system_cron' ) === 'yes';

		if ( $system_cron_enabled ) {
			$this->setup_system_cron();
		} else {
			$this->setup_wp_cron();
		}

	}

	/**
	 * Adds necessary hooks for system cronjob.
	 *
	 * @return void
	 */
	private function setup_system_cron() {

		add_action(
			'admin_post_' . self::CRON_ID,
			array( $this, 'handle_system_cron' )
		);

		add_action(
			'admin_post_nopriv_' . self::CRON_ID,
			array( $this, 'handle_system_cron' )
		);

		wp_clear_scheduled_hook( self::CRON_ID );

	}

	/**
	 * Adds necessary hooks for WordPress cron.
	 *
	 * @return void
	 */
	private function setup_wp_cron() {

		// phpcs:disable WordPress.WP.CronInterval.ChangeDetected
		add_filter( 'cron_schedules', array( $this, 'get_intervals' ) );
		add_action( self::CRON_ID, array( $this, 'import' ) );
		if ( ! wp_next_scheduled( self::CRON_ID ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_ID );
		}

	}

	/**
	 * Handles the system cron validation.
	 *
	 * @return void
	 */
	public function handle_system_cron() {

		$stored_password = EK()->settings->get( 'cron_password' );

		// @phpcs:disable WordPress.Security.NonceVerification.Recommended
		// @phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		// @phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$password = wp_unslash( $_REQUEST['pass'] ) ?? '';

		if ( empty( $password ) || empty( $stored_password ) ) {
			// @phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_die( __( 'Bad request or bad configuration.', 'woocommerce-eikon' ) );
		}

		if ( $password !== $stored_password ) {
			// @phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_die( __( 'Incorrect password.', 'woocommerce-eikon' ) );
		}

		$this->import();

	}

	/**
	 * Checks execution time and dies if it exceeds the maximum allowed.
	 *
	 * @return boolean|void
	 */
	public function check_execution_time() {

		if ( time() - $this->start_time > self::MAX_EXECUTION_TIME_IN_SECONDS ) {
			// @phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_die( __( 'Timeout.', 'woocommerce-eikon' ) );
		}

		return true;

	}

	/**
	 * Does the heavy lifting of creating and/or updating products
	 * based on Eikon data.
	 *
	 * @return void
	 */
	public function import() {

		\wc_set_time_limit( self::MAX_EXECUTION_TIME_IN_SECONDS );
		$this->start_time = time();

		$account_id   = EK()->settings->get( 'account_id' );
		$access_token = EK()->settings->get( 'access_token' );

		if ( empty( $account_id ) || empty( $access_token ) ) {
			return;
		}

		$properties = EK()->api->get_properties();

		if ( empty( $properties ) ) {
			return;
		}

		/**
		 * This system assumes properties always come in the same order.
		 * Also the only resource it monitors is time execution, it does
		 * not care about other server resources.
		 *
		 * TODO: Replace with Action Scheduler
		 */
		$last_processed = get_option( 'wc_eikon_last_proccessed', 0 );

		$properties = array_slice( $properties, $last_processed );

		foreach ( $properties as $i => $property ) {

			$property = $this->expand_property( $property );
			$this->import_property( $property );
			update_option( 'wc_eikon_last_proccessed', $i );
			$this->check_execution_time();

		}

		$this->draft_missing_properties( EK()->api->get_properties() );

		update_option( 'wc_eikon_last_proccessed', 0 );

	}

	/**
	 * Adds gallery images and zones to property (this is an expensive operation).
	 *
	 * @param Property $property The basic Eikon property.
	 * @return Property
	 */
	private function expand_property( $property ) {

		$property['zonas'] = EK()->api->get_property_zones( $property );

		$property['contacto'] = EK()->api->get_property_contact_information( $property['id'] );

		// We remove the first image since its already in the $property object.
		$images              = EK()->api->get_property_image_urls( $property['id'] );
		$property['galeria'] = array_slice( (array) $images, 1 );

		$property['imagen'] .= '?ext=.jpg';

		return $property;

	}

	/**
	 * Creates or updates a single property based on the
	 * information provided by the Eikon API.
	 *
	 * @param Property $property Eikon property.
	 * @return void
	 */
	private function import_property( $property ) {

		$product_id = $this->property_exists( $property );

		if ( $product_id ) {
			if ( $this->is_property_stale( $product_id, $property ) ) {
				$this->update_property( $product_id, $property );
			}
		} else {
			$this->create_property( $property );
		}

	}

	/**
	 * Changes WooCommerce products statuses to draft when a previously synced property
	 * no longer exists in the endpoint (usually because its rented or sold).
	 *
	 * @param Property[] $properties The list of properties.
	 * @return void
	 */
	private function draft_missing_properties( $properties ) {

		$property_ids = wp_list_pluck( $properties, 'id' );

		$missing_properties = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_sku',
						'value'   => $property_ids,
						'compare' => 'NOT IN',
					),
				),
				'fields'         => 'ids',
			)
		);

		foreach ( $missing_properties as $property ) {

			$product = \wc_get_product( $property );
			if ( $product ) {
				$product->set_status( 'draft' );
				$product->save();
			}
		}

	}

	/**
	 * Returns true if the Eikon property was updated after the last time we updated the
	 * WooCommerce product.
	 *
	 * @param int      $product_id The WooCommerce product id.
	 * @param Property $property The Eikon property.
	 * @return boolean
	 */
	private function is_property_stale( $product_id, $property ) {

		$eikon_date         = date_create_from_format( 'd/m/Y', $property['fecUpd'] );
		$eikon_last_updated = $eikon_date->getTimestamp();

		$woocommerce_last_updated = $this->get_last_updated( $product_id );

		if ( $eikon_last_updated > $woocommerce_last_updated ) {
			return true;
		}

		return false;

	}

	/**
	 * Gets the last update timestamp for the product.
	 * This only accounts for updates made by the importer, not the user.
	 *
	 * @see $this->is_property_stale()
	 * @param int $product_id The WooCommerce product id.
	 * @return int
	 */
	private function get_last_updated( $product_id ) {

		$product = new \WC_Product( $product_id );
		return $product->get_meta( 'wc_eikon_last_updated' );

	}

	/**
	 * Saves the current time as the last update on the product.
	 * This only accounts for updates made by the importer, not the user.
	 *
	 * @see $this->is_property_stale()
	 * @param WC_Product $product The WooCommerce product.
	 * @return void
	 */
	private function set_last_updated( $product ) {

		$product->update_meta_data( 'wc_eikon_last_updated', time() );

	}

	/**
	 * Checks wether or not a property exists as a WooCommerce product.
	 *
	 * @param Property $property Eikon property.
	 * @return int
	 */
	private function property_exists( $property ) {

		return \wc_get_product_id_by_sku( $property['id'] );

	}

	/**
	 * Creates a new WooCommerce product based on Eikon information.
	 *
	 * @param Property $property Eikon property.
	 * @return void
	 */
	private function create_property( $property ) {

		$product = new \WC_Product();

		$product->set_sku( $property['id'] );
		$product->set_name( $property['titulo'] );
		$product->set_regular_price( $this->get_price( $property['precio'] ) );
		$product->set_category_ids( $this->get_category_ids( $property ) );
		$product->set_attributes( $this->get_attributes( $property ) );
		$this->set_meta_data( $product, $property );
		$this->set_last_updated( $product );

		$product->save();

		$product->set_image_id( $this->get_image_id( $property['imagen'] ) );
		$product->set_gallery_image_ids( $this->get_gallery_image_ids( $property['galeria'] ) );

		$product->save();

	}

	/**
	 * Updates an existing product with the new property information
	 * from Eikon.
	 *
	 * Its selective in which fields it updates and where it respects
	 * the changes made by the WooCommerce user.
	 *
	 * @param int      $product_id The WooCommerce product ID for that property based on SKU = id.
	 * @param Property $property Eikon property.
	 * @return void
	 */
	private function update_property( $product_id, $property ) {

		$product = new \WC_Product( $product_id );

		$product->set_status( 'publish' );
		$product->set_regular_price( $this->get_price( $property['precio'] ) );

		/**
		 * There's no easy way to determine which images changed, so if the product is stale
		 * we delete them all and re-import them.
		 */
		$this->delete_images( $product );
		$product->set_image_id( $this->get_image_id( $property['imagen'] ) );
		$product->set_gallery_image_ids( $this->get_gallery_image_ids( $property['galeria'] ) );

		$this->set_meta_data( $product, $property );
		$this->set_last_updated( $product );

		$product->save();

	}

	/**
	 * Returns the generated meta data.
	 *
	 * @param WC_Product $product WooCommerce product.
	 * @param Property   $property Eikon property.
	 * @return void
	 */
	private function set_meta_data( $product, $property ) {

		$coordinates   = explode( ', ', $property['coord'] );
		$eikon_date    = date_create_from_format( 'd/m/Y', $property['fecIng'] );
		$eikon_created = $eikon_date->getTimestamp();

		$meta_data = array(
			'wc_eikon_latitude'                  => $coordinates[0],
			'wc_eikon_longitude'                 => $coordinates[1],
			'wc_eikon_created'                   => $eikon_created,
			'wc_eikon_allows_credit'             => $property['aptoCredito'],
			'wc_eikon_visible_price'             => $property['precioVisible'],
			'wc_eikon_video'                     => $property['video'],
			'wc_eikon_tour'                      => $property['tour'],
			'wc_eikon_whatsapp'                  => $property['contacto']['wa'],
			'_wcj_currency_per_product_currency' => $this->get_currency( $property['moneda'] ),
		);

		foreach ( $meta_data as $key => $value ) {

			$product->update_meta_data( $key, $value );

		}

	}

	/**
	 * Converts Eikon currency to Booster Plus currency.
	 *
	 * @param string $eikon_currency The Eikon currency representation.
	 * @return string
	 */
	private function get_currency( $eikon_currency ) {

		return 'P' === $eikon_currency ? 'ARS' : 'USD';

	}

	/**
	 * Returns the category IDs based on property information.
	 *
	 * @param Property $property The Eikon property.
	 * @return int[]
	 */
	private function get_category_ids( $property ) {

		$operation_parent_id = $this->generate_category_id( 'Operación' );
		$operation_id        = $this->generate_category_id(
			$property['opera'],
			$operation_parent_id
		);

		$type_parent_id = $this->generate_category_id( 'Tipo' );
		$type_id        = $this->generate_category_id( $property['tipo'], $type_parent_id );

		$zones_parent_id = $this->generate_category_id( 'Zona' );
		$zone_ids        = array();

		foreach ( $property['zonas'] as $zone ) {
			$zone_ids[] = $this->generate_category_id( $zone['nombre'], $zones_parent_id );
		}

		$ids = array_merge( array( $operation_id, $type_id ), $zone_ids );

		return $ids;

	}

	/**
	 * Returns category ID if it exists and creates it if it doesn't.
	 *
	 * @param string $category_name The name of the category.
	 * @param int    $parent_id The parent ID.
	 * @return int
	 */
	private function generate_category_id( $category_name, $parent_id = null ) {

		$category_id = term_exists( $category_name, 'product_cat', $parent_id );
		if ( ! $category_id ) {
			$args = array();
			if ( $parent_id ) {
				$args['parent'] = $parent_id;
			}
			$category_id = wp_insert_term( $category_name, 'product_cat', $args );
		}

		return $category_id['term_id'];

	}

	/**
	 * Deletes product images, including thumbnail and gallery.
	 *
	 * @param WC_Product $product The WooCommerce product.
	 * @return void
	 */
	private function delete_images( $product ) {

		$image_ids   = array();
		$image_ids[] = $product->get_image_id();
		$image_ids[] = $product->get_gallery_image_ids();

		foreach ( $image_ids as $image_id ) {
			wp_delete_attachment( $image_id );
		}

	}

	/**
	 * Returns the numeric value of the string price without the unit.
	 *
	 * @param string $string_price Eikon unit price.
	 * @return int
	 */
	private function get_price( $string_price ) {

		return intval( preg_replace( '/[^0-9]/', '', $string_price ) );

	}

	/**
	 * Returns attachment ID based on Eikon image URL.
	 *
	 * @param string $image_url The Eikon image URL.
	 * @return int
	 */
	private function get_image_id( $image_url ) {

		if ( ! $image_url ) {
			return null;
		}

		/*
		// This code imports the actual image and stores it it your server.

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$image_id = media_sideload_image(
			$image_url,
			null,
			null,
			'id'
		);
		*/

		$image_info = getimagesize( $image_url );

		$attachment = array(
			'guid'           => $image_url,
			'post_mime_type' => $image_info['mime'],
			'post_title'     => 'Imagen de Eikon',
		);

		$attachment_metadata = array(
			'width'  => $image_info[0],
			'height' => $image_info[1],
			'file'   => wp_basename( $image_url ),
		);

		$image_id = wp_insert_attachment( $attachment );
		wp_update_attachment_metadata( $image_id, $attachment_metadata );

		return $image_id;

	}

	/**
	 * Returns attachment IDs based on Eikon image URLs.
	 *
	 * @param string[] $image_urls The Eikon image URL.
	 * @return int[]
	 */
	private function get_gallery_image_ids( $image_urls ) {

		return array_filter( array_map( array( $this, 'get_image_id' ), (array) $image_urls ) );

	}

	/**
	 * Returns the attributes with the corresponding terms attached to them.
	 * If the attribute doesn't exist it creates it.
	 *
	 * @param Property $property The Eikon property.
	 *
	 * @return WC_Product_Attribute[]
	 */
	private function get_attributes( $property ) {

		$attributes = array();

		$attribute_slugs = array(
			'pais',
			'provincia',
			'localidad',
			'barrio',
			'calle',
			'nro',
			'ambientes',
			'dormitorios',
			'garage',
			'banos',
			'plantas',
			'antiguedad',
			'estado',
			'estilo',
			'supTerr',
			'supCub',
		);

		foreach ( $attribute_slugs as $i => $slug ) {

			$attributes[] = $this->get_attribute( strtolower( $slug ), $property[ $slug ], $i );

		}

		return $attributes;

	}

	/**
	 * Returns a WooCommerce attribute based on a loose definition.
	 *
	 * @param string $slug The WooCommerce Product Attribute slug.
	 * @param string $term The term name.
	 * @param int    $position The Attribute position in the product info table.
	 * @return WC_Product_Attribute
	 */
	private function get_attribute( $slug, $term, $position = 0 ) {

		$attribute_id = \wc_attribute_taxonomy_id_by_name( $slug );

		if ( ! $attribute_id ) {

			$attribute_id = \wc_create_attribute(
				array(
					'name' => $slug,
					'slug' => $slug,
				)
			);

			/**
			 * Attributes are taxonomies so you need to register them if you want to use them
			 * in the same page load.
			 *
			 * @link https://github.com/woocommerce/woocommerce/issues/19237
			 */

			register_taxonomy(
				'pa_' . $slug,
				apply_filters( 'woocommerce_taxonomy_objects_pa_' . $slug, array( 'product' ) ),
				apply_filters(
					'woocommerce_taxonomy_args_pa_' . $slug,
					array(
						'hierarchical' => true,
						'show_ui'      => false,
						'query_var'    => true,
						'rewrite'      => false,
					)
				)
			);

		}

		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( $attribute_id );
		$attribute->set_name( 'pa_' . $slug );
		$attribute->set_visible( true );
		$attribute->set_options( array( $term ) );
		$attribute->set_position( $position );
		$attribute->set_variation( false );

		return $attribute;

	}

	/**
	 * Add intervals for WordPress cron.
	 *
	 * @param Array $schedules The WordPress schedules.
	 * @return Array
	 */
	public function get_intervals( $schedules ) {

		$schedules[ self::CRON_INTERVAL ] = array(
			'interval' => self::CRON_INTERVAL_TIME_IN_SECONDS,
			'display'  => __(
				'WooCommerce Eikon Interval',
				'woocommerce-eikon'
			),
		);

		return $schedules;

	}

}
