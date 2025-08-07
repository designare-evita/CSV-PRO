<?php
/**
 * Verarbeitet alle AJAX-Anfragen aus dem Admin-Bereich des CSV Import Pro Plugins.
 * Version 5.4 - Final Fix
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
        'csv_import_cancel',
        'csv_import_get_profile_details',
        'csv_import_system_check',
        'csv_debug_info'
    ];

    foreach($ajax_actions as $action) {
        add_action('wp_ajax_' . $action, $action . '_handler');
    }
}
add_action( 'plugins_loaded', 'csv_import_register_ajax_hooks' );

/**
 * Handler für die Validierung von Konfiguration und CSV-Dateien.
 */
function csv_import_validate_handler() {
    // KORREKTUR 1: Der zweite Parameter 'nonce' wurde hinzugefügt, um dem JavaScript zu entsprechen.
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }

    $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';
    $response_data = [ 'valid' => false, 'message' => 'Unbekannter Test-Typ.' ];

    try {
        $config = csv_import_get_config();
        
        if ( $type === 'config' ) {
            $validation = csv_import_validate_config( $config );
            $response_data = array_merge($response_data, $validation);
            if (!$validation['valid']) {
                $response_data['message'] = 'Konfigurationsfehler: <ul><li>' . implode('</li><li>', $validation['errors']) . '</li></ul>';
            } else {
                 $response_data['message'] = '✅ Konfiguration ist gültig.';
            }

        } elseif ( in_array( $type, [ 'dropbox', 'local' ] ) ) {
            $csv_result = csv_import_validate_csv_source( $type, $config );
            $response_data = array_merge( $response_data, $csv_result );
        }

    } catch ( Exception $e ) {
        $response_data['message'] = 'Validierungsfehler: ' . $e->getMessage();
    }

    if ( $response_data['valid'] ) {
        wp_send_json_success( $response_data );
    } else {
        wp_send_json_error( $response_data );
    }
}

/**
 * Handler zum Starten des Imports.
 */
function csv_import_start_handler() {
    // KORREKTUR 1: Der zweite Parameter 'nonce' wurde hinzugefügt.
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }

    $source = isset( $_POST['source'] ) ? sanitize_key( $_POST['source'] ) : '';
    if ( ! in_array( $source, ['dropbox', 'local'] ) ) {
        wp_send_json_error( [ 'message' => 'Ungültige Import-Quelle.' ] );
    }

    if ( csv_import_is_import_running() ) {
        wp_send_json_error( [ 'message' => 'Ein Import läuft bereits.' ] );
    }
    
    // KORREKTUR 2: Der Import wird jetzt direkt ausgeführt statt als Hintergrund-Task geplant.
    // Dies ist zuverlässiger und behebt das Problem, dass der Import nicht startet.
    if ( class_exists( 'CSV_Import_Pro_Run' ) ) {
        $result = CSV_Import_Pro_Run::run( $source );
        if ( $result['success'] ) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    } else {
        wp_send_json_error(['message' => 'Kritischer Fehler: Import-Klasse nicht gefunden.']);
    }
}

/**
 * Handler zum Abrufen des Import-Fortschritts.
 */
function csv_import_get_progress_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }

    $progress = csv_import_get_progress();
    wp_send_json_success( $progress );
}

/**
 * Handler zum Abbrechen eines laufenden Imports.
 */
function csv_import_cancel_handler() {
    check_ajax_referer( 'csv_import_ajax', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( ['message' => 'Keine Berechtigung.'] );
    }

    csv_import_force_reset_import_status();
    wp_send_json_success( ['message' => 'Import abgebrochen und zurückgesetzt.'] );
}

// Hier können bei Bedarf weitere AJAX-Handler hinzugefügt werden.
