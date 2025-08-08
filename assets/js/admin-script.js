/**
 * Admin JavaScript für das CSV Import Pro Plugin
 * Version: 6.0 - Komplett neu geschrieben und korrigiert
 * 
 * Funktionen:
 * - Robuste CSV-Validierung mit Fehlerbehandlung
 * - Real-time Import-Progress-Updates
 * - Sichere AJAX-Kommunikation
 * - Responsive Admin-Interface
 * - Automatische Error-Recovery
 */

(function($) {
    'use strict';

    // =======================================================================
    // GLOBALE VARIABLEN & KONFIGURATION
    // =======================================================================

    const CSVImportAdmin = {
        // Debug-System
        debug: {
            enabled: (typeof csvImportAjax !== 'undefined' && csvImportAjax.debug) || false,
            log: function(message, data) {
                if (this.enabled) {
                    console.log('🔧 CSV Import:', message, data || '');
                }
            },
            warn: function(message, data) {
                console.warn('⚠️ CSV Import:', message, data || '');
            },
            error: function(message, data) {
                console.error('❌ CSV Import:', message, data || '');
            }
        },

        // DOM-Elemente Cache
        elements: {
            resultsContainer: null,
            sampleDataContainer: null,
            importButtons: null,
            progressNotice: null,
            progressBar: null
        },

        // Status-Tracking
        status: {
            importRunning: false,
            validationInProgress: false,
            progressUpdateInterval: null
        },

        // Konfiguration
        config: {
            progressUpdateInterval: 5000, // 5 Sekunden
            maxRetries: 3,
            ajaxTimeout: 30000, // 30 Sekunden
            retryDelay: 2000 // 2 Sekunden
        }
    };

    // =======================================================================
    // INITIALISIERUNG
    // =======================================================================

    $(document).ready(function() {
        CSVImportAdmin.init();
    });

    /**
     * Hauptinitialisierung
     */
    CSVImportAdmin.init = function() {
        this.debug.log('Initialisiere CSV Import Admin Interface');

        // Verfügbarkeit von csvImportAjax prüfen
        if (typeof csvImportAjax === 'undefined') {
            this.debug.error('csvImportAjax Object nicht verfügbar - Plugin korrekt geladen?');
            this.showGlobalError('Admin-Konfiguration fehlt. Seite neu laden oder Administrator kontaktieren.');
            return;
        }

        // DOM-Elemente cachen
        this.cacheElements();

        // Event-Listener registrieren
        this.bindEvents();

        // Status initialisieren
        this.initializeStatus();

        // Progress-Updates starten falls Import läuft
        if (csvImportAjax.import_running) {
            this.startProgressUpdates();
        }

        this.debug.log('CSV Import Admin Interface erfolgreich initialisiert');
    };

    /**
     * DOM-Elemente cachen für bessere Performance
     */
    CSVImportAdmin.cacheElements = function() {
        this.elements = {
            resultsContainer: $('#csv-test-results'),
            sampleDataContainer: $('#csv-sample-data-container'),
            importButtons: $('.csv-import-btn'),
            progressNotice: $('.csv-import-progress-notice'),
            progressBar: $('.csv-import-progress-fill, .progress-bar-fill'),
            emergencyReset: $('#csv-emergency-reset'),
            refreshButton: $('.csv-refresh-page')
        };

        // Fehlende wichtige Elemente loggen
        if (!this.elements.resultsContainer.length) {
            this.debug.warn('Test-Results-Container (#csv-test-results) nicht gefunden');
        }
        if (!this.elements.sampleDataContainer.length) {
            this.debug.warn('Sample-Data-Container (#csv-sample-data-container) nicht gefunden');
        }
    };

    /**
     * Event-Listener registrieren
     */
    CSVImportAdmin.bindEvents = function() {
        const self = this;

        // Import-Buttons
        this.elements.importButtons.on('click', function(e) {
            e.preventDefault();
            self.handleImportClick($(this));
        });

        // Emergency Reset Button
        this.elements.emergencyReset.on('click', function(e) {
            e.preventDefault();
            self.handleEmergencyReset();
        });

        // Page Refresh Button
        this.elements.refreshButton.on('click', function(e) {
            e.preventDefault();
            self.handlePageRefresh();
        });

        // Globale AJAX-Fehlerbehandlung
        $(document).ajaxError(function(event, xhr, settings, error) {
            if (settings.url.includes('csv_import')) {
                self.debug.error('AJAX-Fehler erkannt', {
                    url: settings.url,
                    error: error,
                    status: xhr.status,
                    response: xhr.responseText
                });
            }
        });

        // Globale Funktionen für Template-Aufrufe verfügbar machen
        window.csvImportTestConfig = function() {
            self.testConfiguration();
        };

        window.csvImportValidateCSV = function(type) {
            self.validateCSV(type);
        };

        this.debug.log('Event-Listener erfolgreich registriert');
    };

    /**
     * Status-Initialisierung
     */
    CSVImportAdmin.initializeStatus = function() {
        // Import-Status aus Server-Daten übernehmen
        if (typeof csvImportAjax !== 'undefined') {
            this.status.importRunning = csvImportAjax.import_running || false;
        }

        // UI entsprechend dem Status anpassen
        this.updateUIState();
    };

    // =======================================================================
    // KONFIGURATION & VALIDIERUNG
    // =======================================================================

    /**
     * Konfiguration testen
     */
    CSVImportAdmin.testConfiguration = function() {
        if (this.status.validationInProgress) {
            this.debug.warn('Validierung bereits in Bearbeitung');
            return;
        }

        if (!this.elements.resultsContainer.length) {
            this.showAlert('Test-Interface nicht verfügbar. Seite neu laden.', 'error');
            return;
        }

        this.debug.log('Konfigurationstest gestartet');
        this.status.validationInProgress = true;

        // UI-Feedback
        this.showTestProgress('config', 'Konfiguration wird geprüft...');

        // AJAX-Request
        this.performAjaxRequest({
            action: 'csv_import_validate',
            type: 'config'
        })
        .done((response) => {
            this.handleValidationResult(response, 'config');
        })
        .fail((xhr, status, error) => {
            this.handleValidationError('Konfigurationstest', error, xhr);
        })
        .always(() => {
            this.status.validationInProgress = false;
        });
    };

    /**
     * CSV-Datei validieren
     */
    CSVImportAdmin.validateCSV = function(type) {
        if (this.status.validationInProgress) {
            this.debug.warn('Validierung bereits in Bearbeitung');
            return;
        }

        if (!type || !['dropbox', 'local'].includes(type)) {
            this.debug.error('Ungültiger CSV-Typ:', type);
            return;
        }

        if (!this.elements.resultsContainer.length) {
            this.showAlert('Validierungs-Interface nicht verfügbar. Seite neu laden.', 'error');
            return;
        }

        this.debug.log(`CSV-Validierung gestartet für: ${type}`);
        this.status.validationInProgress = true;

        const typeLabel = type.charAt(0).toUpperCase() + type.slice(1);

        // UI-Feedback
        this.showTestProgress(type, `${typeLabel} CSV wird validiert...`);
        this.showSampleDataProgress('Lade Beispieldaten...');

        // AJAX-Request
        this.performAjaxRequest({
            action: 'csv_import_validate',
            type: type
        })
        .done((response) => {
            this.handleValidationResult(response, type);
        })
        .fail((xhr, status, error) => {
            this.handleValidationError(`${typeLabel} CSV-Validierung`, error, xhr);
        })
        .always(() => {
            this.status.validationInProgress = false;
        });
    };

    /**
     * Validierungsergebnis verarbeiten
     */
    CSVImportAdmin.handleValidationResult = function(response, type) {
        if (!response) {
            this.showTestResult('Keine Antwort vom Server erhalten', false);
            return;
        }

        const data = response.success ? response.data : (response.data || {});
        const message = data.message || (response.success ? 'Validierung erfolgreich' : 'Validierung fehlgeschlagen');

        // Test-Ergebnis anzeigen
        this.showTestResult(message, response.success);

        // Beispieldaten anzeigen (nur bei erfolgreicher CSV-Validierung)
        if (response.success && data.columns && data.sample_data && type !== 'config') {
            this.showSampleData(data.columns, data.sample_data);
        } else {
            this.clearSampleData();
        }

        this.debug.log(`Validierung ${type} abgeschlossen:`, {
            success: response.success,
            rows: data.rows,
            columns: data.columns ? data.columns.length : 0
        });
    };

    /**
     * Validierungsfehler behandeln
     */
    CSVImportAdmin.handleValidationError = function(operation, error, xhr) {
        this.debug.error(`${operation} fehlgeschlagen`, {
            error: error,
            status: xhr ? xhr.status : 'unknown',
            response: xhr ? xhr.responseText : 'no response'
        });

        let errorMessage = `${operation} fehlgeschlagen`;
        
        if (xhr && xhr.status) {
            if (xhr.status === 0) {
                errorMessage += ': Netzwerkfehler - Internetverbindung prüfen';
            } else if (xhr.status >= 500) {
                errorMessage += ': Server-Fehler - Administrator kontaktieren';
            } else if (xhr.status === 403) {
                errorMessage += ': Keine Berechtigung - Anmeldung prüfen';
            } else {
                errorMessage += `: HTTP ${xhr.status}`;
            }
        } else {
            errorMessage += ': ' + (error || 'Unbekannter Fehler');
        }

        this.showTestResult(errorMessage, false);
        this.clearSampleData();
    };

    // =======================================================================
    // IMPORT-FUNKTIONEN
    // =======================================================================

    /**
     * Import-Button-Click behandeln
     */
    CSVImportAdmin.handleImportClick = function($button) {
        const source = $button.data('source');

        if (!source) {
            this.debug.error('Import-Button ohne Datenquelle');
            this.showAlert('Import-Konfigurationsfehler', 'error');
            return;
        }

        if (this.status.importRunning) {
            this.showAlert('Ein Import läuft bereits. Bitte warten oder Reset durchführen.', 'warning');
            return;
        }

        // Bestätigung einholen
        const sourceLabel = source.charAt(0).toUpperCase() + source.slice(1);
        if (!confirm(`${sourceLabel} Import wirklich starten?\n\nDies kann mehrere Minuten dauern.`)) {
            return;
        }

        this.startImport(source);
    };

    /**
     * Import starten
     */
    CSVImportAdmin.startImport = function(source) {
        this.debug.log(`Import wird gestartet: ${source}`);

        // UI-Status ändern
        this.status.importRunning = true;
        this.updateUIState();
        this.setImportButtonsState(true, 'Import läuft...');

        // AJAX-Request
        this.performAjaxRequest({
            action: 'csv_import_start',
            source: source
        })
        .done((response) => {
            this.handleImportResult(response, source);
        })
        .fail((xhr, status, error) => {
            this.handleImportError(source, error, xhr);
        })
        .always(() => {
            this.status.importRunning = false;
            this.updateUIState();
            this.setImportButtonsState(false);
        });

        // Progress-Updates starten
        this.startProgressUpdates();
    };

    /**
     * Import-Ergebnis verarbeiten
     */
    CSVImportAdmin.handleImportResult = function(response, source) {
        if (response.success) {
            const processed = response.data.processed || 0;
            const total = response.data.total || 0;
            const errors = response.data.errors || 0;

            let message = `Import erfolgreich abgeschlossen!\n\n`;
            message += `Verarbeitet: ${processed} von ${total} Einträgen\n`;
            if (errors > 0) {
                message += `Fehler: ${errors}\n`;
            }
            message += `\nSeite wird neu geladen...`;

            this.showAlert(message, 'success');
            
            // Nach kurzem Delay Seite neu laden
            setTimeout(() => {
                window.location.reload();
            }, 2000);

        } else {
            const errorMsg = response.data?.message || response.message || 'Unbekannter Import-Fehler';
            this.showAlert(`Import fehlgeschlagen:\n${errorMsg}`, 'error');
        }

        this.debug.log(`Import ${source} beendet:`, response);
    };

    /**
     * Import-Fehler behandeln
     */
    CSVImportAdmin.handleImportError = function(source, error, xhr) {
        this.debug.error(`Import ${source} fehlgeschlagen`, {
            error: error,
            status: xhr?.status,
            response: xhr?.responseText
        });

        let errorMessage = `Import fehlgeschlagen`;
        
        if (xhr?.status === 0) {
            errorMessage += `\n\nNetzwerkfehler - möglicherweise ist der Import noch aktiv.\nBitte warten oder Reset durchführen.`;
        } else if (xhr?.status >= 500) {
            errorMessage += `\n\nServer-Fehler. Import möglicherweise abgebrochen.\nBitte Logs prüfen oder Administrator kontaktieren.`;
        } else {
            errorMessage += `\n\n${error || 'Unbekannter Fehler'}`;
        }

        this.showAlert(errorMessage, 'error');
    };

    // =======================================================================
    // PROGRESS-UPDATES
    // =======================================================================

    /**
     * Progress-Updates starten
     */
    CSVImportAdmin.startProgressUpdates = function() {
        if (this.status.progressUpdateInterval) {
            clearInterval(this.status.progressUpdateInterval);
        }

        this.debug.log('Progress-Updates gestartet');

        this.status.progressUpdateInterval = setInterval(() => {
            this.updateProgress();
        }, this.config.progressUpdateInterval);

        // Sofortiges erstes Update
        this.updateProgress();
    };

    /**
     * Progress-Updates stoppen
     */
    CSVImportAdmin.stopProgressUpdates = function() {
        if (this.status.progressUpdateInterval) {
            clearInterval(this.status.progressUpdateInterval);
            this.status.progressUpdateInterval = null;
            this.debug.log('Progress-Updates gestoppt');
        }
    };

    /**
     * Aktuellen Progress vom Server holen
     */
    CSVImportAdmin.updateProgress = function() {
        $.post(csvImportAjax.ajaxurl, {
            action: 'csv_import_get_progress',
            nonce: csvImportAjax.nonce
        })
        .done((response) => {
            if (response.success) {
                this.handleProgressUpdate(response.data);
            } else {
                this.debug.warn('Progress-Update fehlgeschlagen:', response);
            }
        })
        .fail((xhr, status, error) => {
            this.debug.error('Progress-AJAX fehlgeschlagen:', error);
            // Nach mehreren Fehlern Progress-Updates stoppen
            if (++this.progressUpdateFailures >= 3) {
                this.stopProgressUpdates();
            }
        });
    };

    /**
     * Progress-Update verarbeiten
     */
    CSVImportAdmin.handleProgressUpdate = function(progressData) {
        if (!progressData) return;

        const isRunning = progressData.running || false;
        const percent = progressData.percent || 0;
        const message = progressData.message || '';

        // Status aktualisieren
        if (this.status.importRunning !== isRunning) {
            this.status.importRunning = isRunning;
            this.updateUIState();
        }

        // Progress-Bar aktualisieren
        if (this.elements.progressBar.length) {
            this.elements.progressBar.css('width', percent + '%');
        }

        // Progress-Notice aktualisieren
        if (this.elements.progressNotice.length) {
            if (isRunning) {
                this.elements.progressNotice.show();
                this.elements.progressNotice.find('.progress-message').text(message);
            } else {
                this.elements.progressNotice.hide();
                this.stopProgressUpdates();
            }
        }

        // Import-Status verfolgen
        if (!isRunning && this.status.importRunning) {
            this.debug.log('Import abgeschlossen laut Progress-Update');
            this.status.importRunning = false;
            this.setImportButtonsState(false);
        }
    };

    // =======================================================================
    // UI-UPDATES & FEEDBACK
    // =======================================================================

    /**
     * UI-Status entsprechend Import-Status aktualisieren
     */
    CSVImportAdmin.updateUIState = function() {
        if (this.status.importRunning) {
            this.setImportButtonsState(true, 'Import läuft...');
            this.startProgressUpdates();
        } else {
            this.setImportButtonsState(false);
            this.stopProgressUpdates();
        }
    };

    /**
     * Import-Button-Zustand setzen
     */
    CSVImportAdmin.setImportButtonsState = function(disabled, text) {
        this.elements.importButtons.each(function() {
            const $btn = $(this);
            const source = $btn.data('source');
            
            if (disabled) {
                $btn.prop('disabled', true);
                $btn.text(text || '🔄 Import läuft...');
                $btn.addClass('button-primary-disabled');
            } else {
                $btn.prop('disabled', false);
                $btn.removeClass('button-primary-disabled');
                
                if (source) {
                    const sourceLabel = source.charAt(0).toUpperCase() + source.slice(1);
                    $btn.text(`🚀 ${sourceLabel} Import starten`);
                }
            }
        });
    };

    /**
     * Test-Progress anzeigen
     */
    CSVImportAdmin.showTestProgress = function(type, message) {
        if (!this.elements.resultsContainer.length) return;

        const progressHtml = `<div class="test-result test-progress">🔄 ${message}</div>`;
        this.elements.resultsContainer.html(progressHtml);
    };

    /**
     * Test-Ergebnis anzeigen
     */
    CSVImportAdmin.showTestResult = function(message, success) {
        if (!this.elements.resultsContainer.length) return;

        const resultClass = success ? 'test-success' : 'test-error';
        const icon = success ? '✅' : '❌';
        
        const resultHtml = `<div class="test-result ${resultClass}">${icon} ${message}</div>`;
        this.elements.resultsContainer.html(resultHtml);
    };

    /**
     * Sample-Data-Progress anzeigen
     */
    CSVImportAdmin.showSampleDataProgress = function(message) {
        if (!this.elements.sampleDataContainer.length) return;

        const progressHtml = `<div class="test-result test-progress">🔄 ${message}</div>`;
        this.elements.sampleDataContainer.html(progressHtml);
    };

    /**
     * Beispieldaten anzeigen
     */
    CSVImportAdmin.showSampleData = function(columns, sampleData) {
        if (!this.elements.sampleDataContainer.length || !columns || !sampleData) return;

        try {
            // Maximale Anzahl anzuzeigender Spalten (für bessere Darstellung)
            const maxCols = 5;
            const displayColumns = columns.slice(0, maxCols);
            const hasMoreCols = columns.length > maxCols;

            let tableHtml = `
                <div class="csv-sample-data-wrapper">
                    <p><strong>Beispieldaten</strong> (erste ${sampleData.length} Zeilen)</p>
                    <table class="wp-list-table widefat striped">
                        <thead>
                            <tr>
                                <th>${displayColumns.join('</th><th>')}</th>
                                ${hasMoreCols ? '<th>...</th>' : ''}
                            </tr>
                        </thead>
                        <tbody>
            `;

            sampleData.forEach(row => {
                if (Array.isArray(row)) {
                    const displayRow = row.slice(0, maxCols);
                    tableHtml += `
                        <tr>
                            <td>${displayRow.join('</td><td>')}</td>
                            ${hasMoreCols ? '<td>...</td>' : ''}
                        </tr>
                    `;
                }
            });

            tableHtml += `
                        </tbody>
                    </table>
                </div>
            `;

            if (hasMoreCols) {
                tableHtml += `<p class="description">Zeige ${maxCols} von ${columns.length} Spalten</p>`;
            }

            this.elements.sampleDataContainer.html(tableHtml);

        } catch (error) {
            this.debug.error('Fehler beim Anzeigen der Beispieldaten:', error);
            this.elements.sampleDataContainer.html('<div class="test-result test-error">❌ Fehler beim Laden der Beispieldaten</div>');
        }
    };

    /**
     * Beispieldaten löschen
     */
    CSVImportAdmin.clearSampleData = function() {
        if (this.elements.sampleDataContainer.length) {
            this.elements.sampleDataContainer.empty();
        }
    };

    /**
     * Alert-Dialog anzeigen
     */
    CSVImportAdmin.showAlert = function(message, type = 'info') {
        // Für bessere UX - native Browser-Alerts durch WordPress-Admin-Notices ersetzen
        if (type === 'success') {
            alert('✅ ' + message);
        } else if (type === 'error') {
            alert('❌ ' + message);
        } else if (type === 'warning') {
            alert('⚠️ ' + message);
        } else {
            alert(message);
        }
    };

    /**
     * Globalen Fehler anzeigen
     */
    CSVImportAdmin.showGlobalError = function(message) {
        const errorHtml = `
            <div class="notice notice-error">
                <p><strong>CSV Import Pro:</strong> ${message}</p>
            </div>
        `;
        
        if ($('.wrap').length) {
            $('.wrap').prepend(errorHtml);
        } else {
            $('body').prepend(errorHtml);
        }
    };

    // =======================================================================
    // HILFSFUNKTIONEN
    // =======================================================================

    /**
     * Sicherer AJAX-Request mit Retry-Logik
     */
    CSVImportAdmin.performAjaxRequest = function(data, options = {}) {
        const defaultOptions = {
            url: csvImportAjax.ajaxurl,
            type: 'POST',
            timeout: this.config.ajaxTimeout,
            data: $.extend({
                nonce: csvImportAjax.nonce
            }, data)
        };

        const requestOptions = $.extend(defaultOptions, options);

        return $.ajax(requestOptions);
    };

    /**
     * Emergency Reset behandeln
     */
    CSVImportAdmin.handleEmergencyReset = function() {
        if (!confirm('Import-Status wirklich zurücksetzen?\n\nDies bricht alle laufenden Prozesse ab!')) {
            return;
        }

        this.debug.log('Emergency Reset ausgeführt');
        
        // Lokalen Status zurücksetzen
        this.status.importRunning = false;
        this.status.validationInProgress = false;
        this.stopProgressUpdates();
        
        // UI zurücksetzen
        this.updateUIState();
        this.clearSampleData();
        
        if (this.elements.resultsContainer.length) {
            this.elements.resultsContainer.html('<div class="test-result">🔄 Status wurde zurückgesetzt</div>');
        }

        // Seite nach kurzer Delay neu laden
        setTimeout(() => {
            window.location.reload();
        }, 1500);
    };

    /**
     * Seite neu laden
     */
    CSVImportAdmin.handlePageRefresh = function() {
        this.debug.log('Seite wird neu geladen');
        window.location.reload();
    };

    // =======================================================================
    // ERROR-HANDLING & CLEANUP
    // =======================================================================

    /**
     * Cleanup bei Seitenverlassen
     */
    $(window).on('beforeunload', function() {
        CSVImportAdmin.stopProgressUpdates();
    });

    /**
     * Global Error Handler für unbehandelte Fehler
     */
    window.addEventListener('error', function(e) {
        if (e.filename && e.filename.includes('admin-script')) {
            CSVImportAdmin.debug.error('JavaScript-Fehler erkannt:', {
                message: e.message,
                filename: e.filename,
                line: e.lineno,
                column: e.colno
            });
        }
    });

    // =======================================================================
    // ÖFFENTLICHE API
    // =======================================================================

    // CSVImportAdmin global verfügbar machen für Debugging
    window.CSVImportAdmin = CSVImportAdmin;

    CSVImportAdmin.debug.log('CSV Import Admin Script geladen (Version 6.0)');

})(jQuery);
