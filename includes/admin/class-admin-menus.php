<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direkten Zugriff auf die Datei verhindern
}

/**
 * Erstellt die Admin-Menüs und steuert die Anzeige der Plugin-Seiten.
 * * Version 8.1 - Korrigierte Menü-Registrierung unter "Werkzeuge"
 * * @since 6.0
 */
class CSV_Import_Pro_Admin {

	private $menu_slug = 'csv-import';
	private $admin_pages = [];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        // Weitere Hooks bleiben hier unverändert...
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_init', [ $this, 'register_plugin_settings' ] );
        add_action( 'admin_notices', [ $this, 'show_plugin_notices' ] );
	}

	public function register_admin_menu() {
        // Hauptseite unter "Werkzeuge" hinzufügen
        $main_page_hook = add_management_page(
            __( 'CSV Import Pro', 'csv-import' ),
            __( 'CSV Import', 'csv-import' ),
            'edit_pages',
            $this->menu_slug,
            [ $this, 'display_main_page' ]
        );
        $this->admin_pages['main'] = $main_page_hook;

        // Untermenüs hinzufügen
        $submenus = [
            'settings' => [
                'page_title' => __( 'CSV Import Einstellungen', 'csv-import' ),
                'menu_title' => __( 'Einstellungen', 'csv-import' ),
                'capability' => 'edit_pages',
                'menu_slug'  => 'csv-import-settings',
                'callback'   => [ $this, 'display_settings_page' ]
            ],
            'backups' => [
                'page_title' => __( 'CSV Import Backups', 'csv-import' ),
                'menu_title' => __( 'Backups & Rollback', 'csv-import' ),
                'capability' => 'edit_pages',
                'menu_slug'  => 'csv-import-backups',
                'callback'   => [ $this, 'display_backup_page' ]
            ],
            'profiles' => [
                'page_title' => __( 'CSV Import Profile', 'csv-import' ),
                'menu_title' => __( 'Import-Profile', 'csv-import' ),
                'capability' => 'edit_pages',
                'menu_slug'  => 'csv-import-profiles',
                'callback'   => [ $this, 'display_profiles_page' ]
            ],
            'scheduling' => [
                'page_title' => __( 'CSV Import Automatisierung', 'csv-import' ),
                'menu_title' => __( 'Automatisierung', 'csv-import' ),
                'capability' => 'manage_options', // Nur für Admins
                'menu_slug'  => 'csv-import-scheduling',
                'callback'   => [ $this, 'display_scheduling_page' ]
            ],
            'logs' => [
                'page_title' => __( 'CSV Import Logs', 'csv-import' ),
                'menu_title' => __( 'Logs & Monitoring', 'csv-import' ),
                'capability' => 'edit_pages',
                'menu_slug'  => 'csv-import-logs',
                'callback'   => [ $this, 'display_logs_page' ]
            ]
        ];

        // Das erste Untermenü muss der Hauptseite entsprechen, aber wir verstecken es nicht
        add_submenu_page(
            'tools.php',
            __( 'CSV Import Dashboard', 'csv-import' ),
            __( 'Import Dashboard', 'csv-import' ),
            'edit_pages',
            $this->menu_slug,
            [ $this, 'display_main_page' ]
        );

        foreach ( $submenus as $key => $submenu ) {
            $submenu_hook = add_submenu_page(
                'tools.php', // Alle unter "Werkzeuge"
                $submenu['page_title'],
                $submenu['menu_title'],
                $submenu['capability'],
                $submenu['menu_slug'],
                $submenu['callback']
            );
            $this->admin_pages[$key] = $submenu_hook;
        }
	}

    // Alle anderen Funktionen der Klasse bleiben unverändert...
    // (display_main_page, display_settings_page etc.)
    
    public function display_main_page() { $this->render_page('page-main.php'); }
    public function display_settings_page() { $this->render_page('page-settings.php'); }
    public function display_backup_page() { $this->render_page('page-backups.php'); }
    public function display_profiles_page() { $this->render_page('page-profiles.php'); }
    public function display_scheduling_page() { $this->render_page('page-scheduling.php'); }
    public function display_logs_page() { $this->render_page('page-logs.php'); }

    private function render_page($template_file) {
        $data = []; // Platzhalter für Daten, die an die View übergeben werden
        if (function_exists('csv_import_get_progress')) {
            $data['progress'] = csv_import_get_progress();
        }
        if (function_exists('csv_import_get_config')) {
            $config = csv_import_get_config();
            $data['config'] = $config;
            if(function_exists('csv_import_validate_config')) {
                $data['config_valid'] = csv_import_validate_config($config);
            }
        }
        if (function_exists('csv_import_system_health_check')) {
            $data['health'] = csv_import_system_health_check();
        }
        if (function_exists('csv_import_get_stats')) {
            $data['stats'] = csv_import_get_stats();
        }
        if (class_exists('CSV_Import_Scheduler') && method_exists('CSV_Import_Scheduler', 'get_scheduler_info')) {
            $data = array_merge($data, CSV_Import_Scheduler::get_scheduler_info());
        }
        if (class_exists('CSV_Import_Backup_Manager') && method_exists('CSV_Import_Backup_Manager', 'get_import_sessions')) {
            $data['sessions'] = CSV_Import_Backup_Manager::get_import_sessions();
        }
         if (class_exists('CSV_Import_Profile_Manager') && method_exists('CSV_Import_Profile_Manager', 'get_profiles')) {
            $data['profiles'] = CSV_Import_Profile_Manager::get_profiles();
        }
        if (function_exists('csv_import_get_error_stats')) {
            $data['error_stats'] = csv_import_get_error_stats();
        }
        if (class_exists('CSV_Import_Error_Handler') && method_exists('CSV_Import_Error_Handler', 'get_persistent_errors')) {
            $data['logs'] = CSV_Import_Error_Handler::get_persistent_errors();
        }

        extract($data);

        $template_path = CSV_IMPORT_PRO_PATH . 'includes/admin/views/' . $template_file;
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            echo '<div class="wrap"><h2>Template-Datei nicht gefunden</h2><p>Die Datei ' . esc_html($template_file) . ' konnte nicht geladen werden.</p></div>';
        }
    }

    public function enqueue_admin_assets($hook_suffix) {
        if (strpos($hook_suffix, 'csv-import') === false) {
            return;
        }
        wp_enqueue_style(
            'csv-import-pro-admin-style',
            CSV_IMPORT_PRO_URL . "assets/css/admin-style.css",
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'csv-import-pro-admin-script',
            CSV_IMPORT_PRO_URL . "assets/js/admin-script.js",
            ['jquery'],
            '1.0.0',
            true
        );
        wp_localize_script('csv-import-pro-admin-script', 'csvImportAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('csv_import_ajax')
        ]);
    }
    
    public function register_plugin_settings() {
        $settings = ['template_id', 'post_type', 'post_status', 'page_builder', 'dropbox_url', 'local_path', 'image_source', 'image_folder', 'memory_limit', 'time_limit', 'seo_plugin', 'required_columns', 'skip_duplicates'];
        foreach ($settings as $setting) {
            register_setting('csv_import_settings', 'csv_import_' . $setting);
        }
    }
    
    public function show_plugin_notices() {
        if (isset($_GET['page']) && strpos($_GET['page'], 'csv-import') !== false) {
            if (get_transient('csv_import_stuck_reset_notice')) {
                echo '<div class="notice notice-warning is-dismissible"><p><strong>CSV Import:</strong> Ein hängender Import-Prozess wurde automatisch zurückgesetzt.</p></div>';
                delete_transient('csv_import_stuck_reset_notice');
            }
        }
    }
}
