<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}

/**
 * CSV Import Scheduler Klasse
 * * Verwaltet geplante CSV-Imports und automatische Wiederholungen
 * * @since 5.1
 */
class CSV_Import_Scheduler {
    
    // Hook-Namen für geplante Events
    const HOOK_SCHEDULED_IMPORT = 'csv_import_scheduled';
    const HOOK_DAILY_CLEANUP = 'csv_import_daily_cleanup';
    const HOOK_WEEKLY_MAINTENANCE = 'csv_import_weekly_maintenance';
    
    // Verfügbare Zeitintervalle
    const INTERVALS = [
        'hourly' => 'Stündlich',
        'twicedaily' => 'Zweimal täglich',
        'daily' => 'Täglich',
        'weekly' => 'Wöchentlich',
        'monthly' => 'Monatlich'
    ];
    
    private static $instance = null;
    
    /**
     * Singleton Pattern
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialisierung
     */
    public static function init() {
        $instance = self::instance();
        $instance->setup_hooks();
        $instance->register_custom_intervals();
    }
    
    /**
     * WordPress Hooks registrieren
     */
    private function setup_hooks() {
        // Geplante Import-Events
        add_action( self::HOOK_SCHEDULED_IMPORT, [ $this, 'execute_scheduled_import' ] );
        
        // Wartung und Bereinigung
        add_action( self::HOOK_DAILY_CLEANUP, [ $this, 'daily_cleanup' ] );
        add_action( self::HOOK_WEEKLY_MAINTENANCE, [ $this, 'weekly_maintenance' ] );
        
        // Plugin-Deaktivierung: Alle geplanten Events löschen
        register_deactivation_hook( CSV_IMPORT_PRO_PATH . 'csv-import-pro.php', [ __CLASS__, 'unschedule_all' ] );
    }
    
    /**
     * Benutzerdefinierte Zeitintervalle registrieren
     */
    private function register_custom_intervals() {
        add_filter( 'cron_schedules', [ $this, 'add_custom_cron_intervals' ] );
    }
    
    /**
     * Fügt benutzerdefinierte Cron-Intervalle hinzu
     */
    public function add_custom_cron_intervals( $schedules ) {
        // Monatlich
        $schedules['monthly'] = [
            'interval' => 30 * 24 * 60 * 60, // 30 Tage
            'display'  => __( 'Einmal im Monat', 'csv-import' )
        ];
        
        // Alle 15 Minuten (für Tests)
        $schedules['fifteen_minutes'] = [
            'interval' => 15 * 60, // 15 Minuten
            'display'  => __( 'Alle 15 Minuten', 'csv-import' )
        ];
        
        // Alle 30 Minuten
        $schedules['thirty_minutes'] = [
            'interval' => 30 * 60, // 30 Minuten
            'display'  => __( 'Alle 30 Minuten', 'csv-import' )
        ];
        
        return $schedules;
    }
    
    // ===================================================================
    // ÖFFENTLICHE METHODEN FÜR SCHEDULING
    // ===================================================================
    
    /**
     * Prüft ob ein geplanter Import aktiv ist
     * * @param string $hook_name Hook-Name (optional, default: scheduled import)
     * @return bool
     */
    public static function is_scheduled( $hook_name = null ) {
        if ( $hook_name === null ) {
            $hook_name = self::HOOK_SCHEDULED_IMPORT;
        }
        
        $timestamp = wp_next_scheduled( $hook_name );
        return $timestamp !== false;
    }
    
    /**
     * Holt das nächste geplante Event
     * * @param string $hook_name Hook-Name (optional)
     * @return int|false Timestamp oder false wenn nicht geplant
     */
    public static function get_next_scheduled( $hook_name = null ) {
        if ( $hook_name === null ) {
            $hook_name = self::HOOK_SCHEDULED_IMPORT;
        }
        
        return wp_next_scheduled( $hook_name );
    }
    
