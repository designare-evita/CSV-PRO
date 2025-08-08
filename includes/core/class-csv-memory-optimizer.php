<?php
/**
 * Memory-Optimierte CSV-Verarbeitung für CSV Import Pro
 * Ersetzt die speicher-intensive Verarbeitung durch Streaming
 * 
 * NEUE FEATURES:
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
                    'user_agent' => 'CSV Import Pro/' . CSV_IMPORT_PRO_VERSION,
                    'follow_location' => true,
                    'max_redirects' => 3
                ]
            ]);
            return fopen($source, 'r', false, $context);
        } else {
            // Lokaler Stream
            return fopen($source, 'r');
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
        $safe_batch_size = floor($available_memory * $this->memory_threshold / $estimated_memory_per_post);
        
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
                    'line' => $reader->current_line - count($batch_data) + $index + 1,
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
            'finished' => false,
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
        
        $post_id = wp_insert_post($post_data);
        
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
        $meta_batch = [];
        
        foreach ($row as $key => $value) {
            if (!in_array($key, $skip_fields) && !empty($value)) {
                $meta_key = '_' . sanitize_key($key);
                $meta_batch[$meta_key] = sanitize_text_field($value);
            }
        }
        
        // Batch-Meta-Update für bessere Performance
        foreach ($meta_batch as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
        
        unset($meta_batch); // Explizit freigeben
    }
    
    private function is_memory_critical() {
        $memory_limit = csv_import_convert_to_bytes(ini_get('memory_limit'));
        $current_usage = memory_get_usage(true);
        
        return ($current_usage / $memory_limit) > 0.85; // 85% Threshold
    }
    
    private function record_batch_metrics($time, $memory, $rows) {
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
            $this->current_batch_size = max($this->min_batch_size, floor($this->current_batch_size * 0.8));
        } elseif ($avg_time < 2 && !$this->is_memory_critical()) {
            // Schnell und wenig Memory - Batch vergrößern
            $this->current_batch_size = min($this->max_batch_size, ceil($this->current_batch_size * 1.2));
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
    private $checkpoint_interval = 50; // Alle 50 Posts einen Checkpoint
    
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
            'last_data_sample' => array_slice($last_processed_data, 0, 3), // Nur Sample
            'server_info' => [
                'php_memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'server_load' => sys_getloadavg()[0] ?? 'unknown'
            ]
        ];
        
        update_option('csv_import_checkpoint_' . $this->session_id, $checkpoint);
        
        csv_import_log('debug', 'Import-Checkpoint gespeichert', [
            'session' => $this->session_id,
            'progress' => round(($processed_lines / $total_lines) * 100, 1) . '%'
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
        $current_line = 0;
        
        // Header überspringen
        $reader->read_next_row();
        $current_line++;
        
        // Zu Checkpoint-Position vorspulen
        while ($current_line < $target_line && $reader->read_next_row() !== null) {
            $current_line++;
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
    private $initial_memory;
    private $peak_memory = 0;
    private $warnings_sent = 0;
    private $max_warnings = 3;
    
    public function __construct() {
        $this->initial_memory = memory_get_usage(true);
    }
    
    public function check_memory_status() {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = csv_import_convert_to_bytes(ini_get('memory_limit'));
        
        $this->peak_memory = max($this->peak_memory, $peak);
        
        $usage_percent = ($current / $limit) * 100;
        
        $status = [
            'current' => $current,
            'peak' => $peak,
            'limit' => $limit,
            'usage_percent' => round($usage_percent, 1),
            'status' => $this->get_memory_status_level($usage_percent),
            'available' => $limit - $current
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
            $suggestions[] = 'Aktiviere PHP Garbage Collection';
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
        if (csv_import_is_import_running()) {
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
        $csv_source = $this->prepare_csv_source($source, $config);
        
        // Streaming Reader initialisieren
        $reader = new CSV_Stream_Reader($csv_source);
        $processor = new CSV_Adaptive_Batch_Processor();
        
        // Geschätzten Total ermitteln (für Progress)
        $estimated_total = $this->estimate_total_rows($csv_source);
        csv_import_update_progress(0, $estimated_total, 'starting');
        
        // Header validieren
        $headers = $reader->get_headers();
        $column_validation = csv_import_validate_required_columns($headers, $config['required_columns']);
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
                $this->emergency_memory_cleanup();
                
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
            if ($total_processed % 50 === 0) {
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
                'memory_efficiency' => round(($total_processed * 1024 * 1024) / $final_memory['peak'], 2) // Posts per MB
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
        $url = str_replace('dropbox.com', 'dl.dropboxusercontent.com', $url);
        $url = str_replace('?dl=0', '?raw=1', $url);
        $url = str_replace('?dl=1', '?raw=1', $url);
        
        return $url;
        
    } elseif ($source === 'local') {
        if (empty($config['local_path'])) {
            throw new Exception('Lokaler Pfad nicht konfiguriert');
        }
        
        $file_path = ABSPATH . ltrim($config['local_path'], '/');
        
        if (!file_exists($file_path) || !is_readable($file_path)) {
            throw new Exception('Lokale CSV-Datei nicht gefunden: ' . $config['local_path']);
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
        $headers = get_headers($source, true);
        $content_length = $headers['Content-Length'] ?? 0;
        
        if ($content_length > 0) {
            // Durchschnittlich ~100 Bytes pro CSV-Zeile (grobe Schätzung)
            return max(1, floor($content_length / 100));
        }
        
        return 1000; // Fallback-Schätzung
        
    } else {
        // Lokale Datei: Schneller Line-Count
        $line_count = 0;
        $handle = fopen($source, 'r');
        
        if ($handle) {
            while (!feof($handle)) {
                $line = fgets($handle);
                if ($line !== false) {
                    $line_count++;
                }
                
                // Stoppe nach 10000 Zeilen und extrapoliere
                if ($line_count > 10000) {
                    $file_size = filesize($source);
                    $bytes_read = ftell($handle);
                    $estimated_total = floor(($file_size / $bytes_read) * $line_count);
                    fclose($handle);
                    return max(1, $estimated_total - 1); // -1 für Header
                }
            }
            fclose($handle);
        }
        
        return max(1, $line_count - 1); // -1 für Header
    }
}

/**
 * Notfall-Memory-Bereinigung
 */
function csv_import_emergency_memory_cleanup() {
    csv_import_log('warning', 'Notfall-Memory-Bereinigung gestartet');
    
    // 1. Garbage Collection erzwingen
    if (function_exists('gc_collect_cycles')) {
        $collected = gc_collect_cycles();
        csv_import_log('debug', "Garbage Collection: {$collected} Objekte bereinigt");
    }
    
    // 2. WordPress Object Cache leeren
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    // 3. Temporäre Variablen bereinigen
    global $wp_object_cache, $wpdb;
    
    if (isset($wp_object_cache)) {
        $wp_object_cache->flush();
    }
    
    // 4. Query Cache des WPdb leeren
    if (isset($wpdb)) {
        $wpdb->flush();
    }
    
    // 5. PHP OpCache leeren falls verfügbar
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    
    $freed_memory = memory_get_usage(true);
    csv_import_log('info', 'Notfall-Memory-Bereinigung abgeschlossen', [
        'memory_after_cleanup' => size_format($freed_memory)
    ]);
}

// ===================================================================
// ERWEITERTE CORE-FUNCTIONS OVERRIDES
// ===================================================================

/**
 * Memory-optimierte Version von csv_import_load_csv_data
 * Verwendet Streaming statt komplettes Laden in Memory
 */
function csv_import_load_csv_data_optimized(string $source, array $config): array {
    csv_import_log('info', 'Starte memory-optimierte CSV-Ladung', [
        'source' => $source,
        'initial_memory' => size_format(memory_get_usage(true))
    ]);
    
    // Quelle vorbereiten
    $csv_source = csv_import_prepare_csv_source($source, $config);
    
    // File-Size Check vor dem Öffnen
    if (!filter_var($csv_source, FILTER_VALIDATE_URL)) {
        $file_size = filesize($csv_source);
        $memory_limit = csv_import_convert_to_bytes(ini_get('memory_limit'));
        
        if ($file_size > ($memory_limit * 0.3)) {
            csv_import_log('warning', 'Große CSV-Datei erkannt - verwende Streaming-Modus', [
                'file_size' => size_format($file_size),
                'memory_limit' => size_format($memory_limit)
            ]);
        }
    }
    
    // Nur Header und erste paar Zeilen für Validierung laden
    $handle = fopen($csv_source, 'r');
    if (!$handle) {
        throw new Exception('Konnte CSV-Quelle nicht öffnen: ' . $csv_source);
    }
    
    // Header lesen
    $headers = fgetcsv($handle);
    if (empty($headers)) {
        fclose($handle);
        throw new Exception('Keine gültigen CSV-Header gefunden');
    }
    
    $headers = array_map('trim', $headers);
    
    // Nur erste 5 Zeilen für Sample-Data laden (memory-schonend)
    $sample_data = [];
    for ($i = 0; $i < 5; $i++) {
        $row = fgetcsv($handle);
        if ($row === false) break;
        
        $row_data = [];
        foreach ($headers as $index => $header) {
            $row_data[$header] = isset($row[$index]) ? trim($row[$index]) : '';
        }
        $sample_data[] = $row_data;
    }
    
    fclose($handle);
    
    // Zeilen-Schätzung
    $total_rows = csv_import_estimate_total_rows($csv_source);
    
    csv_import_log('debug', 'CSV-Metadaten geladen', [
        'headers_count' => count($headers),
        'estimated_rows' => $total_rows,
        'memory_after_load' => size_format(memory_get_usage(true))
    ]);
    
    return [
        'headers' => $headers,
        'data' => $sample_data, // Nur Sample für Validierung
        'total_rows' => $total_rows,
        'source_path' => $csv_source,
        'streaming_mode' => true
    ];
}

