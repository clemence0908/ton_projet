<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// Sécurité : Seul l'admin peut accéder à ces données
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Accès non autorisé']);
    exit();
}

header('Content-Type: application/json');

if (isset($_GET['user_id'])) {
    try {
        $user_id = intval($_GET['user_id']);
        
        // 1. Infos de base de l'utilisateur
        $user = $pdo->prepare("SELECT id, nom, prenom, email, role FROM utilisateurs WHERE id = ?");
        $user->execute([$user_id]);
        $userData = $user->fetch();

        if ($userData) {
            $response = ['user' => $userData];
            
            if ($userData['role'] === 'etudiant') {
                // 2. Détails étudiant
                $stmt = $pdo->prepare("SELECT e.*, p.nom_promotion, g.nom AS groupe_nom 
                                      FROM etudiants e 
                                      LEFT JOIN promotions p ON e.promotion_id = p.id 
                                      LEFT JOIN groupes_td g ON e.groupe_td_id = g.id 
                                      WHERE e.utilisateur_id = ?");
                $stmt->execute([$user_id]);
                $response['details'] = $stmt->fetch();

                // 3. Notes
                $stmt = $pdo->prepare("SELECT n.*, c.nom_cours 
                                      FROM notes n 
                                      JOIN cours c ON n.cours_id = c.id 
                                      WHERE n.etudiant_id = ? 
                                      ORDER BY n.date_saisie DESC");
                $stmt->execute([$user_id]);
                $response['notes'] = $stmt->fetchAll();

                // 4. Absences
                $stmt = $pdo->prepare("SELECT p.*, s.date_cours, c.nom_cours 
                                      FROM presences p 
                                      JOIN sessions_cours s ON p.session_cours_id = s.id 
                                      JOIN cours c ON s.cours_id = c.id 
                                      WHERE p.etudiant_id = ? AND p.statut_presence != 'present'");
                $stmt->execute([$user_id]);
                $response['absences'] = $stmt->fetchAll();
            } else if ($userData['role'] === 'enseignant') {
                // 2. Détails enseignant - Cours assurés
                $stmt = $pdo->prepare("SELECT DISTINCT c.nom_cours, p.nom_promotion 
                                      FROM sessions_cours s 
                                      JOIN cours c ON s.cours_id = c.id 
                                      JOIN promotions p ON c.promotion_id = p.id 
                                      WHERE s.enseignant_id = ?");
                $stmt->execute([$user_id]);
                $response['cours'] = $stmt->fetchAll();
            }
            
            echo json_encode($response);
        } else {
            echo json_encode(['error' => 'Utilisateur non trouvé']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['error' => 'ID utilisateur manquant']);
}
exit();
?>