    /**
     * Plant einen wiederkehrenden Import
     * * @param string $frequency Häufigkeit (hourly, daily, weekly, monthly)
     * @param string $source Import-Quelle (dropbox, local)
     * @param array $options Zusätzliche Optionen
     * @return bool|WP_Error
     */
    public static function schedule_import( $frequency, $source, $options = [] ) {
        try {
            // Bestehende Planung löschen
            self::unschedule_import();
            
            // Validierung
            if ( ! in_array( $frequency, array_keys( self::INTERVALS ) ) ) {
                throw new Exception( 'Ungültige Frequenz: ' . $frequency );
            }
            
            if ( ! in_array( $source, ['dropbox', 'local'] ) ) {
                throw new Exception( 'Ungültige Quelle: ' . $source );
            }
            
            // Start-Zeit berechnen (nächste volle Stunde)
            $start_time = strtotime( '+1 hour', current_time( 'timestamp' ) );
            $start_time = strtotime( date( 'Y-m-d H:00:00', $start_time ) );
            
            // Event planen
            $result = wp_schedule_event( 
                $start_time, 
                $frequency, 
                self::HOOK_SCHEDULED_IMPORT,
                [ $source, $options ]
            );
            
            if ( $result === false ) {
                throw new Exception( 'WordPress konnte das Event nicht planen' );
            }
            
            // Einstellungen speichern
            update_option( 'csv_import_scheduled_frequency', $frequency );
            update_option( 'csv_import_scheduled_source', $source );
            update_option( 'csv_import_scheduled_options', $options );
            update_option( 'csv_import_scheduled_start', $start_time );
            
            // Logging
            if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
                CSV_Import_Error_Handler::log_info( 
                    "Geplanter Import aktiviert: {$frequency} für Quelle {$source}",
                    [
                        'start_time' => date( 'Y-m-d H:i:s', $start_time ),
                        'options' => $options
                    ]
                );
            }
            
            return true;
            
        } catch ( Exception $e ) {
            if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
                CSV_Import_Error_Handler::log_error(
                    'Fehler beim Planen des Imports: ' . $e->getMessage(),
                    [
                        'frequency' => $frequency,
                        'source' => $source,
                        'options' => $options
                    ]
                );
            }
            
