<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/new.config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/template_standalone.php';

$auth = new \TierphysioManager\Auth();
$auth->requireLogin();
$pdo = get_pdo();

$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? null;

try {
  if ($action === 'view' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
    $stmt->execute([$id]);
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$owner) {
      header("Location: owners.php");
      exit;
    }

    $patients = $pdo->prepare("SELECT id, name, species, breed, birth_date FROM tp_patients WHERE owner_id = ?");
    $patients->execute([$id]);

    render_template('pages/owner_view.twig', [
      'owner' => $owner,
      'patients' => $patients->fetchAll(PDO::FETCH_ASSOC),
      'page_title' => 'Besitzer-Details'
    ]);
    exit;
  }

  if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    $sql = "UPDATE tp_owners
            SET salutation=:salutation, first_name=:first_name, last_name=:last_name,
                email=:email, phone=:phone, mobile=:mobile, city=:city,
                updated_at=NOW()
            WHERE id=:id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':id' => $input['id'],
      ':salutation' => $input['salutation'] ?? 'Herr',
      ':first_name' => $input['first_name'] ?? '',
      ':last_name' => $input['last_name'] ?? '',
      ':email' => $input['email'] ?? '',
      ':phone' => $input['phone'] ?? '',
      ':mobile' => $input['mobile'] ?? '',
      ':city' => $input['city'] ?? ''
    ]);
    header("Location: owners.php?action=view&id=".$input['id']);
    exit;
  }

  $search = trim($_GET['search'] ?? '');
  $where = '';
  $params = [];

  if ($search !== '') {
    $where = "WHERE first_name LIKE :s OR last_name LIKE :s OR email LIKE :s OR phone LIKE :s";
    $params[':s'] = "%$search%";
  }

  $stmt = $pdo->prepare("SELECT o.*, 
            (SELECT COUNT(*) FROM tp_patients p WHERE p.owner_id=o.id) AS patient_count
            FROM tp_owners o $where
            ORDER BY last_name, first_name");
  $stmt->execute($params);
  $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

  render_template('pages/owners.twig', [
    'owners' => $owners,
    'search' => $search,
    'page_title' => 'Besitzerübersicht'
  ]);

} catch (Throwable $e) {
  error_log('Owners render error: '.$e->getMessage());
  http_response_code(500);
  echo "<h1>Fehler</h1><pre>".htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')."</pre>";
  exit;
}