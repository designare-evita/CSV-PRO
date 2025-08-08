<?php
/**
 * Memory-Optimierte CSV-Verarbeitung für CSV Import Pro
 * Ersetzt die speicher-intensive Verarbeitung durch Streaming
 * * NEUE FEATURES:
 * - Streaming CSV-Reader (verarbeitet nur eine Zeile zur Zeit)
 * - Adaptive Batch-Größen basierend auf verfügbarem Speicher
 * - Intelligente Memory-Überwachung mit automatischer Anpassung
 * - Resume-Funktionalität für unterbrochene Imports
 * - Garbage Collection Optimierung
 */

if (!defined('ABSPATH')) {
    exit;
}

// ===================================================================
// STREAMING CSV READER - Kernstück der Memory-Optimierung
// ===================================================================

class CSV_Stream_Reader {
    private $file_handle;
    private $current_line = 0;
    private $headers = [];
    private $file_size = 0;
    private $bytes_read = 0;
    
    public function __construct($file_path_or_url) {
        $this->file_size = $this->get_file_size($file_path_or_url);
        $this->file_handle = $this->open_stream($file_path_or_url);
        
        if (!$this->file_handle) {
            throw new Exception('Konnte CSV-Stream nicht öffnen: ' . $file_path_or_url);
        }
        
        // Header-Zeile lesen
        $this->headers = $this->read_next_row();
        if (empty($this->headers)) {
            throw new Exception('CSV enthält keine gültigen Header');
        }
        
        csv_import_log('debug', 'CSV-Stream geöffnet', [
            'file_size' => size_format($this->file_size),
            'headers_count' => count($this->headers),
            'memory_usage' => size_format(memory_get_usage(true))
        ]);
    }
    
