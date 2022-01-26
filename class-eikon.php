<?php
/**
 * The Eikon class.
 *
 * @package woocommerce-eikon
 */

namespace EON\WooCommerce\Eikon;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for articulating the entire Eikon integration.
 */
class Eikon {

	/**
	 * The single instance of the class.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var Eikon
	 */
	public static $_instance = null;

	/**
	 * Main Eikon Instance.
	 *
	 * Ensures only one instance of Eikon is loaded or can be loaded.
	 *
	 * @static
	 * @see EK()
	 * @return Eikon - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
			self::$_instance->initialize_modules();
		}
		return self::$_instance;
	}

	/**
	 * Eikon Constructor.
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

		define( __NAMESPACE__ . '\ID', 'woocommerce-eikon' );
		define( __NAMESPACE__ . '\DASHED_ID', 'woocommerce_eikon' );
		define( __NAMESPACE__ . '\VERSION', '1.0.0' );
		define( __NAMESPACE__ . '\FILE', __FILE__ );
		define( __NAMESPACE__ . '\DIR', plugin_dir_path( FILE ) );
		define( __NAMESPACE__ . '\URL', plugin_dir_url( FILE ) );
		define( __NAMESPACE__ . '\DEBUG', true );

	}

	/**
	 * Loads all the required files.
	 *
	 * @return void
	 */
	private function includes() {

		require_once DIR . 'includes/class-helpers.php';
		require_once DIR . 'includes/class-settings.php';
		require_once DIR . 'includes/class-api.php';
		require_once DIR . 'includes/class-importer.php';

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
	}

}

/**
 * Returns the main Eikon instance.
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
 *
 * @return Eikon
 */
function EK() {
	return Eikon::instance();
}
