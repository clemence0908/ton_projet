<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// PROTECTION DE LA ROUTE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'enseignant') {
    header('Location: login.php'); exit();
}
$enseignant_id = $_SESSION['user_id'];

$msg_status = "";
$active_tab = "dashboard";
$selected_cours_id = null;
$selected_session_id = null;

// --- SOUMISSIONS FORMULAIRES (NOTES & MESSAGERIE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_valider_note'])) {
        $active_tab = "notes";
        $selected_cours_id = intval($_POST['cours_id']);
        $eleve_id = intval($_POST['etudiant_id']);
        $note_val = floatval($_POST['valeur_note']);
        $type_eval = $_POST['type_evaluation'];

        try {
            $stmtCheck = $pdo->prepare("SELECT statut_verrouillage FROM notes WHERE etudiant_id = ? AND cours_id = ? AND type_evaluation = ?");
            $stmtCheck->execute([$eleve_id, $selected_cours_id, $type_eval]);
            $exist = $stmtCheck->fetch();

            if ($exist && $exist['statut_verrouillage'] === 'valide_definitif') {
                $msg_status = "<div class='alert error'>Erreur : Cette note est verrouillée définitivement et ne peut plus être changée.</div>";
            } else {
                if ($exist) {
                    $stmt = $pdo->prepare("UPDATE notes SET valeur = ?, enseignant_id = ?, statut_verrouillage = 'valide_definitif' WHERE etudiant_id = ? AND cours_id = ? AND type_evaluation = ?");
                    $stmt->execute([$note_val, $enseignant_id, $eleve_id, $selected_cours_id, $type_eval]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO notes (etudiant_id, cours_id, enseignant_id, valeur, type_evaluation, statut_verrouillage) VALUES (?, ?, ?, ?, ?, 'valide_definitif')");
                    $stmt->execute([$eleve_id, $selected_cours_id, $enseignant_id, $note_val, $type_eval]);
                }
                $msg_status = "<div class='alert success'>Note validée et enregistrée définitivement.</div>";
            }
        } catch (PDOException $e) {
            $msg_status = "<div class='alert error'>Erreur SQL : " . $e->getMessage() . "</div>";
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

$lundi_courant = strtotime('monday this week');
$timestamp_lundi = strtotime($offset_semaine . " weeks", $lundi_courant);
$date_debut_semaine = date('Y-m-d', $timestamp_lundi);
$date_fin_semaine = date('Y-m-d', strtotime('+6 days', $timestamp_lundi));

try {
    // CORRECTION : Suppression des LEFT JOIN sur les tables amphis/groupes_td inexistantes
    $stmtEdt = $pdo->prepare("
        SELECT s.*, c.nom_cours
        FROM sessions_cours s
        JOIN cours c ON s.cours_id = c.id
        WHERE s.enseignant_id = ? AND s.date_cours BETWEEN ? AND ?
        ORDER BY s.date_cours ASC, s.heure_debut ASC
    ");
    $stmtEdt->execute([$enseignant_id, $date_debut_semaine, $date_fin_semaine]);
    $sessions = $stmtEdt->fetchAll();
} catch (PDOException $e) {
    $msg_status .= "<div class='alert error'>Erreur Emploi du temps : " . $e->getMessage() . "</div>";
    $sessions = [];
}

// ÉTUDIANTS POUR LA MESSAGERIE
$les_eleves = $pdo->query("SELECT u.id, u.nom, u.prenom FROM utilisateurs u WHERE u.role = 'etudiant' ORDER BY u.nom ASC")->fetchAll();
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
    </style>
</head>
<body>

<div class="sidebar">
    <h3>SmartCampus Prof</h3>
    <ul class="sidebar-menu">
        <li><a id="btn-dashboard" class="active" onclick="switchTab('dashboard')"><i class="fa-solid fa-chart-line"></i> Vue d'ensemble</a></li>
        <li><a id="btn-edt" onclick="switchTab('edt')"><i class="fa-solid fa-calendar-week"></i> Mon Emploi du Temps</a></li>
        <li><a id="btn-notes" onclick="switchTab('notes')"><i class="fa-solid fa-marker"></i> Saisie des Notes</a></li>
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
            <h3><i class="fa-solid fa-calendar-week"></i> Planning Hebdomadaire</h3>
            <div class="edt-navigation">
                <a class="btn-nav" href="?semaine=<?php echo $offset_semaine - 1; ?>"><i class="fa-solid fa-arrow-left"></i> Précédente</a>
                <span>Semaine du <strong><?php echo date('d/m/Y', strtotime($date_debut_semaine)); ?></strong> au <strong><?php echo date('d/m/Y', strtotime($date_fin_semaine)); ?></strong></span>
                <a class="btn-nav" href="?semaine=<?php echo $offset_semaine + 1; ?>">Suivante <i class="fa-solid fa-arrow-right"></i></a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Horaires</th>
                        <th>Matière / Cours</th>
                        <th>Salle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                        <tr><td colspan="4" style="text-align:center; color:#718096; padding:20px;">Aucun cours planifié pour cette semaine.</td></tr>
                    <?php else: ?>
                        <?php foreach ($sessions as $session): ?>
                            <tr>
                                <td><strong><?php echo date('d/m/Y', strtotime($session['date_cours'])); ?></strong></td>
                                <td><span style="color:var(--rouge-ecole); font-weight:600;"><?php echo substr($session['heure_debut'],0,5); ?> - <?php echo substr($session['heure_fin'],0,5); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($session['nom_cours']); ?></strong></td>
                                <td><span style="background:#E2E8F0; padding:4px 8px; border-radius:4px; font-weight:600;"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($session['salle']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="tab-notes" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-marker"></i> Validation des évaluations</h3>
            <form method="POST">
                <label>Sélectionner le Cours</label>
                <select name="cours_id" required>
                    <?php 
                    $mes_cours = $pdo->query("SELECT id, nom_cours FROM cours ORDER BY nom_cours ASC")->fetchAll();
                    foreach($mes_cours as $mc): ?>
                        <option value="<?php echo $mc['id']; ?>"><?php echo htmlspecialchars($mc['nom_cours']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Sélectionner l'Étudiant</label>
                <select name="etudiant_id" required>
                    <?php foreach($les_eleves as $le): ?>
                        <option value="<?php echo $le['id']; ?>"><?php echo htmlspecialchars($le['nom'] . ' ' . $le['prenom']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Type d'évaluation</label>
                <select name="type_evaluation" required>
                    <option value="CC">Contrôle Continu (CC)</option>
                    <option value="Examen">Examen Final</option>
                    <option value="Projet">Projet</option>
                </select>

                <label>Note (Valeur sur 20)</label>
                <input type="number" name="valeur_note" step="0.25" min="0" max="20" required placeholder="Ex: 14.5">

                <button type="submit" name="action_valider_note"><i class="fa-solid fa-lock"></i> Valider et Verrouiller la Note</button>
            </form>
        </div>
    </div>

    <div id="tab-messagerie" class="tab-content">
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
</script>
</body>
</html>