<?php
// 1. CHARGEMENT DE LA CONFIGURATION ET DE LA SESSION
require_once 'config.php';

// 2. PROTECTION DE LA ROUTE (SÉCURITÉ EXIGÉE PAR LE SUJET)
// Si l'utilisateur n'est pas connecté ou n'est pas un étudiant, on le renvoie au login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'etudiant') {
    header('Location: login.php');
    exit();
}

$etudiant_id = $_SESSION['user_id'];

// 3. RÉCUPÉRATION DES DONNÉES DE L'ÉTUDIANT DEPUIS LA BASE
try {
    // Infos du profil + promotion + majeure
    $queryUser = "SELECT u.nom, u.prenom, p.nom_promotion, m.nom_majeure, e.statut_parcours, e.score_toeic 
                  FROM utilisateurs u
                  JOIN etudiants e ON u.id = e.utilisateur_id
                  JOIN promotions p ON e.promotion_id = p.id
                  LEFT JOIN majeures m ON p.majeure_id = m.id
                  WHERE u.id = ?";
    $stmtUser = $pdo->prepare($queryUser);
    $stmtUser->execute([$etudiant_id]);
    $student = $stmtUser->fetch();

    // Compteur d'absences injustifiées (Règle métier : Seuil alerte)
    $queryAbsences = "SELECT COUNT(*) as total_absences FROM presences 
                      WHERE etudiant_id = ? AND statut_presence = 'absent_injustifie'";
    $stmtAbsences = $pdo->prepare($queryAbsences);
    $stmtAbsences->execute([$etudiant_id]);
    $absencesData = $stmtAbsences->fetch();
    $nb_absences = $absencesData['total_absences'];

    // Notes récentes (avec calcul de la pondération 40% CC / 60% Examen)
    $queryNotes = "SELECT c.nom_cours, n.note_valeur, n.type_evaluation 
                   FROM notes n
                   JOIN cours c ON n.cours_id = c.id
                   WHERE n.etudiant_id = ? 
                   ORDER BY n.date_saisie DESC LIMIT 4";
    $stmtNotes = $pdo->prepare($queryNotes);
    $stmtNotes->execute([$etudiant_id]);
    $notesRecentes = $stmtNotes->fetchAll();

    // Emploi du temps du jour (Exemple calé sur la date de test du script SQL : 2026-05-25)
    $dateDuJour = '2026-05-25'; 
    $queryPlanning = "SELECT s.heure_debut, s.heure_fin, s.salle_id, sa.nom_salle, c.nom_cours, u.nom as prof_nom
                      FROM sessions_cours s
                      JOIN cours c ON s.cours_id = c.id
                      JOIN salles sa ON s.salle_id = sa.id
                      JOIN utilisateurs u ON s.enseignant_id = u.id
                      WHERE s.date_cours = ?
                      ORDER BY s.heure_debut ASC";
    $stmtPlanning = $pdo->prepare($queryPlanning);
    $stmtPlanning->execute([$dateDuJour]);
    $planningduJour = $stmtPlanning->fetchAll();

} catch (PDOException $e) {
    die("Erreur lors de la récupération des données : " . $e->getMessage());
}

