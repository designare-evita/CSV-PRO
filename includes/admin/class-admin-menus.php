<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff auf die Datei verhindern
}

/**
 * Erstellt die Admin-Menüs und steuert die Anzeige der Plugin-Seiten.
 * Version 6.0 - Umstellung auf Admin Top Bar
 */
class CSV_Import_Pro_Admin {

	public function __construct() {
		// KORREKTUR: Hook geändert von 'admin_menu' auf 'admin_bar_menu'
		add_action( 'admin_bar_menu', [ $this, 'register_admin_bar_menu' ], 999 );
		
		// Diese Hooks bleiben unverändert
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_init', [ $this, 'register_plugin_settings' ] );
		
		// Wir brauchen immer noch die Seiten, aber sie sind "versteckt"
		add_action( 'admin_menu', [ $this, 'register_hidden_admin_pages' ] );
	}

	/**
	 * Fügt den Menüpunkt zur oberen Admin-Leiste hinzu.
	 */
	public function register_admin_bar_menu( $wp_admin_bar ) {
		// Hauptmenüpunkt (Eltern-Element)
		$wp_admin_bar->add_node( [
			'id'    => 'csv_importer_main',
			'title' => '<span class="ab-icon dashicons-database-import"></span>CSV Importer', // Titel mit Icon
			'href'  => admin_url( 'tools.php?page=csv-import' ),
		] );

		// Untermenüpunkte
		$submenus = [
			'import'    => [ 'title' => 'Import', 'slug' => 'csv-import' ],
			'settings'  => [ 'title' => 'Einstellungen', 'slug' => 'csv-import-settings' ],
			'backups'   => [ 'title' => 'Backups', 'slug' => 'csv-import-backups' ],
			'profiles'  => [ 'title' => 'Profile', 'slug' => 'csv-import-profiles' ],
			'scheduling'=> [ 'title' => 'Scheduling', 'slug' => 'csv-import-scheduling' ],
			'logs'      => [ 'title' => 'Logs', 'slug' => 'csv-import-logs' ],
			'advanced'  => [ 'title' => 'Erweiterte Einstellungen', 'slug' => 'csv-import-advanced' ],
		];

		foreach ( $submenus as $id => $menu ) {
			$wp_admin_bar->add_node( [
				'id'     => 'csv_importer_' . $id,
				'parent' => 'csv_importer_main', // Wichtig: Dem Hauptmenü zuordnen
				'title'  => $menu['title'],
				'href'   => admin_url( 'tools.php?page=' . $menu['slug'] ),
			] );
		}
	}

	/**
	 * Registriert die Admin-Seiten, ohne sie im Hauptmenü anzuzeigen.
	 * Dies ist notwendig, damit die Seiten-URLs weiterhin funktionieren.
	 */
	public function register_hidden_admin_pages() {
		add_submenu_page(
			null, // Wichtig: null, um die Seite zu verstecken
			'CSV Import',
			'CSV Import',
			'manage_options',
			'csv-import',
			[ $this, 'display_main_page' ]
		);
		add_submenu_page(
			null,
			'CSV Import Einstellungen',
			'CSV Einstellungen',
			'manage_options',
			'csv-import-settings',
			[ $this, 'display_settings_page' ]
		);
		add_submenu_page(
			null,
			'CSV Import Backups',
			'Import Backups',
			'manage_options',
			'csv-import-backups',
			[ $this, 'display_backup_page' ]
		);
		add_submenu_page(
			null,
			'CSV Import Profile',
			'Import Profile',
			'manage_options',
			'csv-import-profiles',
			[ $this, 'display_profiles_page' ]
		);
		add_submenu_page(
			null,
			'CSV Import Scheduling',
			'Import Scheduling',
			'manage_options',
			'csv-import-scheduling',
			[ $this, 'display_scheduling_page' ]
		);
		add_submenu_page(
			null,
			'CSV Import Logs',
			'Import Logs',
			'manage_options',
			'csv-import-logs',
			[ $this, 'display_logs_page' ]
		);
		add_submenu_page(
			null,
			'CSV Import Erweitert',
			'Erweiterte Einstellungen',
			'manage_options',
			'csv-import-advanced',
			[ $this, 'display_advanced_settings_page' ]
		);
	}

