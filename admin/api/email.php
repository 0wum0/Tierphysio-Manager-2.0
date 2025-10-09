<?php
/**
 * Admin Email Management API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'get_smtp';

switch ($action) {
    case 'get_smtp':
        $stmt = $pdo->query("SELECT `key`, value FROM tp_settings WHERE category = 'email'");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = str_replace('email_', '', $row['key']);
            $settings[$key] = $row['value'];
        }
        api_success($settings);
        break;
        
    case 'update_smtp':
        csrf_check();
        requirePermission('email.manage');
        
        $data = getJsonInput();
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("
                INSERT INTO tp_settings (`key`, value, category, updated_at)
                VALUES (?, ?, 'email', NOW())
                ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()
            ");
            
            $fields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 
                      'smtp_encryption', 'from_email', 'from_name'];
            
            foreach ($fields as $field) {
                if (isset($data[$field])) {
                    $stmt->execute(['email_' . $field, $data[$field]]);
                }
            }
            
            $pdo->commit();
            api_success(null, 'SMTP-Einstellungen gespeichert');
        } catch (Exception $e) {
            $pdo->rollBack();
            api_error('Fehler beim Speichern: ' . $e->getMessage());
        }
        break;
        
    case 'test':
        csrf_check();
        requirePermission('email.manage');
        
        $data = getJsonInput();
        $to = sanitize($data['to'] ?? '', 'email');
        
        if (!$to) {
            api_error('Empfänger E-Mail erforderlich');
        }
        
        // Get SMTP settings
        $stmt = $pdo->query("SELECT `key`, value FROM tp_settings WHERE category = 'email'");
        $smtp = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $key = str_replace('email_', '', $row['key']);
            $smtp[$key] = $row['value'];
        }
        
        // Try to send test email
        $subject = 'Test E-Mail von Tierphysio Manager';
        $message = "Dies ist eine Test-E-Mail.\n\nWenn Sie diese Nachricht erhalten, funktionieren Ihre E-Mail-Einstellungen korrekt.";
        $headers = "From: " . ($smtp['from_name'] ?? 'Tierphysio Manager') . " <" . ($smtp['from_email'] ?? 'noreply@example.com') . ">\r\n";
        $headers .= "Reply-To: " . ($smtp['from_email'] ?? 'noreply@example.com') . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();
        
        if (mail($to, $subject, $message, $headers)) {
            api_success(null, 'Test-E-Mail wurde gesendet');
        } else {
            api_error('Fehler beim Senden der Test-E-Mail');
        }
        break;
        
    case 'templates':
        $stmt = $pdo->query("SELECT * FROM tp_email_templates ORDER BY `key`");
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        api_success($templates, null, count($templates));
        break;
        
    case 'get_template':
        $id = sanitize($_GET['id'] ?? 0, 'int');
        if (!$id) {
            api_error('Template ID erforderlich');
        }
        
        $stmt = $pdo->prepare("SELECT * FROM tp_email_templates WHERE id = ?");
        $stmt->execute([$id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$template) {
            api_error('Template nicht gefunden', 404);
        }
        
        api_success($template);
        break;
        
    case 'save_template':
        csrf_check();
        requirePermission('email.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        
        if (!$id) {
            api_error('Template ID erforderlich');
        }
        
        $subject = sanitize($data['subject'] ?? '');
        $body_html = $data['body_html'] ?? '';
        $body_text = $data['body_text'] ?? '';
        $is_active = sanitize($data['is_active'] ?? 1, 'bool');
        
        try {
            $stmt = $pdo->prepare("
                UPDATE tp_email_templates 
                SET subject = ?, body_html = ?, body_text = ?, is_active = ?, 
                    updated_by = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$subject, $body_html, $body_text, $is_active ? 1 : 0, $auth->getUserId(), $id]);
            
            if ($stmt->rowCount() > 0) {
                api_success(null, 'Template erfolgreich gespeichert');
            } else {
                api_error('Template nicht gefunden oder keine Änderungen', 404);
            }
        } catch (Exception $e) {
            api_error('Fehler beim Speichern: ' . $e->getMessage());
        }
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}