// Calcul dynamique de la jauge d'absence pour le CSS (Seuil de 15 absences max)
$pourcentage_jauge = min(($nb_absences / 15) * 100, 100);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartCampus - Tableau de Bord Étudiant</title>
    <link href="https://fonts.googleapis.com/css2?family=Segoe+UI:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* --- STYLES ET VARIABLES (CONFORME À ÉCOLE DIRECTE) --- */
        :root {
            --bleu-marine-dark: #0A2240;  
            --bleu-marine-light: #1A365D; 
            --rouge-ecole: #D9383A;       
            --rouge-hover: #B8282A;       
            --texte-sombre: #2D3748;      
            --fond-gris-clair: #F7FAFC;   
            --fond-carte: #FFFFFF;        
            --bordure: #E2E8F0;           
            --succes: #2F855A;            
            --orange-alerte: #DD6B20;     
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: var(--fond-gris-clair); color: var(--texte-sombre); display: flex; min-height: 100vh; }

        /* --- SIDEBAR --- */
        .sidebar { width: 260px; background-color: var(--bleu-marine-dark); color: #ffffff; display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; }
        .sidebar-logo { padding: 25px 20px; text-align: center; border-bottom: 1px solid rgba(255, 255, 255, 0.1); }
        .sidebar-logo h1 { font-size: 22px; font-weight: 700; color: #ffffff; }
        .sidebar-logo span { color: var(--rouge-ecole); }
        .sidebar-menu { list-style: none; padding: 20px 0; flex-grow: 1; }
        .sidebar-menu li a { display: flex; align-items: center; padding: 15px 25px; color: #E2E8F0; text-decoration: none; font-size: 15px; transition: all 0.3s; }
        .sidebar-menu li a i { margin-right: 15px; font-size: 18px; width: 25px; text-align: center; }
        .sidebar-menu li a:hover, .sidebar-menu li.active a { background-color: var(--bleu-marine-light); color: #ffffff; border-left: 4px solid var(--rouge-ecole); }
        .sidebar-footer { padding: 20px; border-top: 1px solid rgba(255, 255, 255, 0.1); font-size: 13px; color: #A0AEC0; text-align: center; }

        /* --- CONTENU --- */
        .main-content { margin-left: 260px; flex-grow: 1; padding: 30px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; background: var(--fond-carte); padding: 15px 25px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); border: 1px solid var(--bordure); }
        .page-title h2 { font-size: 24px; color: var(--bleu-marine-dark); }
        .user-profile { display: flex; align-items: center; gap: 20px; }
        .user-info { text-align: right; }
        .user-name { font-weight: 600; color: var(--bleu-marine-dark); display: block; }
        .user-role { font-size: 13px; color: #718096; display: block; margin-bottom: 2px; }
        .global-badge { background-color: var(--bleu-marine-dark); color: white; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; border: 1px solid var(--rouge-ecole); }
        .user-avatar { width: 45px; height: 45px; border-radius: 50%; background-color: var(--bleu-marine-dark); color: white; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid var(--rouge-ecole); }

        /* --- DASHBOARD GRID --- */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; }
        .left-column, .right-column { display: flex; flex-direction: column; gap: 25px; }
        .card { background-color: var(--fond-carte); border-radius: 8px; border: 1px solid var(--bordure); box-shadow: 0 4px 6px rgba(0,0,0,0.02); overflow: hidden; }
        .card-header { background-color: var(--bleu-marine-dark); color: white; padding: 15px 20px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; font-size: 16px; }
        .card-body { padding: 20px; }

        /* --- ABSENCES COMPOSANT --- */
        .absences-container { display: flex; align-items: center; justify-content: space-between; gap: 30px; }
        .absences-stats { flex-grow: 1; }
        .progress-bar-bg { background-color: #E2E8F0; height: 12px; border-radius: 10px; margin-top: 10px; overflow: hidden; }
        .progress-bar-fill { background-color: var(--orange-alerte); width: <?php echo $pourcentage_jauge; ?>%; height: 100%; border-radius: 10px; transition: width 0.5s; }
        .absences-warning { font-size: 13px; color: #718096; margin-top: 8px; display: flex; align-items: center; gap: 5px; }
        .absences-warning i { color: var(--orange-alerte); }
        .absences-counter { background-color: rgba(217, 56, 58, 0.1); color: var(--rouge-ecole); padding: 15px 25px; border-radius: 8px; text-align: center; border: 1px solid rgba(217, 56, 58, 0.2); }
        .absences-number { font-size: 32px; font-weight: 700; }
        .absences-label { font-size: 11px; text-transform: uppercase; font-weight: 600; }

        /* --- TIMETABLE COMPOSANT --- */
        .timetable-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid var(--bordure); padding-bottom: 10px; }
        .timetable-day { font-weight: 700; color: var(--bleu-marine-dark); font-size: 16px; }
        .timetable-row { display: flex; padding: 15px 0; border-bottom: 1px solid var(--bordure); align-items: center; }
        .timetable-row:last-child { border-bottom: none; }
        .time-col { width: 100px; font-weight: 600; color: var(--rouge-ecole); font-size: 14px; }
        .course-info-col { flex-grow: 1; }
        .course-title { font-weight: 600; font-size: 15px; }
        .course-details { font-size: 13px; color: #718096; margin-top: 3px; }
        .status-col { width: 120px; text-align: right; }
        .badge-status { font-size: 12px; padding: 4px 10px; border-radius: 12px; font-weight: 600; display: inline-block; }
        .status-validated { background-color: #C6F6D5; color: var(--succes); }

        /* --- QR CODE COMPOSANT --- */
        .qr-section { text-align: center; display: flex; flex-direction: column; align-items: center; gap: 15px; }
        .qr-placeholder { width: 100px; height: 100px; border: 2px dashed var(--bleu-marine-dark); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: var(--bleu-marine-dark); background-color: #F8FAFC; }
        .btn-qr { background-color: var(--rouge-ecole); color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; width: 100%; transition: 0.3s; }
        .btn-qr:hover { background-color: var(--rouge-hover); }

        /* --- NOTES COMPOSANT --- */
        .notes-table { width: 100%; border-collapse: collapse; }
        .notes-table th, .notes-table td { padding: 12px 10px; border-bottom: 1px solid var(--bordure); font-size: 13px; text-align: left; }
        .notes-table th { font-weight: 600; color: #718096; text-transform: uppercase; font-size: 11px; }
        .note-badge { font-weight: 700; color: var(--bleu-marine-dark); }

        @media (max-width: 1024px) { .dashboard-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-logo">
            <h1>Smart<span>Campus</span></h1>
        </div>
        <ul class="sidebar-menu">
            <li class="active"><a href="#"><i class="fa-solid fa-chart-pie"></i>Tableau de bord</a></li>
            <li><a href="#"><i class="fa-solid fa-graduation-cap"></i>Mes Notes</a></li>
            <li><a href="#"><i class="fa-solid fa-calendar-days"></i>Emploi du temps</a></li>
            <li><a href="#"><i class="fa-solid fa-qrcode"></i>Présences</a></li>
            <li><a href="deconnexion.php" style="color: #FC8181;"><i class="fa-solid fa-power-off"></i>Déconnexion</a></li>
        </ul>
        <div class="sidebar-footer">
            <p>© 2026 SmartCampus</p>
            <small><?php echo htmlspecialchars($student['nom_promotion']); ?></small>
        </div>
    </div>

    <div class="main-content">
        
        <div class="topbar">
            <div class="page-title">
                <h2>Espace Étudiant</h2>
            </div>
            <div class="user-profile">
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($student['prenom'] . ' ' . $student['nom']); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($student['nom_promotion']); ?> - <?php echo htmlspecialchars($student['nom_majeure'] ?? 'Tronc Commun'); ?></span>
                    <span class="global-badge">TOEIC : <?php echo $student['score_toeic'] ? $student['score_toeic'].' pts' : 'Non saisi'; ?></span>
                </div>
                <div class="user-avatar">
                    <?php echo strtoupper(substr($student['prenom'], 0, 1) . substr($student['nom'], 0, 1)); ?>
                </div>
            </div>
        </div>

        <div class="dashboard-grid">
            
            <div class="left-column">
                
                <div class="card">
                    <div class="card-header">
                        <span>Suivi d'Assiduité Académique (Semestriel)</span>
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div class="card-body absences-container">
                        <div class="absences-stats">
                            <strong style="font-size: 15px;">Seuil critique avant avertissement administratif</strong>
                            <div class="progress-bar-bg">
                                <div class="progress-bar-fill"></div>
                            </div>
                            <div class="absences-warning">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <span>Vous avez cumulé <?php echo $nb_absences; ?> absences. Le règlement de l'école fixe la limite à 15 avant sanction.</span>
                            </div>
                        </div>
                        <div class="absences-counter">
                            <div class="absences-number"><?php echo $nb_absences; ?></div>
                            <div class="absences-label">Absences</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span>Emploi du Temps du jour</span>
                        <i class="fa-solid fa-calendar-week"></i>
                    </div>
                    <div class="card-body">
                        <div class="timetable-header">
                            <div class="timetable-day">Lundi 25 Mai 2026</div>
                        </div>

                        <?php if (empty($planningduJour)): ?>
                            <p style="color: #718096; text-align: center; padding: 20px;">Aucun cours planifié pour cette journée.</p>
                        <?php else: ?>
                            <?php foreach ($planningduJour as $cours): ?>
                                <div class="timetable-row">
                                    <div class="time-col"><?php echo substr($cours['heure_debut'], 0, 5); ?> - <?php echo substr($cours['heure_fin'], 0, 5); ?></div>
                                    <div class="course-info-col">
                                        <div class="course-title"><?php echo htmlspecialchars($cours['nom_cours']); ?></div>
                                        <div class="course-details">M. <?php echo htmlspecialchars($cours['prof_nom']); ?> • <strong><?php echo htmlspecialchars($cours['nom_salle']); ?></strong></div>
                                    </div>
                                    <div class="status-col">
                                        <span class="badge-status status-validated"><i class="fa-solid fa-circle-check"></i> Planifié</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>
                </div>
            </div> 

            <div class="right-column">
                
                <div class="card">
                    <div class="card-header">
                        <span>Présence Directe QR Code</span>
                        <i class="fa-solid fa-qrcode"></i>
                    </div>
                    <div class="card-body qr-section">
                        <div class="qr-placeholder">
                            <i class="fa-solid fa-qrcode fa-4x"></i>
                        </div>
                        <button class="btn-qr"><i class="fa-solid fa-camera"></i> Enregistrer ma présence</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <span>Notes Récentes</span>
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <div class="card-body" style="padding: 10px 15px;">
                        <table class="notes-table">
                            <thead>
                                <tr>
                                    <th>Module</th>
                                    <th>Éval</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($notesRecentes)): ?>
                                    <tr><td colspan="3" style="text-align: center; color: #718096;">Aucune note saisie.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($notesRecentes as $note): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($note['nom_cours']); ?></td>
                                            <td><small class="global-badge" style="border:none; padding: 2px 6px; font-size:10px;"><?php echo $note['type_evaluation']; ?></small></td>
                                            <td class="note-badge"><?php echo number_format($note['note_valeur'], 2); ?>/20</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div> 
            
        </div> 
    </div> 

</body>
</html>