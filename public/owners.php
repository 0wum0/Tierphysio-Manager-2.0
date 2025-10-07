<?php
/**
 * Tierphysio Manager 2.0
 * Owners API Endpoint
 */

require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');

// Check authentication
checkApiAuth();

// Get action from request
$action = $_REQUEST['action'] ?? 'get_all';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'get_all':
            // Get all owners with optional filters
            $search = $_GET['search'] ?? '';
            $limit = intval($_GET['limit'] ?? 100);
            $offset = intval($_GET['offset'] ?? 0);
            
            $sql = "SELECT o.*, 
                    COUNT(DISTINCT p.id) as patient_count,
                    COUNT(DISTINCT i.id) as invoice_count
                    FROM tp_owners o 
                    LEFT JOIN tp_patients p ON o.id = p.owner_id AND p.is_active = 1
                    LEFT JOIN tp_invoices i ON o.id = i.owner_id
                    WHERE 1=1";
            
            $params = [];
            
            if ($search) {
                $sql .= " AND (o.first_name LIKE :search OR o.last_name LIKE :search 
                         OR o.email LIKE :search OR o.customer_number LIKE :search 
                         OR o.phone LIKE :search OR o.mobile LIKE :search)";
                $params['search'] = "%$search%";
            }
            
            $sql .= " GROUP BY o.id ORDER BY o.last_name, o.first_name LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $owners = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $owners,
                "message" => count($owners) . " Besitzer gefunden"
            ]);
            break;
            
        case 'get_by_id':
            $id = intval($_GET['id'] ?? 0);
            
            if (!$id) {
                throw new Exception('Besitzer ID fehlt');
            }
            
            $sql = "SELECT * FROM tp_owners WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            $owner = $stmt->fetch();
            
            if (!$owner) {
                throw new Exception('Besitzer nicht gefunden');
            }
            
            // Get associated patients
            $sql = "SELECT * FROM tp_patients WHERE owner_id = :owner_id AND is_active = 1 ORDER BY name";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['owner_id' => $id]);
            $owner['patients'] = $stmt->fetchAll();
            
            // Get recent invoices
            $sql = "SELECT * FROM tp_invoices WHERE owner_id = :owner_id ORDER BY invoice_date DESC LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['owner_id' => $id]);
            $owner['invoices'] = $stmt->fetchAll();
            
            // Get notes
            $sql = "SELECT n.*, u.first_name as creator_first_name, u.last_name as creator_last_name 
                    FROM tp_notes n 
                    LEFT JOIN tp_users u ON n.created_by = u.id 
                    WHERE n.owner_id = :owner_id 
                    ORDER BY n.created_at DESC LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['owner_id' => $id]);
            $owner['notes'] = $stmt->fetchAll();
            
            echo json_encode([
                "status" => "success",
                "data" => $owner,
                "message" => "Besitzer gefunden"
            ]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Nur POST-Methode erlaubt');
            }
            
            // Validate required fields
            if (empty($_POST['first_name']) || empty($_POST['last_name'])) {
                throw new Exception('Vor- und Nachname sind Pflichtfelder');
            }
            
            // Generate customer number
            $customer_number = 'K' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
            
            $sql = "INSERT INTO tp_owners (
                        customer_number, salutation, first_name, last_name, company, 
                        email, phone, mobile, street, house_number, postal_code, 
                        city, country, notes, newsletter, invoice_email, payment_method, 
                        iban, bic, tax_number, created_by
                    ) VALUES (
                        :customer_number, :salutation, :first_name, :last_name, :company, 
                        :email, :phone, :mobile, :street, :house_number, :postal_code, 
                        :city, :country, :notes, :newsletter, :invoice_email, :payment_method, 
                        :iban, :bic, :tax_number, :created_by
                    )";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'customer_number' => $customer_number,
                'salutation' => $_POST['salutation'] ?? 'Herr',
                'first_name' => $_POST['first_name'],
                'last_name' => $_POST['last_name'],
                'company' => $_POST['company'] ?? null,
                'email' => $_POST['email'] ?? null,
                'phone' => $_POST['phone'] ?? null,
                'mobile' => $_POST['mobile'] ?? null,
                'street' => $_POST['street'] ?? null,
                'house_number' => $_POST['house_number'] ?? null,
                'postal_code' => $_POST['postal_code'] ?? null,
                'city' => $_POST['city'] ?? null,
                'country' => $_POST['country'] ?? 'Deutschland',
                'notes' => $_POST['notes'] ?? null,
                'newsletter' => isset($_POST['newsletter']) ? 1 : 0,
                'invoice_email' => $_POST['invoice_email'] ?? $_POST['email'] ?? null,
                'payment_method' => $_POST['payment_method'] ?? 'transfer',
                'iban' => $_POST['iban'] ?? null,
                'bic' => $_POST['bic'] ?? null,
                'tax_number' => $_POST['tax_number'] ?? null,
                'created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $ownerId = $pdo->lastInsertId();
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $ownerId, "customer_number" => $customer_number],
                "message" => "Besitzer erfolgreich angelegt"
            ]);
            break;
            
        case 'update':
            if ($method !== 'POST' && $method !== 'PUT') {
                throw new Exception('Nur POST/PUT-Methode erlaubt');
            }
            
            $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) {
                throw new Exception('Besitzer ID fehlt');
            }
            
            // Parse PUT data if necessary
            if ($method === 'PUT') {
                parse_str(file_get_contents("php://input"), $_POST);
            }
            
            $sql = "UPDATE tp_owners SET 
                        salutation = :salutation,
                        first_name = :first_name,
                        last_name = :last_name,
                        company = :company,
                        email = :email,
                        phone = :phone,
                        mobile = :mobile,
                        street = :street,
                        house_number = :house_number,
                        postal_code = :postal_code,
                        city = :city,
                        country = :country,
                        notes = :notes,
                        newsletter = :newsletter,
                        invoice_email = :invoice_email,
                        payment_method = :payment_method,
                        iban = :iban,
                        bic = :bic,
                        tax_number = :tax_number,
                        updated_at = NOW()
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'id' => $id,
                'salutation' => $_POST['salutation'] ?? 'Herr',
                'first_name' => $_POST['first_name'] ?? '',
                'last_name' => $_POST['last_name'] ?? '',
                'company' => $_POST['company'] ?? null,
                'email' => $_POST['email'] ?? null,
                'phone' => $_POST['phone'] ?? null,
                'mobile' => $_POST['mobile'] ?? null,
                'street' => $_POST['street'] ?? null,
                'house_number' => $_POST['house_number'] ?? null,
                'postal_code' => $_POST['postal_code'] ?? null,
                'city' => $_POST['city'] ?? null,
                'country' => $_POST['country'] ?? 'Deutschland',
                'notes' => $_POST['notes'] ?? null,
                'newsletter' => isset($_POST['newsletter']) ? 1 : 0,
                'invoice_email' => $_POST['invoice_email'] ?? $_POST['email'] ?? null,
                'payment_method' => $_POST['payment_method'] ?? 'transfer',
                'iban' => $_POST['iban'] ?? null,
                'bic' => $_POST['bic'] ?? null,
                'tax_number' => $_POST['tax_number'] ?? null
            ]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Besitzer erfolgreich aktualisiert"
            ]);
            break;
            
        case 'delete':
            if ($method !== 'DELETE' && $method !== 'POST') {
                throw new Exception('Nur DELETE/POST-Methode erlaubt');
            }
            
            $id = intval($_REQUEST['id'] ?? 0);
            if (!$id) {
                throw new Exception('Besitzer ID fehlt');
            }
            
            // Check for related records
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM tp_patients WHERE owner_id = :id AND is_active = 1");
            $stmt->execute(['id' => $id]);
            $patients = $stmt->fetch()['count'];
            
            if ($patients > 0) {
                throw new Exception('Besitzer kann nicht gelöscht werden. Es existieren noch ' . $patients . ' aktive Patienten.');
            }
            
            // Hard delete (or you could implement soft delete)
            $sql = "DELETE FROM tp_owners WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            
            echo json_encode([
                "status" => "success",
                "data" => ["id" => $id],
                "message" => "Besitzer erfolgreich gelöscht"
            ]);
            break;
            
        default:
            throw new Exception('Unbekannte Aktion: ' . $action);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage(),
        "data" => null
    ]);
}