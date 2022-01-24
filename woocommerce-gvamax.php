<?php
/**
 * WooCommerce GVAmax
 *
 * @package           WooCommerce GVAmax
 * @author            Grupo EON
 * @copyright         Grupo EON
 *
 * @wordpress-plugin
 * Plugin Name:       WooCommerce GVAmax
 * Plugin URI:        https://github.com/grupoeon/plugin.gvamax2woo
 * Description:       Integra GVAmax con WooCommerce.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Grupo EON
 * Author URI:        https://grupoeon.com.ar
 * Text Domain:       woocommerce-gvamax
 * Update URI:        https://github.com/grupoeon/plugin.gvamax2woo
 */

namespace EON\WooCommerce\GVAmax;

defined( 'ABSPATH' ) || die;

require_once __DIR__ . '/class-gvamax.php';

add_action( 'init', array( __NAMESPACE__ . '\GVAmax', 'instance' ) );
