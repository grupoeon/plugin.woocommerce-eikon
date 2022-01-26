<?php
/**
 * WooCommerce Eikon
 *
 * @package           WooCommerce Eikon
 * @author            Grupo EON
 * @copyright         Grupo EON
 *
 * @wordpress-plugin
 * Plugin Name:       WooCommerce Eikon
 * Plugin URI:        https://github.com/grupoeon/plugin.woocommerce-eikon
 * Description:       Integra Eikon con WooCommerce.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Grupo EON
 * Author URI:        https://grupoeon.com.ar
 * Text Domain:       woocommerce-eikon
 * Update URI:        https://github.com/grupoeon/plugin.woocommerce-eikon
 */

namespace EON\WooCommerce\Eikon;

defined( 'ABSPATH' ) || die;

require_once __DIR__ . '/class-eikon.php';

add_action( 'init', array( __NAMESPACE__ . '\Eikon', 'instance' ) );
add_action(
	'init',
	function() {

		if ( isset( $_GET['delete'] ) ) {

			$products = get_posts(
				array(
					'post_type'      => 'product',
					'fields'         => 'ids',
					'posts_per_page' => -1,
				)
			);
			foreach ( $products as $product ) {
				wp_delete_post( $product, true );
			}
		}

	},
	11
);


add_action(
	'admin_notices',
	function() {
		echo '<pre>';
		var_dump( get_option( 'wc_eikon_last_proccessed' ) );
		var_dump( get_option( 'wc_eikon_import_status' ) );
		echo '</pre>';
	}
);
