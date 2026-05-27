<?php
require_once 'config.php';
$users = $pdo->query("SELECT email, mot_de_pass, role FROM utilisateurs")->fetchAll(PDO::FETCH_ASSOC);
echo "<h3>Liste des utilisateurs enregistrés :</h3>";
echo "<table border='1'><tr><th>Email</th><th>Mot de Passe</th><th>Rôle</th></tr>";
foreach($users as $u) {
    echo "<tr><td>{$u['email']}</td><td>{$u['mot_de_pass']}</td><td>{$u['role']}</td></tr>";
}
echo "</table>";
?>