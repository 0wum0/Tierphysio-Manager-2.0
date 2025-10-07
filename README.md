# Tierphysio Manager 2.0

Eine moderne, vollständige Web-Anwendung zur Verwaltung von Tierphysiotherapie-Praxen. Entwickelt mit PHP 8.3, Twig, TailwindCSS, Alpine.js und Anime.js.

## 🎯 Features

### Kernfunktionen
- **Dashboard** mit KPIs und Statistiken
- **Patientenverwaltung** mit vollständiger CRUD-Funktionalität
- **Besitzerverwaltung** mit Verknüpfung zu Patienten
- **Terminplanung** mit Kalenderansicht
- **Behandlungsdokumentation** mit Verlauf
- **Rechnungssystem** mit PDF-Export
- **Notizen** für Patienten und Termine
- **Admin-Panel** mit umfangreichen Einstellungen

### Technische Features
- **PWA-Unterstützung** für Offline-Funktionalität
- **Dark/Light Mode** Toggle
- **Responsive Design** für alle Geräte
- **RESTful API** für AJAX-Requests
- **Backup-System** für Datensicherheit
- **Mehrsprachigkeit** (DE/EN)
- **JWT-Authentifizierung**

## 🚀 Installation

### Systemanforderungen
- PHP >= 8.3
- MySQL >= 5.7 oder MariaDB >= 10.3
- Apache mit mod_rewrite
- Composer

### Installationsschritte

1. **Dateien hochladen**
   ```bash
   # Alle Dateien auf Ihren Webserver hochladen
   ```

2. **Dependencies installieren**
   ```bash
   composer install
   ```

3. **Installer aufrufen**
   - Öffnen Sie im Browser: `https://ihre-domain.de/installer/`
   - Folgen Sie den Installationsanweisungen

4. **Fertig!**
   - Nach erfolgreicher Installation werden Sie zum Login weitergeleitet
   - Melden Sie sich mit Ihrem Admin-Account an

## 🎨 Design

- **Farbschema**: Lilac-Gradient (#9b5de5 → #7C4DFF → #6c63ff)
- **Glassmorphismus-Effekte** für moderne Optik
- **Sanfte Animationen** mit Anime.js
- **Tierphysio-bezogene Icons** und Loader

## 🔐 Sicherheit

- Prepared Statements gegen SQL-Injection
- CSRF-Token-Schutz
- Passwort-Hashing mit bcrypt
- Session-Timeout
- Login-Attempt-Limiting
- XSS-Schutz

## 📁 Projektstruktur

```
/
├── api/                # API-Endpunkte
├── admin/             # Admin-Panel
├── backups/           # Datenbank-Backups
├── includes/          # PHP-Klassen und Konfiguration
├── installer/         # Installations-Wizard
├── integrity/         # System-Integrity-Check
├── migrations/        # Datenbank-Migrations
├── public/            # Öffentliche Dateien
│   ├── css/          # Stylesheets
│   ├── js/           # JavaScript
│   ├── images/       # Bilder
│   └── uploads/      # Benutzer-Uploads
├── templates/         # Twig-Templates
│   ├── layouts/      # Basis-Layouts
│   ├── pages/        # Seiten-Templates
│   └── partials/     # Wiederverwendbare Komponenten
├── vendor/           # Composer-Dependencies
└── composer.json     # Composer-Konfiguration
```

## 🛠️ Technologie-Stack

- **Backend**: PHP 8.3 mit OOP
- **Datenbank**: MySQL/MariaDB mit PDO
- **Template Engine**: Twig 3.x
- **CSS Framework**: TailwindCSS (via CDN)
- **JavaScript**: Alpine.js, Anime.js
- **Charts**: Chart.js
- **PDF**: TCPDF
- **Email**: PHPMailer
- **Authentication**: Firebase PHP-JWT

## 📱 PWA Features

- Offline-Funktionalität mit Service Worker
- Installierbar als App
- Push-Benachrichtigungen
- Hintergrund-Synchronisation
- App-Shortcuts

## 🔧 Konfiguration

Die Hauptkonfiguration befindet sich in `includes/config.php` (wird vom Installer erstellt).

### Wichtige Einstellungen
- Datenbank-Verbindung
- E-Mail-Konfiguration
- Upload-Limits
- Session-Einstellungen
- Backup-Konfiguration

## 📝 API-Dokumentation

Die RESTful API ist unter `/api/` verfügbar.

### Endpunkte
- `GET /api/patients` - Alle Patienten abrufen
- `POST /api/patients` - Neuen Patienten anlegen
- `GET /api/appointments` - Termine abrufen
- `POST /api/auth/login` - Anmeldung
- Weitere Endpunkte siehe API-Dokumentation

## 🎯 Verwendung

1. **Dashboard**: Übersicht über alle wichtigen Kennzahlen
2. **Patienten**: Verwalten Sie Ihre tierischen Patienten
3. **Termine**: Planen und verwalten Sie Behandlungstermine
4. **Behandlungen**: Dokumentieren Sie Therapieverläufe
5. **Rechnungen**: Erstellen und verwalten Sie Rechnungen
6. **Admin**: Systemeinstellungen und Benutzerverwaltung

## 🐛 Fehlerbehebung

### Installation schlägt fehl
- Prüfen Sie die PHP-Version (mind. 8.3)
- Stellen Sie sicher, dass alle PHP-Extensions installiert sind
- Überprüfen Sie die Schreibrechte für Ordner

### Keine Styles/JavaScript
- Prüfen Sie die Internetverbindung (CDN-Ressourcen)
- Browser-Cache leeren
- Entwickler-Konsole auf Fehler prüfen

## 📄 Lizenz

Dieses Projekt ist proprietäre Software. Alle Rechte vorbehalten.

## 👥 Support

Bei Fragen oder Problemen kontaktieren Sie bitte:
- Email: support@tierphysio-manager.de
- Website: https://www.tierphysio-manager.de

## 🔄 Updates

Regelmäßige Updates mit neuen Features und Sicherheitspatches.
Prüfen Sie das Admin-Panel für verfügbare Updates.

## ✨ Version 2.0.0

Initiale Release-Version mit allen Grundfunktionen.

---

**Tierphysio Manager 2.0** - Moderne Praxisverwaltung für Tierphysiotherapie
© 2024 TierphysioManager Team