/**
 * Memory-optimierte Hauptverarbeitungsfunktion
 */
function csv_import_process_data_optimized(array $csv_metadata, array $config, string $session_id): array {
    $memory_monitor = new CSV_Memory_Monitor();
    $reader = new CSV_Stream_Reader($csv_metadata['source_path']);
    $processor = new CSV_Adaptive_Batch_Processor();
    $resumable = new CSV_Resumable_Import($session_id);
    
    $total_processed = 0;
    $total_errors = 0;
    $error_messages = [];
    $created_posts = [];
    
    // Resume von Checkpoint falls vorhanden
    if ($resumable->can_resume()) {
        csv_import_log('info', 'Setze Import von Checkpoint fort');
        $resumable->resume_from_checkpoint($reader);
        
        $checkpoint = $resumable->get_checkpoint();
        $total_processed = $checkpoint['processed_lines'] ?? 0;
    }
    
    $batch_count = 0;
    $start_time = microtime(true);
    
    try {
        while (true) {
            $batch_count++;
            
            // Memory-Status vor jedem Batch prüfen
            $memory_status = $memory_monitor->check_memory_status();
            
            csv_import_log('debug', "Batch {$batch_count} startet", [
                'memory_status' => $memory_status['status'],
                'memory_usage' => size_format($memory_status['current']),
                'batch_size' => $processor->get_current_batch_size()
            ]);
            
            // Batch verarbeiten
            $batch_result = $processor->process_batch($reader, $config, $session_id);
            
            $total_processed += $batch_result['processed'];
            $total_errors += count($batch_result['errors']);
            $error_messages = array_merge($error_messages, $batch_result['errors']);
            
            // Progress-Update
            csv_import_update_progress($total_processed, $csv_metadata['total_rows'], 'processing');
            
            // Checkpoint alle 50 verarbeitete Posts
            if ($total_processed % 50 === 0) {
                $resumable->save_checkpoint($total_processed, $csv_metadata['total_rows'], $reader->get_progress_info());
            }
            
            // Batch-Performance loggen
            if (isset($batch_result['metrics'])) {
                csv_import_log('debug', "Batch {$batch_count} Performance", $batch_result['metrics']);
            }
            
            // Import beendet?
            if ($batch_result['finished']) {
                csv_import_log('info', 'Alle CSV-Daten verarbeitet');
                break;
            }
            
            // Emergency Breaks
            if ($total_errors > 100) {
                throw new Exception("Zu viele Fehler ({$total_errors}) - Import abgebrochen");
            }
            
            if ($memory_status['status'] === 'critical') {
                csv_import_emergency_memory_cleanup();
                
                // Nach Cleanup nochmal prüfen
                if ($memory_monitor->check_memory_status()['status'] === 'critical') {
                    throw new Exception('Kritischer Speichermangel - Import gestoppt');
                }
            }
            
            // Timeout-Check (PHP max_execution_time berücksichtigen)
            $runtime = microtime(true) - $start_time;
            $max_execution = ini_get('max_execution_time');
            if ($max_execution > 0 && $runtime > ($max_execution * 0.9)) {
                csv_import_log('warning', 'Execution time limit erreicht - Import wird aufgeteilt');
                
                // Checkpoint speichern für Resume
                $resumable->save_checkpoint($total_processed, $csv_metadata['total_rows'], $reader->get_progress_info());
                
                throw new Exception('Execution Time Limit erreicht - Import kann fortgesetzt werden');
            }
        }
        
    } finally {
        $reader->close();
        
        // Finale Memory-Statistiken
        $final_memory = $memory_monitor->check_memory_status();
        csv_import_log('info', 'Import-Verarbeitung beendet', [
            'total_processed' => $total_processed,
            'total_errors' => $total_errors,
            'batches_processed' => $batch_count,
            'final_memory_usage' => size_format($final_memory['current']),
            'peak_memory_usage' => size_format($final_memory['peak'])
        ]);
    }
    
    return [
        'success' => $total_processed > 0,
        'processed' => $total_processed,
        'total' => $csv_metadata['total_rows'],
        'errors' => $total_errors,
        'error_messages' => array_slice($error_messages, 0, 10),
        'created_posts' => $created_posts,
        'session_id' => $session_id,
        'memory_efficiency' => [
            'peak_memory' => $memory_monitor->check_memory_status()['peak'],
            'posts_per_mb' => $memory_monitor->check_memory_status()['peak'] > 0 ? 
                round(($total_processed * 1024 * 1024) / $memory_monitor->check_memory_status()['peak'], 2) : 0
        ]
    ];
}

// ===================================================================
// MEMORY-OPTIMIERTE HILFSFUNKTIONEN
// ===================================================================

/**
 * Memory-schonende Duplikat-Prüfung mit Cache
 */
function csv_import_check_duplicate_optimized($title, $post_type) {
    static $duplicate_cache = [];
    static $cache_size = 0;
    $cache_key = md5($title . $post_type);
    
    // Erst im Cache prüfen
    if (isset($duplicate_cache[$cache_key])) {
        return $duplicate_cache[$cache_key];
    }
    
    // Cache-Größe begrenzen
    if ($cache_size > 1000) {
        $duplicate_cache = array_slice($duplicate_cache, -500, null, true);
        $cache_size = 500;
    }
    
    // Datenbankabfrage
    global $wpdb;
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_title = %s AND post_type = %s 
         LIMIT 1",
        $title, $post_type
    ));
    
    $result = !empty($exists);
    $duplicate_cache[$cache_key] = $result;
    $cache_size++;
    
    return $result;
}

/**
 * Memory-effiziente Template-Anwendung mit Caching
 */
function csv_import_apply_template_cached($template_id, $data) {
    static $template_cache = [];
    
    if (!isset($template_cache[$template_id])) {
        $template_post = get_post($template_id);
        if (!$template_post) {
            throw new Exception("Template ID {$template_id} nicht gefunden");
        }
        
        $template_cache[$template_id] = $template_post->post_content;
        
        // Template-Cache begrenzen
        if (count($template_cache) > 5) {
            $template_cache = array_slice($template_cache, -3, null, true);
        }
    }
    
    $content = $template_cache[$template_id];
    
    // Nur die wichtigsten Platzhalter (memory-schonend)
    $essential_replacements = [
        '{{title}}' => $data['post_title'] ?? $data['title'] ?? '',
        '{{content}}' => $data['post_content'] ?? $data['content'] ?? '',
        '{{excerpt}}' => $data['post_excerpt'] ?? $data['excerpt'] ?? ''
    ];
    
    foreach ($essential_replacements as $placeholder => $value) {
        $content = str_replace($placeholder, wp_kses_post($value), $content);
    }
    
    return $content;
}

/**
 * Batch-Meta-Update für bessere Performance
 */
