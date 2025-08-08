<?php
/**
 * Plugin Name:       CSV Import Pro
 * Plugin URI:        https://example.com/csv-import-plugin
 * Description:       Professionelles CSV-Import System mit verbesserter Fehlerbehandlung, Batch-Verarbeitung, Backups, Scheduling und robuster Sicherheit.
 * Version:           6.2
 * Author:            Michael Kanda
 * Author URI:        https://example.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       csv-import
 * Domain Path:       /languages
 * Requires at least: 5.0
 * Tested up to:      6.4
 * Requires PHP:      7.4
 * Network:           false
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
 * 
 * @since 6.2
 */
final class CSV_Import_Pro {

	/**
	 * Plugin-Instanz (Singleton)
	 * 
	 * @var CSV_Import_Pro|null
	 */
	private static $instance = null;

	/**
	 * Plugin-Version
	 * 
	 * @var string
	 */
	public $version = '6.2';

	/**
	 * Mindest-WordPress-Version
	 * 
	 * @var string
	 */
	private $min_wp_version = '5.0';

	/**
	 * Mindest-PHP-Version
	 * 
	 * @var string
	 */
	private $min_php_version = '7.4';

	/**
	 * Feature-Klassen Registry
	 * 
	 * @var array
	 */
	private $feature_classes = [];

	/**
	 * Plugin-Status Flags
	 * 
	 * @var array
	 */
	private $status = [
		'requirements_met' => false,
		'core_loaded' => false,
		'features_loaded' => false,
		'admin_loaded' => false
	];

	/**
	 * Singleton Pattern - Holt oder erstellt Plugin-Instanz
	 * 
	 * @return CSV_Import_Pro
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Konstruktor - Initialisiert das Plugin
	 */
	private function __construct() {
		$this->define_constants();
		$this->setup_hooks();
		$this->init_plugin();
	}

	/**
	 * Verhindert das Klonen der Instanz
	 */
	private function __clone() {}

	/**
	 * Verhindert die Deserialisierung der Instanz
	 */
	public function __wakeup() {}

	/**
	 * Definiert Plugin-Konstanten
	 */
	private function define_constants() {
		define( 'CSV_IMPORT_PRO_VERSION', $this->version );
		define( 'CSV_IMPORT_PRO_PATH', plugin_dir_path( __FILE__ ) );
		define( 'CSV_IMPORT_PRO_URL', plugin_dir_url( __FILE__ ) );
		define( 'CSV_IMPORT_PRO_BASENAME', plugin_basename( __FILE__ ) );
		define( 'CSV_IMPORT_PRO_SLUG', dirname( CSV_IMPORT_PRO_BASENAME ) );
		
		// Debug-Konstante falls nicht definiert
		if ( ! defined( 'CSV_IMPORT_DEBUG' ) ) {
			define( 'CSV_IMPORT_DEBUG', defined( 'WP_DEBUG' ) && WP_DEBUG );
		}
	}