    private function get_file_size($source) {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            // Remote-Datei: HEAD-Request für Content-Length
            $headers = get_headers($source, true);
            return isset($headers['Content-Length']) ? (int)$headers['Content-Length'] : 0;
        } else {
            // Lokale Datei
            return file_exists($source) ? filesize($source) : 0;
        }
    }
    
    private function open_stream($source) {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            // Remote-Stream mit Context für bessere Kontrolle
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 60,
                    'user_agent' => 'CSV Import Pro/' . (defined('CSV_IMPORT_PRO_VERSION') ? CSV_IMPORT_PRO_VERSION : '1.0'),
                    'follow_location' => true,
                    'max_redirects' => 3
                ]
            ]);
            return @fopen($source, 'r', false, $context);
        } else {
            // Lokaler Stream
            return @fopen($source, 'r');
        }
    }
    
    public function read_next_row() {
        if (!$this->file_handle || feof($this->file_handle)) {
            return null;
        }
        
        $row = fgetcsv($this->file_handle);
        if ($row !== false) {
            $this->current_line++;
            $this->bytes_read = ftell($this->file_handle);
            
            // Assoziatives Array mit Headern erstellen
            if ($this->current_line > 1 && !empty($this->headers)) {
                $row_data = [];
                foreach ($this->headers as $index => $header) {
                    $row_data[trim($header)] = isset($row[$index]) ? trim($row[$index]) : '';
                }
                return $row_data;
            }
        }
        
        return $row;
    }
    
    public function get_progress_info() {
        $percent = $this->file_size > 0 ? round(($this->bytes_read / $this->file_size) * 100, 1) : 0;
        
        return [
            'current_line' => $this->current_line,
            'bytes_read' => $this->bytes_read,
            'file_size' => $this->file_size,
            'percent' => $percent,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
    
    public function get_headers() {
        return $this->headers;
    }
    
    public function close() {
        if ($this->file_handle) {
            fclose($this->file_handle);
            $this->file_handle = null;
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}

// ===================================================================
// ADAPTIVE BATCH PROCESSOR - Intelligente Batch-Größen
// ===================================================================

class CSV_Adaptive_Batch_Processor {
    private $base_batch_size = 10;
    private $max_batch_size = 100;
    private $min_batch_size = 5;
    private $current_batch_size;
    private $memory_threshold = 0.8; // 80% des verfügbaren Speichers
    private $performance_metrics = [];
    
    public function __construct() {
        $this->current_batch_size = $this->base_batch_size;
        $this->calculate_optimal_batch_size();
    }
    
    private function calculate_optimal_batch_size() {
        $memory_limit = csv_import_convert_to_bytes(ini_get('memory_limit'));
        $available_memory = $memory_limit - memory_get_usage(true);
        
        // Schätze Memory pro Post (basierend auf durchschnittlicher Post-Größe)
        $estimated_memory_per_post = 2 * 1024 * 1024; // 2MB pro Post (konservativ)
        $safe_batch_size = floor(($available_memory * $this->memory_threshold) / $estimated_memory_per_post);
        
        $this->current_batch_size = max(
            $this->min_batch_size,
            min($this->max_batch_size, $safe_batch_size)
        );
        
        csv_import_log('debug', 'Adaptive Batch-Größe berechnet', [
            'memory_limit' => size_format($memory_limit),
            'available_memory' => size_format($available_memory),
            'batch_size' => $this->current_batch_size
        ]);
    }
    
    public function process_batch(CSV_Stream_Reader $reader, $config, $session_id) {
        $batch_start_time = microtime(true);
        $batch_start_memory = memory_get_usage(true);
        
        $batch_data = [];
        $processed = 0;
        $errors = [];
        
        // Batch einlesen
        for ($i = 0; $i < $this->current_batch_size; $i++) {
            $row = $reader->read_next_row();
            if ($row === null) {
                break; // Ende der Datei erreicht
            }
            $batch_data[] = $row;
        }
        
        if (empty($batch_data)) {
            return ['processed' => 0, 'errors' => [], 'finished' => true];
        }
        
        // Batch verarbeiten
        foreach ($batch_data as $index => $row) {
            try {
                $post_id = $this->create_post_memory_efficient($row, $config, $session_id);
                if ($post_id) {
                    $processed++;
                    
                    // Memory-Check nach jedem Post
                    if ($this->is_memory_critical()) {
                        csv_import_log('warning', 'Kritischer Speicherstatus - Batch vorzeitig beendet', [
                            'memory_usage' => size_format(memory_get_usage(true)),
                            'processed_in_batch' => $processed
                        ]);
                        break;
                    }
                }
            } catch (Exception $e) {
                $errors[] = [
                    'line' => $reader->get_progress_info()['current_line'] - count($batch_data) + $index + 1,
                    'message' => $e->getMessage(),
                    'data' => array_slice($row, 0, 3) // Nur erste 3 Felder für Debug
                ];
                
                // Bei zu vielen Fehlern in einem Batch abbrechen
                if (count($errors) > ($this->current_batch_size * 0.5)) {
                    csv_import_log('error', 'Zu viele Fehler in Batch - Import abgebrochen');
                    break;
                }
            }
        }
        
        // Performance-Metriken sammeln
        $batch_time = microtime(true) - $batch_start_time;
        $memory_used = memory_get_usage(true) - $batch_start_memory;
        
        $this->record_batch_metrics($batch_time, $memory_used, count($batch_data));
        $this->adjust_batch_size_based_on_performance();
        
        // Explizite Garbage Collection nach jedem Batch
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        return [
            'processed' => $processed,
            'errors' => $errors,
            'finished' => empty($batch_data) || count($batch_data) < $this->current_batch_size,
            'metrics' => [
                'batch_time' => round($batch_time, 2),
                'memory_used' => size_format($memory_used),
                'posts_per_second' => $batch_time > 0 ? round($processed / $batch_time, 2) : 0
            ]
        ];
    }
    
    private function create_post_memory_efficient($row, $config, $session_id) {
        // Vereinfachte Post-Erstellung mit minimaler Memory-Footprint
        $post_title = $this->get_field_value($row, ['post_title', 'title']);
        if (empty($post_title)) {
            throw new Exception('Post-Titel erforderlich');
        }
        
        // Duplikat-Check nur wenn nötig (memory-schonend)
        if (!empty($config['skip_duplicates'])) {
            if ($this->post_exists($post_title, $config['post_type'])) {
                throw new Exception('Post bereits vorhanden: ' . $post_title);
            }
        }
        
        // Minimale Post-Daten
        $post_data = [
            'post_title' => wp_strip_all_tags($post_title),
            'post_content' => $this->get_field_value($row, ['post_content', 'content'], ''),
            'post_excerpt' => $this->get_field_value($row, ['post_excerpt', 'excerpt'], ''),
            'post_name' => $this->generate_slug($post_title),
            'post_status' => $config['post_status'],
            'post_type' => $config['post_type'],
            'meta_input' => [
                '_csv_import_session' => $session_id,
                '_csv_import_date' => current_time('mysql')
            ]
        ];
        
        // Template anwenden (memory-effizient)
        if (!empty($config['template_id']) && $config['page_builder'] !== 'none') {
            $post_data['post_content'] = $this->apply_template_efficient($config['template_id'], $row);
        }
        
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            throw new Exception('WordPress-Fehler: ' . $post_id->get_error_message());
        }
        
        // Meta-Felder hinzufügen (batch-weise für bessere Performance)
        $this->add_meta_fields_efficient($post_id, $row);
        
        // Sofortiges Memory-Cleanup nach jedem Post
        unset($row, $post_data);
        
        return $post_id;
    }
    
    private function get_field_value($row, $possible_fields, $default = null) {
        foreach ($possible_fields as $field) {
            if (isset($row[$field]) && !empty(trim($row[$field]))) {
                return trim($row[$field]);
            }
        }
        return $default;
    }
    
    private function post_exists($title, $post_type) {
        global $wpdb;
        
        // Memory-effiziente Existenz-Prüfung
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_title = %s AND post_type = %s 
             LIMIT 1",
            $title, $post_type
        ));
        
        return !empty($exists);
    }
    
    private function generate_slug($title) {
        static $used_slugs = []; // Cache für verwendete Slugs
        
        $slug = sanitize_title($title);
        $original_slug = $slug;
        $counter = 1;
        
        // Erst im lokalen Cache prüfen, dann in DB
        while (isset($used_slugs[$slug]) || $this->slug_exists_in_db($slug)) {
            $slug = $original_slug . '-' . $counter;
            $counter++;
        }
        
        $used_slugs[$slug] = true;
        
        // Cache begrenzen um Memory zu sparen
        if (count($used_slugs) > 1000) {
            $used_slugs = array_slice($used_slugs, -500, null, true);
        }
        
        return $slug;
    }
    
    private function slug_exists_in_db($slug) {
        global $wpdb;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s LIMIT 1",
            $slug
        ));
        
        return !empty($exists);
    }
    
    private function apply_template_efficient($template_id, $row) {
        static $template_cache = null;
        
        // Template nur einmal laden und cachen
        if ($template_cache === null) {
            $template_post = get_post($template_id);
            if (!$template_post) {
                throw new Exception("Template ID {$template_id} nicht gefunden");
            }
            $template_cache = $template_post->post_content;
        }
        
        $content = $template_cache;
        
        // Nur die wichtigsten Platzhalter ersetzen (memory-schonend)
        $replacements = [
            '{{title}}' => $this->get_field_value($row, ['post_title', 'title']),
            '{{content}}' => $this->get_field_value($row, ['post_content', 'content']),
            '{{excerpt}}' => $this->get_field_value($row, ['post_excerpt', 'excerpt'])
        ];
        
        foreach ($replacements as $placeholder => $value) {
            if ($value !== null) {
                $content = str_replace($placeholder, $value, $content);
            }
        }
        
        return $content;
    }
    
    private function add_meta_fields_efficient($post_id, $row) {
        $skip_fields = ['post_title', 'title', 'post_content', 'content', 'post_excerpt', 'excerpt', 'post_name'];
        
        foreach ($row as $key => $value) {
            if (!in_array($key, $skip_fields) && !empty($value)) {
                $meta_key = '_' . sanitize_key($key);
                update_post_meta($post_id, $meta_key, sanitize_text_field($value));
            }
        }
    }
    
    private function is_memory_critical() {
        $memory_limit = csv_import_convert_to_bytes(ini_get('memory_limit'));
        if ($memory_limit <= 0) return false; // Kein Limit
        $current_usage = memory_get_usage(true);
        
        return ($current_usage / $memory_limit) > 0.85; // 85% Threshold
    }
    
    private function record_batch_metrics($time, $memory, $rows) {
        if ($rows == 0) return;
        $this->performance_metrics[] = [
            'time' => $time,
            'memory' => $memory,
            'rows' => $rows,
            'timestamp' => microtime(true)
        ];
        
        // Nur letzte 20 Metriken behalten
        if (count($this->performance_metrics) > 20) {
            $this->performance_metrics = array_slice($this->performance_metrics, -20);
        }
    }
    
    private function adjust_batch_size_based_on_performance() {
        if (count($this->performance_metrics) < 3) {
            return; // Nicht genug Daten
        }
        
        $recent_metrics = array_slice($this->performance_metrics, -3);
        $avg_time = array_sum(array_column($recent_metrics, 'time')) / count($recent_metrics);
        $avg_memory = array_sum(array_column($recent_metrics, 'memory')) / count($recent_metrics);
        
        // Batch-Größe anpassen basierend auf Performance
        if ($avg_time > 10 || $this->is_memory_critical()) {
            // Zu langsam oder zu viel Memory - Batch verkleinern
            $this->current_batch_size = (int) max($this->min_batch_size, floor($this->current_batch_size * 0.8));
        } elseif ($avg_time < 2 && !$this->is_memory_critical()) {
            // Schnell und wenig Memory - Batch vergrößern
            $this->current_batch_size = (int) min($this->max_batch_size, ceil($this->current_batch_size * 1.2));
        }
        
        csv_import_log('debug', 'Batch-Größe angepasst', [
            'new_size' => $this->current_batch_size,
            'avg_time' => round($avg_time, 2),
            'avg_memory' => size_format($avg_memory),
            'memory_critical' => $this->is_memory_critical()
        ]);
    }
    
    public function get_current_batch_size() {
        return $this->current_batch_size;
    }
}

