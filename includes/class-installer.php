<?php
/**
 * Behandelt die Aktivierungs- und Deinstallations-Logik des Plugins.
 */
class Installer {

    /**
     * Wird bei der Plugin-Aktivierung ausgeführt.
     */
    public static function activate() {
        try {
            // Verzeichnisse erstellen
            $image_folder_path = get_option('csv_import_image_folder', 'wp-content/uploads/csv-import-images/');
            $directories = [
                ABSPATH . 'data/',
                ABSPATH . $image_folder_path
            ];

            foreach ($directories as $dir) {
                if (!file_exists($dir)) {
                    if (!wp_mkdir_p($dir) || !is_writable($dir)) {
                        throw new Exception("Konnte Verzeichnis nicht erstellen oder es ist nicht beschreibbar: $dir");
                    }

                    // .htaccess für Sicherheit hinzufügen
                    $htaccess_file = trailingslashit($dir) . '.htaccess';
                    if (!file_exists($htaccess_file)) {
                        @file_put_contents($htaccess_file, "Options -Indexes\nDeny from all");
                    }
                }
            }

            // Standard-Einstellungen setzen, falls nicht vorhanden
            $defaults_to_check = ['template_id', 'post_type', 'post_status', 'page_builder', 'required_columns'];
            foreach ($defaults_to_check as $key) {
                if (get_option('csv_import_' . $key) === false) {
                    update_option('csv_import_' . $key, csv_import_get_default_value($key));
                }
            }

            // Plugin-Version speichern
            update_option('csv_import_version', '5.1');

            CSV_Import_Error_Handler::handle(
                CSV_Import_Error_Handler::LEVEL_INFO,
                'CSV Import System V5.1 aktiviert'
            );

        } catch (Exception $e) {
            // Bei einem Fehler während der Aktivierung, das Plugin sofort wieder deaktivieren.
            deactivate_plugins(plugin_basename(CSV_IMPORT_PRO_PATH . 'csv-import-pro.php'));
            wp_die('Plugin-Aktivierung fehlgeschlagen: ' . $e->getMessage());
        }
    }
}