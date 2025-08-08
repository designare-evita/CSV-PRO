<?php
/**
 * View-Datei für die Scheduling Seite.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>CSV Import Scheduling</h1>

	<?php
	if ( isset( $action_result ) && is_array( $action_result ) ) {
		$notice_class   = $action_result['success'] ? 'notice-success' : 'notice-error';
		$notice_message = $action_result['message'];
		echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . wp_kses_post( $notice_message ) . '</p></div>';
	}
	?>

	<div class="csv-scheduling-dashboard">
		<?php if ( $is_scheduled ) : ?>
			<div class="card scheduled-active">
				<h2>⏰ Aktiver Zeitplan</h2>
				<div class="scheduling-status">
					<p><strong>Status:</strong> <span class="status-active">Aktiv</span></p>
					<p><strong>Quelle:</strong> <?php echo esc_html( ucfirst( $current_source ) ); ?></p>
					<p><strong>Frequenz:</strong> <?php echo esc_html( ucfirst( str_replace( '_', ' ', $current_frequency ) ) ); ?></p>
					<p><strong>Nächster Import:</strong>
						<?php
						echo $next_scheduled
							? esc_html( date_i18n( 'd.m.Y H:i:s', $next_scheduled ) )
							: 'Unbekannt';
						?>
					</p>
				</div>

				<form method="post">
					<?php wp_nonce_field( 'csv_import_scheduling' ); ?>
					<input type="hidden" name="action" value="unschedule_import">
					<button type="submit" class="button button-secondary" onclick="return confirm('Geplante Imports wirklich deaktivieren?');">
						⏹️ Scheduling deaktivieren
					</button>
				</form>
			</div>
		<?php else : ?>
			<div class="card">
				<h2>📅 Import planen</h2>
				<p>Planen Sie automatische CSV-Imports in regelmäßigen Abständen. Die aktuelle Konfiguration aus den <a href="<?php echo esc_url(admin_url('tools.php?page=csv-import-settings')); ?>">Einstellungen</a> wird verwendet.</p>

				<form method="post">
					<?php wp_nonce_field( 'csv_import_scheduling' ); ?>
					<input type="hidden" name="action" value="schedule_import">

					<table class="form-table">
						<tbody>
							<tr>
								<th scope="row"><label for="import_source">Import-Quelle</label></th>
								<td>
									<select id="import_source" name="import_source" required>
										<option value="">-- Quelle wählen --</option>
										<?php if ( $validation['dropbox_ready'] ) : ?>
											<option value="dropbox">☁️ Dropbox</option>
										<?php endif; ?>
										<?php if ( $validation['local_ready'] ) : ?>
											<option value="local">📁 Lokale Datei</option>
										<?php endif; ?>
									</select>
									<p class="description">Nur konfigurierte und verfügbare Quellen werden angezeigt.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="frequency">Frequenz</label></th>
								<td>
									<select id="frequency" name="frequency" required>
										<option value="">-- Frequenz wählen --</option>
										<option value="hourly">Stündlich</option>
                                        <option value="twicedaily">Zweimal täglich</option>
                                        <option value="daily">Täglich</option>
									</select>
									<p class="description">Wie oft soll der Import automatisch ausgeführt werden?</p>
								</td>
							</tr>
						</tbody>
					</table>
					<?php submit_button( 'Import planen', 'primary' ); ?>
				</form>
			</div>
		<?php endif; ?>

		<div class="card">
			<h2>📊 Scheduling-Historie</h2>
			<?php if ( empty( $scheduled_imports ) ) : ?>
				<p><em>Noch keine geplanten Imports ausgeführt.</em></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th>Zeitpunkt</th>
							<th>Status</th>
							<th>Nachricht</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scheduled_imports as $import ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'd.m.Y H:i:s', $import['time'] ) ); ?></td>
								<td>
									<?php if ( $import['level'] === 'info' ) : ?>
										<span style="color: green;">✅ Erfolg</span>
									<?php else : ?>
										<span style="color: red;">❌ Fehler</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $import['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="card">
			<h2>⚙️ Benachrichtigungen für geplante Imports</h2>
			<form method="post">
				<?php wp_nonce_field( 'csv_import_notification_settings' ); ?>
				<input type="hidden" name="action" value="update_notifications">

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">E-Mail bei Erfolg</th>
							<td>
								<label>
									<input type="checkbox" name="email_on_success" value="1"
										   <?php checked( $notification_settings['email_on_success'] ?? false ); ?>>
									E-Mail senden, wenn Import erfolgreich war
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">E-Mail bei Fehlern</th>
							<td>
								<label>
									<input type="checkbox" name="email_on_failure" value="1"
										   <?php checked( $notification_settings['email_on_failure'] ?? true ); ?>>
									E-Mail senden, wenn Import fehlschlägt
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="recipients">E-Mail-Empfänger</label></th>
							<td>
								<textarea id="recipients" name="recipients" rows="3" class="large-text"><?php
									$recipients = $notification_settings['recipients'] ?? [ get_option( 'admin_email' ) ];
									echo esc_textarea( implode( "\n", $recipients ) );
								?></textarea>
								<p class="description">Eine E-Mail-Adresse pro Zeile.</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( 'Benachrichtigungen speichern' ); ?>
			</form>
		</div>
	</div>
</div>
