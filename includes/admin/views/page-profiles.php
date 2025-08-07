<?php
/**
 * View-Datei für die Profil-Management Seite.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>CSV Import Profile</h1>

	<?php
	if ( isset( $action_result ) && is_array( $action_result ) ) {
		$notice_class   = $action_result['success'] ? 'notice-success' : 'notice-error';
		$notice_message = $action_result['message'];
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $notice_message ) . '</p></div>';
	}
	?>

	<div class="csv-profiles-dashboard">
		<div class="card">
			<h2>💾 Aktuelles Profil speichern</h2>
			<p>Speichern Sie Ihre aktuelle CSV-Import-Konfiguration als wiederverwendbares Profil.</p>
			
			<form method="post">
				<?php wp_nonce_field( 'csv_import_save_profile' ); ?>
				<input type="hidden" name="action" value="save_profile">
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="profile_name">Profil-Name</label></th>
							<td>
								<input type="text" id="profile_name" name="profile_name" class="regular-text" placeholder="z.B. Landingpages Standard" required>
								<p class="description">Eindeutiger Name für das Profil</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( 'Profil speichern', 'primary', 'save_profile' ); ?>
			</form>
		</div>
		
		<div class="card">
			<h2>📋 Gespeicherte Profile</h2>
			
			<?php if ( empty( $profiles ) ) : ?>
				<p><em>Noch keine Profile gespeichert.</em></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Profil-Name</th>
							<th>Erstellt</th>
							<th>Letzte Nutzung</th>
							<th style="width: 80px;">Nutzungen</th>
							<th style="width: 200px;">Aktionen</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $profiles as $profile_id => $profile ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $profile['name'] ); ?></strong></td>
								<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $profile['created_at'] ) ); ?></td>
								<td>
									<?php
									echo esc_html( $profile['last_used']
										? mysql2date( 'd.m.Y H:i', $profile['last_used'] )
										: 'Nie verwendet' );
									?>
								</td>
								<td><?php echo esc_html( $profile['use_count'] ); ?>x</td>
								<td>
									<form method="post" style="display: inline;">
										<?php wp_nonce_field( 'csv_import_load_profile' ); ?>
										<input type="hidden" name="action" value="load_profile">
										<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>">
										<button type="submit" class="button button-primary">📂 Laden</button>
									</form>
									
									<form method="post" style="display: inline;" onsubmit="return confirm('Profil wirklich löschen?');">
										<?php wp_nonce_field( 'csv_import_delete_profile' ); ?>
										<input type="hidden" name="action" value="delete_profile">
										<input type="hidden" name="profile_id" value="<?php echo esc_attr( $profile_id ); ?>">
										<button type="submit" class="button button-secondary">🗑️ Löschen</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				
				<h3 style="margin-top: 2em;">Profil-Details</h3>
				<div id="profile-details" class="profile-details-container">
					<p><em>Klicken Sie auf ein Profil in der Tabelle, um die gespeicherte Konfiguration anzuzeigen.</em></p>
				</div>
			<?php endif; ?>
		</div>
	</div>
</div>