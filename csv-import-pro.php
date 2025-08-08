<?php
/**
 * Plugin Name:       CSV Import Pro
 * Plugin URI:        https://example.com/csv-import-plugin
 * Description:       Professionelles CSV-Import System mit korrigierter Scheduler-Integration und stabiler Fehlerbehandlung.
 * Version:           8.4 (Scheduler-Fix)
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
define( 'CSV_IMPORT_PRO_VERSION', '8.4' );
define( 'CSV_IMPORT_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CSV_IMPORT_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'CSV_IMPORT_PRO_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Lädt die Core-Dateien, die sofort benötigt werden.
 * Diese müssen vor allen anderen Komponenten geladen werden.
 */
function csv_import_pro_load_core_files() {
    $core_files = [
        'includes/core/core-functions.php',           // KRITISCH: Zuerst laden
        'includes/class-csv-import-error-handler.php', // Error Handler als zweites
        'includes/class-installer.php'                // Installer für Aktivierung
    ];
    
    foreach ( $core_files as $file ) {
        $path = CSV_IMPORT_PRO_PATH . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
        } else {
            // Kritischer Fehler: Core-Dateien fehlen
            error_log( 'CSV Import Pro: KRITISCHE Core-Datei fehlt: ' . $path );
            
            // Admin-Notice für fehlende Core-Dateien
            add_action( 'admin_notices', function() use ( $file ) {
                echo '<div class="notice notice-error"><p><strong>CSV Import Pro:</strong> Kritische Datei fehlt: ' . esc_html( basename( $file ) ) . '</p></div>';
            });
            
            return false;
        }
    }
    
    return true;
}

// Lade die Core-Dateien sofort beim Plugin-Load
if ( ! csv_import_pro_load_core_files() ) {
    return; // Stoppe Execution wenn Core-Dateien fehlen
}

/**
 * Lädt alle weiteren Plugin-Dateien in der KORREKTEN Reihenfolge.
 * Version 8.4 - Optimierte Ladungsreihenfolge für Scheduler-Kompatibilität
 */
function csv_import_pro_load_plugin_files() {
    $files_to_include = [
        // === CORE KLASSEN (bereits geladen via csv_import_pro_load_core_files) ===
        // 'includes/core/core-functions.php',           // ✅ Bereits geladen
        // 'includes/class-csv-import-error-handler.php', // ✅ Bereits geladen
        
        // === HAUPT-KLASSEN (in Abhängigkeits-Reihenfolge) ===
        'includes/core/class-csv-import-run.php',        // Benötigt core-functions.php
        
        // === FEATURE-KLASSEN ===
        'includes/classes/class-csv-import-backup-manager.php',
        'includes/classes/class-csv-import-notifications.php',
        'includes/classes/class-csv-import-performance-monitor.php',
        'includes/classes/class-csv-import-profile-manager.php',
        'includes/classes/class-csv-import-template-manager.php',
        'includes/classes/class-csv-import-validator.php',
        
        // === SCHEDULER (nach allen Dependencies) ===
        'includes/classes/class-csv-import-scheduler.php', // Benötigt core-functions.php + Error Handler
        
        // === ADMIN-BEREICH (nur wenn im Admin) ===
        'includes/admin/class-admin-menus.php',           // Benötigt alle anderen Klassen
        'includes/admin/admin-ajax.php',                  // AJAX-Handler
    ];

    $loaded_files = [];
    $failed_files = [];

    foreach ( $files_to_include as $file ) {
        // Admin-Dateien nur im Admin-Bereich laden
        if ( strpos( $file, 'includes/admin/' ) === 0 && ! is_admin() ) {
            continue;
        }
        
        $path = CSV_IMPORT_PRO_PATH . $file;
        if ( file_exists( $path ) ) {
            require_once $path;
            $loaded_files[] = $file;
            
            // Debug-Log für erfolgreiche Ladung
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'CSV Import Pro: Geladen - ' . basename( $file ) );
            }
        } else {
            $failed_files[] = $file;
            error_log( 'CSV Import Pro: Datei fehlt - ' . $path );
        }
    }
    
    // Log-Zusammenfassung
    if ( function_exists( 'csv_import_log' ) ) {
        csv_import_log( 'info', 'Plugin-Dateien geladen', [
            'loaded_count' => count( $loaded_files ),
            'failed_count' => count( $failed_files ),
            'failed_files' => $failed_files
        ]);
    }
    
    // Admin-Notice für fehlende Dateien
    if ( ! empty( $failed_files ) && is_admin() ) {
        add_action( 'admin_notices', function() use ( $failed_files ) {
            echo '<div class="notice notice-warning"><p><strong>CSV Import Pro:</strong> ' . count( $failed_files ) . ' Dateien fehlen. Plugin möglicherweise unvollständig installiert.</p></div>';
        });
    }
    
    return empty( $failed_files );
}

