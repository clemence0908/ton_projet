<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// PROTECTION DE LA ROUTE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'enseignant') {
    header('Location: login.php'); exit();
}
$enseignant_id = $_SESSION['user_id'];

// --- AUTO-MIGRATION : AJOUT COLONNE NOM_EXAMEN SI MANQUANTE ---
try {
    $pdo->exec("ALTER TABLE notes ADD COLUMN nom_examen VARCHAR(100) AFTER type_evaluation");
} catch (Exception $e) { /* Colonne probablement déjà présente */ }

// --- AUTO-MIGRATION : TABLE DES RENDEZ-VOUS ÉTUDIANT / ENSEIGNANT ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rendez_vous (
        id INT AUTO_INCREMENT PRIMARY KEY,
        etudiant_id INT NOT NULL,
        enseignant_id INT NOT NULL,
        sujet VARCHAR(150) NOT NULL,
        message TEXT NULL,
        date_rdv DATE NOT NULL,
        heure_debut TIME NOT NULL,
        heure_fin TIME NOT NULL,
        statut ENUM('en_attente','accepte','refuse','annule') NOT NULL DEFAULT 'en_attente',
        reponse_enseignant TEXT NULL,
        date_demande TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        date_reponse TIMESTAMP NULL DEFAULT NULL,
        INDEX idx_rdv_etudiant (etudiant_id),
        INDEX idx_rdv_enseignant (enseignant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* Table probablement déjà présente */ }

// --- AUTO-MIGRATION : TABLE DES PRÉSENCES ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS presences (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_cours_id INT NOT NULL,
        etudiant_id INT NOT NULL,
        statut_presence ENUM('present','absent_justifie','absent_injustifie') NOT NULL DEFAULT 'present',
        date_marquage TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_presence (session_cours_id, etudiant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) { /* Table probablement déjà présente */ }

$msg_status = "";
$active_tab = "dashboard";

