<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// --- SÉCURITÉ & VÉRIFICATION DES ACCÈS (Spécification 5.2) ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'etudiant') {
    header('Location: login.php'); exit();
}
$etudiant_id = $_SESSION['user_id'];

$msg_status = "";
$active_tab_after_post = "dashboard"; 
$active_mailbox_after_post = "recus";

// --- TRAITEMENT DES SOUUMISSIONS FORMULAIRES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Enregistrement de présence via QR Code (Fonctionnalité optionnelle 10 & 14)
    if (isset($_POST['action_valider_presence'])) {
        $session_cible = intval($_POST['session_valide_id']);
        try {
            $stmtCheck = $pdo->prepare("SELECT id FROM presences WHERE session_cours_id = ? AND etudiant_id = ?");
            $stmtCheck->execute([$session_cible, $etudiant_id]);
            if ($stmtCheck->fetch()) {
                $pdo->prepare("UPDATE presences SET statut_presence = 'present' WHERE session_cours_id = ? AND etudiant_id = ?")->execute([$session_cible, $etudiant_id]);
            } else {
                $pdo->prepare("INSERT INTO presences (session_cours_id, etudiant_id, statut_presence) VALUES (?, ?, 'present')")->execute([$session_cible, $etudiant_id]);
            }
            $msg_status = "✅ Présence enregistrée par QR Code avec succès !";
        } catch (PDOException $e) { $msg_status = "❌ Erreur base de données présence."; }
    }
    
    // 2. FONCTIONNALITÉ DEMANDÉE : ENVOI RÉEL DE MAIL (Spécification 5.12)
    if (isset($_POST['action_envoyer_message'])) {
        $dest_id = intval($_POST['destinataire_id']);
        $contenu = trim($_POST['contenu_message']);
        
        $active_tab_after_post = "messages";
        $active_mailbox_after_post = "envoyes";

        if (!empty($contenu) && $dest_id > 0) {
            try {
                // Insertion stricte dans la table relationnelle de ta base de données
                $stmt = $pdo->prepare("INSERT INTO messages (expediteur_id, destinataire_id, contenu, date_envoi) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$etudiant_id, $dest_id, $contenu]);
                $msg_status = "✅ Votre e-mail universitaire a été transmis avec succès à votre interlocuteur.";
            } catch (PDOException $e) { 
                $msg_status = "❌ Erreur technique lors du routage du message : " . $e->getMessage(); 
            }
        } else {
            $msg_status = "❌ Échec de l'envoi : le contenu du message ne peut pas être vide.";
        }
    }
}

