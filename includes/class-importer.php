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
	const CRON_INTERVAL_TIME_IN_SECONDS = 350;
	const MAX_EXECUTION_TIME_IN_SECONDS = 300;

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

		if ( time() - $this->start_time > self::MAX_EXECUTION_TIME_IN_SECONDS - 10 ) {
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

		$products = EK()->api->get_products();

		if ( empty( $products ) ) {
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

		$products = array_slice( $products, $last_processed );

		foreach ( $products as $i => $product ) {

			$this->import_product( $product );
			update_option( 'wc_eikon_last_proccessed', $i );
			$this->check_execution_time();

		}

		update_option( 'wc_eikon_last_proccessed', 0 );

	}


	/**
	 * Creates or updates a single product based on the
	 * information provided by the Eikon API.
	 *
	 * @param Product $product Eikon property.
	 * @return void
	 */
	private function import_product( $product ) {

		$product_id = $this->product_exists( $product );

		if ( $product_id ) {
			$this->update_product( $product_id, $product );
		} else {
			$this->create_product( $product );
		}

	}

	/**
	 * Checks wether or not an Eikon product exists as a WooCommerce product.
	 *
	 * @param Product $product Eikon product.
	 * @return int
	 */
	private function product_exists( $product ) {

		return \wc_get_product_id_by_sku( $product['codigo'] );

	}

	/**
	 * Creates a new WooCommerce product based on Eikon information.
	 *
	 * @param Product $product Eikon product.
	 * @return void
	 */
	private function create_product( $product ) {

		$woocommerce_product = new \WC_Product();
		$woocommerce_product->save();

		$woocommerce_product->set_sku( $product['codigo'] );
		$woocommerce_product->set_name( $product['decripcion'] );
		$woocommerce_product->set_manage_stock( true );
		$woocommerce_product->set_stock_quantity( $product['existencia'] );
		$woocommerce_product->set_regular_price( $product['precio'] );
		$woocommerce_product->set_category_ids( $this->get_category_ids( $product ) );
		$this->set_meta_data( $woocommerce_product, $product );

		$woocommerce_product->save();

	}

	/**
	 * Updates an existing product with the new property information
	 * from Eikon.
	 *
	 * @param int     $product_id The WooCommerce product ID for that property based on SKU = id.
	 * @param Product $product Eikon product.
	 * @return void
	 */
	private function update_product( $product_id, $product ) {

		$woocommerce_product = new \WC_Product( $product_id );

		$woocommerce_product->set_stock_quantity( $product['existencia'] );
		$woocommerce_product->set_regular_price( $product['precio'] );
		$this->set_meta_data( $woocommerce_product, $product );

		$woocommerce_product->save();

	}

	/**
	 * Sets the products meta data.
	 *
	 * @param WC_Product $woocommerce_product WooCommerce product.
	 * @param Product    $product Eikon product.
	 * @return void
	 */
	private function set_meta_data( $woocommerce_product, $product ) {

		$meta_data = array(
			'wholesale_customer_wholesale_price' => $product['precio_mayorista'],
		);

		foreach ( $meta_data as $key => $value ) {

			$woocommerce_product->update_meta_data( $key, $value );

		}

	}

	/**
	 * Returns the category IDs based on product information.
	 *
	 * @param Product $product The Eikon product.
	 * @return int[]
	 */
	private function get_category_ids( $product ) {

		$brand_parent_id = $this->generate_category_id( 'Marcas' );
		$brand_id        = $this->generate_category_id( $product['marca_descripcion'], $brand_parent_id );

		$category_id    = $this->generate_category_id( $product['rubro_descripcion'] );
		$subcategory_id = $this->generate_category_id( $product['familia_descripcion'], $category_id );

		$ids = array( $brand_id, $category_id, $subcategory_id );

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