// ===================================================================
// RESUMABLE IMPORT SYSTEM - Unterbrochene Imports fortsetzen
// ===================================================================

class CSV_Resumable_Import {
    private $session_id;
    
    public function __construct($session_id) {
        $this->session_id = $session_id;
    }
    
    public function save_checkpoint($processed_lines, $total_lines, $last_processed_data) {
        $checkpoint = [
            'session_id' => $this->session_id,
            'processed_lines' => $processed_lines,
            'total_lines' => $total_lines,
            'timestamp' => current_time('mysql'),
            'memory_usage' => memory_get_usage(true),
            'last_data_sample' => array_slice((array)$last_processed_data, 0, 3), // Nur Sample
            'server_info' => [
                'php_memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'server_load' => function_exists('sys_getloadavg') ? (sys_getloadavg()[0] ?? 'unknown') : 'unknown'
            ]
        ];
        
        update_option('csv_import_checkpoint_' . $this->session_id, $checkpoint);
        
        csv_import_log('debug', 'Import-Checkpoint gespeichert', [
            'session' => $this->session_id,
            'progress' => $total_lines > 0 ? (round(($processed_lines / $total_lines) * 100, 1) . '%') : 'N/A'
        ]);
    }
    
    public function get_checkpoint() {
        return get_option('csv_import_checkpoint_' . $this->session_id, null);
    }
    
