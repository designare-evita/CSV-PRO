<?php
/**
 * Verarbeitet alle AJAX-Anfragen aus dem Admin-Bereich des CSV Import Pro Plugins.
 * Version 6.1 - Finaler Stabilitäts- und Funktions-Fix
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registriert alle AJAX-Aktionen des Plugins.
 */
function csv_import_register_ajax_hooks() {
    $ajax_actions = [
        'csv_import_validate',
        'csv_import_start',
        'csv_import_get_progress',
        'csv_import_cancel'
    ];

    foreach($ajax_actions as $action) {
        add_action('wp_ajax_' . $action, $action . '_handler');
    }
}
// Haken wird direkt ausgeführt, da diese Datei bei Bedarf geladen wird.
csv_import_register_ajax_hooks();

/**
 * Handler für die Validierung von Konfiguration und CSV-Dateien.
 */
function csv_import_validate_handler() {
    // Sicherheitsprüfung
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }

    $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
    $response_data = [ 'valid' => false, 'message' => 'Unbekannter Test-Typ.' ];

    try {
        // Sicherstellen, dass die Funktionen verfügbar sind
        if (!function_exists('csv_import_get_config')) {
             throw new Exception('Kernfunktionen des Plugins sind nicht geladen.');
        }

        $config = csv_import_get_config();
        
        if ( $type === 'config' ) {
            $validation = csv_import_validate_config( $config );
            $response_data = array_merge($response_data, $validation);
            if (!$validation['valid']) {
                $response_data['message'] = 'Konfigurationsfehler: <ul><li>' . implode('</li><li>', $validation['errors']) . '</li></ul>';
            } else {
                 $response_data['message'] = '✅ Konfiguration ist gültig und alle Systemanforderungen sind erfüllt.';
            }

        } elseif ( in_array( $type, [ 'dropbox', 'local' ] ) ) {
            $csv_result = csv_import_validate_csv_source( $type, $config );
            $response_data = array_merge( $response_data, $csv_result );
        }

    } catch ( Exception $e ) {
        $response_data['message'] = 'Validierungsfehler: ' . $e->getMessage();
    }

    if ( !empty($response_data['valid']) && $response_data['valid'] ) {
        wp_send_json_success( $response_data );
    } else {
        wp_send_json_error( $response_data );
    }
}

/**
 * Handler zum Starten des Imports.
 */
function csv_import_start_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }

    $source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
    if ( ! in_array( $source, ['dropbox', 'local'] ) ) {
        wp_send_json_error( [ 'message' => 'Ungültige Import-Quelle.' ] );
    }

    if ( function_exists('csv_import_is_import_running') && csv_import_is_import_running() ) {
        wp_send_json_error( [ 'message' => 'Ein Import läuft bereits.' ] );
    }
    
    if ( class_exists( 'CSV_Import_Pro_Run' ) ) {
        $result = CSV_Import_Pro_Run::run( $source );
        if ( !empty($result['success']) ) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    } else {
        wp_send_json_error(['message' => 'Kritischer Fehler: Import-Klasse (CSV_Import_Pro_Run) nicht gefunden.']);
    }
}

/**
 * Handler zum Abrufen des Import-Fortschritts.
 */
function csv_import_get_progress_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }

    if(function_exists('csv_import_get_progress')){
        $progress = csv_import_get_progress();
        wp_send_json_success( $progress );
    } else {
        wp_send_json_error(['message' => 'Fortschritts-Funktion nicht verfügbar.']);
    }
}

/**
 * Handler zum Abbrechen eines laufenden Imports.
 */
function csv_import_cancel_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'edit_pages' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }
    if(function_exists('csv_import_force_reset_import_status')){
        csv_import_force_reset_import_status();
        wp_send_json_success( ['message' => 'Import abgebrochen und zurückgesetzt.'] );
    } else {
         wp_send_json_error( ['message' => 'Reset-Funktion nicht verfügbar.'] );
    }
}
