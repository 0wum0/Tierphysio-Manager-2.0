<?php
/**
 * Tierphysio Manager 2.0
 * Standalone Template Rendering (No vendor/autoload.php required)
 * Simpler fallback when Twig is not available
 */

/**
 * Render a template without Twig
 * @param string $path Template path relative to templates directory
 * @param array $data Data to pass to template
 * @return void
 */
function render_template($path, $data = []) {
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Add global data
    $data['user'] = $_SESSION['user'] ?? null;
    $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
    $data['flash'] = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    
    // Convert .twig extension to .php if needed
    $templateFile = __DIR__ . '/../templates/' . $path;
    
    // For now, just output basic HTML with the data
    // This is a simple fallback when Twig is not available
    ?>
<!DOCTYPE html>
<html lang="de" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($data['page_title'] ?? 'Tierphysio Manager 2.0'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #1a1a1a; color: #e5e5e5; }
    </style>
</head>
<body class="bg-gray-900 text-gray-100">
    <nav class="bg-gray-800 shadow-lg">
        <div class="container mx-auto px-4 py-3">
            <div class="flex justify-between items-center">
                <h1 class="text-xl font-bold">🐾 Tierphysio Manager 2.0</h1>
                <div class="space-x-4">
                    <a href="index.php" class="hover:text-blue-400">Dashboard</a>
                    <a href="patients.php" class="hover:text-blue-400">Patienten</a>
                    <a href="owners.php" class="hover:text-blue-400">Besitzer</a>
                    <a href="appointments.php" class="hover:text-blue-400">Termine</a>
                    <a href="treatments.php" class="hover:text-blue-400">Behandlungen</a>
                    <a href="invoices.php" class="hover:text-blue-400">Rechnungen</a>
                    <a href="logout.php" class="text-red-400">Logout</a>
                </div>
            </div>
        </div>
    </nav>
    
    <main class="container mx-auto px-4 py-6">
        <?php
        // Handle different templates
        if ($path === 'pages/owners.twig') {
            include_owners_list($data);
        } elseif ($path === 'pages/owner_view.twig') {
            include_owner_view($data);
        } else {
            echo '<p class="text-red-500">Template not implemented: ' . htmlspecialchars($path) . '</p>';
        }
        ?>
    </main>
    
    <script src="/public/js/owners.js"></script>
</body>
</html>
    <?php
}

/**
 * Render owners list
 */
function include_owners_list($data) {
    $owners = $data['owners'] ?? [];
    $search = $data['search'] ?? '';
    ?>
    <div class="p-4">
        <h1 class="text-2xl font-bold mb-4">🐾 Besitzerübersicht</h1>
        <form method="get" class="mb-4 flex gap-2">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                   placeholder="Suche nach Name oder E-Mail" 
                   class="border border-gray-600 bg-gray-800 p-2 rounded w-64 text-white">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Suchen</button>
        </form>

        <?php if (empty($owners)): ?>
            <p class="text-gray-400">Keine Besitzer gefunden.</p>
        <?php else: ?>
            <table class="min-w-full border border-gray-700 text-sm">
                <thead class="bg-gray-800 text-gray-200">
                    <tr>
                        <th class="px-2 py-1 text-left">Name</th>
                        <th class="px-2 py-1">E-Mail</th>
                        <th class="px-2 py-1">Telefon</th>
                        <th class="px-2 py-1 text-center">Patienten</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($owners as $owner): ?>
                    <tr class="hover:bg-gray-700 cursor-pointer border-t border-gray-700" 
                        onclick="window.location='owners.php?action=view&id=<?php echo $owner['id']; ?>'">
                        <td class="px-2 py-1"><?php echo htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']); ?></td>
                        <td class="px-2 py-1"><?php echo htmlspecialchars($owner['email'] ?? ''); ?></td>
                        <td class="px-2 py-1"><?php echo htmlspecialchars($owner['phone'] ?? ''); ?></td>
                        <td class="px-2 py-1 text-center"><?php echo $owner['patient_count'] ?? 0; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render owner view/edit
 */
function include_owner_view($data) {
    $owner = $data['owner'] ?? [];
    $patients = $data['patients'] ?? [];
    ?>
    <div class="p-4">
        <h1 class="text-2xl font-bold mb-4">🐾 Besitzer bearbeiten</h1>
        <form method="post" action="owners.php?action=update" class="grid grid-cols-2 gap-4">
            <input type="hidden" name="id" value="<?php echo $owner['id']; ?>">
            
            <div>
                <label class="block text-sm mb-1">Anrede</label>
                <select name="salutation" class="border border-gray-600 bg-gray-800 p-2 w-full rounded text-white">
                    <option value="Herr" <?php echo ($owner['salutation'] ?? '') === 'Herr' ? 'selected' : ''; ?>>Herr</option>
                    <option value="Frau" <?php echo ($owner['salutation'] ?? '') === 'Frau' ? 'selected' : ''; ?>>Frau</option>
                    <option value="Divers" <?php echo ($owner['salutation'] ?? '') === 'Divers' ? 'selected' : ''; ?>>Divers</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm mb-1">Vorname</label>
                <input name="first_name" value="<?php echo htmlspecialchars($owner['first_name'] ?? ''); ?>" 
                       class="border border-gray-600 bg-gray-800 p-2 w-full rounded text-white">
            </div>
            
            <div>
                <label class="block text-sm mb-1">Nachname</label>
                <input name="last_name" value="<?php echo htmlspecialchars($owner['last_name'] ?? ''); ?>" 
                       class="border border-gray-600 bg-gray-800 p-2 w-full rounded text-white">
            </div>
            
            <div>
                <label class="block text-sm mb-1">E-Mail</label>
                <input name="email" type="email" value="<?php echo htmlspecialchars($owner['email'] ?? ''); ?>" 
                       class="border border-gray-600 bg-gray-800 p-2 w-full rounded text-white">
            </div>
            
            <div>
                <label class="block text-sm mb-1">Telefon</label>
                <input name="phone" value="<?php echo htmlspecialchars($owner['phone'] ?? ''); ?>" 
                       class="border border-gray-600 bg-gray-800 p-2 w-full rounded text-white">
            </div>
            
            <div>
                <label class="block text-sm mb-1">Mobil</label>
                <input name="mobile" value="<?php echo htmlspecialchars($owner['mobile'] ?? ''); ?>" 
                       class="border border-gray-600 bg-gray-800 p-2 w-full rounded text-white">
            </div>
            
            <div>
                <label class="block text-sm mb-1">Ort</label>
                <input name="city" value="<?php echo htmlspecialchars($owner['city'] ?? ''); ?>" 
                       class="border border-gray-600 bg-gray-800 p-2 w-full rounded text-white">
            </div>
            
            <div class="col-span-2">
                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Speichern</button>
                <a href="owners.php" class="inline-block bg-gray-600 text-white px-4 py-2 rounded ml-2 hover:bg-gray-700">Zurück</a>
            </div>
        </form>

        <h2 class="text-xl font-semibold mt-8 mb-2">🐶 Zugeordnete Patienten</h2>
        <?php if (empty($patients)): ?>
            <p class="text-gray-400">Keine Patienten vorhanden.</p>
        <?php else: ?>
            <ul class="list-disc pl-5">
                <?php foreach ($patients as $patient): ?>
                    <li><?php echo htmlspecialchars($patient['name'] . ' (' . $patient['species'] . ', ' . $patient['breed'] . ')'); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Set flash message
 */
function set_flash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'][$type][] = $message;
}

/**
 * Get flash messages
 */
function get_flash() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}