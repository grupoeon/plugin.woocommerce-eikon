<?php
/**
 * The Helpers class.
 *
 * @package woocommerce-eikon
 */

namespace EON\WooCommerce\Eikon;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for generic helper functions.
 */
class Helpers {

	/**
	 * The single instance of the class.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var Helpers
	 */
	protected static $_instance = null;

	/**
	 * Main Helpers Instance.
	 *
	 * Ensures only one instance of Helpers is loaded or can be loaded.
	 *
	 * @static
	 * @see H()
	 * @return Helpers - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Resets the environment.
	 *
	 * DANGER: DO NOT USE WITHOUT UNDERSTANDING WHAT IT DOES.
	 *
	 * @return void
	 */
	public function reset() {

		$posts = get_posts(
			array(
				'post_type'   => 'attachment',
				'numberposts' => -1,
			)
		);

		foreach ( $posts as $post ) {
			wp_delete_attachment( $post->ID, true );
		}

		$posts = get_posts(
			array(
				'post_type'   => array( 'product' ),
				'numberposts' => -1,
			)
		);
		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		foreach ( $terms as $term ) {
			wp_delete_term( $term->term_id, 'product_cat' );
		}

		$attributes = wc_get_attribute_taxonomies();

		foreach ( $attributes as $attribute ) {
			wc_delete_attribute( $attribute->attribute_id );
		}

		array_map( 'unlink', array_filter( (array) glob( plugin_dir_path( __FILE__ ) . 'logs/*' ) ) );

	}

	/**
	 * Logs to a file, requires an ID for file differentiation.
	 * The output of the log has time markers and is json encoded and prettified.
	 *
	 * @param string $id The filename differentiator.
	 * @param any    ...$args The things to log.
	 * @return void
	 */
	public function log( $id, ...$args ) {

		$now  = \DateTime::createFromFormat( 'U.u', microtime( true ), wp_timezone() );
		$time = $now->format( 'Y-m-d H:i:s.u' );
		$date = $now->format( 'Ymd' );

		if ( 1 === count( $args ) && 'string' === gettype( $args[0] ) ) {

			$log = <<<LOG
[$time]: $args[0]

LOG;

		} else {

			ob_start();
			var_dump( $args );
			$json = ob_get_clean();

			$log = <<<LOG

========================================================================
[$time]:
$json
========================================================================

LOG;

		}

		file_put_contents(
			DIR . "/logs/log_$id_$date.txt",
			$log,
			FILE_APPEND
		);

	}


}

/**
 * Returns the only Helpers instance.
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
 *
 * @return Helpers
 */
function H() {

	return Helpers::instance();

}