    public function can_resume() {
        $checkpoint = $this->get_checkpoint();
        return !empty($checkpoint) && isset($checkpoint['processed_lines']);
    }
    
    public function resume_from_checkpoint(CSV_Stream_Reader $reader) {
        $checkpoint = $this->get_checkpoint();
        if (!$checkpoint) {
            return false;
        }
        
        // Stream zu der Position vorspulen wo wir aufgehört haben
        $target_line = $checkpoint['processed_lines'];
        
        // Lese Zeilen bis zum Ziel
        for ($i = 0; $i < $target_line; $i++) {
            if ($reader->read_next_row() === null) {
                break; // Dateiende erreicht
            }
        }
        
        csv_import_log('info', 'Import von Checkpoint fortgesetzt', [
            'session' => $this->session_id,
            'resume_line' => $target_line,
            'memory_usage' => size_format(memory_get_usage(true))
        ]);
        
        return true;
    }
    
    public function cleanup_checkpoint() {
        delete_option('csv_import_checkpoint_' . $this->session_id);
    }
}

// ===================================================================
// MEMORY MONITOR - Überwacht Speicherverbrauch in Echtzeit
// ===================================================================

class CSV_Memory_Monitor {
    private $peak_memory = 0;
    private $warnings_sent = 0;
    private $max_warnings = 3;
    
