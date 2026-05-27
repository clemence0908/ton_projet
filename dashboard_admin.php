<?php
require_once 'config.php';

// PROTECTION DE LA ROUTE ADMIN
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$message = ""; $status = "";
$page = isset($_GET['page']) ? $_GET['page'] : 'annuaire';

// Récupération email admin
$stmtAdmin = $pdo->prepare("SELECT email FROM utilisateurs WHERE id = ?");
$stmtAdmin->execute([$_SESSION['user_id']]);
$admin_email = $stmtAdmin->fetchColumn() ?: 'admin@ecole.fr';

// ====================================================================
// TRAITEMENT DES FORMULAIRES (POST)
// ====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // A. PLANIFICATION COURS
    if (isset($_POST['sauvegarder_cours'])) {
        $id_cours = !empty($_POST['id_cours']) ? intval($_POST['id_cours']) : null;
        $matiere_id = intval($_POST['matiere_id']); 
        $enseignant_id = intval($_POST['enseignant_id']);
        $salle_id = intval($_POST['salle_id']); 
        $amphi_id = !empty($_POST['amphi_id']) ? intval($_POST['amphi_id']) : null;
        $td_id = !empty($_POST['td_id']) ? intval($_POST['td_id']) : null;
        $date_cours = $_POST['date_cours']; 
        $heure_debut = $_POST['heure_debut']; 
        $heure_fin = $_POST['heure_fin'];

        try {
            // Conflits (Enseignant et Salle)
            $checkProf = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours WHERE enseignant_id = ? AND date_cours = ? AND id != ? AND (heure_debut < ? AND heure_fin > ?)");
            $checkProf->execute([$enseignant_id, $date_cours, $id_cours ?? 0, $heure_fin, $heure_debut]);
            
            $checkSalle = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours WHERE salle_cours = ? AND date_cours = ? AND id != ? AND (heure_debut < ? AND heure_fin > ?)");
            $checkSalle->execute([$salle_id, $date_cours, $id_cours ?? 0, $heure_fin, $heure_debut]);

            if ($checkProf->fetchColumn() > 0) {
                $message = "❌ Conflit : L'enseignant est déjà occupé !"; $status = "error";
            } elseif ($checkSalle->fetchColumn() > 0) {
                $message = "❌ Conflit : La salle est déjà occupée !"; $status = "error";
            } else {
                if ($id_cours) {
                    $stmt = $pdo->prepare("UPDATE sessions_cours SET nom_cours=?, enseignant_id=?, amphi_id=?, td_id=?, salle_cours=?, date_cours=?, heure_debut=?, heure_fin=? WHERE id=?");
                    $stmt->execute([$matiere_id, $enseignant_id, $amphi_id, $td_id, $salle_id, $date_cours, $heure_debut, $heure_fin, $id_cours]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO sessions_cours (nom_cours, enseignant_id, amphi_id, td_id, salle_cours, date_cours, heure_debut, heure_fin) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->execute([$matiere_id, $enseignant_id, $amphi_id, $td_id, $salle_id, $date_cours, $heure_debut, $heure_fin]);
                }
                $message = "🎉 Cours enregistré avec succès."; $status = "success";
            }
        } catch (PDOException $e) { $message = "Erreur : " . $e->getMessage(); $status = "error"; }
    }

    // B. AUTRES ACTIONS
    if (isset($_POST['valider_note_eleve'])) {
        $pdo->prepare("UPDATE etudiants SET note_finale = ? WHERE utilisateur_id = ?")->execute([$_POST['note_finale']!==""?floatval($_POST['note_finale']):null, intval($_POST['id_utilisateur'])]);
        $message = "📝 Note enregistrée."; $status = "success";
    }
    if (isset($_POST['modifier_etudiant_fiche'])) {
        $pdo->prepare("UPDATE utilisateurs SET nom=?, prenom=?, email=? WHERE id=?")->execute([trim($_POST['nom']), trim($_POST['prenom']), trim($_POST['email']), intval($_POST['id_utilisateur'])]);
        $pdo->prepare("UPDATE etudiants SET promotion_id=?, groupe_id=? WHERE utilisateur_id=?")->execute([intval($_POST['promotion_id']), intval($_POST['td_id']), intval($_POST['id_utilisateur'])]);
        $message = "Dossier mis à jour !"; $status = "success";
    }
    if (isset($_POST['ajouter_etudiant'])) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (email, mot_de_pass, nom, prenom, role) VALUES (?, ?, ?, ?, 'etudiant')");
        $stmt->execute([trim($_POST['email']), trim($_POST['password']), trim($_POST['nom']), trim($_POST['prenom'])]);
        $pdo->prepare("INSERT INTO etudiants (utilisateur_id, promotion_id, groupe_id) VALUES (?, ?, ?)")->execute([$pdo->lastInsertId(), intval($_POST['promotion_id']), intval($_POST['td_id'])]);
        $pdo->commit(); $message = "Élève inscrit !"; $status = "success";
    }
    if (isset($_POST['supprimer_cours'])) { $pdo->prepare("DELETE FROM sessions_cours WHERE id = ?")->execute([intval($_POST['id_cours'])]); header("Location: ?page=planning"); exit(); }
}

