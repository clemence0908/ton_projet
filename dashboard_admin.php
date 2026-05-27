<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// 1. PROTECTION DE LA ROUTE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$msg_status = "";
$active_tab = "dashboard";

// 2. TRAITEMENT DES SOUMISSIONS (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A. Planification de cours
    if (isset($_POST['planifier_cours'])) {
        $active_tab = "planification";
        $cours_id = intval($_POST['cours_id']);
        $enseignant_id = intval($_POST['enseignant_id']);
        $salle = trim($_POST['salle']);
        $date_cours = $_POST['date_cours'];
        $heure_debut = $_POST['heure_debut'];
        $heure_fin = $_POST['heure_fin'];

        if (strtotime($heure_debut) >= strtotime($heure_fin)) {
            $msg_status = "<div class='alert error'>❌ Erreur : L'heure de fin doit être après l'heure de début.</div>";
        } else {
            try {
                // Détection des conflits (chevauchement)
                $checkConflict = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours 
                                                WHERE (enseignant_id = ? OR salle = ?) 
                                                AND date_cours = ? 
                                                AND heure_debut < ? AND heure_fin > ?");
                $checkConflict->execute([$enseignant_id, $salle, $date_cours, $heure_fin, $heure_debut]);

                if ($checkConflict->fetchColumn() > 0) {
                    $msg_status = "<div class='alert error'>⚠️ Conflit : L'enseignant ou la salle est déjà occupé sur ce créneau.</div>";
                } else {
                    $ins = $pdo->prepare("INSERT INTO sessions_cours (cours_id, enseignant_id, salle, date_cours, heure_debut, heure_fin) VALUES (?, ?, ?, ?, ?, ?)");
                    $ins->execute([$cours_id, $enseignant_id, $salle, $date_cours, $heure_debut, $heure_fin]);
                    $msg_status = "<div class='alert success'>✅ Séance planifiée avec succès !</div>";
                }
            } catch (PDOException $e) {
                $msg_status = "<div class='alert error'>Erreur SQL : " . $e->getMessage() . "</div>";
            }
        }
    }

    // B. Inscription d'un nouvel étudiant
    if (isset($_POST['inscrire_etudiant'])) {
        $active_tab = "inscription";
        $nom = trim($_POST['nom']);
        $prenom = trim($_POST['prenom']);
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $password = trim($_POST['password']);
        $promotion_id = intval($_POST['promotion_id']);
        $groupe_td_id = !empty($_POST['groupe_td_id']) ? intval($_POST['groupe_td_id']) : null;
        $statut_parcours = $_POST['statut_parcours'];

        if (!empty($nom) && !empty($prenom) && !empty($email) && !empty($password)) {
            try {
                // Vérifier si l'email existe déjà
                $checkEmail = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE email = ?");
                $checkEmail->execute([$email]);
                if ($checkEmail->fetchColumn() > 0) {
                    $msg_status = "<div class='alert error'>❌ Erreur : Cet email est déjà utilisé par un autre utilisateur.</div>";
                } else {
                    $pdo->beginTransaction();

                    // 1. Créer l'utilisateur
                    $stmtUser = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_pass, role) VALUES (?, ?, ?, ?, 'etudiant')");
                    $stmtUser->execute([$nom, $prenom, $email, $password]);
                    $user_id = $pdo->lastInsertId();

                    // 2. Lier à la table etudiants (Conformément au schéma SQL avec groupe_td_id)
                    $stmtEtudiant = $pdo->prepare("INSERT INTO etudiants (utilisateur_id, promotion_id, groupe_td_id, statut_parcours) VALUES (?, ?, ?, ?)");
                    $stmtEtudiant->execute([$user_id, $promotion_id, $groupe_td_id, $statut_parcours]);

                    $pdo->commit();
                    $msg_status = "<div class='alert success'>✅ Étudiant inscrit avec succès !</div>";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $msg_status = "<div class='alert error'>❌ Erreur lors de l'inscription : " . $e->getMessage() . "</div>";
            }
        } else {
            $msg_status = "<div class='alert error'>❌ Veuillez remplir tous les champs.</div>";
        }
    }

    // C. Modification d'un étudiant
    if (isset($_POST['modifier_etudiant'])) {
        $active_tab = "students";
        $id_user = intval($_POST['user_id']);
        $nom = trim($_POST['nom']);
        $prenom = trim($_POST['prenom']);
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $promotion_id = intval($_POST['promotion_id']);
        $groupe_td_id = !empty($_POST['groupe_td_id']) ? intval($_POST['groupe_td_id']) : null;
        $statut_parcours = $_POST['statut_parcours'];

        if (!empty($nom) && !empty($prenom) && !empty($email)) {
            try {
                $pdo->beginTransaction();

                // 1. Mettre à jour l'utilisateur
                $updUser = $pdo->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ? WHERE id = ?");
                $updUser->execute([$nom, $prenom, $email, $id_user]);

                // 2. Mettre à jour les infos étudiantes
                $updEtudiant = $pdo->prepare("UPDATE etudiants SET promotion_id = ?, groupe_td_id = ?, statut_parcours = ? WHERE utilisateur_id = ?");
                $updEtudiant->execute([$promotion_id, $groupe_td_id, $statut_parcours, $id_user]);

                $pdo->commit();
                $msg_status = "<div class='alert success'>✅ Profil étudiant mis à jour avec succès !</div>";
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $msg_status = "<div class='alert error'>❌ Erreur lors de la modification : " . $e->getMessage() . "</div>";
            }
        }
    }

    // D. Suppression d'un étudiant
    if (isset($_POST['supprimer_etudiant'])) {
        $active_tab = "students";
        $id_user = intval($_POST['user_id']);

        try {
            $pdo->beginTransaction();

            // 1. Supprimer les présences
            $pdo->prepare("DELETE FROM presences WHERE etudiant_id = ?")->execute([$id_user]);
            // 2. Supprimer les notes
            $pdo->prepare("DELETE FROM notes WHERE etudiant_id = ?")->execute([$id_user]);
            // 3. Supprimer les inscriptions aux cours
            $pdo->prepare("DELETE FROM inscriptions_cours WHERE etudiant_id = ?")->execute([$id_user]);
            // 4. Supprimer l'entrée dans la table etudiants
            $pdo->prepare("DELETE FROM etudiants WHERE utilisateur_id = ?")->execute([$id_user]);
            // 5. Supprimer l'utilisateur
            $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$id_user]);

            $pdo->commit();
            $msg_status = "<div class='alert success'>🗑️ Étudiant supprimé avec succès !</div>";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $msg_status = "<div class='alert error'>❌ Erreur lors de la suppression : " . $e->getMessage() . "</div>";
        }
    }

    // E. Inscription d'un nouvel enseignant
    if (isset($_POST['inscrire_enseignant'])) {
        $active_tab = "inscription_prof";
        $nom = trim($_POST['nom']);
        $prenom = trim($_POST['prenom']);
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $password = trim($_POST['password']);
        $specialite = trim($_POST['specialite']);

        if (!empty($nom) && !empty($prenom) && !empty($email) && !empty($password)) {
            try {
                // Vérifier si l'email existe déjà
                $checkEmail = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE email = ?");
                $checkEmail->execute([$email]);
                if ($checkEmail->fetchColumn() > 0) {
                    $msg_status = "<div class='alert error'>❌ Erreur : Cet email est déjà utilisé.</div>";
                } else {
                    $pdo->beginTransaction();

                    // 1. Créer l'utilisateur (rôle enseignant)
                    $stmtUser = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_pass, role) VALUES (?, ?, ?, ?, 'enseignant')");
                    $stmtUser->execute([$nom, $prenom, $email, $password]);
                    $user_id = $pdo->lastInsertId();

                    // 2. Lier à la table enseignants
                    $stmtProf = $pdo->prepare("INSERT INTO enseignants (utilisateur_id, specialite) VALUES (?, ?)");
                    $stmtProf->execute([$user_id, $specialite]);

                    $pdo->commit();
                    $msg_status = "<div class='alert success'>✅ Enseignant inscrit avec succès !</div>";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $msg_status = "<div class='alert error'>❌ Erreur lors de l'inscription : " . $e->getMessage() . "</div>";
            }
        } else {
            $msg_status = "<div class='alert error'>❌ Veuillez remplir tous les champs.</div>";
        }
    }
}

