<?php
/**
 * Plugin Name:       CSV Import Pro
 * Plugin URI:        https://example.com/csv-import-plugin
 * Description:       Professionelles CSV-Import System mit verbesserter Fehlerbehandlung und Stabilität.
 * Version:           8.3 (Stabilitäts-Fix)
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

// Direkten Zugriff verhindern
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Mehrfache Ladung verhindern
if ( defined( 'CSV_IMPORT_PRO_LOADED' ) ) {
    return;
}
define( 'CSV_IMPORT_PRO_LOADED', true );

// Plugin-Konstanten definieren
define( 'CSV_IMPORT_PRO_VERSION', '8.3' );
define( 'CSV_IMPORT_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CSV_IMPORT_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'CSV_IMPORT_PRO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Lädt die essentiellen Dateien, die sofort benötigt werden.
 */
function csv_import_pro_load_core_files() {
    $core_files = [
        'includes/class-csv-import-error-handler.php',
        'includes/core/core-functions.php',
        'includes/class-installer.php'
    ];
    foreach ( $core_files as $file ) {
        $path = CSV_IMPORT_PRO_PATH . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
}

// Lade die Core-Dateien sofort, damit sie für die Aktivierung verfügbar sind.
csv_import_pro_load_core_files();

/**
 * Lädt den Rest der Plugin-Dateien.
 */
function csv_import_pro_load_plugin_files() {
    $files_to_include = [
        // Klassen
        'includes/core/class-csv-import-run.php',
        'includes/classes/class-csv-import-backup-manager.php',
        'includes/classes/class-csv-import-notifications.php',
        'includes/classes/class-csv-import-performance-monitor.php',
        'includes/classes/class-csv-import-profile-manager.php',
        'includes/classes/class-csv-import-scheduler.php',
        'includes/classes/class-csv-import-template-manager.php',
        'includes/classes/class-csv-import-validator.php',
        // Admin-Bereich (nur laden, wenn im Admin-Bereich)
        'includes/admin/class-admin-menus.php',
        'includes/admin/admin-ajax.php',
    ];

    foreach ( $files_to_include as $file ) {
        if ( strpos( $file, 'includes/admin/' ) === 0 && ! is_admin() ) {
            continue;
        }
        $path = CSV_IMPORT_PRO_PATH . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
        } else {
            error_log('CSV Import Pro: Kritische Datei fehlt: ' . $path);
        }
    }
}

/**
 * Haupt-Initialisierungsfunktion.
 */
function csv_import_pro_init() {
    // Lade die restlichen Dateien
    csv_import_pro_load_plugin_files();

    // Initialisiert die Admin-Klasse
    if ( is_admin() && class_exists('CSV_Import_Pro_Admin') ) {
        new CSV_Import_Pro_Admin();
    }
    
    // Initialisiert den Scheduler
    if (class_exists('CSV_Import_Scheduler')) {
        CSV_Import_Scheduler::init();
    }
}
add_action( 'plugins_loaded', 'csv_import_pro_init' );

/**
 * Logik bei der Aktivierung.
 */
register_activation_hook( __FILE__, function() {
    if(class_exists('Installer')) {
        Installer::activate();
    }
    set_transient( 'csv_import_activated_notice', true, 5 );
});

/**
 * Logik bei der Deaktivierung.
 */
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook('csv_import_scheduled');
    wp_clear_scheduled_hook('csv_import_daily_cleanup');
    wp_clear_scheduled_hook('csv_import_weekly_maintenance');
    delete_option('csv_import_progress');
    delete_option('csv_import_running_lock');
});