// ====================================================================
// CHARGEMENT DES DONNÉES
// ====================================================================
$matieres = $pdo->query("SELECT * FROM cours ORDER BY nom_cours ASC")->fetchAll(); 
$salles = $pdo->query("SELECT * FROM salles ORDER BY nom_salle ASC")->fetchAll(); 
$promotions = $pdo->query("SELECT * FROM promotions ORDER BY id ASC")->fetchAll();
$enseignants = $pdo->query("SELECT id, nom, prenom, email FROM utilisateurs WHERE role = 'enseignant' ORDER BY nom ASC")->fetchAll();

// Utilisation de e.groupe_id (corrigé)
$groupes_td_complet = $pdo->query("SELECT g.*, COUNT(e.utilisateur_id) as inscrits FROM groupes_td g LEFT JOIN etudiants e ON g.id = e.groupe_id GROUP BY g.id")->fetchAll();
$amphis_complet = $pdo->query("SELECT a.*, COUNT(e.utilisateur_id) as inscrits_promo FROM amphis a LEFT JOIN etudiants e ON a.promotion_id = e.promotion_id GROUP BY a.id")->fetchAll();

$etudiants_liste = $pdo->query("SELECT u.id, u.nom, u.prenom, u.email, p.nom_promotion, t.nom_td, e.note_finale FROM utilisateurs u JOIN etudiants e ON u.id = e.utilisateur_id LEFT JOIN promotions p ON e.promotion_id = p.id LEFT JOIN groupes_td t ON e.groupe_id = t.id WHERE u.role = 'etudiant' ORDER BY u.nom ASC")->fetchAll();

$semaine_offset = isset($_GET['semaine']) ? intval($_GET['semaine']) : 0;
$date_debut_semaine = date('Y-m-d', strtotime("monday this week $semaine_offset weeks"));
$date_fin_semaine = date('Y-m-d', strtotime("sunday this week $semaine_offset weeks"));
$stmt_week = $pdo->prepare("SELECT s.*, u.nom as prof_nom, c.nom_cours as nom_matiere, sa.nom_salle FROM sessions_cours s JOIN utilisateurs u ON s.enseignant_id = u.id JOIN cours c ON s.nom_cours = c.id JOIN salles sa ON s.salle_cours = sa.id WHERE s.date_cours BETWEEN ? AND ?");
$stmt_week->execute([$date_debut_semaine, $date_fin_semaine]);
$cours_semaine = $stmt_week->fetchAll();
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>SmartCampus Admin</title>
    <style>
        :root { --bleu-nuit: #0A2240; --rouge-ecole: #D9383A; --gris-fond: #F4F7F9; }
        body { font-family: sans-serif; background: var(--gris-fond); margin:0; display:flex; height:100vh; }
        .sidebar { width: 250px; background: var(--bleu-nuit); color: white; padding: 20px; }
        .main-content { flex: 1; padding: 30px; overflow-y: auto; }
        .card { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        .btn-submit { background: var(--bleu-nuit); color: white; border: none; padding: 10px 20px; cursor: pointer; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #eee; padding: 10px; text-align: left; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>SmartCampus</h2>
        <a href="?page=annuaire" style="color:white; display:block; padding:10px;">🗂️ Annuaire</a>
        <a href="?page=planning" style="color:white; display:block; padding:10px;">🗓️ Planning</a>
        <a href="?page=inscriptions" style="color:white; display:block; padding:10px;">➕ Inscriptions</a>
    </div>

    <div class="main-content">
        <?php if(!empty($message)): ?><div class="alert alert-<?php echo $status; ?>"><?php echo $message; ?></div><?php endif; ?>

        <?php if($page === 'annuaire'): ?>
            <h2>Annuaire des étudiants</h2>
            <div class="card">
                <table>
                    <thead><tr><th>Nom</th><th>Email</th><th>Groupe</th><th>Note</th></tr></thead>
                    <tbody>
                        <?php foreach($etudiants_liste as $e): ?>
                        <tr>
                            <td><a href="?page=fiche_etudiant&id=<?php echo $e['id']; ?>"><?php echo $e['nom'].' '.$e['prenom']; ?></a></td>
                            <td><?php echo $e['email']; ?></td>
                            <td><?php echo $e['nom_td']; ?></td>
                            <td><?php echo $e['note_finale'] ?? 'Non noté'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif($page === 'planning'): ?>
            <h2>Planning Hebdomadaire</h2>
            <div class="card">
                <form method="POST">
                    <input type="hidden" name="id_cours" value="">
                    <label>Matière</label>
                    <select name="matiere_id"><?php foreach($matieres as $m): ?><option value="<?php echo $m['id']; ?>"><?php echo $m['nom_cours']; ?></option><?php endforeach; ?></select>
                    <label>Salle</label>
                    <select name="salle_id"><?php foreach($salles as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo $s['nom_salle']; ?></option><?php endforeach; ?></select>
                    <input type="date" name="date_cours" required>
                    <input type="time" name="heure_debut" required>
                    <input type="time" name="heure_fin" required>
                    <button type="submit" name="sauvegarder_cours" class="btn-submit">Ajouter cours</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>