// 3. RÉCUPÉRATION DES DONNÉES
try {
    // Liste des promotions
    $list_promotions = $pdo->query("SELECT id, nom_promotion, annee_academique FROM promotions ORDER BY nom_promotion ASC, annee_academique DESC")->fetchAll();
    
    // Liste des groupes de TD (Classes) avec leur promotion parente
    $list_groupes = $pdo->query("SELECT g.id, g.nom AS groupe_nom, p.nom_promotion 
                                 FROM groupes_td g 
                                 JOIN amphis a ON g.amphi_id = a.id 
                                 JOIN promotions p ON a.promotion_id = p.id 
                                 ORDER BY p.nom_promotion ASC, g.nom ASC")->fetchAll();

    // Liste des étudiants (Correction : ajout du groupe de TD et ID utilisateur pour modif)
    $students = $pdo->query("SELECT u.id, u.nom, u.prenom, u.email, p.id AS promo_id, p.nom_promotion AS promo_nom, g.id AS groupe_id, g.nom AS groupe_nom, e.statut_parcours 
                             FROM utilisateurs u 
                             LEFT JOIN etudiants e ON u.id = e.utilisateur_id 
                             LEFT JOIN promotions p ON e.promotion_id = p.id 
                             LEFT JOIN groupes_td g ON e.groupe_td_id = g.id
                             WHERE u.role = 'etudiant' ORDER BY u.nom ASC")->fetchAll();

    // Emploi du temps global
    $allSessions = $pdo->query("SELECT s.*, c.nom_cours, u.nom AS prof_nom 
                                FROM sessions_cours s 
                                JOIN cours c ON s.cours_id = c.id 
                                JOIN utilisateurs u ON s.enseignant_id = u.id 
                                ORDER BY s.date_cours DESC, s.heure_debut ASC")->fetchAll();

    // Listes pour le formulaire
    $list_cours = $pdo->query("SELECT id, nom_cours FROM cours ORDER BY nom_cours ASC")->fetchAll();
    $list_profs = $pdo->query("SELECT id, nom, prenom FROM utilisateurs WHERE role = 'enseignant' ORDER BY nom ASC")->fetchAll();

    // Stats
    $count_std = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'etudiant'")->fetchColumn();
    $count_prf = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'enseignant'")->fetchColumn();
    $count_crs = $pdo->query("SELECT COUNT(*) FROM cours")->fetchColumn();

} catch (PDOException $e) {
    $msg_status = "<div class='alert error'>Erreur de chargement : " . $e->getMessage() . "</div>";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>SmartCampus - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --bleu-ecole: #0A2240; --rouge-ecole: #D9383A; --gris-fond: #F7FAFC; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--gris-fond); margin: 0; display: flex; }
        
        /* SIDEBAR (Identique aux autres) */
        .sidebar { width: 260px; background: var(--bleu-ecole); color: white; height: 100vh; position: fixed; padding-top: 20px; }
        .sidebar h3 { text-align: center; margin-bottom: 30px; color: white; }
        .sidebar h3 span { color: var(--rouge-ecole); }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; }
        .sidebar-menu li a { display: block; padding: 15px 25px; color: #CBD5E0; text-decoration: none; font-weight: 500; cursor: pointer; transition: 0.3s; }
        .sidebar-menu li a:hover, .sidebar-menu li a.active { background: #1A365D; color: white; border-left: 4px solid var(--rouge-ecole); }
        
        /* CONTENU PRINCIPAL */
        .main-content { margin-left: 260px; padding: 40px; width: calc(100% - 260px); box-sizing: border-box; }
        .header-panel { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--bleu-ecole); padding-bottom: 15px; margin-bottom: 30px; }
        
        .tab-content { display: none; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 25px; }
        
        /* GRILLE STATS */
        .grid-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-box { padding: 20px; border-radius: 8px; color: white; text-align: center; }
        .stat-std { background: #2B6CB0; }
        .stat-prf { background: var(--rouge-ecole); }
        .stat-crs { background: #38A169; }

        /* TABLES */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #E2E8F0; }
        th { background: var(--bleu-ecole); color: white; }
        
        /* FORMULAIRES */
        label { display: block; margin-bottom: 5px; font-weight: bold; color: var(--bleu-ecole); }
        select, input, button { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #CBD5E0; border-radius: 4px; box-sizing: border-box; }
        button { background: var(--rouge-ecole); color: white; border: none; font-weight: bold; cursor: pointer; }
        button:hover { background: #B8282A; }

        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert.success { background: #C6F6D5; color: #22543D; }
        .alert.error { background: #FED7D7; color: #742A2A; }

        /* MODAL EDIT */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 5% auto; padding: 20px; border-radius: 8px; width: 500px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #E2E8F0; padding-bottom: 10px; margin-bottom: 20px; }
        .close-modal { cursor: pointer; font-size: 24px; color: #A0AEC0; }
        .btn-edit { background: #4A5568; color: white; padding: 6px 10px; border-radius: 4px; text-decoration: none; font-size: 0.8em; cursor: pointer; border: none; }
        .btn-edit:hover { background: #2D3748; }
        .btn-delete { background: #E53E3E; color: white; padding: 6px 10px; border-radius: 4px; text-decoration: none; font-size: 0.8em; cursor: pointer; border: none; margin-left: 5px; }
        .btn-delete:hover { background: #C53030; }
    </style>
</head>
<body>

<div class="sidebar">
    <h3>Smart<span>Campus</span></h3>
    <ul class="sidebar-menu">
        <li><a id="btn-dashboard" class="active" onclick="switchTab('dashboard')"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li><a id="btn-students" onclick="switchTab('students')"><i class="fa-solid fa-user-graduate"></i> Étudiants</a></li>
        <li><a id="btn-inscription" onclick="switchTab('inscription')"><i class="fa-solid fa-user-plus"></i> Inscrire un étudiant</a></li>
        <li><a id="btn-inscription_prof" onclick="switchTab('inscription_prof')"><i class="fa-solid fa-chalkboard-user"></i> Inscrire un enseignant</a></li>
        <li><a id="btn-planification" onclick="switchTab('planification')"><i class="fa-solid fa-calendar-plus"></i> Planifier un cours</a></li>
        <li><a id="btn-calendar" onclick="switchTab('calendar')"><i class="fa-solid fa-calendar-days"></i> Emploi du temps</a></li>
        <li><a href="deconnexion.php" style="color:#FEB2B2;"><i class="fa-solid fa-power-off"></i> Déconnexion</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="header-panel">
        <h2>Panel Administration</h2>
        <span>Bienvenue, <strong>Admin <?php echo htmlspecialchars($_SESSION['user_nom']); ?></strong></span>
    </div>

    <?php echo $msg_status; ?>

    <div id="tab-dashboard" class="tab-content" style="display: block;">
        <div class="grid-stats">
            <div class="stat-box stat-std"><h3><?php echo $count_std; ?></h3><p>Étudiants</p></div>
            <div class="stat-box stat-prf"><h3><?php echo $count_prf; ?></h3><p>Enseignants</p></div>
            <div class="stat-box stat-crs"><h3><?php echo $count_crs; ?></h3><p>Cours</p></div>
        </div>
        <div class="card">
            <h3>Résumé de l'activité</h3>
            <p>Depuis cet espace, vous pouvez gérer les ressources humaines du campus et organiser les séances de cours dans les différentes salles disponibles.</p>
        </div>
    </div>

    <div id="tab-students" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-users"></i> Étudiants inscrits</h3>
            <table>
                <thead>
                    <tr><th>Nom & Prénom</th><th>Email</th><th>Promotion</th><th>Classe (TD)</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach($students as $s): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($s['nom'].' '.$s['prenom']); ?></strong></td>
                        <td><?php echo htmlspecialchars($s['email']); ?></td>
                        <td><span style="color:#2B6CB0; font-weight:bold;"><?php echo htmlspecialchars($s['promo_nom'] ?? 'Non assigné'); ?></span></td>
                        <td><span style="background:#E2E8F0; padding:4px 8px; border-radius:4px; font-size: 0.9em;"><?php echo htmlspecialchars($s['groupe_nom'] ?? 'Aucune'); ?></span></td>
                        <td>
                            <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($s)); ?>)">
                                <i class="fa-solid fa-pen-to-square"></i> Modifier
                            </button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cet étudiant ? Cette action est irréversible.');">
                                <input type="hidden" name="user_id" value="<?php echo $s['id']; ?>">
                                <button type="submit" name="supprimer_etudiant" class="btn-delete">
                                    <i class="fa-solid fa-trash"></i> Supprimer
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL DE MODIFICATION -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Modifier le profil étudiant</h3>
                <span class="close-modal" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST">
                <input type="hidden" name="user_id" id="edit_user_id">
                
                <label>Nom</label>
                <input type="text" name="nom" id="edit_nom" required>

                <label>Prénom</label>
                <input type="text" name="prenom" id="edit_prenom" required>

                <label>Email</label>
                <input type="email" name="email" id="edit_email" required>

                <label>Promotion</label>
                <select name="promotion_id" id="edit_promotion_id" required>
                    <?php foreach($list_promotions as $promo): ?>
                        <option value="<?php echo $promo['id']; ?>"><?php echo htmlspecialchars($promo['nom_promotion']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Classe (Groupe TD)</label>
                <select name="groupe_td_id" id="edit_groupe_td_id">
                    <option value="">-- Sans classe --</option>
                    <?php foreach($list_groupes as $grp): ?>
                        <option value="<?php echo $grp['id']; ?>"><?php echo htmlspecialchars($grp['nom_promotion'] . ' - ' . $grp['groupe_nom']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Statut Parcours</label>
                <select name="statut_parcours" id="edit_statut_parcours" required>
                    <option value="initial">Initial</option>
                    <option value="alternant">Alternant</option>
                </select>

                <button type="submit" name="modifier_etudiant">Enregistrer les modifications</button>
            </form>
        </div>
    </div>

    <div id="tab-inscription" class="tab-content">
        <div class="card" style="max-width: 600px; margin: 0 auto;">
            <h3><i class="fa-solid fa-user-plus"></i> Inscrire un nouvel étudiant</h3>
            <form method="POST">
                <label>Nom</label>
                <input type="text" name="nom" required placeholder="Ex: MARTIN">

                <label>Prénom</label>
                <input type="text" name="prenom" required placeholder="Ex: Jean">

                <label>Email École</label>
                <input type="email" name="email" required placeholder="Ex: jean.martin@ecole.fr">

                <label>Mot de passe provisoire</label>
                <input type="password" name="password" required value="Etudiant2026!">

                <label>Promotion</label>
                <select name="promotion_id" required>
                    <?php foreach($list_promotions as $promo): ?>
                        <option value="<?php echo $promo['id']; ?>"><?php echo htmlspecialchars($promo['nom_promotion'] . ' (' . $promo['annee_academique'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Classe (Groupe TD)</label>
                <select name="groupe_td_id">
                    <option value="">-- Sans classe pour le moment --</option>
                    <?php foreach($list_groupes as $grp): ?>
                        <option value="<?php echo $grp['id']; ?>"><?php echo htmlspecialchars($grp['nom_promotion'] . ' - ' . $grp['groupe_nom']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Statut Parcours</label>
                <select name="statut_parcours" required>
                    <option value="initial">Initial</option>
                    <option value="alternant">Alternant</option>
                </select>

                <button type="submit" name="inscrire_etudiant">Valider l'inscription</button>
            </form>
        </div>
    </div>

    <div id="tab-inscription_prof" class="tab-content">
        <div class="card" style="max-width: 600px; margin: 0 auto;">
            <h3><i class="fa-solid fa-chalkboard-user"></i> Inscrire un nouvel enseignant</h3>
            <form method="POST">
                <label>Nom</label>
                <input type="text" name="nom" required placeholder="Ex: DUPONT">

                <label>Prénom</label>
                <input type="text" name="prenom" required placeholder="Ex: Pierre">

                <label>Email École</label>
                <input type="email" name="email" required placeholder="Ex: pierre.dupont@ecole.fr">

                <label>Mot de passe provisoire</label>
                <input type="password" name="password" required value="Enseignant2026!">

                <label>Spécialité / Discipline</label>
                <input type="text" name="specialite" placeholder="Ex: Mathématiques, Développement Web...">

                <button type="submit" name="inscrire_enseignant">Valider l'inscription</button>
            </form>
        </div>
    </div>

    <div id="tab-planification" class="tab-content">
        <div class="card" style="max-width: 600px; margin: 0 auto;">
            <h3><i class="fa-solid fa-calendar-plus"></i> Programmer une séance</h3>
            <form method="POST">
                <label>Cours / Matière</label>
                <select name="cours_id" required>
                    <?php foreach($list_cours as $c): ?>
                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nom_cours']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Enseignant</label>
                <select name="enseignant_id" required>
                    <?php foreach($list_profs as $p): ?>
                        <option value="<?php echo $p['id']; ?>">M. <?php echo htmlspecialchars($p['nom'].' '.$p['prenom']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Salle</label>
                <input type="text" name="salle" required placeholder="Ex: Labo 3, Amphi A...">

                <label>Date</label>
                <input type="date" name="date_cours" required>

                <label>Heure Début</label>
                <input type="time" name="heure_debut" required>

                <label>Heure Fin</label>
                <input type="time" name="heure_fin" required>

                <button type="submit" name="planifier_cours">Enregistrer la séance</button>
            </form>
        </div>
    </div>

    <div id="tab-calendar" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-calendar-days"></i> Emploi du temps global</h3>
            <table>
                <thead>
                    <tr><th>Date</th><th>Horaire</th><th>Cours</th><th>Professeur</th><th>Salle</th></tr>
                </thead>
                <tbody>
                    <?php foreach($allSessions as $sess): ?>
                    <tr>
                        <td><strong><?php echo date('d/m/Y', strtotime($sess['date_cours'])); ?></strong></td>
                        <td style="color:var(--rouge-ecole); font-weight:bold;"><?php echo substr($sess['heure_debut'],0,5); ?> - <?php echo substr($sess['heure_fin'],0,5); ?></td>
                        <td><?php echo htmlspecialchars($sess['nom_cours']); ?></td>
                        <td>M. <?php echo htmlspecialchars($sess['prof_nom']); ?></td>
                        <td><span style="background:#E2E8F0; padding:4px 8px; border-radius:4px;"><?php echo htmlspecialchars($sess['salle']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function switchTab(name) {
        // Cacher tous les contenus
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        // Retirer la classe active de tous les boutons
        document.querySelectorAll('.sidebar-menu li a').forEach(b => b.classList.remove('active'));
        
        // Afficher l'onglet demandé
        document.getElementById('tab-' + name).style.display = 'block';
        // Activer le bouton cliqué
        document.getElementById('btn-' + name).classList.add('active');
    }

    // Gestion du retour après POST
    <?php if($active_tab !== 'dashboard'): ?>
        switchTab('<?php echo $active_tab; ?>');
    <?php endif; ?>

    // Fonctions pour le Modal de Modification
    function openEditModal(student) {
        document.getElementById('edit_user_id').value = student.id;
        document.getElementById('edit_nom').value = student.nom;
        document.getElementById('edit_prenom').value = student.prenom;
        document.getElementById('edit_email').value = student.email;
        document.getElementById('edit_promotion_id').value = student.promo_id;
        document.getElementById('edit_groupe_td_id').value = student.groupe_id || "";
        document.getElementById('edit_statut_parcours').value = student.statut_parcours;
        
        document.getElementById('editModal').style.display = 'block';
    }

    function closeEditModal() {
        document.getElementById('editModal').style.display = 'none';
    }

    // Fermer le modal si on clique en dehors
    window.onclick = function(event) {
        if (event.target == document.getElementById('editModal')) {
            closeEditModal();
        }
    }
</script>

</body>
</html>