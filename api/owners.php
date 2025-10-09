<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/db.php';

try {
    $action = $_GET['action'] ?? 'list';
    $pdo = get_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'list') {
        $stmt = $pdo->query("SELECT id, first_name, last_name, email, phone, city, created_at FROM tp_owners ORDER BY last_name, first_name");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        api_success(['items' => $rows, 'count' => count($rows)]);
    }

    if ($action === 'create') {
        $data = json_decode(file_get_contents('php://input'), true);
        $sql = "INSERT INTO tp_owners (customer_number, salutation, first_name, last_name, email, phone, mobile, street, house_number, postal_code, city, created_by)
                VALUES (:customer_number, :salutation, :first_name, :last_name, :email, :phone, :mobile, :street, :house_number, :postal_code, :city, :created_by)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':customer_number' => uniqid('OWN-'),
            ':salutation' => $data['salutation'] ?? 'Herr',
            ':first_name' => $data['first_name'] ?? '',
            ':last_name' => $data['last_name'] ?? '',
            ':email' => $data['email'] ?? '',
            ':phone' => $data['phone'] ?? '',
            ':mobile' => $data['mobile'] ?? '',
            ':street' => $data['street'] ?? '',
            ':house_number' => $data['house_number'] ?? '',
            ':postal_code' => $data['postal_code'] ?? '',
            ':city' => $data['city'] ?? '',
            ':created_by' => $_SESSION['user_id'] ?? null
        ]);
        $newId = $pdo->lastInsertId();
        $stmt = $pdo->prepare("SELECT * FROM tp_owners WHERE id = ?");
        $stmt->execute([$newId]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        api_success(['items' => [$owner]]);
    }

    if ($action === 'delete') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM tp_owners WHERE id = ?");
            $stmt->execute([$id]);
            api_success(['items' => []]);
        } else {
            api_error('Invalid owner ID');
        }
    }

    api_error('Unknown action');
} catch (Throwable $e) {
    api_error($e->getMessage());
}