/**
 * Haupt-Initialisierungsfunktion mit verbesserter Dependency-Verwaltung.
 * Version 8.4 - Robuste Scheduler-Integration
 */
function csv_import_pro_init() {
    // Lade alle Plugin-Dateien
    $files_loaded = csv_import_pro_load_plugin_files();
    
    if ( ! $files_loaded ) {
        // Wenn kritische Dateien fehlen, Plugin-Execution stoppen
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'critical', 'Plugin-Initialization fehlgeschlagen - kritische Dateien fehlen' );
        }
        return false;
    }

    // === ADMIN-BEREICH INITIALISIERUNG ===
    if ( is_admin() && class_exists( 'CSV_Import_Pro_Admin' ) ) {
        new CSV_Import_Pro_Admin();
        
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'debug', 'Admin-Interface initialisiert' );
        }
    }
    
    // === SCHEDULER-INITIALISIERUNG (mit umfassenden Dependency-Checks) ===
    if ( class_exists( 'CSV_Import_Scheduler' ) ) {
        // Prüfe alle erforderlichen Dependencies
        $required_functions = [
            'csv_import_get_config',
            'csv_import_start_import',
            'csv_import_validate_config',
            'csv_import_is_import_running',
            'csv_import_log'
        ];
        
        $missing_functions = [];
        foreach ( $required_functions as $func ) {
            if ( ! function_exists( $func ) ) {
                $missing_functions[] = $func;
            }
        }
        
        if ( empty( $missing_functions ) ) {
            // Alle Dependencies verfügbar - Scheduler sicher initialisieren
            CSV_Import_Scheduler::init();
            
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'info', 'Scheduler erfolgreich initialisiert' );
            }
        } else {
            // Dependencies fehlen - Fallback-Initialisierung
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'warning', 'Scheduler-Dependencies fehlen - Fallback-Initialisierung', [
                    'missing_functions' => $missing_functions
                ]);
            }
            
            // Versuche späte Initialisierung nach dem 'init' Hook
            add_action( 'init', function() {
                if ( class_exists( 'CSV_Import_Scheduler' ) && function_exists( 'csv_import_get_config' ) ) {
                    CSV_Import_Scheduler::init();
                    
                    if ( function_exists( 'csv_import_log' ) ) {
                        csv_import_log( 'info', 'Scheduler erfolgreich mit Fallback initialisiert' );
                    }
                } else {
                    if ( function_exists( 'csv_import_log' ) ) {
                        csv_import_log( 'error', 'Scheduler-Fallback-Initialisierung fehlgeschlagen' );
                    }
                }
            }, 999 );
        }
    } else {
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'error', 'CSV_Import_Scheduler Klasse nicht gefunden' );
        }
    }
    
    // === WEITERE KOMPONENTEN INITIALISIERUNG ===
    
    // Backup Manager
    if ( class_exists( 'CSV_Import_Backup_Manager' ) ) {
        CSV_Import_Backup_Manager::init();
    }
    
    // Notifications
    if ( class_exists( 'CSV_Import_Notifications' ) ) {
        CSV_Import_Notifications::init();
    }
    
    // Performance Monitor
    if ( class_exists( 'CSV_Import_Performance_Monitor' ) ) {
        CSV_Import_Performance_Monitor::start();
    }
    
    // === WARTUNGS-HOOKS REGISTRIEREN ===
    
    // Tägliche Wartung
    if ( ! wp_next_scheduled( 'csv_import_daily_maintenance' ) ) {
        wp_schedule_event( time(), 'daily', 'csv_import_daily_maintenance' );
    }
    
    // Wöchentliche Wartung
    if ( ! wp_next_scheduled( 'csv_import_weekly_maintenance' ) ) {
        wp_schedule_event( time(), 'weekly', 'csv_import_weekly_maintenance' );
    }
    
    // === INITIALIZATION ERFOLGREICH ===
    if ( function_exists( 'csv_import_log' ) ) {
        csv_import_log( 'info', 'CSV Import Pro erfolgreich initialisiert', [
            'version' => CSV_IMPORT_PRO_VERSION,
            'scheduler_active' => class_exists( 'CSV_Import_Scheduler' ),
            'admin_active' => is_admin() && class_exists( 'CSV_Import_Pro_Admin' ),
            'core_functions_available' => function_exists( 'csv_import_get_config' )
        ]);
    }
    
    return true;
}

