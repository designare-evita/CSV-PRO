<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff auf die Datei verhindern
}

/**
 * Erstellt die Admin-Menüs und steuert die Anzeige der Plugin-Seiten.
 * 
 * Version 8.0 - Tools-Menü Integration mit korrigierten Berechtigungen
 * 
 * @since 6.0
 */
class CSV_Import_Pro_Admin {

	/**
	 * Plugin-Slug für Menüs
	 * 
	 * @var string
	 */
	private $menu_slug = 'csv-import';

	/**
	 * Menü-Konfiguration
	 * 
	 * @var array
	 */
	private $menu_config = [];

	/**
	 * Admin-Seiten Registry
	 * 
	 * @var array
	 */
	private $admin_pages = [];

	/**
	 * Konstruktor - Initialisiert Admin-Komponenten
	 */
	public function __construct() {
		$this->setup_menu_config();
		$this->init_hooks();
	}

	/**
	 * Konfiguriert Menü-Struktur - KORRIGIERT für Tools-Menü
	 */
	private function setup_menu_config() {
		$this->menu_config = [
			'main' => [
				'page_title' => __( 'CSV Import Pro', 'csv-import' ),
				'menu_title' => __( 'CSV Import', 'csv-import' ),
				'capability' => 'edit_pages', // KORRIGIERT: Weniger restriktiv
				'menu_slug'  => $this->menu_slug,
				'callback'   => [ $this, 'display_main_page' ],
				'parent'     => 'tools.php' // NEU: Unter Tools-Menü
			],
			'submenus' => [
				'import' => [
					'page_title' => __( 'CSV Import Dashboard', 'csv-import' ),
					'menu_title' => __( 'Import Dashboard', 'csv-import' ),
					'menu_slug'  => $this->menu_slug,
					'capability' => 'edit_pages', // KORRIGIERT
					'callback'   => [ $this, 'display_main_page' ]
				],
				'settings' => [
					'page_title' => __( 'CSV Import Einstellungen', 'csv-import' ),
					'menu_title' => __( 'Einstellungen', 'csv-import' ),
					'menu_slug'  => 'csv-import-settings',
					'capability' => 'edit_pages', // KORRIGIERT: Auch für Einstellungen
					'callback'   => [ $this, 'display_settings_page' ]
				],
				'backups' => [
					'page_title' => __( 'CSV Import Backups', 'csv-import' ),
					'menu_title' => __( 'Backups & Rollback', 'csv-import' ),
					'menu_slug'  => 'csv-import-backups', 
					'capability' => 'edit_pages', // KORRIGIERT
					'callback'   => [ $this, 'display_backup_page' ]
				],
				'profiles' => [
					'page_title' => __( 'CSV Import Profile', 'csv-import' ),
					'menu_title' => __( 'Import-Profile', 'csv-import' ),
					'menu_slug'  => 'csv-import-profiles',
					'capability' => 'edit_pages', // KORRIGIERT
					'callback'   => [ $this, 'display_profiles_page' ]
				],
				'scheduling' => [
					'page_title' => __( 'CSV Import Scheduling', 'csv-import' ),
					'menu_title' => __( 'Automatisierung', 'csv-import' ),
					'menu_slug'  => 'csv-import-scheduling',
					'capability' => 'manage_options', // Bleibt restriktiv für Cron-Jobs
					'callback'   => [ $this, 'display_scheduling_page' ]
				],
				'logs' => [
					'page_title' => __( 'CSV Import Logs', 'csv-import' ),
					'menu_title' => __( 'Logs & Monitoring', 'csv-import' ),
					'menu_slug'  => 'csv-import-logs',
					'capability' => 'edit_pages', // KORRIGIERT
					'callback'   => [ $this, 'display_logs_page' ]
				]
			]
		];

		// Filter für Anpassungen durch andere Plugins/Themes
		$this->menu_config = apply_filters( 'csv_import_menu_config', $this->menu_config );
	}

