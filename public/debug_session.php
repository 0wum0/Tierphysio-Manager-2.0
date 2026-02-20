<?php
// public/debug_session.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Wir nutzen genau das gleiche Setup wie deine Auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h1>Session Debug</h1>";
echo "<p>Session ID: " . session_id() . "</p>";

echo "<h2>Inhalt von \$_SESSION:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

echo "<h2>Inhalt von \$_COOKIE:</h2>";
echo "<pre>";
print_r($_COOKIE);
echo "</pre>";

echo "<h2>Test:</h2>";
if (isset($_SESSION['user'])) {
    echo "<h3 style='color:green'>✅ User gefunden: " . htmlspecialchars($_SESSION['user']['username'] ?? 'Unbekannt') . "</h3>";
} else {
    echo "<h3 style='color:red'>❌ Kein User in Session gefunden!</h3>";
}
?>
