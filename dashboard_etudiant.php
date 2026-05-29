<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// --- SÉCURITÉ & VÉRIFICATION DES ACCÈS ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'etudiant') {
    header('Location: login.php');
    exit();
}
$etudiant_id = $_SESSION['user_id'];

// --- AUTO-MIGRATION : AJOUT COLONNE COEFFICIENT SI MANQUANTE ---
try {
    $pdo->exec("ALTER TABLE cours ADD COLUMN coefficient FLOAT DEFAULT 1.0 AFTER nom_cours");
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
} catch (Exception $e) {
    $msg_status = "<div class='alert danger'>⚠️ Erreur création table RDV : " . htmlspecialchars($e->getMessage()) . "</div>";
}

$msg_status = "";
$active_tab_after_post = "dashboard"; 

// --- TRAITEMENT DES SOUMISSIONS FORMULAIRES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Enregistrement de présence via QR Code
    if (isset($_POST['action_valider_presence'])) {
        $session_cible = intval($_POST['session_valide_id']);
        $active_tab_after_post = "presence";
        try {
            $stmtCheck = $pdo->prepare("SELECT id FROM presences WHERE session_cours_id = ? AND etudiant_id = ?");
            $stmtCheck->execute([$session_cible, $etudiant_id]);
            if ($stmtCheck->fetch()) {
                $pdo->prepare("UPDATE presences SET statut_presence = 'present' WHERE session_cours_id = ? AND etudiant_id = ?")->execute([$session_cible, $etudiant_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO presences (session_cours_id, etudiant_id, statut_presence) VALUES (?, ?, 'present')");
                $stmt->execute([$session_cible, $etudiant_id]);
            }
            $msg_status = "<div class='alert success'>✅ Présence enregistrée avec succès par QR Code !</div>";
        } catch (Exception $e) {
            $msg_status = "<div class='alert danger'>❌ Erreur lors du badgeage : " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }

    // Envoi de message
    if (isset($_POST['action_envoyer_message'])) {
        $active_tab_after_post = "messagerie";
        $dest_id = intval($_POST['destinataire_id']);
        $contenu = trim($_POST['contenu_message']);
        
        if (!empty($contenu)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO messages (expediteur_id, destinataire_id, contenu, date_envoi) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$etudiant_id, $dest_id, $contenu]);
                $msg_status = "<div class='alert success'>✉️ Message envoyé avec succès !</div>";
            } catch (Exception $e) {
                $msg_status = "<div class='alert danger'>❌ Erreur envoi : " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
}

    // Demande de rendez-vous avec un enseignant
    if (isset($_POST['action_demander_rdv'])) {
        $active_tab_after_post = "rdv";
        $enseignant_rdv_id = intval($_POST['enseignant_id']);
        $sujet = trim($_POST['sujet_rdv']);
        $message_rdv = trim($_POST['message_rdv']);
        $date_rdv = $_POST['date_rdv'];
        $heure_debut = $_POST['heure_debut'];
        $heure_fin = $_POST['heure_fin'];

        if ($enseignant_rdv_id <= 0 || empty($sujet) || empty($date_rdv) || empty($heure_debut) || empty($heure_fin)) {
            $msg_status = "<div class='alert danger'>❌ Merci de remplir tous les champs obligatoires du rendez-vous.</div>";
        } elseif ($heure_fin <= $heure_debut) {
            $msg_status = "<div class='alert danger'>❌ L'heure de fin doit être après l'heure de début.</div>";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO rendez_vous (etudiant_id, enseignant_id, sujet, message, date_rdv, heure_debut, heure_fin, statut) VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente')");
                $stmt->execute([$etudiant_id, $enseignant_rdv_id, $sujet, $message_rdv, $date_rdv, $heure_debut, $heure_fin]);
                $msg_status = "<div class='alert success'>✅ Demande de rendez-vous envoyée au professeur.</div>";
            } catch (Exception $e) {
                $msg_status = "<div class='alert danger'>❌ Erreur demande RDV : " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }


// =====================================================================
// CHARGEMENT DES DONNÉES EN STRIPTE CONFORMITÉ AVEC LE SQL
// =====================================================================

$profil = ['nom' => 'Étudiant', 'prenom' => '', 'nom_promotion' => 'Non définie', 'annee_academique' => '-', 'statut' => '-'];
$absences = ['absences_injustifiees' => 0, 'absences_justifiees' => 0];
$my_sessions = [];
$bulletin = [];
$all_profs = [];
$boite_recus = [];
$mes_rdv = [];

// 1. Profil de l'étudiant
try {
    $req_profil = $pdo->prepare("
        SELECT u.nom AS student_nom, u.prenom AS student_prenom, p.nom_promotion, p.annee_academique, e.statut_parcours
        FROM utilisateurs u
        INNER JOIN etudiants e ON u.id = e.utilisateur_id
        INNER JOIN promotions p ON e.promotion_id = p.id
        WHERE u.id = ?
    ");
    $req_profil->execute([$etudiant_id]);
    $res_profil = $req_profil->fetch();
    if ($res_profil) {
        // Adaptation pour l'affichage HTML
        $profil = [
            'nom' => $res_profil['student_nom'],
            'prenom' => $res_profil['student_prenom'],
            'nom_promotion' => $res_profil['nom_promotion'],
            'annee_academique' => $res_profil['annee_academique'],
            'statut' => $res_profil['statut_parcours']
        ];
    }
} catch (Exception $e) {
    $msg_status .= "<div class='alert danger'>⚠️ Erreur Profil : " . htmlspecialchars($e->getMessage()) . "</div>";
}

// 2. Compteur d'absences
try {
    $req_absences = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN statut_presence = 'absent_injustifie' THEN 1 END) AS absences_injustifiees,
            COUNT(CASE WHEN statut_presence = 'absent_justifie' THEN 1 END) AS absences_justifiees
        FROM presences
        WHERE etudiant_id = ?
    ");
    $req_absences->execute([$etudiant_id]);
    $res_abs = $req_absences->fetch();
    if ($res_abs) {
        $absences = $res_abs;
    }
} catch (Exception $e) {
    $msg_status .= "<div class='alert danger'>⚠️ Erreur Absences : " . htmlspecialchars($e->getMessage()) . "</div>";
}

// NAVIGATION DE L'EMPLOI DU TEMPS (Semaine)
$offset_semaine = isset($_GET['semaine']) ? intval($_GET['semaine']) : 0;
if (isset($_GET['semaine'])) { $active_tab_after_post = "edt"; }

$lundi_courant = strtotime('monday this week');
$timestamp_lundi = strtotime($offset_semaine . " weeks", $lundi_courant);
$date_debut_semaine = date('Y-m-d', $timestamp_lundi);
$date_fin_semaine = date('Y-m-d', strtotime('+6 days', $timestamp_lundi));

// 3. Emploi du temps Hebdomadaire
try {
    // On utilise inscriptions_cours comme base (remplie automatiquement via config.php)
    // Et on filtre strictement par groupe de TD ou Amphi
    $stmtEdt = $pdo->prepare("
        SELECT s.*, c.nom_cours, sl.nom_salle, u.nom AS prof_nom, u.prenom AS prof_prenom
        FROM sessions_cours s
        JOIN cours c ON s.cours_id = c.id
        JOIN salles sl ON s.salle_id = sl.id
        JOIN utilisateurs u ON s.enseignant_id = u.id
        JOIN inscriptions_cours ic ON c.id = ic.cours_id
        JOIN etudiants e ON ic.etudiant_id = e.utilisateur_id
        WHERE ic.etudiant_id = ? 
          AND s.date_cours BETWEEN ? AND ?
          AND (s.groupe_td_id IS NULL OR s.groupe_td_id = e.groupe_td_id)
        ORDER BY s.date_cours ASC, s.heure_debut ASC
    ");
    $stmtEdt->execute([$etudiant_id, $date_debut_semaine, $date_fin_semaine]);
    $sessions_semaine = $stmtEdt->fetchAll();
} catch (Exception $e) {
    $msg_status .= "<div class='alert danger'>⚠️ Erreur Emploi du temps : " . htmlspecialchars($e->getMessage()) . "</div>";
    $sessions_semaine = [];
}

// 4. Liste des notes détaillée et moyennes
try {
    // On affiche tous les cours où l'étudiant est inscrit (auto via config.php) OR a une note
    $stmtCourses = $pdo->prepare("
        SELECT DISTINCT c.id, c.nom_cours, c.coefficient, ue.code_ue 
        FROM cours c
        JOIN unites_enseignement ue ON c.ue_id = ue.id 
        LEFT JOIN inscriptions_cours ic ON c.id = ic.cours_id AND ic.etudiant_id = ?
        LEFT JOIN notes n ON c.id = n.cours_id AND n.etudiant_id = ?
        WHERE ic.etudiant_id IS NOT NULL OR n.id IS NOT NULL
        ORDER BY ue.code_ue ASC, c.nom_cours ASC
    ");
    $stmtCourses->execute([$etudiant_id, $etudiant_id]);
    $my_courses = $stmtCourses->fetchAll();

    $bulletin_detail = [];
    foreach ($my_courses as $course) {
        $course_id = $course['id'];
        
        // Notes CC
        $stmtCC = $pdo->prepare("SELECT note_valeur, nom_examen FROM notes WHERE etudiant_id = ? AND cours_id = ? AND type_evaluation = 'CC'");
        $stmtCC->execute([$etudiant_id, $course_id]);
        $notes_cc = $stmtCC->fetchAll();
        
        // Notes Examen
        $stmtEx = $pdo->prepare("SELECT note_valeur, nom_examen FROM notes WHERE etudiant_id = ? AND cours_id = ? AND type_evaluation = 'Examen'");
        $stmtEx->execute([$etudiant_id, $course_id]);
        $notes_ex = $stmtEx->fetchAll();
        
        // Calcul moyennes
        $moy_cc = null;
        if (count($notes_cc) > 0) {
            $sum = 0; foreach($notes_cc as $n) $sum += $n['note_valeur'];
            $moy_cc = $sum / count($notes_cc);
        }
        
        $moy_ex = null;
        if (count($notes_ex) > 0) {
            $sum = 0; foreach($notes_ex as $n) $sum += $n['note_valeur'];
            $moy_ex = $sum / count($notes_ex);
        }
        
        $moy_gen = null;
        if ($moy_cc !== null && $moy_ex !== null) {
            $moy_gen = ($moy_cc * 0.4) + ($moy_ex * 0.6);
        } elseif ($moy_cc !== null) {
            $moy_gen = $moy_cc;
        } elseif ($moy_ex !== null) {
            $moy_gen = $moy_ex;
        }
        
        $bulletin_detail[] = [
            'code_ue' => $course['code_ue'],
            'nom_cours' => $course['nom_cours'],
            'coefficient' => $course['coefficient'],
            'notes_cc' => $notes_cc,
            'notes_ex' => $notes_ex,
            'moy_cc' => $moy_cc,
            'moy_ex' => $moy_ex,
            'moy_gen' => $moy_gen
        ];
    }
} catch (Exception $e) {
    $msg_status .= "<div class='alert danger'>⚠️ Erreur Notes : " . htmlspecialchars($e->getMessage()) . "</div>";
}

// 5. Liste des enseignants pour la messagerie
try {
    $all_profs = $pdo->query("SELECT id, nom, prenom FROM utilisateurs WHERE role = 'enseignant'")->fetchAll();
} catch (Exception $e) {}

// 6. Boîte de réception des messages
try {
    $messages_recus = $pdo->prepare("
        SELECT m.*, u.nom AS exp_nom, u.prenom AS exp_prenom 
        FROM messages m 
        INNER JOIN utilisateurs u ON m.expediteur_id = u.id 
        WHERE m.destinataire_id = ? 
        ORDER BY m.date_envoi DESC
    ");
    $messages_recus->execute([$etudiant_id]);
    $boite_recus = $messages_recus->fetchAll();
} catch (Exception $e) {
    $msg_status .= "<div class='alert danger'>⚠️ Erreur Messagerie : " . htmlspecialchars($e->getMessage()) . "</div>";
}
// 7. Rendez-vous demandés par l'étudiant
try {
    $stmtRdv = $pdo->prepare("
        SELECT r.*, u.nom AS prof_nom, u.prenom AS prof_prenom
        FROM rendez_vous r
        JOIN utilisateurs u ON r.enseignant_id = u.id
        WHERE r.etudiant_id = ?
        ORDER BY r.date_rdv DESC, r.heure_debut DESC
    ");
    $stmtRdv->execute([$etudiant_id]);
    $mes_rdv = $stmtRdv->fetchAll();
} catch (Exception $e) {
    $msg_status .= "<div class='alert danger'>⚠️ Erreur Rendez-vous : " . htmlspecialchars($e->getMessage()) . "</div>";
}   
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>SmartCampus - Espace Étudiant</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f4f6f9; margin:0; display:flex; color:#333; }
        .sidebar { width: 260px; background: #0A2240; color: white; min-height: 100vh; padding: 20px 0; }
        .sidebar-brand { text-align: center; font-size: 22px; font-weight: bold; margin-bottom: 30px; border-bottom: 1px solid #1A365D; padding-bottom: 15px; }
        .sidebar-brand span { color: #D9383A; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { display: block; padding: 15px 25px; color: #A0AEC0; text-decoration: none; font-weight: 500; transition: 0.3s; }
        .sidebar-menu li a:hover, .sidebar-menu li a.active { background: #1A365D; color: white; border-left: 4px solid #D9383A; }
        .main-content { flex: 1; padding: 30px; box-sizing: border-box; width: calc(100% - 260px); }
        .card { background: white; border-radius: 8px; padding: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; }
        .stat-box { padding: 20px; border-radius: 6px; text-align: center; color: white; }
        .stat-box.danger { background: #E53E3E; }
        .stat-box.success { background: #38A169; }
        .tab-content { display: none; }
        table { width:100%; border-collapse:collapse; margin-top:15px; }
        th, td { padding:12px; border-bottom:1px solid #E2E8F0; text-align:left; }
        th { background:#F7FAFC; }
        .alert { padding:15px; border-radius:6px; margin-bottom:20px; font-weight:bold; }
        .alert.success { background:#C6F6D5; color:#22543D; }
        .alert.danger { background:#FED7D7; color:#742A2A; }
        input, select, textarea, button { width:100%; padding:10px; margin-top:8px; margin-bottom:15px; border:1px solid #CBD5E0; border-radius:4px; box-sizing:border-box; }
        button { background:#0A2240; color:white; font-weight:bold; cursor:pointer; border:none; }
        button:hover { background:#1A365D; }
        .badge { display:inline-block; padding:4px 8px; border-radius:12px; font-size:0.8em; font-weight:bold; }
        .badge.en_attente { background:#FEFCBF; color:#744210; }
        .badge.accepte { background:#C6F6D5; color:#22543D; }
        .badge.refuse { background:#FED7D7; color:#742A2A; }
        .badge.annule { background:#E2E8F0; color:#2D3748; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">Smart<span>Campus</span></div>
    <ul class="sidebar-menu">
        <li><a href="#" id="btn-dashboard" class="active" onclick="switchTab('dashboard')"><i class="fa-solid fa-chart-line"></i> Vue d'ensemble</a></li>
        <li><a href="#" id="btn-edt" onclick="switchTab('edt')"><i class="fa-solid fa-calendar-week"></i> Mon Emploi du Temps</a></li>
        <li><a href="#" id="btn-notes" onclick="switchTab('notes')"><i class="fa-solid fa-graduation-cap"></i> Mes Notes & Résultats</a></li>
        <li><a href="#" id="btn-rdv" onclick="switchTab('rdv')"><i class="fa-solid fa-calendar-plus"></i> Mes Rendez-vous</a></li>
        <li><a href="#" id="btn-messagerie" onclick="switchTab('messagerie')"><i class="fa-solid fa-envelope"></i> Messagerie</a></li>
        <li><a href="deconnexion.php" style="color:#FEB2B2;"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a></li>
    </ul>
</div>

<div class="main-content">
    
    <div class="card">
        <h2>Bienvenue, <?php echo htmlspecialchars($profil['prenom'] . ' ' . $profil['nom']); ?> 👋</h2>
        <p><i class="fa-solid fa-graduation-cap"></i> <strong>Filière :</strong> <?php echo htmlspecialchars($profil['nom_promotion']); ?> | <i class="fa-solid fa-user-check"></i> <strong>Statut :</strong> En formation <?php echo htmlspecialchars($profil['statut']); ?></p>
    </div>

    <?php echo $msg_status; ?>

    <div id="tab-dashboard" class="tab-content" style="display:block;">
        <div class="grid">
            <div class="stat-box danger">
                <h3><?php echo $absences['absences_injustifiees']; ?></h3>
                <p>Absence(s) Injustifiée(s)</p>
            </div>
            <div class="stat-box success">
                <h3><?php echo $absences['absences_justifiees']; ?></h3>
                <p>Absence(s) Justifiée(s)</p>
            </div>
        </div>
        
        <div class="card" style="margin-top:25px;">
            <h3><i class="fa-solid fa-calendar-day"></i> Mes cours d'aujourd'hui (<?php echo date('d/m/Y'); ?>)</h3>
            <?php 
            $today_sessions = array_filter($sessions_semaine, function($s) { return $s['date_cours'] === date('Y-m-d'); });
            if(empty($today_sessions)): ?>
                <p>Aucun cours programmé aujourd'hui.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Horaire</th><th>Cours</th><th>Salle</th><th>Enseignant</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($today_sessions as $s): ?>
                            <tr>
                                <td><strong><?php echo substr($s['heure_debut'],0,5); ?> - <?php echo substr($s['heure_fin'],0,5); ?></strong></td>
                                <td><?php echo htmlspecialchars($s['nom_cours']); ?></td>
                                <td>📍 <?php echo htmlspecialchars($s['nom_salle']); ?></td>
                                <td>M. <?php echo htmlspecialchars($s['prof_nom'] . ' ' . $s['prof_prenom']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-edt" class="tab-content">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h3 style="margin:0;"><i class="fa-solid fa-calendar-week"></i> Mon Planning Hebdomadaire</h3>
                
                <select id="studentScheduleFilter" onchange="applyStudentFilter()" style="width: 250px; padding: 8px; border: 1px solid #CBD5E0; border-radius: 4px; margin:0; font-size:0.9em; background:white;">
                    <option value="ALL">-- Tous mes cours --</option>
                    <?php 
                    $unique_subjects = array_unique(array_column($sessions_semaine, 'nom_cours'));
                    asort($unique_subjects);
                    foreach($unique_subjects as $fs): ?>
                        <option value="SUBJECT_<?php echo htmlspecialchars($fs); ?>"><?php echo htmlspecialchars($fs); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; background: #EDF2F7; padding: 10px 20px; border-radius: 6px; margin-bottom: 20px;">
                <a style="background: #0A2240; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px; font-weight: bold;" href="?semaine=<?php echo $offset_semaine - 1; ?>"><i class="fa-solid fa-arrow-left"></i> Précédente</a>
                <span>Semaine du <strong><?php echo date('d/m/Y', $timestamp_lundi); ?></strong> au <strong><?php echo date('d/m/Y', strtotime('+4 days', $timestamp_lundi)); ?></strong></span>
                <a style="background: #0A2240; color: white; padding: 8px 15px; text-decoration: none; border-radius: 4px; font-weight: bold;" href="?semaine=<?php echo $offset_semaine + 1; ?>">Suivante <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <style>
                .weekly-grid { display: grid; grid-template-columns: 60px repeat(5, minmax(120px, 1fr)); border: 1px solid #E2E8F0; border-radius: 8px; overflow: hidden; background: white; }
                .grid-header { background: #0A2240; color: white; padding: 12px 5px; text-align: center; font-weight: bold; font-size: 0.9em; border: 1px solid #1A365D; }
                .time-slot { background: #EDF2F7; padding: 10px 5px; text-align: center; font-size: 0.85em; border: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #0A2240; }
                .day-slot { min-height: 100px; border: 1px solid #E2E8F0; padding: 8px; background: white; position: relative; }
                .session-item { background: #EBF8FF; border-left: 4px solid #3182CE; margin-bottom: 8px; padding: 8px; font-size: 0.8em; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
                .session-time { font-weight: bold; color: #2B6CB0; display: block; margin-bottom: 3px; }
                .session-item strong { color: #0A2240; display: block; margin-bottom: 2px; }
            </style>

            <div class="weekly-grid">
                <div class="grid-header">Heures</div>
                <?php 
                $days_names = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven'];
                for($i=0; $i<5; $i++): 
                    $date_day = date('d/m', strtotime("+$i days", $timestamp_lundi));
                ?>
                    <div class="grid-header"><?php echo $days_names[$i] . ' <br><small>' . $date_day . '</small>'; ?></div>
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
                            <?php foreach($sessions_semaine as $sess): 
                                $hour = intval(substr($sess['heure_debut'], 0, 2));
                                $is_in_slot = false;
                                if ($slot_hour == 8 && $hour >= 8 && $hour < 10) $is_in_slot = true;
                                elseif ($slot_hour == 10 && $hour >= 10 && $hour < 13) $is_in_slot = true;
                                elseif ($slot_hour == 13 && $hour >= 13 && $hour < 15) $is_in_slot = true;
                                elseif ($slot_hour == 15 && $hour >= 15 && $hour < 17) $is_in_slot = true;
                                elseif ($slot_hour == 17 && $hour >= 17) $is_in_slot = true;

                                if($sess['date_cours'] === $current_date && $is_in_slot): 
                                ?>
                                <div class="session-item" data-subject="SUBJECT_<?php echo htmlspecialchars($sess['nom_cours']); ?>">
                                    <span class="session-time"><?php echo substr($sess['heure_debut'],0,5).' - '.substr($sess['heure_fin'],0,5); ?></span>
                                    <strong><?php echo htmlspecialchars($sess['nom_cours']); ?></strong>
                                    <small>📍 <?php echo htmlspecialchars($sess['nom_salle']); ?></small><br>
                                    <small>M. <?php echo htmlspecialchars($sess['prof_nom']); ?></small>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    <?php endfor; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div id="tab-notes" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-graduation-cap"></i> Mes Notes & Résultats Détaillés</h3>
            <?php if(empty($bulletin_detail)): ?>
                <p>Aucun résultat disponible pour le moment.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Matière</th>
                            <th>Notes Détailées (Toutes)</th>
                            <th style="text-align:center; background:#EDF2F7;">Moyenne CC</th>
                            <th style="text-align:center; background:#EDF2F7;">Moyenne Examen</th>
                            <th style="text-align:center; background:#0A2240; color:white;">Moyenne Générale</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($bulletin_detail as $res): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($res['nom_cours']); ?></strong><br>
                                    <small style="color:#718096;">UE: <?php echo htmlspecialchars($res['code_ue']); ?> | Coeff: <?php echo $res['coefficient']; ?></small>
                                </td>
                                <td>
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                        <?php 
                                        $all_n = array_merge($res['notes_cc'], $res['notes_ex']);
                                        if (empty($all_n)): ?>
                                            <span style="color:#A0AEC0; font-style:italic; font-size:0.85em;">Aucune note</span>
                                        <?php else: 
                                            foreach($all_n as $n): 
                                                $color = $n['note_valeur'] < 10 ? '#D9383A' : '#2B6CB0';
                                            ?>
                                                <div style="background:#F7FAFC; border:1px solid #E2E8F0; padding:4px 8px; border-radius:4px; font-size:0.8em; min-width:60px; text-align:center;">
                                                    <span style="font-weight:bold; color:<?php echo $color; ?>;"><?php echo number_format($n['note_valeur'], 2); ?></span>
                                                    <br><small style="font-size:0.8em;"><?php echo htmlspecialchars($n['nom_examen'] ?? 'Note'); ?></small>
                                                </div>
                                            <?php endforeach; 
                                        endif; ?>
                                    </div>
                                </td>
                                <td style="text-align:center; font-weight:bold;">
                                    <?php echo $res['moy_cc'] !== null ? number_format($res['moy_cc'], 2) : '-'; ?>
                                </td>
                                <td style="text-align:center; font-weight:bold;">
                                    <?php echo $res['moy_ex'] !== null ? number_format($res['moy_ex'], 2) : '-'; ?>
                                </td>
                                <td style="text-align:center; font-weight:bold; background:#F8FAFC; color:#0A2240;">
                                    <?php echo $res['moy_gen'] !== null ? number_format($res['moy_gen'], 2) : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

        <div id="tab-rdv" class="tab-content">
        <div class="grid">
            <div class="card">
                <h3><i class="fa-solid fa-calendar-plus"></i> Demander un rendez-vous</h3>
                <form method="POST">
                    <label>Professeur</label>
                    <select name="enseignant_id" required>
                        <option value="">-- Choisir un professeur --</option>
                        <?php foreach($all_profs as $p): ?>
                            <option value="<?php echo $p['id']; ?>">M. <?php echo htmlspecialchars($p['nom'] . ' ' . $p['prenom']); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Sujet du rendez-vous</label>
                    <input type="text" name="sujet_rdv" required placeholder="Ex : Question sur les notes, orientation, absence...">

                    <label>Message complémentaire</label>
                    <textarea name="message_rdv" rows="4" placeholder="Expliquez rapidement la raison du rendez-vous..."></textarea>

                    <div class="grid" style="grid-template-columns: repeat(3, 1fr); gap: 10px;">
                        <div>
                            <label>Date souhaitée</label>
                            <input type="date" name="date_rdv" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label>Début</label>
                            <input type="time" name="heure_debut" required>
                        </div>
                        <div>
                            <label>Fin</label>
                            <input type="time" name="heure_fin" required>
                        </div>
                    </div>

                    <button type="submit" name="action_demander_rdv"><i class="fa-solid fa-paper-plane"></i> Envoyer la demande</button>
                </form>
            </div>

            <div class="card">
                <h3><i class="fa-solid fa-list-check"></i> Mes demandes de rendez-vous</h3>
                <?php if(empty($mes_rdv)): ?>
                    <p>Aucune demande de rendez-vous pour le moment.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr><th>Professeur</th><th>Date</th><th>Sujet</th><th>Statut</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($mes_rdv as $r): ?>
                                <tr>
                                    <td>M. <?php echo htmlspecialchars($r['prof_nom'] . ' ' . $r['prof_prenom']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($r['date_rdv'])); ?><br><small><?php echo substr($r['heure_debut'],0,5) . ' - ' . substr($r['heure_fin'],0,5); ?></small></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($r['sujet']); ?></strong>
                                        <?php if(!empty($r['message'])): ?><br><small><?php echo htmlspecialchars($r['message']); ?></small><?php endif; ?>
                                        <?php if(!empty($r['reponse_enseignant'])): ?><br><em>Réponse : <?php echo htmlspecialchars($r['reponse_enseignant']); ?></em><?php endif; ?>
                                    </td>
                                    <td><span class="badge <?php echo htmlspecialchars($r['statut']); ?>"><?php echo str_replace('_', ' ', htmlspecialchars($r['statut'])); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div id="tab-messagerie" class="tab-content">
        <div class="grid">
            <div class="card">
                <h3><i class="fa-solid fa-paper-plane"></i> Envoyer un message à un professeur</h3>
                <form method="POST">
                    <label>Sélectionner le professeur</label>
                    <select name="destinataire_id" required>
                        <?php foreach($all_profs as $p): ?>
                            <option value="<?php echo $p['id']; ?>">M. <?php echo htmlspecialchars($p['nom'] . ' ' . $p['prenom']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Votre Message</label>
                    <textarea name="contenu_message" rows="5" required placeholder="Écrivez votre message ici..."></textarea>
                    <button type="submit" name="action_envoyer_message">Envoyer</button>
                </form>
            </div>
            
            <div class="card">
                <h3><i class="fa-solid fa-inbox"></i> Boîte de réception</h3>
                <?php if(empty($boite_recus)): ?>
                    <p>Aucun message reçu.</p>
                <?php else: ?>
                    <?php foreach($boite_recus as $m): ?>
                        <div style="background:#F7FAFC; padding:12px; margin-bottom:10px; border-left:3px solid #0A2240; border-radius:4px;">
                            <strong>De: M. <?php echo htmlspecialchars($m['exp_nom'] . ' ' . $m['exp_prenom']); ?></strong> 
                            <small style="color:#718096; float:right;"><?php echo $m['date_envoi']; ?></small>
                            <p style="margin:5px 0 0 0;"><?php echo htmlspecialchars($m['contenu']); ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
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
    window.onload = function() {
        switchTab("<?php echo $active_tab_after_post; ?>");
    }

    function applyStudentFilter() {
        let filterVal = document.getElementById("studentScheduleFilter").value;
        let items = document.querySelectorAll(".session-item");

        items.forEach(item => {
            if (filterVal === "ALL") {
                item.style.display = "block";
            } else {
                let matchSubject = item.getAttribute("data-subject") === filterVal;
                if (matchSubject) {
                    item.style.display = "block";
                } else {
                    item.style.display = "none";
                }
            }
        });
    }
</script>
</body>
</html>
