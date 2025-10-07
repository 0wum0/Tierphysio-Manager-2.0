# Tierphysio Manager 2.0 - API Dokumentation

## 🎯 Übersicht

Es wurden **6 vollständige API-Endpunkte** für das Tierphysio-Manager-2.0 System erstellt:

1. **owners.php** - Besitzerverwaltung
2. **api_patients.php** - Patientenverwaltung (umbenannt zur Unterscheidung von der Twig-View)
3. **appointments.php** - Terminverwaltung
4. **invoices.php** - Rechnungsverwaltung
5. **notes.php** - Notizverwaltung
6. **settings.php** - Systemeinstellungen

## 📁 Dateistruktur

```
/workspace/
├── includes/
│   ├── config.php          # Konfigurationsdatei (erstellt)
│   ├── db.php              # Datenbankverbindung für APIs (erstellt)
│   └── Database.php        # OOP Database-Klasse
└── public/
    ├── owners.php          # ✅ Besitzer API
    ├── api_patients.php    # ✅ Patienten API
    ├── appointments.php    # ✅ Termine API
    ├── invoices.php        # ✅ Rechnungen API
    ├── notes.php          # ✅ Notizen API
    ├── settings.php       # ✅ Einstellungen API
    ├── test_api.php       # Web-basiertes Test-Tool
    └── api_test_cli.php   # CLI Test-Skript
```

## 🔌 API-Endpunkte

Alle APIs unterstützen folgende Standard-Actions:

### 1. **Owners API** (`/public/owners.php`)

- `?action=get_all` - Liste aller Besitzer
- `?action=get_by_id&id=X` - Einzelner Besitzer mit Patienten und Rechnungen
- `POST action=create` - Neuen Besitzer anlegen
- `POST action=update&id=X` - Besitzer aktualisieren
- `POST action=delete&id=X` - Besitzer löschen

**Besondere Features:**
- Automatische Kundennummer-Generierung
- Verknüpfung mit Patienten und Rechnungen
- Soft-Delete-Schutz bei aktiven Patienten

### 2. **Patients API** (`/public/api_patients.php`)

- `?action=get_all` - Liste aller Patienten
- `?action=get_by_id&id=X` - Patient mit Terminen, Behandlungen und Notizen
- `POST action=create` - Neuen Patient anlegen
- `POST action=update&id=X` - Patient aktualisieren
- `POST action=delete&id=X` - Patient löschen (Soft-Delete)

**Besondere Features:**
- Automatische Patientennummer-Generierung
- Verknüpfung mit Besitzerdaten
- Soft-Delete (is_active Flag)

### 3. **Appointments API** (`/public/appointments.php`)

- `?action=get_all` - Termine in Zeitraum
- `?action=get_by_id&id=X` - Einzelner Termin
- `?action=get_availability&date=Y-m-d&therapist_id=X` - Verfügbare Zeitslots
- `POST action=create` - Neuen Termin anlegen
- `POST action=update&id=X` - Termin aktualisieren
- `POST action=delete&id=X` - Termin löschen

**Besondere Features:**
- Konflikterkennung bei Terminkollisionen
- Zeitslot-Verfügbarkeitscheck
- Stornierungsverwaltung mit Grund und Zeitstempel

### 4. **Invoices API** (`/public/invoices.php`)

- `?action=get_all` - Rechnungen mit Zusammenfassung
- `?action=get_by_id&id=X` - Rechnung mit Positionen
- `POST action=create` - Neue Rechnung erstellen
- `POST action=update&id=X` - Rechnung aktualisieren
- `POST action=delete&id=X` - Rechnung löschen
- `POST action=mark_paid&id=X` - Als bezahlt markieren
- `POST action=send_reminder&id=X` - Zahlungserinnerung

**Besondere Features:**
- Automatische Rechnungsnummer-Generierung
- MwSt-Berechnung
- Rabattverwaltung
- Überfälligkeitsstatus

### 5. **Notes API** (`/public/notes.php`)