// Plugin nach dem Laden aller WordPress-Komponenten initialisieren
add_action( 'plugins_loaded', 'csv_import_pro_init', 10 );

/**
 * Plugin-Aktivierung mit verbesserter Fehlerbehandlung.
 */
register_activation_hook( __FILE__, function() {
    // Prüfe ob Installer verfügbar ist
    if ( class_exists( 'Installer' ) ) {
        try {
            Installer::activate();
            
            // Erfolgreiche Aktivierung protokollieren
            if ( function_exists( 'csv_import_log' ) ) {
                csv_import_log( 'info', 'Plugin erfolgreich aktiviert', [
                    'version' => CSV_IMPORT_PRO_VERSION,
                    'wp_version' => get_bloginfo( 'version' ),
                    'php_version' => PHP_VERSION
                ]);
            }
            
            // Admin-Notice für erfolgreiche Aktivierung
            set_transient( 'csv_import_activated_notice', true, 30 );
            
        } catch ( Exception $e ) {
            // Aktivierungsfehler protokollieren
            error_log( 'CSV Import Pro Aktivierungsfehler: ' . $e->getMessage() );
            
            // Plugin bei kritischen Fehlern wieder deaktivieren
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 
                'CSV Import Pro Aktivierung fehlgeschlagen: ' . $e->getMessage() . 
                '<br><br><a href="' . admin_url( 'plugins.php' ) . '">Zurück zu Plugins</a>',
                'Plugin Aktivierung Fehlgeschlagen',
                ['back_link' => true]
            );
        }
    } else {
        // Installer-Klasse nicht verfügbar
        error_log( 'CSV Import Pro: Installer-Klasse nicht verfügbar bei Aktivierung' );
        wp_die( 
            'CSV Import Pro: Installation unvollständig. Installer-Klasse fehlt.<br>' .
            'Bitte Plugin neu herunterladen und installieren.<br><br>' .
            '<a href="' . admin_url( 'plugins.php' ) . '">Zurück zu Plugins</a>',
            'Plugin Installation Unvollständig',
            ['back_link' => true]
        );
    }
});

/**
 * Plugin-Deaktivierung mit kompletter Bereinigung.
 */
