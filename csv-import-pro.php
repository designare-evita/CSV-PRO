<?php
/**
 * Plugin Name:       CSV Import Pro
 * Plugin URI:        https://example.com/csv-import-plugin
 * Description:       Professionelles CSV-Import System mit verbesserter Fehlerbehandlung und Stabilität.
 * Version:           8.2 (Stabilitäts-Update)
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
define( 'CSV_IMPORT_PRO_VERSION', '8.2' );
define( 'CSV_IMPORT_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CSV_IMPORT_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'CSV_IMPORT_PRO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Lädt alle notwendigen Plugin-Dateien sicher.
 */
function csv_import_pro_load_files() {
    
    // Reihenfolge ist wichtig: Zuerst die Kernfunktionen, dann die Klassen.
    $files_to_include = [
        // Core
        'includes/core/core-functions.php',
        'includes/core/class-csv-import-run.php',

        // Klassen
        'includes/class-csv-import-error-handler.php',
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
        // Nur Admin-Dateien im Admin-Bereich laden
        if ( strpos( $file, 'includes/admin/' ) === 0 && ! is_admin() ) {
            continue;
        }

        $path = CSV_IMPORT_PRO_PATH . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
        } else {
            // Loggt einen Fehler, falls eine Datei fehlt
            error_log('CSV Import Pro: Kritische Datei fehlt und konnte nicht geladen werden: ' . $path);
        }
    }
}

/**
 * Haupt-Initialisierungsfunktion des Plugins.
 */
function csv_import_pro_init() {

    // Alle Dateien laden
    csv_import_pro_load_files();

    // Initialisiert die Admin-Klasse, die die Menüs erstellt
    if ( is_admin() && class_exists('CSV_Import_Pro_Admin') ) {
        new CSV_Import_Pro_Admin();
    }
    
    // Initialisiert den Scheduler, wenn die Klasse existiert
    if (class_exists('CSV_Import_Scheduler')) {
        CSV_Import_Scheduler::init();
    }
}
// Startet das Plugin auf dem 'plugins_loaded' Hook
add_action( 'plugins_loaded', 'csv_import_pro_init' );


/**
 * Logik bei der Aktivierung des Plugins.
 */
register_activation_hook( __FILE__, function() {
    require_once CSV_IMPORT_PRO_PATH . 'includes/class-installer.php';
    if(class_exists('Installer')) {
        Installer::activate();
    }
    // Setzt einen Hinweis für den Benutzer, dass die Aktivierung erfolgreich war
    set_transient( 'csv_import_activated_notice', true, 5 );
});

/**
 * Logik bei der Deaktivierung des Plugins.
 */
register_deactivation_hook( __FILE__, function() {
    // Geplante Aufgaben entfernen
    wp_clear_scheduled_hook('csv_import_scheduled');
    wp_clear_scheduled_hook('csv_import_daily_cleanup');
    wp_clear_scheduled_hook('csv_import_weekly_maintenance');
    
    // Laufenden Import-Status zurücksetzen
    delete_option('csv_import_progress');
    delete_option('csv_import_running_lock');
});
