<?php
/**
 * View-Datei für die Logs & Monitoring Seite.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>CSV Import Logs &amp; Monitoring</h1>

	<?php
	if ( isset( $_GET['logs_cleared'] ) && $_GET['logs_cleared'] === 'true' ) {
		echo '<div class="notice notice-success is-dismissible"><p>Alle Logs wurden gelöscht.</p></div>';
	}
	?>

	<div class="csv-logs-dashboard">
		<div class="status-overview">
			<div class="status-card">
				<h3>System Health</h3>
				<?php foreach ( $health as $check => $status ) : ?>
					<div class="status-item <?php echo $status ? 'status-ok' : 'status-error'; ?>">
						<?php echo $status ? '✅' : '❌'; ?>
						<?php echo esc_html( ucfirst( str_replace( '_ok', '', str_replace( '_', ' ', $check ) ) ) ); ?>
					</div>
				<?php endforeach; ?>
			</div>
			
			<div class="status-card">
				<h3>Fehler-Statistiken</h3>
				<div class="error-stats">
					<div class="stat-item">
						<strong><?php echo esc_html( $error_stats['total_errors'] ?? 0 ); ?></strong>
						<span>Gesamt Meldungen</span>
					</div>
					<?php foreach ( $error_stats['errors_by_level'] ?? [] as $level => $count ) : ?>
						<div class="stat-item level-<?php echo esc_attr( $level ); ?>">
							<strong><?php echo esc_html( $count ); ?></strong>
							<span><?php echo esc_html( ucfirst( $level ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
			
			<div class="status-card">
				<h3>Import-Fortschritt</h3>
				<?php $progress = csv_import_get_progress(); ?>
				<?php if ( $progress['status'] === 'processing' ) : ?>
					<div class="progress-active">
						<div class="progress-bar">
							<div class="progress-fill" style="width: <?php echo esc_attr( $progress['percent'] ); ?>%"></div>
						</div>
						<p><?php echo esc_html( $progress['processed'] ); ?>/<?php echo esc_html( $progress['total'] ); ?> (<?php echo esc_html( $progress['percent'] ); ?>%)</p>
					</div>
				<?php else : ?>
					<p>Kein Import aktiv</p>
				<?php endif; ?>
			</div>
		</div>
		
		<div class="card">
			<div class="log-filters">
				<a href="<?php echo esc_url( admin_url('tools.php?page=csv-import-logs') ); ?>" 
				   class="filter-button <?php echo $filter_level === 'all' ? 'active' : ''; ?>">
					Alle (<?php echo count( $error_stats['recent_errors'] ?? [] ); ?>)
				</a>
				<?php foreach ( ['critical', 'error', 'warning', 'info'] as $level ) : ?>
					<?php 
					$level_count = $error_stats['errors_by_level'][$level] ?? 0;
					if ($level_count > 0) :
					?>
					<a href="<?php echo esc_url( admin_url('tools.php?page=csv-import-logs&level=' . $level) ); ?>" 
					   class="filter-button filter-<?php echo esc_attr($level); ?> <?php echo $filter_level === $level ? 'active' : ''; ?>">
						<?php echo esc_html( ucfirst($level) ); ?> (<?php echo esc_html($level_count); ?>)
					</a>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</div>
		
		<div class="card">
			<h2>📋 Import Logs</h2>
			
			<?php if ( empty( $logs ) ) : ?>
				<p><em>Keine Logs für den gewählten Filter gefunden.</em></p>
			<?php else : ?>
				<div class="log-table-wrapper">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width:150px;">Zeitpunkt</th>
								<th style="width:90px;">Level</th>
								<th>Nachricht</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $log_entry ) : ?>
								<tr class="log-row log-level-<?php echo esc_attr( $log_entry['level'] ); ?>">
									<td><?php echo esc_html( mysql2date('d.m.Y H:i:s', $log_entry['time']) ); ?></td>
									<td>
										<span class="log-level-badge level-<?php echo esc_attr( $log_entry['level'] ); ?>">
											<?php echo esc_html( strtoupper( $log_entry['level'] ) ); ?>
										</span>
									</td>
									<td>
										<div class="log-message">
											<?php echo esc_html( $log_entry['message'] ); ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				
				<?php if ( $total_pages > 1 ) : ?>
					<div class="tablenav bottom">
						<div class="tablenav-pages">
							<span class="displaying-num"><?php echo esc_html( $total_logs ); ?> Einträge</span>
							<?php
							$base_url = admin_url('tools.php?page=csv-import-logs');
							if ( $filter_level !== 'all' ) {
								$base_url = add_query_arg( 'level', $filter_level, $base_url );
							}

							echo paginate_links( [
								'base'    => $base_url . '%_%',
								'format'  => '&paged=%#%',
								'current' => $page,
								'total'   => $total_pages,
								'prev_text' => '«',
								'next_text' => '»',
							] );
							?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			
			<div class="log-management">
				<h3>Log-Verwaltung</h3>
				<form method="post" onsubmit="return confirm('Alle Logs wirklich löschen?');">
					<?php wp_nonce_field( 'csv_import_clear_logs' ); ?>
					<input type="hidden" name="action" value="clear_logs">
					<button type="submit" class="button">🗑️ Alle Logs löschen</button>
				</form>
			</div>
		</div>
		
		<div class="card">
			<h2>📈 Performance Trends (Fehler pro Tag)</h2>
			<div class="performance-chart">
				<?php if ( ! empty( $error_stats['error_trends'] ) ) : ?>
					<canvas id="errorTrendChart" width="400" height="200"></canvas>
				<?php else : ?>
					<p><em>Noch keine Trend-Daten verfügbar.</em></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>