<?php
require_once 'config.php';

// 1. PROTECTION DE LA ROUTE (SÉCURITÉ ADMIN)
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$message = "";
$status = "";

// 2. TRAITEMENT DU FORMULAIRE DE PLANIFICATION (ALGORITHME ANTI-CONFLIT)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['planifier_cours'])) {
    $cours_id = intval($_POST['cours_id']);
    $enseignant_id = intval($_POST['enseignant_id']);
    $salle_id = intval($_POST['salle_id']);
    $date_cours = $_POST['date_cours'];
    $heure_debut = $_POST['heure_debut'];
    $heure_fin = $_POST['heure_fin'];

    try {
        // --- ALGORITHME DE DÉTECTION DES CONFLITS ---
        
        // Conflit 1 : Le professeur est-il déjà pris ?
        $checkProf = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours 
                                    WHERE enseignant_id = ? AND date_cours = ? 
                                    AND ((heure_debut <= ? AND heure_fin > ?) OR (heure_debut < ? AND heure_fin >= ?))");
        $checkProf->execute([$enseignant_id, $date_cours, $heure_debut, $heure_debut, $heure_fin, $heure_fin]);
        $profOccupe = $checkProf->fetchColumn() > 0;

        // Conflit 2 : La salle est-elle déjà occupée ?
        $checkSalle = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours 
                                     WHERE salle_id = ? AND date_cours = ? 
                                     AND ((heure_debut <= ? AND heure_fin > ?) OR (heure_debut < ? AND heure_fin >= ?))");
        $checkSalle->execute([$salle_id, $date_cours, $heure_debut, $heure_debut, $heure_fin, $heure_fin]);
        $salleOccupe = $checkSalle->fetchColumn() > 0;

        // --- VERDICT DE L'ALGORITHME ---
        if ($profOccupe) {
            $message = "🚫 Action Bloquée : Ce professeur donne déjà un autre cours sur ce créneau horaire !";
            $status = "erreur";
        } elseif ($salleOccupe) {
            $message = "🚫 Action Bloquée : La salle sélectionnée est déjà réservée pour un autre cours !";
            $status = "erreur";
        } else {
            // Aucun conflit trouvé -> Insertion sécurisée
            $stmtInsert = $pdo->prepare("INSERT INTO sessions_cours (cours_id, enseignant_id, salle_id, date_cours, heure_debut, heure_fin) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$cours_id, $enseignant_id, $salle_id, $date_cours, $heure_debut, $heure_fin]);
            
            $message = "✅ Succès : Le cours a été planifié avec succès dans l'emploi du temps !";
            $status = "succes";
        }

    } catch (PDOException $e) {
        $message = "Erreur système : " . $e->getMessage();
        $status = "erreur";
    }
}

