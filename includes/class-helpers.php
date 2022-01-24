<?php
/**
 * The Helpers class.
 *
 * @package woocommerce-gvamax
 */

namespace EON\WooCommerce\GVAmax;

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
	 * Returns wether or not the point is inside a polygon.
	 *
	 * @link https://stackoverflow.com/a/55158861
	 * @param string   $point The point.
	 * @param string[] $polygon The polygon.
	 * @return boolean
	 */
	public function is_point_in_polygon( $point, $polygon ) {
		$point = $this->point_string_to_coords( $point );

		// Must be a self closed polygon.
		if ( array_slice( $polygon, -1 )[0] !== $polygon[0] ) {
			$polygon[] = $polygon[0];
		}

		$vertices = array_map( array( $this, 'point_string_to_coords' ), $polygon );

		if ( $this->is_point_on_vertex( $point, $vertices ) ) {
			return true;
		}

		$intersections  = 0;
		$vertices_count = count( $vertices );

		for ( $i = 1; $i < $vertices_count; $i++ ) {
			$vertex1 = $vertices[ $i - 1 ];
			$vertex2 = $vertices[ $i ];

			if ( $vertex1['y'] === $vertex2['y']
				&& $vertex1['y'] === $point['y']
				&& $point['x'] > min( $vertex1['x'], $vertex2['x'] )
				&& $point['x'] < max( $vertex1['x'], $vertex2['x'] ) ) {
					return true;
			}

			if ( $point['y'] > min( $vertex1['y'], $vertex2['y'] )
				&& $point['y'] <= max( $vertex1['y'], $vertex2['y'] )
				&& $point['x'] <= max( $vertex1['x'], $vertex2['x'] )
				&& $vertex1['y'] !== $vertex2['y'] ) {
				$xinters = ( $point['y'] - $vertex1['y'] )
				* ( $vertex2['x'] - $vertex1['x'] )
				/ ( $vertex2['y'] - $vertex1['y'] )
				+ $vertex1['x'];

				if ( $xinters === $point['x'] ) {
					return true;
				}

				if ( $vertex1['x'] === $vertex2['x'] || $point['x'] <= $xinters ) {
					$intersections++;
				}
			}
		}
		if ( 0 !== $intersections % 2 ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Returns wether or not the point is on a vertice.
	 *
	 * @param Coordinate   $point The point.
	 * @param Coordinate[] $vertices The vertices.
	 * @return boolean
	 */
	private function is_point_on_vertex( $point, $vertices ) {
		foreach ( $vertices as $vertex ) {
			if ( $point === $vertex ) {
				return true;
			}
		}

	}

	/**
	 * Returns a coordinates array from a comma separated coordinate string.
	 *
	 * @param string $point_string The coordinate string (lat,lng).
	 * @return Coordinate
	 */
	private function point_string_to_coords( $point_string ) {
		$coordinates = explode( ',', $point_string );
		return array(
			'x' => $coordinates[0],
			'y' => $coordinates[1],
		);
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

		$time = microtime( true );
		ob_start();
		var_dump( $args );
		$json = ob_get_clean();
		$log  = <<<LOG

========================================================================
[$time]: $id
------------------------------------------------------------------------
$json
========================================================================

LOG;

		file_put_contents(
			DIR . "/logs/log_$id.json",
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