    public function __construct() {
        $this->peak_memory = memory_get_usage(true);
    }
    
    public function check_memory_status() {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = csv_import_convert_to_bytes(ini_get('memory_limit'));
        
        $this->peak_memory = max($this->peak_memory, $peak);
        
        $usage_percent = ($limit > 0) ? (($current / $limit) * 100) : 0;
        
        $status = [
            'current' => $current,
            'peak' => $this->peak_memory,
            'limit' => $limit,
            'usage_percent' => round($usage_percent, 1),
            'status' => $this->get_memory_status_level($usage_percent),
            'available' => $limit > 0 ? $limit - $current : -1 // Unendlich
        ];
        
        // Warnungen bei kritischem Speicherverbrauch
        if ($usage_percent > 85 && $this->warnings_sent < $this->max_warnings) {
            csv_import_log('warning', 'Kritischer Speicherverbrauch', [
                'usage_percent' => $usage_percent,
                'current_memory' => size_format($current),
                'memory_limit' => size_format($limit)
            ]);
            $this->warnings_sent++;
        }
        
        return $status;
    }
    
    private function get_memory_status_level($usage_percent) {
        if ($usage_percent < 50) return 'good';
        if ($usage_percent < 70) return 'ok';
        if ($usage_percent < 85) return 'warning';
        return 'critical';
    }
    
    public function suggest_optimization() {
        $status = $this->check_memory_status();
        $suggestions = [];
        
        if ($status['status'] === 'critical') {
            $suggestions[] = 'Reduziere Batch-Größe auf 5-10 Posts';
            $suggestions[] = 'Erhöhe PHP Memory Limit';
        } elseif ($status['status'] === 'warning') {
            $suggestions[] = 'Überwache Memory-Verbrauch genauer';
            $suggestions[] = 'Reduziere gleichzeitige Prozesse';
        }
        
        return $suggestions;
    }
}

// ===================================================================
// OPTIMIERTE HAUPT-IMPORT-FUNKTION
// ===================================================================

/**
 * Memory-optimierte Import-Funktion (Ersetzt csv_import_start_import)
 */
