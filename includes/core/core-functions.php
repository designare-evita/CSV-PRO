<?php
/**
 * Core-Funktionen für das CSV Import Pro Plugin
 * * Version: 5.3 - Redundante Memory-Funktion entfernt, um Konflikt zu beheben.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Direkten Zugriff verhindern
}

// ===================================================================
// VORBEUGENDE MAßNAHMEN & SCHUTZ VOR HÄNGENDEN IMPORTS
// ===================================================================

if (!function_exists('csv_import_check_stuck_imports')) {
    function csv_import_check_stuck_imports() {
        // ... (Inhalt der Funktion bleibt unverändert)
    }
}

if (!function_exists('csv_import_force_reset_import_status')) {
    function csv_import_force_reset_import_status() {
        // ... (Inhalt der Funktion bleibt unverändert)
    }
}

// ... (Alle anderen Funktionen aus der Original-Datei bleiben hier unverändert) ...


// ===================================================================
// IMPORT HAUPT-FUNKTIONEN MIT SICHERHEITSCHECKS
// ===================================================================

/**
 * Startet den CSV-Import (Hauptfunktion).
 * Diese Funktion ruft nun die optimierte Version auf, die in
 * class-csv-memory-optimizer.php definiert ist.
 */
if (!function_exists('csv_import_start_import')) {
    function csv_import_start_import(string $source, array $config = null): array {
        if (function_exists('csv_import_start_import_with_memory_optimization')) {
            return csv_import_start_import_with_memory_optimization($source, $config);
        } else {
            // Fallback, falls die Optimizer-Klasse nicht geladen ist
            $error_msg = 'Kritischer Fehler: Die Memory-Optimizer-Funktion wurde nicht gefunden.';
            csv_import_log('critical', $error_msg);
            return [
                'success' => false,
                'message' => $error_msg,
                'errors' => 1
            ];
        }
    }
}


// ... (Alle weiteren Funktionen aus der Original-Datei bleiben hier unverändert) ...

/**
 * Gibt den aktuellen Speicherstatus zurück.
 */
if (!function_exists('csv_import_get_memory_status')) {
    function csv_import_get_memory_status() {
        $current_usage = memory_get_usage(true);
        $memory_limit_str = ini_get('memory_limit');
        $memory_limit = csv_import_convert_to_bytes($memory_limit_str);

        $usage_percent = 0;
        if ($memory_limit > 0 && $memory_limit != PHP_INT_MAX) {
            $usage_percent = round(($current_usage / $memory_limit) * 100, 1);
        }

        return [
            'current'           => $current_usage,
            'limit'             => $memory_limit,
            'current_formatted' => size_format($current_usage),
            'limit_formatted'   => $memory_limit_str,
            'usage_percent'     => $usage_percent,
        ];
    }
}

csv_import_log( 'debug', 'CSV Import Pro Core Functions geladen - Version 5.3' );