// --- SOUMISSIONS FORMULAIRES (NOTES & MESSAGERIE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Nouvelle action pour valider un lot de notes
    if (isset($_POST['action_enregistrer_notes_classe'])) {
        $active_tab = "notes";
        $cours_id = intval($_POST['cours_id']);
        $type_eval = $_POST['type_evaluation'];
        $nom_exam = trim($_POST['nom_examen']);
        $bareme = isset($_POST['bareme']) ? floatval($_POST['bareme']) : 20;
        if ($bareme <= 0) $bareme = 20;
        
        $notes_data = $_POST['notes']; // Array [etudiant_id => note_valeur]

        try {
            $pdo->beginTransaction();
            foreach ($notes_data as $eleve_id => $note_val) {
                if ($note_val === "") continue; // Ignorer les notes vides
                
                // Conversion automatique sur 20
                $val = (floatval($note_val) / $bareme) * 20;
                
                // Vérifier si une note existe déjà
                $stmtCheck = $pdo->prepare("SELECT id, statut_verrouillage FROM notes WHERE etudiant_id = ? AND cours_id = ? AND type_evaluation = ? AND (nom_examen = ? OR nom_examen IS NULL)");
                $stmtCheck->execute([$eleve_id, $cours_id, $type_eval, $nom_exam]);
                $exist = $stmtCheck->fetch();

                if ($exist) {
                    if ($exist['statut_verrouillage'] !== 'valide_definitif') {
                        $pdo->prepare("UPDATE notes SET note_valeur = ?, nom_examen = ?, enseignant_id = ? WHERE id = ?")
                            ->execute([$val, $nom_exam, $enseignant_id, $exist['id']]);
                    }
                } else {
                    $pdo->prepare("INSERT INTO notes (etudiant_id, cours_id, enseignant_id, note_valeur, type_evaluation, nom_examen, statut_verrouillage) VALUES (?, ?, ?, ?, ?, ?, 'en_cours')")
                        ->execute([$eleve_id, $cours_id, $enseignant_id, $val, $type_eval, $nom_exam]);
                }
            }
            $pdo->commit();
            $msg_status = "<div class='alert success'>✅ Notes enregistrées et converties sur 20 avec succès.</div>";
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $msg_status = "<div class='alert error'>❌ Erreur lors de l'enregistrement : " . $e->getMessage() . "</div>";
        }
    }
    
    // Verrouillage définitif
    if (isset($_POST['action_verrouiller_notes'])) {
        $active_tab = "notes";
        $cours_id = intval($_POST['cours_id']);
        $nom_exam = trim($_POST['nom_examen']);
        try {
            $pdo->prepare("UPDATE notes SET statut_verrouillage = 'valide_definitif' WHERE cours_id = ? AND nom_examen = ? AND enseignant_id = ?")
                ->execute([$cours_id, $nom_exam, $enseignant_id]);
            $msg_status = "<div class='alert success'>🔒 Notes verrouillées définitivement.</div>";
        } catch (Exception $e) {
            $msg_status = "<div class='alert error'>❌ Erreur verrouillage : " . $e->getMessage() . "</div>";
        }
    }

    // NOUVEAU : Validation globale de la moyenne pour toute la classe
    if (isset($_POST['action_verrouiller_moyennes_classe'])) {
        $active_tab = "results";
        $cours_id = intval($_POST['cours_id']);
        $type_cl = $_POST['type_classe'];
        $id_cl = intval($_POST['id_classe']);
        
        try {
            // On verrouille toutes les notes de ce cours pour cette classe
            if ($type_cl === 'td') {
                $sql = "UPDATE notes n JOIN etudiants e ON n.etudiant_id = e.utilisateur_id 
                        SET n.statut_verrouillage = 'valide_definitif' 
                        WHERE n.cours_id = ? AND n.enseignant_id = ? AND e.groupe_td_id = ?";
            } else {
                $sql = "UPDATE notes n JOIN etudiants e ON n.etudiant_id = e.utilisateur_id 
                        SET n.statut_verrouillage = 'valide_definitif' 
                        WHERE n.cours_id = ? AND n.enseignant_id = ? 
                        AND e.groupe_td_id IN (SELECT id FROM groupes_td WHERE amphi_id = ?)";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$cours_id, $enseignant_id, $id_cl]);
            $msg_status = "<div class='alert success'>🔒 Tous les résultats de la classe ont été validés et verrouillés.</div>";
        } catch (Exception $e) {
            $msg_status = "<div class='alert error'>❌ Erreur validation globale : " . $e->getMessage() . "</div>";
        }
    }

    // MISE À JOUR D'UNE NOTE INDIVIDUELLE
    if (isset($_POST['action_modifier_note'])) {
        $active_tab = isset($_POST['active_tab_custom']) ? $_POST['active_tab_custom'] : "manage_notes";
        $note_id = intval($_POST['note_id']);
        $nouvelle_valeur_brute = floatval($_POST['note_valeur']);
        $bareme = isset($_POST['bareme_modif']) ? floatval($_POST['bareme_modif']) : 20;
        if ($bareme <= 0) $bareme = 20;

        $nouvelle_valeur = ($nouvelle_valeur_brute / $bareme) * 20;

        try {
            // On vérifie d'abord si la note n'est pas verrouillée
            $stmtCheck = $pdo->prepare("SELECT statut_verrouillage FROM notes WHERE id = ? AND enseignant_id = ?");
            $stmtCheck->execute([$note_id, $enseignant_id]);
            $note = $stmtCheck->fetch();

            if ($note && $note['statut_verrouillage'] === 'valide_definitif') {
                $msg_status = "<div class='alert error'>❌ Impossible de modifier : Cette note est validée définitivement.</div>";
            } else {
                $stmt = $pdo->prepare("UPDATE notes SET note_valeur = ? WHERE id = ? AND enseignant_id = ?");
                $stmt->execute([$nouvelle_valeur, $note_id, $enseignant_id]);
                $msg_status = "<div class='alert success'>✅ Note mise à jour avec succès.</div>";
            }
        } catch (Exception $e) {
            $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
        }
    }

    // SUPPRESSION D'UNE NOTE
    if (isset($_POST['action_supprimer_note'])) {
        $active_tab = isset($_POST['active_tab_custom']) ? $_POST['active_tab_custom'] : "manage_notes";
        $note_id = intval($_POST['note_id']);
        try {
            // On vérifie d'abord si la note n'est pas verrouillée
            $stmtCheck = $pdo->prepare("SELECT statut_verrouillage FROM notes WHERE id = ? AND enseignant_id = ?");
            $stmtCheck->execute([$note_id, $enseignant_id]);
            $note = $stmtCheck->fetch();

            if ($note && $note['statut_verrouillage'] === 'valide_definitif') {
                $msg_status = "<div class='alert error'>❌ Impossible de supprimer : Cette note est validée définitivement.</div>";
            } else {
                $stmt = $pdo->prepare("DELETE FROM notes WHERE id = ? AND enseignant_id = ?");
                $stmt->execute([$note_id, $enseignant_id]);
                $msg_status = "<div class='alert success'>🗑️ Note supprimée avec succès.</div>";
            }
        } catch (Exception $e) {
            $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
        }
    }


    // Acceptation d'une demande de rendez-vous
    if (isset($_POST['action_accepter_rdv'])) {
        $active_tab = "rdv";
        $rdv_id = intval($_POST['rdv_id'] ?? 0);
        $reponse = trim($_POST['reponse_enseignant'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE rendez_vous SET statut = 'accepte', reponse_enseignant = ?, date_reponse = NOW() WHERE id = ? AND enseignant_id = ?");
            $stmt->execute([$reponse, $rdv_id, $enseignant_id]);
            $msg_status = "<div class='alert success'>✅ Rendez-vous accepté.</div>";
        } catch (Exception $e) {
            $msg_status = "<div class='alert error'>❌ Erreur acceptation RDV : " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    // Refus d'une demande de rendez-vous
    if (isset($_POST['action_refuser_rdv'])) {
        $active_tab = "rdv";
        $rdv_id = intval($_POST['rdv_id'] ?? 0);
        $reponse = trim($_POST['reponse_enseignant'] ?? '');
        try {
            $stmt = $pdo->prepare("UPDATE rendez_vous SET statut = 'refuse', reponse_enseignant = ?, date_reponse = NOW() WHERE id = ? AND enseignant_id = ?");
            $stmt->execute([$reponse, $rdv_id, $enseignant_id]);
            $msg_status = "<div class='alert success'>❌ Rendez-vous refusé.</div>";
        } catch (Exception $e) {
            $msg_status = "<div class='alert error'>❌ Erreur refus RDV : " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    if (isset($_POST['action_envoyer_mail'])) {
        $active_tab = "messagerie";
        $dest_id = intval($_POST['destinataire_id']);
        $contenu = trim($_POST['contenu_message']);

        if (!empty($contenu) && $dest_id > 0) {
            try {
                // CORRECTION : Utilisation de la colonne 'contenu' conformément à la structure SQL
                $stmt = $pdo->prepare("INSERT INTO messages (expediteur_id, destinataire_id, contenu, date_envoi) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$enseignant_id, $dest_id, $contenu]);
                $msg_status = "<div class='alert success'>Message transmis avec succès à l'étudiant.</div>";
            } catch (PDOException $e) {
                $msg_status = "<div class='alert error'>Erreur envoi message : " . $e->getMessage() . "</div>";
            }
        }
    }
}

// NAVIGATION DE L'EMPLOI DU TEMPS
$offset_semaine = isset($_GET['semaine']) ? intval($_GET['semaine']) : 0;
if (isset($_GET['semaine'])) { $active_tab = "edt"; }

if (isset($_GET['presence_session_id'])) { $active_tab = "presence"; }

$lundi_courant = strtotime('monday this week');
$timestamp_lundi = strtotime($offset_semaine . " weeks", $lundi_courant);
$date_debut_semaine = date('Y-m-d', $timestamp_lundi);
$date_fin_semaine = date('Y-m-d', strtotime('+6 days', $timestamp_lundi));

try {
    $stmtEdt = $pdo->prepare("
        SELECT s.*, c.nom_cours, sl.nom_salle, g.nom AS groupe_nom, p.nom_promotion
        FROM sessions_cours s
        JOIN cours c ON s.cours_id = c.id
        JOIN salles sl ON s.salle_id = sl.id
        LEFT JOIN groupes_td g ON s.groupe_td_id = g.id
        LEFT JOIN amphis a ON g.amphi_id = a.id
        LEFT JOIN promotions p ON a.promotion_id = p.id
        WHERE s.enseignant_id = ? AND s.date_cours BETWEEN ? AND ?
        ORDER BY s.date_cours ASC, s.heure_debut ASC
    ");
    $stmtEdt->execute([$enseignant_id, $date_debut_semaine, $date_fin_semaine]);
    $sessions = $stmtEdt->fetchAll();
} catch (PDOException $e) {
    $msg_status .= "<div class='alert error'>Erreur Emploi du temps : " . $e->getMessage() . "</div>";
    $sessions = [];
}


// --- DONNÉES POUR LA GESTION DES PRÉSENCES ---
$presence_sessions = [];
$selected_presence_session = isset($_GET['presence_session_id']) ? intval($_GET['presence_session_id']) : 0;
$students_presence = [];
$session_presence_info = null;

try {
    $stmtPresenceSessions = $pdo->prepare("
        SELECT s.id, s.date_cours, s.heure_debut, s.heure_fin, c.nom_cours, sl.nom_salle
        FROM sessions_cours s
        JOIN cours c ON s.cours_id = c.id
        JOIN salles sl ON s.salle_id = sl.id
        WHERE s.enseignant_id = ?
        ORDER BY s.date_cours DESC, s.heure_debut DESC
    ");
    $stmtPresenceSessions->execute([$enseignant_id]);
    $presence_sessions = $stmtPresenceSessions->fetchAll();

    if ($selected_presence_session > 0) {
        $stmtSessionInfo = $pdo->prepare("
            SELECT s.*, c.nom_cours, sl.nom_salle
            FROM sessions_cours s
            JOIN cours c ON s.cours_id = c.id
            JOIN salles sl ON s.salle_id = sl.id
            WHERE s.id = ? AND s.enseignant_id = ?
        ");
        $stmtSessionInfo->execute([$selected_presence_session, $enseignant_id]);
        $session_presence_info = $stmtSessionInfo->fetch();

        if ($session_presence_info) {
            $stmtStudentsPresence = $pdo->prepare("
                SELECT u.id, u.nom, u.prenom, p.statut_presence
                FROM inscriptions_cours ic
                JOIN utilisateurs u ON ic.etudiant_id = u.id
                LEFT JOIN presences p ON p.etudiant_id = u.id AND p.session_cours_id = ?
                WHERE ic.cours_id = ?
                ORDER BY u.nom ASC, u.prenom ASC
            ");
            $stmtStudentsPresence->execute([$selected_presence_session, $session_presence_info['cours_id']]);
            $students_presence = $stmtStudentsPresence->fetchAll();
        }
    }
} catch (Exception $e) {
    $msg_status .= "<div class='alert error'>Erreur Présences : " . htmlspecialchars($e->getMessage()) . "</div>";
}

// ÉTUDIANTS POUR LA MESSAGERIE
$les_eleves = $pdo->query("SELECT u.id, u.nom, u.prenom FROM utilisateurs u WHERE u.role = 'etudiant' ORDER BY u.nom ASC")->fetchAll();

// --- DONNÉES POUR LA SAISIE DES NOTES ---
$list_promotions = $pdo->query("SELECT id, nom_promotion FROM promotions ORDER BY nom_promotion ASC")->fetchAll();
$list_groupes = $pdo->query("SELECT g.id, g.nom AS groupe_nom, p.nom_promotion, a.nom AS amphi_nom, a.id AS amphi_id 
                             FROM groupes_td g 
                             JOIN amphis a ON g.amphi_id = a.id 
                             JOIN promotions p ON a.promotion_id = p.id 
                             ORDER BY p.nom_promotion ASC, a.nom ASC, g.nom ASC")->fetchAll();

// Étudiants filtrés si une classe est sélectionnée (via GET pour l'affichage dynamique)
$selected_class_type = isset($_GET['type_classe']) ? $_GET['type_classe'] : null; // 'td' ou 'amphi'
$selected_class_id = isset($_GET['id_classe']) ? intval($_GET['id_classe']) : null;
$students_to_grade = [];

if ($selected_class_id) {
    if (isset($_GET['active_tab'])) $active_tab = $_GET['active_tab'];
    if ($selected_class_type === 'td') {
        $stmtStd = $pdo->prepare("SELECT u.id, u.nom, u.prenom FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id WHERE e.groupe_td_id = ? ORDER BY u.nom ASC");
        $stmtStd->execute([$selected_class_id]);
    } else {
        $stmtStd = $pdo->prepare("SELECT u.id, u.nom, u.prenom FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id JOIN groupes_td g ON e.groupe_td_id = g.id WHERE g.amphi_id = ? ORDER BY u.nom ASC");
        $stmtStd->execute([$selected_class_id]);
    }
    $students_to_grade = $stmtStd->fetchAll();
}

// --- LOGIQUE POUR L'ONGLET GESTION DES NOTES ---
$selected_manage_exam = isset($_GET['manage_exam']) ? $_GET['manage_exam'] : null;
$grades_to_manage = [];
$averages_data = []; // Pour stocker les moyennes pondérées

if ($selected_class_id) {
    // 1. Récupération des notes pour l'examen sélectionné (si applicable)
    if ($selected_manage_exam) {
        $sqlManage = "SELECT n.*, u.nom, u.prenom, c.nom_cours 
                      FROM notes n 
                      JOIN utilisateurs u ON n.etudiant_id = u.id 
                      JOIN etudiants e ON u.id = e.utilisateur_id 
                      JOIN cours c ON n.cours_id = c.id
                      WHERE n.nom_examen = ? AND n.enseignant_id = ?";
        
        if ($selected_class_type === 'td') {
            $sqlManage .= " AND e.groupe_td_id = ?";
        } else {
            $sqlManage .= " AND e.groupe_td_id IN (SELECT id FROM groupes_td WHERE amphi_id = ?)";
        }
        $stmtManage = $pdo->prepare($sqlManage);
        $stmtManage->execute([$selected_manage_exam, $enseignant_id, $selected_class_id]);
        $grades_to_manage = $stmtManage->fetchAll();
    }

    // 2. Calcul des moyennes pondérées pour toute la classe (CC 40% / Examen 60%)
    // On récupère toutes les notes de la classe pour ce prof
    $sqlAvg = "SELECT n.etudiant_id, u.nom, u.prenom, n.type_evaluation, AVG(n.note_valeur) as moy_type
               FROM notes n
               JOIN utilisateurs u ON n.etudiant_id = u.id
               JOIN etudiants e ON u.id = e.utilisateur_id
               WHERE n.enseignant_id = ?";
    
    if ($selected_class_type === 'td') { $sqlAvg .= " AND e.groupe_td_id = ?"; }
    else { $sqlAvg .= " AND e.groupe_td_id IN (SELECT id FROM groupes_td WHERE amphi_id = ?)"; }
    
    $sqlAvg .= " GROUP BY n.etudiant_id, n.type_evaluation";
    $stmtAvg = $pdo->prepare($sqlAvg);
    $stmtAvg->execute([$enseignant_id, $selected_class_id]);
    $raw_averages = $stmtAvg->fetchAll();

    // Structuration des données par étudiant
    foreach ($raw_averages as $row) {
        $id = $row['etudiant_id'];
        if (!isset($averages_data[$id])) {
            $averages_data[$id] = ['nom' => $row['nom'], 'prenom' => $row['prenom'], 'CC' => null, 'Examen' => null];
        }
        $averages_data[$id][$row['type_evaluation']] = $row['moy_type'];
    }
}

// Liste des examens uniques créés par ce prof pour le filtrage
$my_exams = $pdo->prepare("SELECT DISTINCT nom_examen FROM notes WHERE enseignant_id = ? AND nom_examen IS NOT NULL ORDER BY nom_examen ASC");
$my_exams->execute([$enseignant_id]);
$my_exams = $my_exams->fetchAll(PDO::FETCH_COLUMN);

// --- LOGIQUE POUR L'ONGLET NOTES PAR CLASSE (VUE GLOBALE) ---
$selected_class_course = isset($_GET['class_course_id']) ? intval($_GET['class_course_id']) : null;
$all_class_grades = [];
if ($selected_class_id && $selected_class_course) {
    $sqlAll = "SELECT n.*, u.nom, u.prenom 
               FROM notes n 
               JOIN utilisateurs u ON n.etudiant_id = u.id 
               JOIN etudiants e ON u.id = e.utilisateur_id 
               WHERE n.cours_id = ? AND n.enseignant_id = ?";
    
    if ($selected_class_type === 'td') {
        $sqlAll .= " AND e.groupe_td_id = ?";
    } else {
        $sqlAll .= " AND e.groupe_td_id IN (SELECT id FROM groupes_td WHERE amphi_id = ?)";
    }
    $sqlAll .= " ORDER BY u.nom ASC, n.date_saisie DESC";
    $stmtAll = $pdo->prepare($sqlAll);
    $stmtAll->execute([$selected_class_course, $enseignant_id, $selected_class_id]);
    $all_class_grades = $stmtAll->fetchAll();
}

// --- LOGIQUE POUR L'ONGLET ALERTES MOYENNE ---
$low_grades = $pdo->prepare("
    SELECT n.*, u.nom, u.prenom, c.nom_cours 
    FROM notes n 
    JOIN utilisateurs u ON n.etudiant_id = u.id 
    JOIN cours c ON n.cours_id = c.id
    WHERE n.enseignant_id = ? AND n.note_valeur < 10
    ORDER BY n.date_saisie DESC
");
$low_grades->execute([$enseignant_id]);
$low_grades = $low_grades->fetchAll();

// --- DEMANDES DE RENDEZ-VOUS REÇUES ---
$demandes_rdv = [];
try {
    $stmtRdv = $pdo->prepare("
        SELECT r.*, u.nom AS etu_nom, u.prenom AS etu_prenom, p.nom_promotion
        FROM rendez_vous r
        JOIN utilisateurs u ON r.etudiant_id = u.id
        LEFT JOIN etudiants e ON u.id = e.utilisateur_id
        LEFT JOIN promotions p ON e.promotion_id = p.id
        WHERE r.enseignant_id = ?
        ORDER BY FIELD(r.statut, 'en_attente', 'accepte', 'refuse', 'annule'), r.date_rdv ASC, r.heure_debut ASC
    ");
    $stmtRdv->execute([$enseignant_id]);
    $demandes_rdv = $stmtRdv->fetchAll();
} catch (Exception $e) {
    $demandes_rdv = [];
    $msg_status .= "<div class='alert error'>Erreur Rendez-vous : " . htmlspecialchars($e->getMessage()) . "</div>";
}

// --- MESSAGES REÇUS PAR L'ENSEIGNANT ---
$messages_recus_prof = [];
try {
    $stmtMessagesProf = $pdo->prepare("
        SELECT m.*, u.nom AS exp_nom, u.prenom AS exp_prenom, u.role AS exp_role
        FROM messages m
        LEFT JOIN utilisateurs u ON m.expediteur_id = u.id
        WHERE m.destinataire_id = ?
        ORDER BY m.date_envoi DESC
    ");
    $stmtMessagesProf->execute([$enseignant_id]);
    $messages_recus_prof = $stmtMessagesProf->fetchAll();
} catch (Exception $e) {
    $messages_recus_prof = [];
    $msg_status .= "<div class='alert error'>Erreur réception messages : " . htmlspecialchars($e->getMessage()) . "</div>";
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>SmartCampus - Espace Enseignant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bleu-ecole: #0A2240; --rouge-ecole: #D9383A; --gris-fond: #F7FAFC; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: var(--gris-fond); margin: 0; padding: 0; display: flex; }
        .sidebar { width: 260px; background: var(--bleu-ecole); color: white; height: 100vh; position: fixed; padding-top: 20px; }
        .sidebar h3 { text-align: center; color: white; margin-bottom: 30px; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { display: block; padding: 15px 20px; color: #CBD5E0; text-decoration: none; font-weight: 500; cursor: pointer; }
        .sidebar-menu li a:hover, .sidebar-menu li a.active { background: #1A365D; color: white; border-left: 4px solid var(--rouge-ecole); }
        .main-content { margin-left: 260px; padding: 40px; width: calc(100% - 260px); box-sizing: border-box; }
        .header-panel { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--bleu-ecole); padding-bottom: 15px; margin-bottom: 20px; }
        .tab-content { display: none; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 25px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #E2E8F0; }
        th { background: var(--bleu-ecole); color: white; }
        .alert { padding: 15px; margin-bottom: 15px; border-radius: 4px; font-weight: bold; }
        .alert.success { background: #C6F6D5; color: #22543D; }
        .alert.error { background: #FED7D7; color: #742A2A; }
        select, input, textarea, button { padding: 10px; width: 100%; border: 1px solid #CBD5E0; border-radius: 4px; box-sizing: border-box; margin-bottom: 15px; }
        button { background: var(--rouge-ecole); color: white; font-weight: bold; border: none; cursor: pointer; }
        button:hover { background: #B8282A; }
        .edt-navigation { display: flex; justify-content: space-between; align-items: center; background: #EDF2F7; padding: 10px 20px; border-radius: 6px; margin-bottom: 20px; }
        .btn-nav { background: var(--bleu-ecole); color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px; font-weight: bold; width: auto; margin-bottom: 0; }
        
        /* SCHEDULE GRID STYLES */
        .weekly-grid { display: grid; grid-template-columns: 60px repeat(5, minmax(120px, 1fr)); border: 1px solid #E2E8F0; border-radius: 8px; overflow: hidden; background: white; }
        .grid-header { background: var(--bleu-ecole); color: white; padding: 12px 5px; text-align: center; font-weight: bold; font-size: 0.9em; border: 1px solid #1A365D; }
        .time-slot { background: #EDF2F7; padding: 10px 5px; text-align: center; font-size: 0.85em; border: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: center; font-weight: bold; color: var(--bleu-ecole); }
        .day-slot { min-height: 100px; border: 1px solid #E2E8F0; padding: 8px; background: white; position: relative; }
        .session-item { background: #EBF8FF; border-left: 4px solid #3182CE; margin-bottom: 8px; padding: 8px; font-size: 0.8em; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .session-time { font-weight: bold; color: #2B6CB0; display: block; margin-bottom: 3px; }
        .session-item strong { color: var(--bleu-ecole); display: block; margin-bottom: 2px; }
        .session-item small { color: #4A5568; font-weight: 500; }
    </style>
</head>
<body>

<div class="sidebar">
    <h3>SmartCampus Prof</h3>
    <ul class="sidebar-menu">
        <li><a id="btn-dashboard" class="active" onclick="switchTab('dashboard')"><i class="fa-solid fa-chart-line"></i> Vue d'ensemble</a></li>
        <li><a id="btn-edt" onclick="switchTab('edt')"><i class="fa-solid fa-calendar-week"></i> Mon Emploi du Temps</a></li>
        <li><a id="btn-presence" onclick="switchTab('presence')"><i class="fa-solid fa-user-check"></i> Présences / Absences</a></li>
        <li><a id="btn-notes" onclick="switchTab('notes')"><i class="fa-solid fa-pen-to-square"></i> Saisie & Modification</a></li>
        <li><a id="btn-results" onclick="switchTab('results')"><i class="fa-solid fa-table-list"></i> Résultats de la Classe</a></li>
        <li><a id="btn-rdv" onclick="switchTab('rdv')"><i class="fa-solid fa-handshake"></i> Rendez-vous étudiants</a></li>
        <li><a id="btn-messagerie" onclick="switchTab('messagerie')"><i class="fa-solid fa-envelope"></i> Contacter un élève</a></li>
        <li><a href="deconnexion.php" style="color:#FEB2B2;"><i class="fa-solid fa-power-off"></i> Déconnexion</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="header-panel">
        <h2>Espace Enseignant</h2>
        <span>Professeur : <strong><?php echo htmlspecialchars($_SESSION['user_prenom'] ?? 'Enseignant' . ' ' . ($_SESSION['user_nom'] ?? '')); ?></strong></span>
    </div>

    <?php echo $msg_status; ?>

    <div id="tab-dashboard" class="tab-content" style="display: block;">
        <div class="card">
            <h3>Bienvenue dans votre espace académique</h3>
            <p>Utilisez le menu latéral pour piloter vos plannings de cours, attribuer les notes de contrôle continu ou envoyer des messages personnalisés.</p>
        </div>
    </div>

    <div id="tab-edt" class="tab-content">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h3 style="margin:0;"><i class="fa-solid fa-calendar-week"></i> Planning Hebdomadaire</h3>
                
                <?php
                // Préparation des filtres dynamiques basés sur les sessions de la semaine
                $filter_classes = [];
                $filter_subjects = [];
                foreach ($sessions as $s) {
                    $c_name = $s['groupe_nom'] ? $s['nom_promotion'] . " - " . $s['groupe_nom'] : "Amphi " . $s['nom_promotion'];
                    $filter_classes[$c_name] = $c_name;
                    $filter_subjects[$s['nom_cours']] = $s['nom_cours'];
                }
                asort($filter_classes);
                asort($filter_subjects);
                ?>

                <select id="teacherScheduleFilter" onchange="applyTeacherFilter()" style="width: 250px; padding: 8px; border: 1px solid #CBD5E0; border-radius: 4px; margin:0; font-size:0.9em; background:white;">
                    <option value="ALL">-- Tous mes cours --</option>
                    <optgroup label="Filtrer par Classe">
                        <?php foreach($filter_classes as $fc): ?>
                            <option value="CLASS_<?php echo htmlspecialchars($fc); ?>"><?php echo htmlspecialchars($fc); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Filtrer par Matière">
                        <?php foreach($filter_subjects as $fs): ?>
                            <option value="SUBJECT_<?php echo htmlspecialchars($fs); ?>"><?php echo htmlspecialchars($fs); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

            <div class="edt-navigation">
                <a class="btn-nav" href="?semaine=<?php echo $offset_semaine - 1; ?>"><i class="fa-solid fa-arrow-left"></i> Précédente</a>
                <span>Semaine du <strong><?php echo date('d/m/Y', $timestamp_lundi); ?></strong> au <strong><?php echo date('d/m/Y', strtotime('+4 days', $timestamp_lundi)); ?></strong></span>
                <a class="btn-nav" href="?semaine=<?php echo $offset_semaine + 1; ?>">Suivante <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <div class="weekly-grid">
                <div class="grid-header">Heures</div>
                <?php 
                $days_names = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven'];
                for($i=0; $i<5; $i++): 
                    $date_day = date('d/m', strtotime("+$i days", $timestamp_lundi));
                ?>
                    <div class="grid-header"><?php echo $days_names[$i] . ' <span style="font-weight:normal;font-size:0.85em;">' . $date_day . '</span>'; ?></div>
                <?php endfor; ?>

                <?php 
                $slots = ["08:00", "10:00", "13:00", "15:00", "17:00"];
                foreach($slots as $slot): 
                    $slot_hour = intval(substr($slot, 0, 2));
                ?>
                    <div class="time-slot"><?php echo $slot; ?></div>
                    <?php for($day_idx=0; $day_idx<5; $day_idx++): 
                        $current_date = date('Y-m-d', strtotime("+$day_idx days", $timestamp_lundi));
                    ?>
                        <div class="day-slot">
                            <?php foreach($sessions as $sess): 
                                $sess_date = $sess['date_cours'];
                                $hour = intval(substr($sess['heure_debut'], 0, 2));
                                $is_in_slot = false;
                                
                                if ($slot_hour == 8 && $hour >= 8 && $hour < 10) $is_in_slot = true;
                                elseif ($slot_hour == 10 && $hour >= 10 && $hour < 13) $is_in_slot = true;
                                elseif ($slot_hour == 13 && $hour >= 13 && $hour < 15) $is_in_slot = true;
                                elseif ($slot_hour == 15 && $hour >= 15 && $hour < 17) $is_in_slot = true;
                                elseif ($slot_hour == 17 && $hour >= 17) $is_in_slot = true;

                                if($sess_date === $current_date && $is_in_slot): 
                                    $className = $sess['groupe_nom'] ? $sess['nom_promotion'] . " - " . $sess['groupe_nom'] : "Amphi " . $sess['nom_promotion'];
                                ?>
                                <div class="session-item" 
                                     data-subject="SUBJECT_<?php echo htmlspecialchars($sess['nom_cours'] ?? ''); ?>"
                                     data-class="CLASS_<?php echo htmlspecialchars($className); ?>">
                                    <span class="session-time"><?php echo substr($sess['heure_debut'],0,5).' - '.substr($sess['heure_fin'],0,5); ?></span>
                                    <strong><?php echo htmlspecialchars($sess['nom_cours'] ?? ''); ?></strong>
                                    <div style="margin-top:2px;"><small>Classe : <strong><?php echo htmlspecialchars($className); ?></strong></small></div>
                                    <small><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($sess['nom_salle'] ?? 'Salle N/A'); ?></small>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>


    <div id="tab-presence" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-user-check"></i> Gestion des présences et absences</h3>

            <form method="GET" style="margin-bottom:20px;">
                <input type="hidden" name="active_tab" value="presence">
                <label>Choisir une séance de cours</label>
                <select name="presence_session_id" onchange="this.form.submit()" required>
                    <option value="">-- Sélectionner une séance --</option>
                    <?php foreach($presence_sessions as $s): ?>
                        <option value="<?php echo intval($s['id']); ?>" <?php echo ($selected_presence_session == $s['id']) ? 'selected' : ''; ?>>
                            <?php echo date('d/m/Y', strtotime($s['date_cours'])) . ' - ' . substr($s['heure_debut'],0,5) . ' - ' . htmlspecialchars($s['nom_cours']) . ' (' . htmlspecialchars($s['nom_salle']) . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if($selected_presence_session > 0 && $session_presence_info): ?>
                <h4>
                    <?php echo htmlspecialchars($session_presence_info['nom_cours']); ?> —
                    <?php echo date('d/m/Y', strtotime($session_presence_info['date_cours'])); ?>
                    de <?php echo substr($session_presence_info['heure_debut'],0,5); ?>
                    à <?php echo substr($session_presence_info['heure_fin'],0,5); ?>
                </h4>

                <?php if(empty($students_presence)): ?>
                    <p>Aucun étudiant n'est inscrit à ce cours pour le moment.</p>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="session_id" value="<?php echo intval($selected_presence_session); ?>">
                        <table>
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th>Présence</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($students_presence as $etu): 
                                    $statut = $etu['statut_presence'] ?? 'present';
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($etu['nom'] . ' ' . $etu['prenom']); ?></strong></td>
                                        <td>
                                            <select name="presences[<?php echo intval($etu['id']); ?>]">
                                                <option value="present" <?php echo ($statut === 'present') ? 'selected' : ''; ?>>Présent</option>
                                                <option value="absent_justifie" <?php echo ($statut === 'absent_justifie') ? 'selected' : ''; ?>>Absent justifié</option>
                                                <option value="absent_injustifie" <?php echo ($statut === 'absent_injustifie') ? 'selected' : ''; ?>>Absent injustifié</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <button type="submit" name="action_enregistrer_presences" style="margin-top:15px;">
                            <i class="fa-solid fa-check"></i> Enregistrer les présences
                        </button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p>Sélectionnez une séance pour faire l'appel.</p>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-notes" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-pen-to-square"></i> Saisie & Modification des Notes</h3>
            
            <form method="GET" style="background: #F8FAFC; padding: 15px; border-radius: 6px; border: 1px solid #E2E8F0; margin-bottom: 25px;">
                <input type="hidden" name="active_tab" value="notes">
                <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; align-items: end;">
                    <div>
                        <label>1. Choisir le Groupe</label>
                        <select name="class_info" required onchange="const parts = this.value.split('|'); window.location.href='?active_tab=notes&type_classe='+parts[0]+'&id_classe='+parts[1];">
                            <option value="">-- Sélectionner --</option>
                            <optgroup label="Amphis">
                                <?php foreach($amphis_list as $al): 
                                    $val = "amphi|".$al['id'];
                                    $sel = ($selected_class_id == $al['id'] && $selected_class_type == 'amphi') ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($al['nom_promotion'] . " - " . $al['nom']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Groupes de TD">
                                <?php foreach($list_groupes as $lg): 
                                    $val = "td|".$lg['id'];
                                    $sel = ($selected_class_id == $lg['id'] && $selected_class_type == 'td') ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($lg['nom_promotion'] . " - " . $lg['groupe_nom']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div style="padding-bottom: 15px; font-size: 0.9em; color: #666;">
                        <em>Sélectionnez un groupe pour commencer la saisie.</em>
                    </div>
                </div>
            </form>

            <?php if ($selected_class_id && !empty($students_to_grade)): ?>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                        <div>
                            <label>Cours / Matière</label>
                            <select name="cours_id" required>
                                <?php 
                                $mes_cours = $pdo->query("SELECT id, nom_cours FROM cours ORDER BY nom_cours ASC")->fetchAll();
                                foreach($mes_cours as $mc): ?>
                                    <option value="<?php echo $mc['id']; ?>"><?php echo htmlspecialchars($mc['nom_cours']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Type d'Évaluation</label>
                            <select name="type_evaluation" required>
                                <option value="CC">Contrôle Continu (CC)</option>
                                <option value="Examen">Partiel / Examen Final</option>
                            </select>
                        </div>
                        <div>
                            <label>Nom de l'Examen</label>
                            <input type="text" name="nom_examen" required placeholder="Ex: DS Janvier">
                        </div>
                        <div>
                            <label>Barème (Note sur ...)</label>
                            <input type="number" name="bareme" value="20" step="1" min="1" required style="background: #FFFBEB; border: 1px solid #F6E05E;">
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Étudiant</th>
                                <th style="width: 150px;">Note brute</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students_to_grade as $std): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($std['nom'] . " " . $std['prenom']); ?></strong></td>
                                    <td>
                                        <input type="number" name="notes[<?php echo $std['id']; ?>]" step="0.25" min="0" placeholder="-" style="margin:0; text-align:center;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="margin-top: 25px; display: flex; gap: 15px;">
                        <button type="submit" name="action_enregistrer_notes_classe" style="flex: 1; background: #38A169;">
                            <i class="fa-solid fa-floppy-disk"></i> Enregistrer les notes
                        </button>
                    </div>
                </form>

                <div style="margin-top: 40px; border-top: 2px solid #E2E8F0; padding-top: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h4 style="margin:0;">Modifier des notes existantes</h4>
                        <?php if ($selected_manage_exam && !empty($grades_to_manage)): 
                            $is_exam_locked = true;
                            foreach($grades_to_manage as $gtm) { if($gtm['statut_verrouillage'] !== 'valide_definitif') { $is_exam_locked = false; break; } }
                        ?>
                            <?php if (!$is_exam_locked): ?>
                                <form method="POST" onsubmit="return confirm('Attention : La validation est définitive. Vous ne pourrez plus modifier ces notes.');" style="margin:0;">
                                    <input type="hidden" name="cours_id" value="<?php echo $grades_to_manage[0]['cours_id']; ?>">
                                    <input type="hidden" name="nom_examen" value="<?php echo htmlspecialchars($selected_manage_exam); ?>">
                                    <button type="submit" name="action_verrouiller_notes" style="background: #2D3748; padding: 8px 15px; font-size: 0.9em; width: auto; margin:0;">
                                        <i class="fa-solid fa-lock"></i> Valider définitivement l'épreuve
                                    </button>
                                </form>
                            <?php else: ?>
                                <span style="color: #38A169; font-weight: bold; font-size: 0.9em;"><i class="fa-solid fa-check-double"></i> Épreuve Validée</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <form method="GET" style="display: flex; gap: 10px; align-items: end; margin-bottom: 20px;">
                        <input type="hidden" name="active_tab" value="notes">
                        <input type="hidden" name="type_classe" value="<?php echo $selected_class_type; ?>">
                        <input type="hidden" name="id_classe" value="<?php echo $selected_class_id; ?>">
                        <div style="flex:1;">
                            <label>Choisir l'épreuve à charger</label>
                            <select name="manage_exam" required>
                                <option value="">-- Sélectionner --</option>
                                <?php foreach($my_exams as $exam): ?>
                                    <option value="<?php echo htmlspecialchars($exam); ?>" <?php echo ($selected_manage_exam == $exam) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($exam); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" style="width: auto; height: 42px; margin:0;">Charger</button>
                    </form>

                    <?php if ($selected_manage_exam && !empty($grades_to_manage)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Étudiant</th>
                                    <th style="width: 150px;">Note</th>
                                    <th style="width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($grades_to_manage as $g): 
                                    $is_locked = ($g['statut_verrouillage'] === 'valide_definitif');
                                ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($g['nom'] . " " . $g['prenom']); ?>
                                            <?php if ($is_locked): ?>
                                                <small style="color:#38A169; margin-left:10px;">(Validée)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="margin:0; display:flex; gap:5px; align-items:center;">
                                                <input type="hidden" name="note_id" value="<?php echo $g['id']; ?>">
                                                <input type="number" name="note_valeur" value="<?php echo $g['note_valeur']; ?>" 
                                                       step="0.25" min="0" <?php echo $is_locked ? 'disabled' : ''; ?>
                                                       style="margin:0; width:70px; text-align:center; <?php echo $is_locked ? 'background:#F7FAFC; color:#A0AEC0;' : ''; ?>">
                                                <span style="font-size:0.8em; color:#718096;">sur</span>
                                                <input type="number" name="bareme_modif" value="20" step="1" min="1" <?php echo $is_locked ? 'disabled' : ''; ?>
                                                       style="margin:0; width:50px; padding:5px; font-size:0.85em; <?php echo $is_locked ? 'background:#F7FAFC;' : 'background:#FFFBEB;'; ?>">
                                                <?php if (!$is_locked): ?>
                                                    <button type="submit" name="action_modifier_note" style="margin:0; background:#3182CE; padding:5px 10px;">
                                                        <i class="fa-solid fa-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <input type="hidden" name="active_tab_custom" value="notes">
                                            </form>
                                        </td>
                                        <td>
                                            <?php if (!$is_locked): ?>
                                                <form method="POST" onsubmit="return confirm('Supprimer ?');" style="margin:0;">
                                                    <input type="hidden" name="note_id" value="<?php echo $g['id']; ?>">
                                                    <button type="submit" name="action_supprimer_note" style="margin:0; background:#E53E3E; padding:5px 10px;">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                    <input type="hidden" name="active_tab_custom" value="notes">
                                                </form>
                                            <?php else: ?>
                                                <span style="color:#CBD5E0;"><i class="fa-solid fa-lock"></i></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-results" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-table-list"></i> Résultats Complets de la Classe</h3>
            
            <form method="GET" style="background: #F8FAFC; padding: 15px; border-radius: 6px; border: 1px solid #E2E8F0; margin-bottom: 25px;">
                <input type="hidden" name="active_tab" value="results">
                <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; align-items: end;">
                    <div>
                        <label>1. Groupe</label>
                        <select name="class_info" required onchange="const parts = this.value.split('|'); window.location.href='?active_tab=results&type_classe='+parts[0]+'&id_classe='+parts[1];">
                            <option value="">-- Sélectionner --</option>
                            <optgroup label="Amphis">
                                <?php foreach($amphis_list as $al): 
                                    $val = "amphi|".$al['id'];
                                    $sel = ($selected_class_id == $al['id'] && $selected_class_type == 'amphi') ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($al['nom_promotion'] . " - " . $al['nom']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Groupes de TD">
                                <?php foreach($list_groupes as $lg): 
                                    $val = "td|".$lg['id'];
                                    $sel = ($selected_class_id == $lg['id'] && $selected_class_type == 'td') ? 'selected' : '';
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($lg['nom_promotion'] . " - " . $lg['groupe_nom']); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <input type="hidden" name="id_classe" value="<?php echo $selected_class_id; ?>">
                        <input type="hidden" name="type_classe" value="<?php echo $selected_class_type; ?>">
                    </div>
                    <div>
                        <label>2. Matière</label>
                        <select name="class_course_id" required>
                            <option value="">-- Sélectionner --</option>
                            <?php foreach($mes_cours as $mc): ?>
                                <option value="<?php echo $mc['id']; ?>" <?php echo ($selected_class_course == $mc['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mc['nom_cours']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" style="margin:0; width: auto; padding: 10px 25px;">Visualiser</button>
                </div>
            </form>

            <?php if ($selected_class_course && $selected_class_id): ?>
                <div style="margin-top: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h4 style="margin:0;">Toutes les notes et moyennes</h4>
                        <form method="POST" onsubmit="return confirm('Voulez-vous valider et verrouiller TOUTES les notes de ce cours pour cette classe ?');" style="margin:0;">
                            <input type="hidden" name="cours_id" value="<?php echo $selected_class_course; ?>">
                            <input type="hidden" name="type_classe" value="<?php echo $selected_class_type; ?>">
                            <input type="hidden" name="id_classe" value="<?php echo $selected_class_id; ?>">
                            <button type="submit" name="action_verrouiller_moyennes_classe" style="background: #2D3748; padding: 10px 20px; font-size: 0.9em; width: auto; margin:0;">
                                <i class="fa-solid fa-check-double"></i> Valider tous les résultats de la classe
                            </button>
                        </form>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th>Étudiant</th>
                                <th>Détail des Notes (Toutes sans exception)</th>
                                <th style="width: 150px; text-align:center; background:#EDF2F7;">Moyenne CC</th>
                                <th style="width: 150px; text-align:center; background:#EDF2F7;">Moyenne Examen</th>
                                <th style="width: 150px; text-align:center; background:var(--bleu-ecole); color:white;">Moyenne Générale</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Récupérer tous les étudiants du groupe
                            foreach ($students_to_grade as $std): 
                                // Récupérer TOUTES les notes de cet étudiant pour ce cours
                                $stmtN = $pdo->prepare("SELECT note_valeur, type_evaluation, nom_examen FROM notes WHERE etudiant_id = ? AND cours_id = ? AND enseignant_id = ? ORDER BY date_saisie ASC");
                                $stmtN->execute([$std['id'], $selected_class_course, $enseignant_id]);
                                $std_grades = $stmtN->fetchAll();
                                
                                $cc_sum = 0; $cc_count = 0;
                                $ex_sum = 0; $ex_count = 0;
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($std['nom'] . " " . $std['prenom']); ?></strong></td>
                                    <td>
                                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                            <?php if (empty($std_grades)): ?>
                                                <span style="color: #A0AEC0; font-style: italic;">Aucune note</span>
                                            <?php else: ?>
                                                <?php foreach($std_grades as $sg): 
                                                    $is_cc = ($sg['type_evaluation'] === 'CC');
                                                    if ($is_cc) { $cc_sum += $sg['note_valeur']; $cc_count++; }
                                                    else { $ex_sum += $sg['note_valeur']; $ex_count++; }
                                                    $color = $sg['note_valeur'] < 10 ? '#D9383A' : '#2B6CB0';
                                                ?>
                                                    <div style="background: #F7FAFC; border: 1px solid #E2E8F0; padding: 5px 10px; border-radius: 4px; font-size: 0.85em;">
                                                        <span style="font-weight: bold; color: <?php echo $color; ?>;"><?php echo number_format($sg['note_valeur'], 2); ?></span>
                                                        <br><small><?php echo htmlspecialchars($sg['nom_examen'] ?? $sg['type_evaluation']); ?></small>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="text-align:center; font-weight:bold;">
                                        <?php 
                                            $moy_cc = ($cc_count > 0) ? ($cc_sum / $cc_count) : null;
                                            echo ($moy_cc !== null) ? number_format($moy_cc, 2) : '-'; 
                                        ?>
                                    </td>
                                    <td style="text-align:center; font-weight:bold;">
                                        <?php 
                                            $moy_ex = ($ex_count > 0) ? ($ex_sum / $ex_count) : null;
                                            echo ($moy_ex !== null) ? number_format($moy_ex, 2) : '-'; 
                                        ?>
                                    </td>
                                    <td style="text-align:center; font-weight:bold; background:#F8FAFC; color:var(--bleu-ecole);">
                                        <?php 
                                            if ($moy_cc !== null && $moy_ex !== null) {
                                                echo number_format(($moy_cc * 0.4) + ($moy_ex * 0.6), 2);
                                            } elseif ($moy_cc !== null) {
                                                echo number_format($moy_cc, 2);
                                            } elseif ($moy_ex !== null) {
                                                echo number_format($moy_ex, 2);
                                            } else {
                                                echo '-';
                                            }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-rdv" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-handshake"></i> Demandes de rendez-vous des étudiants</h3>
            <?php if(empty($demandes_rdv)): ?>
                <p>Aucune demande de rendez-vous reçue.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Étudiant</th>
                            <th>Date demandée</th>
                            <th>Sujet</th>
                            <th>Statut</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($demandes_rdv as $r): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['etu_nom'] . ' ' . $r['etu_prenom']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($r['nom_promotion'] ?? 'Promotion non définie'); ?></small>
                                </td>
                                <td>
                                    <?php echo date('d/m/Y', strtotime($r['date_rdv'])); ?><br>
                                    <small><?php echo substr($r['heure_debut'],0,5) . ' - ' . substr($r['heure_fin'],0,5); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($r['sujet']); ?></strong>
                                    <?php if(!empty($r['message'])): ?><br><small><?php echo htmlspecialchars($r['message']); ?></small><?php endif; ?>
                                    <?php if(!empty($r['reponse_enseignant'])): ?><br><em>Réponse : <?php echo htmlspecialchars($r['reponse_enseignant']); ?></em><?php endif; ?>
                                </td>
                                <td><span class="badge <?php echo htmlspecialchars($r['statut']); ?>"><?php echo htmlspecialchars(str_replace('_', ' ', $r['statut'])); ?></span></td>
                                <td>
                                    <?php if($r['statut'] === 'en_attente'): ?>
                                        <form method="POST" style="margin-bottom:8px;">
                                            <input type="hidden" name="rdv_id" value="<?php echo intval($r['id']); ?>">
                                            <textarea name="reponse_enseignant" rows="2" placeholder="Réponse facultative..." style="margin-bottom:6px;"></textarea>
                                            <div style="display:flex; gap:6px;">
                                                <button type="submit" name="action_accepter_rdv" style="background:#38A169;">Accepter</button>
                                                <button type="submit" name="action_refuser_rdv" style="background:#E53E3E;">Refuser</button>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-messagerie" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-inbox"></i> Messages reçus</h3>
            <?php if(empty($messages_recus_prof)): ?>
                <p>Aucun message reçu pour le moment.</p>
            <?php else: ?>
                <?php foreach($messages_recus_prof as $m): ?>
                    <div style="background:#F7FAFC; padding:12px; margin-bottom:10px; border-left:4px solid #0A2240; border-radius:4px; overflow:hidden;">
                        <strong>De : <?php echo htmlspecialchars(trim(($m['exp_prenom'] ?? '') . ' ' . ($m['exp_nom'] ?? 'Utilisateur supprimé'))); ?></strong>
                        <small style="float:right; color:#718096;"><?php echo htmlspecialchars($m['date_envoi']); ?></small>
                        <p style="margin:8px 0 0 0;"><?php echo nl2br(htmlspecialchars($m['contenu'])); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <h3><i class="fa-solid fa-envelope"></i> Contacter un étudiant</h3>
            <form method="POST">
                <label>Étudiant Destinataire</label>
                <select name="destinataire_id" required>
                    <?php foreach($les_eleves as $le): ?>
                        <option value="<?php echo $le['id']; ?>"><?php echo htmlspecialchars($le['nom'] . ' ' . $le['prenom']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Message</label>
                <textarea name="contenu_message" rows="6" required placeholder="Votre message..."></textarea>

                <button type="submit" name="action_envoyer_mail"><i class="fa-solid fa-paper-plane"></i> Envoyer le Message</button>
            </form>
        </div>
    </div>
</div>

<script>
    function switchTab(name) {
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        document.querySelectorAll('.sidebar-menu li a').forEach(b => b.classList.remove('active'));
        if(document.getElementById('tab-' + name)) document.getElementById('tab-' + name).style.display = 'block';
        if(document.getElementById('btn-' + name)) document.getElementById('btn-' + name).classList.add('active');
    }
    <?php if($active_tab !== 'dashboard'): ?>
        switchTab('<?php echo $active_tab; ?>');
    <?php endif; ?>

    function applyTeacherFilter() {
        let filterVal = document.getElementById("teacherScheduleFilter").value;
        let items = document.querySelectorAll(".session-item");

        items.forEach(item => {
            if (filterVal === "ALL") {
                item.style.display = "block";
            } else {
                let matchSubject = item.getAttribute("data-subject") === filterVal;
                let matchClass = item.getAttribute("data-class") === filterVal;
                
                if (matchSubject || matchClass) {
                    item.style.display = "block";
                } else {
                    item.style.display = "none";
                }
            }
        });
    }
</script>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(document).ready(function() {
    setTimeout(function() {
        $(".alert.success, .alert.error, .alert.danger").fadeOut(800);
    }, 4000);
});
</script>
</body>
</html>