function csv_import_start_import_optimized(string $source, array $config = null): array {
    // Memory-Monitor starten
    $memory_monitor = new CSV_Memory_Monitor();
    
    try {
        // Basis-Validierung
        if (function_exists('csv_import_is_import_running') && csv_import_is_import_running()) {
            return [
                'success' => false,
                'message' => 'Ein Import läuft bereits'
            ];
        }
        
        if ($config === null) {
            $config = csv_import_get_config();
        }
        
        // Session-ID und Resumable Import
        $session_id = 'opt_' . time() . '_' . uniqid();
        $resumable = new CSV_Resumable_Import($session_id);
        
        csv_import_set_import_lock();
        csv_import_log('info', "Memory-optimierter Import gestartet", [
            'source' => $source,
            'session' => $session_id,
            'initial_memory' => size_format(memory_get_usage(true))
        ]);
        
        // CSV-Quelle vorbereiten
        $csv_source = csv_import_prepare_csv_source($source, $config);
        
        // Streaming Reader initialisieren
        $reader = new CSV_Stream_Reader($csv_source);
        $processor = new CSV_Adaptive_Batch_Processor();
        
        // Geschätzten Total ermitteln (für Progress)
        $estimated_total = csv_import_estimate_total_rows($csv_source);
        csv_import_update_progress(0, $estimated_total, 'starting');
        
        // Header validieren
        $headers = $reader->get_headers();
        $required_columns = is_array($config['required_columns']) ? $config['required_columns'] : array_filter(array_map('trim', explode("\n", $config['required_columns'])));
        $column_validation = csv_import_validate_required_columns($headers, $required_columns);
        if (!$column_validation['valid']) {
            throw new Exception('Erforderliche Spalten fehlen: ' . implode(', ', $column_validation['missing']));
        }
        
        // Haupt-Verarbeitungsschleife
        $total_processed = 0;
        $total_errors = 0;
        $all_error_messages = [];
        $processing_start = microtime(true);
        
        while (true) {
            // Memory-Status prüfen
            $memory_status = $memory_monitor->check_memory_status();
            
            if ($memory_status['status'] === 'critical') {
                // Emergency Memory Cleanup
                csv_import_emergency_memory_cleanup();
                
                if ($memory_monitor->check_memory_status()['status'] === 'critical') {
                    throw new Exception('Kritischer Speichermangel - Import kann nicht fortgesetzt werden');
                }
            }
            
            // Batch verarbeiten
            $batch_result = $processor->process_batch($reader, $config, $session_id);
            
            $total_processed += $batch_result['processed'];
            $total_errors += count($batch_result['errors']);
            $all_error_messages = array_merge($all_error_messages, $batch_result['errors']);
            
            // Progress aktualisieren
            csv_import_update_progress($total_processed, $estimated_total, 'processing');
            
            // Checkpoint speichern
            if ($total_processed > 0 && $total_processed % 50 === 0) {
                $resumable->save_checkpoint($total_processed, $estimated_total, $reader->get_progress_info());
            }
            
            // Batch beendet?
            if ($batch_result['finished']) {
                break;
            }
            
            // Zu viele Fehler?
            if ($total_errors > 100) {
                csv_import_log('error', 'Import abgebrochen - zu viele Fehler', [
                    'total_errors' => $total_errors,
                    'processed' => $total_processed
                ]);
                break;
            }
            
            // Kurze Pause zwischen Batches
            usleep(100000); // 0.1 Sekunde
        }
        
        // Import abschließen
        $reader->close();
        $resumable->cleanup_checkpoint();
        csv_import_remove_import_lock();
        
        $total_time = microtime(true) - $processing_start;
        $final_memory = $memory_monitor->check_memory_status();
        
        // Ergebnis zusammenstellen
        $result = [
            'success' => $total_processed > 0,
            'processed' => $total_processed,
            'total' => $estimated_total,
            'errors' => $total_errors,
            'error_messages' => array_slice($all_error_messages, 0, 10),
            'session_id' => $session_id,
            'performance' => [
                'total_time' => round($total_time, 2),
                'posts_per_second' => $total_time > 0 ? round($total_processed / $total_time, 2) : 0,
                'peak_memory' => size_format($final_memory['peak']),
                'memory_efficiency' => $final_memory['peak'] > 0 ? round(($total_processed * 1024 * 1024) / $final_memory['peak'], 2) : 0 // Posts per MB
            ]
        ];
        
        csv_import_update_progress($total_processed, $estimated_total, 
            $total_errors > 0 ? 'completed_with_errors' : 'completed');
        
        csv_import_log('info', 'Memory-optimierter Import abgeschlossen', $result['performance']);
        
        return $result;
        
    } catch (Exception $e) {
        csv_import_remove_import_lock();
        csv_import_update_progress(0, 0, 'failed');
        
        // Memory-Status bei Fehler loggen
        $memory_status = $memory_monitor->check_memory_status();
        
        csv_import_log('error', 'Memory-optimierter Import fehlgeschlagen: ' . $e->getMessage(), [
            'memory_status' => $memory_status,
            'suggestions' => $memory_monitor->suggest_optimization()
        ]);
        
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'processed' => 0,
            'total' => 0,
            'errors' => 1,
            'memory_info' => $memory_status
        ];
    }
}

