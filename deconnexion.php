<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Vider le tableau de session
$_SESSION = array();
// Détruire la session
session_destroy();
// Rediriger vers la page de login
header('Location: login.php');
exit();
?>