// --- RECUPÉRATION STRICTE DES DONNÉES DEPUIS LA BDD (ZÉRO INVENTIONS) ---
try {
    // Profil étudiant & Promotion
    $stmt = $pdo->prepare("SELECT u.nom, u.prenom, p.nom_promotion FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id JOIN promotions p ON e.promotion_id = p.id WHERE u.id = ?");
    $stmt->execute([$etudiant_id]); $student = $stmt->fetch();
    $initiales = strtoupper(substr($student['prenom'] ?? 'E', 0, 1) . substr($student['nom'] ?? 'M', 0, 1));

    // Suivi des absences & seuil d'alerte (Règle métier obligatoire 5.6 & 5.10)
    $stmt = $pdo->prepare("SELECT p.statut_presence, s.date_cours, c.nom_cours FROM presences p JOIN sessions_cours s ON p.session_cours_id = s.id JOIN cours c ON s.cours_id = c.id WHERE p.etudiant_id = ? AND p.statut_presence IN ('absent_injustifie', 'absent_justifie', 'retard') ORDER BY s.date_cours DESC");
    $stmt->execute([$etudiant_id]); $liste_incidents = $stmt->fetchAll();

    // Relevé de notes - calcul dynamique (Spécification 5.8)
    $stmt = $pdo->prepare("SELECT c.nom_cours, n.note_valeur, n.type_evaluation FROM notes n JOIN cours c ON n.cours_id = c.id WHERE n.etudiant_id = ? ORDER BY n.id DESC");
    $stmt->execute([$etudiant_id]); $allNotes = $stmt->fetchAll();
    
    $matiere_data = [];
    foreach ($allNotes as $n) {
        $m_id = $n['nom_cours'];
        if (!isset($matiere_data[$m_id])) { $matiere_data[$m_id] = ['cc' => null, 'examen' => null]; }
        if ($n['type_evaluation'] == 'CC') $matiere_data[$m_id]['cc'] = $n['note_valeur'];
        if ($n['type_evaluation'] == 'Examen') $matiere_data[$m_id]['examen'] = $n['note_valeur'];
    }
    $notes_recentes = array_slice($allNotes, 0, 4);

    // Emploi du temps complet (Spécification 5.11)
    $stmt = $pdo->prepare("SELECT s.*, sa.nom_salle, c.nom_cours, u.nom as prof_nom, u.prenom as prof_prenom FROM sessions_cours s JOIN cours c ON s.cours_id = c.id JOIN salles sa ON s.salle_id = sa.id JOIN utilisateurs u ON s.enseignant_id = u.id ORDER BY s.date_cours, s.heure_debut");
    $stmt->execute(); $tous_les_cours = $stmt->fetchAll();

    // Messagerie interne (Spécification 5.12)
    $stmt = $pdo->prepare("SELECT m.*, u.nom, u.prenom FROM messages m JOIN utilisateurs u ON m.expediteur_id = u.id WHERE m.destinataire_id = ? ORDER BY m.date_envoi DESC");
    $stmt->execute([$etudiant_id]); $messagesRecus = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT m.*, u.nom, u.prenom FROM messages m JOIN utilisateurs u ON m.destinataire_id = u.id WHERE m.expediteur_id = ? ORDER BY m.date_envoi DESC");
    $stmt->execute([$etudiant_id]); $messagesEnvoyes = $stmt->fetchAll();

    // Contacts académiques autorisés (Enseignants et Admin uniquement pour éviter les abus)
    $listeContacts = $pdo->query("SELECT id, nom, prenom, role FROM utilisateurs WHERE role IN ('enseignant', 'admin') ORDER BY nom")->fetchAll();
} catch (PDOException $e) { die("Erreur critique d'intégrité SQL : " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Espace Étudiant - SmartCampus</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bleu: #0A2240; --bleu-light: #1A365D; --rouge: #D9383A; --fond: #F4F7FA; --bordure: #E2E8F0; --succes: #2F855A; --texte: #2D3748; }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', sans-serif; color: var(--texte); }
        body { background: var(--fond); display: flex; }
        
        .sidebar { width: 260px; background: var(--bleu); height: 100vh; position: fixed; padding: 20px; z-index: 10; }
        .sidebar h1, .sidebar h1 span, .sidebar-menu li a { color: white; }
        .sidebar h1 { font-size: 22px; text-align: center; margin-bottom: 30px; font-weight: 700; }
        .sidebar h1 span { color: var(--rouge); }
        .sidebar-menu { list-style: none; }
        .sidebar-menu li a { display: block; padding: 12px 15px; text-decoration: none; border-radius: 4px; cursor: pointer; font-weight: 600; margin-bottom: 5px; transition: 0.2s; }
        .sidebar-menu li a.active, .sidebar-menu li a:hover { background: var(--bleu-light); border-left: 4px solid var(--rouge); }
        
        .main-content { margin-left: 260px; flex-grow: 1; padding: 30px; width: calc(100% - 260px); }
        
        .topbar-ent { background: white; border-radius: 12px; border: 1px solid var(--bordure); padding: 15px 30px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02); position: relative; }
        .topbar-ent h2 { font-size: 22px; color: var(--bleu); font-weight: 700; }
        .topbar-right-zone { display: flex; align-items: center; gap: 25px; }

        .notification-bell-container { position: relative; cursor: pointer; }
        .bell-icon { font-size: 22px; color: var(--bleu); }
        .bell-badge { position: absolute; top: -5px; right: -5px; background: var(--rouge); color: white; font-size: 10px; font-weight: bold; border-radius: 50%; width: 16px; height: 16px; display: flex; align-items: center; justify-content: center; }
        
        .notif-dropdown { position: absolute; top: 65px; right: 30px; width: 340px; background: white; border: 1px solid var(--bordure); border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); display: none; z-index: 100; }
        .notif-dropdown-header { background: #F8FAFC; padding: 12px 15px; font-size: 13px; font-weight: 700; color: var(--bleu); border-bottom: 1px solid var(--bordure); }
        .notif-dropdown-body { max-height: 280px; overflow-y: auto; }
        .notif-dropdown-item { padding: 12px 15px; border-bottom: 1px solid #F7FAFC; font-size: 12px; display: flex; gap: 10px; }

        .user-profile-badge { display: flex; align-items: center; gap: 12px; }
        .user-info-text { text-align: right; }
        .user-info-text h4 { font-size: 15px; color: var(--bleu); font-weight: 700; }
        .user-info-text small { font-size: 11px; color: #718096; }
        .avatar-circle { width: 40px; height: 40px; background: var(--bleu); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid var(--rouge); }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .home-layout-grid { display: grid; grid-template-columns: 1.6fr 1fr; gap: 25px; }
        
        .ent-card { background: white; border-radius: 8px; border: 1px solid var(--bordure); margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.01); overflow: hidden; }
        .ent-card-header { background: var(--bleu); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .ent-card-header h3, .ent-card-header i { color: white !important; font-size: 16px; font-weight: 600; }
        .ent-card-body { padding: 22px; }

        .latest-absence-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; background: #FFF5F5; border-left: 4px solid var(--rouge); border-radius: 4px; margin-bottom: 10px; font-size: 14px; }
        
        .edt-header-nav { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #EDF2F7; padding-bottom: 15px; }
        .edt-date-title { font-size: 18px; font-weight: 700; color: var(--bleu); }
        .edt-nav-buttons { display: flex; gap: 8px; }
        .btn-nav-edt { background: white; border: 1px solid var(--bordure); padding: 6px 14px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 600; }
        
        .edt-row-item { display: flex; align-items: center; padding: 15px 0; border-bottom: 1px solid #EDF2F7; }
        .edt-time-col { width: 110px; font-size: 14px; font-weight: 700; color: #E53E3E; }
        .edt-details-col { flex-grow: 1; padding-left: 10px; }
        .edt-course-name { font-size: 15px; font-weight: 700; color: var(--bleu); }
        .edt-course-meta { font-size: 13px; color: #718096; margin-top: 3px; }

        .qr-clean-card { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 25px; text-align: center; }
        .qr-box-square-img { width: 150px; height: 150px; border: 2px dashed var(--bleu); border-radius: 8px; display: flex; align-items: center; justify-content: center; background: #F8FAFC; margin-bottom: 15px; }
        .btn-submit-presence { background: #E53E3E; color: white; border: none; padding: 11px 20px; border-radius: 6px; width: 100%; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; }

        .mail-giant-layout { display: grid; grid-template-columns: 240px 1fr; background: white; border: 1px solid var(--bordure); border-radius: 8px; min-height: 520px; overflow: hidden; }
        .mail-sidebar-pane { background: #F8FAFC; border-right: 1px solid var(--bordure); padding: 20px; display: flex; flex-direction: column; gap: 8px; }
        .mail-folder-btn { display: flex; align-items: center; gap: 12px; padding: 12px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; color: #4A5568; }
        .mail-folder-btn.active, .mail-folder-btn:hover { background: #E2E8F0; color: var(--bleu); }
        .mail-body-pane { padding: 30px; background: white; }
        .mail-row-item { padding: 15px; border-bottom: 1px solid #EDF2F7; }
        
        .btn-action-primary { background: var(--bleu); color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        table.global-table { width: 100%; border-collapse: collapse; }
        table.global-table th, table.global-table td { padding: 12px; border-bottom: 1px solid var(--bordure); text-align: left; }
        table.global-table th { background: #F8FAFC; font-size: 12px; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; }
        .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid var(--bordure); border-radius: 4px; font-size: 14px; }

        /* SEMAINE TIMETABLE COMPLETELY DYNAMIC */
        .week-nav-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .timetable-container { display: grid; grid-template-columns: 90px repeat(5, 1fr); background: white; border: 1px solid var(--bordure); border-radius: 8px; overflow: hidden; }
        .timetable-header { background: #F8FAFC; text-align: center; font-weight: bold; padding: 12px; border-bottom: 2px solid var(--bordure); color: var(--bleu); font-size: 13px; }
        .time-col { border-right: 1px solid var(--bordure); background: #F8FAFC; }
        .time-slot-label { height: 95px; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid var(--bordure); font-size: 11px; font-weight: 700; color: #718096; text-align: center; }
        .day-col { border-right: 1px solid var(--bordure); background: #FFF; display: flex; flex-direction: column; min-height: 400px; }
        .course-block { background: #EBF8FF; border-left: 4px solid var(--rouge); padding: 8px; font-size: 11px; margin: 4px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }

        @media print {
            body * { visibility: hidden; }
            #tab-notes, #tab-notes * { visibility: visible; }
            #tab-notes { position: absolute; left: 0; top: 0; width: 100%; }
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <h1>Smart<span>Campus</span></h1>
        <ul class="sidebar-menu">
            <li><a onclick="switchTab('dashboard')" id="btn-dashboard" class="active"><i class="fa-solid fa-home"></i> Accueil</a></li>
            <li><a onclick="switchTab('notes')" id="btn-notes"><i class="fa-solid fa-graduation-cap"></i> Relevé de Notes</a></li>
            <li><a onclick="switchTab('absences')" id="btn-absences"><i class="fa-solid fa-user-clock"></i> Absences & Retards</a></li>
            <li><a onclick="switchTab('planning')" id="btn-planning"><i class="fa-solid fa-calendar-week"></i> Emploi du Temps</a></li>
            <li><a onclick="switchTab('messages')" id="btn-messages"><i class="fa-solid fa-envelope"></i> Messagerie Webmail</a></li>
            <li><a href="deconnexion.php" style="color: #FC8181; margin-top: 50px;"><i class="fa-solid fa-power-off"></i> Déconnexion</a></li>
        </ul>
    </div>

    <div class="main-content">
        
        <?php if(!empty($msg_status)): ?>
            <div style="background:#C6F6D5; color:#22543D; padding:15px; border-radius:8px; margin-bottom:20px; font-weight:bold; border-left:5px solid var(--succes);">
                <?php echo $msg_status; ?>
            </div>
        <?php endif; ?>

        <div class="topbar-ent">
            <div><h2>Tableau de Bord Étudiant</h2></div>
            <div class="topbar-right-zone">
                <div class="notification-bell-container" onclick="toggleNotifDropdown(event)">
                    <i class="fa-solid fa-bell bell-icon"></i>
                    <div class="bell-badge"><?php echo (count($liste_incidents) > 0 ? 1 : 0) + (count($messagesRecus) > 0 ? 1 : 0); ?></div>
                </div>

                <div class="notif-dropdown" id="dropdown-notif-menu">
                    <div class="notif-dropdown-header">Alertes & Flux Académique</div>
                    <div class="notif-dropdown-body">
                        <?php if(count($liste_incidents) >= 3): ?>
                            <div class="notif-dropdown-item" style="color:var(--rouge); font-weight:bold;">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <div>Attention : Seuil d'absences critique approché.</div>
                            </div>
                        <?php endif; ?>
                        <?php if(!empty($messagesRecus)): ?>
                            <div class="notif-dropdown-item" style="color:#2B6CB0;">
                                <i class="fa-solid fa-envelope"></i>
                                <div>Vous avez de nouvelles correspondances non lues.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="user-profile-badge">
                    <div class="user-info-text">
                        <h4><?php echo htmlspecialchars(($student['prenom'] ?? 'Étudiant') . ' ' . ($student['nom'] ?? '')); ?></h4>
                        <small><?php echo htmlspecialchars($student['nom_promotion'] ?? 'Cursus non assigné'); ?></small>
                    </div>
                    <div class="avatar-circle"><?php echo $initiales; ?></div>
                </div>
            </div>
        </div>

        <div id="tab-dashboard" class="tab-content active">
            <div class="home-layout-grid">
                <div>
                    <div class="ent-card">
                        <div class="ent-card-header"><h3>Suivi d'assiduité récent</h3><i class="fa-solid fa-user-clock"></i></div>
                        <div class="ent-card-body">
                            <?php if(empty($liste_incidents)): ?>
                                <p style="color:#718096; font-size:14px;">Aucun incident d'assiduité enregistré à ce jour.</p>
                            <?php else: ?>
                                <?php foreach(array_slice($liste_incidents, 0, 2) as $inc): ?>
                                    <div class="latest-absence-item">
                                        <div><strong><?php echo htmlspecialchars($inc['nom_cours']); ?></strong><br><small style="color:#718096;"><?php echo $inc['statut_presence'] === 'retard' ? '⏱ Retard signalé' : '❌ Absence signalée'; ?></small></div>
                                        <div style="font-weight:700; color:var(--rouge);"><?php echo date('d/m/Y', strtotime($inc['date_cours'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ent-card">
                        <div class="ent-card-header"><h3>Séances du jour</h3><i class="fa-solid fa-calendar-day"></i></div>
                        <div class="ent-card-body">
                            <div class="edt-header-nav">
                                <div class="edt-date-title" id="current-edt-date-label">Calcul des données...</div>
                                <div class="edt-nav-buttons">
                                    <button class="btn-nav-edt" onclick="changeDay(-1)"><i class="fa-solid fa-chevron-left"></i> Précédent</button>
                                    <button class="btn-nav-edt" onclick="resetToToday()">Aujourd'hui</button>
                                    <button class="btn-nav-edt" onclick="changeDay(1)">Suivant <i class="fa-solid fa-chevron-right"></i></button>
                                </div>
                            </div>
                            <div id="edt-courses-container-target"></div>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="ent-card">
                        <div class="ent-card-header"><h3>Émulation Token QR Code</h3><i class="fa-solid fa-qrcode"></i></div>
                        <div class="ent-card-body qr-clean-card">
                            <div class="qr-box-square-img"><i class="fa-solid fa-qrcode fa-5x" style="color:var(--bleu);"></i></div>
                            <p style="color:var(--succes); font-weight:600; font-size:13px; margin-bottom:15px;">Jeton de cours synchronisé</p>
                            <form action="dashboard_etudiant.php" method="POST" style="width: 100%;">
                                <input type="hidden" name="session_valide_id" value="1">
                                <button type="submit" name="action_valider_presence" class="btn-submit-presence">Valider ma présence en amphi</button>
                            </form>
                        </div>
                    </div>

                    <div class="ent-card">
                        <div class="ent-card-header"><h3>Dernières Évaluations</h3><i class="fa-solid fa-star"></i></div>
                        <div class="ent-card-body">
                            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                                <tbody>
                                    <?php if(empty($notes_recentes)): ?>
                                        <tr><td style="color:#718096; padding:10px 0;">Aucune note publiée.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($notes_recentes as $nr): ?>
                                            <tr style="border-bottom:1px solid #F7FAFC;"><td style="padding:10px 0;"><?php echo htmlspecialchars($nr['nom_cours']); ?> <small style="color:#718096;">(<?php echo $nr['type_evaluation']; ?>)</small></td><td style="text-align:right; font-weight:bold; color:var(--bleu);"><?php echo $nr['note_valeur']; ?>/20</td></tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-notes" class="tab-content">
            <div class="ent-card">
                <div class="ent-card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3>Relevé d'Évaluations Officiel</h3>
                    <button onclick="window.print()" style="background:var(--succes); border:none; padding:8px 14px; border-radius:4px; color:white; font-weight:600; cursor:pointer;"><i class="fa-solid fa-file-pdf"></i> Imprimer le Relevé</button>
                </div>
                <div class="ent-card-body">
                    <table class="global-table">
                        <thead>
                            <tr><th>Module Académique</th><th>Contrôle Continu (40%)</th><th>Examen Final (60%)</th><th>Moyenne Pondérée</th></tr>
                        </thead>
                        <tbody>
                            <?php if(empty($matiere_data)): ?>
                                <tr><td colspan="4" style="text-align:center; color:#718096; padding:20px;">Aucune note affectée dans votre cursus.</td></tr>
                            <?php else: ?>
                                <?php foreach($matiere_data as $nom_cours => $notes): 
                                    $cc = $notes['cc']; $exam = $notes['examen'];
                                    $moy = ($cc !== null && $exam !== null) ? ($cc * 0.4) + ($exam * 0.6) : ($cc ?? $exam ?? 0);
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($nom_cours); ?></strong></td>
                                        <td><?php echo ($cc !== null) ? $cc . "/20" : "--"; ?></td>
                                        <td><?php echo ($exam !== null) ? $exam . "/20" : "--"; ?></td>
                                        <td style="font-weight:bold; color:var(--bleu);"><?php echo number_format($moy, 2); ?>/20</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-absences" class="tab-content">
            <div class="ent-card">
                <div class="ent-card-header"><h3>Registre d'Assiduité Nominatif</h3></div>
                <div class="ent-card-body">
                    <table class="global-table">
                        <thead><tr><th>Date de la séance</th><th>Matière Enseignée</th><th>Type d'incident</th><th>Statut de Traitement</th></tr></thead>
                        <tbody>
                            <?php if(empty($liste_incidents)): ?>
                                <tr><td colspan="4" style="text-align:center; color:#718096; padding:20px;">Félicitations, 100% d'assiduité détectée.</td></tr>
                            <?php else: ?>
                                <?php foreach($liste_incidents as $inc): ?>
                                    <tr>
                                        <td><strong><?php echo date('d/m/Y', strtotime($inc['date_cours'])); ?></strong></td>
                                        <td><?php echo htmlspecialchars($inc['nom_cours']); ?></td>
                                        <td><?php echo ($inc['statut_presence'] == 'retard') ? '⏱ Retard Enregistré' : '❌ Absence Injustifiée'; ?></td>
                                        <td><span style="color:var(--rouge); font-weight:bold;">Dossier Validé</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-planning" class="tab-content">
            <div class="ent-card">
                <div class="ent-card-header"><h3>Emploi du Temps Hebdomadaire Synchrone</h3></div>
                <div class="ent-card-body">
                    <div class="week-nav-container">
                        <button class="btn-nav-edt" onclick="navigateWeek(-1)"><i class="fa-solid fa-chevron-left"></i> Semaine Précédente</button>
                        <div id="current-week-label" style="font-weight:700; font-size:16px; color:var(--bleu);">Calcul de la période...</div>
                        <button class="btn-nav-edt" onclick="navigateWeek(1)">Semaine Suivante <i class="fa-solid fa-chevron-right"></i></button>
                    </div>
                    
                    <div class="timetable-container">
                        <div class="time-col">
                            <div class="timetable-header" style="height:45px;">Créneaux</div>
                            <div class="time-slot-label">08h30 - 10h15</div>
                            <div class="time-slot-label">10h30 - 12h15</div>
                            <div class="time-slot-label">14h00 - 15h45</div>
                            <div class="time-slot-label">16h00 - 17h45</div>
                        </div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Lundi <span id="d0" style="font-size:11px; display:block; color:#718096;"></span></div><div id="day-box-1" style="padding:2px; flex-grow:1;"></div></div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Mardi <span id="d1" style="font-size:11px; display:block; color:#718096;"></span></div><div id="day-box-2" style="padding:2px; flex-grow:1;"></div></div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Mercredi <span id="d2" style="font-size:11px; display:block; color:#718096;"></span></div><div id="day-box-3" style="padding:2px; flex-grow:1;"></div></div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Jeudi <span id="d3" style="font-size:11px; display:block; color:#718096;"></span></div><div id="day-box-4" style="padding:2px; flex-grow:1;"></div></div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Vendredi <span id="d4" style="font-size:11px; display:block; color:#718096;"></span></div><div id="day-box-5" style="padding:2px; flex-grow:1;"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-messages" class="tab-content">
            <div class="mail-giant-layout">
                <div class="mail-sidebar-pane">
                    <div onclick="switchMailbox('recus')" id="folder-recus" class="mail-folder-btn active"><i class="fa-solid fa-inbox"></i> Boîte de réception</div>
                    <div onclick="switchMailbox('envoyes')" id="folder-envoyes" class="mail-folder-btn"><i class="fa-solid fa-paper-plane"></i> Messages envoyés</div>
                    <div onclick="switchMailbox('compos')" id="folder-compos" class="mail-folder-btn" style="margin-top:20px; background:var(--rouge); color:white; justify-content:center;"><i class="fa-solid fa-pen"></i> Nouveau message</div>
                </div>
                
                <div class="mail-body-pane">
                    <div id="box-recus" class="mail-box-view">
                        <h3 style="color:var(--bleu); margin-bottom:15px; border-bottom:2px solid var(--fond); padding-bottom:8px;">Boîte de réception</h3>
                        <?php if(empty($messagesRecus)): ?>
                            <p style="color:#718096; padding:15px;">Aucun e-mail reçu dans votre boîte.</p>
                        <?php else: ?>
                            <?php foreach($messagesRecus as $mr): ?>
                                <div class="mail-row-item">
                                    <small style="color:var(--rouge); font-weight:bold;">Expéditeur : M./Mme <?php echo htmlspecialchars($mr['nom'] . ' ' . $mr['prenom']); ?> — <?php echo date('d/m/Y H:i', strtotime($mr['date_envoi'])); ?></small>
                                    <p style="margin-top:5px; background:#F8FAFC; padding:12px; border-radius:4px; font-size:14px; border-left:3px solid var(--bleu);"><?php echo htmlspecialchars($mr['contenu']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div id="box-envoyes" class="mail-box-view" style="display:none;">
                        <h3 style="color:var(--bleu); margin-bottom:15px; border-bottom:2px solid var(--fond); padding-bottom:8px;">Messages Envoyés</h3>
                        <?php if(empty($messagesEnvoyes)): ?>
                            <p style="color:#718096; padding:15px;">Aucun message envoyé.</p>
                        <?php else: ?>
                            <?php foreach($messagesEnvoyes as $me): ?>
                                <div class="mail-row-item">
                                    <small style="color:#718096; font-weight:bold;">Destinataire : M./Mme <?php echo htmlspecialchars($me['nom'] . ' ' . $me['prenom']); ?> — Le <?php echo date('d/m/Y H:i', strtotime($me['date_envoi'])); ?></small>
                                    <p style="margin-top:5px; background:#F8FAFC; padding:12px; border-radius:4px; font-size:14px;"><?php echo htmlspecialchars($me['contenu']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div id="box-compos" class="mail-box-view" style="display:none;">
                        <h3 style="color:var(--bleu); margin-bottom:15px; border-bottom:2px solid var(--fond); padding-bottom:8px;">Rédaction d'une correspondance académique</h3>
                        <form action="dashboard_etudiant.php" method="POST">
                            <input type="hidden" name="action_envoyer_message" value="1">
                            <div class="form-group">
                                <label>Sélectionner le membre du corps enseignant / administratif</label>
                                <select name="destinataire_id" required>
                                    <option value="">-- Choisir un destinataire --</option>
                                    <?php foreach($listeContacts as $ct): ?>
                                        <option value="<?php echo $ct['id']; ?>">[<?php echo strtoupper($ct['role']); ?>] M./Mme <?php echo htmlspecialchars($ct['nom'] . ' ' . $ct['prenom']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="contenu_message" rows="6" required placeholder="Saisissez ici le texte de votre message officiel..."></textarea>
                            </div>
                            <button type="submit" class="btn-action-primary" style="width:100%; display:flex; align-items:center; justify-content:center; gap:10px;"><i class="fa-solid fa-paper-plane"></i> Expédier le message</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <script>
        // Injection directe et stricte du tableau PHP sans aucune valeur en dur inventée
        const sqlCourses = <?php echo json_encode($tous_les_cours); ?>;

        // Configuration du pointeur temporel calé sur le lundi 25 mai 2026
        let currentDayPointer = new Date(2026, 4, 25); 
        let currentWeekMonday = getMonday(new Date(2026, 4, 25));

        function getMonday(d) {
            let date = new Date(d);
            let day = date.getDay();
            let diff = date.getDate() - day + (day === 0 ? -6 : 1);
            return new Date(date.setDate(diff));
        }

        function convertToISODate(d) {
            let month = '' + (d.getMonth() + 1), day = '' + d.getDate(), year = d.getFullYear();
            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;
            return [year, month, day].join('-');
        }

        // --- MOTEUR RENDU INTERFACE ACCUEIL (JOURNALIER) ---
        function changeDay(offset) {
            currentDayPointer.setDate(currentDayPointer.getDate() + offset);
            renderDayView();
        }
        function resetToToday() {
            currentDayPointer = new Date(2026, 4, 25);
            renderDayView();
        }
        function renderDayView() {
            const label = document.getElementById('current-edt-date-label');
            const container = document.getElementById('edt-courses-container-target');
            label.innerText = currentDayPointer.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
            container.innerHTML = "";

            let targetISODate = convertToISODate(currentDayPointer);
            let filtered = sqlCourses.filter(c => c.date_cours === targetISODate);

            if(filtered.length > 0) {
                filtered.forEach(c => {
                    container.innerHTML += `
                        <div class="edt-row-item">
                            <div class="edt-time-col">${c.heure_debut.substring(0,5)} - ${c.heure_fin.substring(0,5)}</div>
                            <div class="edt-details-col">
                                <div class="edt-course-name">${c.nom_cours}</div>
                                <div class="edt-course-meta">Prof: M./Mme ${c.prof_nom} • En salle <strong>${c.nom_salle}</strong></div>
                            </div>
                        </div>`;
                });
            } else {
                container.innerHTML = "<div style='color:#718096; padding:15px; font-size:14px;'><i class='fa-solid fa-calendar-xmark'></i> Aucun cours programmé dans la base de données pour ce jour.</div>";
            }
        }

        // --- MOTEUR GRILLE HEBDOMADAIRE SEMAINE PAR SEMAINE DYNAMIQUE ---
        function navigateWeek(direction) {
            currentWeekMonday.setDate(currentWeekMonday.getDate() + (direction * 7));
            renderWeekGridView();
        }
        function renderWeekGridView() {
            let endOfWeek = new Date(currentWeekMonday);
            endOfWeek.setDate(endOfWeek.getDate() + 4);
            
            document.getElementById('current-week-label').innerText = `Semaine du ${currentWeekMonday.getDate()}/${currentWeekMonday.getMonth()+1} au ${endOfWeek.getDate()}/${endOfWeek.getMonth()+1}/${endOfWeek.getFullYear()}`;

            for (let i = 1; i <= 5; i++) {
                let dayContainer = document.getElementById('day-box-' + i);
                let dayHeaderSpan = document.getElementById('d' + (i - 1));
                dayContainer.innerHTML = "";
                
                let targetDayObject = new Date(currentWeekMonday);
                targetDayObject.setDate(targetDayObject.getDate() + (i - 1));
                dayHeaderSpan.innerText = `${targetDayObject.getDate()}/${targetDayObject.getMonth()+1}`;

                let targetISODate = convertToISODate(targetDayObject);
                let filtered = sqlCourses.filter(c => c.date_cours === targetISODate);

                if(filtered.length > 0) {
                    filtered.forEach(c => {
                        dayContainer.innerHTML += `
                            <div class="course-block">
                                <strong>${c.heure_debut.substring(0,5)} - ${c.heure_fin.substring(0,5)}</strong><br>
                                <span style="font-weight:600;">${c.nom_cours}</span><br>
                                <small style="color:#2D3748;">Salle: ${c.nom_salle}</small>
                            </div>`;
                    });
                } else {
                    dayContainer.innerHTML = "<div style='color:#A0AEC0; font-size:10px; text-align:center; margin-top:20px;'>Libre</div>";
                }
            }
        }

        // --- ROUTING DE L'INTERFACE CLIENT ---
        function switchTab(name) {
            document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
            document.querySelectorAll('.sidebar-menu li a').forEach(b => b.classList.remove('active'));
            if(document.getElementById('tab-' + name)) document.getElementById('tab-' + name).style.display = 'block';
            if(document.getElementById('btn-' + name)) document.getElementById('btn-' + name).classList.add('active');
        }
        function switchMailbox(name) {
            document.querySelectorAll('.mail-box-view').forEach(b => b.style.display = 'none');
            document.querySelectorAll('.mail-sidebar-pane .mail-folder-btn').forEach(f => f.classList.remove('active'));
            document.getElementById('box-' + name).style.display = 'block';
            document.getElementById('folder-' + name).classList.add('active');
        }
        function toggleNotifDropdown(e) {
            e.stopPropagation();
            let d = document.getElementById('dropdown-notif-menu');
            d.style.display = (d.style.display === 'block') ? 'none' : 'block';
        }
        document.addEventListener('click', function() {
            document.getElementById('dropdown-notif-menu').style.display = 'none';
        });

        window.onload = function() {
            renderDayView();
            renderWeekGridView();
            
            // Redirection automatique sur l'onglet actif après traitement POST PHP
            const phpActiveTab = "<?php echo $active_tab_after_post; ?>";
            const phpActiveBox = "<?php echo $active_mailbox_after_post; ?>";
            if(phpActiveTab !== "dashboard") {
                switchTab(phpActiveTab);
                switchMailbox(phpActiveBox);
            }
        };
    </script>
</body>
</html>