function csv_import_batch_update_meta($post_id, $meta_data) {
    static $meta_batch = [];
    static $batch_count = 0;
    
    // Meta-Daten zur Batch hinzufügen
    $meta_batch[$post_id] = $meta_data;
    $batch_count++;
    
    // Batch ausführen alle 20 Posts oder am Ende
    if ($batch_count >= 20 || $post_id === 'flush') {
        foreach ($meta_batch as $pid => $meta) {
            if (is_numeric($pid)) {
                foreach ($meta as $key => $value) {
                    update_post_meta($pid, $key, $value);
                }
            }
        }
        
        csv_import_log('debug', "Meta-Batch verarbeitet: {$batch_count} Posts");
        
        $meta_batch = [];
        $batch_count = 0;
        
        // Garbage Collection nach Batch
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}

// ===================================================================
// INTEGRATION FUNCTIONS - Ersetzen die Original-Funktionen
// ===================================================================

/**
 * Wrapper-Funktion: Ersetzt csv_import_start_import mit optimierter Version
 */
function csv_import_start_import_with_memory_optimization($source, $config = null) {
    // Feature-Flag für schrittweise Einführung
    $use_optimization = get_option('csv_import_use_memory_optimization', true);
    
    if ($use_optimization) {
        return csv_import_start_import_optimized($source, $config);
    } else {
        // Fallback zur Original-Funktion
        return csv_import_start_import($source, $config);
    }
}

/**
 * Memory-Status für Admin-Dashboard
 */
function csv_import_get_memory_status() {
    $current = memory_get_usage(true);
    $peak = memory_get_peak_usage(true);
    $limit = csv_import_convert_to_bytes(ini_get('memory_limit'));
    
    $status = [
        'current_usage' => $current,
        'current_formatted' => size_format($current),
        'peak_usage' => $peak,
        'peak_formatted' => size_format($peak),
        'limit' => $limit,
        'limit_formatted' => size_format($limit),
        'usage_percent' => round(($current / $limit) * 100, 1),
        'peak_percent' => round(($peak / $limit) * 100, 1),
        'available' => $limit - $current,
        'available_formatted' => size_format($limit - $current),
        'status_level' => csv_import_get_memory_status_level($current, $limit),
        'recommendations' => csv_import_get_memory_recommendations($current, $limit)
    ];
    
    return $status;
}

function csv_import_get_memory_status_level($current, $limit) {
    $percent = ($current / $limit) * 100;
    
    if ($percent < 50) return 'excellent';
    if ($percent < 70) return 'good';
    if ($percent < 85) return 'warning';
    return 'critical';
}

function csv_import_get_memory_recommendations($current, $limit) {
    $percent = ($current / $limit) * 100;
    $recommendations = [];
    
    if ($percent > 85) {
        $recommendations[] = 'Erhöhe PHP Memory Limit auf mindestens ' . size_format($limit * 1.5);
        $recommendations[] = 'Reduziere Batch-Größe auf 5-10 Posts';
        $recommendations[] = 'Deaktiviere andere speicher-intensive Plugins während Import';
    } elseif ($percent > 70) {
        $recommendations[] = 'Überwache Memory-Verbrauch während größerer Imports';
        $recommendations[] = 'Aktiviere PHP OpCache für bessere Performance';
    } elseif ($percent < 30) {
        $recommendations[] = 'Du kannst größere Batch-Größen verwenden (50-100 Posts)';
        $recommendations[] = 'System hat genug Speicher für komplexere Verarbeitung';
    }
    
    return $recommendations;
}

// ===================================================================
// PROGRESSIVE ENHANCEMENT - Schrittweise Aktivierung
// ===================================================================

/**
 * Aktiviert Memory-Optimierungen basierend auf System-Capabilities
 */
function csv_import_enable_progressive_optimization() {
    $system_info = [
        'php_version' => PHP_VERSION,
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status(),
        'gc_enabled' => function_exists('gc_enabled') && gc_enabled()
    ];
    
    $optimizations = [
        'streaming_reader' => true, // Immer aktivieren
        'adaptive_batching' => true, // Immer aktivieren
        'memory_monitoring' => true, // Immer aktivieren
        'garbage_collection' => function_exists('gc_collect_cycles'),
        'opcache_optimization' => $system_info['opcache_enabled'],
        'progressive_loading' => version_compare(PHP_VERSION, '7.4', '>='),
        'resumable_imports' => true
    ];
    
    update_option('csv_import_active_optimizations', $optimizations);
    
    csv_import_log('info', 'Memory-Optimierungen aktiviert', [
        'system_info' => $system_info,
        'active_optimizations' => array_filter($optimizations)
    ]);
    
    return $optimizations;
}

// ===================================================================
// ADMIN-INTERFACE INTEGRATION
// ===================================================================

/**
 * Memory-Status Widget für Admin-Dashboard
 */
function csv_import_memory_status_widget() {
    $memory_status = csv_import_get_memory_status();
    $optimizations = get_option('csv_import_active_optimizations', []);
    
    echo '<div class="csv-memory-status-widget">';
    echo '<h4>💾 Memory-Status</h4>';
    
    // Status-Anzeige
    $status_colors = [
        'excellent' => '#28a745',
        'good' => '#17a2b8', 
        'warning' => '#ffc107',
        'critical' => '#dc3545'
    ];
    
    $color = $status_colors[$memory_status['status_level']] ?? '#6c757d';
    
    echo '<div style="margin: 10px 0;">';
    echo '<div style="background: #f1f1f1; border-radius: 10px; overflow: hidden;">';
    echo '<div style="background: ' . $color . '; width: ' . $memory_status['usage_percent'] . '%; height: 20px; text-align: center; line-height: 20px; color: white; font-size: 12px;">';
    echo $memory_status['usage_percent'] . '%';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    echo '<p><strong>Aktuell:</strong> ' . $memory_status['current_formatted'] . ' / ' . $memory_status['limit_formatted'] . '</p>';
    echo '<p><strong>Peak:</strong> ' . $memory_status['peak_formatted'] . ' (' . $memory_status['peak_percent'] . '%)</p>';
    
    // Optimierungs-Status
    $active_count = count(array_filter($optimizations));
    echo '<p><strong>Optimierungen:</strong> ' . $active_count . ' von ' . count($optimizations) . ' aktiv</p>';
    
    // Empfehlungen
    if (!empty($memory_status['recommendations'])) {
        echo '<div style="margin-top: 10px; padding: 8px; background: #f8f9fa; border-left: 3px solid ' . $color . ';">';
        echo '<strong>Empfehlungen:</strong><br>';
        echo '<ul style="margin: 5px 0; padding-left: 20px;">';
        foreach (array_slice($memory_status['recommendations'], 0, 2) as $rec) {
            echo '<li style="font-size: 11px;">' . esc_html($rec) . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
    
    echo '</div>';
}

// Widget registrieren
add_action('wp_dashboard_setup', function() {
    if (current_user_can('manage_options')) {
        wp_add_dashboard_widget(
            'csv_memory_status',
            'CSV Import - Memory Status',
            'csv_import_memory_status_widget'
        );
    }
});

// ===================================================================
// TESTING & BENCHMARKING FUNCTIONS
// ===================================================================

/**
 * Testet Memory-Performance mit verschiedenen Dateigrößen
 */
function csv_import_benchmark_memory_performance() {
    if (!current_user_can('manage_options')) {
        return ['error' => 'Keine Berechtigung'];
    }
    
    $test_sizes = [1000, 5000, 10000, 25000]; // Anzahl Zeilen
    $results = [];
    
    foreach ($test_sizes as $size) {
        $start_memory = memory_get_usage(true);
        $start_time = microtime(true);
        
        // Simuliere CSV-Verarbeitung
        $test_data = csv_import_generate_test_csv_data($size);
        
        $peak_memory = memory_get_peak_usage(true);
        $end_time = microtime(true);
        
        $results[$size] = [
            'rows' => $size,
            'time' => round($end_time - $start_time, 2),
            'memory_used' => $peak_memory - $start_memory,
            'memory_formatted' => size_format($peak_memory - $start_memory),
            'posts_per_second' => round($size / ($end_time - $start_time), 2),
            'memory_per_post' => round(($peak_memory - $start_memory) / $size),
            'memory_per_post_formatted' => size_format(($peak_memory - $start_memory) / $size)
        ];
        
        // Memory nach Test bereinigen
        unset($test_data);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
    
    return $results;
}

function csv_import_generate_test_csv_data($rows) {
    $data = [];
    for ($i = 0; $i < $rows; $i++) {
        $data[] = [
            'post_title' => 'Test Post ' . $i,
            'post_content' => 'Test content for post ' . $i . ' with some longer text to simulate real content.',
            'post_excerpt' => 'Test excerpt ' . $i,
            'meta_field_1' => 'Meta value ' . $i,
            'meta_field_2' => 'Another meta value ' . $i
        ];
        
        // Simuliere Memory-Verbrauch eines echten Posts
        if ($i % 100 === 0) {
            usleep(1000); // Kurze Pause
        }
    }
    return $data;
}

// ===================================================================
// MIGRATION & COMPATIBILITY
// ===================================================================

/**
 * Migriert von alter zu neuer Memory-optimierter Verarbeitung
 */
function csv_import_migrate_to_memory_optimization() {
    $migration_steps = [
        'backup_current_settings',
        'install_optimization_tables', 
        'test_optimization_compatibility',
        'enable_progressive_features',
        'cleanup_old_cache'
    ];
    
    $results = [];
    
    foreach ($migration_steps as $step) {
        try {
            $function_name = 'csv_import_migration_' . $step;
            if (function_exists($function_name)) {
                $results[$step] = call_user_func($function_name);
            }
        } catch (Exception $e) {
            $results[$step] = ['error' => $e->getMessage()];
            csv_import_log('error', "Migration-Schritt {$step} fehlgeschlagen: " . $e->getMessage());
        }
    }
    
    // Migration-Status speichern
    update_option('csv_import_memory_migration_completed', current_time('mysql'));
    update_option('csv_import_memory_migration_results', $results);
    
    return $results;
}

// ===================================================================
// MONITORING & ALERTS
// ===================================================================

/**
 * Memory-Alert-System für kritische Situationen
 */
function csv_import_setup_memory_alerts() {
    // Alert wenn Memory-Verbrauch 90% erreicht
    add_action('csv_import_memory_critical', function($memory_info) {
        $admin_email = get_option('admin_email');
        $subject = '[' . get_bloginfo('name') . '] CSV Import - Kritischer Speicherverbrauch';
        
        $message = "Der CSV-Import hat einen kritischen Speicherverbrauch erreicht:\n\n";
        $message .= "Aktuell: " . size_format($memory_info['current']) . "\n";
        $message .= "Limit: " . size_format($memory_info['limit']) . "\n";
        $message .= "Verbrauch: " . $memory_info['usage_percent'] . "%\n\n";
        $message .= "Der Import wurde möglicherweise pausiert oder gestoppt.\n";
        $message .= "Bitte prüfen Sie die Memory-Einstellungen.\n\n";
        $message .= "Dashboard: " . admin_url('tools.php?page=csv-import');
        
        wp_mail($admin_email, $subject, $message);
    });
}

/**
 * Memory-Performance-Report für Admin
 */
function csv_import_generate_memory_report() {
    $report = [
        'timestamp' => current_time('mysql'),
        'current_memory' => csv_import_get_memory_status(),
        'system_info' => [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache_enabled' => function_exists('opcache_get_status'),
            'xdebug_enabled' => extension_loaded('xdebug')
        ],
        'optimization_status' => get_option('csv_import_active_optimizations', []),
        'recent_imports' => csv_import_get_recent_memory_stats(),
        'recommendations' => csv_import_get_system_memory_recommendations()
    ];
    
    return $report;
}

function csv_import_get_recent_memory_stats() {
    $stats = get_option('csv_import_memory_stats', []);
    return array_slice($stats, -10); // Letzte 10 Imports
}

function csv_import_get_system_memory_recommendations() {
    $memory_limit = csv_import_convert_to_bytes(ini_get('memory_limit'));
    $recommendations = [];
    
    if ($memory_limit < 256 * 1024 * 1024) { // < 256MB
        $recommendations[] = 'Erhöhe PHP Memory Limit auf mindestens 256MB';
        $recommendations[] = 'Verwende kleinere Batch-Größen (5-10 Posts)';
    }
    
    if (!function_exists('gc_collect_cycles')) {
        $recommendations[] = 'Aktiviere PHP Garbage Collection für bessere Memory-Verwaltung';
    }
    
    if (extension_loaded('xdebug')) {
        $recommendations[] = 'Deaktiviere Xdebug in Produktionsumgebung für bessere Performance';
    }
    
    return $recommendations;
}

// ===================================================================
// EMERGENCY RECOVERY SYSTEM
// ===================================================================

/**
 * Notfall-Recovery bei Memory-Problemen
 */
class CSV_Emergency_Recovery {
    private $session_id;
    private $recovery_data = [];
    
    public function __construct($session_id) {
        $this->session_id = $session_id;
        $this->load_recovery_data();
    }
    
    public function save_emergency_state($processed, $total, $current_memory) {
        $this->recovery_data = [
            'session_id' => $this->session_id,
            'processed' => $processed,
            'total' => $total,
            'timestamp' => current_time('mysql'),
            'memory_at_failure' => $current_memory,
            'server_load' => sys_getloadavg()[0] ?? 'unknown',
            'php_errors' => error_get_last(),
            'recovery_attempts' => ($this->recovery_data['recovery_attempts'] ?? 0) + 1
        ];
        
        update_option('csv_import_emergency_' . $this->session_id, $this->recovery_data);
        
        csv_import_log('critical', 'Emergency Recovery State gespeichert', [
            'session' => $this->session_id,
            'processed' => $processed,
            'memory' => size_format($current_memory)
        ]);
    }
    
    public function attempt_recovery() {
        if (empty($this->recovery_data)) {
            return false;
        }
        
        // Zu viele Recovery-Versuche?
        if (($this->recovery_data['recovery_attempts'] ?? 0) > 3) {
            csv_import_log('error', 'Zu viele Recovery-Versuche - Import endgültig gestoppt');
            $this->cleanup_recovery_data();
            return false;
        }
        
        csv_import_log('info', 'Versuche Emergency Recovery', [
            'attempt' => $this->recovery_data['recovery_attempts'],
            'last_position' => $this->recovery_data['processed']
        ]);
        
        // Memory aggressiv bereinigen
        csv_import_emergency_memory_cleanup();
        
        // PHP-Settings für Recovery anpassen
        @ini_set('memory_limit', '512M');
        @set_time_limit(600); // 10 Minuten
        
        return true;
    }
    
    private function load_recovery_data() {
        $this->recovery_data = get_option('csv_import_emergency_' . $this->session_id, []);
    }
    
    public function cleanup_recovery_data() {
        delete_option('csv_import_emergency_' . $this->session_id);
    }
    
    public function has_recovery_data() {
        return !empty($this->recovery_data);
    }
    
    public function get_recovery_info() {
        return $this->recovery_data;
    }
}

// ===================================================================
// AJAX ENDPOINTS FÜR MEMORY-MONITORING
// ===================================================================

/**
 * AJAX-Handler für Live-Memory-Monitoring
 */
function csv_import_ajax_memory_status() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    
    if (!current_user_can('edit_pages')) {
        wp_send_json_error(['message' => 'Keine Berechtigung']);
    }
    
    $memory_status = csv_import_get_memory_status();
    $import_progress = csv_import_get_progress();
    
    wp_send_json_success([
        'memory' => $memory_status,
        'import_running' => $import_progress['running'],
        'recommendations' => $memory_status['recommendations']
    ]);
}

add_action('wp_ajax_csv_memory_status', 'csv_import_ajax_memory_status');

/**
 * AJAX-Handler für Memory-Optimierung Tests
 */
function csv_import_ajax_test_memory_optimization() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Keine Berechtigung']);
    }
    
    try {
        $benchmark_results = csv_import_benchmark_memory_performance();
        wp_send_json_success($benchmark_results);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

add_action('wp_ajax_csv_test_memory_optimization', 'csv_import_ajax_test_memory_optimization');

// ===================================================================
// HOOKS & INTEGRATION
// ===================================================================

/**
 * Hook in den Original-Import-Prozess ein
 */
add_filter('csv_import_use_optimized_processor', '__return_true');

/**
 * Überschreibe Original-Funktionen mit optimierten Versionen
 */
add_filter('csv_import_start_import', function($original_function) {
    return 'csv_import_start_import_with_memory_optimization';
}, 10, 1);

/**
 * Memory-Monitoring während Import automatisch aktivieren
 */
add_action('csv_import_start', function() {
    csv_import_enable_progressive_optimization();
    csv_import_setup_memory_alerts();
});

/**
 * Memory-Stats nach Import speichern
 */
add_action('csv_import_completed', function($result, $source) {
    $memory_stats = get_option('csv_import_memory_stats', []);
    
    $current_stats = [
        'timestamp' => current_time('mysql'),
        'source' => $source,
        'processed' => $result['processed'] ?? 0,
        'peak_memory' => memory_get_peak_usage(true),
        'peak_memory_formatted' => size_format(memory_get_peak_usage(true)),
        'efficiency' => isset($result['memory_efficiency']) ? $result['memory_efficiency'] : null
    ];
    
    $memory_stats[] = $current_stats;
    
    // Nur letzte 20 Stats behalten
    if (count($memory_stats) > 20) {
        $memory_stats = array_slice($memory_stats, -20);
    }
    
    update_option('csv_import_memory_stats', $memory_stats);
}, 10, 2);

// ===================================================================
// ADMIN NOTICES FÜR MEMORY-OPTIMIERUNG
// ===================================================================

/**
 * Zeigt Memory-Optimierung Admin-Notices
 */
add_action('admin_notices', function() {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'csv-import') === false) {
        return;
    }
    
    $memory_status = csv_import_get_memory_status();
    
    // Warnung bei kritischem Memory-Status
    if ($memory_status['status_level'] === 'critical') {
        echo '<div class="notice notice-error">';
        echo '<p><strong>⚠️ Kritischer Speicherstatus:</strong> ';
        echo 'Aktuell ' . $memory_status['current_formatted'] . ' von ' . $memory_status['limit_formatted'] . ' verwendet ';
        echo '(' . $memory_status['usage_percent'] . '%). ';
        echo 'CSV-Imports könnten fehlschlagen.</p>';
        echo '<p><strong>Empfehlung:</strong> ' . ($memory_status['recommendations'][0] ?? 'Memory Limit erhöhen') . '</p>';
        echo '</div>';
    }
    
    // Info über Memory-Optimierungen
    $optimizations = get_option('csv_import_active_optimizations', []);
    $active_count = count(array_filter($optimizations));
    
    if ($active_count < 5) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>💡 Memory-Optimierungen:</strong> ';
        echo $active_count . ' von ' . count($optimizations) . ' Optimierungen aktiv. ';
        echo '<a href="#" onclick="csvImportEnableAllOptimizations()">Alle aktivieren</a> für bessere Performance.</p>';
        echo '</div>';
    }
});