            return new WP_Error( 'scheduling_failed', $e->getMessage() );
        }
    }
    
    /**
     * Stoppt den geplanten Import
     * * @return bool
     */
    public static function unschedule_import() {
        $timestamp = wp_next_scheduled( self::HOOK_SCHEDULED_IMPORT );
        
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK_SCHEDULED_IMPORT );
        }
        
        // Alle Events dieses Typs löschen (Sicherheit)
        wp_clear_scheduled_hook( self::HOOK_SCHEDULED_IMPORT );
        
        // Einstellungen löschen
        delete_option( 'csv_import_scheduled_frequency' );
        delete_option( 'csv_import_scheduled_source' );
        delete_option( 'csv_import_scheduled_options' );
        delete_option( 'csv_import_scheduled_start' );
        
        // Logging
        if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
            CSV_Import_Error_Handler::log_info( 'Geplanter Import deaktiviert' );
        }
        
        return true;
    }
    
    /**
     * Stoppt ALLE geplanten Events des Plugins
     */
    public static function unschedule_all() {
        $hooks = [
            self::HOOK_SCHEDULED_IMPORT,
            self::HOOK_DAILY_CLEANUP,
            self::HOOK_WEEKLY_MAINTENANCE
        ];
        
        foreach ( $hooks as $hook ) {
            wp_clear_scheduled_hook( $hook );
        }
        
        // Scheduler-spezifische Optionen löschen
        $options_to_delete = [
            'csv_import_scheduled_frequency',
            'csv_import_scheduled_source', 
            'csv_import_scheduled_options',
            'csv_import_scheduled_start',
            'csv_import_scheduler_stats'
        ];
        
        foreach ( $options_to_delete as $option ) {
            delete_option( $option );
        }
    }
    
    // ===================================================================
    // EVENT HANDLER
    // ===================================================================
    
    /**
     * Führt einen geplanten Import aus
     * * @param string $source
     * @param array $options
     */
    public function execute_scheduled_import( $source = 'local', $options = [] ) {
        // Bereits laufenden Import prüfen
        if ( function_exists( 'csv_import_is_import_running' ) && csv_import_is_import_running() ) {
            $this->log_scheduler_event( 'warning', 'Geplanter Import übersprungen - bereits ein Import läuft' );
            return;
        }
        
        $this->log_scheduler_event( 'info', "Geplanter Import gestartet für Quelle: {$source}" );
        
        try {
            // Import-Statistiken aktualisieren
            $this->update_scheduler_stats( 'started' );
            
            // Import durchführen
            if ( function_exists( 'csv_import_start_import' ) ) {
                $result = csv_import_start_import( $source );
                
                if ( $result['success'] ) {
                    $this->log_scheduler_event( 'info', 
                        "Geplanter Import erfolgreich: {$result['processed']} Einträge verarbeitet" 
                    );
                    $this->update_scheduler_stats( 'completed', $result );
                } else {
                    $this->log_scheduler_event( 'error', 
                        "Geplanter Import fehlgeschlagen: " . $result['message'] 
                    );
                    $this->update_scheduler_stats( 'failed', $result );
                }
            } else {
                throw new Exception( 'Import-Funktion nicht verfügbar' );
            }
            
        } catch ( Exception $e ) {
            $this->log_scheduler_event( 'error', 
                'Exception im geplanten Import: ' . $e->getMessage() 
            );
            $this->update_scheduler_stats( 'error', [ 'message' => $e->getMessage() ] );
        }
    }
    
    /**
     * Tägliche Bereinigung
     */
    public function daily_cleanup() {
        $this->log_scheduler_event( 'debug', 'Tägliche Scheduler-Bereinigung gestartet' );
        
        // Verwaiste Scheduler-Optionen bereinigen
        $this->cleanup_orphaned_options();
        
        // Scheduler-Statistiken bereinigen (älter als 30 Tage)
        $this->cleanup_old_stats();
        
        $this->log_scheduler_event( 'debug', 'Tägliche Scheduler-Bereinigung abgeschlossen' );
    }
    
    /**
     * Wöchentliche Wartung
     */
    public function weekly_maintenance() {
        $this->log_scheduler_event( 'debug', 'Wöchentliche Scheduler-Wartung gestartet' );
        
        // Scheduler-Health-Check
        $this->perform_health_check();
        
        // Statistiken optimieren
        $this->optimize_stats();
        
        $this->log_scheduler_event( 'debug', 'Wöchentliche Scheduler-Wartung abgeschlossen' );
    }
    
    // ===================================================================
    // HILFSMETHODEN
    // ===================================================================
    
    /**
     * Scheduler-spezifisches Logging
     */
    private function log_scheduler_event( $level, $message, $context = [] ) {
        if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
            CSV_Import_Error_Handler::handle( $level, '[Scheduler] ' . $message, $context );
        } else {
            error_log( "CSV Import Scheduler [{$level}]: {$message}" );
        }
    }
    
    /**
     * Aktualisiert Scheduler-Statistiken
     */
    private function update_scheduler_stats( $event_type, $data = [] ) {
        $stats = get_option( 'csv_import_scheduler_stats', [] );
        
        $today = current_time( 'Y-m-d' );
        if ( ! isset( $stats[ $today ] ) ) {
            $stats[ $today ] = [
                'started' => 0,
                'completed' => 0,
                'failed' => 0,
                'error' => 0,
                'processed_total' => 0
            ];
        }
        
        $stats[ $today ][ $event_type ]++;
        
        if ( $event_type === 'completed' && isset( $data['processed'] ) ) {
            $stats[ $today ]['processed_total'] += (int) $data['processed'];
        }
        
        // Nur die letzten 30 Tage behalten
        $cutoff_date = date( 'Y-m-d', strtotime( '-30 days' ) );
        foreach ( $stats as $date => $stat ) {
            if ( $date < $cutoff_date ) {
                unset( $stats[ $date ] );
            }
        }
        
        update_option( 'csv_import_scheduler_stats', $stats );
    }
    
    /**
     * Bereinigt verwaiste Scheduler-Optionen
     */
    private function cleanup_orphaned_options() {
        global $wpdb;
        
        // Suche nach veralteten Scheduler-Optionen
        $orphaned_options = $wpdb->get_results(
            "SELECT option_name FROM {$wpdb->options} 
             WHERE option_name LIKE 'csv_import_scheduled_%_old' 
             OR option_name LIKE '%_csv_import_temp_%'"
        );
        
        foreach ( $orphaned_options as $option ) {
            delete_option( $option->option_name );
        }
    }
    
    /**
     * Bereinigt alte Statistiken
     */
    private function cleanup_old_stats() {
        $stats = get_option( 'csv_import_scheduler_stats', [] );
        $cutoff_date = date( 'Y-m-d', strtotime( '-30 days' ) );
        $cleaned = false;
        
        foreach ( $stats as $date => $stat ) {
            if ( $date < $cutoff_date ) {
                unset( $stats[ $date ] );
                $cleaned = true;
            }
        }
        
        if ( $cleaned ) {
            update_option( 'csv_import_scheduler_stats', $stats );
        }
    }
    
    /**
     * Führt Scheduler-Health-Check durch
     */
    private function perform_health_check() {
        $issues = [];
        
        // Prüfen ob geplante Events korrekt registriert sind
        if ( self::is_scheduled() ) {
            $next_run = self::get_next_scheduled();
            if ( $next_run < time() - 3600 ) { // Mehr als 1 Stunde überfällig
                $issues[] = 'Geplanter Import ist überfällig';
            }
        }
        
        // Prüfen ob WordPress Cron funktioniert
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            $issues[] = 'WordPress Cron ist deaktiviert - externe Cron-Jobs erforderlich';
        }
        
        // Health-Check-Ergebnisse loggen
        if ( ! empty( $issues ) ) {
            $this->log_scheduler_event( 'warning', 
                'Scheduler Health-Check Probleme gefunden', 
                [ 'issues' => $issues ] 
            );
        } else {
            $this->log_scheduler_event( 'debug', 'Scheduler Health-Check erfolgreich' );
        }
    }
    
    /**
     * Optimiert Scheduler-Statistiken
     */
    private function optimize_stats() {
        $stats = get_option( 'csv_import_scheduler_stats', [] );
        
        if ( count( $stats ) > 60 ) { // Mehr als 60 Tage
            $stats = array_slice( $stats, -30, null, true ); // Nur die letzten 30 behalten
            update_option( 'csv_import_scheduler_stats', $stats );
        }
    }
    
    // ===================================================================
    // ÖFFENTLICHE METHODEN FÜR ADMIN-INTERFACE
    // ===================================================================
    
    /**
     * Holt Scheduler-Informationen für das Admin-Interface
     */
    public static function get_scheduler_info() {
        return [
            'is_scheduled' => self::is_scheduled(),
            'next_run' => self::get_next_scheduled(),
            'frequency' => get_option( 'csv_import_scheduled_frequency', '' ),
            'source' => get_option( 'csv_import_scheduled_source', '' ),
            'available_intervals' => self::INTERVALS,
            'stats' => get_option( 'csv_import_scheduler_stats', [] ),
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
        ];
    }
    
    /**
     * Testet den Scheduler
     */
    public static function test_scheduler() {
        // Test-Event in 1 Minute planen
        $test_time = time() + 60;
        $test_hook = 'csv_import_scheduler_test';
        
        // Test-Handler registrieren
        add_action( $test_hook, function() {
            update_option( 'csv_import_scheduler_test_result', [
                'success' => true,
                'timestamp' => current_time( 'mysql' )
            ] );
        });
        
        // Test-Event planen
        $scheduled = wp_schedule_single_event( $test_time, $test_hook );
        
        return [
            'scheduled' => $scheduled !== false,
            'test_time' => $test_time,
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
            'next_cron_run' => wp_next_scheduled( 'wp_version_check' ) // Standard WP Cron als Referenz
        ];
    }
}

// Auto-Initialisierung wenn die Klasse geladen wird
add_action( 'plugins_loaded', [ 'CSV_Import_Scheduler', 'init' ], 15 );