// 3. RÉCUPÉRATION DES DONNÉES POUR LES FORMULAIRES
try {
    $listeCours = $pdo->query("SELECT id, nom_cours FROM cours")->fetchAll();
    $listeProfs = $pdo->query("SELECT id, nom, prenom FROM utilisateurs WHERE role = 'enseignant'")->fetchAll();
    $listeSalles = $pdo->query("SELECT id, nom_salle, capacite_max FROM salles")->fetchAll();
    
    // Liste de tous les cours déjà planifiés pour affichage
    $allSessions = $pdo->query("SELECT s.date_cours, s.heure_debut, s.heure_fin, c.nom_cours, u.nom as prof_nom, sa.nom_salle 
                                FROM sessions_cours s
                                JOIN cours c ON s.cours_id = c.id
                                JOIN utilisateurs u ON s.enseignant_id = u.id
                                JOIN salles sa ON s.salle_id = sa.id
                                ORDER BY s.date_cours DESC, s.heure_debut ASC")->fetchAll();
} catch (PDOException $e) {
    die("Erreur : " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>SmartCampus - Panneau Administration</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bleu-marine: #0A2240;
            --rouge-ecole: #D9383A;
            --fond-gris: #F7FAFC;
            --bordure: #E2E8F0;
        }
        body { font-family: 'Segoe UI', sans-serif; background-color: var(--fond-gris); margin: 0; display: flex; }
        
        .sidebar { width: 260px; background-color: var(--bleu-marine); color: white; height: 100vh; position: fixed; padding: 20px; }
        .sidebar h1 { font-size: 22px; margin-bottom: 30px; text-align: center; }
        .sidebar h1 span { color: var(--rouge-ecole); }
        .sidebar-menu { list-style: none; padding: 0; }
        .sidebar-menu li a { display: block; padding: 12px 15px; color: #E2E8F0; text-decoration: none; border-radius: 4px; margin-bottom: 8px; }
        .sidebar-menu li.active a { background-color: #1A365D; border-left: 4px solid var(--rouge-ecole); }
        
        .main-content { margin-left: 260px; flex-grow: 1; padding: 40px; }
        .topbar { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--bordure); }
        
        .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 30px; }
        .card { background: white; border-radius: 8px; border: 1px solid var(--bordure); padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); height: fit-content; }
        .card-title { font-size: 18px; color: var(--bleu-marine); font-weight: 600; margin-bottom: 20px; border-bottom: 2px solid var(--bordure); padding-bottom: 10px; }
        
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-size: 14px; color: #4A5568; }
        select, input { width: 100%; padding: 10px; border: 1px solid var(--bordure); border-radius: 4px; box-sizing: border-box; }
        
        .btn { background: var(--rouge-ecole); color: white; border: none; padding: 12px 15px; border-radius: 4px; cursor: pointer; font-weight: 600; width: 100%; }
        .btn:hover { background: #B8282A; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--bordure); font-size: 14px; }
        th { color: #718096; text-transform: uppercase; font-size: 12px; }
        
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 600; }
        .alert-succes { background: #C6F6D5; color: #22543D; border-left: 5px solid #38A169; }
        .alert-erreur { background: #FED7D7; color: #742A2A; border-left: 5px solid #E53E3E; }
    </style>
</head>
<body>

    <div class="sidebar">
        <h1>Smart<span>Campus</span></h1>
        <ul class="sidebar-menu">
            <li class="active"><a href="#"><i class="fa-solid fa-sliders"></i> Planification Globale</a></li>
            <li><a href="deconnexion.php" style="color: #FC8181;"><i class="fa-solid fa-power-off"></i> Déconnexion</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="topbar">
            <h2>Espace Scolarité / Administration</h2>
            <div><strong>Scolarité SmartCampus</strong></div>
        </div>

        <?php if(!empty($message)): ?>
            <div class="alert alert-<?php echo $status; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="grid">
            <div class="card">
                <div class="card-title">Planifier un Cours</div>
                <form action="dashboard_admin.php" method="POST">
                    <input type="hidden" name="planifier_cours" value="1">
                    
                    <div class="form-group">
                        <label>Module de Cours</label>
                        <select name="cours_id" required>
                            <?php foreach ($listeCours as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nom_cours']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Enseignant Responsable</label>
                        <select name="enseignant_id" required>
                            <?php foreach ($listeProfs as $p): ?>
                                <option value="<?php echo $p['id']; ?>">M. <?php echo htmlspecialchars($p['nom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Salle de Classe</label>
                        <select name="salle_id" required>
                            <?php foreach ($listeSalles as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['nom_salle']); ?> (max: <?php echo $s['capacite_max']; ?> p.)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date du cours</label>
                        <input type="date" name="date_cours" value="2026-05-25" required>
                    </div>

                    <div class="form-group">
                        <label>Heure de Début</label>
                        <input type="time" name="heure_debut" value="08:30" required>
                    </div>

                    <div class="form-group">
                        <label>Heure de Fin</label>
                        <input type="time" name="heure_fin" value="10:15" required>
                    </div>

                    <button type="submit" class="btn">Vérifier & Enregistrer</button>
                </form>
            </div>

            <div class="card">
                <div class="card-title">Emploi du Temps Global du Campus</div>
                <table>
                    <thead>
                        <tr>
                            <th>Date & Horaires</th>
                            <th>Cours / Matière</th>
                            <th>Professeur</th>
                            <th>Salle Allouée</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allSessions as $session): ?>
                            <tr>
                                <td><strong><?php echo $session['date_cours']; ?></strong><br><small style="color:var(--rouge-ecole)"><?php echo substr($session['heure_debut'],0,5); ?> - <?php echo substr($session['heure_fin'],0,5); ?></small></td>
                                <td><?php echo htmlspecialchars($session['nom_cours']); ?></td>
                                <td>M. <?php echo htmlspecialchars($session['prof_nom']); ?></td>
                                <td><span style="background:#E2E8F0; padding:3px 8px; border-radius:4px; font-weight:600;"><?php echo htmlspecialchars($session['nom_salle']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>
</html>