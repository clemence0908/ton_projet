<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// --- SÉCURITÉ & VÉRIFICATION DES ACCÈS ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'enseignant') {
    header('Location: login.php'); exit();
}
$enseignant_id = $_SESSION['user_id'];

$msg_status = "";
$active_tab = "dashboard";
$active_mailbox = "recus";
$selected_cours_id = null;
$selected_session_id = null;
$selected_eleve_id = null;

// --- TRAITEMENTS DES FORMULAIRES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Saisie des Notes
    if (isset($_POST['action_enregistrer_notes'])) {
        $selected_cours_id = intval($_POST['cours_id']);
        $type_eval = $_POST['type_evaluation'];
        $nom_eval = trim($_POST['nom_evaluation']);
        $coefficient = floatval(str_replace(',', '.', $_POST['coefficient'] ?? '1'));
        if ($coefficient <= 0) $coefficient = 1;
        $active_tab = "notes";
        if (!empty($_POST['notes']) && !empty($nom_eval)) {
            try {
                $pdo->beginTransaction();
                foreach ($_POST['notes'] as $id_etd => $valeur_note) {
                    if ($valeur_note === "") continue;
                    $note_float = floatval(str_replace(',', '.', $valeur_note));
                    if ($note_float < 0) $note_float = 0;
                    if ($note_float > 20) $note_float = 20;
                    // Vérifie si la colonne coefficient existe dans la table notes
                    $stmtCheck = $pdo->prepare("SELECT id FROM notes WHERE etudiant_id = ? AND cours_id = ? AND type_evaluation = ? AND nom_evaluation = ?");
                    $stmtCheck->execute([$id_etd, $selected_cours_id, $type_eval, $nom_eval]);
                    $existe = $stmtCheck->fetch();
                    if ($existe) {
                        try {
                            $pdo->prepare("UPDATE notes SET note_valeur = ?, coefficient = ? WHERE id = ?")->execute([$note_float, $coefficient, $existe['id']]);
                        } catch (Exception $ex) {
                            $pdo->prepare("UPDATE notes SET note_valeur = ? WHERE id = ?")->execute([$note_float, $existe['id']]);
                        }
                    } else {
                        try {
                            $pdo->prepare("INSERT INTO notes (etudiant_id, cours_id, note_valeur, type_evaluation, nom_evaluation, coefficient) VALUES (?, ?, ?, ?, ?, ?)")
                                ->execute([$id_etd, $selected_cours_id, $note_float, $type_eval, $nom_eval, $coefficient]);
                        } catch (Exception $ex) {
                            $pdo->prepare("INSERT INTO notes (etudiant_id, cours_id, note_valeur, type_evaluation, nom_evaluation) VALUES (?, ?, ?, ?, ?)")
                                ->execute([$id_etd, $selected_cours_id, $note_float, $type_eval, $nom_eval]);
                        }
                    }
                }
                $pdo->commit();
                $msg_status = "✅ Évaluation [" . htmlspecialchars($nom_eval) . "] — Coeff. " . $coefficient . " — enregistrée avec succès pour " . count(array_filter($_POST['notes'], fn($v) => $v !== "")) . " étudiant(s).";
            } catch (Exception $e) { $pdo->rollBack(); $msg_status = "❌ Erreur lors de l'enregistrement : " . $e->getMessage(); }
        } else {
            $msg_status = "⚠️ Veuillez remplir le libellé de l'évaluation et au moins une note.";
            $active_tab = "notes";
        }
    }

    // 2. Validation de la Feuille d'Appel
    if (isset($_POST['action_valider_presences'])) {
        $selected_session_id = intval($_POST['session_cours_id']);
        $active_tab = "presences";
        if (isset($_POST['statut_presence'])) {
            try {
                $pdo->beginTransaction();
                foreach ($_POST['statut_presence'] as $id_etd => $statut) {
                    $stmtCheck = $pdo->prepare("SELECT id FROM presences WHERE session_cours_id = ? AND etudiant_id = ?");
                    $stmtCheck->execute([$selected_session_id, $id_etd]);
                    if ($stmtCheck->fetch()) {
                        $pdo->prepare("UPDATE presences SET statut_presence = ? WHERE session_cours_id = ? AND etudiant_id = ?")->execute([$statut, $selected_session_id, $id_etd]);
                    } else {
                        $pdo->prepare("INSERT INTO presences (session_cours_id, etudiant_id, statut_presence) VALUES (?, ?, ?)")->execute([$selected_session_id, $id_etd, $statut]);
                    }
                }
                $pdo->commit();
                $msg_status = "✅ Feuille d'appel mise à jour avec succès.";
            } catch (Exception $e) { $pdo->rollBack(); $msg_status = "❌ Erreur d'enregistrement de l'appel."; }
        }
    }

    // 3. Messagerie : Envoi d'un message
    if (isset($_POST['action_envoyer_mail'])) {
        $active_tab = "messages";
        $active_mailbox = "envoyes";
        $dest_id = intval($_POST['destinataire_id']);
        $contenu = trim($_POST['contenu_message']);
        if (!empty($contenu) && $dest_id > 0) {
            $stmt = $pdo->prepare("INSERT INTO messages (expediteur_id, destinataire_id, contenu, date_envoi) VALUES (?, ?, ?, NOW())");
            $stmt->execute([$enseignant_id, $dest_id, $contenu]);
            $msg_status = "✅ Message envoyé avec succès.";
        }
    }
}

