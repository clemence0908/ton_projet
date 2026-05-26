<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// --- SÉCURITÉ & VÉRIFICATION DES ACCÈS (Spécification 5.2) ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'enseignant') {
    header('Location: login.php'); exit();
}
$enseignant_id = $_SESSION['user_id'];

$msg_status = "";
$active_tab = "dashboard";
$selected_cours_id = null;
$selected_session_id = null;

// --- TRAITEMENT DES ACTIONS PÉDAGOGIQUES (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Saisie / Modification des notes d'un cours (Spécification 5.8)
    if (isset($_POST['action_enregistrer_notes'])) {
        $selected_cours_id = intval($_POST['cours_id']);
        $type_eval = $_POST['type_evaluation']; // 'CC' ou 'Examen'
        $active_tab = "notes";
        
        if (!empty($_POST['notes'])) {
            try {
                $pdo->beginTransaction();
                foreach ($_POST['notes'] as $etudiant_id_cle => $valeur_note) {
                    if ($valeur_note === "") continue; // Ignore les champs vides
                    $note_float = floatval(str_replace(',', '.', $valeur_note));
                    
                    // Règle métier : Vérification de la validité de la note (0 à 20)
                    if ($note_float < 0 || $note_float > 20) continue;

                    // Vérification si la note existe déjà pour ce type d'évaluation
                    $stmtCheck = $pdo->prepare("SELECT id FROM notes WHERE etudiant_id = ? AND cours_id = ? AND type_evaluation = ?");
                    $stmtCheck->execute([$etudiant_id_cle, $selected_cours_id, $type_eval]);
                    $existe = $stmtCheck->fetch();

                    if ($existe) {
                        // Mise à jour (Si non verrouillé par l'admin, règle métier 5.6)
                        $stmtUp = $pdo->prepare("UPDATE notes SET note_valeur = ? WHERE id = ?");
                        $stmtUp->execute([$note_float, $existe['id']]);
                    } else {
                        // Création d'une nouvelle entrée
                        $stmtIns = $pdo->prepare("INSERT INTO notes (etudiant_id, cours_id, note_valeur, type_evaluation) VALUES (?, ?, ?, ?)");
                        $stmtIns->execute([$etudiant_id_cle, $selected_cours_id, $note_float, $type_eval]);
                    }
                }
                $pdo->commit();
                $msg_status = "✅ Notes enregistrées avec succès pour l'évaluation : " . $type_eval;
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg_status = "❌ Erreur lors de la sauvegarde des notes : " . $e->getMessage();
            }
        }
    }

    // 2. Enregistrement de la feuille d'émargement / Présences (Spécification 5.10)
    if (isset($_POST['action_valider_presences'])) {
        $selected_session_id = intval($_POST['session_cours_id']);
        $active_tab = "presences";
        
        if (isset($_POST['statut_presence'])) {
            try {
                $pdo->beginTransaction();
                foreach ($_POST['statut_presence'] as $etudiant_id_cle => $statut) {
                    $stmtCheck = $pdo->prepare("SELECT id FROM presences WHERE session_cours_id = ? AND etudiant_id = ?");
                    $stmtCheck->execute([$selected_session_id, $etudiant_id_cle]);
                    
                    if ($stmtCheck->fetch()) {
                        $pdo->prepare("UPDATE presences SET statut_presence = ? WHERE session_cours_id = ? AND etudiant_id = ?")
                            ->execute([$statut, $selected_session_id, $etudiant_id_cle]);
                    } else {
                        $pdo->prepare("INSERT INTO presences (session_cours_id, etudiant_id, statut_presence) VALUES (?, ?, ?)")
                            ->execute([$selected_session_id, $etudiant_id_cle, $statut]);
                    }
                }
                $pdo->commit();
                $msg_status = "✅ Feuille d'émargement mise à jour avec succès.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg_status = "❌ Erreur lors de la mise à jour des présences.";
            }
        }
    }

    // 3. Envoi d'un message aux étudiants / admins (Spécification 5.12)
    if (isset($_POST['action_envoyer_message'])) {
        $dest_id = intval($_POST['destinataire_id']);
        $contenu = trim($_POST['contenu_message']);
        $active_tab = "messages";

        if (!empty($contenu) && $dest_id > 0) {
            try {
                $stmt = $pdo->prepare("INSERT INTO messages (expediteur_id, destinataire_id, contenu, date_envoi) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$enseignant_id, $dest_id, $contenu]);
                $msg_status = "✅ Message envoyé avec succès.";
            } catch (PDOException $e) { 
                $msg_status = "❌ Échec de l'envoi du message."; 
            }
        }
    }
}

