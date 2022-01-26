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

	const ACTION_SCHEDULER_QUEUE_TIMEOUT = 60;
	const ACTION_SCHEDULER_BATCH_SIZE    = 100;

	const IMPORT_ACTION    = 'wc_eikon_import';
	const IMPORT_FRECUENCY = MINUTE_IN_SECONDS * 30;

	const IMPORT_BATCH_ACTION = 'wc_eikon_import_batch';
	const IMPORT_BATCH_SIZE   = 50;

	const IMPORT_PRODUCT_ACTION = 'wc_eikon_import_product';

	const BATCHES_AND_PRODUCTS_GROUP = 'wc_eikon_batches_and_products_group';


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

		if ( ! class_exists( '\ActionScheduler' ) ) {
			return;
		}

		add_filter(
			'action_scheduler_queue_runner_time_limit',
			function() {
				return self::ACTION_SCHEDULER_QUEUE_TIMEOUT;
			}
		);

		add_filter(
			'action_scheduler_queue_runner_batch_size',
			function() {
				return self::ACTION_SCHEDULER_BATCH_SIZE;
			}
		);

		add_action( self::IMPORT_ACTION, array( $this, 'import' ) );
		add_action( self::IMPORT_BATCH_ACTION, array( $this, 'import_batch' ) );
		add_action( self::IMPORT_PRODUCT_ACTION, array( $this, 'import_product' ) );

		if ( false === \as_has_scheduled_action( self::IMPORT_ACTION ) ) {
			\as_schedule_recurring_action( time(), self::IMPORT_FRECUENCY, self::IMPORT_ACTION );
		}

	}

	/**
	 * Starts the import process.
	 *
	 * 1. Checks if all previous import batches have run, if not, exits.
	 * 2. Gets the Eikon products from the API.
	 * 3. Splits the products into manageable import batches.
	 * 4. Schedules the individual batches execution for as soon as possible.
	 *
	 * Later (as soon as possible):
	 * 5. Each batch gets executed and schedules its respective creations or updates.
	 *
	 * Later (as soon as possible):
	 * 6. Products get created or updated.
	 *
	 * @return void
	 */
	public function import() {

		$pending_imports = \as_get_scheduled_actions(
			array(
				'group'    => self::BATCHES_AND_PRODUCTS_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);

		if ( count( $pending_imports ) ) {
			return;
		}

		$completed_imports = \as_get_scheduled_actions(
			array(
				'group'    => self::BATCHES_AND_PRODUCTS_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_COMPLETE,
				'per_page' => -1,
			),
			'ids'
		);

		$canceled_imports = \as_get_scheduled_actions(
			array(
				'group'    => self::BATCHES_AND_PRODUCTS_GROUP,
				'status'   => \ActionScheduler_Store::STATUS_CANCELED,
				'per_page' => -1,
			),
			'ids'
		);

		$done_imports = array_merge( $completed_imports, $canceled_imports );

		foreach ( $done_imports as $import_id ) {
			\ActionScheduler_Store::instance()->delete_action( $import_id );
		}

		$products       = EK()->api->get_products();
		$products_count = count( $products );

		for ( $i = 0; $i <= $products_count; $i += self::IMPORT_BATCH_SIZE ) {

			\as_enqueue_async_action(
				self::IMPORT_BATCH_ACTION,
				array(
					array(
						'index' => $i / self::IMPORT_BATCH_SIZE,
						'start' => $i,
					),
				),
				self::BATCHES_AND_PRODUCTS_GROUP
			);

		}

	}


	/**
	 * Imports a batch of products.
	 *
	 * 1. Gets product data from the Eikon API and the batch data.
	 * 2. Schedules a product import as soon as possible.
	 *
	 * Later (as soon as possible):
	 * 6. Products get created or updated.
	 *
	 * @param array $batch The batch information.
	 * @return void
	 */
	public function import_batch( $batch ) {

		$products = EK()->api->get_products();
		$products = array_slice( $products, $batch['start'], self::IMPORT_BATCH_SIZE );

		foreach ( $products as $i => $product ) {

			\as_enqueue_async_action(
				self::IMPORT_PRODUCT_ACTION,
				array(
					array(
						'batch'   => $batch['index'],
						'index'   => $i,
						'product' => $product,
					),
				),
				self::BATCHES_AND_PRODUCTS_GROUP
			);

		}

	}

	/**
	 * Imports a product (creates or updates).
	 *
	 * @param array $data The product data along with the scheduler data.
	 * @return void
	 */
	public function import_product( $data ) {

		$product = $data['product'];

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

		return \wc_get_product_id_by_sku( $product['sku'] );

	}

	/**
	 * Creates a new WooCommerce product based on Eikon information.
	 *
	 * @param Product $product Eikon product.
	 * @return void
	 */
	private function create_product( $product ) {

		$woocommerce_product = new \WC_Product();
		$woocommerce_product->set_sku( $product['sku'] );

		$woocommerce_product->set_name( $product['name'] );
		$woocommerce_product->set_manage_stock( true );
		$woocommerce_product->set_stock_quantity( $product['stock'] );
		$woocommerce_product->set_regular_price( $product['price'] );
		$woocommerce_product->set_category_ids( $this->get_category_ids( $product ) );
		$woocommerce_product->update_meta_data( 'wholesale_customer_wholesale_price', $product['wholesale_price'] );

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

		$old_stock           = $woocommerce_product->get_stock_quantity();
		$old_price           = floatval( $woocommerce_product->get_regular_price() );
		$old_wholesale_price = floatval( $woocommerce_product->get_meta( 'wholesale_customer_wholesale_price' ) );

		$new_stock           = $product['stock'];
		$new_price           = $product['price'];
		$new_wholesale_price = $product['wholesale_price'];

		if ( $old_stock !== $new_stock ) {

			$woocommerce_product->set_stock_quantity( $new_stock );

		}

		if ( $old_price !== $new_price ) {

			$woocommerce_product->set_regular_price( $new_price );

		}

		if ( $old_wholesale_price !== $new_wholesale_price ) {

			$woocommerce_product->update_meta_data( 'wholesale_customer_wholesale_price', $new_wholesale_price );

		}

		$woocommerce_product->save();

	}

	/**
	 * Returns the category IDs based on product information.
	 *
	 * @param Product $product The Eikon product.
	 * @return int[]
	 */
	private function get_category_ids( $product ) {

		$brand_parent_id = $this->generate_category_id( 'Marcas' );
		$brand_id        = $this->generate_category_id( $product['brand'], $brand_parent_id );

		$category_id    = $this->generate_category_id( $product['category'] );
		$subcategory_id = $this->generate_category_id( $product['subcategory'], $category_id );

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

		if ( is_wp_error( $category_id ) ) {
			return null;
		} else {
			return $category_id['term_id'];
		}

	}

}