// --- RÉCUPÉRATION DES DONNÉES ---
try {
    $stmt = $pdo->prepare("SELECT nom, prenom FROM utilisateurs WHERE id = ?");
    $stmt->execute([$enseignant_id]); $prof = $stmt->fetch();
    $initiales = strtoupper(substr($prof['prenom'] ?? 'P', 0, 1) . substr($prof['nom'] ?? 'F', 0, 1));

    $stmt = $pdo->prepare("SELECT DISTINCT c.* FROM cours c JOIN sessions_cours s ON c.id = s.cours_id WHERE s.enseignant_id = ? ORDER BY c.nom_cours");
    $stmt->execute([$enseignant_id]); $mes_cours = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT s.*, c.nom_cours FROM sessions_cours s JOIN cours c ON s.cours_id = c.id WHERE s.enseignant_id = ? ORDER BY s.date_cours DESC, s.heure_debut DESC");
    $stmt->execute([$enseignant_id]); $toutes_mes_sessions = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT s.*, c.nom_cours, sa.nom_salle FROM sessions_cours s JOIN cours c ON s.cours_id = c.id JOIN salles sa ON s.salle_id = sa.id WHERE s.enseignant_id = ? AND (s.date_cours > CURRENT_DATE OR (s.date_cours = CURRENT_DATE AND s.heure_debut >= CURRENT_TIME)) ORDER BY s.date_cours ASC, s.heure_debut ASC LIMIT 3");
    $stmt->execute([$enseignant_id]); $cours_a_venir = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT DISTINCT u.id, u.nom, u.prenom, p.nom_promotion FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id JOIN promotions p ON e.promotion_id = p.id JOIN inscriptions_cours ic ON e.utilisateur_id = ic.etudiant_id JOIN sessions_cours sc ON ic.cours_id = sc.cours_id WHERE sc.enseignant_id = ? ORDER BY u.nom ASC");
    $stmt->execute([$enseignant_id]); $mes_eleves = $stmt->fetchAll();

    $profil_eleve = null; $notes_eleve = []; $absences_eleve = []; $moyenne_generale = 0;
    if (isset($_GET['detail_eleve'])) {
        $selected_eleve_id = intval($_GET['detail_eleve']); $active_tab = "eleves";
        $stmt = $pdo->prepare("SELECT u.id, u.nom, u.prenom, p.nom_promotion, e.numero_etudiant FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id JOIN promotions p ON e.promotion_id = p.id WHERE u.id = ?");
        $stmt->execute([$selected_eleve_id]); $profil_eleve = $stmt->fetch();
        if ($profil_eleve) {
            $stmt = $pdo->prepare("SELECT n.*, c.nom_cours FROM notes n JOIN cours c ON n.cours_id = c.id WHERE n.etudiant_id = ?");
            $stmt->execute([$selected_eleve_id]); $notes_eleve = $stmt->fetchAll();
            if (count($notes_eleve) > 0) {
                $total = 0; foreach ($notes_eleve as $n) { $total += $n['note_valeur']; }
                $moyenne_generale = $total / count($notes_eleve);
            }
            $stmt = $pdo->prepare("SELECT p.*, s.date_cours, c.nom_cours FROM presences p JOIN sessions_cours s ON p.session_cours_id = s.id JOIN cours c ON s.cours_id = c.id WHERE p.etudiant_id = ? AND p.statut_presence != 'present'");
            $stmt->execute([$selected_eleve_id]); $absences_eleve = $stmt->fetchAll();
        }
    }

    if (isset($_GET['select_cours'])) { $selected_cours_id = intval($_GET['select_cours']); $active_tab = "notes"; }
    if (isset($_GET['select_session'])) { $selected_session_id = intval($_GET['select_session']); $active_tab = "presences"; }
    if (isset($_GET['tab']) && $_GET['tab'] === 'notes') { $active_tab = "notes"; }

    // Récupérer toutes les promotions associées aux cours de cet enseignant (pour filtre de classe)
    $stmt = $pdo->prepare("SELECT DISTINCT p.id, p.nom_promotion FROM promotions p JOIN etudiants e ON p.id = e.promotion_id JOIN utilisateurs u ON e.utilisateur_id = u.id JOIN inscriptions_cours ic ON u.id = ic.etudiant_id JOIN sessions_cours sc ON ic.cours_id = sc.cours_id WHERE sc.enseignant_id = ? ORDER BY p.nom_promotion ASC");
    $stmt->execute([$enseignant_id]); $toutes_promotions = $stmt->fetchAll();

    // Filtre promotion pour la saisie notes
    $selected_promo_id = isset($_POST['promotion_id']) ? intval($_POST['promotion_id']) : (isset($_GET['promotion_id']) ? intval($_GET['promotion_id']) : 0);

    $etudiants_du_cours = []; $notes_deja_publiees = [];
    if ($selected_cours_id) {
        // Filtre par promotion si sélectionnée
        if ($selected_promo_id > 0) {
            $stmt = $pdo->prepare("SELECT u.id, u.nom, u.prenom, p.nom_promotion FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id JOIN promotions p ON e.promotion_id = p.id JOIN inscriptions_cours ic ON e.utilisateur_id = ic.etudiant_id WHERE ic.cours_id = ? AND p.id = ? ORDER BY u.nom ASC");
            $stmt->execute([$selected_cours_id, $selected_promo_id]);
        } else {
            $stmt = $pdo->prepare("SELECT u.id, u.nom, u.prenom, p.nom_promotion FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id JOIN promotions p ON e.promotion_id = p.id JOIN inscriptions_cours ic ON e.utilisateur_id = ic.etudiant_id WHERE ic.cours_id = ? ORDER BY p.nom_promotion ASC, u.nom ASC");
            $stmt->execute([$selected_cours_id]);
        }
        $etudiants_du_cours = $stmt->fetchAll();
        $stmt = $pdo->prepare("SELECT n.*, u.nom, u.prenom FROM notes n JOIN utilisateurs u ON n.etudiant_id = u.id WHERE n.cours_id = ? ORDER BY n.nom_evaluation ASC, u.nom ASC");
        $stmt->execute([$selected_cours_id]); $notes_deja_publiees = $stmt->fetchAll();
    }

    $etudiants_de_la_session = []; $presences_existantes = [];
    if ($selected_session_id) {
        $stmt = $pdo->prepare("SELECT cours_id FROM sessions_cours WHERE id = ?");
        $stmt->execute([$selected_session_id]); $c_id = $stmt->fetchColumn();
        if ($c_id) {
            $stmt = $pdo->prepare("SELECT u.id, u.nom, u.prenom FROM utilisateurs u JOIN inscriptions_cours ic ON u.id = ic.etudiant_id WHERE ic.cours_id = ? ORDER BY u.nom ASC");
            $stmt->execute([$c_id]); $etudiants_de_la_session = $stmt->fetchAll();
            $stmt = $pdo->prepare("SELECT etudiant_id, statut_presence FROM presences WHERE session_cours_id = ?");
            $stmt->execute([$selected_session_id]); $presences_existantes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }
    }

    // EDT dynamique
    $stmt = $pdo->prepare("SELECT s.*, sa.nom_salle, c.nom_cours FROM sessions_cours s JOIN cours c ON s.cours_id = c.id JOIN salles sa ON s.salle_id = sa.id WHERE s.enseignant_id = ? ORDER BY s.date_cours, s.heure_debut");
    $stmt->execute([$enseignant_id]); $toutes_sessions_edt = $stmt->fetchAll();

    // Messagerie
    $stmt = $pdo->prepare("SELECT m.*, u.nom, u.prenom, u.role FROM messages m JOIN utilisateurs u ON m.expediteur_id = u.id WHERE m.destinataire_id = ? ORDER BY m.date_envoi DESC");
    $stmt->execute([$enseignant_id]); $mailsRecus = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT m.*, u.nom, u.prenom FROM messages m JOIN utilisateurs u ON m.destinataire_id = u.id WHERE m.expediteur_id = ? ORDER BY m.date_envoi DESC");
    $stmt->execute([$enseignant_id]); $mailsEnvoyes = $stmt->fetchAll();

    $listeContacts = $pdo->query("SELECT id, nom, prenom, role FROM utilisateurs WHERE id != $enseignant_id ORDER BY role DESC, nom ASC")->fetchAll();

} catch (PDOException $e) { die("Erreur de synchronisation SQL : " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>SmartCampus - Espace Enseignant</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bleu: #0A2240; --rouge: #D9383A; --fond: #F4F7FA; --bordure: #E2E8F0; --succes: #2F855A; --texte: #2D3748; }
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Segoe UI', sans-serif; color: var(--texte); }
        body { background: var(--fond); display: flex; min-height: 100vh; }

        .sidebar { width: 260px; background: var(--bleu); height: 100vh; position: fixed; padding: 20px; z-index: 10; }
        .sidebar h1 { font-size: 22px; text-align: center; color: white; margin-bottom: 30px; font-weight: 700; }
        .sidebar h1 span { color: var(--rouge); }
        .sidebar-menu { list-style: none; }
        .sidebar-menu li a { display: block; padding: 12px 15px; color: #A0AEC0; text-decoration: none; border-radius: 6px; cursor: pointer; font-weight: 600; margin-bottom: 6px; transition: 0.2s; }
        .sidebar-menu li a.active, .sidebar-menu li a:hover { background: #1A365D; color: white; border-left: 4px solid var(--rouge); }
        .logout-btn { display: flex; align-items: center; gap: 10px; color: #FC8181 !important; background: rgba(252,129,129,0.1); padding: 12px; border-radius: 6px; text-decoration: none; font-weight: 600; margin-top: 40px; }

        .main-content { margin-left: 260px; flex-grow: 1; padding: 30px; width: calc(100% - 260px); }
        .topbar-ent { background: white; border-radius: 12px; border: 1px solid var(--bordure); padding: 15px 30px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .avatar-circle { width: 40px; height: 40px; background: var(--bleu); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid var(--rouge); }
        .notif-bell { position: relative; cursor: pointer; font-size: 20px; color: var(--bleu); }
        .notif-badge { position: absolute; top: -5px; right: -5px; background: var(--rouge); color: white; font-size: 10px; padding: 2px 5px; border-radius: 50%; font-weight: bold; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .ent-card { background: white; border-radius: 12px; border: 1px solid var(--bordure); margin-bottom: 25px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .ent-card-header { background: var(--bleu); padding: 15px 20px; color: white !important; font-weight: 600; }
        .ent-card-header h3 { color: white !important; font-size: 15px; }
        .ent-card-body { padding: 20px; }

        .grid-home { display: grid; grid-template-columns: 1.6fr 1fr; gap: 25px; }
        .grid-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 25px; }
        .action-box { background: white; border: 1px solid var(--bordure); padding: 20px; border-radius: 10px; text-align: center; cursor: pointer; transition: 0.2s; }
        .action-box:hover { border-color: var(--rouge); transform: translateY(-2px); }
        .action-box i { font-size: 26px; color: var(--rouge); margin-bottom: 10px; }
        .action-box h4 { font-size: 13px; font-weight: 600; }

        .upcoming-row { display: flex; align-items: center; padding: 12px 0; border-bottom: 1px solid #EDF2F7; }
        .upcoming-row:last-child { border-bottom: none; }
        .upcoming-time { width: 90px; font-weight: 700; color: var(--rouge); font-size: 14px; }
        .upcoming-details { flex-grow: 1; }
        .upcoming-details strong { font-size: 13px; color: var(--bleu); }
        .upcoming-details small { color: #718096; font-size: 11px; }
        .upcoming-status { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #E2E8F0; color: #4A5568; }

        /* EMPLOI DU TEMPS */
        .week-nav-container { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .timetable-container { display: grid; grid-template-columns: 90px repeat(5, 1fr); background: white; border: 1px solid var(--bordure); border-radius: 8px; overflow: hidden; }
        .timetable-header { background: #F8FAFC; text-align: center; font-weight: bold; padding: 12px; border-bottom: 2px solid var(--bordure); color: var(--bleu); font-size: 13px; }
        .time-col { border-right: 1px solid var(--bordure); background: #F8FAFC; }
        .time-slot-label { height: 95px; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid var(--bordure); font-size: 11px; font-weight: 700; color: #718096; text-align: center; }
        .day-col { border-right: 1px solid var(--bordure); background: #FFF; display: flex; flex-direction: column; min-height: 400px; }
        .day-col:last-child { border-right: none; }
        .course-block { background: #EBF8FF; border-left: 4px solid var(--rouge); padding: 8px; font-size: 11px; margin: 4px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .btn-nav-edt { background: white; border: 1px solid var(--bordure); padding: 6px 14px; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 600; }

        /* TABLEAUX & FORMULAIRES */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid var(--bordure); text-align: left; font-size: 14px; }
        th { background: #F8FAFC; color: #718096; font-weight: 600; }
        .btn-p { background: var(--bleu); color: white; padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 600; cursor: pointer; border: none; display: inline-block; }
        .btn-s { background: var(--succes); color: white; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; }
        select, input[type="text"], textarea { width: 100%; padding: 10px; border: 1px solid var(--bordure); border-radius: 6px; font-size: 13px; }

        /* MESSAGERIE BOÎTE MAIL */
        .mail-giant-layout { display: grid; grid-template-columns: 240px 1fr; background: white; border: 1px solid var(--bordure); border-radius: 12px; min-height: 560px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .mail-sidebar-pane { background: #F8FAFC; border-right: 1px solid var(--bordure); padding: 20px; display: flex; flex-direction: column; gap: 8px; }
        .mail-folder-btn { display: flex; align-items: center; gap: 12px; padding: 12px 15px; border-radius: 6px; cursor: pointer; font-weight: 600; color: #4A5568; font-size: 14px; }
        .mail-folder-btn.active, .mail-folder-btn:hover { background: #E2E8F0; color: var(--bleu); }
        .mail-folder-compose { margin-top: 20px; background: var(--rouge); color: white !important; justify-content: center; border-radius: 6px; }
        .mail-folder-compose:hover { background: #B8282A !important; }
        .mail-body-pane { padding: 30px; background: white; overflow-y: auto; }
        .mail-box-view h3 { color: var(--bleu); margin-bottom: 18px; border-bottom: 2px solid var(--fond); padding-bottom: 10px; font-size: 16px; }
        .mail-row-item { padding: 15px; border-bottom: 1px solid #EDF2F7; }
        .mail-row-item:last-child { border-bottom: none; }
        .mail-badge { margin-left: auto; background: var(--rouge); color: white; font-size: 10px; font-weight: bold; border-radius: 50%; min-width: 18px; height: 18px; display: flex; align-items: center; justify-content: center; padding: 0 4px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 13px; color: #4A5568; }
        .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid var(--bordure); border-radius: 6px; font-size: 13px; }
    </style>
</head>
<body>

    <div class="sidebar">
        <h1>Smart<span>Campus</span></h1>
        <ul class="sidebar-menu">
            <li><a onclick="switchTab('dashboard')" id="btn-dashboard" class="active"><i class="fa-solid fa-home"></i> Accueil</a></li>
            <li><a onclick="switchTab('timetable')" id="btn-timetable"><i class="fa-solid fa-calendar-alt"></i> Emploi du Temps</a></li>
            <li><a onclick="switchTab('eleves')" id="btn-eleves"><i class="fa-solid fa-user-graduate"></i> Mes Élèves</a></li>
            <li><a onclick="switchTab('notes')" id="btn-notes"><i class="fa-solid fa-star"></i> Saisie des Notes</a></li>
            <li><a onclick="switchTab('presences')" id="btn-presences"><i class="fa-solid fa-user-check"></i> Feuilles d'Appel</a></li>
            <li><a onclick="switchTab('messages')" id="btn-messages"><i class="fa-solid fa-envelope"></i> Messagerie</a></li>
            <li><a href="deconnexion.php" class="logout-btn"><i class="fa-solid fa-power-off"></i> Déconnexion</a></li>
        </ul>
    </div>

    <div class="main-content">

        <?php if(!empty($msg_status)): ?>
            <div style="background:#C6F6D5; color:#22543D; padding:15px; border-radius:8px; margin-bottom:20px; font-weight:600; border-left:5px solid var(--succes);">
                <?php echo $msg_status; ?>
            </div>
        <?php endif; ?>

        <div class="topbar-ent">
            <h2>Portail Académique Enseignant</h2>
            <div style="display:flex; align-items:center; gap:20px;">
                <div class="notif-bell" onclick="switchTab('messages')">
                    <i class="fa-solid fa-bell"></i>
                    <span class="notif-badge"><?php echo count($mailsRecus); ?></span>
                </div>
                <div style="text-align:right;">
                    <h4 style="font-size:14px; font-weight:700;">M./Mme <?php echo htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']); ?></h4>
                    <small style="color:#718096;">Espace Enseignant</small>
                </div>
                <div class="avatar-circle"><?php echo $initiales; ?></div>
            </div>
        </div>

        <!-- DASHBOARD -->
        <div id="tab-dashboard" class="tab-content active">
            <div class="grid-home">
                <div>
                    <h3 style="margin-bottom:15px; font-size:16px;">Actions de gestion</h3>
                    <div class="grid-actions">
                        <div class="action-box" onclick="switchTab('presences')"><i class="fa-solid fa-clipboard-user"></i><h4>Faire l'appel</h4></div>
                        <div class="action-box" onclick="alert('QR Code d\'Émargement activé.')"><i class="fa-solid fa-qrcode"></i><h4>Lancer un QR Code</h4></div>
                        <div class="action-box" onclick="switchTab('notes')"><i class="fa-solid fa-marker"></i><h4>Saisir des notes</h4></div>
                    </div>
                    <div class="ent-card">
                        <div class="ent-card-header"><h3><i class="fa-solid fa-clock"></i> Mes cours à venir</h3></div>
                        <div class="ent-card-body" style="padding:10px 20px;">
                            <?php if(empty($cours_a_venir)): ?>
                                <p style="padding:20px; text-align:center; color:#A0AEC0; font-size:13px;">Aucun cours planifié prochainement.</p>
                            <?php else: ?>
                                <?php foreach($cours_a_venir as $cav): ?>
                                    <div class="upcoming-row">
                                        <div class="upcoming-time"><?php echo substr($cav['heure_debut'],0,5); ?></div>
                                        <div class="upcoming-details">
                                            <strong><?php echo htmlspecialchars($cav['nom_cours']); ?></strong><br>
                                            <small>Salle: <?php echo htmlspecialchars($cav['nom_salle']); ?> • Le <?php echo date('d/m/Y', strtotime($cav['date_cours'])); ?></small>
                                        </div>
                                        <span class="upcoming-status">Planifié</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div>
                    <div class="ent-card">
                        <div class="ent-card-header"><h3><i class="fa-solid fa-users"></i> Étudiants sous votre responsabilité</h3></div>
                        <div class="ent-card-body" style="max-height:430px; overflow-y:auto; padding:10px;">
                            <?php foreach(array_slice($mes_eleves, 0, 10) as $me): ?>
                                <div style="display:flex; align-items:center; padding:10px; border-bottom:1px solid #F7FAFC;">
                                    <span style="font-size:13px; font-weight:600; flex-grow:1;"><?php echo htmlspecialchars($me['nom'] . ' ' . $me['prenom']); ?></span>
                                    <a href="dashboard_enseignant.php?detail_eleve=<?php echo $me['id']; ?>" class="btn-p" style="padding:4px 8px; font-size:11px;">Consulter</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- EMPLOI DU TEMPS -->
        <div id="tab-timetable" class="tab-content">
            <div class="ent-card">
                <div class="ent-card-header"><h3><i class="fa-solid fa-calendar-week"></i> Emploi du Temps Hebdomadaire</h3></div>
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
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Lundi <span id="p_d0" style="font-size:11px; display:block; color:#718096;"></span></div><div id="p_day-box-1" style="padding:2px; flex-grow:1;"></div></div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Mardi <span id="p_d1" style="font-size:11px; display:block; color:#718096;"></span></div><div id="p_day-box-2" style="padding:2px; flex-grow:1;"></div></div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Mercredi <span id="p_d2" style="font-size:11px; display:block; color:#718096;"></span></div><div id="p_day-box-3" style="padding:2px; flex-grow:1;"></div></div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Jeudi <span id="p_d3" style="font-size:11px; display:block; color:#718096;"></span></div><div id="p_day-box-4" style="padding:2px; flex-grow:1;"></div></div>
                        <div class="day-col"><div class="timetable-header" style="height:45px;">Vendredi <span id="p_d4" style="font-size:11px; display:block; color:#718096;"></span></div><div id="p_day-box-5" style="padding:2px; flex-grow:1;"></div></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MES ÉLÈVES -->
        <div id="tab-eleves" class="tab-content">
            <div style="display:grid; grid-template-columns: 1fr 1.5fr; gap:25px;">
                <div class="ent-card">
                    <div class="ent-card-header"><h3>Liste des Étudiants Rattachés</h3></div>
                    <div class="ent-card-body" style="max-height:500px; overflow-y:auto; padding:10px;">
                        <table>
                            <tbody>
                                <?php foreach($mes_eleves as $el): ?>
                                    <tr style="<?php echo $selected_eleve_id === $el['id'] ? 'background:#EDF2F7;' : ''; ?>">
                                        <td><strong><?php echo htmlspecialchars($el['nom'] . ' ' . $el['prenom']); ?></strong><br><small style="color:#718096;"><?php echo htmlspecialchars($el['nom_promotion']); ?></small></td>
                                        <td><a href="dashboard_enseignant.php?detail_eleve=<?php echo $el['id']; ?>" class="btn-p" style="padding:6px 12px;"><i class="fa-solid fa-folder-open"></i> Ouvrir</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="ent-card">
                    <div class="ent-card-header"><h3>Suivi Académique Détaillé</h3></div>
                    <div class="ent-card-body">
                        <?php if($profil_eleve): ?>
                            <div style="display:flex; justify-content:space-between; border-bottom:2px solid var(--bordure); padding-bottom:15px; margin-bottom:20px;">
                                <div>
                                    <h3 style="color:var(--bleu); font-size:18px;"><?php echo htmlspecialchars($profil_eleve['nom'] . ' ' . $profil_eleve['prenom']); ?></h3>
                                    <small style="color:#718096; display:block; margin-top:3px;">ID : <code><?php echo htmlspecialchars($profil_eleve['numero_etudiant'] ?? 'N/A'); ?></code></small>
                                    <small style="color:#718096;">Classe : <?php echo htmlspecialchars($profil_eleve['nom_promotion']); ?></small>
                                </div>
                                <div style="text-align:center; background:var(--bleu); color:white; padding:10px 15px; border-radius:8px;">
                                    <span style="font-size:9px; display:block; color:#E2E8F0;">MOYENNE</span>
                                    <strong style="font-size:20px; color:white;"><?php echo number_format($moyenne_generale, 2); ?>/20</strong>
                                </div>
                            </div>
                            <h4 style="font-size:13px; margin-bottom:8px;"><i class="fa-solid fa-star"></i> Notes</h4>
                            <table style="margin-bottom:25px;">
                                <thead><tr><th>Module</th><th>Type</th><th>Libellé</th><th>Note</th></tr></thead>
                                <tbody>
                                    <?php if(empty($notes_eleve)): ?><tr><td colspan="4">Aucun relevé disponible.</td></tr><?php endif; ?>
                                    <?php foreach($notes_eleve as $ne): ?>
                                        <tr><td><?php echo htmlspecialchars($ne['nom_cours']); ?></td><td><strong><?php echo $ne['type_evaluation']; ?></strong></td><td><?php echo htmlspecialchars($ne['nom_evaluation']); ?></td><td><strong><?php echo number_format($ne['note_valeur'],2); ?>/20</strong></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <h4 style="color:var(--rouge); font-size:13px; margin-bottom:8px;"><i class="fa-solid fa-triangle-exclamation"></i> Absences</h4>
                            <table>
                                <thead><tr><th>Date</th><th>Module</th><th>Type</th></tr></thead>
                                <tbody>
                                    <?php if(empty($absences_eleve)): ?><tr><td colspan="3" style="color:var(--succes); font-weight:600;">Assiduité irréprochable.</td></tr><?php endif; ?>
                                    <?php foreach($absences_eleve as $ae): ?>
                                        <tr><td><?php echo date('d/m/Y', strtotime($ae['date_cours'])); ?></td><td><?php echo htmlspecialchars($ae['nom_cours']); ?></td><td><span style="color:white; background:var(--rouge); padding:3px 6px; border-radius:4px; font-size:11px; font-weight:700;"><?php echo $ae['statut_presence']; ?></span></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="text-align:center; color:#718096; padding:20px;">Sélectionnez un élève pour charger son dossier.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- SAISIE DES NOTES -->
        <div id="tab-notes" class="tab-content">

            <!-- ÉTAPE 1 : CONFIGURATION DE L'ÉVALUATION -->
            <div class="ent-card" id="notes-step1-card">
                <div class="ent-card-header">
                    <h3><i class="fa-solid fa-gear"></i> Étape 1 — Configurer l'évaluation</h3>
                </div>
                <div class="ent-card-body">
                    <form id="form-config-notes" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:18px; align-items:end;">

                        <!-- Matière -->
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:700; font-size:13px; color:var(--bleu);">
                                <i class="fa-solid fa-book" style="color:var(--rouge);"></i> Matière
                            </label>
                            <select id="cfg-cours" name="select_cours" required style="width:100%; padding:10px; border:2px solid var(--bordure); border-radius:8px; font-size:13px; font-weight:600;">
                                <option value="">-- Choisir une matière --</option>
                                <?php foreach($mes_cours as $mc): ?>
                                    <option value="<?php echo $mc['id']; ?>" <?php echo $selected_cours_id === $mc['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($mc['nom_cours']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Classe / Promotion -->
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:700; font-size:13px; color:var(--bleu);">
                                <i class="fa-solid fa-users" style="color:var(--rouge);"></i> Classe / Promotion
                            </label>
                            <select id="cfg-promo" name="promotion_id" style="width:100%; padding:10px; border:2px solid var(--bordure); border-radius:8px; font-size:13px; font-weight:600;">
                                <option value="0">Toutes les promotions</option>
                                <?php foreach($toutes_promotions as $tp): ?>
                                    <option value="<?php echo $tp['id']; ?>" <?php echo $selected_promo_id === $tp['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($tp['nom_promotion']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Type évaluation -->
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:700; font-size:13px; color:var(--bleu);">
                                <i class="fa-solid fa-list-check" style="color:var(--rouge);"></i> Type d'évaluation
                            </label>
                            <select id="cfg-type" style="width:100%; padding:10px; border:2px solid var(--bordure); border-radius:8px; font-size:13px; font-weight:600;">
                                <option value="CC">Contrôle Continu (CC)</option>
                                <option value="Partiel">Partiel</option>
                                <option value="Examen">Examen Final</option>
                                <option value="Rattrapage">Rattrapage</option>
                                <option value="TP">TP / Pratique</option>
                                <option value="Projet">Projet</option>
                            </select>
                        </div>

                        <!-- Nom de l'examen -->
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:700; font-size:13px; color:var(--bleu);">
                                <i class="fa-solid fa-tag" style="color:var(--rouge);"></i> Nom / Libellé de l'examen
                            </label>
                            <input type="text" id="cfg-nom" placeholder="Ex : CC1, Partiel S1, TP Réseau…" style="width:100%; padding:10px; border:2px solid var(--bordure); border-radius:8px; font-size:13px; font-weight:600;">
                        </div>

                        <!-- Coefficient -->
                        <div>
                            <label style="display:block; margin-bottom:6px; font-weight:700; font-size:13px; color:var(--bleu);">
                                <i class="fa-solid fa-scale-balanced" style="color:var(--rouge);"></i> Coefficient
                            </label>
                            <input type="number" id="cfg-coeff" value="1" min="0.5" max="10" step="0.5" style="width:100%; padding:10px; border:2px solid var(--bordure); border-radius:8px; font-size:13px; font-weight:600;">
                        </div>

                        <!-- Bouton charger -->
                        <div style="display:flex; align-items:flex-end;">
                            <button type="button" onclick="chargerListeEtudiants()" style="width:100%; background:var(--rouge); color:white; padding:11px 16px; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;">
                                <i class="fa-solid fa-users-line"></i> Charger la liste des élèves
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ÉTAPE 2 : SAISIE DES NOTES (apparaît après clic) -->
            <div class="ent-card" id="notes-step2-card" style="display:none;">
                <div class="ent-card-header" style="display:flex; justify-content:space-between; align-items:center;">
                    <h3><i class="fa-solid fa-pen-to-square"></i> Étape 2 — Saisir les notes</h3>
                    <div id="notes-recap-badge" style="background:rgba(255,255,255,0.15); padding:6px 14px; border-radius:20px; font-size:12px; font-weight:600; color:white;"></div>
                </div>
                <div class="ent-card-body" style="padding:0;">

                    <!-- Barre de résumé de la config -->
                    <div id="notes-config-summary" style="background:#EBF8FF; border-bottom:2px solid #BEE3F8; padding:14px 20px; display:flex; gap:20px; flex-wrap:wrap;">
                    </div>

                    <!-- Barre d'outils rapide -->
                    <div style="padding:12px 20px; background:#FAFAFA; border-bottom:1px solid var(--bordure); display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <span style="font-size:12px; color:#718096; font-weight:600;">Actions rapides :</span>
                        <button type="button" onclick="remplirToutesNotes()" style="background:#0A2240; color:white; border:none; padding:6px 12px; border-radius:5px; font-size:12px; cursor:pointer; font-weight:600;">
                            <i class="fa-solid fa-keyboard"></i> Remplissage rapide
                        </button>
                        <button type="button" onclick="viderToutesNotes()" style="background:#718096; color:white; border:none; padding:6px 12px; border-radius:5px; font-size:12px; cursor:pointer; font-weight:600;">
                            <i class="fa-solid fa-eraser"></i> Effacer tout
                        </button>
                        <span id="notes-compteur" style="margin-left:auto; font-size:12px; font-weight:700; color:var(--succes);"></span>
                    </div>

                    <form method="POST" id="form-notes-final" style="padding:20px;">
                        <input type="hidden" name="cours_id" id="hidden-cours-id">
                        <input type="hidden" name="type_evaluation" id="hidden-type-eval">
                        <input type="hidden" name="nom_evaluation" id="hidden-nom-eval">
                        <input type="hidden" name="coefficient" id="hidden-coeff">
                        <input type="hidden" name="promotion_id" id="hidden-promo-id">

                        <table id="table-notes" style="width:100%;">
                            <thead>
                                <tr style="background:#F8FAFC;">
                                    <th style="padding:12px; text-align:left; font-size:13px; color:#4A5568; width:40px;">#</th>
                                    <th style="padding:12px; text-align:left; font-size:13px; color:#4A5568;">Nom de l'étudiant</th>
                                    <th style="padding:12px; text-align:left; font-size:13px; color:#4A5568;">Promotion / Classe</th>
                                    <th style="padding:12px; text-align:center; font-size:13px; color:#4A5568; width:160px;">
                                        Note <span style="color:var(--rouge);">/20</span>
                                    </th>
                                    <th style="padding:12px; text-align:center; font-size:13px; color:#4A5568; width:90px;">Statut</th>
                                </tr>
                            </thead>
                            <tbody id="tbody-notes">
                                <!-- Rempli dynamiquement par JS -->
                            </tbody>
                        </table>

                        <!-- Bouton de soumission -->
                        <div style="display:flex; align-items:center; justify-content:space-between; margin-top:20px; padding-top:20px; border-top:2px solid var(--bordure);">
                            <button type="button" onclick="retourEtape1()" style="background:white; color:var(--bleu); border:2px solid var(--bleu); padding:12px 24px; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;">
                                <i class="fa-solid fa-arrow-left"></i> Modifier la configuration
                            </button>
                            <button type="submit" name="action_enregistrer_notes" style="background:var(--succes); color:white; border:none; padding:14px 30px; border-radius:8px; font-size:15px; font-weight:700; cursor:pointer;">
                                <i class="fa-solid fa-floppy-disk"></i> Enregistrer toutes les notes
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- RÉCAPITULATIF DES NOTES DÉJÀ SAISIES -->
            <?php if($selected_cours_id && !empty($notes_deja_publiees)): ?>
            <div class="ent-card" style="margin-top:10px;">
                <div class="ent-card-header"><h3><i class="fa-solid fa-table-list"></i> Notes déjà enregistrées pour cette matière</h3></div>
                <div class="ent-card-body" style="padding:0; overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse;">
                        <thead><tr style="background:#F8FAFC;">
                            <th style="padding:10px 14px; text-align:left; font-size:12px; color:#718096;">Étudiant</th>
                            <th style="padding:10px 14px; text-align:left; font-size:12px; color:#718096;">Évaluation</th>
                            <th style="padding:10px 14px; text-align:center; font-size:12px; color:#718096;">Type</th>
                            <th style="padding:10px 14px; text-align:center; font-size:12px; color:#718096;">Note</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach($notes_deja_publiees as $np): ?>
                                <tr style="border-bottom:1px solid var(--bordure);">
                                    <td style="padding:10px 14px; font-size:13px; font-weight:600;"><?php echo htmlspecialchars($np['nom'] . ' ' . $np['prenom']); ?></td>
                                    <td style="padding:10px 14px; font-size:13px;"><?php echo htmlspecialchars($np['nom_evaluation']); ?></td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <span style="background:#E2E8F0; color:#4A5568; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:700;"><?php echo $np['type_evaluation']; ?></span>
                                    </td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <?php $n = $np['note_valeur'];
                                        $col = $n >= 10 ? '#22543D' : '#742A2A';
                                        $bg  = $n >= 10 ? '#C6F6D5' : '#FED7D7'; ?>
                                        <span style="background:<?php echo $bg; ?>; color:<?php echo $col; ?>; padding:4px 10px; border-radius:12px; font-size:13px; font-weight:700;">
                                            <?php echo number_format($n, 2); ?>/20
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div>
        <!-- FIN SAISIE DES NOTES -->

        <!-- FEUILLES D'APPEL -->
        <div id="tab-presences" class="tab-content">
            <div class="ent-card">
                <div class="ent-card-header"><h3>Registre d'Émargement des Séances</h3></div>
                <div class="ent-card-body">
                    <form method="GET" style="margin-bottom:20px;">
                        <label style="display:block; margin-bottom:6px; font-weight:600; font-size:13px;">Sélectionner la séance pour l'appel</label>
                        <select name="select_session" onchange="this.form.submit()">
                            <option value="">-- Choisir une séance --</option>
                            <?php foreach($toutes_mes_sessions as $tms): ?>
                                <option value="<?php echo $tms['id']; ?>" <?php echo $selected_session_id === $tms['id'] ? 'selected' : ''; ?>>[<?php echo date('d/m/Y', strtotime($tms['date_cours'])); ?>] <?php echo htmlspecialchars($tms['nom_cours']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php if($selected_session_id && !empty($etudiants_de_la_session)): ?>
                        <form method="POST">
                            <input type="hidden" name="session_cours_id" value="<?php echo $selected_session_id; ?>">
                            <table>
                                <thead><tr><th>Étudiant</th><th>Statut de présence</th></tr></thead>
                                <tbody>
                                    <?php foreach($etudiants_de_la_session as $eds): $actuel = $presences_existantes[$eds['id']] ?? 'present'; ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($eds['nom'] . ' ' . $eds['prenom']); ?></strong></td>
                                            <td>
                                                <select name="statut_presence[<?php echo $eds['id']; ?>]" style="width:200px;">
                                                    <option value="present" <?php echo $actuel === 'present' ? 'selected' : ''; ?>>✅ Présent</option>
                                                    <option value="absent_injustifie" <?php echo $actuel === 'absent_injustifie' ? 'selected' : ''; ?>>❌ Absent Injustifié</option>
                                                    <option value="absent_justifie" <?php echo $actuel === 'absent_justifie' ? 'selected' : ''; ?>>📝 Absent Justifié</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <button type="submit" name="action_valider_presences" class="btn-s" style="margin-top:15px;">Figer la feuille d'appel</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- MESSAGERIE BOÎTE MAIL -->
        <div id="tab-messages" class="tab-content">
            <div class="mail-giant-layout">

                <!-- SIDEBAR DOSSIERS -->
                <div class="mail-sidebar-pane">
                    <div onclick="switchMailbox('recus')" id="folder-recus" class="mail-folder-btn active">
                        <i class="fa-solid fa-inbox"></i>
                        Boîte de réception
                        <?php if(count($mailsRecus) > 0): ?>
                            <span class="mail-badge"><?php echo count($mailsRecus); ?></span>
                        <?php endif; ?>
                    </div>
                    <div onclick="switchMailbox('envoyes')" id="folder-envoyes" class="mail-folder-btn">
                        <i class="fa-solid fa-paper-plane"></i>
                        Messages envoyés
                    </div>
                    <div onclick="switchMailbox('compos')" id="folder-compos" class="mail-folder-btn mail-folder-compose">
                        <i class="fa-solid fa-pen"></i>
                        Nouveau message
                    </div>
                </div>

                <!-- CORPS DE LA BOÎTE MAIL -->
                <div class="mail-body-pane">

                    <!-- MESSAGES REÇUS -->
                    <div id="box-recus" class="mail-box-view">
                        <h3><i class="fa-solid fa-inbox"></i> Boîte de réception</h3>
                        <?php if(empty($mailsRecus)): ?>
                            <p style="color:#718096; font-size:14px; padding:15px 0;">Votre boîte de réception est vide.</p>
                        <?php else: ?>
                            <?php foreach($mailsRecus as $mr): ?>
                                <div class="mail-row-item">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                        <small style="color:var(--rouge); font-weight:700;">
                                            <i class="fa-solid fa-user"></i>
                                            De : <?php echo htmlspecialchars($mr['nom'] . ' ' . $mr['prenom']); ?>
                                            <span style="background:#E2E8F0; color:#4A5568; padding:2px 6px; border-radius:4px; font-size:10px; margin-left:5px;"><?php echo strtoupper($mr['role']); ?></span>
                                        </small>
                                        <small style="color:#A0AEC0; font-size:11px;"><?php echo date('d/m/Y à H:i', strtotime($mr['date_envoi'])); ?></small>
                                    </div>
                                    <p style="background:#F8FAFC; padding:12px; border-radius:6px; font-size:14px; border-left:3px solid var(--bleu); line-height:1.6; color:#2D3748;">
                                        <?php echo htmlspecialchars($mr['contenu']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- MESSAGES ENVOYÉS -->
                    <div id="box-envoyes" class="mail-box-view" style="display:none;">
                        <h3><i class="fa-solid fa-paper-plane"></i> Messages envoyés</h3>
                        <?php if(empty($mailsEnvoyes)): ?>
                            <p style="color:#718096; font-size:14px; padding:15px 0;">Aucun message envoyé pour le moment.</p>
                        <?php else: ?>
                            <?php foreach($mailsEnvoyes as $me): ?>
                                <div class="mail-row-item">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                        <small style="color:#718096; font-weight:700;">
                                            <i class="fa-solid fa-arrow-right"></i>
                                            À : <?php echo htmlspecialchars($me['nom'] . ' ' . $me['prenom']); ?>
                                        </small>
                                        <small style="color:#A0AEC0; font-size:11px;"><?php echo date('d/m/Y à H:i', strtotime($me['date_envoi'])); ?></small>
                                    </div>
                                    <p style="background:#F8FAFC; padding:12px; border-radius:6px; font-size:14px; border-left:3px solid var(--succes); line-height:1.6; color:#2D3748;">
                                        <?php echo htmlspecialchars($me['contenu']); ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- COMPOSER UN MESSAGE -->
                    <div id="box-compos" class="mail-box-view" style="display:none;">
                        <h3><i class="fa-solid fa-pen-to-square"></i> Rédiger un message</h3>
                        <form method="POST">
                            <div class="form-group">
                                <label>Destinataire</label>
                                <select name="destinataire_id" required>
                                    <option value="">-- Sélectionner un contact --</option>
                                    <?php foreach($listeContacts as $lc): ?>
                                        <option value="<?php echo $lc['id']; ?>">[<?php echo strtoupper($lc['role']); ?>] <?php echo htmlspecialchars($lc['nom'] . ' ' . $lc['prenom']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="contenu_message" rows="8" required placeholder="Rédigez votre message ici..."></textarea>
                            </div>
                            <button type="submit" name="action_envoyer_mail" class="btn-p" style="width:100%; padding:12px; font-size:14px;">
                                <i class="fa-solid fa-share"></i> Envoyer le message
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <script>
        const sqlCoursesprof = <?php echo json_encode($toutes_sessions_edt); ?>;
        // Données pour la saisie des notes (étudiants pré-chargés côté serveur)
        const etudiantsDuCours = <?php echo json_encode($etudiants_du_cours); ?>;
        const selectedCoursPHP  = <?php echo $selected_cours_id ?? 'null'; ?>;
        const selectedPromoPHP  = <?php echo $selected_promo_id ?? 0; ?>;
        let currentWeekMondayProf = getMondayProf(new Date());

        function getMondayProf(d) {
            let date = new Date(d);
            let day = date.getDay();
            let diff = date.getDate() - day + (day === 0 ? -6 : 1);
            return new Date(date.setDate(diff));
        }
        function convertToISODateProf(d) {
            let month = '' + (d.getMonth() + 1), day = '' + d.getDate(), year = d.getFullYear();
            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;
            return [year, month, day].join('-');
        }
        function navigateWeek(direction) {
            currentWeekMondayProf.setDate(currentWeekMondayProf.getDate() + (direction * 7));
            renderWeekGridViewProf();
        }
        function renderWeekGridViewProf() {
            let endOfWeek = new Date(currentWeekMondayProf);
            endOfWeek.setDate(endOfWeek.getDate() + 4);
            document.getElementById('current-week-label').innerText = `Semaine du ${currentWeekMondayProf.getDate()}/${currentWeekMondayProf.getMonth()+1} au ${endOfWeek.getDate()}/${endOfWeek.getMonth()+1}/${endOfWeek.getFullYear()}`;
            for (let i = 1; i <= 5; i++) {
                let dayContainer = document.getElementById('p_day-box-' + i);
                let dayHeaderSpan = document.getElementById('p_d' + (i - 1));
                dayContainer.innerHTML = "";
                let targetDayObject = new Date(currentWeekMondayProf);
                targetDayObject.setDate(targetDayObject.getDate() + (i - 1));
                dayHeaderSpan.innerText = `${targetDayObject.getDate()}/${targetDayObject.getMonth()+1}`;
                let targetISODate = convertToISODateProf(targetDayObject);
                let filtered = sqlCoursesprof.filter(c => c.date_cours === targetISODate);
                if (filtered.length > 0) {
                    filtered.forEach(c => {
                        dayContainer.innerHTML += `
                            <div class="course-block">
                                <strong>${c.heure_debut.substring(0,5)} - ${c.heure_fin.substring(0,5)}</strong><br>
                                <span style="font-weight:600;">${c.nom_cours}</span><br>
                                <small style="color:var(--rouge); font-weight:600;"><i class="fa-solid fa-location-dot"></i> ${c.nom_salle}</small>
                            </div>`;
                    });
                } else {
                    dayContainer.innerHTML = "<div style='color:#A0AEC0; font-size:10px; text-align:center; margin-top:20px;'>Libre</div>";
                }
            }
        }

        function switchTab(name) {
            document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
            document.querySelectorAll('.sidebar-menu li a').forEach(b => b.classList.remove('active'));
            if (document.getElementById('tab-' + name)) document.getElementById('tab-' + name).style.display = 'block';
            if (document.getElementById('btn-' + name)) document.getElementById('btn-' + name).classList.add('active');
        }

        // ═══════════════════════════════════════════════
        // LOGIQUE SAISIE DES NOTES
        // ═══════════════════════════════════════════════

        function chargerListeEtudiants() {
            const coursId  = document.getElementById('cfg-cours').value;
            const promoId  = document.getElementById('cfg-promo').value;
            const typeEval = document.getElementById('cfg-type').value;
            const nomEval  = document.getElementById('cfg-nom').value.trim();
            const coeff    = document.getElementById('cfg-coeff').value;
            const coursText = document.getElementById('cfg-cours').options[document.getElementById('cfg-cours').selectedIndex]?.text;
            const promoText = document.getElementById('cfg-promo').options[document.getElementById('cfg-promo').selectedIndex]?.text;

            if (!coursId) { alert('Veuillez sélectionner une matière.'); return; }
            if (!nomEval) { alert('Veuillez renseigner un libellé pour l\'évaluation.'); return; }

            // Si on a déjà les étudiants chargés côté PHP pour ce cours, on les utilise directement
            if (selectedCoursPHP && parseInt(coursId) === selectedCoursPHP) {
                let filtered = etudiantsDuCours;
                if (promoId > 0) {
                    // On filtre en JS si la promo est choisie et que les données sont déjà là
                    filtered = etudiantsDuCours.filter(e => true); // déjà filtré côté PHP
                }
                afficherStep2(filtered, coursId, promoId, typeEval, nomEval, coeff, coursText, promoText);
            } else {
                // Recharge la page avec les bons paramètres pour que PHP charge les étudiants
                window.location.href = 'dashboard_enseignant.php?select_cours=' + coursId + '&promotion_id=' + promoId + '&tab=notes';
            }
        }

        function afficherStep2(etudiants, coursId, promoId, typeEval, nomEval, coeff, coursText, promoText) {
            // Remplir les hidden inputs
            document.getElementById('hidden-cours-id').value  = coursId;
            document.getElementById('hidden-type-eval').value = typeEval;
            document.getElementById('hidden-nom-eval').value  = nomEval;
            document.getElementById('hidden-coeff').value     = coeff;
            document.getElementById('hidden-promo-id').value  = promoId;

            // Résumé de config
            const summary = document.getElementById('notes-config-summary');
            const badge   = document.getElementById('notes-recap-badge');
            summary.innerHTML = `
                <span style="font-size:13px; color:#2B6CB0;"><strong style="color:var(--bleu);">📚 Matière :</strong> ${coursText}</span>
                <span style="font-size:13px; color:#2B6CB0;"><strong style="color:var(--bleu);">🏫 Classe :</strong> ${promoText}</span>
                <span style="font-size:13px; color:#2B6CB0;"><strong style="color:var(--bleu);">📋 Type :</strong> ${typeEval}</span>
                <span style="font-size:13px; color:#2B6CB0;"><strong style="color:var(--bleu);">🏷️ Libellé :</strong> ${nomEval}</span>
                <span style="font-size:13px; color:#2B6CB0;"><strong style="color:var(--bleu);">⚖️ Coefficient :</strong> ${coeff}</span>
            `;
            badge.textContent = etudiants.length + ' étudiant(s)';

            // Remplir le tableau
            const tbody = document.getElementById('tbody-notes');
            tbody.innerHTML = '';
            if (etudiants.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="padding:30px; text-align:center; color:#718096;">Aucun étudiant trouvé pour cette sélection.</td></tr>';
            } else {
                etudiants.forEach((e, idx) => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid var(--bordure)';
                    tr.innerHTML = `
                        <td style="padding:10px 12px; color:#A0AEC0; font-size:12px; font-weight:700;">${idx+1}</td>
                        <td style="padding:10px 12px;">
                            <strong style="font-size:14px; color:var(--bleu);">${e.nom} ${e.prenom}</strong>
                        </td>
                        <td style="padding:10px 12px; font-size:13px; color:#718096;">${e.nom_promotion || ''}</td>
                        <td style="padding:10px 12px; text-align:center;">
                            <input type="number" name="notes[${e.id}]"
                                min="0" max="20" step="0.25"
                                placeholder="—"
                                oninput="updateStatutNote(this)"
                                onblur="clampNote(this)"
                                style="width:90px; padding:8px; text-align:center; font-size:15px; font-weight:700;
                                       border:2px solid #CBD5E0; border-radius:8px; outline:none; transition:0.2s;
                                       background:white; color:var(--bleu);"
                                onfocus="this.style.borderColor='var(--rouge)'"
                            >
                        </td>
                        <td style="padding:10px 12px; text-align:center;" id="statut-${e.id}">
                            <span style="color:#CBD5E0; font-size:11px;">—</span>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            }

            // Affichage
            document.getElementById('notes-step1-card').style.display = 'block';
            document.getElementById('notes-step2-card').style.display = 'block';
            document.getElementById('notes-step2-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
            mettreAJourCompteur();
        }

        function updateStatutNote(input) {
            const val = parseFloat(input.value);
            const etudiantId = input.name.match(/\[(\d+)\]/)?.[1];
            const statutCell = document.getElementById('statut-' + etudiantId);
            if (!statutCell) return;
            if (isNaN(val) || input.value === '') {
                statutCell.innerHTML = '<span style="color:#CBD5E0; font-size:11px;">—</span>';
                input.style.borderColor = '#CBD5E0';
            } else if (val >= 10) {
                statutCell.innerHTML = '<span style="background:#C6F6D5; color:#22543D; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:700;">✅ Admis</span>';
                input.style.borderColor = '#38A169';
                input.style.background = '#F0FFF4';
            } else {
                statutCell.innerHTML = '<span style="background:#FED7D7; color:#742A2A; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:700;">❌ Ajourné</span>';
                input.style.borderColor = '#E53E3E';
                input.style.background = '#FFF5F5';
            }
            mettreAJourCompteur();
        }

        function clampNote(input) {
            let val = parseFloat(input.value);
            if (!isNaN(val)) {
                if (val < 0) input.value = 0;
                if (val > 20) input.value = 20;
            }
        }

        function mettreAJourCompteur() {
            const inputs = document.querySelectorAll('#tbody-notes input[type="number"]');
            let remplis = 0;
            inputs.forEach(i => { if (i.value !== '') remplis++; });
            const el = document.getElementById('notes-compteur');
            if (el) el.textContent = remplis + ' / ' + inputs.length + ' notes saisies';
        }

        function remplirToutesNotes() {
            const note = prompt('Note rapide à attribuer à tous les étudiants (0–20) :', '');
            if (note === null || note === '') return;
            const val = parseFloat(note.replace(',', '.'));
            if (isNaN(val) || val < 0 || val > 20) { alert('Note invalide. Entrez un nombre entre 0 et 20.'); return; }
            document.querySelectorAll('#tbody-notes input[type="number"]').forEach(inp => {
                inp.value = val;
                updateStatutNote(inp);
            });
        }

        function viderToutesNotes() {
            if (!confirm('Effacer toutes les notes saisies ?')) return;
            document.querySelectorAll('#tbody-notes input[type="number"]').forEach(inp => {
                inp.value = '';
                inp.style.borderColor = '#CBD5E0';
                inp.style.background = 'white';
                const etudiantId = inp.name.match(/\[(\d+)\]/)?.[1];
                const statutCell = document.getElementById('statut-' + etudiantId);
                if (statutCell) statutCell.innerHTML = '<span style="color:#CBD5E0; font-size:11px;">—</span>';
            });
            mettreAJourCompteur();
        }

        function retourEtape1() {
            document.getElementById('notes-step2-card').style.display = 'none';
            document.getElementById('notes-step1-card').scrollIntoView({ behavior: 'smooth' });
        }

        function switchMailbox(name) {
            document.querySelectorAll('.mail-box-view').forEach(b => b.style.display = 'none');
            document.querySelectorAll('.mail-sidebar-pane .mail-folder-btn').forEach(f => f.classList.remove('active'));
            document.getElementById('box-' + name).style.display = 'block';
            document.getElementById('folder-' + name).classList.add('active');
        }

        window.onload = function() {
            renderWeekGridViewProf();
            <?php if($active_tab !== 'dashboard'): ?>
                switchTab('<?php echo $active_tab; ?>');
                <?php if($active_tab === 'messages'): ?>
                    switchMailbox('<?php echo $active_mailbox; ?>');
                <?php endif; ?>
            <?php endif; ?>

            // Si des étudiants sont déjà chargés (POST ou GET avec select_cours), afficher step2
            <?php if($selected_cours_id && !empty($etudiants_du_cours)): ?>
            (function() {
                const coursSelect = document.getElementById('cfg-cours');
                const promoSelect = document.getElementById('cfg-promo');
                if (coursSelect) coursSelect.value = '<?php echo $selected_cours_id; ?>';
                if (promoSelect) promoSelect.value = '<?php echo $selected_promo_id; ?>';
                // On pré-sélectionne les champs type/nom si venant d'un POST
                <?php if(isset($_POST['type_evaluation'])): ?>
                document.getElementById('cfg-type').value = '<?php echo htmlspecialchars($_POST['type_evaluation']); ?>';
                document.getElementById('cfg-nom').value  = '<?php echo htmlspecialchars($_POST['nom_evaluation'] ?? ''); ?>';
                document.getElementById('cfg-coeff').value= '<?php echo htmlspecialchars($_POST['coefficient'] ?? '1'); ?>';
                <?php endif; ?>
                afficherStep2(
                    etudiantsDuCours,
                    '<?php echo $selected_cours_id; ?>',
                    '<?php echo $selected_promo_id; ?>',
                    document.getElementById('cfg-type').value,
                    document.getElementById('cfg-nom').value || 'À compléter',
                    document.getElementById('cfg-coeff').value,
                    coursSelect ? coursSelect.options[coursSelect.selectedIndex]?.text : 'Matière sélectionnée',
                    promoSelect ? promoSelect.options[promoSelect.selectedIndex]?.text : 'Toutes'
                );
            })();
            <?php endif; ?>
        };
    </script>
</body>
</html>