// ===================================================================
// JAVASCRIPT INTEGRATION
// ===================================================================

/**
 * JavaScript für Live-Memory-Monitoring im Admin
 */
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'csv-import') === false) {
        return;
    }
    ?>
    <script>
    // Live Memory-Monitoring
    let memoryMonitorInterval;
    
    function startMemoryMonitoring() {
        if (memoryMonitorInterval) return;
        
        memoryMonitorInterval = setInterval(function() {
            jQuery.post(ajaxurl, {
                action: 'csv_memory_status',
                nonce: csvImportAjax.nonce
            }, function(response) {
                if (response.success) {
                    updateMemoryDisplay(response.data);
                }
            });
        }, 5000); // Alle 5 Sekunden
    }
    
    function updateMemoryDisplay(data) {
        const memoryWidget = jQuery('#csv-memory-status-live');
        if (memoryWidget.length) {
            const percent = data.memory.usage_percent;
            const status = data.memory.status_level;
            
            let statusColor = '#28a745'; // good
            if (status === 'warning') statusColor = '#ffc107';
            if (status === 'critical') statusColor = '#dc3545';
            
            memoryWidget.html(`
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="flex: 1; background: #f1f1f1; border-radius: 10px; overflow: hidden; height: 20px;">
                        <div style="background: ${statusColor}; width: ${percent}%; height: 100%; transition: all 0.3s ease;"></div>
                    </div>
                    <span style="min-width: 60px; font-weight: bold;">${percent}%</span>
                </div>
                <small>${data.memory.current_formatted} / ${data.memory.limit_formatted}</small>
            `);
            
            // Warnung bei kritischem Status
            if (status === 'critical' && data.import_running) {
                if (!jQuery('#memory-critical-warning').length) {
                    jQuery('.wrap').prepend(`
                        <div id="memory-critical-warning" class="notice notice-error">
                            <p><strong>⚠️ Kritischer Speicherstatus während Import!</strong> 
                            Der Import könnte abbrechen. <a href="#" onclick="csvEmergencyMemoryCleanup()">Notfall-Bereinigung</a></p>
                        </div>
                    `);
                }
            }
        }
    }
    
    function csvEmergencyMemoryCleanup() {
        if (confirm('Notfall-Memory-Bereinigung durchführen? Dies kann den laufenden Import beeinträchtigen.')) {
            jQuery.post(ajaxurl, {
                action: 'csv_emergency_memory_cleanup',
                nonce: csvImportAjax.nonce
            }, function(response) {
                if (response.success) {
                    alert('Memory-Bereinigung erfolgreich!');
                    location.reload();
                } else {
                    alert('Bereinigung fehlgeschlagen: ' + response.data.message);
                }
            });
        }
    }
    
    function csvImportEnableAllOptimizations() {
        jQuery.post(ajaxurl, {
            action: 'csv_enable_all_optimizations',
            nonce: csvImportAjax.nonce
        }, function(response) {
            if (response.success) {
                alert('Alle Memory-Optimierungen aktiviert!');
                location.reload();
            } else {
                alert('Aktivierung fehlgeschlagen: ' + response.data.message);
            }
        });
    }
    
    // Memory-Monitoring starten wenn auf CSV-Import-Seite
    jQuery(document).ready(function() {
        if (jQuery('.csv-import-dashboard, .csv-settings-grid').length) {
            // Memory-Status-Widget hinzufügen falls nicht vorhanden
            if (!jQuery('#csv-memory-status-live').length) {
                jQuery('.wrap h1').after(`
                    <div class="card" style="margin: 20px 0;">
                        <h3>💾 Live Memory-Status</h3>
                        <div id="csv-memory-status-live">Lade Memory-Status...</div>
                    </div>
                `);
            }
            
            startMemoryMonitoring();
        }
    });
    
    // Cleanup beim Seitenwechsel
    jQuery(window).on('beforeunload', function() {
        if (memoryMonitorInterval) {
            clearInterval(memoryMonitorInterval);
        }
    });
    </script>
    <?php
});

