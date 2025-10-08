# Tierphysio Manager 2.0 - Deployment Guide

## 🚀 Quick Start

### 1. System-Anforderungen
- PHP 7.4 oder höher
- MySQL 5.7 oder MariaDB 10.3+
- Apache/Nginx Webserver
- Erforderliche PHP-Extensions:
  - PDO & PDO_MySQL
  - JSON
  - MBString
  - OpenSSL

### 2. Installation

#### Schritt 1: Dateien hochladen
```bash
# Alle Dateien in das Webroot hochladen
# Struktur beibehalten!
```

#### Schritt 2: Konfiguration
```bash
# Config-Datei erstellen
cp includes/config.example.php includes/config.php

# Config-Datei bearbeiten und Datenbankzugangsdaten eintragen
nano includes/config.php
```

#### Schritt 3: Datenbank einrichten
```bash
# Option A: Über den Installer (empfohlen)
# Browser öffnen: https://ihre-domain.de/installer/

# Option B: Manuell
mysql -u username -p database_name < migrations/001_initial_schema.sql
php run_migrations.php
```

#### Schritt 4: Verzeichnisse prüfen
```bash
# Schreibrechte setzen
chmod 755 cache/
chmod 755 logs/
chmod 755 public/uploads/
chmod 755 backups/
```

## 📋 Checkliste vor Go-Live

### ✅ Datenbank
- [ ] Alle tp_* Tabellen vorhanden
- [ ] Migrationen ausgeführt (`php run_migrations.php`)
- [ ] Test-Daten gelöscht
- [ ] Backup eingerichtet

### ✅ Konfiguration
- [ ] `APP_DEBUG` auf `false` gesetzt
- [ ] `APP_URL` korrekt eingestellt
- [ ] `JWT_SECRET` generiert (mindestens 32 Zeichen)
- [ ] Mail-Einstellungen konfiguriert

### ✅ Sicherheit
- [ ] `.htaccess` Dateien vorhanden
- [ ] Installer-Verzeichnis gelöscht/geschützt
- [ ] Config-Datei Schreibschutz (`chmod 644 includes/config.php`)
- [ ] SSL-Zertifikat installiert

### ✅ Tests
- [ ] Health Check ausführen: `https://ihre-domain.de/healthcheck.php`
- [ ] System-Test ausführen: `php test_complete_system.php`
- [ ] API-Test ausführen: `php test_api_endpoints.php`

## 🔧 Wartung

### Tägliche Aufgaben
```bash
# Health Check
curl https://ihre-domain.de/healthcheck.php

# Backup (falls automatisch aktiviert)
# Wird automatisch durchgeführt
```

### Wöchentliche Aufgaben
```bash
# Datenbank-Backup
mysqldump -u username -p database_name > backup_$(date +%Y%m%d).sql

# Logs prüfen
tail -n 100 logs/error.log
```

### Updates
```bash
# 1. Backup erstellen
mysqldump -u username -p database_name > backup_before_update.sql

# 2. Neue Dateien hochladen

# 3. Migrationen ausführen
php run_migrations.php

# 4. Cache leeren
rm -rf cache/*

# 5. System testen
php test_complete_system.php
```

## 🐛 Fehlerbehebung

### Problem: "Unexpected token '<' in JSON"
**Lösung:**
1. Prüfen ob alle API-Endpoints korrekt sind
2. `php test_api_endpoints.php` ausführen
3. Error-Logs prüfen

### Problem: Tabellen fehlen
**Lösung:**
```bash
php run_migrations.php
```

### Problem: 500 Internal Server Error
**Lösung:**
1. PHP Error-Log prüfen
2. `.htaccess` Datei prüfen
3. Dateirechte prüfen

### Problem: Keine Verbindung zur Datenbank
**Lösung:**
1. Config-Datei prüfen
2. MySQL-Service prüfen
3. Firewall-Einstellungen prüfen

## 📊 Monitoring

### Health Check Endpoint
```bash
# Automatisiertes Monitoring
curl https://ihre-domain.de/healthcheck.php

# Erwartete Antwort bei gesundem System:
{
  "status": "healthy",
  "timestamp": "2025-10-08 12:00:00",
  "checks": {
    "database": {"status": "ok"},
    "database_tables": {"status": "ok"}
  }
}
```

### Wichtige Metriken
- Aktive Patienten
- Offene Rechnungen
- Heutige Termine
- Speichernutzung
- Datenbankgröße

## 🔐 Sicherheits-Hinweise

1. **Regelmäßige Updates**
   - PHP-Version aktuell halten
   - Sicherheitsupdates zeitnah einspielen

2. **Backups**
   - Tägliche Datenbank-Backups
   - Wöchentliche Komplett-Backups
   - Backup-Tests durchführen

3. **Zugangskontrolle**
   - Starke Passwörter verwenden
   - 2FA aktivieren (wenn verfügbar)
   - Regelmäßige Passwort-Änderungen

4. **Monitoring**
   - Error-Logs überwachen
   - Ungewöhnliche Aktivitäten prüfen
   - Health-Check automatisieren

## 📞 Support

Bei Problemen:
1. Logs prüfen (`/logs/error.log`)
2. Health Check ausführen
3. System-Test durchführen
4. Dokumentation konsultieren

## 📝 Wichtige Dateien

- `/includes/config.php` - Hauptkonfiguration
- `/migrations/` - Datenbank-Migrationen
- `/test_complete_system.php` - System-Test
- `/healthcheck.php` - Health Check
- `/run_migrations.php` - Migration Runner
- `/FIX_REPORT_TP_SCHEMA.md` - Schema-Fix Dokumentation

## ✅ Fertig!

Nach erfolgreicher Installation:
1. Standard-Admin-Account ändern
2. Eigene Praxisdaten eingeben
3. Erste Patienten anlegen
4. System produktiv nutzen

---

**Version:** 2.0.0  
**Letztes Update:** Oktober 2025  
**Branch:** fix/tp-schema-patients