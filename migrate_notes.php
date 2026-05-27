<?php
require_once 'config.php';
try {
    $pdo->exec("ALTER TABLE notes ADD COLUMN nom_examen VARCHAR(100) AFTER type_evaluation");
    echo "Migration réussie : Colonne 'nom_examen' ajoutée.";
} catch (PDOException $e) {
    echo "Erreur ou colonne déjà existante : " . $e->getMessage();
}
?>