register_deactivation_hook( __FILE__, function() {
    // Alle geplanten Events löschen
    $scheduled_hooks = [
        'csv_import_scheduled',
        'csv_import_daily_cleanup', 
        'csv_import_weekly_maintenance',
        'csv_import_daily_maintenance'
    ];
    
    foreach ( $scheduled_hooks as $hook ) {
        wp_clear_scheduled_hook( $hook );
    }
    
    // Scheduler-spezifische Optionen bereinigen
    if ( class_exists( 'CSV_Import_Scheduler' ) ) {
        CSV_Import_Scheduler::unschedule_all();
    }
    
    // Temporäre Plugin-Daten löschen
    $temp_options = [
        'csv_import_progress',
        'csv_import_running_lock',
        'csv_import_session_id',
        'csv_import_start_time',
        'csv_import_current_header'
    ];
    
    foreach ( $temp_options as $option ) {
        delete_option( $option );
        delete_transient( $option );
    }
    
    // Deaktivierung protokollieren
    if ( function_exists( 'csv_import_log' ) ) {
        csv_import_log( 'info', 'Plugin deaktiviert - Bereinigung abgeschlossen' );
    }
    
    error_log( 'CSV Import Pro: Plugin deaktiviert und bereinigt' );
});

/**
 * Plugin-Update-Hook für zukünftige Versionen.
 */
add_action( 'upgrader_process_complete', function( $upgrader_object, $options ) {
    if ( isset( $options['plugin'] ) && $options['plugin'] === CSV_IMPORT_PRO_BASENAME ) {
        // Plugin wurde aktualisiert
        if ( function_exists( 'csv_import_log' ) ) {
            csv_import_log( 'info', 'Plugin aktualisiert', [
                'new_version' => CSV_IMPORT_PRO_VERSION,
                'previous_version' => get_option( 'csv_import_version', 'unbekannt' )
            ]);
        }
        
        // Version in Datenbank aktualisieren
        update_option( 'csv_import_version', CSV_IMPORT_PRO_VERSION );
        
        // Cache-Bereinigung nach Update
        if ( function_exists( 'csv_import_cleanup_temp_files' ) ) {
            csv_import_cleanup_temp_files();
        }
    }
}, 10, 2 );

/**
 * Emergency-Reset für hängende Imports (Admin-Interface).
 */
add_action( 'admin_init', function() {
    if ( isset( $_GET['csv_emergency_reset'] ) && $_GET['csv_emergency_reset'] === '1' ) {
        // Nur für Admins
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Keine Berechtigung für diese Aktion.' );
        }
        
        // Nonce-Check
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'csv_import_emergency_reset' ) ) {
            wp_die( 'Sicherheitscheck fehlgeschlagen.' );
        }
        
        // Reset durchführen
        if ( function_exists( 'csv_import_force_reset_import_status' ) ) {
            csv_import_force_reset_import_status();
        }
        
        // Zusätzliche Bereinigung
        if ( function_exists( 'csv_import_cleanup_temp_files' ) ) {
            csv_import_cleanup_temp_files();
        }
        
        if ( function_exists( 'csv_import_cleanup_dead_processes' ) ) {
            csv_import_cleanup_dead_processes();
        }
        
        // Erfolgs-Notice
        set_transient( 'csv_import_emergency_reset_success', true, 30 );
        
        // Redirect zurück zum Plugin
        wp_redirect( add_query_arg( [
            'page' => 'csv-import',
            'reset' => 'success'
        ], admin_url( 'tools.php' ) ) );
        exit;
    }
});

/**
 * Admin-Notice für Emergency-Reset-Erfolg.
 */
add_action( 'admin_notices', function() {
    if ( get_transient( 'csv_import_emergency_reset_success' ) ) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>CSV Import Pro:</strong> Notfall-Reset erfolgreich durchgeführt. Alle hängenden Prozesse wurden bereinigt.</p>';
        echo '</div>';
        delete_transient( 'csv_import_emergency_reset_success' );
    }
});

/**
 * Plugin-Health-Check für Admin-Dashboard.
 */
