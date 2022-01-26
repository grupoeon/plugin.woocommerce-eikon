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
	 * The current import index.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var int
	 */
	private $_index = null;

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

		register_shutdown_function(
			function() {
				if ( wp_doing_cron() && $this->is_importing() ) {
					H()->log( 'importer', '💥 System Shutdown.' );
				}
			}
		);

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

		add_action(
			'shutdown',
			function() {
				if ( wp_doing_cron() && $this->is_importing() ) {
					H()->log( 'importer', '💥 WordPress Shutdown.' );
				}
			}
		);

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
			$this->stop_import();
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

		if ( $this->is_importing() ) {
			return;
		}

		$this->start_import();

		\wc_set_time_limit( self::MAX_EXECUTION_TIME_IN_SECONDS );
		$this->start_time = time();

		$account_id   = EK()->settings->get( 'account_id' );
		$access_token = EK()->settings->get( 'access_token' );

		if ( empty( $account_id ) || empty( $access_token ) ) {
			$this->stop_import( 'Credentials not found.' );
			return;
		}

		$products = EK()->api->get_products();

		if ( DEBUG ) {
			H()->log( 'importer', '📦 Fetched ' . count( $products ) . ' products from Eikon.' );
		}

		if ( empty( $products ) ) {
			$this->stop_import( 'Could not retrieve any products from Eikon.' );
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

		if ( DEBUG ) {
			H()->log( 'importer', '📦 ' . count( $products ) . ' products remain in this batch.' );
		}

		foreach ( $products as $i => $product ) {

			$this->index( $last_processed + $i );
			$this->import_product( $product );
			$this->check_execution_time();

		}

		$this->stop_import();
		$this->index( 0 );

		if ( DEBUG ) {
			H()->log( 'importer', '🏆 Finished import run.' );
		}

	}

	/**
	 * Starts the import process, saves an option to prevent multiple
	 * instances of the importer to run at the same time.
	 *
	 * @return void
	 */
	private function start_import() {

		update_option( 'wc_eikon_import_status', 'importing' );
		update_option( 'wc_eikon_import_status_updated', time() );

		if ( DEBUG ) {
			H()->log( 'importer', "⛳ Starting import at index: {$this->position()}/{$this->get_total()}" );
		}

	}

	/**
	 * Stops the import process, saves an option to indicate a new import
	 * batch should start on the next run.
	 *
	 * @param string $message Optional error message.
	 * @return void
	 */
	private function stop_import( $message = null ) {

		update_option( 'wc_eikon_import_status', 'stopped' );
		update_option( 'wc_eikon_import_status_updated', time() );

		if ( DEBUG ) {
			if ( $message ) {
				H()->log( 'importer', "🚫 Stopped import because: $message" );
			} else {
				H()->log( 'importer', "⛔ Stopped import at index: {$this->position()}/{$this->get_total()}." );
			}
		}

	}

	/**
	 * Checks wether or not there is an import batch executing.
	 *
	 * @return boolean
	 */
	private function is_importing() {

		if ( time() - get_option( 'wc_eikon_import_status_updated', time() ) >= self::MAX_EXECUTION_TIME_IN_SECONDS ) {
			$this->stop_import();
			return false;
		}

		return get_option( 'wc_eikon_import_status', 'stopped' ) === 'importing';

	}


	/**
	 * Retrieves or sets the current product index.
	 *
	 * @param null|int $index The index.
	 * @return void|int
	 */
	private function index( $index = null ) {

		if ( is_numeric( $index ) ) {

			update_option( 'wc_eikon_last_proccessed', $index );
			$this->_index = $index;

		} else {

			if ( null === $this->_index ) {

				return (int) get_option( 'wc_eikon_last_proccessed', 0 );

			} else {

				return $this->_index;

			}
		}

	}

	/**
	 * Retrieves the position which is always index + 1.
	 *
	 * @return int
	 */
	private function position() {

		return $this->index() + 1;

	}

	/**
	 * Gets the total amount of products to import.
	 *
	 * @return int
	 */
	private function get_total() {

		return count( EK()->api->get_products() );

	}


	/**
	 * Creates or updates a single product based on the
	 * information provided by the Eikon API.
	 *
	 * @param Product $product Eikon property.
	 * @return void
	 */
	private function import_product( $product ) {

		if ( DEBUG ) {

			H()->log( 'importer', "Processing product {$this->position()}/{$this->get_total()}" );

		}

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

		if ( DEBUG ) {
			H()->log( 'importer', '🆕 Creating new product: #' . $product['codigo'] . '.' );
		}

		$stock           = round( $product['existencia'] );
		$price           = round( $product['precio'], 2 );
		$wholesale_price = round( $product['precio_mayorista'], 2 );

		$woocommerce_product = new \WC_Product();
		$woocommerce_product->set_sku( $product['codigo'] );
		$woocommerce_product->save();

		$woocommerce_product->set_name( $product['decripcion'] );
		$woocommerce_product->set_manage_stock( true );
		$woocommerce_product->set_stock_quantity( $stock );
		$woocommerce_product->set_regular_price( $price );
		$woocommerce_product->set_category_ids( $this->get_category_ids( $product ) );
		$woocommerce_product->update_meta_data( 'wholesale_customer_wholesale_price', $wholesale_price );

		$woocommerce_product->save();

		if ( DEBUG ) {
			H()->log( 'importer', '🆗 Finished creating product: #' . $product['codigo'] . '.' );
		}

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

		if ( DEBUG ) {
			H()->log( 'importer', '🔁 Updating product: #' . $product['codigo'] . '.' );
		}

		$woocommerce_product = new \WC_Product( $product_id );

		$old_stock           = $woocommerce_product->get_stock_quantity();
		$old_price           = floatval( $woocommerce_product->get_regular_price() );
		$old_wholesale_price = floatval( $woocommerce_product->get_meta( 'wholesale_customer_wholesale_price' ) );
		$new_stock           = intval( $product['existencia'] );
		$new_price           = round( $product['precio'], 2 );
		$new_wholesale_price = round( $product['precio_mayorista'], 2 );

		if ( $old_stock !== $new_stock ) {

			$woocommerce_product->set_stock_quantity( $new_stock );

			if ( DEBUG ) {
				H()->log( 'importer', "-- Updating stock from [ $old_stock ] to [ $new_stock ]." );
			}
		}

		if ( $old_price !== $new_price ) {

			$woocommerce_product->set_regular_price( $new_price );

			if ( DEBUG ) {
				H()->log( 'importer', "-- Updating regular price from [ $old_price ] to [ $new_price ]." );
			}
		}

		if ( $old_wholesale_price !== $new_wholesale_price ) {

			$woocommerce_product->update_meta_data( 'wholesale_customer_wholesale_price', $new_wholesale_price );

			if ( DEBUG ) {
				H()->log( 'importer', "-- Updating wholesale price from [ $old_wholesale_price ] to [ $new_wholesale_price ]." );
			}
		}

		$woocommerce_product->save();

		if ( DEBUG ) {
			H()->log( 'importer', '🆗 Finished updating product: #' . $product['codigo'] . '.' );
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
