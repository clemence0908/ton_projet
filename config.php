<?php
// Empêcher l'affichage des erreurs brutes aux utilisateurs en production (Sécurité)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Démarrer la session globale si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$dbname = 'smartcampus_db';
$username = 'root'; 
$password = 'root'; // Note : Sur MAMP, le mot de passe par défaut est souvent 'root' (sur XAMPP il est vide)

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    // Configurer PDO pour lever des exceptions en cas d'erreur SQL
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Récupérer les résultats sous forme de tableaux associatifs par défaut
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erreur critique de connexion à la base de données : " . $e->getMessage());
}
?>