	/**
	 * Initialisiert WordPress-Hooks
	 */
	private function init_hooks() {
		// Admin-Menü registrieren
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ], 10 );
		
		// Assets nur auf Plugin-Seiten laden
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		
		// Plugin-Einstellungen registrieren
		add_action( 'admin_init', [ $this, 'register_plugin_settings' ] );
		
		// Admin-Notices für Plugin-spezifische Meldungen
		add_action( 'admin_notices', [ $this, 'show_plugin_notices' ] );
		
		// AJAX-Handler für Admin-Funktionen
		add_action( 'wp_ajax_csv_import_admin_action', [ $this, 'handle_admin_ajax' ] );
		
		// Plugin-Links in der Plugin-Liste
		add_filter( 'plugin_action_links_' . CSV_IMPORT_PRO_BASENAME, [ $this, 'add_plugin_action_links' ] );
		
		// Plugin-Meta-Links
		add_filter( 'plugin_row_meta', [ $this, 'add_plugin_meta_links' ], 10, 2 );

		// Admin-Footer-Text anpassen auf Plugin-Seiten
		add_filter( 'admin_footer_text', [ $this, 'admin_footer_text' ] );

		// KORRIGIERT: Permission-Check-Hook hinzufügen
		add_action( 'current_screen', [ $this, 'check_page_permissions' ] );
	}

	/**
	 * Registriert das Admin-Menü unter Tools - VOLLSTÄNDIG NEU
	 */
	public function register_admin_menu() {
		$main_config = $this->menu_config['main'];
		
		// KORRIGIERT: Hauptseite unter Tools-Menü hinzufügen
		$main_page = add_management_page(
			$main_config['page_title'],  // Page title
			$main_config['menu_title'],  // Menu title  
			$main_config['capability'],  // Capability
			$main_config['menu_slug'],   // Menu slug
			$main_config['callback']     // Callback function
		);

		// Hook für Hauptseite
		add_action( "load-{$main_page}", [ $this, 'load_main_page' ] );
		$this->admin_pages['main'] = $main_page;

		// KORRIGIERT: Untermenüs als separate Tools-Seiten hinzufügen
		foreach ( $this->menu_config['submenus'] as $submenu_key => $submenu_config ) {
			// Skip das Import-Dashboard (ist die Hauptseite)
			if ( $submenu_key === 'import' ) {
				continue;
			}

			$submenu_page = add_management_page(
				$submenu_config['page_title'], // Page title
				'CSV ' . $submenu_config['menu_title'], // Menu title mit Prefix
				$submenu_config['capability'], // Capability
				$submenu_config['menu_slug'],  // Menu slug
				$submenu_config['callback']    // Callback
			);

			// Hook für Unterseite
			if ( $submenu_page ) {
				add_action( "load-{$submenu_page}", [ $this, 'load_submenu_page' ] );
				$this->admin_pages[$submenu_key] = $submenu_page;
			}
		}

		// KORRIGIERT: Entwickler-Tools nur bei aktiviertem Debug und Admin-Berechtigung
		if ( defined( 'CSV_IMPORT_DEBUG' ) && CSV_IMPORT_DEBUG && current_user_can( 'manage_options' ) ) {
			$debug_page = add_management_page(
				__( 'CSV Import Debug', 'csv-import' ),
				__( 'CSV Debug', 'csv-import' ),
				'manage_options', // Debug bleibt restriktiv
				'csv-import-debug',
				[ $this, 'display_debug_page' ]
			);
			
			if ( $debug_page ) {
				$this->admin_pages['debug'] = $debug_page;
			}
		}

		// KORRIGIERT: Dashboard-Widget nur für berechtigt Benutzer
		if ( current_user_can( 'edit_pages' ) ) {
			$this->maybe_add_dashboard_widget();
		}
	}

	/**
	 * NEU: Permission-Check für Plugin-Seiten
	 */
	public function check_page_permissions() {
		$screen = get_current_screen();
		
		if ( ! $screen ) {
			return;
		}

		// Prüfen ob es eine unserer Plugin-Seiten ist
		$csv_import_pages = [
			'tools_page_csv-import',
			'tools_page_csv-import-settings',
			'tools_page_csv-import-backups',
			'tools_page_csv-import-profiles',
			'tools_page_csv-import-scheduling',
			'tools_page_csv-import-logs',
			'tools_page_csv-import-debug'
		];

		if ( in_array( $screen->id, $csv_import_pages ) ) {
			// Mindest-Berechtigung prüfen
			if ( ! current_user_can( 'edit_pages' ) ) {
				wp_die( 
					__( 'Sie haben keine Berechtigung, auf diese Seite zuzugreifen.', 'csv-import' ),
					__( 'Keine Berechtigung', 'csv-import' ),
					[ 'response' => 403 ]
				);
			}

			// Spezielle Berechtigung für kritische Seiten
			if ( $screen->id === 'tools_page_csv-import-scheduling' && ! current_user_can( 'manage_options' ) ) {
				wp_die( 
					__( 'Für die Automatisierung sind Administrator-Rechte erforderlich.', 'csv-import' ),
					__( 'Erweiterte Berechtigung erforderlich', 'csv-import' ),
					[ 'response' => 403 ]
				);
			}
		}
	}

	/**
	 * Lädt Assets nur auf Plugin-Seiten - KORRIGIERT
	 * 
	 * @param string $hook_suffix
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		// KORRIGIERT: Tools-Seiten-Pattern prüfen
		if ( ! $this->is_plugin_page( $hook_suffix ) ) {
			return;
		}

		$version = defined( 'CSV_IMPORT_PRO_VERSION' ) ? CSV_IMPORT_PRO_VERSION : '1.0.0';
		$is_debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG;
		$suffix = $is_debug ? '' : '.min';

		// CSS einbinden
		wp_enqueue_style(
			'csv-import-pro-admin-style',
			CSV_IMPORT_PRO_URL . "assets/css/admin-style{$suffix}.css",
			[],
			$version
		);

		// JavaScript einbinden
		wp_enqueue_script(
			'csv-import-pro-admin-script',
			CSV_IMPORT_PRO_URL . "assets/js/admin-script{$suffix}.js",
			[ 'jquery', 'wp-util', 'wp-api' ],
			$version,
			true
		);

		// Spezielle Scripts für bestimmte Seiten
		$this->enqueue_page_specific_assets( $hook_suffix );

		// JavaScript-Variablen - KORRIGIERT
		wp_localize_script( 'csv-import-pro-admin-script', 'csvImportAjax', [
			'ajaxurl'            => admin_url( 'admin-ajax.php' ),
			'nonce'              => wp_create_nonce( 'csv_import_ajax' ),
			'admin_nonce'        => wp_create_nonce( 'csv_import_admin_action' ),
			'strings'            => $this->get_js_strings(),
			'config'             => $this->get_js_config(),
			'debug'              => defined( 'CSV_IMPORT_DEBUG' ) && CSV_IMPORT_DEBUG,
			'current_page'       => $this->get_current_page_slug( $hook_suffix ),
			'user_can_import'    => current_user_can( 'edit_pages' ), // KORRIGIERT
			'user_can_admin'     => current_user_can( 'manage_options' ),
			'import_running'     => function_exists( 'csv_import_is_import_running' ) ? csv_import_is_import_running() : false,
			'system_status'      => $this->get_system_status_for_js(),
			'menu_location'      => 'tools' // NEU: Für JavaScript-Navigation
		] );
	}

	/**
	 * Lädt seitenspezifische Assets - KORRIGIERT
	 * 
	 * @param string $hook_suffix
	 */
	private function enqueue_page_specific_assets( $hook_suffix ) {
		$page_slug = $this->get_current_page_slug( $hook_suffix );

		switch ( $page_slug ) {
			case 'tools_page_csv-import-logs':
				// Chart.js für Log-Visualisierung
				wp_enqueue_script(
					'chart-js',
					'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.min.js',
					[],
					'4.4.1',
					true
				);
				break;

			case 'tools_page_csv-import-scheduling':
				// Cron-Expression-Builder
				wp_enqueue_script(
					'cron-builder',
					CSV_IMPORT_PRO_URL . 'assets/js/cron-builder.js',
					[ 'jquery' ],
					CSV_IMPORT_PRO_VERSION,
					true
				);
				break;

			case 'tools_page_csv-import-debug':
				// CodeMirror für erweiterte Einstellungen
				wp_enqueue_code_editor( [ 'type' => 'application/json' ] );
				break;
		}
	}

	/**
	 * Registriert Plugin-Einstellungen - KORRIGIERT
	 */
	public function register_plugin_settings() {
		// Einstellungsgruppe registrieren
		register_setting( 'csv_import_settings', 'csv_import_settings', [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_settings' ],
			'default'           => $this->get_default_settings()
		] );

		// Einzelne Einstellungen für bessere Verwaltung
		$individual_settings = [
			'template_id'      => [ 'type' => 'integer', 'sanitize' => 'absint' ],
			'post_type'        => [ 'type' => 'string',  'sanitize' => 'sanitize_key' ],
			'post_status'      => [ 'type' => 'string',  'sanitize' => 'sanitize_key' ],
			'page_builder'     => [ 'type' => 'string',  'sanitize' => 'sanitize_key' ],
			'dropbox_url'      => [ 'type' => 'string',  'sanitize' => 'esc_url_raw' ],
			'local_path'       => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field' ],
			'image_source'     => [ 'type' => 'string',  'sanitize' => 'sanitize_key' ],
			'image_folder'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field' ],
			'memory_limit'     => [ 'type' => 'string',  'sanitize' => 'sanitize_text_field' ],
			'time_limit'       => [ 'type' => 'integer', 'sanitize' => 'absint' ],
			'seo_plugin'       => [ 'type' => 'string',  'sanitize' => 'sanitize_key' ],
			'required_columns' => [ 'type' => 'string',  'sanitize' => 'sanitize_textarea_field' ],
			'skip_duplicates'  => [ 'type' => 'boolean', 'sanitize' => 'rest_sanitize_boolean' ]
		];

		foreach ( $individual_settings as $key => $config ) {
			register_setting( 'csv_import_settings', 'csv_import_' . $key, [
				'type'              => $config['type'],
				'sanitize_callback' => $config['sanitize'],
				'default'           => $this->get_default_value( $key ),
				'show_in_rest'      => current_user_can( 'manage_options' ), // KORRIGIERT
			] );
		}

		// Erweiterte Einstellungen nur für Admins
		if ( current_user_can( 'manage_options' ) ) {
			register_setting( 'csv_import_advanced_settings', 'csv_import_advanced_settings', [
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_advanced_settings' ],
				'default'           => $this->get_default_advanced_settings()
			] );
		}
	}

	/**
	 * Verarbeitet AJAX-Anfragen für Admin-Funktionen - KORRIGIERT
	 */
	public function handle_admin_ajax() {
		// Sicherheitsprüfung
		if ( ! check_ajax_referer( 'csv_import_admin_action', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Sicherheitsprüfung fehlgeschlagen', 'csv-import' ) ] );
		}

		// KORRIGIERT: Basis-Berechtigung prüfen
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( [ 'message' => __( 'Keine Berechtigung', 'csv-import' ) ] );
		}

		$action = sanitize_key( $_POST['admin_action'] ?? '' );

		switch ( $action ) {
			case 'test_connection':
				$this->ajax_test_connection();
				break;

			case 'reset_plugin':
				// KORRIGIERT: Reset nur für Admins
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( [ 'message' => __( 'Admin-Berechtigung erforderlich', 'csv-import' ) ] );
				}
				$this->ajax_reset_plugin();
				break;

			case 'export_settings':
				$this->ajax_export_settings();
				break;

			case 'import_settings':
				// KORRIGIERT: Import nur für Admins
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_send_json_error( [ 'message' => __( 'Admin-Berechtigung erforderlich', 'csv-import' ) ] );
				}
				$this->ajax_import_settings();
				break;

			case 'system_info':
				$this->ajax_get_system_info();
				break;

			default:
				wp_send_json_error( [ 'message' => __( 'Unbekannte Aktion', 'csv-import' ) ] );
		}
	}

	/**
	 * Fügt Plugin-Action-Links hinzu - KORRIGIERT
	 * 
	 * @param array $links
	 * @return array
	 */
	public function add_plugin_action_links( $links ) {
		$plugin_links = [
			'settings' => sprintf(
				'<a href="%s">%s</a>',
				admin_url( 'tools.php?page=csv-import-settings' ), // KORRIGIERT: tools.php
				__( 'Einstellungen', 'csv-import' )
			),
			'import' => sprintf(
				'<a href="%s" style="color: #00a32a; font-weight: 600;">%s</a>',
				admin_url( 'tools.php?page=csv-import' ), // KORRIGIERT: tools.php
				__( 'Import starten', 'csv-import' )
			)
		];

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Fügt Plugin-Meta-Links hinzu
	 * 
	 * @param array $links
	 * @param string $file
	 * @return array
	 */
	public function add_plugin_meta_links( $links, $file ) {
		if ( $file !== CSV_IMPORT_PRO_BASENAME ) {
			return $links;
		}

		$meta_links = [
			'docs' => sprintf(
				'<a href="%s" target="_blank">%s</a>',
				'https://example.com/docs',
				__( 'Dokumentation', 'csv-import' )
			),
			'support' => sprintf(
				'<a href="%s" target="_blank">%s</a>',
				'https://example.com/support',
				__( 'Support', 'csv-import' )
			),
			'tools' => sprintf(
				'<a href="%s">%s</a>',
				admin_url( 'tools.php?page=csv-import' ), // KORRIGIERT
				__( 'Zum Plugin', 'csv-import' )
			)
		];

		return array_merge( $links, $meta_links );
	}

	/**
	 * Zeigt Plugin-spezifische Admin-Notices - KORRIGIERT
	 */
	public function show_plugin_notices() {
		if ( ! $this->is_plugin_page() ) {
			return;
		}

		// KORRIGIERT: Berechtigung für Notices prüfen
		if ( ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		// Import läuft gerade
		if ( function_exists( 'csv_import_is_import_running' ) && csv_import_is_import_running() ) {
			$progress = function_exists( 'csv_import_get_progress' ) ? csv_import_get_progress() : [];
			
			echo '<div class="notice notice-info csv-import-progress-notice">';
			echo '<p><strong>' . __( 'Import läuft:', 'csv-import' ) . '</strong> ';
			
			if ( ! empty( $progress['message'] ) ) {
				echo esc_html( $progress['message'] );
			} else {
				echo __( 'Ein CSV-Import wird gerade verarbeitet...', 'csv-import' );
			}
			
			if ( ! empty( $progress['percent'] ) ) {
				echo sprintf( ' (%s%%)', $progress['percent'] );
			}
			
			echo '</p>';
			
			// Progress-Bar
			if ( ! empty( $progress['percent'] ) ) {
				echo '<div class="csv-import-progress-bar" style="background: #f0f0f1; height: 20px; border-radius: 10px; overflow: hidden; margin: 10px 0;">';
				echo '<div class="csv-import-progress-fill" style="background: #00a32a; height: 100%; width: ' . intval( $progress['percent'] ) . '%; transition: width 0.3s ease;"></div>';
				echo '</div>';
			}
			
			echo '</div>';
		}

		// Konfigurationsfehler - nur für berechtigte Benutzer anzeigen
		if ( function_exists( 'csv_import_get_config' ) && function_exists( 'csv_import_validate_config' ) ) {
			$config = csv_import_get_config();
			$validation = csv_import_validate_config( $config );
			
			if ( ! $validation['valid'] && ! empty( $validation['errors'] ) ) {
				echo '<div class="notice notice-warning">';
				echo '<p><strong>' . __( 'CSV Import Konfigurationsprobleme:', 'csv-import' ) . '</strong></p>';
				echo '<ul style="margin-left: 20px;">';
				foreach ( $validation['errors'] as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul>';
				echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=csv-import-settings' ) ) . '" class="button">' . __( 'Einstellungen korrigieren', 'csv-import' ) . '</a></p>'; // KORRIGIERT
				echo '</div>';
			}
		}

		// System-Warnungen
		$this->show_system_warnings();

		// KORRIGIERT: Willkommens-Notice für neue Benutzer
		if ( get_transient( 'csv_import_show_welcome' ) ) {
			echo '<div class="notice notice-success is-dismissible">';
			echo '<p><strong>' . __( 'Willkommen bei CSV Import Pro!', 'csv-import' ) . '</strong> ';
			echo __( 'Das Plugin befindet sich jetzt im Tools-Menü für bessere Integration.', 'csv-import' );
			echo ' <a href="' . esc_url( admin_url( 'tools.php?page=csv-import-settings' ) ) . '">' . __( 'Jetzt konfigurieren', 'csv-import' ) . '</a></p>';
			echo '</div>';
			delete_transient( 'csv_import_show_welcome' );
		}
	}

	/**
	 * Anpassung des Admin-Footer-Texts
	 * 
	 * @param string $text
	 * @return string
	 */
	public function admin_footer_text( $text ) {
		if ( ! $this->is_plugin_page() ) {
			return $text;
		}

		return sprintf(
			__( 'Vielen Dank für die Nutzung von %s. | Version %s | %s', 'csv-import' ),
			'<strong>CSV Import Pro</strong>',
			defined( 'CSV_IMPORT_PRO_VERSION' ) ? CSV_IMPORT_PRO_VERSION : '1.0.0',
			'<a href="' . admin_url( 'tools.php' ) . '">' . __( 'Zurück zu Tools', 'csv-import' ) . '</a>'
		);
	}

	// ===================================================================
	// SEITEN-CALLBACK-FUNKTIONEN
	// ===================================================================

	/**
	 * Zeigt die Hauptseite an
	 */
	public function display_main_page() {
		$this->load_page_data( 'main' );
		$this->render_page( 'page-main.php' );
	}

	/**
	 * Zeigt die Einstellungsseite an
	 */
	public function display_settings_page() {
		$this->handle_settings_form_submission();
		$this->load_page_data( 'settings' );
		$this->render_page( 'page-settings.php' );
	}

	/**
	 * Zeigt die Backup-Seite an
	 */
	public function display_backup_page() {
		$this->handle_backup_actions();
		$this->load_page_data( 'backups' );
		$this->render_page( 'page-backups.php' );
	}

	/**
	 * Zeigt die Profile-Seite an
	 */
	public function display_profiles_page() {
		$this->handle_profile_actions();
		$this->load_page_data( 'profiles' );
		$this->render_page( 'page-profiles.php' );
	}

	/**
	 * Zeigt die Scheduling-Seite an
	 */
	public function display_scheduling_page() {
		// KORRIGIERT: Admin-Berechtigung für Scheduling prüfen
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Für die Automatisierung sind Administrator-Rechte erforderlich.', 'csv-import' ) );
		}

		$this->handle_scheduling_actions();
		$this->load_page_data( 'scheduling' );
		$this->render_page( 'page-scheduling.php' );
	}

	/**
	 * Zeigt die Logs-Seite an
	 */
	public function display_logs_page() {
		$this->handle_logs_actions();
		$this->load_page_data( 'logs' );
		$this->render_page( 'page-logs.php' );
	}

	/**
	 * Zeigt die Debug-Seite an (nur bei aktiviertem Debug)
	 */
	public function display_debug_page() {
		if ( ! defined( 'CSV_IMPORT_DEBUG' ) || ! CSV_IMPORT_DEBUG ) {
			wp_die( __( 'Debug-Modus ist nicht aktiviert.', 'csv-import' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Admin-Berechtigung für Debug-Funktionen erforderlich.', 'csv-import' ) );
		}

		$this->load_page_data( 'debug' );
		$this->render_page( 'page-debug.php' );
	}

	// ===================================================================
	// HILFSFUNKTIONEN - KORRIGIERT
	// ===================================================================

	/**
	 * Prüft ob wir auf einer Plugin-Seite sind - KORRIGIERT für Tools-Menü
	 * 
	 * @param string|null $hook_suffix
	 * @return bool
	 */
	private function is_plugin_page( $hook_suffix = null ) {
		if ( $hook_suffix === null ) {
			$hook_suffix = get_current_screen()->id ?? '';
		}

		// KORRIGIERT: Tools-Seiten-Pattern
		$plugin_page_patterns = [
			'tools_page_csv-import',
			'tools_page_csv-import-settings',
			'tools_page_csv-import-backups',
			'tools_page_csv-import-profiles',
			'tools_page_csv-import-scheduling',
			'tools_page_csv-import-logs',
			'tools_page_csv-import-debug'
		];

		return in_array( $hook_suffix, $plugin_page_patterns ) || 
			   in_array( $hook_suffix, $this->admin_pages ) ||
			   strpos( $hook_suffix, 'csv-import' ) !== false;
	}

	/**
	 * Ermittelt den aktuellen Seiten-Slug - KORRIGIERT
	 * 
	 * @param string $hook_suffix
	 * @return string
	 */
	private function get_current_page_slug( $hook_suffix ) {
		// KORRIGIERT: Tools-Seiten-Mapping
		$page_mapping = [
			'tools_page_csv-import' => 'csv-import',
			'tools_page_csv-import-settings' => 'csv-import-settings',
			'tools_page_csv-import-backups' => 'csv-import-backups',
			'tools_page_csv-import-profiles' => 'csv-import-profiles',
			'tools_page_csv-import-scheduling' => 'csv-import-scheduling',
			'tools_page_csv-import-logs' => 'csv-import-logs',
			'tools_page_csv-import-debug' => 'csv-import-debug'
		];

		return $page_mapping[ $hook_suffix ] ?? $this->menu_slug;
	}

	/**
	 * Lädt seitenspezifische Daten
	 * 
	 * @param string $page_type
	 */
	private function load_page_data( $page_type ) {
		// Gemeinsame Daten für alle Seiten
		$GLOBALS['csv_import_admin_data'] = [
			'page_type' => $page_type,
			'nonce_action' => 'csv_import_' . $page_type . '_action',
			'current_user_can_import' => current_user_can( 'edit_pages' ), // KORRIGIERT
			'current_user_can_admin' => current_user_can( 'manage_options' ),
			'plugin_version' => defined( 'CSV_IMPORT_PRO_VERSION' ) ? CSV_IMPORT_PRO_VERSION : '1.0.0',
			'wp_version' => get_bloginfo( 'version' ),
			'php_version' => PHP_VERSION,
			'menu_location' => 'tools' // NEU: Für Template-Navigation
		];

		// Seitenspezifische Daten laden
		switch ( $page_type ) {
			case 'main':
				$this->load_main_page_data();
				break;
			case 'settings':
				$this->load_settings_page_data();
				break;
			case 'backups':
				$this->load_backup_page_data();
				break;
			case 'profiles':
				$this->load_profiles_page_data();
				break;
			case 'scheduling':
				$this->load_scheduling_page_data();
				break;
			case 'logs':
				$this->load_logs_page_data();
				break;
			case 'debug':
				$this->load_debug_page_data();
				break;
		}
	}

	/**
	 * Rendert eine Seiten-Template
	 * 
	 * @param string $template_file
	 */
	private function render_page( $template_file ) {
		$template_path = CSV_IMPORT_PRO_PATH . 'includes/admin/views/' . $template_file;
		
		if ( file_exists( $template_path ) ) {
			// Template-Variablen aus globalen Admin-Daten extrahieren
			if ( isset( $GLOBALS['csv_import_admin_data'] ) ) {
				extract( $GLOBALS['csv_import_admin_data'], EXTR_SKIP );
			}
			
			include $template_path;
		} else {
			$this->render_fallback_page( $template_file );
		}
	}

	/**
	 * Rendert eine Fallback-Seite wenn Template nicht gefunden
	 * 
	 * @param string $template_file
	 */
	private function render_fallback_page( $template_file ) {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		
		// KORRIGIERT: Bessere Fallback-Navigation
		echo '<div class="nav-tab-wrapper">';
		echo '<a href="' . admin_url( 'tools.php?page=csv-import' ) . '" class="nav-tab">Import Dashboard</a>';
		echo '<a href="' . admin_url( 'tools.php?page=csv-import-settings' ) . '" class="nav-tab">Einstellungen</a>';
		echo '<a href="' . admin_url( 'tools.php?page=csv-import-logs' ) . '" class="nav-tab">Logs</a>';
		echo '</div>';
		
		echo '<div class="notice notice-error">';
		echo '<p>' . sprintf( 
			__( 'Template-Datei nicht gefunden: %s', 'csv-import' ), 
			esc_html( $template_file ) 
		) . '</p>';
		echo '<p>' . __( 'Bitte prüfen Sie die Plugin-Installation oder kontaktieren Sie den Administrator.', 'csv-import' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Lädt Hauptseiten-Daten
	 */
	private function load_main_page_data() {
		// Import-Status und Fortschritt
		$progress = function_exists( 'csv_import_get_progress' ) ? csv_import_get_progress() : [];
		$config = function_exists( 'csv_import_get_config' ) ? csv_import_get_config() : [];
		$validation = function_exists( 'csv_import_validate_config' ) ? csv_import_validate_config( $config ) : [ 'valid' => false ];
		$stats = function_exists( 'csv_import_get_stats' ) ? csv_import_get_stats() : [];
		$health = function_exists( 'csv_import_system_health_check' ) ? csv_import_system_health_check() : [];

		$GLOBALS['csv_import_admin_data'] = array_merge( $GLOBALS['csv_import_admin_data'], [
			'progress' => $progress,
			'config' => $config,
			'config_valid' => $validation,
			'stats' => $stats,
			'health' => $health,
			'import_running' => function_exists( 'csv_import_is_import_running' ) ? csv_import_is_import_running() : false
		] );
	}

	/**
	 * Lädt Einstellungsseiten-Daten
	 */
	private function load_settings_page_data() {
		$config = function_exists( 'csv_import_get_config' ) ? csv_import_get_config() : [];
		
		$GLOBALS['csv_import_admin_data'] = array_merge( $GLOBALS['csv_import_admin_data'], [
			'settings' => $config,
			'post_types' => get_post_types( [ 'public' => true ], 'objects' ),
			'template_info' => function_exists( 'csv_import_get_template_info' ) ? csv_import_get_template_info() : '',
			'file_status' => $this->get_file_status_info()
		] );
	}

	/**
	 * Lädt Backup-Seiten-Daten
	 */
	private function load_backup_page_data() {
		$sessions = [];
		if ( class_exists( 'CSV_Import_Backup_Manager' ) && method_exists( 'CSV_Import_Backup_Manager', 'get_import_sessions' ) ) {
			$sessions = CSV_Import_Backup_Manager::get_import_sessions();
		}

		$GLOBALS['csv_import_admin_data'] = array_merge( $GLOBALS['csv_import_admin_data'], [
			'sessions' => $sessions,
			'backup_settings' => get_option( 'csv_import_advanced_settings', [] )
		] );
	}

	/**
	 * Lädt Profile-Seiten-Daten
	 */
	private function load_profiles_page_data() {
		$profiles = [];
		if ( class_exists( 'CSV_Import_Profile_Manager' ) && method_exists( 'CSV_Import_Profile_Manager', 'get_profiles' ) ) {
			$profiles = CSV_Import_Profile_Manager::get_profiles();
		}

		$GLOBALS['csv_import_admin_data'] = array_merge( $GLOBALS['csv_import_admin_data'], [
			'profiles' => $profiles,
			'current_config' => function_exists( 'csv_import_get_config' ) ? csv_import_get_config() : []
		] );
	}

	/**
	 * Lädt Scheduling-Seiten-Daten
	 */
	private function load_scheduling_page_data() {
		$scheduler_info = [];
		if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'get_scheduler_info' ) ) {
			$scheduler_info = CSV_Import_Scheduler::get_scheduler_info();
		}

		$config = function_exists( 'csv_import_get_config' ) ? csv_import_get_config() : [];
		$validation = function_exists( 'csv_import_validate_config' ) ? csv_import_validate_config( $config ) : [ 'valid' => false ];

		$GLOBALS['csv_import_admin_data'] = array_merge( $GLOBALS['csv_import_admin_data'], [
			'scheduler_info' => $scheduler_info,
			'is_scheduled' => $scheduler_info['is_scheduled'] ?? false,
			'next_scheduled' => $scheduler_info['next_run'] ?? false,
			'current_source' => get_option( 'csv_import_scheduled_source', '' ),
			'current_frequency' => get_option( 'csv_import_scheduled_frequency', '' ),
			'validation' => $validation,
			'notification_settings' => get_option( 'csv_import_notification_settings', [
				'email_on_success' => false,
				'email_on_failure' => true,
				'recipients' => [ get_option( 'admin_email' ) ]
			] ),
			'scheduled_imports' => $this->get_scheduled_imports_history()
		] );
	}

	/**
	 * Lädt Logs-Seiten-Daten
	 */
	private function load_logs_page_data() {
		$filter_level = sanitize_key( $_GET['level'] ?? 'all' );
		$page = max( 1, intval( $_GET['paged'] ?? 1 ) );
		$per_page = 50;

		$logs = [];
		$total_logs = 0;
		$error_stats = [];
		$health = [];

		if ( class_exists( 'CSV_Import_Error_Handler' ) ) {
			$all_logs = CSV_Import_Error_Handler::get_persistent_errors();
			
			// Filter anwenden
			if ( $filter_level !== 'all' ) {
				$all_logs = array_filter( $all_logs, function( $log ) use ( $filter_level ) {
					return ( $log['level'] ?? '' ) === $filter_level;
				} );
			}

			$total_logs = count( $all_logs );
			$logs = array_slice( $all_logs, ( $page - 1 ) * $per_page, $per_page );
		}

		if ( function_exists( 'csv_import_get_error_stats' ) ) {
			$error_stats = csv_import_get_error_stats();
		}

		if ( function_exists( 'csv_import_system_health_check' ) ) {
			$health = csv_import_system_health_check();
		}

		$GLOBALS['csv_import_admin_data'] = array_merge( $GLOBALS['csv_import_admin_data'], [
			'logs' => $logs,
			'filter_level' => $filter_level,
			'page' => $page,
			'per_page' => $per_page,
			'total_logs' => $total_logs,
			'total_pages' => ceil( $total_logs / $per_page ),
			'error_stats' => $error_stats,
			'health' => $health
		] );
	}

	/**
	 * Lädt Debug-Seiten-Daten
	 */
	private function load_debug_page_data() {
		$debug_info = [
			'plugin_status' => method_exists( 'CSV_Import_Pro', 'get_status' ) ? csv_import_pro()->get_status() : [],
			'wp_constants' => $this->get_wp_constants(),
			'server_info' => array_filter( $_SERVER, function( $key ) {
				// Sensible Daten ausfiltern
				$safe_keys = [ 'SERVER_SOFTWARE', 'HTTP_HOST', 'REQUEST_METHOD', 'REQUEST_URI', 'HTTP_USER_AGENT' ];
				return in_array( $key, $safe_keys );
			}, ARRAY_FILTER_USE_KEY ),
			'php_config' => [
				'version' => PHP_VERSION,
				'memory_limit' => ini_get( 'memory_limit' ),
				'max_execution_time' => ini_get( 'max_execution_time' ),
				'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
				'post_max_size' => ini_get( 'post_max_size' )
			],
			'hooks_info' => $this->get_hooks_debug_info()
		];

		$GLOBALS['csv_import_admin_data'] = array_merge( $GLOBALS['csv_import_admin_data'], [
			'debug_info' => $debug_info,
			'can_debug' => current_user_can( 'manage_options' ) && ( defined( 'CSV_IMPORT_DEBUG' ) && CSV_IMPORT_DEBUG )
		] );
	}

	// ===================================================================
	// FORMULAR-VERARBEITUNG
	// ===================================================================

	/**
	 * Verarbeitet Einstellungsformular-Eingaben
	 */
	private function handle_settings_form_submission() {
		if ( ! isset( $_POST['submit'] ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_settings-options' ) ) {
			return;
		}

		// KORRIGIERT: Berechtigung prüfen
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_die( __( 'Keine Berechtigung zum Ändern der Einstellungen.', 'csv-import' ) );
		}

		// WordPress Settings API übernimmt die Verarbeitung
		$this->validate_and_save_settings();
	}

	/**
	 * Verarbeitet Backup-Aktionen
	 */
	private function handle_backup_actions() {
		if ( ! isset( $_POST['action'] ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		$action = sanitize_key( $_POST['action'] );

		switch ( $action ) {
			case 'rollback_import':
				if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_rollback' ) ) {
					$this->handle_rollback_action();
				}
				break;

			case 'cleanup_backups':
				if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_cleanup_backups' ) ) {
					$this->handle_cleanup_backups();
				}
				break;
		}
	}

	/**
	 * Verarbeitet Profil-Aktionen
	 */
	private function handle_profile_actions() {
		if ( ! isset( $_POST['action'] ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		$action = sanitize_key( $_POST['action'] );

		switch ( $action ) {
			case 'save_profile':
				if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_save_profile' ) ) {
					$this->handle_save_profile();
				}
				break;

			case 'load_profile':
				if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_load_profile' ) ) {
					$this->handle_load_profile();
				}
				break;

			case 'delete_profile':
				if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_delete_profile' ) ) {
					$this->handle_delete_profile();
				}
				break;
		}
	}

	/**
	 * Verarbeitet Scheduling-Aktionen
	 */
	private function handle_scheduling_actions() {
		if ( ! isset( $_POST['action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = sanitize_key( $_POST['action'] );

		switch ( $action ) {
			case 'schedule_import':
				if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_scheduling' ) ) {
					$this->handle_schedule_import();
				}
				break;

			case 'unschedule_import':
				if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_scheduling' ) ) {
					$this->handle_unschedule_import();
				}
				break;

			case 'update_notifications':
				if ( wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_notification_settings' ) ) {
					$this->handle_update_notifications();
				}
				break;
		}
	}

	/**
	 * Verarbeitet Logs-Aktionen
	 */
	private function handle_logs_actions() {
		if ( ! isset( $_POST['action'] ) || ! current_user_can( 'edit_pages' ) ) {
			return;
		}

		$action = sanitize_key( $_POST['action'] );

		if ( $action === 'clear_logs' && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'csv_import_clear_logs' ) ) {
			$this->handle_clear_logs();
		}
	}

	// ===================================================================
	// AJAX-HANDLER
	// ===================================================================

	/**
	 * Testet Verbindungen (AJAX)
	 */
	private function ajax_test_connection() {
		$type = sanitize_key( $_POST['connection_type'] ?? '' );
		$result = [ 'success' => false, 'message' => 'Unbekannter Verbindungstyp' ];

		switch ( $type ) {
			case 'dropbox':
				$url = sanitize_url( $_POST['url'] ?? '' );
				$result = $this->test_dropbox_connection( $url );
				break;

			case 'local':
				$path = sanitize_text_field( $_POST['path'] ?? '' );
				$result = $this->test_local_file( $path );
				break;
		}

		wp_send_json( $result );
	}

	/**
	 * Exportiert Einstellungen (AJAX)
	 */
	private function ajax_export_settings() {
		$settings = $this->get_all_plugin_settings();
		$export_data = [
			'version' => defined( 'CSV_IMPORT_PRO_VERSION' ) ? CSV_IMPORT_PRO_VERSION : '1.0.0',
			'exported' => current_time( 'mysql' ),
			'settings' => $settings
		];

		wp_send_json_success( [
			'data' => base64_encode( wp_json_encode( $export_data ) ),
			'filename' => 'csv-import-settings-' . date( 'Y-m-d-H-i-s' ) . '.json'
		] );
	}

	/**
	 * Plugin zurücksetzen (AJAX)
	 */
	private function ajax_reset_plugin() {
		$confirmed = rest_sanitize_boolean( $_POST['confirmed'] ?? false );
		
		if ( ! $confirmed ) {
			wp_send_json_error( [ 'message' => 'Zurücksetzung nicht bestätigt' ] );
		}

		$result = $this->reset_plugin_data();
		wp_send_json( $result );
	}

	/**
	 * Holt System-Informationen (AJAX)
	 */
	private function ajax_get_system_info() {
		wp_send_json_success( [
			'system_info' => $this->get_system_info(),
			'plugin_status' => method_exists( 'CSV_Import_Pro', 'get_status' ) ? csv_import_pro()->get_status() : [],
			'health_check' => function_exists( 'csv_import_system_health_check' ) ? csv_import_system_health_check() : []
		] );
	}

	// ===================================================================
	// HILFSFUNKTIONEN FÜR DATENVERARBEITUNG
	// ===================================================================

	/**
	 * Holt Datei-Status-Informationen
	 * 
	 * @return array
	 */
	private function get_file_status_info() {
		$config = function_exists( 'csv_import_get_config' ) ? csv_import_get_config() : [];
		
		return [
			'local_csv' => function_exists( 'csv_import_get_file_status' ) ? 
				csv_import_get_file_status( ABSPATH . ltrim( $config['local_path'] ?? '', '/' ) ) : 
				'Funktion nicht verfügbar',
			'image_folder' => function_exists( 'csv_import_get_file_status' ) ? 
				csv_import_get_file_status( ABSPATH . ltrim( $config['image_folder'] ?? '', '/' ), true ) : 
				'Funktion nicht verfügbar'
		];
	}

	/**
	 * Holt Scheduling-Historie
	 * 
	 * @return array
	 */
	private function get_scheduled_imports_history() {
		if ( ! class_exists( 'CSV_Import_Error_Handler' ) ) {
			return [];
		}

		$logs = CSV_Import_Error_Handler::get_persistent_errors();
		$scheduled_logs = array_filter( $logs, function( $log ) {
			return strpos( $log['message'] ?? '', 'Geplanter Import' ) !== false;
		} );

		return array_slice( $scheduled_logs, -20 ); // Letzte 20 Einträge
	}

	/**
	 * Holt System-Informationen
	 * 
	 * @return array
	 */
	private function get_system_info() {
		global $wpdb;

		return [
			'php_version' => PHP_VERSION,
			'wp_version' => get_bloginfo( 'version' ),
			'mysql_version' => $wpdb->db_version(),
			'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
			'memory_limit' => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size' => ini_get( 'post_max_size' ),
			'max_input_vars' => ini_get( 'max_input_vars' ),
			'curl_enabled' => function_exists( 'curl_init' ),
			'openssl_enabled' => extension_loaded( 'openssl' ),
			'mbstring_enabled' => extension_loaded( 'mbstring' ),
			'zip_enabled' => extension_loaded( 'zip' )
		];
	}

	/**
	 * Holt WordPress-Konstanten für Debug
	 * 
	 * @return array
	 */
	private function get_wp_constants() {
		$constants = [
			'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY',
			'SCRIPT_DEBUG', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT',
			'DISABLE_WP_CRON', 'WP_CRON_LOCK_TIMEOUT'
		];

		$values = [];
		foreach ( $constants as $constant ) {
			$values[ $constant ] = defined( $constant ) ? constant( $constant ) : 'undefined';
		}

		return $values;
	}

	/**
	 * Holt Hook-Debug-Informationen
	 * 
	 * @return array
	 */
	private function get_hooks_debug_info() {
		global $wp_filter;
		
		$plugin_hooks = [];
		foreach ( $wp_filter as $hook_name => $hook_obj ) {
			if ( strpos( $hook_name, 'csv_import' ) !== false ) {
				$plugin_hooks[ $hook_name ] = count( $hook_obj->callbacks ?? [] );
			}
		}

		return $plugin_hooks;
	}

	/**
	 * Holt JavaScript-Strings für Lokalisierung
	 * 
	 * @return array
	 */
	private function get_js_strings() {
		return [
			'confirm_import' => __( 'Import wirklich starten?', 'csv-import' ),
			'confirm_rollback' => __( 'Rollback wirklich durchführen? Alle importierten Posts werden gelöscht!', 'csv-import' ),
			'confirm_reset' => __( 'Plugin wirklich zurücksetzen? Alle Einstellungen gehen verloren!', 'csv-import' ),
			'import_running' => __( 'Import läuft bereits', 'csv-import' ),
			'import_success' => __( 'Import erfolgreich abgeschlossen', 'csv-import' ),
			'import_error' => __( 'Import fehlgeschlagen', 'csv-import' ),
			'saving' => __( 'Speichern...', 'csv-import' ),
			'loading' => __( 'Laden...', 'csv-import' ),
			'testing' => __( 'Teste...', 'csv-import' ),
			'connection_ok' => __( 'Verbindung erfolgreich', 'csv-import' ),
			'connection_failed' => __( 'Verbindung fehlgeschlagen', 'csv-import' )
		];
	}

	/**
	 * Holt JavaScript-Konfiguration
	 * 
	 * @return array
	 */
	private function get_js_config() {
		return [
			'refresh_interval' => 5000, // 5 Sekunden
			'max_retries' => 3,
			'timeout' => 30000, // 30 Sekunden
			'auto_refresh_progress' => true,
			'show_debug_info' => defined( 'CSV_IMPORT_DEBUG' ) && CSV_IMPORT_DEBUG
		];
	}

	/**
	 * Holt System-Status für JavaScript
	 * 
	 * @return array
	 */
	private function get_system_status_for_js() {
		$health = function_exists( 'csv_import_system_health_check' ) ? csv_import_system_health_check() : [];
		
		return [
			'overall_status' => empty( array_filter( $health, function( $status ) { return $status === false; } ) ),
			'memory_ok' => $health['memory_ok'] ?? true,
			'disk_space_ok' => $health['disk_space_ok'] ?? true,
			'permissions_ok' => $health['permissions_ok'] ?? true
		];
	}

	/**
	 * Holt Standard-Einstellungen
	 * 
	 * @return array
	 */
	private function get_default_settings() {
		return function_exists( 'csv_import_get_config' ) ? csv_import_get_config() : [];
	}

	/**
	 * Holt Standard-Wert für eine Einstellung
	 * 
	 * @param string $key
	 * @return mixed
	 */
	private function get_default_value( $key ) {
		return function_exists( 'csv_import_get_default_value' ) ? csv_import_get_default_value( $key ) : '';
	}

	/**
	 * Holt erweiterte Standard-Einstellungen
	 * 
	 * @return array
	 */
	private function get_default_advanced_settings() {
		return [
			'batch_size' => 25,
			'performance_logging' => true,
			'max_errors_per_level' => [
				'critical' => 1,
				'error' => 10,
				'warning' => 50
			],
			'csv_preprocessing' => [
				'remove_empty_rows' => true,
				'trim_values' => true,
				'convert_encoding' => true
			],
			'security_settings' => [
				'strict_ssl_verification' => true,
				'allowed_file_extensions' => [ 'csv', 'txt' ],
				'max_file_size_mb' => 50
			],
			'backup_retention_days' => 30
		];
	}

	/**
	 * Sanitiert Einstellungen
	 * 
	 * @param array $settings
	 * @return array
	 */
	public function sanitize_settings( $settings ) {
		// Basis-Sanitization für alle Einstellungen
		return array_map( 'sanitize_text_field', $settings );
	}

	/**
	 * Sanitiert erweiterte Einstellungen
	 * 
	 * @param array $settings
	 * @return array
	 */
	public function sanitize_advanced_settings( $settings ) {
		$sanitized = [];
		
		// Batch-Größe
		$sanitized['batch_size'] = max( 1, min( 200, intval( $settings['batch_size'] ?? 25 ) ) );
		
		// Performance-Logging
		$sanitized['performance_logging'] = rest_sanitize_boolean( $settings['performance_logging'] ?? true );
		
		// Fehler-Limits
		$sanitized['max_errors_per_level'] = [
			'critical' => max( 1, min( 10, intval( $settings['max_critical_errors'] ?? 1 ) ) ),
			'error' => max( 1, min( 100, intval( $settings['max_error_errors'] ?? 10 ) ) ),
			'warning' => max( 1, min( 500, intval( $settings['max_warning_errors'] ?? 50 ) ) )
		];
		
		// CSV-Preprocessing
		$sanitized['csv_preprocessing'] = [
			'remove_empty_rows' => rest_sanitize_boolean( $settings['remove_empty_rows'] ?? true ),
			'trim_values' => rest_sanitize_boolean( $settings['trim_values'] ?? true ),
			'convert_encoding' => rest_sanitize_boolean( $settings['convert_encoding'] ?? true )
		];
		
		// Sicherheitseinstellungen
		$sanitized['security_settings'] = [
			'strict_ssl_verification' => rest_sanitize_boolean( $settings['strict_ssl_verification'] ?? true ),
			'allowed_file_extensions' => array_map( 'sanitize_file_name', 
				explode( ',', $settings['allowed_file_extensions'] ?? 'csv,txt' ) 
			),
			'max_file_size_mb' => max( 1, min( 500, intval( $settings['max_file_size_mb'] ?? 50 ) ) )
		];
		
		// Backup-Aufbewahrung
		$sanitized['backup_retention_days'] = max( 1, min( 365, intval( $settings['backup_retention_days'] ?? 30 ) ) );
		
		return $sanitized;
	}

	/**
	 * Fügt Dashboard-Widget hinzu falls gewünscht
	 */
	private function maybe_add_dashboard_widget() {
		if ( apply_filters( 'csv_import_show_dashboard_widget', true ) ) {
			add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widget' ] );
		}
	}

	/**
	 * Fügt Dashboard-Widget hinzu
	 */
	public function add_dashboard_widget() {
		wp_add_dashboard_widget(
			'csv_import_dashboard_widget',
			__( 'CSV Import Pro Status', 'csv-import' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	/**
	 * Rendert Dashboard-Widget - KORRIGIERT
	 */
	public function render_dashboard_widget() {
		if ( function_exists( 'csv_import_dashboard_widget' ) ) {
			csv_import_dashboard_widget();
		} else {
			echo '<div class="csv-import-widget">';
			echo '<p>' . __( 'CSV Import Pro ist aktiv.', 'csv-import' ) . '</p>';
			
			// Status-Informationen
			$import_running = function_exists( 'csv_import_is_import_running' ) && csv_import_is_import_running();
			if ( $import_running ) {
				echo '<p><strong style="color: #d63638;">⏳ ' . __( 'Import läuft gerade', 'csv-import' ) . '</strong></p>';
			} else {
				echo '<p>✅ ' . __( 'Bereit für Import', 'csv-import' ) . '</p>';
			}
			
			// KORRIGIERT: Tools-Menü-Links
			echo '<p>';
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=csv-import' ) ) . '" class="button button-primary">' . __( 'Import starten', 'csv-import' ) . '</a> ';
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=csv-import-settings' ) ) . '" class="button">' . __( 'Einstellungen', 'csv-import' ) . '</a>';
			echo '</p>';
			echo '</div>';
		}
	}

	/**
	 * Lädt Hauptseiten-Hook
	 */
	public function load_main_page() {
		// Wird vor dem Rendern der Hauptseite aufgerufen
		do_action( 'csv_import_load_main_page' );
	}

	/**
	 * Lädt Unterseiten-Hook
	 */
	public function load_submenu_page() {
		// Wird vor dem Rendern einer Unterseite aufgerufen
		do_action( 'csv_import_load_submenu_page' );
	}

	/**
	 * Zeigt System-Warnungen - KORRIGIERT
	 */
	private function show_system_warnings() {
		if ( ! function_exists( 'csv_import_system_health_check' ) ) {
			return;
		}

		$health = csv_import_system_health_check();
		$issues = array_filter( $health, function( $status ) { return $status === false; } );

		if ( empty( $issues ) ) {
			return;
		}

		$warning_messages = [
			'memory_ok' => __( 'Niedriges Memory Limit - könnte Probleme bei großen CSV-Dateien verursachen', 'csv-import' ),
			'disk_space_ok' => __( 'Wenig freier Speicherplatz verfügbar', 'csv-import' ),
			'permissions_ok' => __( 'Dateiberechtigungen-Probleme erkannt', 'csv-import' ),
			'php_version_ok' => __( 'PHP-Version ist veraltet', 'csv-import' ),
			'wp_version_ok' => __( 'WordPress-Version ist veraltet', 'csv-import' ),
			'curl_ok' => __( 'cURL-Erweiterung nicht verfügbar', 'csv-import' ),
			'import_locks' => __( 'Import-Sperren aktiv - möglicherweise hängender Prozess', 'csv-import' ),
			'stuck_processes' => __( 'Hängende Import-Prozesse erkannt', 'csv-import' )
		];

		echo '<div class="notice notice-warning">';
		echo '<p><strong>' . __( 'CSV Import Pro - System-Warnungen:', 'csv-import' ) . '</strong></p>';
		echo '<ul style="margin-left: 20px;">';
		
		foreach ( $issues as $issue => $status ) {
			if ( isset( $warning_messages[ $issue ] ) ) {
				echo '<li>' . esc_html( $warning_messages[ $issue ] ) . '</li>';
			}
		}
		
		echo '</ul>';
		echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=csv-import-logs' ) ) . '" class="button">' . __( 'System-Details anzeigen', 'csv-import' ) . '</a></p>'; // KORRIGIERT
		echo '</div>';
	}

	// ===================================================================
	// AKTIONS-HANDLER (private Methoden für Formular-Verarbeitung)
	// ===================================================================

	/**
	 * Validiert und speichert Einstellungen
	 */
	private function validate_and_save_settings() {
		// Zusätzliche Validierung vor dem Speichern
		$template_id = intval( $_POST['csv_import_template_id'] ?? 0 );
		
		if ( $template_id > 0 && ! get_post( $template_id ) ) {
			add_settings_error( 
				'csv_import_settings', 
				'invalid_template', 
				__( 'Die angegebene Template-ID existiert nicht.', 'csv-import' ) 
			);
		}

		// Dropbox-URL validieren
		$dropbox_url = esc_url_raw( $_POST['csv_import_dropbox_url'] ?? '' );
		if ( ! empty( $dropbox_url ) && strpos( $dropbox_url, 'dropbox.com' ) === false ) {
			add_settings_error( 
				'csv_import_settings', 
				'invalid_dropbox_url', 
				__( 'Bitte geben Sie eine gültige Dropbox-URL ein.', 'csv-import' ) 
			);
		}
	}

	/**
	 * Behandelt Rollback-Aktion
	 */
	private function handle_rollback_action() {
		$session_id = sanitize_text_field( $_POST['rollback_session'] ?? '' );
		
		if ( empty( $session_id ) ) {
			return;
		}

		if ( class_exists( 'CSV_Import_Backup_Manager' ) && method_exists( 'CSV_Import_Backup_Manager', 'rollback_import' ) ) {
			$GLOBALS['csv_import_admin_data']['rollback_result'] = CSV_Import_Backup_Manager::rollback_import( $session_id );
		}
	}

	/**
	 * Behandelt Backup-Bereinigung
	 */
	private function handle_cleanup_backups() {
		$retention_days = get_option( 'csv_import_advanced_settings', [] )['backup_retention_days'] ?? 30;
		
		if ( class_exists( 'CSV_Import_Backup_Manager' ) && method_exists( 'CSV_Import_Backup_Manager', 'cleanup_old_backups' ) ) {
			$deleted_count = CSV_Import_Backup_Manager::cleanup_old_backups( $retention_days );
			$GLOBALS['csv_import_admin_data']['deleted_count'] = $deleted_count;
		}
	}

	/**
	 * Behandelt Profil-Speichern
	 */
	private function handle_save_profile() {
		$profile_name = sanitize_text_field( $_POST['profile_name'] ?? '' );
		
		if ( empty( $profile_name ) ) {
			$GLOBALS['csv_import_admin_data']['action_result'] = [
				'success' => false,
				'message' => __( 'Profil-Name ist erforderlich.', 'csv-import' )
			];
			return;
		}

		if ( class_exists( 'CSV_Import_Profile_Manager' ) && method_exists( 'CSV_Import_Profile_Manager', 'save_profile' ) ) {
			$config = function_exists( 'csv_import_get_config' ) ? csv_import_get_config() : [];
			$profile_id = CSV_Import_Profile_Manager::save_profile( $profile_name, $config );
			
			$GLOBALS['csv_import_admin_data']['action_result'] = [
				'success' => ! empty( $profile_id ),
				'message' => ! empty( $profile_id ) ? 
					__( 'Profil erfolgreich gespeichert.', 'csv-import' ) : 
					__( 'Fehler beim Speichern des Profils.', 'csv-import' )
			];
		}
	}

	/**
	 * Behandelt Profil-Laden
	 */
	private function handle_load_profile() {
		$profile_id = sanitize_key( $_POST['profile_id'] ?? '' );
		
		if ( class_exists( 'CSV_Import_Profile_Manager' ) && method_exists( 'CSV_Import_Profile_Manager', 'load_profile' ) ) {
			$success = CSV_Import_Profile_Manager::load_profile( $profile_id );
			
			$GLOBALS['csv_import_admin_data']['action_result'] = [
				'success' => $success,
				'message' => $success ? 
					__( 'Profil erfolgreich geladen.', 'csv-import' ) : 
					__( 'Fehler beim Laden des Profils.', 'csv-import' )
			];
		}
	}

	/**
	 * Behandelt Profil-Löschen
	 */
	private function handle_delete_profile() {
		$profile_id = sanitize_key( $_POST['profile_id'] ?? '' );
		
		if ( class_exists( 'CSV_Import_Profile_Manager' ) && method_exists( 'CSV_Import_Profile_Manager', 'delete_profile' ) ) {
			$success = CSV_Import_Profile_Manager::delete_profile( $profile_id );
			
			$GLOBALS['csv_import_admin_data']['action_result'] = [
				'success' => $success,
				'message' => $success ? 
					__( 'Profil erfolgreich gelöscht.', 'csv-import' ) : 
					__( 'Fehler beim Löschen des Profils.', 'csv-import' )
			];
		}
	}

	/**
	 * Behandelt Import-Planung
	 */
	private function handle_schedule_import() {
		$source = sanitize_key( $_POST['import_source'] ?? '' );
		$frequency = sanitize_key( $_POST['frequency'] ?? '' );
		
		if ( empty( $source ) || empty( $frequency ) ) {
			$GLOBALS['csv_import_admin_data']['action_result'] = [
				'success' => false,
				'message' => __( 'Quelle und Frequenz sind erforderlich.', 'csv-import' )
			];
			return;
		}

		if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'schedule_import' ) ) {
			$result = CSV_Import_Scheduler::schedule_import( $frequency, $source );
			
			$GLOBALS['csv_import_admin_data']['action_result'] = [
				'success' => ! is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? 
					$result->get_error_message() : 
					__( 'Import erfolgreich geplant.', 'csv-import' )
			];
		}
	}

	/**
	 * Behandelt Import-Planung stoppen
	 */
	private function handle_unschedule_import() {
		if ( class_exists( 'CSV_Import_Scheduler' ) && method_exists( 'CSV_Import_Scheduler', 'unschedule_import' ) ) {
			$success = CSV_Import_Scheduler::unschedule_import();
			
			$GLOBALS['csv_import_admin_data']['action_result'] = [
				'success' => $success,
				'message' => $success ? 
					__( 'Geplante Imports erfolgreich deaktiviert.', 'csv-import' ) : 
					__( 'Fehler beim Deaktivieren der geplanten Imports.', 'csv-import' )
			];
		}
	}

	/**
	 * Behandelt Benachrichtigungs-Updates
	 */
	private function handle_update_notifications() {
		$settings = [
			'email_on_success' => rest_sanitize_boolean( $_POST['email_on_success'] ?? false ),
			'email_on_failure' => rest_sanitize_boolean( $_POST['email_on_failure'] ?? true ),
			'recipients' => array_filter( array_map( 'sanitize_email', explode( "\n", $_POST['recipients'] ?? '' ) ) )
		];

		if ( class_exists( 'CSV_Import_Notifications' ) && method_exists( 'CSV_Import_Notifications', 'update_notification_settings' ) ) {
			CSV_Import_Notifications::update_notification_settings( $settings );
			
			$GLOBALS['csv_import_admin_data']['action_result'] = [
				'success' => true,
				'message' => __( 'Benachrichtigungseinstellungen gespeichert.', 'csv-import' )
			];
		}
	}

	/**
	 * Behandelt Log-Bereinigung
	 */
	private function handle_clear_logs() {
		if ( class_exists( 'CSV_Import_Error_Handler' ) && method_exists( 'CSV_Import_Error_Handler', 'clear_error_log' ) ) {
			CSV_Import_Error_Handler::clear_error_log();
			
			wp_redirect( add_query_arg( 'logs_cleared', 'true', admin_url( 'tools.php?page=csv-import-logs' ) ) ); // KORRIGIERT
			exit;
		}
	}

	// ===================================================================
	// HILFSFUNKTIONEN FÜR TESTS UND DATENVERARBEITUNG
	// ===================================================================

	/**
	 * Testet Dropbox-Verbindung
	 * 
	 * @param string $url
	 * @return array
	 */
	private function test_dropbox_connection( $url ) {
		if ( empty( $url ) || strpos( $url, 'dropbox.com' ) === false ) {
			return [ 'success' => false, 'message' => 'Ungültige Dropbox-URL' ];
		}

		// URL für direkten Download konvertieren
		$download_url = str_replace( 'www.dropbox.com', 'dl.dropboxusercontent.com', $url );
		$download_url = str_replace( 'dropbox.com', 'dl.dropboxusercontent.com', $download_url );
		$download_url = str_replace( '?dl=0', '?raw=1', $download_url );

		$response = wp_remote_head( $download_url, [ 'timeout' => 10 ] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		
		return [
			'success' => $code === 200,
			'message' => $code === 200 ? 'Verbindung erfolgreich' : "HTTP Fehler: {$code}"
		];
	}

	/**
	 * Testet lokale Datei
	 * 
	 * @param string $path
	 * @return array
	 */
	private function test_local_file( $path ) {
		$full_path = ABSPATH . ltrim( $path, '/' );
		
		if ( ! file_exists( $full_path ) ) {
			return [ 'success' => false, 'message' => 'Datei nicht gefunden' ];
		}

		if ( ! is_readable( $full_path ) ) {
			return [ 'success' => false, 'message' => 'Datei nicht lesbar' ];
		}

		$size = filesize( $full_path );
		$modified = date( 'Y-m-d H:i:s', filemtime( $full_path ) );

		return [
			'success' => true,
			'message' => "Datei gefunden - Größe: " . size_format( $size ) . ", Geändert: {$modified}"
		];
	}

	/**
	 * Holt alle Plugin-Einstellungen
	 * 
	 * @return array
	 */
	private function get_all_plugin_settings() {
		global $wpdb;
		
		$options = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options} 
			 WHERE option_name LIKE 'csv_import_%'",
			ARRAY_A
		);

		$settings = [];
		foreach ( $options as $option ) {
			$settings[ $option['option_name'] ] = maybe_unserialize( $option['option_value'] );
		}

		return $settings;
	}

	/**
	 * Setzt Plugin-Daten zurück
	 * 
	 * @return array
	 */
	private function reset_plugin_data() {
		global $wpdb;

		// Plugin-Optionen löschen
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csv_import_%'" );

		// Transients löschen
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_csv_import_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_csv_import_%'" );

		// Cron-Jobs entfernen
		wp_clear_scheduled_hook( 'csv_import_daily_maintenance' );
		wp_clear_scheduled_hook( 'csv_import_weekly_maintenance' );

		// Backup-Tabelle leeren (nicht löschen)
		$backup_table = $wpdb->prefix . 'csv_import_backups';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$backup_table}'" ) ) {
			$wpdb->query( "TRUNCATE TABLE {$backup_table}" );
		}

		// Standard-Einstellungen wiederherstellen
		$defaults = $this->get_default_settings();
		foreach ( $defaults as $key => $value ) {
			update_option( 'csv_import_' . $key, $value );
		}

		return [
			'success' => true,
			'message' => 'Plugin erfolgreich zurückgesetzt'
		];
	}
}

// ===================================================================
// PLUGIN AKTIVIERUNG - KORRIGIERT
// ===================================================================

/**
 * Setzt Willkommens-Transient bei Plugin-Aktivierung
 */
register_activation_hook( CSV_IMPORT_PRO_BASENAME, function() {
	// Willkommens-Notice für 24 Stunden setzen
	set_transient( 'csv_import_show_welcome', true, DAY_IN_SECONDS );
	
	// Emergency-Admin-Access sicherstellen
	if ( current_user_can( 'activate_plugins' ) ) {
		$user = wp_get_current_user();
		if ( $user && ! $user->has_cap( 'edit_pages' ) ) {
			$user->add_cap( 'edit_pages' );
		}
	}
} );
