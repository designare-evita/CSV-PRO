<?php
/**
 * Plugin Name:       CSV Import Pro
 * Plugin URI:        https://example.com/csv-import-plugin
 * Description:       Professionelles CSV-Import System mit verbesserter Fehlerbehandlung, Batch-Verarbeitung, Backups, Scheduling und robuster Sicherheit.
 * Version:           6.1
 * Author:            Michael Kanda
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       csv-import
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Tested up to:      6.4
 * Requires PHP:      7.4
 */

// Verhindert den direkten Zugriff auf die Datei.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Verhindert mehrfache Ladung des Plugins
if ( defined( 'CSV_IMPORT_PRO_LOADED' ) ) {
    return;
}
define( 'CSV_IMPORT_PRO_LOADED', true );

/**
 * Die Hauptklasse des Plugins.
 */
final class CSV_Import_Pro {

	private static $instance = null;
	public $version = '6.1';

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->define_constants();
		$this->includes();
		$this->init_hooks();
	}

	private function define_constants() {
		define( 'CSV_IMPORT_PRO_VERSION', $this->version );
		define( 'CSV_IMPORT_PRO_PATH', plugin_dir_path( __FILE__ ) );
		define( 'CSV_IMPORT_PRO_URL', plugin_dir_url( __FILE__ ) );
		define( 'CSV_IMPORT_PRO_BASENAME', plugin_basename( __FILE__ ) );
	}

	public function includes() {
		// Core-Funktionen zuerst laden
		$this->include_if_exists( 'includes/core/core-functions.php' );
		
		// Error Handler als erstes laden
		$this->include_if_exists( 'includes/class-csv-import-error-handler.php' );
		
		// Installer für Aktivierung
		$this->include_if_exists( 'includes/class-installer.php' );
		
		// Core Import-Logik
		$this->include_if_exists( 'includes/core/class-csv-import-run.php' );

		// Feature-Klassen
		$feature_classes = [
			'includes/classes/class-csv-import-backup-manager.php',
			'includes/classes/class-csv-import-scheduler.php',
			'includes/classes/class-csv-import-validator.php',
			'includes/classes/class-csv-import-template-manager.php',
			'includes/classes/class-csv-import-profile-manager.php',
			'includes/classes/class-csv-import-notifications.php',
			'includes/classes/class-csv-import-performance-monitor.php'
		];
		foreach ( $feature_classes as $class_file ) {
			$this->include_if_exists( $class_file );
		}

		// Admin-Bereich nur im Backend laden
		if ( is_admin() ) {
			$this->include_if_exists( 'includes/admin/class-admin-menus.php' );
			$this->include_if_exists( 'includes/admin/admin-ajax.php' );
		}
	}

	private function include_if_exists( $file ) {
		$full_path = CSV_IMPORT_PRO_PATH . $file;
		if ( file_exists( $full_path ) ) {
			require_once $full_path;
		} else {
			if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
				CSV_Import_Error_Handler::handle(
					CSV_Import_Error_Handler::LEVEL_WARNING,
					"Datei nicht gefunden: {$file}",
					[ 'file_path' => $full_path ]
				);
			}
		}
	}

	private function init_hooks() {
		register_activation_hook( __FILE__, [ 'Installer', 'activate' ] );
		// KORREKTUR: Der Hook zeigt jetzt auf die korrigierte, statische Methode in dieser Klasse.
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate_plugin' ] );
		register_uninstall_hook( __FILE__, [ __CLASS__, 'uninstall' ] );

		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ], 10 );
		add_action( 'plugins_loaded', [ $this, 'init_feature_classes' ], 20 );
	}

	public function on_plugins_loaded() {
		load_plugin_textdomain( 
			'csv-import', 
			false, 
			dirname( plugin_basename( __FILE__ ) ) . '/languages' 
		);
		if ( ! $this->check_requirements() ) {
			return;
		}
	}

	public function init_feature_classes() {
		$feature_classes = [
			'CSV_Import_Backup_Manager', 'CSV_Import_Scheduler', 
			'CSV_Import_Notifications', 'CSV_Import_Validator',
			'CSV_Import_Template_Manager', 'CSV_Import_Profile_Manager',
			'CSV_Import_Performance_Monitor'
		];
		foreach ( $feature_classes as $class_name ) {
			if ( class_exists( $class_name ) && method_exists( $class_name, 'init' ) ) {
				call_user_func( [ $class_name, 'init' ] );
			}
		}
		if ( is_admin() && class_exists( 'CSV_Import_Pro_Admin' ) ) {
			new CSV_Import_Pro_Admin();
		}
	}

	private function check_requirements() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			add_action( 'admin_notices', [ $this, 'php_version_notice' ] );
			return false;
		}
		global $wp_version;
		if ( version_compare( $wp_version, '5.0', '<' ) ) {
			add_action( 'admin_notices', [ $this, 'wp_version_notice' ] );
			return false;
		}
		return true;
	}

	public function php_version_notice() {
		echo '<div class="notice notice-error"><p>' . __( 'CSV Import Pro benötigt PHP 7.4 oder höher.', 'csv-import' ) . '</p></div>';
	}

	public function wp_version_notice() {
		echo '<div class="notice notice-error"><p>' . __( 'CSV Import Pro benötigt WordPress 5.0 oder höher.', 'csv-import' ) . '</p></div>';
	}

	/**
	 * Code, der bei der Plugin-Deaktivierung ausgeführt wird.
	 * KORREKTUR: Diese Methode ist jetzt statisch und lädt benötigte Dateien selbst.
	 */
	public static function deactivate_plugin() {
		// Benötigte Klassen-Dateien explizit laden, um Fehler zu vermeiden.
		require_once plugin_dir_path( __FILE__ ) . 'includes/classes/class-csv-import-scheduler.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-csv-import-error-handler.php';
		
		// Geplante Imports stoppen
		if ( class_exists( 'CSV_Import_Scheduler' ) ) {
			CSV_Import_Scheduler::unschedule_all();
		}
		
		// Progress-Status zurücksetzen
		delete_option( 'csv_import_progress' );
		
		// Deaktivierung loggen
		if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
			CSV_Import_Error_Handler::handle(
				CSV_Import_Error_Handler::LEVEL_INFO,
				'CSV Import System deaktiviert'
			);
		}
	}

	public static function uninstall() {
		// Alle Plugin-Optionen löschen...
		// (Code bleibt unverändert)
	}
}

// Plugin starten
CSV_Import_Pro::instance();
