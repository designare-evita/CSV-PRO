/**
 * Admin JavaScript für das CSV Import Pro Plugin.
 * Version: 5.6 - Layout Anpassung
 */
jQuery(document).ready(function($) {

    const csvImportDebug = {
        log: function(message, data) { console.log('🔧 CSV Import:', message, data || ''); },
        warn: function(message, data) { console.warn('⚠️ CSV Import:', message, data || ''); },
        error: function(message, data) { console.error('❌ CSV Import:', message, data || ''); }
    };

    const resultsContainer = $('#csv-test-results');
    // KORREKTUR: Neuer Container für die Beispieldaten
    const sampleDataContainer = $('#csv-sample-data-container');
    const importButtons = $('.csv-import-btn');

    // KORREKTUR: Funktion aufgeteilt, um Ergebnisse getrennt anzuzeigen
    function showTestResult(response) {
        const data = response.success ? response.data : response.data || { message: 'Ein unbekannter Fehler ist aufgetreten.' };
        const resultClass = response.success ? 'test-success' : 'test-error';
        
        // Statusmeldung in der Test-Box anzeigen
        resultsContainer.html(`<div class="test-result ${resultClass}">${data.message}</div>`);
        
        // Beispieldaten-Box leeren oder befüllen
        if (response.success && data.columns && data.sample_data) {
            let tableHtml = `
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr><th>${data.columns.slice(0, 5).join('</th><th>')}...</th></tr>
                    </thead>
                    <tbody>
                        ${data.sample_data.map(row => `<tr><td>${row.join('</td><td>')}</td></tr>`).join('')}
                    </tbody>
                </table>
            `;
            sampleDataContainer.html(tableHtml);
        } else {
            sampleDataContainer.html(''); // Box leeren bei Fehler oder Konfig-Test
        }
    }
    
    window.csvImportTestConfig = function() {
        if (!resultsContainer.length) return;
        
        csvImportDebug.log('Config Test gestartet');
        resultsContainer.html('<div class="test-result">🔄 Konfiguration wird geprüft...</div>');
        sampleDataContainer.html(''); // Beispieldaten leeren

        $.post(csvImportAjax.ajaxurl, {
            action: 'csv_import_validate',
            type: 'config',
            nonce: csvImportAjax.nonce
        })
        .done(function(response) {
            showTestResult(response);
        })
        .fail(function(xhr) {
            csvImportDebug.error('Config Test fehlgeschlagen', xhr.responseText);
            showTestResult({ success: false, data: { message: 'Kommunikationsfehler mit dem Server.' } });
        });
    };

    window.csvImportValidateCSV = function(type) {
        if (!resultsContainer.length) return;
        
        const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);
        csvImportDebug.log(`CSV Validierung für ${typeLabel} gestartet`);
        resultsContainer.html(`<div class="test-result">🔄 ${typeLabel} CSV wird validiert...</div>`);
        sampleDataContainer.html('<div class="test-result">🔄 Lade Beispieldaten...</div>');

        $.post(csvImportAjax.ajaxurl, {
            action: 'csv_import_validate',
            type: type,
            nonce: csvImportAjax.nonce
        })
        .done(function(response) {
            showTestResult(response);
        })
        .fail(function(xhr) {
            csvImportDebug.error(`CSV Validierung für ${typeLabel} fehlgeschlagen`, xhr.responseText);
            showTestResult({ success: false, data: { message: 'Kommunikationsfehler mit dem Server.' } });
        });
    };

    importButtons.on('click', function(e) {
        e.preventDefault();
        const $btn = $(this);
        const source = $btn.data('source');
        if (!confirm(source.charAt(0).toUpperCase() + source.slice(1) + ' Import wirklich starten?')) {
            return;
        }
        
        csvImportDebug.log(`Import gestartet für Quelle: ${source}`);
        importButtons.prop('disabled', true).text('🔄 Import läuft...');
        
        $.post(csvImportAjax.ajaxurl, {
            action: 'csv_import_start',
            source: source,
            nonce: csvImportAjax.nonce
        })
        .done(function(response) {
            if(response.success) {
                alert('Import erfolgreich abgeschlossen!');
                location.reload();
            } else {
                alert('Import fehlgeschlagen: ' + (response.data.message || 'Unbekannter Fehler.'));
            }
        })
        .fail(function(xhr) {
            csvImportDebug.error('Import-Request fehlgeschlagen', xhr.responseText);
            alert('Ein kritischer Fehler ist aufgetreten. Bitte prüfen Sie die Logs.');
        })
        .always(function() {
            importButtons.prop('disabled', false).text('🚀 ' + source.charAt(0).toUpperCase() + source.slice(1) + ' Import starten');
        });
    });

    csvImportDebug.log('CSV Import Admin Script vollständig initialisiert.');
});
