<?php
/**
 * GitHub Updater
 *
 * @author    Andy Fragen
 * @license   GPL-2.0+
 * @link      https://github.com/afragen/github-updater
 * @package   github-updater
 */

namespace Fragen\GitHub_Updater;

use Fragen\Singleton;
use Fragen\GitHub_Updater\Traits\GHU_Trait;
use Fragen\GitHub_Updater\Traits\Basic_Auth_Loader;

/*
 * Exit if called directly.
 */
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Class Init
 */
class Init {
	use GHU_Trait, Basic_Auth_Loader;

	/**
	 * Holds Class Base object.
	 *
	 * @var Base $base
	 */
	protected $base;

	/**
	 * Constuctor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->load_options();
		$this->base = Singleton::get_instance( 'Base', $this );
	}

	/**
	 * Rename on activation.
	 *
	 * Correctly renames the slug when GitHub Updater is installed
	 * via FTP or from plugin upload.
	 *
	 * Set current branch to `develop` if appropriate.
	 *
	 * `rename()` causes activation to fail.
	 *
	 * @return void
	 */
	public function rename_on_activation() {
		$plugin_dir = trailingslashit( WP_PLUGIN_DIR );
		$slug       = isset( $_GET['plugin'] ) ? $_GET['plugin'] : false;
		$exploded   = explode( '-', dirname( $slug ) );

		if ( in_array( 'develop', $exploded, true ) ) {
			$options = $this->get_class_vars( 'Base', 'options' );
			update_site_option( 'github_updater', array_merge( $options, array( 'current_branch_github-updater' => 'develop' ) ) );
		}

		if ( $slug && 'github-updater/github-updater.php' !== $slug ) {
			@rename( $plugin_dir . dirname( $slug ), $plugin_dir . 'github-updater' );
		}
	}

	/**
	 * Let's get going.
	 */
	public function run() {
		if ( ! static::is_heartbeat() ) {
			$this->load_hooks();
		}

		if ( static::is_wp_cli() ) {
			include_once __DIR__ . '/WP_CLI/CLI.php';
			include_once __DIR__ . '/WP_CLI/CLI_Integration.php';
		}
	}

	/**
	 * Load relevant action/filter hooks.
	 * Use 'init' hook for user capabilities.
	 */
	protected function load_hooks() {
		add_action( 'init', array( $this->base, 'load' ) );
		add_action( 'init', array( $this->base, 'background_update' ) );
		add_action( 'init', array( $this->base, 'set_options_filter' ) );
		add_action( 'wp_ajax_github-updater-update', array( Singleton::get_instance( 'Rest_Update', $this ), 'process_request' ) );
		add_action( 'wp_ajax_nopriv_github-updater-update', array( Singleton::get_instance( 'Rest_Update', $this ), 'process_request' ) );

		// Load hook for shiny updates Basic Authentication headers.
		if ( self::is_doing_ajax() ) {
			$this->base->load_authentication_hooks();
		}

		add_filter( 'upgrader_source_selection', array( $this->base, 'upgrader_source_selection' ), 10, 4 );
	}

	/**
	 * Checks current user capabilities and admin pages.
	 *
	 * @return bool
	 */
	public function can_update() {
		global $pagenow;

		// WP-CLI access has full capabilities.
		if ( static::is_wp_cli() ) {
			return true;
		}

		$can_user_update = current_user_can( 'update_plugins' ) && current_user_can( 'update_themes' );
		$this->load_options();

		$admin_pages = array(
			'plugins.php',
			'plugin-install.php',
			'themes.php',
			'theme-install.php',
			'update-core.php',
			'update.php',
			'options-general.php',
			'options.php',
			'settings.php',
			'edit.php',
		);

		// Needed for sequential shiny updating.
		if ( isset( $_POST['action'] ) && in_array( $_POST['action'], array( 'update-plugin', 'update-theme' ), true ) ) {
			$admin_pages[] = 'admin-ajax.php';
		}

		/**
		 * Filter $admin_pages to be able to adjust the pages where GitHub Updater runs.
		 *
		 * @since 8.0.0
		 *
		 * @param array $admin_pages Default array of admin pages where GitHub Updater runs.
		 */
		$admin_pages = array_unique( apply_filters( 'github_updater_add_admin_pages', $admin_pages ) );

		return $can_user_update && in_array( $pagenow, $admin_pages, true );
	}
}
