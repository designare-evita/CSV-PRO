<?php
/**
 * Die zentrale Klasse zur Durchführung des CSV-Imports.
 * Korrigierte Version - kompatibel mit core-functions.php
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CSV_Import_Pro_Run {

	private array $config;
	private ?object $template_post = null;
	private string $session_id;
	private array $existing_slugs = [];
	private string $source;
	private array $csv_data = [];

	private function __construct( string $source ) {
		$this->source     = $source;
		$this->session_id = 'run_' . time() . '_' . uniqid();
	}

	public static function run( string $source ): array {
		$importer = new self( $source );
		return $importer->execute_import();
	}

	private function execute_import(): array {
		if ( class_exists( 'CSV_Import_Error_Handler' ) && method_exists( 'CSV_Import_Error_Handler', 'clear_error_log' ) ) {
			// Optional: Alte Logs vor einem neuen Lauf löschen
		}
		
		do_action( 'csv_import_start' );
		update_option( 'csv_import_session_id', $this->session_id );
		
		try {
			$this->load_and_validate_config();
			$this->set_system_limits();

			// CSV-Daten über core-functions laden
			$this->csv_data = csv_import_load_csv_data( $this->source, $this->config );
			
			if ( empty( $this->csv_data['data'] ) ) {
				throw new Exception( 'CSV muss mindestens Header und eine Datenzeile enthalten.' );
			}
			
			$header = $this->csv_data['headers'];
			$data_rows = $this->csv_data['data'];
			
			$this->validate_header( $header );
			update_option( 'csv_import_current_header', implode( ',', $header ) );

			csv_import_log( 'info', "CSV-Import gestartet: " . count( $data_rows ) . " Zeilen." );
			
			$results = $this->process_batches( $data_rows, $header );

			$message = sprintf( 
				'Import erfolgreich: %d Posts erstellt, %d Duplikate übersprungen, %d Fehler.', 
				$results['created'], 
				$results['skipped'], 
				$results['errors'] 
			);
			
			$final_result = [ 
				'success' => true, 
				'message' => $message,
				'processed' => $results['created'],
				'total' => count( $data_rows ),
				'errors' => $results['errors']
			];
			
			do_action( 'csv_import_completed', $final_result, $this->source );
			csv_import_log( 'info', $message );
			
			$this->cleanup_after_import();
			return $final_result;

		} catch ( Exception $e ) {
			$this->cleanup_after_import( true );
			$error_message = 'Kritischer Import-Fehler: ' . $e->getMessage();
			csv_import_log( 'critical', $error_message, [ 'source' => $this->source ] );
			do_action( 'csv_import_failed', $error_message, $this->source );
			
			return [ 
				'success' => false, 
				'message' => $e->getMessage(),
				'processed' => 0,
				'total' => count($this->csv_data['data'] ?? []),
				'errors' => 1
			];
		}
	}

	private function load_and_validate_config(): void {
		$this->config = csv_import_get_config();
		
		if ( empty( $this->config['post_type'] ) || ! post_type_exists( $this->config['post_type'] ) ) {
			throw new Exception( 'Post-Typ ist nicht konfiguriert oder existiert nicht: ' . esc_html($this->config['post_type']) );
		}
		
		if ( ! empty( $this->config['template_id'] ) ) {
			$this->template_post = get_post( (int) $this->config['template_id'] );
			if ( ! $this->template_post ) {
				throw new Exception( 'Template Post nicht gefunden: ID ' . (int) $this->config['template_id'] );
			}
		}
	}

	private function process_batches( array $rows, array $header ): array {
		$results = ['created' => 0, 'skipped' => 0, 'errors' => 0];
		$total_rows = count( $rows );
		
		$required_columns = $this->config['required_columns'] ?? [];
		if ( is_string( $required_columns ) ) {
			$required_columns = array_filter( array_map( 'trim', explode( "\n", $required_columns ) ) );
		}
		
		$column_validation = csv_import_validate_required_columns( $header, $required_columns );
		if ( ! $column_validation['valid'] ) {
			throw new Exception( 'Erforderliche Spalten fehlen: ' . implode( ', ', $column_validation['missing'] ) );
		}
		
		foreach ( $rows as $index => $row_data ) {
            csv_import_update_progress( $index, $total_rows, 'processing' );
            
			try {
				$post_result = $this->process_single_row( $row_data );
				
				if ( $post_result === 'created' ) {
					$results['created']++;
				} elseif ( $post_result === 'skipped' ) {
					$results['skipped']++;
				}
				
			} catch ( Exception $e ) {
				$results['errors']++;
				$error_msg = "Zeile " . ($index + 2) . ": " . $e->getMessage();
				csv_import_log( 'warning', $error_msg, ['row_data' => $row_data]);
				
				if ( $results['errors'] > 50 ) {
					csv_import_log( 'error', 'Import abgebrochen - zu viele Fehler (>50)' );
					break;
				}
			}
            
            if ($index % 10 === 0) {
                usleep(50000); // 0.05 Sekunden Pause
            }
		}
		
		return $results;
	}

	private function process_single_row( array $data ): string {
		$post_title = $this->sanitize_title( $data['post_title'] ?? $data['title'] ?? '' );
		
		if ( empty( $post_title ) ) {
			throw new Exception( 'Post-Titel ist erforderlich' );
		}
		
		if ( ! empty( $this->config['skip_duplicates'] ) ) {
			$existing_post = get_page_by_title( $post_title, OBJECT, $this->config['post_type'] );
			if ( $existing_post ) {
				return 'skipped';
			}
		}
		
		$post_id = $this->create_post_transaction( $data );
		
		return $post_id ? 'created' : 'skipped';
	}

	private function create_post_transaction( array $data ): ?int {
        $post_title = $this->sanitize_title( $data['post_title'] ?? $data['title'] ?? '' );
		$post_slug = $this->generate_unique_slug( $post_title );

		$post_data = [
			'post_title'   => $post_title,
			'post_content' => $data['post_content'] ?? $data['content'] ?? '',
			'post_excerpt' => $data['post_excerpt'] ?? $data['excerpt'] ?? '',
			'post_name'    => $post_slug,
			'post_status'  => $this->config['post_status'] ?? 'draft',
			'post_type'    => $this->config['post_type'],
			'meta_input'   => [
				'_csv_import_session' => $this->session_id,
				'_csv_import_date' => current_time( 'mysql' ),
			]
		];
		
		if ( $this->template_post && ! empty( $this->config['page_builder'] ) && $this->config['page_builder'] !== 'none' ) {
			$post_data['post_content'] = $this->apply_template( $data );
		}
		
		$post_id = wp_insert_post( $post_data, true );
		
		if ( is_wp_error( $post_id ) ) {
			throw new Exception( 'WordPress Fehler: ' . $post_id->get_error_message() );
		}
		
		$this->add_meta_fields( $post_id, $data );
		
		if ( ! empty( $this->config['image_source'] ) && $this->config['image_source'] !== 'none' ) {
			$this->process_post_images( $post_id, $data );
		}
		
		return $post_id;
	}

	private function validate_header( array $header ): void {
		if ( empty( $header ) ) {
			throw new Exception( 'CSV-Header ist leer' );
		}
		
		$title_fields = ['post_title', 'title'];
		if ( !array_intersect($title_fields, $header) ) {
			throw new Exception( 'CSV muss eine "post_title" oder "title" Spalte enthalten' );
		}
	}

	private function set_system_limits(): void {
		if ( ! empty( $this->config['memory_limit'] ) ) {
			@ini_set( 'memory_limit', $this->config['memory_limit'] );
		}
		if ( ! empty( $this->config['time_limit'] ) ) {
			@set_time_limit( (int) $this->config['time_limit'] );
		}
	}
	
	private function cleanup_after_import( bool $is_error = false ): void {
		delete_option( 'csv_import_current_header' );
		delete_option( 'csv_import_session_id' );
		
		if ( ! $is_error ) {
			csv_import_update_progress( 0, 0, 'completed' );
		} else {
			csv_import_clear_progress();
		}
	}
	
	private function sanitize_title( string $title ): string {
		return html_entity_decode( wp_strip_all_tags( trim( $title ) ), ENT_QUOTES, 'UTF-8' );
	}
	
	private function generate_unique_slug( string $title ): string {
		$slug = sanitize_title( $title );
		$original_slug = $slug ?: 'csv-import-post';
        $counter = 1;
		
        while ( get_page_by_path( $slug, OBJECT, $this->config['post_type'] ) ) {
            $slug = $original_slug . '-' . $counter++;
        }
		
		return $slug;
	}
	
	private function apply_template( array $data ): string {
		if ( ! $this->template_post ) {
			return $data['post_content'] ?? '';
		}
		
		$content = $this->template_post->post_content;
		foreach ( $data as $key => $value ) {
			$content = str_replace( '{{' . $key . '}}', $value, $content );
		}
		
		return $content;
	}
	
	private function add_meta_fields( int $post_id, array $data ): void {
		$skip_fields = ['post_title', 'title', 'post_content', 'content', 'post_excerpt', 'excerpt', 'post_name'];
		
		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $skip_fields ) && ! empty( $value ) ) {
				$meta_key = sanitize_key( $key );
				if ( strpos( $meta_key, '_' ) !== 0 ) {
					$meta_key = '_' . $meta_key;
				}
				update_post_meta( $post_id, $meta_key, sanitize_text_field( $value ) );
			}
		}
	}
	
	private function process_post_images( int $post_id, array $data ): void {
		$image_fields = ['image', 'featured_image', 'thumbnail', 'post_image'];
		$image_url = '';
		
		foreach ( $image_fields as $field ) {
			if ( ! empty( $data[ $field ] ) ) {
				$image_url = $data[ $field ];
				break;
			}
		}
		
		if ( empty( $image_url ) ) return;
		
		try {
			$attachment_id = csv_import_download_and_attach_image( $image_url, $post_id );
			if ( $attachment_id ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		} catch ( Exception $e ) {
			csv_import_log( 'warning', "Bild-Fehler für Post {$post_id}: " . $e->getMessage() );
		}
	}
}
