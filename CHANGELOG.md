# TimePoint Changelog

## Changelog

### Release 1.0.3 in Bearbeitung
- [] Browser Extensions Bugfixes und first Public Release.
- [] Impressum und Datenschutz können jetzt direkt im Browser bearbeitet werden. 
- [] Strukturelle Verbesserungen
   - [] Anpassung und Optimierung der Ordnerstruktur.
   - [x] Entfernung von alten Dateien.

### Release 1.0.2
- [x] SMTP Implementierung in den Einstellungen.
- [x] Passwort vergessen Funktion.
- [x] Vollständige Docker Implementierung.
- [x] Möglichkeit PDF, PDF/A E-Mail Versand an einen oder mehrere Mitarbeiter über die Supervisoren Export Oberfläche.
- [x] Audit Log (wer hat was geändert, keine nachträgliche Manipulation ohne Log inkl. Hash-Kettenprüfung).
- [x] Audit-Prüfung wurde implementiert.
- [x] Audit Log Exportfunktion (CSV, JSON, CSV).
- [x] manage.sh Script zur Verwaltung des Docker Containers und Backup Funktionen der Datenbank und des Audit Logs.
   - [x] backup.sh Script zur Erstellung eines SQL Backups der Datenbank und des Audit Logs.
   - [x] Start, restart und Stop Script für die einfache Verwaltung des Docker Containers.
- [x] Funktionsüberprüfung mit Docker Container und Nativ.
- [x] Vollständige Docker Implementierung.

### Release 1.0.1
- [x] Mitarbeiter können Ihr Passwort ändern.
- [x] Administratoren können Mitarbeiter Namen ändern.
- [x] Administratoren können beim Erstellen eines neuen Mitarbeiters auswählen das ein neuer Mitarbeiter beim ersten Login das Passwort ändern muss.
- [x] PDF/A Export für Langzeitarchivierung wurde hinzugefügt.
- [x] Entwickler-Informationen mit To-Do's und Changelog Informationen.
- [x] Impressum und Datenschutz kann jetzt einzeln in den Admin Einstellungen ein und ausgeblendet werden.
- [x] Datenbank ex und import wurde von den Einstellungen in den Admin Bereich verschoben.


### Release 1.0.0
- [x] Erstes Public Release.
- [x] Zahlreiche Bugfixes und Verbesserungen sowie grundlegende Überarbeitungen.
- [x] Automatischer Pausenabzug möglich.
- [x] PDF Generierung -> Farbliche Kennzeichnung von Urlaub, Feiertagen und Krankheit.
- [x] Dark Mode wurde nicht geladen bzw. wurde teilweise falsch dargestellt.
- [x] UI Verbesserungen:
    - [x] Dashboard: Genommener und Resturlaub sowie Krankheitstage werden angezeigt.
    - [x] Jeder Mitarbeiter wird jetzt beim Namen genannt.
    - [x] Vor der Stempelzeit steht jetzt: "Aktuelle Arbeitszeit:".
    - [x] Dropdown Menü im Mitarbeitername integriert.

## Geplante Features

- [] Backup Strategie:
    - [] Automatischer Backup Job (Tag, Woche, Monat).
    - [] Backup Speicherort: Cloud Storage (AWS S3, Google Cloud Storage, Azure Blob Storage), NAS.
    - [] Backup Versionsverwaltung.

- [] Mobile App (API) Entwicklung für iOS und Android
     - [] Push Notifications (noch nicht eingestempelt oder Sollzeit überschritten (nicht ausgestempelt).
     - [] Push Notifications (beim Ein und Ausstempeln).
     - [] Geofencing (Ein und Ausstempeln nur innerhalb der Firma -/ Kann von Supervisoren aktiviert werden z. B. wenn ein Mitarbeiter kein Homeoffice hat).

- [] Urlaubsplaner

- [] Optimierung der Datenbankstruktur.
- [] LDAP Funktionstest.
- [] Benutzer können Bilder hinzufügen.
- [] Language Fix - Variablen sind zum Teil noch nicht gesetzt.
- [] Implementierung Bugtracker.

## Roadmap

- [] 2FA Unterstützung (Authenticator, Mail, oder Ähnliches).

- [] Dienstplanung
    - [] Supervisoren können vorgefertigten Dienstplan erstellen.
    - [] Mitarbeiter können sich auf freie Schichten bewerben.
    - [] Mitarbeiter können Schichten mit Begründung ablehnen (z. B. Arzttermin), der Supervisor kann die Schichten dann ändern.
    - [] Mitarbeiter können sich vorab für Schichten als abwesend anmelden (z. B. Arzttermin).
    - [] Automatische Dienstplan erstellung basierend auf Mitarbeiter Verfügbarkeit.

- [] Projektzeiterfassung
