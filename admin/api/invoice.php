<?php
/**
 * Admin Invoice Design API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'get';

switch ($action) {
    case 'get':
        $stmt = $pdo->query("SELECT * FROM tp_invoice_design WHERE id = 1");
        $design = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$design) {
            // Create default entry
            $stmt = $pdo->exec("
                INSERT IGNORE INTO tp_invoice_design 
                (id, color_primary, color_accent, header_text, footer_text)
                VALUES (1, '#9b5de5', '#7C4DFF', '', '')
            ");
            
            $stmt = $pdo->query("SELECT * FROM tp_invoice_design WHERE id = 1");
            $design = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        api_success($design);
        break;
        
    case 'save':
        csrf_check();
        requirePermission('invoice.design');
        
        $data = getJsonInput();
        
        $updates = [];
        $params = [];
        
        if (isset($data['logo_path'])) {
            $updates[] = 'logo_path = ?';
            $params[] = sanitize($data['logo_path']);
        }
        
        if (isset($data['color_primary'])) {
            $color = sanitize($data['color_primary']);
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                api_error('Ungültiges Farbformat für Primärfarbe');
            }
            $updates[] = 'color_primary = ?';
            $params[] = $color;
        }
        
        if (isset($data['color_accent'])) {
            $color = sanitize($data['color_accent']);
            if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                api_error('Ungültiges Farbformat für Akzentfarbe');
            }
            $updates[] = 'color_accent = ?';
            $params[] = $color;
        }
        
        if (isset($data['header_text'])) {
            $updates[] = 'header_text = ?';
            $params[] = $data['header_text'];
        }
        
        if (isset($data['footer_text'])) {
            $updates[] = 'footer_text = ?';
            $params[] = $data['footer_text'];
        }
        
        if (empty($updates)) {
            api_error('Keine Daten zum Aktualisieren');
        }
        
        $updates[] = 'updated_by = ?';
        $params[] = $auth->getUserId();
        
        $updates[] = 'updated_at = NOW()';
        
        try {
            // Ensure record exists
            $pdo->exec("
                INSERT IGNORE INTO tp_invoice_design 
                (id, color_primary, color_accent)
                VALUES (1, '#9b5de5', '#7C4DFF')
            ");
            
            // Update
            $stmt = $pdo->prepare("UPDATE tp_invoice_design SET " . implode(', ', $updates) . " WHERE id = 1");
            $stmt->execute($params);
            
            api_success(null, 'Design erfolgreich gespeichert');
        } catch (Exception $e) {
            api_error('Fehler beim Speichern: ' . $e->getMessage());
        }
        break;
        
    case 'preview':
        requirePermission('invoice.design');
        
        // Get current design
        $stmt = $pdo->query("SELECT * FROM tp_invoice_design WHERE id = 1");
        $design = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'logo_path' => '',
            'color_primary' => '#9b5de5',
            'color_accent' => '#7C4DFF',
            'header_text' => "Tierphysiotherapie Praxis\nMusterstraße 123\n12345 Musterstadt",
            'footer_text' => "Bankverbindung: Musterbank | IBAN: DE12 3456 7890 1234 5678 90"
        ];
        
        // Generate sample PDF preview (HTML for now)
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { border-bottom: 3px solid ' . $design['color_primary'] . '; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { max-width: 200px; max-height: 80px; }
        .header-text { white-space: pre-line; color: #333; }
        .invoice-title { color: ' . $design['color_primary'] . '; font-size: 24px; margin: 20px 0; }
        .info-row { margin: 10px 0; }
        .items-table { width: 100%; border-collapse: collapse; margin: 30px 0; }
        .items-table th { background: ' . $design['color_primary'] . '; color: white; padding: 10px; text-align: left; }
        .items-table td { padding: 10px; border-bottom: 1px solid #ddd; }
        .total-row { font-weight: bold; background: #f5f5f5; }
        .footer { border-top: 3px solid ' . $design['color_accent'] . '; padding-top: 20px; margin-top: 50px; }
        .footer-text { white-space: pre-line; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="header">
        ' . ($design['logo_path'] ? '<img src="' . $design['logo_path'] . '" class="logo">' : '') . '
        <div class="header-text">' . htmlspecialchars($design['header_text']) . '</div>
    </div>
    
    <h1 class="invoice-title">Rechnung Nr. RE-2025-0001</h1>
    
    <div class="info-row"><strong>Rechnungsdatum:</strong> ' . date('d.m.Y') . '</div>
    <div class="info-row"><strong>Fälligkeitsdatum:</strong> ' . date('d.m.Y', strtotime('+14 days')) . '</div>
    <div class="info-row"><strong>Patient:</strong> Max Mustermann</div>
    
    <table class="items-table">
        <thead>
            <tr>
                <th>Beschreibung</th>
                <th>Menge</th>
                <th>Einzelpreis</th>
                <th>Gesamt</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Physiotherapie Erstbehandlung</td>
                <td>1</td>
                <td>75,00 €</td>
                <td>75,00 €</td>
            </tr>
            <tr>
                <td>Manuelle Therapie</td>
                <td>2</td>
                <td>45,00 €</td>
                <td>90,00 €</td>
            </tr>
            <tr class="total-row">
                <td colspan="3">Gesamtbetrag (inkl. 19% MwSt.)</td>
                <td>195,85 €</td>
            </tr>
        </tbody>
    </table>
    
    <div class="footer">
        <div class="footer-text">' . htmlspecialchars($design['footer_text']) . '</div>
    </div>
</body>
</html>';
        
        // Output HTML preview
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
        
    case 'upload_logo':
        csrf_check();
        requirePermission('invoice.design');
        
        if (!isset($_FILES['logo'])) {
            api_error('Keine Datei hochgeladen');
        }
        
        $file = $_FILES['logo'];
        
        // Validate
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml'];
        if (!in_array($file['type'], $allowedTypes)) {
            api_error('Ungültiger Dateityp');
        }
        
        if ($file['size'] > 2 * 1024 * 1024) {
            api_error('Datei zu groß (max. 2MB)');
        }
        
        // Upload
        $uploadDir = __DIR__ . '/../../public/uploads';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'invoice_logo_' . uniqid() . '.' . $extension;
        $filepath = $uploadDir . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Update database
            $stmt = $pdo->prepare("
                UPDATE tp_invoice_design 
                SET logo_path = ?, updated_by = ?, updated_at = NOW()
                WHERE id = 1
            ");
            $stmt->execute(['/public/uploads/' . $filename, $auth->getUserId()]);
            
            api_success(['path' => '/public/uploads/' . $filename], 'Logo hochgeladen');
        } else {
            api_error('Fehler beim Hochladen');
        }
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}