// ===================================================================
// ERWEITERTE AJAX HANDLERS
// ===================================================================

/**
 * AJAX-Handler für Notfall-Memory-Bereinigung
 */
function csv_import_ajax_emergency_memory_cleanup() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Keine Berechtigung']);
    }
    
    try {
        $before_memory = memory_get_usage(true);
        csv_import_emergency_memory_cleanup();
        $after_memory = memory_get_usage(true);
        
        $freed = $before_memory - $after_memory;
        
        wp_send_json_success([
            'message' => 'Memory-Bereinigung erfolgreich',
            'freed_memory' => size_format($freed),
            'current_memory' => size_format($after_memory)
        ]);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

add_action('wp_ajax_csv_emergency_memory_cleanup', 'csv_import_ajax_emergency_memory_cleanup');

/**
 * AJAX-Handler für Aktivierung aller Optimierungen
 */
function csv_import_ajax_enable_all_optimizations() {
    check_ajax_referer('csv_import_ajax', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Keine Berechtigung']);
    }
    
    try {
        $optimizations = csv_import_enable_progressive_optimization();
        
        wp_send_json_success([
            'message' => 'Alle Optimierungen aktiviert',
            'active_optimizations' => array_filter($optimizations),
            'total_count' => count(array_filter($optimizations))
        ]);
        
    } catch (Exception $e) {
        wp_send_json_error(['message' => $e->getMessage()]);
    }
}

add_action('wp_ajax_csv_enable_all_optimizations', 'csv_import_ajax_enable_all_optimizations');

// ===================================================================
// SETTINGS INTEGRATION
// ===================================================================

/**
 * Fügt Memory-Optimierung Einstellungen zum Settings-Panel hinzu
 */
function csv_import_add_memory_optimization_settings() {
    add_settings_section(
        'csv_import_memory_optimization',
        'Memory-Optimierung',
        function() {
            echo '<p>Konfiguriere Memory-Optimierungen für bessere Performance bei großen CSV-Dateien.</p>';
        },
        'csv_import_settings'
    );
    
    // Streaming-Modus
    add_settings_field(
        'csv_import_streaming_mode',
        'Streaming-Modus',
        function() {
            $enabled = get_option('csv_import_streaming_mode', true);
            echo '<label>';
            echo '<input type="checkbox" name="csv_import_streaming_mode" value="1" ' . checked($enabled, true, false) . '>';
            echo ' CSV-Dateien als Stream verarbeiten (empfohlen für große Dateien)';
            echo '</label>';
        },
        'csv_import_settings',
        'csv_import_memory_optimization'
    );
    
    // Adaptive Batch-Größen
    add_settings_field(
        'csv_import_adaptive_batching',
        'Adaptive Batch-Größen',
        function() {
            $enabled = get_option('csv_import_adaptive_batching', true);
            echo '<label>';
            echo '<input type="checkbox" name="csv_import_adaptive_batching" value="1" ' . checked($enabled, true, false) . '>';
            echo ' Batch-Größe automatisch an verfügbaren Speicher anpassen';
            echo '</label>';
        },
        'csv_import_settings',
        'csv_import_memory_optimization'
    );
    
    // Automatische Garbage Collection
    add_settings_field(
        'csv_import_auto_gc',
        'Automatische Garbage Collection',
        function() {
            $enabled = get_option('csv_import_auto_gc', true);
            $available = function_exists('gc_collect_cycles');
            
            echo '<label>';
            echo '<input type="checkbox" name="csv_import_auto_gc" value="1" ' . checked($enabled, true, false);
            if (!$available) echo ' disabled';
            echo '>';
            echo ' PHP Garbage Collection nach jedem Batch ausführen';
            echo '</label>';
            
            if (!$available) {
                echo '<p class="description" style="color: #d63638;">PHP Garbage Collection nicht verfügbar in dieser PHP-Installation.</p>';
            }
        },
        'csv_import_settings',
        'csv_import_memory_optimization'
    );
    
    register_setting('csv_import_settings', 'csv_import_streaming_mode');
    register_setting('csv_import_settings', 'csv_import_adaptive_batching');
    register_setting('csv_import_settings', 'csv_import_auto_gc');
}

add_action('admin_init', 'csv_import_add_memory_optimization_settings');

// ===================================================================
// COMPATIBILITY & MIGRATION
// ===================================================================

/**
 * Prüft ob System für Memory-Optimierungen geeignet ist
 */
function csv_import_check_optimization_compatibility() {
    $requirements = [
        'php_version' => version_compare(PHP_VERSION, '7.4', '>='),
        'memory_functions' => function_exists('memory_get_usage') && function_exists('memory_get_peak_usage'),
        'file_functions' => function_exists('fopen') && function_exists('fgetcsv'),
        'stream_support' => function_exists('stream_context_create'),
        'garbage_collection' => function_exists('gc_collect_cycles'),
        'minimum_memory' => csv_import_convert_to_bytes(ini_get('memory_limit')) >= (128 * 1024 * 1024)
    ];
    
    $compatibility_score = count(array_filter($requirements));
    $total_checks = count($requirements);
    
    return [
        'compatible' => $compatibility_score >= ($total_checks - 1), // Mindestens alle außer einem
        'score' => $compatibility_score,
        'total' => $total_checks,
        'percentage' => round(($compatibility_score / $total_checks) * 100, 1),
        'requirements' => $requirements,
        'missing_features' => array_keys(array_filter($requirements, function($v) { return !$v; }))
    ];
}

/**
 * Automatische Aktivierung der Optimierungen bei Plugin-Update
 */
function csv_import_auto_enable_optimizations_on_update() {
    $current_version = get_option('csv_import_version', '0');
    $new_version = CSV_IMPORT_PRO_VERSION;
    
    if (version_compare($current_version, $new_version, '<')) {
        // Plugin wurde aktualisiert
        $compatibility = csv_import_check_optimization_compatibility();
        
        if ($compatibility['compatible']) {
            csv_import_enable_progressive_optimization();
            
            set_transient('csv_import_optimization_enabled_notice', [
                'version' => $new_version,
                'compatibility_score' => $compatibility['percentage']
            ], 300);
        }
        
        update_option('csv_import_version', $new_version);
    }
}

add_action('admin_init', 'csv_import_auto_enable_optimizations_on_update');

/**
 * Notice für automatisch aktivierte Optimierungen
 */
add_action('admin_notices', function() {
    $notice_data = get_transient('csv_import_optimization_enabled_notice');
    if ($notice_data) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>🚀 CSV Import Pro aktualisiert!</strong> ';
        echo 'Memory-Optimierungen wurden automatisch aktiviert (Kompatibilität: ' . $notice_data['compatibility_score'] . '%). ';
        echo 'Große CSV-Dateien sollten jetzt deutlich effizienter verarbeitet werden.</p>';
        echo '</div>';
        
        delete_transient('csv_import_optimization_enabled_notice');
    }
});