	/**
	 * Registriert WordPress-Hooks
	 */
	private function setup_hooks() {
		// Aktivierungs- und Deaktivierungshooks
		register_activation_hook( __FILE__, [ $this, 'activate_plugin' ] );
		register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate_plugin' ] );
		register_uninstall_hook( __FILE__, [ __CLASS__, 'uninstall_plugin' ] );

		// WordPress-Lifecycle-Hooks mit optimierten Prioritäten
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ], 5 );
		add_action( 'plugins_loaded', [ $this, 'check_requirements' ], 10 );
		add_action( 'plugins_loaded', [ $this, 'load_core_components' ], 15 );
		add_action( 'plugins_loaded', [ $this, 'init_feature_classes' ], 20 );
		add_action( 'init', [ $this, 'late_init' ], 10 );
	}

	/**
	 * Initialisiert das Plugin
	 */
	private function init_plugin() {
		// Sofortige Initialisierung für kritische Komponenten
		$this->maybe_load_error_handler();
		
		// Admin-spezifische Initialisierung
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this, 'init_admin_components' ], 10 );
			add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );
		}
	}

	/**
	 * Lädt den Error Handler falls verfügbar
	 */
	private function maybe_load_error_handler() {
		$error_handler_path = CSV_IMPORT_PRO_PATH . 'includes/class-csv-import-error-handler.php';
		if ( file_exists( $error_handler_path ) ) {
			require_once $error_handler_path;
			
			if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
				CSV_Import_Error_Handler::handle(
					CSV_Import_Error_Handler::LEVEL_INFO,
					'CSV Import Pro Plugin initialisiert',
					[ 'version' => $this->version, 'php' => PHP_VERSION ]
				);
			}
		}
	}

	/**
	 * Lädt die Sprachdateien
	 */
	public function load_textdomain() {
		$loaded = load_plugin_textdomain( 
			'csv-import', 
			false, 
			CSV_IMPORT_PRO_SLUG . '/languages' 
		);

		if ( CSV_IMPORT_DEBUG && ! $loaded ) {
			error_log( 'CSV Import Pro: Sprachdateien konnten nicht geladen werden' );
		}
	}

	/**
	 * Überprüft System-Anforderungen
	 */
	public function check_requirements() {
		$requirements_met = true;

		// PHP-Version prüfen
		if ( version_compare( PHP_VERSION, $this->min_php_version, '<' ) ) {
			add_action( 'admin_notices', [ $this, 'php_version_notice' ] );
			$requirements_met = false;
		}

		// WordPress-Version prüfen
		global $wp_version;
		if ( version_compare( $wp_version, $this->min_wp_version, '<' ) ) {
			add_action( 'admin_notices', [ $this, 'wp_version_notice' ] );
			$requirements_met = false;
		}

		// Erweiterte System-Checks
		if ( $requirements_met ) {
			$requirements_met = $this->check_extended_requirements();
		}

		$this->status['requirements_met'] = $requirements_met;

		// Plugin deaktivieren falls Anforderungen nicht erfüllt
		if ( ! $requirements_met && is_admin() ) {
			add_action( 'admin_init', [ $this, 'deactivate_self' ] );
		}
	}

	/**
	 * Erweiterte System-Anforderungen prüfen
	 * 
	 * @return bool
	 */
	private function check_extended_requirements() {
		$checks = [
			'memory' => $this->check_memory_limit(),
			'functions' => $this->check_required_functions(),
			'permissions' => $this->check_file_permissions(),
		];

		$failed_checks = array_filter( $checks, function( $check ) {
			return ! $check['status'];
		});

		if ( ! empty( $failed_checks ) && class_exists( 'CSV_Import_Error_Handler' ) ) {
			CSV_Import_Error_Handler::handle(
				CSV_Import_Error_Handler::LEVEL_WARNING,
				'Erweiterte System-Checks fehlgeschlagen',
				[ 'failed_checks' => array_keys( $failed_checks ) ]
			);
		}

		return empty( $failed_checks );
	}

	/**
	 * Prüft verfügbaren Arbeitsspeicher
	 * 
	 * @return array
	 */
	private function check_memory_limit() {
		$memory_limit = ini_get( 'memory_limit' );
		$memory_bytes = $this->convert_to_bytes( $memory_limit );
		$min_memory = 128 * 1024 * 1024; // 128MB

		return [
			'status' => ( $memory_bytes === -1 || $memory_bytes >= $min_memory ),
			'current' => $memory_limit,
			'required' => '128M'
		];
	}

	/**
	 * Prüft erforderliche PHP-Funktionen
	 * 
	 * @return array
	 */
	private function check_required_functions() {
		$required_functions = [
			'curl_init', 'file_get_contents', 'json_encode', 
			'json_decode', 'mb_convert_encoding', 'iconv'
		];

		$missing_functions = [];
		foreach ( $required_functions as $function ) {
			if ( ! function_exists( $function ) ) {
				$missing_functions[] = $function;
			}
		}

		return [
			'status' => empty( $missing_functions ),
			'missing' => $missing_functions
		];
	}

	/**
	 * Prüft Dateiberechtigungen
	 * 
	 * @return array
	 */
	private function check_file_permissions() {
		$upload_dir = wp_upload_dir();
		$test_dirs = [
			$upload_dir['basedir'],
			ABSPATH . 'data',
		];

		$permission_issues = [];
		foreach ( $test_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				if ( ! wp_mkdir_p( $dir ) ) {
					$permission_issues[] = $dir . ' (nicht erstellbar)';
				}
			} elseif ( ! is_writable( $dir ) ) {
				$permission_issues[] = $dir . ' (nicht beschreibbar)';
			}
		}

		return [
			'status' => empty( $permission_issues ),
			'issues' => $permission_issues
		];
	}

	/**
	 * Lädt Kern-Komponenten des Plugins
	 */
	public function load_core_components() {
		if ( ! $this->status['requirements_met'] ) {
			return;
		}

		// Core-Funktionen als erstes laden
		$this->include_if_exists( 'includes/core/core-functions.php', true );
		
		// Error Handler laden (falls nicht schon geladen)
		$this->include_if_exists( 'includes/class-csv-import-error-handler.php' );
		
		// Installer für Aktivierung/Deaktivierung
		$this->include_if_exists( 'includes/class-installer.php' );
		
		// Core Import-Logik
		$this->include_if_exists( 'includes/core/class-csv-import-run.php', true );

		$this->status['core_loaded'] = true;

		if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
			CSV_Import_Error_Handler::handle(
				CSV_Import_Error_Handler::LEVEL_INFO,
				'Kern-Komponenten geladen'
			);
		}
	}

	/**
	 * Initialisiert Feature-Klassen
	 */
	public function init_feature_classes() {
		if ( ! $this->status['core_loaded'] ) {
			return;
		}

		// Feature-Klassen definieren
		$this->feature_classes = [
			'backup' => [
				'file' => 'includes/classes/class-csv-import-backup-manager.php',
				'class' => 'CSV_Import_Backup_Manager',
				'critical' => true
			],
			'scheduler' => [
				'file' => 'includes/classes/class-csv-import-scheduler.php',
				'class' => 'CSV_Import_Scheduler',
				'critical' => false
			],
			'validator' => [
				'file' => 'includes/classes/class-csv-import-validator.php',
				'class' => 'CSV_Import_Validator',
				'critical' => true
			],
			'notifications' => [
				'file' => 'includes/classes/class-csv-import-notifications.php',
				'class' => 'CSV_Import_Notifications',
				'critical' => false
			],
			'template_manager' => [
				'file' => 'includes/classes/class-csv-import-template-manager.php',
				'class' => 'CSV_Import_Template_Manager',
				'critical' => false
			],
			'profile_manager' => [
				'file' => 'includes/classes/class-csv-import-profile-manager.php',
				'class' => 'CSV_Import_Profile_Manager',
				'critical' => false
			],
			'performance_monitor' => [
				'file' => 'includes/classes/class-csv-import-performance-monitor.php',
				'class' => 'CSV_Import_Performance_Monitor',
				'critical' => false
			]
		];

		// Feature-Klassen laden
		$loaded_features = [];
		$failed_features = [];

		foreach ( $this->feature_classes as $feature_name => $feature_config ) {
			$loaded = $this->include_if_exists( $feature_config['file'], $feature_config['critical'] );
			
			if ( $loaded && class_exists( $feature_config['class'] ) ) {
				// Klasse initialisieren falls init-Methode vorhanden
				if ( method_exists( $feature_config['class'], 'init' ) ) {
					call_user_func( [ $feature_config['class'], 'init' ] );
				}
				$loaded_features[] = $feature_name;
			} else {
				$failed_features[] = $feature_name;
				
				// Kritische Features müssen geladen werden
				if ( $feature_config['critical'] ) {
					if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
						CSV_Import_Error_Handler::handle(
							CSV_Import_Error_Handler::LEVEL_ERROR,
							"Kritisches Feature konnte nicht geladen werden: {$feature_name}",
							[ 'file' => $feature_config['file'] ]
						);
					}
				}
			}
		}

		$this->status['features_loaded'] = true;

		if ( CSV_IMPORT_DEBUG ) {
			error_log( sprintf(
				'CSV Import Pro: Features geladen: %s | Fehlgeschlagen: %s',
				implode( ', ', $loaded_features ),
				implode( ', ', $failed_features )
			) );
		}
	}

	/**
	 * Initialisiert Admin-Komponenten
	 */
	public function init_admin_components() {
		if ( ! $this->status['features_loaded'] ) {
			return;
		}

		// Admin-Klassen laden
		$this->include_if_exists( 'includes/admin/class-admin-menus.php', true );
		$this->include_if_exists( 'includes/admin/admin-ajax.php', true );

		// Admin-Menüs initialisieren
		if ( class_exists( 'CSV_Import_Pro_Admin' ) ) {
			new CSV_Import_Pro_Admin();
		}

		$this->status['admin_loaded'] = true;
	}

	/**
	 * Späte Initialisierung für Hooks die nach 'init' kommen
	 */
	public function late_init() {
		// Backup-Tabelle erstellen falls nicht vorhanden
		if ( class_exists( 'CSV_Import_Backup_Manager' ) && 
			 method_exists( 'CSV_Import_Backup_Manager', 'create_backup_table' ) ) {
			CSV_Import_Backup_Manager::create_backup_table();
		}

		// Wartungs-Cron-Jobs planen
		$this->schedule_maintenance_tasks();

		// Plugin als vollständig geladen markieren
		do_action( 'csv_import_pro_loaded', $this );
	}

	/**
	 * Plant Wartungsaufgaben
	 */
	private function schedule_maintenance_tasks() {
		// Tägliche Bereinigung
		if ( ! wp_next_scheduled( 'csv_import_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'csv_import_daily_maintenance' );
		}

		// Wöchentliche Wartung
		if ( ! wp_next_scheduled( 'csv_import_weekly_maintenance' ) ) {
			wp_schedule_event( time(), 'weekly', 'csv_import_weekly_maintenance' );
		}
	}

	/**
	 * Lädt eine Datei falls sie existiert
	 * 
	 * @param string $file Relativer Pfad zur Datei
	 * @param bool $critical Ob die Datei kritisch ist
	 * @return bool Ob die Datei erfolgreich geladen wurde
	 */
	private function include_if_exists( $file, $critical = false ) {
		$full_path = CSV_IMPORT_PRO_PATH . $file;
		
		if ( file_exists( $full_path ) ) {
			require_once $full_path;
			return true;
		}
		
		$error_level = $critical ? 'ERROR' : 'WARNING';
		$message = "Datei nicht gefunden: {$file}";
		
		if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
			CSV_Import_Error_Handler::handle(
				$critical ? CSV_Import_Error_Handler::LEVEL_ERROR : CSV_Import_Error_Handler::LEVEL_WARNING,
				$message,
				[ 'file_path' => $full_path, 'critical' => $critical ]
			);
		} else {
			error_log( "CSV Import Pro [{$error_level}]: {$message}" );
		}
		
		return false;
	}

	/**
	 * Plugin-Aktivierung
	 */
	public function activate_plugin() {
		// Installer aufrufen falls verfügbar
		if ( class_exists( 'Installer' ) && method_exists( 'Installer', 'activate' ) ) {
			Installer::activate();
		} else {
			// Fallback-Aktivierung
			$this->fallback_activation();
		}

		// Plugin-Version speichern
		update_option( 'csv_import_pro_version', $this->version );
		update_option( 'csv_import_pro_activated', current_time( 'mysql' ) );

		// Flush rewrite rules
		flush_rewrite_rules();
	}

	/**
	 * Plugin-Deaktivierung (statische Methode)
	 */
	public static function deactivate_plugin() {
		// Sicherstellen dass benötigte Klassen geladen sind
		$plugin_path = plugin_dir_path( __FILE__ );
		
		// Scheduler-Klasse laden falls nicht verfügbar
		$scheduler_file = $plugin_path . 'includes/classes/class-csv-import-scheduler.php';
		if ( file_exists( $scheduler_file ) && ! class_exists( 'CSV_Import_Scheduler' ) ) {
			require_once $scheduler_file;
		}
		
		// Error Handler laden falls nicht verfügbar
		$error_handler_file = $plugin_path . 'includes/class-csv-import-error-handler.php';
		if ( file_exists( $error_handler_file ) && ! class_exists( 'CSV_Import_Error_Handler' ) ) {
			require_once $error_handler_file;
		}
		
		// Geplante Imports stoppen
		if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'unschedule_all' ) ) {
			CSV_Import_Scheduler::unschedule_all();
		}
		
		// Progress-Status zurücksetzen
		delete_option( 'csv_import_progress' );
		delete_option( 'csv_import_running_lock' );
		
		// Maintenance-Cron-Jobs entfernen
		wp_clear_scheduled_hook( 'csv_import_daily_maintenance' );
		wp_clear_scheduled_hook( 'csv_import_weekly_maintenance' );
		
		// Deaktivierung loggen
		if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
			CSV_Import_Error_Handler::handle(
				CSV_Import_Error_Handler::LEVEL_INFO,
				'CSV Import System deaktiviert',
				[ 'timestamp' => current_time( 'mysql' ) ]
			);
		}

		// Deaktivierungs-Timestamp speichern
		update_option( 'csv_import_pro_deactivated', current_time( 'mysql' ) );

		// Rewrite rules zurücksetzen
		flush_rewrite_rules();
	}

	/**
	 * Plugin-Deinstallation (statische Methode)
	 */
	public static function uninstall_plugin() {
		// Nur ausführen wenn tatsächlich deinstalliert wird
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}

		global $wpdb;

		// Alle Plugin-Optionen löschen
		$option_patterns = [
			'csv_import_%',
			'%csv_import_%',
		];

		foreach ( $option_patterns as $pattern ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			) );
		}

		// Backup-Tabelle löschen
		$backup_table = $wpdb->prefix . 'csv_import_backups';
		$wpdb->query( "DROP TABLE IF EXISTS {$backup_table}" );

		// Upload-Verzeichnisse bereinigen
		$upload_dir = wp_upload_dir();
		$csv_dirs = [
			$upload_dir['basedir'] . '/csv-import-temp/',
			$upload_dir['basedir'] . '/csv-import-images/',
			$upload_dir['basedir'] . '/csv-import-backups/',
		];

		foreach ( $csv_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				self::recursive_rmdir( $dir );
			}
		}

		// Cron-Jobs entfernen
		wp_clear_scheduled_hook( 'csv_import_daily_maintenance' );
		wp_clear_scheduled_hook( 'csv_import_weekly_maintenance' );

		// Meta-Felder von Posts entfernen (optional - könnte Daten zerstören)
		// $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_csv_import_%'");
	}

	/**
	 * Fallback-Aktivierung falls Installer nicht verfügbar
	 */
	private function fallback_activation() {
		// Grundlegende Verzeichnisse erstellen
		$dirs = [
			ABSPATH . 'data/',
			wp_upload_dir()['basedir'] . '/csv-import-temp/',
		];

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		// Basis-Einstellungen setzen
		$default_settings = [
			'csv_import_version' => $this->version,
			'csv_import_post_type' => 'page',
			'csv_import_post_status' => 'draft',
			'csv_import_page_builder' => 'gutenberg',
		];

		foreach ( $default_settings as $option => $value ) {
			if ( get_option( $option ) === false ) {
				update_option( $option, $value );
			}
		}
	}

	/**
	 * Plugin selbst deaktivieren
	 */
	public function deactivate_self() {
		deactivate_plugins( CSV_IMPORT_PRO_BASENAME );
	}

	/**
	 * Admin-Notices anzeigen
	 */
	public function show_admin_notices() {
		// Anforderungen nicht erfüllt
		if ( ! $this->status['requirements_met'] ) {
			return; // Spezifische Notices werden bereits in check_requirements() hinzugefügt
		}

		// Aktivierungs-Notice
		if ( get_transient( 'csv_import_pro_activated' ) ) {
			echo '<div class="notice notice-success is-dismissible">';
			echo '<p><strong>CSV Import Pro:</strong> Plugin erfolgreich aktiviert. ';
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=csv-import' ) ) . '">Jetzt konfigurieren</a></p>';
			echo '</div>';
			delete_transient( 'csv_import_pro_activated' );
		}

		// Stuck-Import-Reset-Notice
		if ( get_transient( 'csv_import_stuck_reset_notice' ) ) {
			echo '<div class="notice notice-warning is-dismissible">';
			echo '<p><strong>CSV Import Pro:</strong> Ein hängender Import-Prozess wurde automatisch zurückgesetzt.</p>';
			echo '</div>';
			delete_transient( 'csv_import_stuck_reset_notice' );
		}
	}

	/**
	 * PHP-Version-Notice
	 */
	public function php_version_notice() {
		echo '<div class="notice notice-error">';
		echo '<p><strong>CSV Import Pro:</strong> ';
		printf( 
			__( 'Benötigt PHP %s oder höher. Aktuelle Version: %s', 'csv-import' ),
			$this->min_php_version,
			PHP_VERSION
		);
		echo '</p></div>';
	}

	/**
	 * WordPress-Version-Notice
	 */
	public function wp_version_notice() {
		global $wp_version;
		echo '<div class="notice notice-error">';
		echo '<p><strong>CSV Import Pro:</strong> ';
		printf( 
			__( 'Benötigt WordPress %s oder höher. Aktuelle Version: %s', 'csv-import' ),
			$this->min_wp_version,
			$wp_version
		);
		echo '</p></div>';
	}

	/**
	 * Hilfsfunktion: Bytes-Konvertierung
	 * 
	 * @param string $size_str
	 * @return int
	 */
	private function convert_to_bytes( $size_str ) {
		$size_str = trim( $size_str );
		if ( empty( $size_str ) || $size_str === '-1' ) {
			return -1; // Unbegrenzter Speicher
		}
		
		$last = strtolower( $size_str[ strlen( $size_str ) - 1 ] );
		$size = (int) $size_str;

		switch ( $last ) {
			case 'g':
				$size *= 1024;
			case 'm':
				$size *= 1024;
			case 'k':
				$size *= 1024;
		}

		return $size;
	}

	/**
	 * Hilfsfunktion: Verzeichnis rekursiv löschen
	 * 
	 * @param string $dir
	 * @return bool
	 */
	private static function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), [ '.', '..' ] );
		foreach ( $files as $file ) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			is_dir( $path ) ? self::recursive_rmdir( $path ) : unlink( $path );
		}

		return rmdir( $dir );
	}

	/**
	 * Plugin-Status für Debugging
	 * 
	 * @return array
	 */
	public function get_status() {
		return array_merge( $this->status, [
			'version' => $this->version,
			'loaded_features' => array_keys( array_filter( $this->feature_classes, function( $feature ) {
				return class_exists( $feature['class'] );
			} ) ),
			'php_version' => PHP_VERSION,
			'wp_version' => get_bloginfo( 'version' ),
			'memory_limit' => ini_get( 'memory_limit' ),
		] );
	}
}

/**
 * Hauptfunktion zum Starten des Plugins
 * 
 * @return CSV_Import_Pro
 */
function csv_import_pro() {
	return CSV_Import_Pro::instance();
}

/**
 * Plugin starten
 */
csv_import_pro();

/**
 * Debugging-Funktion (nur bei aktiviertem Debug-Modus)
 */
if ( CSV_IMPORT_DEBUG && function_exists( 'add_action' ) ) {
	add_action( 'wp_footer', function() {
		if ( current_user_can( 'manage_options' ) ) {
			$status = csv_import_pro()->get_status();
			echo '<!-- CSV Import Pro Debug: ' . wp_json_encode( $status, JSON_PRETTY_PRINT ) . ' -->';
		}
	} );
}