// --- RECUPÉRATION DES DONNÉES DEPUIS LA BDD REELLE ---
try {
    // Infos du professeur connecté
    $stmt = $pdo->prepare("SELECT nom, prenom FROM utilisateurs WHERE id = ?");
    $stmt->execute([$enseignant_id]); $prof = $stmt->fetch();
    $initiales = strtoupper(substr($prof['prenom'] ?? 'P', 0, 1) . substr($prof['nom'] ?? 'F', 0, 1));

    // 1. Liste des cours attribués à cet enseignant (Spécification 5.4 & 5.5)
    $stmt = $pdo->prepare("SELECT * FROM cours WHERE enseignant_id = ? ORDER BY nom_cours");
    $stmt->execute([$enseignant_id]); $mes_cours = $stmt->fetchAll();

    // 2. Liste de toutes les sessions de cours à venir / passées (pour l'appel)
    $stmt = $pdo->prepare("SELECT s.*, c.nom_cours, sa.nom_salle FROM sessions_cours s JOIN cours c ON s.cours_id = c.id JOIN salles sa ON s.salle_id = sa.id WHERE s.enseignant_id = ? ORDER BY s.date_cours DESC, s.heure_debut DESC");
    $stmt->execute([$enseignant_id]); $mes_sessions = $stmt->fetchAll();

    // 3. Données de saisie dynamique si un cours ou une session est sélectionné(e) via GET/POST
    if (isset($_GET['select_cours'])) { $selected_cours_id = intval($_GET['select_cours']); $active_tab = "notes"; }
    if (isset($_GET['select_session'])) { $selected_session_id = intval($_GET['select_session']); $active_tab = "presences"; }

    $etudiants_du_cours = [];
    if ($selected_cours_id) {
        // Récupère les étudiants inscrits à ce cours spécifique (Spécification 5.7)
        $stmt = $pdo->prepare("SELECT u.id, u.nom, u.prenom, p.nom_promotion FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id JOIN promotions p ON e.promotion_id = p.id JOIN inscriptions i ON e.utilisateur_id = i.etudiant_id WHERE i.cours_id = ? ORDER BY u.nom");
        $stmt->execute([$selected_cours_id]); $etudiants_du_cours = $stmt->fetchAll();
    }

    $etudiants_de_la_session = []; $presences_existantes = [];
    if ($selected_session_id) {
        // Trouve le cours lié à cette session pour avoir la liste des étudiants inscrits
        $stmt = $pdo->prepare("SELECT cours_id FROM sessions_cours WHERE id = ?");
        $stmt->execute([$selected_session_id]);
        $c_id = $stmt->fetchColumn();
        
        if ($c_id) {
            $stmt = $pdo->prepare("SELECT u.id, u.nom, u.prenom FROM utilisateurs u JOIN inscriptions i ON u.id = i.etudiant_id WHERE i.cours_id = ? ORDER BY u.nom");
            $stmt->execute([$c_id]); $etudiants_de_la_session = $stmt->fetchAll();
            
            $stmt = $pdo->prepare("SELECT etudiant_id, statut_presence FROM presences WHERE session_cours_id = ?");
            $stmt->execute([$selected_session_id]);
            $presences_existantes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Génère un tableau associatif [id_etudiant => statut]
        }
    }

    // 4. Messagerie Interne
    $stmt = $pdo->prepare("SELECT m.*, u.nom, u.prenom FROM messages m JOIN utilisateurs u ON m.expediteur_id = u.id WHERE m.destinataire_id = ? ORDER BY m.date_envoi DESC");
    $stmt->execute([$enseignant_id]); $messagesRecus = $stmt->fetchAll();

    // Liste complète des étudiants et des admins pour pouvoir leur écrire
    $listeContacts = $pdo->query("SELECT id, nom, prenom, role FROM utilisateurs WHERE role IN ('etudiant', 'admin') ORDER BY role DESC, nom ASC")->fetchAll();

} catch (PDOException $e) { die("Erreur SQL critique : " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Espace Enseignant - SmartCampus</title>
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
        .topbar-ent { background: white; border-radius: 12px; border: 1px solid var(--bordure); padding: 15px 30px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .topbar-ent h2 { font-size: 22px; color: var(--bleu); font-weight: 700; }

        .user-profile-badge { display: flex; align-items: center; gap: 12px; }
        .user-info-text { text-align: right; }
        .user-info-text h4 { font-size: 15px; color: var(--bleu); font-weight: 700; }
        .avatar-circle { width: 40px; height: 40px; background: var(--bleu); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid var(--rouge); }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .ent-card { background: white; border-radius: 8px; border: 1px solid var(--bordure); margin-bottom: 25px; box-shadow: 0 2px 5px rgba(0,0,0,0.01); overflow: hidden; }
        .ent-card-header { background: var(--bleu); padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; }
        .ent-card-header h3 { color: white !important; font-size: 16px; font-weight: 600; }
        .ent-card-body { padding: 22px; }

        table.global-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.global-table th, table.global-table td { padding: 12px; border-bottom: 1px solid var(--bordure); text-align: left; font-size: 14px; }
        table.global-table th { background: #F8FAFC; color: #4A5568; font-weight: 600; }

        .btn-action-primary { background: var(--bleu); color: white; border: none; padding: 10px 18px; border-radius: 4px; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; font-size: 13px; }
        .btn-action-success { background: var(--succes); color: white; border: none; padding: 10px 18px; border-radius: 4px; font-weight: bold; cursor: pointer; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 14px; }
        .form-group select, .form-group textarea, .form-group input { width: 100%; padding: 10px; border: 1px solid var(--bordure); border-radius: 4px; font-size: 14px; }
        
        .input-note { width: 80px !important; text-align: center; font-weight: bold; color: var(--bleu); }
        
        .grid-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-box { background: white; border: 1px solid var(--bordure); border-radius: 8px; padding: 20px; display: flex; align-items: center; gap: 15px; }
        .stat-icon { width: 50px; height: 50px; border-radius: 8px; background: #EBF8FF; color: #2B6CB0; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .stat-info h5 { font-size: 13px; color: #718096; }
        .stat-info p { font-size: 22px; font-weight: 700; color: var(--bleu); }
    </style>
</head>
<body>

    <div class="sidebar">
        <h1>Smart<span>Campus</span></h1>
        <ul class="sidebar-menu">
            <li><a onclick="switchTab('dashboard')" id="btn-dashboard" class="<?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>"><i class="fa-solid fa-home"></i> Vue d'ensemble</a></li>
            <li><a onclick="switchTab('notes')" id="btn-notes" class="<?php echo $active_tab == 'notes' ? 'active' : ''; ?>"><i class="fa-solid fa-graduation-cap"></i> Saisie des Notes</a></li>
            <li><a onclick="switchTab('presences')" id="btn-presences" class="<?php echo $active_tab == 'presences' ? 'active' : ''; ?>"><i class="fa-solid fa-user-check"></i> Suivi des Présences</a></li>
            <li><a onclick="switchTab('messages')" id="btn-messages" class="<?php echo $active_tab == 'messages' ? 'active' : ''; ?>"><i class="fa-solid fa-envelope"></i> Messagerie Interne</a></li>
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
            <div><h2>Espace Enseignant & Pédagogique</h2></div>
            <div class="user-profile-badge">
                <div class="user-info-text">
                    <h4>M./Mme <?php echo htmlspecialchars($prof['prenom'] . ' ' . $prof['nom']); ?></h4>
                    <small>Corps Enseignant titulaire</small>
                </div>
                <div class="avatar-circle"><?php echo $initiales; ?></div>
            </div>
        </div>

        <div id="tab-dashboard" class="tab-content <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>">
            <div class="grid-stats">
                <div class="stat-box">
                    <div class="stat-icon"><i class="fa-solid fa-book"></i></div>
                    <div class="stat-info"><h5>Mes Modules d'Enseignement</h5><p><?php echo count($mes_cours); ?></p></div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="background:#E6FFFA; color:#2C7A7B;"><i class="fa-solid fa-calendar-check"></i></div>
                    <div class="stat-info"><h5>Séances programmées</h5><p><?php echo count($mes_sessions); ?></p></div>
                </div>
                <div class="stat-box">
                    <div class="stat-icon" style="background:#FFF5F5; color:#C53030;"><i class="fa-solid fa-inbox"></i></div>
                    <div class="stat-info"><h5>Messages universitaires</h5><p><?php echo count($messagesRecus); ?></p></div>
                </div>
            </div>

            <div class="ent-card">
                <div class="ent-card-header"><h3>Mes cours & assignations académiques</h3></div>
                <div class="ent-card-body">
                    <table class="global-table">
                        <thead>
                            <tr><th>Nom du module</th><th>Sélectionner pour saisir les notes</th></tr>
                        </thead>
                        <tbody>
                            <?php if(empty($mes_cours)): ?>
                                <tr><td colspan="2" style="color:#718096;">Aucun cours ne vous a encore été rattaché par l'administration.</td></tr>
                            <?php else: ?>
                                <?php foreach($mes_cours as $c): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($c['nom_cours']); ?></strong></td>
                                        <td><a href="dashboard_enseignant.php?select_cours=<?php echo $c['id']; ?>" class="btn-action-primary"><i class="fa-solid fa-pen-to-square"></i> Ouvrir le carnet de notes</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="tab-notes" class="tab-content <?php echo $active_tab == 'notes' ? 'active' : ''; ?>">
            <div class="ent-card">
                <div class="ent-card-header"><h3>Saisie des notes par examen</h3></div>
                <div class="ent-card-body">
                    <form action="dashboard_enseignant.php" method="GET" style="margin-bottom: 20px; display:flex; gap:15px; align-items:flex-end;">
                        <div class="form-group" style="flex-grow: 1; margin-bottom: 0;">
                            <label>Veuillez choisir un de vos cours</label>
                            <select name="select_cours" required onchange="this.form.submit()">
                                <option value="">-- Choisir un module --</option>
                                <?php foreach($mes_cours as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $selected_cours_id === $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['nom_cours']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <?php if($selected_cours_id && !empty($etudiants_du_cours)): ?>
                        <form action="dashboard_enseignant.php" method="POST">
                            <input type="hidden" name="cours_id" value="<?php echo $selected_cours_id; ?>">
                            
                            <div class="form-group" style="width: 250px;">
                                <label>Nature de l'évaluation</label>
                                <select name="type_evaluation" required>
                                    <option value="CC">Contrôle Continu (CC)</option>
                                    <option value="Examen">Examen National / Partiel</option>
                                </select>
                            </div>

                            <table class="global-table">
                                <thead>
                                    <tr><th>Nom de l'étudiant</th><th>Classe / Promotion</th><th>Note sur /20</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach($etudiants_du_cours as $etd): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($etd['nom'] . ' ' . $etd['prenom']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($etd['nom_promotion']); ?></td>
                                            <td>
                                                <input type="text" name="notes[<?php echo $etd['id']; ?>]" class="form-group input-note" placeholder="--" maxlength="5">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div style="margin-top:20px;">
                                <button type="submit" name="action_enregistrer_notes" class="btn-action-success"><i class="fa-solid fa-floppy-disk"></i> Publier & Verrouiller la grille de notes</button>
                            </div>
                        </form>
                    <?php elseif($selected_cours_id): ?>
                        <p style="color:#718096; padding:15px;">Aucun étudiant n'est inscrit à ce cours actuellement.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="tab-presences" class="tab-content <?php echo $active_tab == 'presences' ? 'active' : ''; ?>">
            <div class="ent-card">
                <div class="ent-card-header"><h3>Registre d'émargement et d'assiduité</h3></div>
                <div class="ent-card-body">
                    <form action="dashboard_enseignant.php" method="GET" style="margin-bottom:20px;">
                        <div class="form-group">
                            <label>Sélectionnez la séance de cours cible</label>
                            <select name="select_session" required onchange="this.form.submit()">
                                <option value="">-- Choisir une séance planifiée --</option>
                                <?php foreach($mes_sessions as $sess): ?>
                                    <option value="<?php echo $sess['id']; ?>" <?php echo $selected_session_id === $sess['id'] ? 'selected' : ''; ?>>
                                        [<?php echo date('d/m/Y', strtotime($sess['date_cours'])); ?> — <?php echo substr($sess['heure_debut'], 0, 5); ?>] <?php echo htmlspecialchars($sess['nom_cours']); ?> (<?php echo htmlspecialchars($sess['nom_salle']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <?php if($selected_session_id && !empty($etudiants_de_la_session)): ?>
                        <form action="dashboard_enseignant.php" method="POST">
                            <input type="hidden" name="session_cours_id" value="<?php echo $selected_session_id; ?>">
                            
                            <table class="global-table">
                                <thead>
                                    <tr><th>Étudiant</th><th>Statut de présence</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach($etudiants_de_la_session as $etd): 
                                        $current_status = $presences_existantes[$etd['id']] ?? 'present';
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($etd['nom'] . ' ' . $etd['prenom']); ?></strong></td>
                                            <td>
                                                <select name="statut_presence[<?php echo $etd['id']; ?>]" style="width:200px;">
                                                    <option value="present" <?php echo $current_status == 'present' ? 'selected' : ''; ?>>✅ Présent</option>
                                                    <option value="absent_injustifie" <?php echo $current_status == 'absent_injustifie' ? 'selected' : ''; ?>>❌ Absent Injustifié</option>
                                                    <option value="absent_justifie" <?php echo $current_status == 'absent_justifie' ? 'selected' : ''; ?>>📝 Absent Justifié</option>
                                                    <option value="retard" <?php echo $current_status == 'retard' ? 'selected' : ''; ?>>⏱ Retard</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <div style="margin-top: 20px;">
                                <button type="submit" name="action_valider_presences" class="btn-action-success"><i class="fa-solid fa-check-double"></i> Enregistrer la feuille d'appel</button>
                            </div>
                        </form>
                    <?php elseif($selected_session_id): ?>
                        <p style="color:#718096; padding:15px;">Aucun étudiant n'est rattaché à ce module d'enseignement.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="tab-messages" class="tab-content <?php echo $active_tab == 'messages' ? 'active' : ''; ?>">
            <div style="display:grid; grid-template-columns: 1.2fr 1fr; gap:25px;">
                <div class="ent-card">
                    <div class="ent-card-header"><h3>Boîte de réception universitaire</h3></div>
                    <div class="ent-card-body" style="max-height: 500px; overflow-y: auto;">
                        <?php if(empty($messagesRecus)): ?>
                            <p style="color:#718096; padding:15px;">Aucun message reçu.</p>
                        <?php else: ?>
                            <?php foreach($messagesRecus as $mr): ?>
                                <div style="padding:15px; border-bottom:1px solid #EDF2F7;">
                                    <small style="color:var(--rouge); font-weight:bold;">De : <?php echo htmlspecialchars($mr['nom'] . ' ' . $mr['prenom']); ?> — Le <?php echo date('d/m/Y H:i', strtotime($mr['date_envoi'])); ?></small>
                                    <p style="margin-top:5px; background:#F8FAFC; padding:12px; border-radius:4px; font-size:14px; border-left:3px solid var(--bleu);"><?php echo htmlspecialchars($mr['contenu']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ent-card">
                    <div class="ent-card-header"><h3>Diffuser un message interne</h3></div>
                    <div class="ent-card-body">
                        <form action="dashboard_enseignant.php" method="POST">
                            <div class="form-group">
                                <label>Destinataire (Étudiant ou Administrateur)</label>
                                <select name="destinataire_id" required>
                                    <option value="">-- Sélectionner l'interlocuteur --</option>
                                    <?php foreach($listeContacts as $ct): ?>
                                        <option value="<?php echo $ct['id']; ?>">[<?php echo strtoupper($ct['role']); ?>] M./Mme <?php echo htmlspecialchars($ct['nom'] . ' ' . $ct['prenom']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Message</label>
                                <textarea name="contenu_message" rows="6" required placeholder="Écrivez vos instructions ou annonces académiques..."></textarea>
                            </div>
                            <button type="submit" name="action_envoyer_message" class="btn-action-primary" style="width:100%;"><i class="fa-solid fa-paper-plane"></i> Transmettre le message</button>
                        </form>
                    </div>
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
    </script>
</body>
</html>