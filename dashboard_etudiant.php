<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// --- SÉCURITÉ & VÉRIFICATION DES ACCÈS ---
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'etudiant') {
    header('Location: login.php');
    exit();
}
$etudiant_id = $_SESSION['user_id'];

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

// =====================================================================
// CHARGEMENT DES DONNÉES EN STRIPTE CONFORMITÉ AVEC LE SQL
// =====================================================================

$profil = ['nom' => 'Étudiant', 'prenom' => '', 'nom_promotion' => 'Non définie', 'annee_academique' => '-', 'statut' => '-'];
$absences = ['absences_injustifiees' => 0, 'absences_justifiees' => 0];
$my_sessions = [];
$bulletin = [];
$all_profs = [];
$boite_recus = [];

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

// 3. Emploi du temps (Date calée sur votre SEED : 2026-05-25)
$date_test = '2026-05-25'; 
try {
    $req_planning = $pdo->prepare("
        SELECT s.id, s.heure_debut, s.heure_fin, s.salle, c.nom_cours, u.nom AS prof_nom
        FROM sessions_cours s
        INNER JOIN cours c ON s.cours_id = c.id
        INNER JOIN inscriptions_cours ic ON c.id = ic.cours_id
        INNER JOIN utilisateurs u ON s.enseignant_id = u.id
        WHERE ic.etudiant_id = ? AND s.date_cours = ?
        ORDER BY s.heure_debut ASC
    ");
    $req_planning->execute([$etudiant_id, $date_test]);
    $my_sessions = $req_planning->fetchAll();
} catch (Exception $e) {
    $msg_status .= "<div class='alert danger'>⚠️ Erreur Emploi du temps : " . htmlspecialchars($e->getMessage()) . "</div>";
}

// 4. Liste des notes et du Bulletin
try {
    $req_notes = $pdo->prepare("
        SELECT ue.code_ue, c.nom_cours, c.coefficient, n.valeur AS note, n.type_evaluation, n.statut_verrouillage
        FROM inscriptions_cours ic
        INNER JOIN cours c ON ic.cours_id = c.id
        INNER JOIN unites_enseignement ue ON c.ue_id = ue.id
        LEFT JOIN notes n ON (n.cours_id = c.id AND n.etudiant_id = ic.etudiant_id)
        WHERE ic.etudiant_id = ?
        ORDER BY ue.code_ue ASC
    ");
    $req_notes->execute([$etudiant_id]);
    $bulletin = $req_notes->fetchAll();
} catch (Exception $e) {
    $msg_status .= "<div class='alert danger'>⚠️ Erreur Bulletin : " . htmlspecialchars($e->getMessage()) . "</div>";
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
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-brand">Smart<span>Campus</span></div>
    <ul class="sidebar-menu">
        <li><a href="#" id="btn-dashboard" class="active" onclick="switchTab('dashboard')"><i class="fa-solid fa-chart-pie"></i> Dashboard</a></li>
        <li><a href="#" id="btn-notes" onclick="switchTab('notes')"><i class="fa-solid fa-graduation-cap"></i> Mes Notes</a></li>
        <li><a href="#" id="btn-presence" onclick="switchTab('presence')"><i class="fa-solid fa-qrcode"></i> Présence QR Code</a></li>
        <li><a href="#" id="btn-messagerie" onclick="switchTab('messagerie')"><i class="fa-solid fa-envelope"></i> Messagerie</a></li>
        <li><a href="deconnexion.php" style="color:#FEB2B2;"><i class="fa-solid fa-right-from-bracket"></i> Déconnexion</a></li>
    </ul>
</div>

<div class="main-content">
    
    <div class="card">
        <h2>Bienvenue, <?php echo htmlspecialchars($profil['prenom'] . ' ' . $profil['nom']); ?> 👋</h2>
        <p><strong>Filière :</strong> <?php echo htmlspecialchars($profil['nom_promotion']); ?> | <strong>Statut :</strong> En formation <?php echo htmlspecialchars($profil['statut']); ?></p>
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
            <h3><i class="fa-solid fa-calendar-day"></i> Mon Planning du Jour (<?php echo $date_test; ?>)</h3>
            <?php if(empty($my_sessions)): ?>
                <p>Aucun cours programmé aujourd'hui.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Horaire</th><th>Cours</th><th>Salle</th><th>Enseignant</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($my_sessions as $s): ?>
                            <tr>
                                <td><strong><?php echo substr($s['heure_debut'],0,5); ?> - <?php echo substr($s['heure_fin'],0,5); ?></strong></td>
                                <td><?php echo htmlspecialchars($s['nom_cours']); ?></td>
                                <td>📍 <?php echo htmlspecialchars($s['salle']); ?></td>
                                <td>M. <?php echo htmlspecialchars($s['prof_nom']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-notes" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-id-card-clip"></i> Mes Notes et Résultats</h3>
            <?php if(empty($bulletin)): ?>
                <p>Aucune note enregistrée pour le moment.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Code UE</th><th>Matière</th><th>Type Éval.</th><th>Coefficient</th><th>Note / 20</th><th>Statut</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($bulletin as $b): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($b['code_ue']); ?></strong></td>
                                <td><?php echo htmlspecialchars($b['nom_cours']); ?></td>
                                <td><?php echo htmlspecialchars($b['type_evaluation'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($b['coefficient']); ?></td>
                                <td><strong><?php echo $b['note'] !== null ? number_format($b['note'], 2) : 'Non saisie'; ?></strong></td>
                                <td><?php echo $b['statut_verrouillage'] === 'valide_definitif' ? '🔴 Validée' : '🟡 En attente'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div id="tab-presence" class="tab-content">
        <div class="card" style="max-width:500px; margin:0 auto; text-align:center;">
            <h3><i class="fa-solid fa-qrcode"></i> Émulation Scanner de QR Code</h3>
            <p>Sélectionnez le cours actuel pour simuler le scan du QR code de l'amphithéâtre.</p>
            <?php if(empty($my_sessions)): ?>
                <p style="color:#718096;">Aucun cours planifié aujourd'hui pour valider une présence.</p>
            <?php else: ?>
                <form method="POST">
                    <select name="session_valide_id" required>
                        <?php foreach($my_sessions as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nom_cours']); ?> (<?php echo substr($s['heure_debut'],0,5); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="action_valider_presence">Flasher & Signer la présence</button>
                </form>
            <?php endif; ?>
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
</script>
</body>
</html>