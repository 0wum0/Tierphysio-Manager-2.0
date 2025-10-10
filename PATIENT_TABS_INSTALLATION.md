# Tierphysio Manager 2.0 - Patientenakte Tabs Installation

## 🎉 Feature: Erweiterte Patientenakte mit Tabs

Die Patientenakte wurde erweitert mit 4 Tabs:
- **Übersicht**: Allgemeine Patienteninformationen
- **Befunde**: Medizinische Befunde und Untersuchungsergebnisse
- **Notizen**: Allgemeine Notizen zum Patienten
- **Dokumente**: Upload und Verwaltung von Dokumenten (PDF, Word, Bilder)

## 📋 Änderungen

### 1. Frontend (templates/pages/patients.twig)
- Neues Tab-Layout im Patientenmodal
- Alpine.js Tab-Navigation mit smooth transitions
- Formulare für Befunde und Notizen
- File-Upload für Dokumente

### 2. JavaScript (in patients.twig inline)
- `patientModal()` erweitert mit Tab-State und neuen Methoden:
  - `saveRecord()`: Speichert medizinische Befunde
  - `saveNote()`: Speichert allgemeine Notizen
  - `uploadDocument()`: Handled Document-Uploads
  - `loadRecords()`, `loadNotes()`, `loadDocuments()`: Lädt Tab-Daten

### 3. Backend (api/patients.php)
Neue API-Endpunkte:
- `?action=get_records`: Lädt medizinische Befunde
- `?action=get_notes`: Lädt Notizen
- `?action=get_documents`: Lädt Dokumente
- `?action=save_record`: Speichert neuen Befund
- `?action=save_note`: Speichert neue Notiz
- `?action=upload_pdf`: Upload von Dokumenten

### 4. Datenbank
Neue Tabellen:
- `tp_notes`: Speichert Befunde und Notizen
- `tp_documents`: Speichert Dokument-Metadaten

## 🚀 Installation

### Schritt 1: Datenbank-Migration
Führen Sie die Migration aus, um die neuen Tabellen zu erstellen:

```bash
# Option A: Direkt via MySQL
mysql -u root -p tierphysio_db < migrations/004_patient_tabs.sql

# Option B: Via PHP Script (empfohlen)
php api/install_patient_tabs.php
```

### Schritt 2: Upload-Verzeichnis
Das Upload-Verzeichnis wurde bereits erstellt:
```
/workspace/public/uploads/docs/
```

Stellen Sie sicher, dass es die richtigen Berechtigungen hat:
```bash
chmod 755 public/uploads/docs/
```

### Schritt 3: Testen
1. Öffnen Sie die Patientenverwaltung
2. Klicken Sie auf einen Patienten
3. Das erweiterte Modal mit Tabs sollte erscheinen
4. Testen Sie alle Tabs:
   - Übersicht anzeigen
   - Befund hinzufügen
   - Notiz speichern
   - Dokument hochladen

## ✅ Verifizierung

Das Feature funktioniert korrekt, wenn:
- [ ] Modal zeigt 4 Tabs (Übersicht, Befunde, Notizen, Dokumente)
- [ ] Tab-Wechsel funktioniert mit smooth animations
- [ ] Befunde können gespeichert und angezeigt werden
- [ ] Notizen können gespeichert und angezeigt werden
- [ ] Dokumente können hochgeladen werden (PDF, Word, Bilder)
- [ ] Hochgeladene Dokumente werden in der Liste angezeigt
- [ ] "Ansehen"-Link öffnet Dokumente in neuem Tab

## 🎨 Styling
- Verwendet Tailwind CSS mit lilac-500 Farbschema
- Dark Mode kompatibel
- Responsive Design
- Animationen via Alpine.js x-transition

## 🔒 Sicherheit
- Datei-Upload validiert erlaubte Dateitypen
- Unique Filenames verhindern Konflikte
- Foreign Key Constraints sichern Datenintegrität
- Session-basierte User ID für Audit Trail

## 📝 Hinweise
- Upload-Limit: Standard PHP upload_max_filesize
- Erlaubte Dateitypen: PDF, DOC, DOCX, JPG, JPEG, PNG
- Alle Daten werden mit Patient verknüpft und beim Löschen kaskadiert

## 🐛 Troubleshooting

**Problem**: Tabs werden nicht angezeigt
- Lösung: Cache leeren, Browser neu laden

**Problem**: Upload schlägt fehl
- Lösung: Berechtigungen von /public/uploads/docs/ prüfen
- Lösung: PHP upload_max_filesize in php.ini prüfen

**Problem**: Daten werden nicht gespeichert
- Lösung: Datenbank-Tabellen mit install_patient_tabs.php erstellen
- Lösung: Browser Console auf JavaScript-Fehler prüfen

---

Bei Fragen oder Problemen wenden Sie sich an den Administrator.