<?php

/**
 * Paperless Attach — Deutsch (de_DE) localization.
 *
 * @license GPL-3.0+
 */

$labels = [];

// Einstellungsbereich
$labels['paperlesssection'] = 'Paperless';

// Token-Feld
$labels['tokenlabel']       = 'API-Token';
$labels['tokenconfigured']  = 'hinterlegt ✓';
$labels['tokenremove']      = 'Hinterlegten Token entfernen';

// Verbindungstest
$labels['testconnection']   = 'Verbindung testen';
$labels['testok']           = 'Verbindung erfolgreich ✓';
$labels['testbadtoken']     = 'Authentifizierung fehlgeschlagen – bitte API-Token prüfen.';
$labels['testunreachable']  = 'Paperless ist nicht erreichbar.';
$labels['testnotoken']      = 'Es ist noch kein API-Token hinterlegt.';
$labels['testdecryptfail']  = 'Hinterlegter Token konnte nicht gelesen werden – bitte erneut eingeben.';

// --- Phase 2: Suchen & Anhängen aus Paperless ---

// Toolbar-Button
$labels['attach_from_paperless'] = 'Aus Paperless anhängen';

// Suche
$labels['search_placeholder'] = 'Dokumente durchsuchen…';
$labels['btn_search']         = 'Suchen';

// Filter
$labels['filters']              = 'Filter';
$labels['filter_tags']          = 'Tags';
$labels['filter_correspondent'] = 'Korrespondent';
$labels['filter_doctype']       = 'Dokumenttyp';
$labels['filter_date_from']     = 'Von';
$labels['filter_date_to']       = 'Bis';
$labels['filter_reset']         = 'Filter zurücksetzen';
$labels['filter_loading']       = 'Filter werden geladen…';
$labels['tags_selected_count']  = '%d ausgewählt';

// Ergebnisliste
$labels['load_more']    = 'Mehr laden';
$labels['result_count'] = '%d von %d';
$labels['meta_size_kb'] = '%d KB';
$labels['meta_size_mb'] = '%d MB';

// Auswahl / Fußzeile
$labels['selected_count']      = '%d ausgewählt';
$labels['btn_attach_selected'] = '%d anhängen';
$labels['btn_cancel']          = 'Abbrechen';

// Zustände
$labels['state_idle']                    = 'Suchbegriff eingeben und „Suchen“ klicken.';
$labels['state_loading']                 = 'Wird gesucht…';
$labels['state_no_results']              = 'Keine Dokumente gefunden.';
$labels['state_no_token']                = 'Kein Paperless-Token konfiguriert.';
$labels['state_no_token_settings_link']  = 'In den Einstellungen konfigurieren';
$labels['state_error']                   = 'Paperless konnte nicht erreicht werden.';
$labels['btn_retry']                     = 'Erneut versuchen';

// --- Phase 3: Anhängen an die E-Mail ---
$labels['attaching']   = 'Wird angehängt…';
$labels['attached']    = 'Dokument(e) angehängt.';
$labels['attacherror'] = 'Das Dokument konnte nicht angehängt werden.';

// --- Phase 4: Härtung & Politur — Stapel- und Fehlerkategorie-Meldungen ---

// Stapel-Zusammenfassung ("3 von 4 angehängt.")
$labels['attach_summary'] = '%d von %d angehängt.';

// Fehlerkategorien je Dokument
$labels['attach_too_large']  = '„%s“ ist zu groß (%s > %s).';
$labels['attach_no_archive'] = '„%s“ hat keine Archiv-PDF und wurde übersprungen.';
$labels['attach_item_error'] = '„%s“ konnte nicht angehängt werden.';
$labels['attach_duplicate']  = '„%s“ ist bereits angehängt.';

// Kurzmarkierungen für Ergebniszeilen
$labels['attach_too_large_short'] = 'zu groß';
$labels['attach_duplicate_short'] = 'bereits angehängt';

// Fehlender/ungültiger Token (ganzer Stapel)
$labels['attach_no_token']      = 'Kein gültiger Paperless-Token. Bitte in den Einstellungen hinterlegen.';
$labels['attach_no_token_link'] = 'In den Einstellungen konfigurieren';

$messages = [];
