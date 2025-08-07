<?php
/**
 * View-Datei für die Einstellungsseite.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>CSV Import Einstellungen</h1>

	<?php
	if ( isset( $_GET['settings-updated'] ) ) {
		settings_errors();
	}
	?>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'csv_import_settings' );
		?>
		<div class="csv-settings-grid">

			<div class="csv-settings-card card">
				<h2>📋 Basis-Konfiguration</h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_template_id">Template-Post ID</label></th>
							<td>
								<input type="number" id="csv_import_template_id" name="csv_import_template_id"
									   value="<?php echo esc_attr( get_option( 'csv_import_template_id' ) ); ?>"
									   class="small-text">
								<p class="description">
									ID der Vorlage. Aktuell: <?php echo csv_import_get_template_info(); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_page_builder">Page Builder / Editor</label></th>
							<td>
								<select id="csv_import_page_builder" name="csv_import_page_builder">
									<?php
									$pb_options = [ 'gutenberg' => 'Gutenberg (Standard)', 'elementor' => 'Elementor', 'wpbakery' => 'WPBakery' ];
									$current_pb = get_option( 'csv_import_page_builder', 'gutenberg' );
									foreach ( $pb_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_pb, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description">Wählen Sie den Editor, mit dem das Template erstellt wurde.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_post_type">Post-Typ</label></th>
							<td>
								<select id="csv_import_post_type" name="csv_import_post_type">
									<?php
									$post_types    = get_post_types( [ 'public' => true ], 'objects' );
									$current_ptype = get_option( 'csv_import_post_type', 'page' );
									foreach ( $post_types as $post_type ) {
										echo '<option value="' . esc_attr( $post_type->name ) . '" ' . selected( $current_ptype, $post_type->name, false ) . '>' . esc_html( $post_type->label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_post_status">Post-Status</label></th>
							<td>
								<select id="csv_import_post_status" name="csv_import_post_status">
									<?php
									$status_options = [ 'draft' => 'Entwurf', 'publish' => 'Veröffentlicht', 'private' => 'Privat', 'pending' => 'Ausstehend' ];
									$current_status = get_option( 'csv_import_post_status', 'draft' );
									foreach ( $status_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_status, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-settings-card card">
				<h2>🔗 Quellen</h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_dropbox_url">Dropbox CSV-URL</label></th>
							<td>
								<input type="url" id="csv_import_dropbox_url" name="csv_import_dropbox_url"
									   value="<?php echo esc_attr( get_option( 'csv_import_dropbox_url' ) ); ?>"
									   class="regular-text" placeholder="https://www.dropbox.com/s/...?dl=1">
								<p class="description">Direkt-Download-Link. Muss mit `?dl=1` enden.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_local_path">Lokaler CSV-Pfad</label></th>
							<td>
								<input type="text" id="csv_import_local_path" name="csv_import_local_path"
									   value="<?php echo esc_attr( get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?>"
									   class="regular-text">
								<p class="description">
									Pfad relativ zum WordPress-Root:
									<code><?php echo esc_html( ABSPATH . get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?></code>
									<?php echo csv_import_get_file_status( ABSPATH . get_option( 'csv_import_local_path', 'data/landingpages.csv' ) ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-settings-card card">
				<h2>🖼️ Medien-Einstellungen</h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_image_source">Bildquelle</label></th>
							<td>
								<select id="csv_import_image_source" name="csv_import_image_source">
									<?php
									$img_src_options = [ 'media_library' => 'WordPress-Mediathek', 'local_folder' => 'Lokaler Ordner' ];
									$current_img_src = get_option( 'csv_import_image_source', 'media_library' );
									foreach ( $img_src_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_img_src, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_image_folder">Lokaler Bild-Ordner</label></th>
							<td>
								<input type="text" id="csv_import_image_folder" name="csv_import_image_folder"
									   value="<?php echo esc_attr( get_option( 'csv_import_image_folder', 'wp-content/uploads/csv-import-images/' ) ); ?>"
									   class="regular-text">
								<p class="description">
									Pfad relativ zum WordPress-Root.
									<?php echo csv_import_get_file_status( ABSPATH . get_option( 'csv_import_image_folder' ), true ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-settings-card card">
				<h2>🎯 SEO &amp; Erweitert</h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="csv_import_seo_plugin">SEO-Plugin</label></th>
							<td>
								<select id="csv_import_seo_plugin" name="csv_import_seo_plugin">
									<?php
									$seo_options    = [ 'none' => 'Keins / Manuell', 'aioseo' => 'All in One SEO', 'yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math' ];
									$current_seo_pl = get_option( 'csv_import_seo_plugin', 'none' );
									foreach ( $seo_options as $val => $label ) {
										echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current_seo_pl, $val, false ) . '>' . esc_html( $label ) . '</option>';
									}
									?>
								</select>
								<p class="description">Wähle dein aktives SEO-Plugin zur Befüllung der Meta-Daten.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="csv_import_required_columns">Erforderliche Spalten</label></th>
							<td>
								<textarea id="csv_import_required_columns" name="csv_import_required_columns" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'csv_import_required_columns', "post_title\npost_name" ) ); ?></textarea>
								<p class="description">Eine Spalte pro Zeile.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Duplikate</th>
							<td>
								<label>
									<input type="checkbox" name="csv_import_skip_duplicates" value="1"
										<?php checked( get_option( 'csv_import_skip_duplicates' ), 1 ); ?> >
									Duplikate überspringen (basierend auf Post-Titel)
								</label>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			
			<div class="csv-settings-card card">
				<h2>🧪 Konfiguration testen</h2>
				<p>Überprüfen Sie Ihre Einstellungen und die CSV-Dateien vor dem Import.</p>
				<p>
					<button type="button" class="button button-secondary" onclick="csvImportTestConfig()">⚙️ Konfiguration prüfen</button>
					<button type="button" class="button button-secondary" onclick="csvImportValidateCSV('dropbox')">📊 Dropbox CSV validieren</button>
					<button type="button" class="button button-secondary" onclick="csvImportValidateCSV('local')">📁 Lokale CSV validieren</button>
				</p>
				<div id="csv-test-results" class="test-results-container"></div>
			</div>

			<div class="csv-settings-card card">
				<h2>📊 CSV Beispieldaten</h2>
				<p class="description">Nach einer erfolgreichen CSV-Validierung werden hier die ersten Zeilen angezeigt.</p>
				<div id="csv-sample-data-container" class="test-results-container">
					</div>
			</div>

		</div>
		<?php submit_button( 'Einstellungen speichern' ); ?>
	</form>
</div>