/**
 * Bereitet die CSV-Quelle vor (URL oder lokaler Pfad)
 */
function csv_import_prepare_csv_source($source, $config) {
    if ($source === 'dropbox') {
        if (empty($config['dropbox_url'])) {
            throw new Exception('Dropbox URL nicht konfiguriert');
        }
        
        // Dropbox URL zu Stream-fähiger URL konvertieren
        $url = $config['dropbox_url'];
        $url = str_replace('www.dropbox.com', 'dl.dropboxusercontent.com', $url);
        $url = str_replace('?dl=0', '', $url);
        if (strpos($url, '?') === false) {
             $url .= '?raw=1';
        }
        
        return $url;
        
    } elseif ($source === 'local') {
        if (empty($config['local_path'])) {
            throw new Exception('Lokaler Pfad nicht konfiguriert');
        }
        
        $file_path = ABSPATH . ltrim($config['local_path'], '/');
        
        if (!file_exists($file_path) || !is_readable($file_path)) {
            throw new Exception('Lokale CSV-Datei nicht gefunden oder lesbar: ' . $config['local_path']);
        }
        
        return $file_path;
        
    } else {
        throw new Exception('Unbekannte Import-Quelle: ' . $source);
    }
}

/**
 * Schätzt die Gesamtanzahl der Zeilen ohne komplette Datei zu laden
 */
function csv_import_estimate_total_rows($source) {
    if (filter_var($source, FILTER_VALIDATE_URL)) {
        // Remote-Datei: Schätzung basierend auf Content-Length
        $headers = @get_headers($source, true);
        $content_length = $headers['Content-Length'] ?? ($headers['content-length'] ?? 0);
        
        if ($content_length > 0) {
            // Durchschnittlich ~150 Bytes pro CSV-Zeile (konservative Schätzung)
            return max(1, floor($content_length / 150));
        }
        
        return 1000; // Fallback-Schätzung
        
    } else {
        // Lokale Datei: Schneller Line-Count
        try {
            $file = new \SplFileObject($source, 'r');
            $file->seek(PHP_INT_MAX);
            $line_count = $file->key() + 1;
            unset($file);
            return max(1, $line_count - 1); // -1 für Header
        } catch (Exception $e) {
            // Fallback, wenn SplFileObject fehlschlägt
            $line_count = 0;
            $handle = @fopen($source, 'r');
            if ($handle) {
                while (!feof($handle)) {
                    if (fgets($handle) !== false) {
                        $line_count++;
                    }
                }
                fclose($handle);
            }
            return max(1, $line_count - 1);
        }
    }
}

/**
 * Notfall-Memory-Bereinigung
 */
function csv_import_emergency_memory_cleanup() {
    csv_import_log('warning', 'Notfall-Memory-Bereinigung gestartet');
    
    // 1. WordPress Object Cache leeren
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // 2. Query Cache des WPdb leeren
    global $wpdb;
    if (isset($wpdb)) {
        $wpdb->flush();
    }

    // 3. Garbage Collection erzwingen
    if (function_exists('gc_collect_cycles')) {
        $collected = gc_collect_cycles();
        csv_import_log('debug', "Garbage Collection: {$collected} Objekte bereinigt");
    }
    
    $freed_memory = memory_get_usage(true);
    csv_import_log('info', 'Notfall-Memory-Bereinigung abgeschlossen', [
        'memory_after_cleanup' => size_format($freed_memory)
    ]);
}
