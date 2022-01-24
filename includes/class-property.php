<?php
/**
 * Property class.
 *
 * @package woocommerce-gvamax
 */

namespace EON\WooCommerce\GVAmax;

defined( 'ABSPATH' ) || die;

/**
 * The class that interfaces with WC_Product to get property details.
 */
class Property extends \WC_Product {

	/**
	 * Returns the name of the operation.
	 *
	 * @return string
	 */
	public function get_operation_name() {

		$operation_category = term_exists( 'Operación', 'product_cat' );

		if ( ! $operation_category ) {
			return null;
		}

		$operations_ids = get_term_children( $operation_category['term_id'], 'product_cat' );

		$product_category_ids = $this->get_category_ids();

		$product_operation_id = end(
			array_values(
				array_intersect(
					$operations_ids,
					$product_category_ids
				)
			)
		);

		$product_operation = get_term( $product_operation_id, 'product_cat' );

		return $product_operation->name;

	}

	/**
	 * Returns the primary zone name from the list of zones.
	 *
	 * @return string
	 */
	public function get_primary_zone_name() {

		$zone_names = $this->get_zone_names();

		return $zone_names ? $zone_names[0] : null;

	}

	/**
	 * Retrieves the zone names.
	 *
	 * @return string[]
	 */
	public function get_zone_names() {

		$zone_category = term_exists( 'Zona', 'product_cat' );

		if ( ! $zone_category ) {
			return null;
		}

		$zones_ids = get_term_children( $zone_category['term_id'], 'product_cat' );

		$product_category_ids = $this->get_category_ids();

		$product_zones_ids = array_values( array_intersect( $zones_ids, $product_category_ids ) );

		$product_zones = array_map(
			function( $product_zones_id ) {
				return get_term( $product_zones_id, 'product_cat' );
			},
			$product_zones_ids
		);

		$product_zones_names = array_map(
			function( $product_zone ) {
				return $product_zone->name;
			},
			$product_zones
		);

		return $product_zones_names;

	}

	/**
	 * Retrieves the property type name.
	 *
	 * @return string[]
	 */
	public function get_type_name() {

		$type_category = term_exists( 'Tipo', 'product_cat' );

		if ( ! $type_category ) {
			return null;
		}

		$type_ids = get_term_children( $type_category['term_id'], 'product_cat' );

		$product_category_ids = $this->get_category_ids();

		$product_type_ids = array_values( array_intersect( $type_ids, $product_category_ids ) );

		$product_type = end(
			array_map(
				function( $product_type_id ) {
					return get_term( $product_type_id, 'product_cat' );
				},
				$product_type_ids
			)
		);

		return $product_type->name;

	}

	/**
	 * Retrieves the WhatsApp number of the GVAmax agent assigned to the property
	 * or the general reception WhatsApp number.
	 *
	 * @return string
	 */
	public function get_whatsapp_number() {

		return $this->get_meta( 'wc_gvamax_whatsapp' ) ?: GM()->shop::WHATSAPP_NUMBER;

	}

	/**
	 * Returns the amount of m2 of the entire proprety.
	 *
	 * @return int
	 */
	public function get_terrain_m2() {

		return $this->get_attribute( 'supterr' );

	}

	/**
	 * Returns the amount of m2 of the covered parts of the property.
	 *
	 * @return int
	 */
	public function get_covered_m2() {

		return $this->get_attribute( 'supcub' );

	}

	/**
	 * Returns the amount of environments inside the property.
	 *
	 * @return int
	 */
	public function get_environments() {

		return $this->get_attribute( 'ambientes' );

	}

	/**
	 * Returns the amount of bedroom of the proprety.
	 *
	 * @return int
	 */
	public function get_bedrooms() {

		return $this->get_attribute( 'dormitorios' );

	}

	/**
	 * Returns the years of antiquity of the proprety.
	 *
	 * @return int
	 */
	public function get_antiquity() {

		return $this->get_attribute( 'antiguedad' );

	}

	/**
	 * Returns the amounts of bathrooms in the proprety.
	 *
	 * @return int
	 */
	public function get_bathrooms() {

		return $this->get_attribute( 'banos' );

	}

	/**
	 * Returns the address of the property.
	 *
	 * @return string
	 */
	public function get_address() {

		return $this->get_attribute( 'calle' ) . ' ' . $this->get_attribute( 'nro' );

	}

	/**
	 * Returns the neighborhood of the property.
	 *
	 * @return string
	 */
	public function get_neighborhood() {

		return $this->get_attribute( 'barrio' );

	}

	/**
	 * Returns the city of the property.
	 *
	 * @return Array
	 */
	public function get_city() {

		return $this->get_attribute( 'localidad' );

	}
	/**
	 * Returns the coordinates of the property.
	 *
	 * @return Array
	 */
	public function get_coordinates() {

		$latitude  = floatval( $this->get_meta( 'wc_gvamax_latitude' ) );
		$longitude = floatval( $this->get_meta( 'wc_gvamax_longitude' ) );

		return array(
			'latitude'  => $latitude,
			'longitude' => $longitude,
		);

	}

}
