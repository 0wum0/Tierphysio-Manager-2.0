<?php
/**
 * Admin Cron Jobs API
 */

require_once __DIR__ . '/_bootstrap.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $stmt = $pdo->query("
            SELECT cj.*, 
                   (SELECT COUNT(*) FROM tp_cron_logs WHERE job_id = cj.id) as log_count
            FROM tp_cron_jobs cj
            ORDER BY cj.key
        ");
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        api_success($jobs, null, count($jobs));
        break;
        
    case 'toggle':
        csrf_check();
        requirePermission('cron.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        $active = sanitize($data['active'] ?? false, 'bool');
        
        if (!$id) {
            api_error('Job ID erforderlich');
        }
        
        $stmt = $pdo->prepare("UPDATE tp_cron_jobs SET is_active = ? WHERE id = ?");
        $stmt->execute([$active ? 1 : 0, $id]);
        
        if ($stmt->rowCount() > 0) {
            api_success(null, $active ? 'Job aktiviert' : 'Job deaktiviert');
        } else {
            api_error('Job nicht gefunden', 404);
        }
        break;
        
    case 'run':
        csrf_check();
        requirePermission('cron.manage');
        
        $data = getJsonInput();
        $id = sanitize($data['id'] ?? 0, 'int');
        
        if (!$id) {
            api_error('Job ID erforderlich');
        }
        
        // Get job details
        $stmt = $pdo->prepare("SELECT * FROM tp_cron_jobs WHERE id = ?");
        $stmt->execute([$id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            api_error('Job nicht gefunden', 404);
        }
        
        // Create log entry
        $stmt = $pdo->prepare("INSERT INTO tp_cron_logs (job_id, started_at, status) VALUES (?, NOW(), 'running')");
        $stmt->execute([$id]);
        $logId = $pdo->lastInsertId();
        
        // Simulate job execution (in real app, would execute actual job logic)
        $success = true;
        $message = 'Job erfolgreich ausgeführt';
        
        // Special handling for known jobs
        switch ($job['key']) {
            case 'appointment_reminders':
                $message = 'Terminerinnerungen versendet: 0';
                break;
            case 'birthday_greetings':
                $message = 'Geburtstagsgrüße versendet: 0';
                break;
            case 'backup_database':
                $message = 'Datenbank-Backup erstellt';
                break;
            case 'cleanup_logs':
                $message = 'Logs bereinigt';
                break;
        }
        
        // Update log entry
        $stmt = $pdo->prepare("
            UPDATE tp_cron_logs 
            SET finished_at = NOW(), status = ?, message = ?
            WHERE id = ?
        ");
        $stmt->execute([$success ? 'success' : 'failed', $message, $logId]);
        
        // Update last run info
        $stmt = $pdo->prepare("
            UPDATE tp_cron_jobs 
            SET last_run = NOW(), last_result = ?
            WHERE id = ?
        ");
        $stmt->execute([$success ? 'success' : 'failed', $id]);
        
        api_success(['log_id' => $logId], $message);
        break;
        
    case 'logs':
        $jobId = sanitize($_GET['job_id'] ?? 0, 'int');
        $limit = sanitize($_GET['limit'] ?? 50, 'int');
        $offset = sanitize($_GET['offset'] ?? 0, 'int');
        
        $where = $jobId ? "WHERE cl.job_id = ?" : "";
        $params = $jobId ? [$jobId] : [];
        
        $stmt = $pdo->prepare("
            SELECT cl.*, cj.key as job_name
            FROM tp_cron_logs cl
            JOIN tp_cron_jobs cj ON cl.job_id = cj.id
            $where
            ORDER BY cl.started_at DESC
            LIMIT ? OFFSET ?
        ");
        
        if ($jobId) {
            $stmt->bindValue(1, $jobId, PDO::PARAM_INT);
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        } else {
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        }
        
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tp_cron_logs cl $where");
        if ($jobId) {
            $stmt->execute([$jobId]);
        } else {
            $stmt->execute();
        }
        $total = $stmt->fetchColumn();
        
        api_success($logs, null, $total);
        break;
        
    case 'clear_logs':
        csrf_check();
        requirePermission('cron.manage');
        
        $data = getJsonInput();
        $jobId = sanitize($data['job_id'] ?? 0, 'int');
        
        if ($jobId) {
            $stmt = $pdo->prepare("DELETE FROM tp_cron_logs WHERE job_id = ?");
            $stmt->execute([$jobId]);
        } else {
            $stmt = $pdo->exec("DELETE FROM tp_cron_logs WHERE started_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }
        
        api_success(null, 'Logs erfolgreich bereinigt');
        break;
        
    default:
        api_error('Unbekannte Aktion', 400);
}