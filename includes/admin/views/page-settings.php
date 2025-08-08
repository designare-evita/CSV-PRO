<?php
/**
 * View-Datei für die Einstellungsseite.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><div class="wrap">
	<h1>CSV Import Einstellungen</h1>

	<?php settings_errors(); ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'csv_import_settings' ); ?>
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
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="csv-settings-card card">
				<h2>🎯 Erweitert</h2>
				<table class="form-table" role="presentation">
					<tbody>
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
		</div>
		<?php submit_button( 'Einstellungen speichern' ); ?>
	</form>
</div>