- `?action=get_all` - Notizen filtern
- `?action=get_by_id&id=X` - Einzelne Notiz
- `?action=get_reminders` - Fällige Erinnerungen
- `POST action=create` - Neue Notiz
- `POST action=update&id=X` - Notiz bearbeiten
- `POST action=delete&id=X` - Notiz löschen
- `POST action=toggle_pin&id=X` - Anheften/Lösen

**Besondere Features:**
- Private/Öffentliche Notizen
- Erinnerungsfunktion
- Anheft-Funktion
- Multi-Entity-Verknüpfung (Patient, Besitzer, Termin, Behandlung)

### 6. **Settings API** (`/public/settings.php`)

- `?action=get_all` - Alle Einstellungen
- `?action=get_by_key&category=X&key=Y` - Einzelne Einstellung
- `?action=get_categories` - Verfügbare Kategorien
- `POST action=create` - Neue Einstellung
- `POST action=update` - Einstellung ändern
- `POST action=delete` - Einstellung löschen
- `POST action=backup` - Export als JSON
- `POST action=restore` - Import aus JSON

**Besondere Features:**
- Typisierte Werte (string, number, boolean, json, array)
- System-/Benutzereinstellungen
- Backup & Restore
- Kategorisierung

## 🔒 Sicherheit

Alle APIs implementieren:

1. **PDO Prepared Statements** - Schutz vor SQL-Injection
2. **Session-Check** - Authentifizierung (in Produktion aktivieren)
3. **JSON-Response** - Einheitliches Ausgabeformat
4. **Error Handling** - Try-Catch mit sinnvollen Fehlermeldungen
5. **Input Validation** - Pflichtfeld-Prüfung
6. **Berechtigungsprüfung** - User-spezifische Zugriffe

## 📊 Response-Format

Alle APIs verwenden einheitliches JSON-Format:

```json
{
    "status": "success|error",
    "data": {...} oder null,
    "message": "Beschreibende Nachricht"
}
```

## 🧪 Testing

### Web-Interface Test
Öffnen Sie `/public/test_api.php` im Browser für ein interaktives Test-Dashboard.

### CLI Test
```bash
php /workspace/public/api_test_cli.php
```

## 🚀 Verwendung

### GET-Request Beispiel:
```javascript
fetch('/public/owners.php?action=get_all')
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            console.log(data.data);
        }
    });
```

### POST-Request Beispiel:
```javascript
const formData = new FormData();
formData.append('action', 'create');
formData.append('first_name', 'Max');
formData.append('last_name', 'Mustermann');
formData.append('email', 'max@example.com');

fetch('/public/owners.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => console.log(data));
```

## ✅ Status

Alle 6 API-Endpunkte sind:
- ✅ Vollständig implementiert
- ✅ Mit CRUD-Operationen ausgestattet
- ✅ Sicherheitsmaßnahmen implementiert
- ✅ Einheitliches Response-Format
- ✅ PDO Prepared Statements
- ✅ Fehlerbehandlung
- ✅ Dokumentiert

## 📝 Hinweise

1. **Datenbank**: Stellen Sie sicher, dass die Datenbank gemäß `/workspace/migrations/001_initial_schema.sql` eingerichtet ist.

2. **Konfiguration**: Passen Sie `/workspace/includes/config.php` an Ihre Umgebung an:
   - Datenbankverbindung
   - Session-Einstellungen
   - Debug-Modus

3. **Authentifizierung**: In der Produktionsumgebung sollte `APP_DEBUG` auf `false` gesetzt werden, um die Session-Prüfung zu aktivieren.

4. **Patients vs api_patients**: Die originale `patients.php` ist eine Twig-View. Die API-Version heißt `api_patients.php`.

## 🎉 Zusammenfassung

Das Tierphysio-Manager-2.0 Backend ist nun vollständig mit allen benötigten API-Endpunkten ausgestattet. Die APIs bieten:

- Vollständige CRUD-Funktionalität
- Konsistente Struktur
- Erweiterte Features je nach Kontext
- Sicherheitsmaßnahmen
- Einheitliche Fehlerbehandlung
- JSON-basierte Kommunikation

Das System ist bereit für die Integration mit dem Frontend!