	public function enqueue_admin_assets( $hook ) {
		// Diese Funktion bleibt unverändert...
		if ( strpos( $hook, 'csv-import' ) === false ) {
			return;
		}

		wp_enqueue_style( 'csv-import-pro-admin-style', CSV_IMPORT_PRO_URL . 'assets/css/admin-style.css', [], CSV_IMPORT_PRO_VERSION );
		wp_enqueue_script( 'csv-import-pro-admin-script', CSV_IMPORT_PRO_URL . 'assets/js/admin-script.js', [ 'jquery' ], CSV_IMPORT_PRO_VERSION, true );

		if (strpos($hook, 'csv-import-logs') !== false) {
			wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.1', true );
		}

		wp_localize_script( 'csv-import-pro-admin-script', 'csvImportAjax', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'csv_import_ajax' ),
		]);
	}

	public function register_plugin_settings() {
		// Diese Funktion bleibt unverändert...
		$settings = [
			'template_id'      => ['type' => 'integer', 'sanitize' => 'absint'],
			'post_type'        => ['type' => 'string',  'sanitize' => 'sanitize_key'],
			'post_status'      => ['type' => 'string',  'sanitize' => 'sanitize_key'],
			'page_builder'     => ['type' => 'string',  'sanitize' => 'sanitize_key'],
			'dropbox_url'      => ['type' => 'string',  'sanitize' => 'esc_url_raw'],
			'local_path'       => ['type' => 'string',  'sanitize' => 'sanitize_text_field'],
			'image_source'     => ['type' => 'string',  'sanitize' => 'sanitize_key'],
			'image_folder'     => ['type' => 'string',  'sanitize' => 'sanitize_text_field'],
			'memory_limit'     => ['type' => 'string',  'sanitize' => 'sanitize_text_field'],
			'time_limit'       => ['type' => 'integer', 'sanitize' => 'absint'],
			'seo_plugin'       => ['type' => 'string',  'sanitize' => 'sanitize_key'],
			'required_columns' => ['type' => 'string',  'sanitize' => 'sanitize_textarea_field'],
			'skip_duplicates'  => ['type' => 'boolean', 'sanitize' => 'rest_sanitize_boolean']
		];

		foreach ( $settings as $key => $config ) {
			register_setting('csv_import_settings', 'csv_import_' . $key, [
				'type'              => $config['type'],
				'sanitize_callback' => $config['sanitize'],
				'default'           => csv_import_get_default_value($key),
				'show_in_rest'      => true,
			]);
		}
	}

	// Die Callback-Funktionen für die Seitenanzeige bleiben alle unverändert
	public function display_main_page() { require_once CSV_IMPORT_PRO_PATH . 'includes/admin/views/page-main.php'; }
	public function display_settings_page() { require_once CSV_IMPORT_PRO_PATH . 'includes/admin/views/page-settings.php'; }
	public function display_backup_page() { /* Logik... */ require_once CSV_IMPORT_PRO_PATH . 'includes/admin/views/page-backups.php'; }
	public function display_profiles_page() { /* Logik... */ require_once CSV_IMPORT_PRO_PATH . 'includes/admin/views/page-profiles.php'; }
	public function display_scheduling_page() { /* Logik... */ require_once CSV_IMPORT_PRO_PATH . 'includes/admin/views/page-scheduling.php'; }
	public function display_logs_page() { /* Logik... */ require_once CSV_IMPORT_PRO_PATH . 'includes/admin/views/page-logs.php'; }
	public function display_advanced_settings_page() { /* Logik... */ require_once CSV_IMPORT_PRO_PATH . 'includes/admin/views/page-advanced-settings.php'; }
}
