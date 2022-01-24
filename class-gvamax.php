<?php
/**
 * The GVAmax class.
 *
 * @package woocommerce-gvamax
 */

namespace EON\WooCommerce\GVAmax;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for articulating the entire GVAmax integration.
 */
class GVAMax {

	/**
	 * The single instance of the class.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var GVAMax
	 */
	public static $_instance = null;

	/**
	 * Main GVAMax Instance.
	 *
	 * Ensures only one instance of GVAmax is loaded or can be loaded.
	 *
	 * @static
	 * @see GM()
	 * @return GVAMax - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
			self::$_instance->initialize_modules();
		}
		return self::$_instance;
	}

	/**
	 * GVAMax Constructor.
	 */
	public function __construct() {

		$this->define_constants();
		$this->includes();

	}

	/**
	 * Defines global constants.
	 *
	 * @return void
	 */
	private function define_constants() {

		define( __NAMESPACE__ . '\ID', 'woocommerce-gvamax' );
		define( __NAMESPACE__ . '\DASHED_ID', 'woocommerce_gvamax' );
		define( __NAMESPACE__ . '\VERSION', '1.0.0' );
		define( __NAMESPACE__ . '\FILE', __FILE__ );
		define( __NAMESPACE__ . '\DIR', plugin_dir_path( FILE ) );
		define( __NAMESPACE__ . '\URL', plugin_dir_url( FILE ) );

	}

	/**
	 * Loads all the required files.
	 *
	 * @return void
	 */
	private function includes() {

		require_once DIR . 'includes/class-helpers.php';
		require_once DIR . 'includes/class-settings.php';
		require_once DIR . 'includes/class-property.php';
		require_once DIR . 'includes/class-api.php';
		require_once DIR . 'includes/class-importer.php';
		require_once DIR . 'includes/class-shop.php';
		require_once DIR . 'includes/class-form.php';

	}

	/**
	 * Initializes internal modules and stores them in this instance.
	 *
	 * @return void
	 */
	private function initialize_modules() {

		$this->helpers  = Helpers::instance();
		$this->settings = Settings::instance();
		$this->api      = API::instance();
		$this->importer = Importer::instance();
		$this->shop     = Shop::instance();
		$this->form     = Form::instance();

	}

}

/**
 * Returns the main GVAmax instance.
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
 *
 * @return GVAmax
 */
function GM() {
	return GVAmax::instance();
}
