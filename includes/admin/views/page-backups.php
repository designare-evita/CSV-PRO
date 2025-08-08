<?php
/**
 * View-Datei für die Backup & Rollback Seite.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><div class="wrap">
    <h1>CSV Import Backups &amp; Rollback</h1>

    <?php
    if ( isset( $rollback_result ) ) {
        if ( $rollback_result['success'] ) {
            echo '<div class="notice notice-success is-dismissible"><p>Rollback erfolgreich: ' . esc_html( $rollback_result['restored'] ) . ' Posts entfernt.</p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>Rollback-Fehler: ' . esc_html( implode( ', ', $rollback_result['errors'] ) ) . '</p></div>';
        }
    }
    ?>
    
    <div class="csv-backup-dashboard">
        <div class="card">
            <h2>📦 Import-Sessions</h2>
            <p>Hier können Sie vergangene Imports rückgängig machen. <strong>Achtung:</strong> Ein Rollback löscht alle durch den Import erstellten Posts unwiderruflich.</p>
            
            <?php if ( empty( $sessions ) ) : ?>
                <p><em>Keine Import-Sessions mit Backups gefunden.</em></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Session ID</th>
                            <th>Import-Datum</th>
                            <th>Quelle</th>
                            <th>Anzahl Posts</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sessions as $session ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $session->import_session ); ?></code></td>
                                <td><?php echo mysql2date( 'd.m.Y H:i:s', $session->import_date ); ?></td>
                                <td>
                                    <?php 
                                    $source_labels = [ 'dropbox' => '☁️ Dropbox', 'local' => '📁 Lokal' ];
                                    echo esc_html( $source_labels[ $session->import_source ] ?? $session->import_source );
                                    ?>
                                </td>
                                <td><?php echo esc_html( $session->post_count ); ?> Posts</td>
                                <td>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('Wirklich alle <?php echo esc_js( $session->post_count ); ?> Posts aus diesem Import löschen?');">
                                        <?php wp_nonce_field( 'csv_import_rollback' ); ?>
                                        <input type="hidden" name="rollback_session" value="<?php echo esc_attr( $session->import_session ); ?>">
                                        <button type="submit" class="button button-secondary">🔄 Rollback</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
