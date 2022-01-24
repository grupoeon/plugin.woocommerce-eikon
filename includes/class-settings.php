<?php
/**
 * The Settings class.
 *
 * @package woocommerce-eikon
 */

namespace EON\WooCommerce\Eikon;

defined( 'ABSPATH' ) || die;

/**
 * The class responsible for managing WooCommerce user settings.
 */
class Settings {

	/**
	 * The single instance of the class.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var Settings
	 */
	protected static $_instance = null;

	/**
	 * The plugin settings.
	 *
     * @phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
	 *
	 * @var Setting[]
	 */
	public $settings = array();

	/**
	 * Main Settings Instance.
	 *
	 * Ensures only one instance of Settings is loaded or can be loaded.
	 *
	 * @static
	 * @return Settings - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Settings Constructor.
	 */
	public function __construct() {

		$this->load_settings();
		$this->init_hooks();

	}

	/**
	 * Adds the class hooks.
	 *
	 * @return void
	 */
	private function init_hooks() {

		add_filter(
			'woocommerce_settings_tabs_array',
			array( $this, 'add_settings_tab' ),
			50
		);

		add_action(
			'woocommerce_settings_tabs_eikon',
			array( $this, 'render_settings_tab' )
		);

		add_action(
			'woocommerce_update_options_eikon',
			array( $this, 'update_settings' )
		);

	}

	/**
	 * Adds the WooCommerce Setting tab.
	 *
	 * @param Tab[] $settings_tabs List of WooCommerce Setting tabs.
	 * @return Tab[]
	 */
	public function add_settings_tab( $settings_tabs ) {

		$settings_tabs['eikon'] = __( 'Eikon', 'woocommerce-eikon' );
		return $settings_tabs;

	}

	/**
	 * Renders the WooCommerce Setting tab.
	 *
	 * @return void
	 */
	public function render_settings_tab() {

		\woocommerce_admin_fields( $this->get_fields() );

	}

	/**
	 * Updates the plugins settings.
	 *
	 * @return void
	 */
	public function update_settings() {

		\woocommerce_update_options( $this->get_fields() );

	}

	/**
	 * Gets the plugins setting fields.
	 *
	 * @return SettingField[]
	 */
	private function get_fields() {

		$cronjob_password = $this->get_cronjob_password();

		$settings = array(
			'section_title'      => array(
				'name' => __( 'Configuración general', 'woocommerce-eikon' ),
				'type' => 'title',
				'desc' => '',
				'id'   => 'wc_eikon_section_title',
			),
			'title'              => array(
				'name' => __( 'Cuenta', 'woocommerce-eikon' ),
				'type' => 'text',
				'desc' => __( 'El ID de la cuenta de Eikon.', 'woocommerce-eikon' ),
				'id'   => 'wc_eikon_account',
			),
			'description'        => array(
				'name' => __( 'Token', 'woocommerce-eikon' ),
				'type' => 'text',
				'desc' => __( 'El token de acceso de Eikon.', 'woocommerce-eikon' ),
				'id'   => 'wc_eikon_token',
			),
			'section_end'        => array(
				'type' => 'sectionend',
				'id'   => 'wc_eikon_section_end',
			),
			'section_cronjob'    => array(
				'name' => __( 'Configuración del cronjob', 'woocommerce-eikon' ),
				'type' => 'title',
				'desc' => __( 'Esta sección te permite seleccionar entre WP Cron (por defecto) y System Cron (debe configurarse en el servidor).', 'woocommerce-eikon' ),
				'id'   => 'wc_eikon_section_scronjob',
			),
			'enable_system_cron' => array(
				'name' => __( 'Habilitar Cronjob del sistema', 'woocommerce-eikon' ),
				'type' => 'checkbox',
				'desc' => sprintf(
					/* translators: %s se reemplaza con una contraseña normal. */
					__( 'Al habilitar esta opción podrá ejecutar la importación cargando la siguiente URL: <code>%s</code>', 'woocommerce-eikon' ),
					admin_url( 'admin-post.php?action=woocommerce-eikon_cron&pass=' . $cronjob_password )
				),
				'id'   => 'wc_eikon_enable_system_cron',
			),

			'cronpassword'       => array(
				'name'  => __( 'Cronjob password', 'woocommerce-eikon' ),
				'type'  => 'text',
				'desc'  => __( 'La contraseña para permitir la ejecución del cronjob, puede ser cualquier texto.', 'woocommerce-eikon' ),
				'id'    => 'wc_eikon_cronpassword',
				'value' => $cronjob_password,
			),
			'section_cron_end'   => array(
				'type' => 'sectionend',
				'id'   => 'wc_eikon_cron_section_end',
			),
		);

		return apply_filters( DASHED_ID . '_settings', $settings );

	}

	/**
	 * Returns and creates (if it doesnt exist) the cronjob password.
	 *
	 * @return string
	 */
	private function get_cronjob_password() {

		$current_password = get_option( 'wc_eikon_cronpassword', '' );

		if ( empty( $current_password ) ) {
			$password = md5( time() . '-' . wp_rand( 999, 9999 ) );
			update_option( 'wc_eikon_cronpassword', $password );
			return $password;
		}

		return $current_password;

	}

	/**
	 * Loads the plugins settings from the database.
	 *
	 * @return void
	 */
	private function load_settings() {

		$this->settings['account_id']         = get_option( 'wc_eikon_account', null );
		$this->settings['access_token']       = get_option( 'wc_eikon_token', null );
		$this->settings['enable_system_cron'] = get_option( 'wc_eikon_enable_system_cron', null );
		$this->settings['cron_password']      = get_option( 'wc_eikon_cronpassword', null );

	}

	/**
	 * Gets a single plugin setting.
	 *
	 * @param string $id The setting id.
	 * @return Setting
	 */
	public function get( $id ) {

		return key_exists( $id, $this->settings ) ? $this->settings[ $id ] : null;

	}

}