add_action( 'wp_dashboard_setup', function() {
    if ( current_user_can( 'manage_options' ) ) {
        wp_add_dashboard_widget(
            'csv_import_health_widget',
            'CSV Import Pro - Status',
            function() {
                echo '<div style="display: flex; gap: 15px; flex-wrap: wrap;">';
                
                // Plugin-Status
                $all_good = class_exists( 'CSV_Import_Scheduler' ) && function_exists( 'csv_import_get_config' );
                echo '<div style="flex: 1; min-width: 200px;">';
                echo '<h4>' . ( $all_good ? '✅ Plugin OK' : '⚠️ Plugin Probleme' ) . '</h4>';
                echo '<p>Version: ' . CSV_IMPORT_PRO_VERSION . '</p>';
                echo '</div>';
                
                // Import-Status
                if ( function_exists( 'csv_import_get_progress' ) ) {
                    $progress = csv_import_get_progress();
                    $is_running = $progress['running'] ?? false;
                    
                    echo '<div style="flex: 1; min-width: 200px;">';
                    echo '<h4>' . ( $is_running ? '🔄 Import läuft' : '💤 Kein Import' ) . '</h4>';
                    if ( $is_running ) {
                        echo '<p>' . ( $progress['percent'] ?? 0 ) . '% abgeschlossen</p>';
                    } else {
                        $last_run = get_option( 'csv_import_last_run', 'Nie' );
                        if ( $last_run !== 'Nie' ) {
                            echo '<p>Letzter Import: ' . human_time_diff( strtotime( $last_run ) ) . ' ago</p>';
                        }
                    }
                    echo '</div>';
                }
                
                // Scheduler-Status
                if ( class_exists( 'CSV_Import_Scheduler' ) ) {
                    $is_scheduled = CSV_Import_Scheduler::is_scheduled();
                    echo '<div style="flex: 1; min-width: 200px;">';
                    echo '<h4>' . ( $is_scheduled ? '⏰ Geplant' : '⏸️ Nicht geplant' ) . '</h4>';
                    if ( $is_scheduled ) {
                        $next_run = CSV_Import_Scheduler::get_next_scheduled();
                        echo '<p>Nächster Run: ' . human_time_diff( $next_run ) . '</p>';
                    }
                    echo '</div>';
                }
                
                echo '</div>';
                
                // Quick-Actions
                echo '<div style="margin-top: 15px; text-align: center;">';
                echo '<a href="' . admin_url( 'tools.php?page=csv-import' ) . '" class="button button-primary">Import Dashboard</a> ';
                echo '<a href="' . admin_url( 'tools.php?page=csv-import-settings' ) . '" class="button">Einstellungen</a>';
                echo '</div>';
            }
        );
    }
});

/**
 * Globaler Fehler-Handler für unerwartete Plugin-Fehler.
 */
add_action( 'wp_loaded', function() {
    // Prüfe Plugin-Integrität
    $critical_functions = [
        'csv_import_get_config',
        'csv_import_validate_config', 
        'csv_import_get_progress'
    ];
    
    $missing_functions = [];
    foreach ( $critical_functions as $func ) {
        if ( ! function_exists( $func ) ) {
            $missing_functions[] = $func;
        }
    }
    
    if ( ! empty( $missing_functions ) && is_admin() ) {
        add_action( 'admin_notices', function() use ( $missing_functions ) {
            echo '<div class="notice notice-error">';
            echo '<p><strong>CSV Import Pro:</strong> Kritische Funktionen fehlen. Plugin möglicherweise beschädigt.</p>';
            echo '<p>Fehlende Funktionen: <code>' . implode( ', ', $missing_functions ) . '</code></p>';
            echo '<p><a href="' . admin_url( 'plugins.php' ) . '">Plugin deaktivieren/reaktivieren</a> oder neu installieren.</p>';
            echo '</div>';
        });
    }
});

// === PLUGIN VOLLSTÄNDIG GELADEN ===

// Debug-Information für Entwicklung
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    error_log( 'CSV Import Pro v' . CSV_IMPORT_PRO_VERSION . ' - Haupt-Plugin-Datei geladen' );
}