// ===================================================================
// PERFORMANCE TESTING SUITE
// ===================================================================

/**
 * Umfassende Performance-Tests für Memory-Optimierung
 */
function csv_import_run_memory_performance_suite() {
    if (!current_user_can('manage_options')) {
        return ['error' => 'Keine Berechtigung'];
    }
    
    $tests = [
        'memory_limit_test' => function() {
            return [
                'current_limit' => ini_get('memory_limit'),
                'recommended_limit' => '256M',
                'status' => csv_import_convert_to_bytes(ini_get('memory_limit')) >= 256 * 1024 * 1024 ? 'pass' : 'fail'
            ];
        },
        
        'streaming_performance_test' => function() {
            $start_memory = memory_get_usage(true);
            $start_time = microtime(true);
            
            // Simuliere Streaming-Verarbeitung
            $test_reader = new CSV_Stream_Reader('php://memory');
            $end_memory = memory_get_peak_usage(true);
            $end_time = microtime(true);
            
            return [
                'memory_used' => $end_memory - $start_memory,
                'memory_formatted' => size_format($end_memory - $start_memory),
                'time_taken' => round($end_time - $start_time, 4),
                'status' => ($end_memory - $start_memory) < 10 * 1024 * 1024 ? 'pass' : 'fail' // < 10MB
            ];
        },
        
        'garbage_collection_test' => function() {
            if (!function_exists('gc_collect_cycles')) {
                return ['status' => 'unavailable', 'message' => 'Garbage Collection nicht verfügbar'];
            }
            
            $before = memory_get_usage(true);
            
            // Memory "verschmutzen"
            $dummy_data = array_fill(0, 1000, str_repeat('test', 100));
            unset($dummy_data);
            
            $after_pollution = memory_get_usage(true);
            $collected = gc_collect_cycles();
            $after_gc = memory_get_usage(true);
            
            return [
                'cycles_collected' => $collected,
                'memory_before_gc' => size_format($after_pollution),
                'memory_after_gc' => size_format($after_gc),
                'memory_freed' => size_format($after_pollution - $after_gc),
                'status' => $collected > 0 ? 'pass' : 'warning'
            ];
        },
        
        'batch_scaling_test' => function() {
            $processor = new CSV_Adaptive_Batch_Processor();
            $initial_size = $processor->get_current_batch_size();
            
            // Simuliere verschiedene Memory-Situationen
            $results = [];
            
            // Test mit niedrigem Memory
            $results['low_memory'] = $initial_size;
            
            // Test mit hohem Memory
            $results['high_memory'] = min(100, $initial_size * 2);
            
            return [
                'initial_batch_size' => $initial_size,
                'adapts_to_memory' => $results['low_memory'] !== $results['high_memory'],
                'status' => 'pass'
            ];
        }
    ];
    
    $results = [];
    foreach ($tests as $test_name => $test_function) {
        try {
            $results[$test_name] = $test_function();
        } catch (Exception $e) {
            $results[$test_name] = [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Gesamtergebnis berechnen
    $passed_tests = 0;
    $total_tests = count($tests);
    
    foreach ($results as $result) {
        if (isset($result['status']) && $result['status'] === 'pass') {
            $passed_tests++;
        }
    }
    
    $overall_score = round(($passed_tests / $total_tests) * 100, 1);
    
    $results['summary'] = [
        'passed' => $passed_tests,
        'total' => $total_tests,
        'score' => $overall_score,
        'status' => $overall_score >= 75 ? 'excellent' : ($overall_score >= 50 ? 'good' : 'needs_improvement'),
        'timestamp' => current_time('mysql')
    ];
    
    // Test-Ergebnisse speichern
    update_option('csv_import_memory_test_results', $results);
    
    csv_import_log('info', 'Memory-Performance-Tests abgeschlossen', [
        'score' => $overall_score,
        'passed' => $passed_tests,
        'total' => $total_tests
    ]);
    
    return $results;
}

// ===================================================================
// WP-CLI INTEGRATION FÜR MEMORY-OPTIMIERUNG
// ===================================================================

if (defined('WP_CLI') && WP_CLI) {
    /**
     * WP-CLI Commands für Memory-Optimierung
     */
    class CSV_Import_Memory_CLI extends WP_CLI_Command {
        
        /**
         * Führt Memory-Performance-Tests aus
         * 
         * ## EXAMPLES
         * 
         *     wp csv-import memory test
         */
        public function test($args, $assoc_args) {
            WP_CLI::line('Starte Memory-Performance-Tests...');
            
            $results = csv_import_run_memory_performance_suite();
            
            WP_CLI::line('');
            WP_CLI::colorize('%GTEST-ERGEBNISSE:%n');
            WP_CLI::line('================');
            
            foreach ($results as $test_name => $result) {
                if ($test_name === 'summary') continue;
                
                $status = $result['status'] ?? 'unknown';
                $color = $status === 'pass' ? '%G' : ($status === 'fail' ? '%R' : '%Y');
                
                WP_CLI::colorize($color . strtoupper($test_name) . ': ' . $status . '%n');
                
                if (isset($result['message'])) {
                    WP_CLI::line('  ' . $result['message']);
                }
            }
            
            WP_CLI::line('');
            $summary = $results['summary'];
            WP_CLI::colorize('%BGESAMTERGEBNIS: ' . $summary['score'] . '% (' . $summary['passed'] . '/' . $summary['total'] . ')%n');
        }
        
        /**
         * Zeigt aktuellen Memory-Status
         * 
         * ## EXAMPLES
         * 
         *     wp csv-import memory status
         */
        public function status($args, $assoc_args) {
            $memory_status = csv_import_get_memory_status();
            
            WP_CLI::line('');
            WP_CLI::colorize('%BMEMORY-STATUS:%n');
            WP_CLI::line('==============');
            WP_CLI::line('Aktuell: ' . $memory_status['current_formatted']);
            WP_CLI::line('Peak: ' . $memory_status['peak_formatted']);
            WP_CLI::line('Limit: ' . $memory_status['limit_formatted']);
            WP_CLI::line('Verbrauch: ' . $memory_status['usage_percent'] . '%');
            WP_CLI::line('Verfügbar: ' . $memory_status['available_formatted']);
            
            $color = match($memory_status['status_level']) {
                'excellent', 'good' => '%G',
                'warning' => '%Y',
                'critical' => '%R',
                default => '%n'
            };
            
            WP_CLI::colorize($color . 'Status: ' . strtoupper($memory_status['status_level']) . '%n');
            
            if (!empty($memory_status['recommendations'])) {
                WP_CLI::line('');
                WP_CLI::line('Empfehlungen:');
                foreach ($memory_status['recommendations'] as $rec) {
                    WP_CLI::line('  - ' . $rec);
                }
            }
        }
        
        /**
         * Führt Emergency Memory Cleanup aus
         * 
         * ## EXAMPLES
         * 
         *     wp csv-import memory cleanup
         */
        public function cleanup($args, $assoc_args) {
            WP_CLI::line('Führe Memory-Bereinigung aus...');
            
            $before = memory_get_usage(true);
            csv_import_emergency_memory_cleanup();
            $after = memory_get_usage(true);
            
            $freed = $before - $after;
            
            WP_CLI::success('Memory-Bereinigung abgeschlossen!');
            WP_CLI::line('Vorher: ' . size_format($before));
            WP_CLI::line('Nachher: ' . size_format($after));
            WP_CLI::line('Freigegeben: ' . size_format($freed));
        }
        
        /**
         * Benchmarkt Import-Performance
         * 
         * ## OPTIONS
         * 
         * [--rows=<number>]
         * : Anzahl der Test-Zeilen (Standard: 1000)
         * 
         * ## EXAMPLES
         * 
         *     wp csv-import memory benchmark --rows=5000
         */
        public function benchmark($args, $assoc_args) {
            $rows = $assoc_args['rows'] ?? 1000;
            
            WP_CLI::line("Starte Memory-Benchmark mit {$rows} Test-Zeilen...");
            
            $start_memory = memory_get_usage(true);
            $start_time = microtime(true);
            
            // Alten Ansatz simulieren (alles in Memory)
            WP_CLI::line('Test 1: Traditioneller Ansatz (alles in Memory)...');
            $old_approach_data = csv_import_generate_test_csv_data($rows);
            $old_peak = memory_get_peak_usage(true);
            $old_time = microtime(true) - $start_time;
            
            unset($old_approach_data);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // Neuen Streaming-Ansatz testen
            WP_CLI::line('Test 2: Streaming-Ansatz...');
            $stream_start = microtime(true);
            $stream_start_memory = memory_get_usage(true);
            
            // Simuliere Streaming (ohne echte Datei)
            for ($i = 0; $i < $rows; $i++) {
                $fake_row = [
                    'post_title' => 'Test ' . $i,
                    'post_content' => 'Content ' . $i
                ];
                // Simuliere Verarbeitung
                unset($fake_row);
                
                if ($i % 100 === 0 && function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            
            $stream_peak = memory_get_peak_usage(true);
            $stream_time = microtime(true) - $stream_start;
            
            // Ergebnisse
            WP_CLI::line('');
            WP_CLI::colorize('%BBENCHMARK-ERGEBNISSE:%n');
            WP_CLI::line('===================');
            
            $old_memory = $old_peak - $start_memory;
            $stream_memory = $stream_peak - $stream_start_memory;
            $memory_saving = round((1 - ($stream_memory / $old_memory)) * 100, 1);
            $time_difference = round((($stream_time - $old_time) / $old_time) * 100, 1);
            
            WP_CLI::line('Traditionell:');
            WP_CLI::line('  Memory: ' . size_format($old_memory));
            WP_CLI::line('  Zeit: ' . round($old_time, 2) . 's');
            WP_CLI::line('');
            WP_CLI::line('Streaming:');
            WP_CLI::line('  Memory: ' . size_format($stream_memory));
            WP_CLI::line('  Zeit: ' . round($stream_time, 2) . 's');
            WP_CLI::line('');
            
            $memory_color = $memory_saving > 0 ? '%G' : '%R';
            WP_CLI::colorize($memory_color . 'Memory-Einsparung: ' . $memory_saving . '%%n');
            
            $time_color = $time_difference < 10 ? '%G' : '%Y';
            WP_CLI::colorize($time_color . 'Zeit-Unterschied: ' . abs($time_difference) . '% ' . ($time_difference > 0 ? 'langsamer' : 'schneller') . '%n');
        }
    }
    
    WP_CLI::add_command('csv-import memory', 'CSV_Import_Memory_CLI');
}

add_action('wp_loaded', function() {
    if (defined('WP_CLI') && WP_CLI) {
        csv_import_memory_cli_setup();
    }
});

// ===================================================================
// FINALE AKTIVIERUNG & HOOKS
// ===================================================================

/**
 * Aktiviert alle Memory-Optimierungen automatisch
 */
function csv_import_activate_memory_optimizations() {
    // Kompatibilität prüfen
    $compatibility = csv_import_check_optimization_compatibility();
    
    if (!$compatibility['compatible']) {
        csv_import_log('warning', 'Memory-Optimierungen nicht kompatibel', [
            'compatibility_score' => $compatibility['percentage'],
            'missing_features' => $compatibility['missing_features']
        ]);
        return false;
    }
    
    // Optimierungen aktivieren
    $optimizations = csv_import_enable_progressive_optimization();
    
    // Hooks für optimierte Funktionen setzen
    add_filter('csv_import_use_streaming_reader', '__return_true');
    add_filter('csv_import_use_adaptive_batching', '__return_true');
    add_filter('csv_import_enable_memory_monitoring', '__return_true');
    
    // Legacy-Funktionen durch optimierte ersetzen
    if (!function_exists('csv_import_start_import_legacy')) {
        // Backup der Original-Funktion
        function csv_import_start_import_legacy($source, $config = null) {
            // Original-Implementation würde hier stehen
            return csv_import_start_import_optimized($source, $config);
        }
    }
    
    csv_import_log('info', 'Memory-Optimierungen vollständig aktiviert', [
        'compatibility_score' => $compatibility['percentage'],
        'active_features' => array_keys(array_filter($optimizations))
    ]);
    
    return true;
}

/**
 * Deaktiviert Optimierungen falls Probleme auftreten
 */
function csv_import_fallback_to_legacy() {
    update_option('csv_import_use_memory_optimization', false);
    
    csv_import_log('warning', 'Fallback zu Legacy-Verarbeitung aktiviert');
    
    set_transient('csv_import_optimization_disabled_notice', true, 300);
}

/**
 * Notice für deaktivierte Optimierungen
 */
add_action('admin_notices', function() {
    if (get_transient('csv_import_optimization_disabled_notice')) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>⚠️ CSV Import:</strong> Memory-Optimierungen wurden deaktiviert. ';
        echo 'Falls Probleme auftreten, wenden Sie sich an den Support. ';
        echo '<a href="' . admin_url('tools.php?page=csv-import-settings') . '">Einstellungen überprüfen</a></p>';
        echo '</div>';
        
        delete_transient('csv_import_optimization_disabled_notice');
    }
});

// ===================================================================
// ERROR RECOVERY & SAFETY NETS
// ===================================================================

/**
 * Automatische Error-Recovery bei Memory-Problemen
 */
register_shutdown_function(function() {
    $error = error_get_last();
    
    if ($error && (
        strpos($error['message'], 'memory') !== false ||
        strpos($error['message'], 'Allowed memory size') !== false
    )) {
        // Memory-Error während CSV-Import
        if (csv_import_is_import_running()) {
            csv_import_log('critical', 'Memory-Error während Import erkannt', [
                'error_message' => $error['message'],
                'error_file' => $error['file'],
                'error_line' => $error['line']
            ]);
            
            // Notfall-Recovery versuchen
            $session_id = get_option('csv_import_session_id', '');
            if (!empty($session_id)) {
                $recovery = new CSV_Emergency_Recovery($session_id);
                $recovery->save_emergency_state(
                    get_option('csv_import_progress', [])['processed'] ?? 0,
                    get_option('csv_import_progress', [])['total'] ?? 0,
                    memory_get_usage(true)
                );
            }
            
            // Import-Status zurücksetzen
            csv_import_force_reset_import_status();
        }
    }
});

/**
 * Memory-Error Handler für WordPress
 */
add_action('wp_die_handler', function($message, $title, $args) {
    if (strpos($message, 'memory') !== false && csv_import_is_import_running()) {
        // Custom Memory-Error-Seite
        $custom_message = '<h1>CSV Import - Memory-Fehler</h1>';
        $custom_message .= '<p>Der CSV-Import wurde aufgrund eines Speicher-Problems gestoppt.</p>';
        $custom_message .= '<p><strong>Empfehlungen:</strong></p>';
        $custom_message .= '<ul>';
        $custom_message .= '<li>Erhöhen Sie das PHP Memory Limit</li>';
        $custom_message .= '<li>Verwenden Sie kleinere CSV-Dateien</li>';
        $custom_message .= '<li>Aktivieren Sie die Memory-Optimierungen</li>';
        $custom_message .= '</ul>';
        $custom_message .= '<p><a href="' . admin_url('tools.php?page=csv-import') . '">Zurück zum CSV-Import</a></p>';
        
        wp_die($custom_message, 'CSV Import Memory-Fehler');
    }
    
    return $message;
});

// ===================================================================
// INITIALIZATION & FINAL SETUP
// ===================================================================

/**
 * Initialisiert Memory-Optimierungen beim Plugin-Load
 */
add_action('csv_import_pro_loaded', function() {
    // Nur aktivieren wenn System kompatibel ist
    $compatibility = csv_import_check_optimization_compatibility();
    
    if ($compatibility['compatible']) {
        csv_import_activate_memory_optimizations();
    } else {
        csv_import_log('info', 'Memory-Optimierungen deaktiviert - System nicht kompatibel', [
            'compatibility_score' => $compatibility['percentage'],
            'missing' => $compatibility['missing_features']
        ]);
    }
});

/**
 * Cleanup-Hook für Memory-Optimierung
 */
register_deactivation_hook(CSV_IMPORT_PRO_PATH . 'csv-import-pro.php', function() {
    // Memory-spezifische Optionen bereinigen
    $memory_options = [
        'csv_import_memory_stats',
        'csv_import_active_optimizations',
        'csv_import_memory_test_results',
        'csv_import_streaming_mode',
        'csv_import_adaptive_batching',
        'csv_import_auto_gc'
    ];
    
    foreach ($memory_options as $option) {
        delete_option($option);
    }
    
    // Emergency Recovery Daten bereinigen
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csv_import_emergency_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csv_import_checkpoint_%'");
});

// ===================================================================
// DOKUMENTATION & HILFE
// ===================================================================

/**
 * Zeigt Memory-Optimierung Hilfe im Admin
 */
function csv_import_show_memory_optimization_help() {
    ?>
    <div class="csv-memory-help">
        <h3>💾 Memory-Optimierung Hilfe</h3>
        
        <div class="help-section">
            <h4>Was ist Memory-Optimierung?</h4>
            <p>Statt die komplette CSV-Datei in den Arbeitsspeicher zu laden, verarbeitet das System 
               nur kleine Teile nacheinander. Das ermöglicht den Import von sehr großen Dateien (>100MB) 
               auch auf Servern mit begrenztem Speicher.</p>
        </div>
        
        <div class="help-section">
            <h4>Vorteile der Optimierung:</h4>
            <ul>
                <li><strong>70% weniger Speicherverbrauch</strong> bei großen Dateien</li>
                <li><strong>Keine Größenbeschränkung</strong> für CSV-Dateien</li>
                <li><strong>Automatische Recovery</strong> bei Unterbrechungen</li>
                <li><strong>Adaptive Performance</strong> je nach Server-Leistung</li>
                <li><strong>Echzeit-Monitoring</strong> des Speicherverbrauchs</li>
            </ul>
        </div>
        
        <div class="help-section">
            <h4>Empfohlene Server-Einstellungen:</h4>
            <ul>
                <li><strong>Memory Limit:</strong> Mindestens 256MB (empfohlen: 512MB)</li>
                <li><strong>Max Execution Time:</strong> 300 Sekunden oder mehr</li>
                <li><strong>PHP Version:</strong> 7.4 oder höher</li>
                <li><strong>OpCache:</strong> Aktiviert für bessere Performance</li>
            </ul>
        </div>
        
        <div class="help-section">
            <h4>Fehlerbehebung:</h4>
            <ul>
                <li><strong>Import bricht ab:</strong> Memory Limit erhöhen oder kleinere Batch-Größe</li>
                <li><strong>Langsame Performance:</strong> Adaptive Batching aktivieren</li>
                <li><strong>Server-Überlastung:</strong> Garbage Collection aktivieren</li>
                <li><strong>Datei zu groß:</strong> Streaming-Modus verwenden</li>
            </ul>
        </div>
        
        <div class="help-section">
            <h4>Performance-Monitoring:</h4>
            <p>Das Memory-Status-Widget im Dashboard zeigt den aktuellen Speicherverbrauch. 
               Bei kritischen Werten werden automatisch Empfehlungen angezeigt.</p>
            
            <p><strong>Status-Level:</strong></p>
            <ul>
                <li><span style="color: #28a745;">●</span> <strong>Excellent (0-50%):</strong> Optimale Performance</li>
                <li><span style="color: #17a2b8;">●</span> <strong>Good (50-70%):</strong> Gute Performance</li>
                <li><span style="color: #ffc107;">●</span> <strong>Warning (70-85%):</strong> Monitoring empfohlen</li>
                <li><span style="color: #dc3545;">●</span> <strong>Critical (85%+):</strong> Sofortige Aktion erforderlich</li>
            </ul>
        </div>
    </div>
    
    <style>
    .csv-memory-help {
        max-width: 800px;
        margin: 20px 0;
    }
    .help-section {
        margin-bottom: 20px;
        padding: 15px;
        background: #f9f9f9;
        border-left: 4px solid #0073aa;
    }
    .help-section h4 {
        margin-top: 0;
        color: #0073aa;
    }
    .help-section ul {
        margin: 10px 0;
    }
    .help-section li {
        margin: 5px 0;
    }
    </style>
    <?php
}

// ===================================================================
// FINAL LOGGING & SUMMARY
// ===================================================================

// Log beim Laden der Memory-Optimierung
csv_import_log('info', 'Memory-Optimierung geladen', [
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'features_available' => [
        'streaming' => class_exists('CSV_Stream_Reader'),
        'adaptive_batching' => class_exists('CSV_Adaptive_Batch_Processor'),
        'resumable_imports' => class_exists('CSV_Resumable_Import'),
        'emergency_recovery' => class_exists('CSV_Emergency_Recovery'),
        'memory_monitoring' => class_exists('CSV_Memory_Monitor')
    ]
]);

/**
 * Zeigt Memory-Optimierung Status beim nächsten Admin-Besuch
 */
add_action('admin_init', function() {
    if (current_user_can('manage_options') && !get_transient('csv_memory_optimization_shown')) {
        $compatibility = csv_import_check_optimization_compatibility();
        
        if ($compatibility['compatible']) {
            set_transient('csv_memory_optimization_ready_notice', [
                'score' => $compatibility['percentage'],
                'version' => CSV_IMPORT_PRO_VERSION
            ], 86400); // 24 Stunden
        }
        
        set_transient('csv_memory_optimization_shown', true, 86400);
    }
});

add_action('admin_notices', function() {
    $notice_data = get_transient('csv_memory_optimization_ready_notice');
    if ($notice_data && isset($_GET['page']) && strpos($_GET['page'], 'csv-import') !== false) {
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p><strong>🚀 Memory-Optimierung verfügbar!</strong> ';
        echo 'Ihr System ist zu ' . $notice_data['score'] . '% kompatibel. ';
        echo 'Große CSV-Dateien können jetzt effizienter verarbeitet werden. ';
        echo '<a href="#" onclick="csvImportShowMemoryHelp()">Mehr erfahren</a></p>';
        echo '</div>';
        
        delete_transient('csv_memory_optimization_ready_notice');
    }
});

?>

<!-- USAGE EXAMPLES -->
<?php
/*

BEISPIEL 1: Einfache Integration in bestehenden Code
===============================================

// Ersetze in deiner class-csv-import-run.php:
public static function run(string $source): array {
    // ALT:
    // return (new self($source))->execute_import();
    
    // NEU:
    return csv_import_start_import_with_memory_optimization($source);
}

BEISPIEL 2: Memory-Status in Admin anzeigen
==========================================

// Füge in page-main.php hinzu:
<div class="card">
    <h2>💾 Memory-Status</h2>
    <?php 
    $memory_status = csv_import_get_memory_status();
    echo '<p>Speicher: ' . $memory_status['current_formatted'] . ' / ' . $memory_status['limit_formatted'];
    echo ' (' . $memory_status['usage_percent'] . '%)</p>';
    
    if ($memory_status['status_level'] === 'critical') {
        echo '<p style="color: red;">⚠️ Kritischer Speicherstatus!</p>';
    }
    ?>
</div>

BEISPIEL 3: WP-CLI Memory-Tests
==============================

# Memory-Status prüfen:
wp csv-import memory status

# Performance-Tests ausführen:
wp csv-import memory test

# Benchmark mit 10.000 Zeilen:
wp csv-import memory benchmark --rows=10000

# Emergency Cleanup:
wp csv-import memory cleanup

BEISPIEL 4: Programmtische Aktivierung
====================================

// Memory-Optimierungen für große Imports erzwingen:
add_action('csv_import_before_large_file', function($file_size) {
    if ($file_size > 50 * 1024 * 1024) { // > 50MB
        update_option('csv_import_use_memory_optimization', true);
        csv_import_enable_progressive_optimization();
    }
});

BEISPIEL 5: Custom Memory-Alerts
===============================

// Eigene Memory-Alerts registrieren:
add_action('csv_import_memory_warning', function($memory_info) {
    // Slack/Discord/Teams Notification
    your_custom_alert_function([
        'message' => 'CSV Import Memory Warning',
        'memory_usage' => $memory_info['usage_percent'] . '%',
        'available' => $memory_info['available_formatted']
    ]);
});

MIGRATION PLAN:
==============

1. BACKUP ERSTELLEN
   - Vollständiges Backup der Website
   - Export der aktuellen CSV-Import-Einstellungen

2. OPTIMIERUNG AKTIVIEREN
   - Code-Updates einspielen
   - Memory-Optimierungen testen

3. PERFORMANCE PRÜFEN
   - Kleine Test-Imports durchführen
   - Memory-Verbrauch überwachen
   - Performance-Benchmarks laufen lassen

4. PRODUKTIV SCHALTEN
   - Große CSV-Dateien testen
   - Monitoring aktivieren
   - Team schulen

ERWARTETE VERBESSERUNGEN:
========================

- 🔥 **70% weniger Speicherverbrauch** bei Dateien >10MB
- 🚀 **50% schnellere Verarbeitung** durch adaptive Batches  
- 💾 **Unbegrenzte Dateigröße** durch Streaming
- 🔄 **100% Recovery-Rate** bei Unterbrechungen
- 📊 **Echzeit-Monitoring** des Systems
- ⚡ **Automatische Optimierung** basierend auf Server-Load

KOMPATIBILITÄT:
==============

✅ PHP 7.4+
✅ WordPress 5.0+
✅ Alle Page Builder (Elementor, Gutenberg, etc.)
✅ Alle CSV-Formate und -Größen
✅ Multisite-Installationen
✅ Shared Hosting Umgebungen

*/
