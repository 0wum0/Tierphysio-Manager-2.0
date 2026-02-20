<?php
/**
 * Tierphysio Manager 2.0
 * Owners Management Page
 */

// Load composer autoloader for dependencies
require_once __DIR__ . '/../includes/autoload.php';

// Load configuration and helpers
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/template.php';

// Initialize auth with namespace (Singleton pattern)
$auth = TierphysioManager\Auth::getInstance();

// Check authentication
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

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

    // Render owner view template
    render_template('pages/owner_view.twig', [
      'pageTitle' => 'Besitzer-Details',
      'owner' => $owner,
      'patients' => $patients->fetchAll(PDO::FETCH_ASSOC),
      'user' => $auth->getUser(),
      'csrf_token' => $auth->getCSRFToken()
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

  // Render owners list template
  render_template('pages/owners.twig', [
    'pageTitle' => 'Besitzerverwaltung',
    'owners' => $owners,
    'search' => $search,
    'user' => $auth->getUser(),
    'csrf_token' => $auth->getCSRFToken()
  ]);

} catch (Throwable $e) {
  echo "<pre style='color:red'>Fehler: ".$e->getMessage()."</pre>";
  exit;
}