<?php
require_once 'config.php';
try {
    $stmt = $pdo->query("SELECT id, email, nom, prenom, role, mot_de_pass FROM utilisateurs ORDER BY id DESC LIMIT 5");
    $users = $stmt->fetchAll();
    echo "<pre>";
    print_r($users);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>