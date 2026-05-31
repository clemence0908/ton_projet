<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'config.php';

// 1. PROTECTION DE LA ROUTE
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

// --- AUTO-INITIALISATION DE LA STRUCTURE (AMPHIS / TD) ---
// Si les tables sont vides, on crée 3 Amphis et 6 TDs par promotion.
try {
    $promos = $pdo->query("SELECT id FROM promotions")->fetchAll();
    foreach ($promos as $p) {
        $p_id = $p['id'];
        $amphi_count = $pdo->query("SELECT COUNT(*) FROM amphis WHERE promotion_id = $p_id")->fetchColumn();
        if ($amphi_count == 0) {
            for ($i = 1; $i <= 3; $i++) {
                $pdo->prepare("INSERT INTO amphis (nom, promotion_id) VALUES (?, ?)")->execute(["Amphi " . $i, $p_id]);
                $amphi_id = $pdo->lastInsertId();
                $start_td = ($i - 1) * 2 + 1;
                for ($j = $start_td; $j <= $start_td + 1; $j++) {
                    $pdo->prepare("INSERT INTO groupes_td (nom, amphi_id) VALUES (?, ?)")->execute(["TD" . $j, $amphi_id]);
                }
            }
        }
    }
} catch (Exception $e) { /* Ignore setup errors to not break dashboard */ }
// ---------------------------------------------------------

$msg_status = "";
$active_tab = "dashboard";

// 2. TRAITEMENT DES SOUMISSIONS (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // A. Inscription d'un nouvel étudiant
    if (isset($_POST['inscrire_etudiant'])) {
        $active_tab = "inscription";
        $nom = trim($_POST['nom']);
        $prenom = trim($_POST['prenom']);
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $password = trim($_POST['password']);
        $promotion_id = intval($_POST['promotion_id']);
        $groupe_td_id = intval($_POST['groupe_td_id']); // Obligatoire
        $statut_parcours = $_POST['statut_parcours'];

        if (!empty($nom) && !empty($prenom) && !empty($email) && !empty($password) && !empty($groupe_td_id)) {
            try {
                // 1. Vérifier si l'email existe
                $checkEmail = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE email = ?");
                $checkEmail->execute([$email]);
                
                // 2. Vérifier la capacité du TD (Max 25 places)
                $checkCapacity = $pdo->prepare("SELECT COUNT(*) FROM etudiants WHERE groupe_td_id = ?");
                $checkCapacity->execute([$groupe_td_id]);
                $current_td_count = $checkCapacity->fetchColumn();

                if ($checkEmail->fetchColumn() > 0) {
                    $msg_status = "<div class='alert error'>❌ Erreur : Cet email est déjà utilisé.</div>";
                } elseif ($current_td_count >= 25) {
                    $msg_status = "<div class='alert error'>❌ Erreur : Ce groupe de TD est complet (25/25 étudiants max).</div>";
                } else {
                    $pdo->beginTransaction();
                    $stmtUser = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_pass, role) VALUES (?, ?, ?, ?, 'etudiant')");
                    $stmtUser->execute([$nom, $prenom, $email, $password]);
                    $user_id = $pdo->lastInsertId();
                    $stmtEtudiant = $pdo->prepare("INSERT INTO etudiants (utilisateur_id, promotion_id, groupe_td_id, statut_parcours) VALUES (?, ?, ?, ?)");
                    $stmtEtudiant->execute([$user_id, $promotion_id, $groupe_td_id, $statut_parcours]);
                    $pdo->commit();
                    $msg_status = "<div class='alert success'>✅ Étudiant inscrit avec succès !</div>";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
            }
        } else {
            $msg_status = "<div class='alert error'>❌ Veuillez remplir tous les champs, y compris le groupe de TD.</div>";
        }
    }

    // B. Modification d'un étudiant
    if (isset($_POST['modifier_etudiant'])) {
        $active_tab = "students";
        $id_user = intval($_POST['user_id']);
        $nom = trim($_POST['nom']);
        $prenom = trim($_POST['prenom']);
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $promotion_id = intval($_POST['promotion_id']);
        $groupe_td_id = intval($_POST['groupe_td_id']); // Obligatoire
        $statut_parcours = $_POST['statut_parcours'];

        if (!empty($nom) && !empty($prenom) && !empty($email) && !empty($groupe_td_id)) {
            try {
                // Vérifier la capacité du TD en excluant l'étudiant actuel
                $checkCapacity = $pdo->prepare("SELECT COUNT(*) FROM etudiants WHERE groupe_td_id = ? AND utilisateur_id != ?");
                $checkCapacity->execute([$groupe_td_id, $id_user]);
                $current_td_count = $checkCapacity->fetchColumn();

                if ($current_td_count >= 25) {
                    $msg_status = "<div class='alert error'>❌ Erreur : Ce groupe de TD est complet (25/25 étudiants max).</div>";
                } else {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ? WHERE id = ?")->execute([$nom, $prenom, $email, $id_user]);
                    $pdo->prepare("UPDATE etudiants SET promotion_id = ?, groupe_td_id = ?, statut_parcours = ? WHERE utilisateur_id = ?")->execute([$promotion_id, $groupe_td_id, $statut_parcours, $id_user]);
                    $pdo->commit();
                    $msg_status = "<div class='alert success'>✅ Profil mis à jour !</div>";
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
            }
        }
    }

    // C. Suppression d'un étudiant
    if (isset($_POST['supprimer_etudiant'])) {
        $active_tab = "students";
        $id_user = intval($_POST['user_id']);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM presences WHERE etudiant_id = ?")->execute([$id_user]);
            $pdo->prepare("DELETE FROM notes WHERE etudiant_id = ?")->execute([$id_user]);
            $pdo->prepare("DELETE FROM inscriptions_cours WHERE etudiant_id = ?")->execute([$id_user]);
            $pdo->prepare("DELETE FROM etudiants WHERE utilisateur_id = ?")->execute([$id_user]);
            $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$id_user]);
            $pdo->commit();
            $msg_status = "<div class='alert success'>🗑️ Étudiant supprimé !</div>";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
        }
    }


    // C2. Inscription d'un étudiant à un cours
    if (isset($_POST['inscrire_cours_etudiant'])) {
        $active_tab = "inscriptions_cours";
        $etudiant_id = intval($_POST['etudiant_id']);
        $cours_id = intval($_POST['cours_id']);

        if (!empty($etudiant_id) && !empty($cours_id)) {
            try {
                // Vérifier que l'étudiant existe et récupérer sa promotion
                $stmtStudent = $pdo->prepare("SELECT e.promotion_id, u.nom, u.prenom 
                                              FROM etudiants e
                                              JOIN utilisateurs u ON u.id = e.utilisateur_id
                                              WHERE e.utilisateur_id = ?");
                $stmtStudent->execute([$etudiant_id]);
                $studentInfo = $stmtStudent->fetch();

                // Vérifier que le cours existe et récupérer sa promotion cible
                $stmtCourse = $pdo->prepare("SELECT id, nom_cours, promotion_id FROM cours WHERE id = ?");
                $stmtCourse->execute([$cours_id]);
                $courseInfo = $stmtCourse->fetch();

                if (!$studentInfo) {
                    $msg_status = "<div class='alert error'>❌ Étudiant introuvable.</div>";
                } elseif (!$courseInfo) {
                    $msg_status = "<div class='alert error'>❌ Cours introuvable.</div>";
                } elseif (!empty($courseInfo['promotion_id']) && intval($courseInfo['promotion_id']) !== intval($studentInfo['promotion_id'])) {
                    $msg_status = "<div class='alert error'>❌ Impossible : ce cours n'est pas associé à la promotion de cet étudiant.</div>";
                } else {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM inscriptions_cours WHERE etudiant_id = ? AND cours_id = ?");
                    $check->execute([$etudiant_id, $cours_id]);

                    if ($check->fetchColumn() > 0) {
                        $msg_status = "<div class='alert error'>⚠️ Cet étudiant est déjà inscrit à ce cours.</div>";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO inscriptions_cours (etudiant_id, cours_id, date_inscription) VALUES (?, ?, CURDATE())");
                        $stmt->execute([$etudiant_id, $cours_id]);
                        $msg_status = "<div class='alert success'>✅ Étudiant inscrit au cours avec succès !</div>";
                    }
                }
            } catch (PDOException $e) {
                $msg_status = "<div class='alert error'>❌ Erreur SQL : " . $e->getMessage() . "</div>";
            }
        } else {
            $msg_status = "<div class='alert error'>❌ Veuillez sélectionner un étudiant et un cours.</div>";
        }
    }

    // C3. Suppression d'une inscription à un cours
    if (isset($_POST['supprimer_inscription_cours'])) {
        $active_tab = "inscriptions_cours";
        $etudiant_id = intval($_POST['etudiant_id']);
        $cours_id = intval($_POST['cours_id']);

        try {
            $stmt = $pdo->prepare("DELETE FROM inscriptions_cours WHERE etudiant_id = ? AND cours_id = ?");
            $stmt->execute([$etudiant_id, $cours_id]);
            $msg_status = "<div class='alert success'>🗑️ Inscription au cours supprimée.</div>";
        } catch (PDOException $e) {
            $msg_status = "<div class='alert error'>❌ Erreur SQL : " . $e->getMessage() . "</div>";
        }
    }

    // D. Inscription Enseignant
    if (isset($_POST['inscrire_enseignant'])) {
        $active_tab = "inscription_prof";
        $nom = trim($_POST['nom']);
        $prenom = trim($_POST['prenom']);
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        $password = trim($_POST['password']);
        try {
            $stmtUser = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_pass, role) VALUES (?, ?, ?, ?, 'enseignant')");
            $stmtUser->execute([$nom, $prenom, $email, $password]);
            $msg_status = "<div class='alert success'>✅ Enseignant inscrit !</div>";
        } catch (PDOException $e) {
            $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
        }
    }

    // E. Modification Enseignant
    if (isset($_POST['modifier_enseignant'])) {
        $active_tab = "teachers";
        $id_user = intval($_POST['user_id']);
        $nom = trim($_POST['nom']);
        $prenom = trim($_POST['prenom']);
        $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
        try {
            $pdo->prepare("UPDATE utilisateurs SET nom = ?, prenom = ?, email = ? WHERE id = ? AND role = 'enseignant'")->execute([$nom, $prenom, $email, $id_user]);
            $msg_status = "<div class='alert success'>✅ Profil enseignant mis à jour !</div>";
        } catch (PDOException $e) {
            $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
        }
    }

    // F. Suppression Enseignant
    if (isset($_POST['supprimer_enseignant'])) {
        $active_tab = "teachers";
        $id_user = intval($_POST['user_id']);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM sessions_cours WHERE enseignant_id = ?")->execute([$id_user]);
            $pdo->prepare("DELETE FROM notes WHERE enseignant_id = ?")->execute([$id_user]);
            $pdo->prepare("DELETE FROM utilisateurs WHERE id = ? AND role = 'enseignant'")->execute([$id_user]);
            $pdo->commit();
            $msg_status = "<div class='alert success'>🗑️ Enseignant supprimé !</div>";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
        }
    }

    // G. Création de Cours (Correction : ajout de ue_id et promotion_id)
    if (isset($_POST['creer_cours'])) {
        $active_tab = "courses_schedule";
        $nom_cours = trim($_POST['nom_cours']);
        $ue_id = intval($_POST['ue_id']);
        $promotion_id = intval($_POST['promotion_id']);
        try {
            $pdo->prepare("INSERT INTO cours (nom_cours, ue_id, promotion_id) VALUES (?, ?, ?)")->execute([$nom_cours, $ue_id, $promotion_id]);
            $msg_status = "<div class='alert success'>✅ Cours créé avec succès !</div>";
        } catch (PDOException $e) {
            $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
        }
    }

    // H. Planification Session par cours
    // Une séance est liée à un cours + un professeur + une salle.
    // Tous les étudiants inscrits à ce cours verront automatiquement la même séance.
    if (isset($_POST['planifier_session'])) {
        $active_tab = "courses_schedule";
        $cours_id = intval($_POST['cours_id']);
        $enseignant_id = intval($_POST['enseignant_id']);
        $salle_id = intval($_POST['salle_id']);
        $date_cours = $_POST['date_cours'];
        $heure_debut = $_POST['heure_debut'];
        $heure_fin = $_POST['heure_fin'];

        if ($cours_id <= 0 || $enseignant_id <= 0 || $salle_id <= 0 || empty($date_cours) || empty($heure_debut) || empty($heure_fin)) {
            $msg_status = "<div class='alert error'>❌ Veuillez remplir tous les champs de la séance.</div>";
        } elseif (strtotime($heure_debut) >= strtotime($heure_fin)) {
            $msg_status = "<div class='alert error'>❌ L'heure de fin doit être après l'heure de début.</div>";
        } else {
            try {
                $conflit_msg = "";

                // Conflit enseignant
                $checkProf = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours WHERE enseignant_id = ? AND date_cours = ? AND heure_debut < ? AND heure_fin > ?");
                $checkProf->execute([$enseignant_id, $date_cours, $heure_fin, $heure_debut]);
                if ($checkProf->fetchColumn() > 0) {
                    $conflit_msg .= "L'enseignant est déjà occupé. ";
                }

                // Conflit salle
                $checkSalle = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours WHERE salle_id = ? AND date_cours = ? AND heure_debut < ? AND heure_fin > ?");
                $checkSalle->execute([$salle_id, $date_cours, $heure_fin, $heure_debut]);
                if ($checkSalle->fetchColumn() > 0) {
                    $conflit_msg .= "La salle est déjà réservée. ";
                }

                // Conflit pour le même cours
                $checkCours = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours WHERE cours_id = ? AND date_cours = ? AND heure_debut < ? AND heure_fin > ?");
                $checkCours->execute([$cours_id, $date_cours, $heure_fin, $heure_debut]);
                if ($checkCours->fetchColumn() > 0) {
                    $conflit_msg .= "Ce cours a déjà une séance sur ce créneau. ";
                }

                if (!empty($conflit_msg)) {
                    $msg_status = "<div class='alert error'>⚠️ <strong>Conflit détecté :</strong> " . $conflit_msg . "</div>";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO sessions_cours (cours_id, enseignant_id, salle_id, date_cours, heure_debut, heure_fin, groupe_td_id) VALUES (?, ?, ?, ?, ?, ?, NULL)");
                    $stmt->execute([$cours_id, $enseignant_id, $salle_id, $date_cours, $heure_debut, $heure_fin]);
                    $msg_status = "<div class='alert success'>✅ Séance planifiée pour ce cours ! Les étudiants inscrits à ce cours la verront dans leur emploi du temps.</div>";
                }
            } catch (PDOException $e) {
                $msg_status = "<div class='alert error'>❌ Erreur SQL : " . $e->getMessage() . "</div>";
            }
        }
    }
    // I. Modification d'une session
    if (isset($_POST['modifier_session'])) {
        $active_tab = "courses_schedule";
        $session_id = intval($_POST['session_id']);
        $enseignant_id = intval($_POST['enseignant_id']);
        $salle_id = intval($_POST['salle_id']);
        $date_cours = $_POST['date_cours'];
        $heure_debut = $_POST['heure_debut'];
        $heure_fin = $_POST['heure_fin'];

        if (strtotime($heure_debut) >= strtotime($heure_fin)) {
            $msg_status = "<div class='alert error'>❌ L'heure de fin doit être après l'heure de début.</div>";
        } else {
            try {
                // Détection de conflit simple (excluant la session actuelle)
                $checkConflit = $pdo->prepare("SELECT COUNT(*) FROM sessions_cours 
                                               WHERE id != ? AND (enseignant_id = ? OR salle_id = ?) 
                                               AND date_cours = ? AND heure_debut < ? AND heure_fin > ?");
                $checkConflit->execute([$session_id, $enseignant_id, $salle_id, $date_cours, $heure_fin, $heure_debut]);
                
                if ($checkConflit->fetchColumn() > 0) {
                    $msg_status = "<div class='alert error'>⚠️ <strong>Conflit détecté :</strong> L'enseignant ou la salle est déjà occupé sur ce créneau.</div>";
                } else {
                    $pdo->prepare("UPDATE sessions_cours SET enseignant_id = ?, salle_id = ?, date_cours = ?, heure_debut = ?, heure_fin = ? WHERE id = ?")
                        ->execute([$enseignant_id, $salle_id, $date_cours, $heure_debut, $heure_fin, $session_id]);
                    $msg_status = "<div class='alert success'>✅ Séance modifiée !</div>";
                }
            } catch (PDOException $e) {
                $msg_status = "<div class='alert error'>❌ Erreur SQL : " . $e->getMessage() . "</div>";
            }
        }
    }

    // J. Suppression d'une session
    if (isset($_POST['supprimer_session'])) {
        $active_tab = "courses_schedule";
        $session_id = intval($_POST['session_id']);
        try {
            $pdo->prepare("DELETE FROM presences WHERE session_cours_id = ?")->execute([$session_id]);
            $pdo->prepare("DELETE FROM sessions_cours WHERE id = ?")->execute([$session_id]);
            $msg_status = "<div class='alert success'>🗑️ Séance supprimée !</div>";
        } catch (PDOException $e) {
            $msg_status = "<div class='alert error'>❌ Erreur : " . $e->getMessage() . "</div>";
        }
    }

    // M. Inscrire un étudiant à un cours
    if (isset($_POST['inscrire_etudiant_cours'])) {
        $active_tab = "inscriptions_cours";
        $etudiant_id = intval($_POST['etudiant_id']);
        $cours_id = intval($_POST['cours_id']);

        if ($etudiant_id > 0 && $cours_id > 0) {
            try {
                // Vérifier que l'étudiant et le cours appartiennent à la même promotion
                $checkPromo = $pdo->prepare("
                    SELECT e.promotion_id AS promo_etudiant, c.promotion_id AS promo_cours
                    FROM etudiants e
                    JOIN cours c ON c.id = ?
                    WHERE e.utilisateur_id = ?
                ");
                $checkPromo->execute([$cours_id, $etudiant_id]);
                $promoInfo = $checkPromo->fetch();

                if (!$promoInfo) {
                    $msg_status = "<div class='alert error'>❌ Étudiant ou cours introuvable.</div>";
                } elseif (!empty($promoInfo['promo_cours']) && intval($promoInfo['promo_etudiant']) !== intval($promoInfo['promo_cours'])) {
                    $msg_status = "<div class='alert error'>❌ Cet étudiant ne peut pas être inscrit à ce cours car il n'est pas dans la même promotion.</div>";
                } else {
                    $check = $pdo->prepare("SELECT COUNT(*) FROM inscriptions_cours WHERE etudiant_id = ? AND cours_id = ?");
                    $check->execute([$etudiant_id, $cours_id]);

                    if ($check->fetchColumn() > 0) {
                        $msg_status = "<div class='alert error'>❌ Cet étudiant est déjà inscrit à ce cours.</div>";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO inscriptions_cours (etudiant_id, cours_id, date_inscription) VALUES (?, ?, CURDATE())");
                        $stmt->execute([$etudiant_id, $cours_id]);
                        $msg_status = "<div class='alert success'>✅ Étudiant inscrit au cours avec succès !</div>";
                    }
                }
            } catch (PDOException $e) {
                $msg_status = "<div class='alert error'>❌ Erreur SQL : " . $e->getMessage() . "</div>";
            }
        } else {
            $msg_status = "<div class='alert error'>❌ Veuillez sélectionner un étudiant et un cours.</div>";
        }
    }

    // N. Supprimer une inscription à un cours
    if (isset($_POST['supprimer_inscription_cours'])) {
        $active_tab = "inscriptions_cours";
        $etudiant_id = intval($_POST['etudiant_id']);
        $cours_id = intval($_POST['cours_id']);

        try {
            $stmt = $pdo->prepare("DELETE FROM inscriptions_cours WHERE etudiant_id = ? AND cours_id = ?");
            $stmt->execute([$etudiant_id, $cours_id]);
            $msg_status = "<div class='alert success'>🗑️ Inscription supprimée !</div>";
        } catch (PDOException $e) {
            $msg_status = "<div class='alert error'>❌ Erreur SQL : " . $e->getMessage() . "</div>";
        }
    }
}
try {
    $list_promotions = $pdo->query("SELECT id, nom_promotion, annee_academique FROM promotions ORDER BY nom_promotion ASC")->fetchAll();
    
    // Nouvelle requête pour récupérer les groupes avec le nombre d'inscrits
    $list_groupes = $pdo->query("
        SELECT g.id, g.nom AS groupe_nom, p.nom_promotion, a.nom AS amphi_nom,
               (SELECT COUNT(*) FROM etudiants WHERE groupe_td_id = g.id) AS nb_inscrits
        FROM groupes_td g 
        JOIN amphis a ON g.amphi_id = a.id 
        JOIN promotions p ON a.promotion_id = p.id 
        ORDER BY p.nom_promotion ASC, a.nom ASC, g.nom ASC
    ")->fetchAll();

    $list_ue = $pdo->query("SELECT id, nom_ue FROM unites_enseignement ORDER BY nom_ue ASC")->fetchAll();
    $list_salles = $pdo->query("SELECT id, nom_salle, type_salle FROM salles ORDER BY nom_salle ASC")->fetchAll();
    $list_cours = $pdo->query("SELECT id, nom_cours FROM cours ORDER BY nom_cours ASC")->fetchAll();
    $list_cours_details = $pdo->query("
        SELECT c.id, c.nom_cours, c.promotion_id, u.nom_ue, p.nom_promotion
        FROM cours c
        LEFT JOIN unites_enseignement u ON c.ue_id = u.id
        LEFT JOIN promotions p ON c.promotion_id = p.id
        ORDER BY c.nom_cours ASC
    ")->fetchAll();

    
    $students = $pdo->query("SELECT u.id, u.nom, u.prenom, u.email, p.id AS promo_id, p.nom_promotion AS promo_nom, g.id AS groupe_id, g.nom AS groupe_nom, e.statut_parcours FROM utilisateurs u LEFT JOIN etudiants e ON u.id = e.utilisateur_id LEFT JOIN promotions p ON e.promotion_id = p.id LEFT JOIN groupes_td g ON e.groupe_td_id = g.id WHERE u.role = 'etudiant' ORDER BY u.nom ASC")->fetchAll();
    $teachers_list = $pdo->query("SELECT id, nom, prenom, email FROM utilisateurs WHERE role = 'enseignant' ORDER BY nom ASC")->fetchAll();

    $list_cours_inscription = $pdo->query("SELECT c.id, c.nom_cours, c.promotion_id, p.nom_promotion
                                           FROM cours c
                                           LEFT JOIN promotions p ON c.promotion_id = p.id
                                           ORDER BY p.nom_promotion ASC, c.nom_cours ASC")->fetchAll();

    $inscriptions_cours_admin = $pdo->query("SELECT ic.etudiant_id, ic.cours_id, ic.date_inscription,
                                                    u.nom, u.prenom, u.email,
                                                    c.nom_cours,
                                                    p.nom_promotion
                                             FROM inscriptions_cours ic
                                             JOIN utilisateurs u ON u.id = ic.etudiant_id
                                             JOIN cours c ON c.id = ic.cours_id
                                             LEFT JOIN promotions p ON c.promotion_id = p.id
                                             ORDER BY ic.date_inscription DESC, u.nom ASC, c.nom_cours ASC")->fetchAll();



    $list_inscriptions_cours = $pdo->query("
        SELECT ic.etudiant_id, ic.cours_id, ic.date_inscription,
               u.nom, u.prenom, u.email,
               c.nom_cours,
               p.nom_promotion
        FROM inscriptions_cours ic
        JOIN utilisateurs u ON ic.etudiant_id = u.id
        JOIN cours c ON ic.cours_id = c.id
        LEFT JOIN etudiants e ON e.utilisateur_id = u.id
        LEFT JOIN promotions p ON e.promotion_id = p.id
        ORDER BY u.nom ASC, u.prenom ASC, c.nom_cours ASC
    ")->fetchAll();
    
    $count_std = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'etudiant'")->fetchColumn();
    $count_prf = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'enseignant'")->fetchColumn();
    $count_crs = $pdo->query("SELECT COUNT(*) FROM cours")->fetchColumn();

    // --- GESTION DU PLANNING PAR SEMAINE ---
    $week_offset = isset($_GET['week']) ? intval($_GET['week']) : 0;
    if (isset($_GET['week'])) { $active_tab = "courses_schedule"; }

    $monday = new DateTime('monday this week');
    if ($week_offset !== 0) {
        $monday->modify($week_offset . ' weeks');
    }
    $start_date = $monday->format('Y-m-d');
    $end_date = (clone $monday)->modify('+6 days')->format('Y-m-d');

    // Emploi du temps par cours : même séance pour le professeur et pour tous les étudiants inscrits au cours
    $schedule_sessions = $pdo->prepare("SELECT s.*, c.nom_cours, u.nom AS prof_nom, u.prenom AS prof_prenom, sl.nom_salle,
                                               NULL AS groupe_nom,
                                               'Étudiants inscrits au cours' AS nom_promotion,
                                               (SELECT COUNT(*) FROM inscriptions_cours ic WHERE ic.cours_id = s.cours_id) AS nb_inscrits
                                        FROM sessions_cours s
                                        JOIN cours c ON s.cours_id = c.id
                                        JOIN utilisateurs u ON s.enseignant_id = u.id
                                        JOIN salles sl ON s.salle_id = sl.id
                                        WHERE s.date_cours >= ? AND s.date_cours <= ?
                                        ORDER BY s.date_cours ASC, s.heure_debut ASC");
    $schedule_sessions->execute([$start_date, $end_date]);
    $schedule_sessions = $schedule_sessions->fetchAll();

    // Liste de toutes les séances pour la recherche
    $all_sessions_list = $pdo->query("SELECT s.*, c.nom_cours, u.nom AS prof_nom, u.prenom AS prof_prenom, sl.nom_salle,
                                             NULL AS groupe_nom,
                                             'Étudiants inscrits au cours' AS nom_promotion,
                                             (SELECT COUNT(*) FROM inscriptions_cours ic WHERE ic.cours_id = s.cours_id) AS nb_inscrits
                                      FROM sessions_cours s
                                      JOIN cours c ON s.cours_id = c.id
                                      JOIN utilisateurs u ON s.enseignant_id = u.id
                                      JOIN salles sl ON s.salle_id = sl.id
                                      ORDER BY s.date_cours DESC, s.heure_debut DESC")->fetchAll();

} catch (PDOException $e) {
    $msg_status = "<div class='alert error'>Erreur : " . $e->getMessage() . "</div>";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>SmartCampus - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root { --bleu-ecole: #0A2240; --rouge-ecole: #D9383A; --gris-fond: #F7FAFC; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--gris-fond); margin: 0; display: flex; }
        .sidebar { width: 260px; background: var(--bleu-ecole); color: white; height: 100vh; position: fixed; padding-top: 20px; }
        .sidebar h3 { text-align: center; margin-bottom: 30px; }
        .sidebar h3 span { color: var(--rouge-ecole); }
        .sidebar-menu { list-style: none; padding: 0; }
        .sidebar-menu li a { display: block; padding: 15px 25px; color: #CBD5E0; text-decoration: none; font-weight: 500; cursor: pointer; transition: 0.3s; }
        .sidebar-menu li a:hover, .sidebar-menu li a.active { background: #1A365D; color: white; border-left: 4px solid var(--rouge-ecole); }
        .main-content { margin-left: 260px; padding: 40px; width: calc(100% - 260px); box-sizing: border-box; }
        .header-panel { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--bleu-ecole); padding-bottom: 15px; margin-bottom: 30px; }
        .tab-content { display: none; }
        .card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .grid-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-box { padding: 20px; border-radius: 8px; color: white; text-align: center; }
        .stat-std { background: #2B6CB0; }
        .stat-prf { background: var(--rouge-ecole); }
        .stat-crs { background: #38A169; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #E2E8F0; }
        th { background: var(--bleu-ecole); color: white; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        select, input, button { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #CBD5E0; border-radius: 4px; box-sizing: border-box; }
        button { background: var(--rouge-ecole); color: white; border: none; font-weight: bold; cursor: pointer; transition: 0.3s; }
        button:hover { background: #B8282A; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
        .alert.success { background: #C6F6D5; color: #22543D; }
        .alert.error { background: #FED7D7; color: #742A2A; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 5% auto; padding: 20px; border-radius: 8px; width: 500px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #EEE; padding-bottom: 10px; margin-bottom: 20px; }
        .close-modal { cursor: pointer; font-size: 24px; color: #AAA; }
        .btn-edit { background: #4A5568; color: white; padding: 6px 10px; border-radius: 4px; font-size: 0.8em; border: none; cursor: pointer; }
        .btn-delete { background: #E53E3E; color: white; padding: 6px 10px; border-radius: 4px; font-size: 0.8em; border: none; cursor: pointer; margin-left: 5px; }
        
        /* SCHEDULE GRID */
        .schedule-container { display: flex; flex-wrap: wrap; gap: 20px; }
        .schedule-form { flex: 1; min-width: 300px; max-width: 350px; }
        .schedule-grid-wrapper { flex: 3; min-width: 0; background: white; padding: 15px; border-radius: 8px; overflow-x: auto; }
        .weekly-grid { display: grid; grid-template-columns: 60px repeat(5, minmax(140px, 1fr)); border: 1px solid #E2E8F0; min-width: 760px; }
        .grid-header { background: var(--bleu-ecole); color: white; padding: 10px; text-align: center; font-weight: bold; border: 1px solid #1A365D; }
        .time-slot { background: #EDF2F7; padding: 5px; text-align: center; font-size: 0.8em; border: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .day-slot { min-height: 80px; border: 1px solid #E2E8F0; padding: 5px; background: white; }
        .session-item { background: #EBF8FF; border-left: 3px solid #3182CE; margin-bottom: 5px; padding: 5px; font-size: 0.75em; overflow: hidden; text-overflow: ellipsis; }
        .session-time { font-weight: bold; color: #2B6CB0; display: block; margin-bottom: 2px; }

        /* NOUVEAUX STYLES POUR LA RECHERCHE ET LES FICHES DÉTAILLÉES */
        .search-container { display: flex; gap: 10px; margin-bottom: 20px; }
        .search-container input { flex: 2; margin-bottom: 0; }
        .search-container select { flex: 1; margin-bottom: 0; }
        .btn-view { background: #3182CE; color: white; padding: 6px 10px; border-radius: 4px; font-size: 0.8em; border: none; cursor: pointer; }
        .modal-large { width: 80% !important; max-width: 1000px; max-height: 90vh; overflow-y: auto; }
        .detail-grid { display: grid; grid-template-columns: 1fr 2fr; gap: 20px; }
        .info-card { background: #F8FAFC; padding: 15px; border-radius: 6px; border: 1px solid #E2E8F0; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75em; font-weight: bold; }
        .badge-present { background: #C6F6D5; color: #22543D; }
        .badge-absent { background: #FED7D7; color: #742A2A; }

        /* Bootstrap ajouté pour améliorer l'interface admin sans supprimer l'ancien CSS */
        .main-content .btn { width: auto; margin-bottom: 0; border-radius: 6px; }
        .main-content .form-control, .main-content .form-select { margin-bottom: 15px; }
        .main-content .table { background: white; border-radius: 8px; overflow: hidden; }
        .main-content .table th { vertical-align: middle; }
        .bootstrap-info-admin { border-left: 5px solid #0d6efd; }
        .stat-bootstrap-card { min-height: 120px; display:flex; align-items:center; justify-content:center; }
        .stat-bootstrap-card h3 { font-size: 2rem; margin: 0; }
        .admin-section-title { display:flex; align-items:center; gap:8px; margin-bottom:18px; }

    </style>
</head>
<body>

<div class="sidebar">
    <h3>Smart<span>Campus</span></h3>
    <ul class="sidebar-menu">
        <li><a id="btn-dashboard" class="active" onclick="switchTab('dashboard')"><i class="fa-solid fa-gauge"></i> Dashboard</a></li>
        <li><a id="btn-students" onclick="switchTab('students')"><i class="fa-solid fa-user-graduate"></i> Étudiants</a></li>
        <li><a id="btn-teachers" onclick="switchTab('teachers')"><i class="fa-solid fa-chalkboard-user"></i> Enseignants</a></li>
        <li><a id="btn-courses_schedule" onclick="switchTab('courses_schedule')"><i class="fa-solid fa-book-open"></i> Cours & Planning</a></li>
        <li><a id="btn-inscriptions_cours" onclick="switchTab('inscriptions_cours')"><i class="fa-solid fa-list-check"></i> Inscriptions aux cours</a></li>
        <li><a id="btn-inscription" onclick="switchTab('inscription')"><i class="fa-solid fa-user-plus"></i> Inscrire Étudiant</a></li>
        <li><a id="btn-inscription_prof" onclick="switchTab('inscription_prof')"><i class="fa-solid fa-chalkboard-user"></i> Inscrire Prof</a></li>
        <li><a href="deconnexion.php" style="color:#FEB2B2; margin-top:20px;"><i class="fa-solid fa-power-off"></i> Déconnexion</a></li>
    </ul>
</div>

<div class="main-content">
    <div class="header-panel bg-white rounded shadow-sm p-3">
        <h2>Panel Administration</h2>
        <span>Bonjour, <strong><?php echo htmlspecialchars($_SESSION['user_nom']); ?></strong></span>
    </div>

    <?php echo $msg_status; ?>

    <!-- DASHBOARD -->
    <div id="tab-dashboard" class="tab-content" style="display: block;">
        <div class="grid-stats">
            <div class="stat-box stat-std stat-bootstrap-card shadow-sm"><div><h3><?php echo $count_std; ?></h3><p class="mb-0">Étudiants</p></div></div>
            <div class="stat-box stat-prf stat-bootstrap-card shadow-sm"><div><h3><?php echo $count_prf; ?></h3><p class="mb-0">Enseignants</p></div></div>
            <div class="stat-box stat-crs stat-bootstrap-card shadow-sm"><div><h3><?php echo $count_crs; ?></h3><p class="mb-0">Cours</p></div></div>
        </div>
        <div class="card">
            <h3>Bienvenue dans votre espace de gestion</h3>
            <p>Utilisez le menu latéral pour gérer les élèves, les professeurs et organiser l'emploi du temps de l'établissement.</p>
        </div>
    </div>

    <!-- ETUDIANTS -->
    <div id="tab-students" class="tab-content">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;">Liste des Étudiants</h3>
                <div class="search-container" style="margin:0; width:60%;">
                    <input type="text" id="searchStudent" class="form-control" placeholder="🔍 Rechercher un nom ou prénom..." onkeyup="filterUsers('student')">
                    <select id="filterPromo" class="form-select" onchange="filterUsers('student')">
                        <option value="">Toutes les promotions</option>
                        <?php foreach($list_promotions as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['nom_promotion']); ?>"><?php echo htmlspecialchars($p['nom_promotion']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <table id="tableStudents" class="table table-striped table-hover align-middle">
                <thead><tr><th>Nom & Prénom</th><th>Promotion</th><th>Public</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($students as $s): ?>
                    <tr data-name="<?php echo htmlspecialchars(strtolower($s['nom'].' '.$s['prenom'])); ?>" data-promo="<?php echo htmlspecialchars($s['promo_nom']); ?>">
                        <td><strong><?php echo htmlspecialchars($s['nom'].' '.$s['prenom']); ?></strong><br><small><?php echo $s['email']; ?></small></td>
                        <td><?php echo $s['promo_nom']; ?></td>
                        <td><?php echo $s['groupe_nom'] ?? 'N/A'; ?></td>
                        <td>
                            <button class="btn btn-primary btn-sm btn-view" onclick="viewUserProfile(<?php echo $s['id']; ?>)" title="Voir la fiche détaillée"><i class="fa-solid fa-file-invoice"></i> Fiche</button>
                            <button class="btn btn-secondary btn-sm btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($s)); ?>)"><i class="fa-solid fa-pen"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ?');">
                                <input type="hidden" name="user_id" value="<?php echo $s['id']; ?>">
                                <button type="submit" name="supprimer_etudiant" class="btn btn-danger btn-sm btn-delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ENSEIGNANTS -->
    <div id="tab-teachers" class="tab-content">
        <div class="card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="margin:0;">Liste des Enseignants</h3>
                <div class="search-container" style="margin:0; width:40%;">
                    <input type="text" id="searchTeacher" placeholder="🔍 Rechercher un nom..." onkeyup="filterUsers('teacher')">
                </div>
            </div>
            <table id="tableTeachers" class="table table-striped table-hover align-middle">
                <thead><tr><th>Nom & Prénom</th><th>Email</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($teachers_list as $t): ?>
                    <tr data-name="<?php echo htmlspecialchars(strtolower($t['nom'].' '.$t['prenom'])); ?>">
                        <td><strong><?php echo htmlspecialchars($t['nom'].' '.$t['prenom']); ?></strong></td>
                        <td><?php echo $t['email']; ?></td>
                        <td>
                            <button class="btn btn-primary btn-sm btn-view" onclick="viewUserProfile(<?php echo $t['id']; ?>)" title="Voir la fiche détaillée"><i class="fa-solid fa-file-invoice"></i> Fiche</button>
                            <button class="btn btn-secondary btn-sm btn-edit" onclick="openEditTeacherModal(<?php echo htmlspecialchars(json_encode($t)); ?>)"><i class="fa-solid fa-pen"></i></button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ?');">
                                <input type="hidden" name="user_id" value="<?php echo $t['id']; ?>">
                                <button type="submit" name="supprimer_enseignant" class="btn btn-danger btn-sm btn-delete"><i class="fa-solid fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- COURS & PLANNING -->
    <div id="tab-courses_schedule" class="tab-content">
        <div class="schedule-container">
            <div class="schedule-form">
                <div class="card">
                    <h4>Créer un Cours</h4>
                    <form method="POST">
                        <input type="text" name="nom_cours" required placeholder="Nom du cours">
                        <select name="promotion_id" required>
                            <option value="">-- Promotion Cible --</option>
                            <?php foreach($list_promotions as $promo): ?>
                                <option value="<?php echo $promo['id']; ?>"><?php echo htmlspecialchars($promo['nom_promotion']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="ue_id" required>
                            <option value="">-- Unité d'Enseignement (UE) --</option>
                            <?php foreach($list_ue as $ue): ?>
                                <option value="<?php echo $ue['id']; ?>"><?php echo htmlspecialchars($ue['nom_ue']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="creer_cours" class="btn btn-success">Créer</button>
                    </form>
                </div>
                <div class="card">
                    <h4>Planifier une Séance</h4>
                    <form method="POST">
                        <select name="cours_id" class="form-select" required>
                            <option value="">-- Matière --</option>
                            <?php foreach($list_cours as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nom_cours']); ?></option><?php endforeach; ?>
                        </select>
                        <select name="enseignant_id" required>
                            <option value="">-- Enseignant --</option>
                            <?php foreach($teachers_list as $p): ?><option value="<?php echo $p['id']; ?>">M. <?php echo htmlspecialchars($p['nom'].' '.$p['prenom']); ?></option><?php endforeach; ?>
                        </select>
                        <div style="background:#EDF2F7; padding:10px; border-radius:6px; font-size:0.9em; color:#2D3748;">
                            <strong>Public :</strong><br>
                            tous les étudiants inscrits au cours sélectionné verront cette séance.
                        </div>
                        <select name="salle_id" required>
                            <option value="">-- Salle --</option>
                            <?php foreach($list_salles as $sl): ?><option value="<?php echo $sl['id']; ?>"><?php echo htmlspecialchars($sl['nom_salle'] . ' (' . $sl['type_salle'] . ')'); ?></option><?php endforeach; ?>
                        </select>
                        <input type="date" name="date_cours" required>
                        <div style="display:flex; gap:5px;">
                            <input type="time" name="heure_debut" required>
                            <input type="time" name="heure_fin" required>
                        </div>
                        <button type="submit" name="planifier_session" class="btn btn-primary">Placer</button>
                    </form>
                </div>
            </div>
            <div class="schedule-grid-wrapper card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                    <h4 style="margin:0;"><i class="fa-solid fa-calendar-week"></i> Planning de la Semaine</h4>
                    
                    <!-- NAVIGATION SEMAINES -->
                    <div style="display:flex; align-items:center; gap:15px; background:#EDF2F7; padding:5px 15px; border-radius:6px;">
                        <a href="?week=<?php echo $week_offset - 1; ?>" class="btn btn-outline-primary btn-sm btn-nav-week" title="Semaine précédente" style="color:var(--bleu-ecole); text-decoration:none;"><i class="fa-solid fa-chevron-left"></i></a>
                        <span style="font-weight:bold; font-size:0.9em; min-width:180px; text-align:center;">
                            Semaine du <?php echo $monday->format('d/m'); ?> au <?php echo (clone $monday)->modify('+4 days')->format('d/m'); ?>
                        </span>
                        <a href="?week=<?php echo $week_offset + 1; ?>" class="btn btn-outline-primary btn-sm btn-nav-week" title="Semaine suivante" style="color:var(--bleu-ecole); text-decoration:none;"><i class="fa-solid fa-chevron-right"></i></a>
                    </div>

                    <?php 
                    $filter_classes = []; $filter_profs = []; $filter_cours = [];
                    foreach($schedule_sessions as $s) {
                        $c_name = 'Étudiants inscrits au cours';
                        $filter_classes[$c_name] = $c_name;
                        $filter_profs[$s['prof_nom'].' '.$s['prof_prenom']] = 'M. '.$s['prof_nom'].' '.$s['prof_prenom'];
                        $filter_cours[$s['nom_cours']] = $s['nom_cours'];
                    }
                    asort($filter_classes); asort($filter_profs); asort($filter_cours);
                    ?>
                    <select id="scheduleFilter" onchange="applyScheduleFilter()" style="width: 280px; padding: 8px; border: 1px solid #CBD5E0; border-radius: 4px; margin:0; font-size:0.9em; background:white;">
                        <option value="ALL">-- Afficher tout le planning --</option>
                        <optgroup label="Filtrer par Public">
                            <?php foreach($filter_classes as $fc): ?><option value="CLASS_<?php echo htmlspecialchars($fc); ?>"><?php echo htmlspecialchars($fc); ?></option><?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Filtrer par Enseignant">
                            <?php foreach($filter_profs as $fp): ?><option value="PROF_<?php echo htmlspecialchars($fp); ?>"><?php echo htmlspecialchars($fp); ?></option><?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Filtrer par Matière">
                            <?php foreach($filter_cours as $fc): ?><option value="COURS_<?php echo htmlspecialchars($fc); ?>"><?php echo htmlspecialchars($fc); ?></option><?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="weekly-grid">
                    <div class="grid-header">Heures</div>
                    <?php 
                    $days_names = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven'];
                    for($i=0; $i<5; $i++): 
                        $current_day = clone $monday;
                        if($i > 0) $current_day->modify("+$i days");
                    ?>
                        <div class="grid-header"><?php echo $days_names[$i] . ' <span style="font-weight:normal;font-size:0.8em;">' . $current_day->format('d/m') . '</span>'; ?></div>
                    <?php endfor; ?>
                    <?php $slots = ["08:00", "10:00", "13:00", "15:00", "17:00"];
                    foreach($slots as $slot): 
                        $slot_hour = intval(substr($slot, 0, 2));
                    ?>
                        <div class="time-slot"><?php echo $slot; ?></div>
                        <?php for($day=1; $day<=5; $day++): ?>
                            <div class="day-slot">
                                <?php foreach($schedule_sessions as $sess): 
                                    $hour = intval(substr($sess['heure_debut'], 0, 2));
                                    $is_in_slot = false;
                                    if ($slot_hour == 8 && $hour >= 8 && $hour < 10) $is_in_slot = true;
                                    elseif ($slot_hour == 10 && $hour >= 10 && $hour < 13) $is_in_slot = true;
                                    elseif ($slot_hour == 13 && $hour >= 13 && $hour < 15) $is_in_slot = true;
                                    elseif ($slot_hour == 15 && $hour >= 15 && $hour < 17) $is_in_slot = true;
                                    elseif ($slot_hour == 17 && $hour >= 17) $is_in_slot = true;

                                    if(date('N', strtotime($sess['date_cours'])) == $day && $is_in_slot): 
                                        $className = 'Étudiants inscrits au cours';
                                        $profName = $sess['prof_nom'].' '.$sess['prof_prenom'];
                                    ?>
                                    <div class="session-item" style="cursor:pointer;" 
                                         data-class="CLASS_<?php echo htmlspecialchars($className); ?>"
                                         data-prof="PROF_<?php echo htmlspecialchars('M. '.$profName); ?>"
                                         data-cours="COURS_<?php echo htmlspecialchars($sess['nom_cours']); ?>"
                                         data-json="<?php echo htmlspecialchars(json_encode($sess), ENT_QUOTES, 'UTF-8'); ?>"
                                         onclick="openEditSessionModal(JSON.parse(this.getAttribute('data-json')))">
                                        <span class="session-time"><?php echo substr($sess['heure_debut'],0,5).' - '.substr($sess['heure_fin'],0,5); ?></span>
                                        <strong><?php echo htmlspecialchars($sess['nom_cours']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($className); ?> (<?php echo htmlspecialchars($sess['nom_salle']); ?>)</small>
                                    </div>
                                <?php endif; endforeach; ?>
                            </div>
                        <?php endfor; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="card" style="margin-top: 25px;">
            <h3><i class="fa-solid fa-list"></i> Liste de Toutes les Séances</h3>
            <input type="text" id="searchSessionInput" class="form-control" placeholder="🔍 Rechercher une séance (Date, Matière, Public, Professeur, Salle)..." onkeyup="filterSessions()" style="padding: 12px; width: 100%; box-sizing: border-box; font-size: 1em; border: 2px solid #E2E8F0; border-radius: 6px; margin-bottom: 15px;">
            <table id="sessionsTable" class="table table-striped table-hover align-middle">
                <thead>
                    <tr><th>Date & Horaires</th><th>Matière</th><th>Public</th><th>Enseignant</th><th>Salle</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach($all_sessions_list as $sess): ?>
                    <tr>
                        <td>
                            <strong><?php echo date('d/m/Y', strtotime($sess['date_cours'])); ?></strong><br>
                            <small><?php echo substr($sess['heure_debut'],0,5).' - '.substr($sess['heure_fin'],0,5); ?></small>
                        </td>
                        <td><strong><?php echo htmlspecialchars($sess['nom_cours']); ?></strong></td>
                        <td><span style="color:#2B6CB0; font-weight:bold;"><?php echo htmlspecialchars('Étudiants inscrits au cours (' . intval($sess['nb_inscrits'] ?? 0) . ' inscrit(s))'); ?></span></td>
                        <td>M. <?php echo htmlspecialchars($sess['prof_nom'].' '.$sess['prof_prenom']); ?></td>
                        <td><span style="background:#E2E8F0; padding:4px 8px; border-radius:4px; font-size: 0.9em;"><?php echo htmlspecialchars($sess['nom_salle']); ?></span></td>
                        <td style="width: 100px;">
                            <button class="btn btn-secondary btn-sm btn-edit" 
                                    data-json="<?php echo htmlspecialchars(json_encode($sess), ENT_QUOTES, 'UTF-8'); ?>"
                                    onclick="openEditSessionModal(JSON.parse(this.getAttribute('data-json')))">
                                <i class="fa-solid fa-pen-to-square"></i> Gérer
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- INSCRIPTIONS AUX COURS -->
    <div id="tab-inscriptions_cours" class="tab-content">
        <div class="card">
            <h3><i class="fa-solid fa-list-check"></i> Inscrire un étudiant à un cours</h3>
            <form method="POST" style="display:grid; grid-template-columns: 1fr 1fr auto; gap:15px; align-items:end;">
                <div>
                    <label>Étudiant</label>
                    <select name="etudiant_id" class="form-select" required>
                        <option value="">-- Sélectionner un étudiant --</option>
                        <?php foreach($students as $s): ?>
                            <option value="<?php echo intval($s['id']); ?>">
                                <?php echo htmlspecialchars($s['nom'] . ' ' . $s['prenom'] . ' - ' . ($s['promo_nom'] ?? 'Sans promotion')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Cours</label>
                    <select name="cours_id" class="form-select" required>
                        <option value="">-- Sélectionner un cours --</option>
                        <?php foreach($list_cours_inscription as $c): ?>
                            <option value="<?php echo intval($c['id']); ?>">
                                <?php echo htmlspecialchars($c['nom_cours']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <button type="submit" name="inscrire_etudiant_cours" value="1" class="btn btn-success" style="margin-bottom:15px;">Inscrire</button>
                </div>
            </form>
        </div>

        <div class="card">
            <h3>Liste des inscriptions aux cours</h3>
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Étudiant</th>
                        <th>Promotion</th>
                        <th>Cours</th>
                        <th>Date d'inscription</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($list_inscriptions_cours)): ?>
                        <tr><td colspan="5" style="text-align:center; color:#718096;">Aucune inscription pour le moment.</td></tr>
                    <?php else: ?>
                        <?php foreach($list_inscriptions_cours as $ins): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($ins['nom'] . ' ' . $ins['prenom']); ?></strong><br>
                                    <small><?php echo htmlspecialchars($ins['email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($ins['nom_promotion'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($ins['nom_cours']); ?></td>
                                <td><?php echo htmlspecialchars($ins['date_inscription']); ?></td>
                                <td>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette inscription au cours ?');">
                                        <input type="hidden" name="etudiant_id" value="<?php echo $ins['etudiant_id']; ?>">
                                        <input type="hidden" name="cours_id" value="<?php echo $ins['cours_id']; ?>">
                                        <button type="submit" name="supprimer_inscription_cours" class="btn btn-danger btn-sm btn-delete"><i class="fa-solid fa-trash"></i> Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- INSCRIPTION ETUDIANT -->
    <div id="tab-inscription" class="tab-content">
        <div class="card" style="max-width: 500px; margin: auto;">
            <h3>Inscrire un Étudiant</h3>
            <form method="POST">
                <input type="text" name="nom" class="form-control" required placeholder="Nom">
                <input type="text" name="prenom" class="form-control" required placeholder="Prénom">
                <input type="email" name="email" class="form-control" required placeholder="Email">
                <input type="password" name="password" class="form-control" required value="Etudiant2026!">
                <select name="promotion_id" required>
                    <option value="">-- Promotion --</option>
                    <?php foreach($list_promotions as $promo): ?><option value="<?php echo $promo['id']; ?>"><?php echo $promo['nom_promotion']; ?></option><?php endforeach; ?>
                </select>
                <select name="groupe_td_id" required>
                    <option value="">-- Classe (Obligatoire) --</option>
                    <?php foreach($list_groupes as $g): 
                        $isFull = $g['nb_inscrits'] >= 25;
                        $label = $g['nom_promotion'] . ' - ' . $g['amphi_nom'] . ' - ' . $g['groupe_nom'] . ' (' . $g['nb_inscrits'] . '/25 places)';
                    ?>
                        <option value="<?php echo $g['id']; ?>" <?php if($isFull) echo 'disabled style="color:red;"'; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="statut_parcours" required>
                    <option value="initial">Initial</option><option value="alternant">Alternant</option>
                </select>
                <button type="submit" name="inscrire_etudiant" class="btn btn-success">Valider</button>
            </form>
        </div>
    </div>

    <!-- INSCRIPTION PROF -->
    <div id="tab-inscription_prof" class="tab-content">
        <div class="card" style="max-width: 500px; margin: auto;">
            <h3>Inscrire un Enseignant</h3>
            <form method="POST">
                <input type="text" name="nom" class="form-control" required placeholder="Nom">
                <input type="text" name="prenom" class="form-control" required placeholder="Prénom">
                <input type="email" name="email" class="form-control" required placeholder="Email">
                <input type="password" name="password" class="form-control" required value="Enseignant2026!">
                <button type="submit" name="inscrire_enseignant" class="btn btn-success">Valider</button>
            </form>
        </div>
    </div>
</div>

<!-- MODAL ETUDIANT -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Modifier l'étudiant</h3><span class="close-modal" onclick="closeEditModal()">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="user_id" id="edit_user_id">
            <input type="text" name="nom" id="edit_nom" required>
            <input type="text" name="prenom" id="edit_prenom" required>
            <input type="email" name="email" id="edit_email" required>
            <select name="promotion_id" id="edit_promotion_id" required>
                <?php foreach($list_promotions as $promo): ?><option value="<?php echo $promo['id']; ?>"><?php echo $promo['nom_promotion']; ?></option><?php endforeach; ?>
            </select>
            <select name="groupe_td_id" id="edit_groupe_td_id" required>
                <option value="">-- Classe (Obligatoire) --</option>
                <?php foreach($list_groupes as $g): 
                    $isFull = $g['nb_inscrits'] >= 25;
                    $label = $g['nom_promotion'] . ' - ' . $g['amphi_nom'] . ' - ' . $g['groupe_nom'] . ' (' . $g['nb_inscrits'] . '/25 places)';
                ?>
                    <option value="<?php echo $g['id']; ?>" <?php if($isFull) echo 'disabled style="color:red;"'; ?>><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="statut_parcours" id="edit_statut_parcours" required>
                <option value="initial">Initial</option><option value="alternant">Alternant</option>
            </select>
            <button type="submit" name="modifier_etudiant" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>
</div>

<!-- MODAL PROF -->
<div id="editTeacherModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Modifier l'enseignant</h3><span class="close-modal" onclick="closeEditTeacherModal()">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="user_id" id="edit_prof_id">
            <input type="text" name="nom" id="edit_prof_nom" required>
            <input type="text" name="prenom" id="edit_prof_prenom" required>
            <input type="email" name="email" id="edit_prof_email" required>
            <button type="submit" name="modifier_enseignant" class="btn btn-primary">Enregistrer</button>
        </form>
    </div>
</div>

<!-- MODAL SESSION -->
<div id="editSessionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3>Gérer la séance</h3><span class="close-modal" onclick="closeEditSessionModal()">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="session_id" id="edit_sess_id">
            
            <label>Matière (Fixe)</label>
            <input type="text" id="edit_sess_cours" readonly style="background:#f0f0f0;">
            
            <label>Public</label>
            <input type="text" id="edit_sess_classe" readonly style="background:#f0f0f0;">

            <label>Enseignant</label>
            <select name="enseignant_id" id="edit_sess_prof" required>
                <?php foreach($teachers_list as $p): ?><option value="<?php echo $p['id']; ?>">M. <?php echo htmlspecialchars($p['nom'].' '.$p['prenom']); ?></option><?php endforeach; ?>
            </select>
            
            <label>Salle</label>
            <select name="salle_id" id="edit_sess_salle" required>
                <?php foreach($list_salles as $sl): ?><option value="<?php echo $sl['id']; ?>"><?php echo htmlspecialchars($sl['nom_salle']); ?></option><?php endforeach; ?>
            </select>
            
            <label>Date</label>
            <input type="date" name="date_cours" id="edit_sess_date" required>
            
            <label>Horaires</label>
            <div style="display:flex; gap:5px;">
                <input type="time" name="heure_debut" id="edit_sess_debut" required>
                <input type="time" name="heure_fin" id="edit_sess_fin" required>
            </div>
            
            <div style="display:flex; justify-content:space-between; margin-top:15px;">
                <button type="submit" name="modifier_session" style="width:48%;">Mettre à jour</button>
                <button type="submit" name="supprimer_session" class="btn btn-danger btn-sm btn-delete" style="width:48%; margin:0;" onclick="return confirm('Supprimer cette séance ?');" formnovalidate>Supprimer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL FICHE DÉTAILLÉE (NOUVEAU) -->
<div id="userProfileModal" class="modal">
    <div class="modal-content modal-large">
        <div class="modal-header">
            <h3 id="profileTitle">Fiche Utilisateur</h3>
            <span class="close-modal" onclick="closeUserProfileModal()">&times;</span>
        </div>
        <div id="profileBody">
            <!-- Rempli par AJAX -->
            <p style="text-align:center; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Chargement des données...</p>
        </div>
    </div>
</div>

<script>
    function filterUsers(type) {
        let inputId = type === 'student' ? 'searchStudent' : 'searchTeacher';
        let tableId = type === 'student' ? 'tableStudents' : 'tableTeachers';
        let query = document.getElementById(inputId).value.toLowerCase();
        let rows = document.querySelectorAll('#' + tableId + ' tbody tr');
        let promoFilter = type === 'student' ? document.getElementById('filterPromo').value : '';

        rows.forEach(row => {
            let name = row.getAttribute('data-name');
            let promo = row.getAttribute('data-promo');
            let matchSearch = name.includes(query);
            let matchPromo = promoFilter === '' || promo === promoFilter;
            
            row.style.display = (matchSearch && matchPromo) ? '' : 'none';
        });
    }

    function viewUserProfile(userId) {
        let modal = document.getElementById('userProfileModal');
        let body = document.getElementById('profileBody');
        modal.style.display = 'block';
        body.innerHTML = '<p style="text-align:center; padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Chargement...</p>';

        console.log("Fetching details for user:", userId);
        // On appelle le nouveau fichier dédié pour éviter toute corruption JSON
        fetch('get_user_details.php?user_id=' + userId)
            .then(response => {
                console.log("Response status:", response.status);
                if (!response.ok) throw new Error('Erreur HTTP ' + response.status);
                return response.text(); 
            })
            .then(text => {
                console.log("Raw response:", text);
                try {
                    let data = JSON.parse(text);
                    if (data.error) {
                        body.innerHTML = '<div class="alert error">Erreur : ' + data.error + '</div>';
                        return;
                    }
                    let html = `
                        <div class="detail-grid">
                            <div class="info-card">
                                <h4><i class="fa-solid fa-user"></i> Informations</h4>
                                <p><strong>Nom :</strong> ${data.user.nom} ${data.user.prenom}</p>
                                <p><strong>Email :</strong> ${data.user.email}</p>
                                <p><strong>Rôle :</strong> ${data.user.role.toUpperCase()}</p>
                                ${data.user.role === 'etudiant' ? `
                                    <p><strong>Promotion :</strong> ${data.details ? data.details.nom_promotion : 'N/A'}</p>
                                    <p><strong>Groupe :</strong> ${data.details ? data.details.groupe_nom : 'N/A'}</p>
                                    <p><strong>Statut :</strong> ${data.details ? data.details.statut_parcours : 'N/A'}</p>
                                ` : ''}
                            </div>
                            <div>
                                ${data.user.role === 'etudiant' ? renderStudentDetails(data) : renderTeacherDetails(data)}
                            </div>
                        </div>
                    `;
                    body.innerHTML = html;
                    document.getElementById('profileTitle').innerText = 'Fiche ' + (data.user.role === 'etudiant' ? 'Étudiant' : 'Enseignant') + ' : ' + data.user.nom + ' ' + data.user.prenom;
                } catch (e) {
                    console.error("JSON Parse Error:", e);
                    body.innerHTML = '<div class="alert error">Erreur de format de données. Raw: ' + text.substring(0, 100) + '</div>';
                }
            })
            .catch(err => {
                console.error("Fetch Error:", err);
                body.innerHTML = '<div class="alert error">Erreur lors du chargement : ' + err.message + '</div>';
            });
    }

    function renderStudentDetails(data) {
        let notesHtml = '<h4><i class="fa-solid fa-graduation-cap"></i> Dernières Notes</h4>';
        if (data.notes.length === 0) notesHtml += '<p>Aucune note enregistrée.</p>';
        else {
            notesHtml += '<table><thead><tr><th>Matière</th><th>Note</th><th>Type</th><th>Date</th></tr></thead><tbody>';
            data.notes.forEach(n => {
                notesHtml += `<tr><td>${n.nom_cours}</td><td><strong>${n.note_valeur}/20</strong></td><td>${n.type_evaluation}</td><td>${n.date_saisie}</td></tr>`;
            });
            notesHtml += '</tbody></table>';
        }

        let absHtml = '<h4><i class="fa-solid fa-clock"></i> Absences & Retards</h4>';
        if (data.absences.length === 0) absHtml += '<p>Aucun incident de présence.</p>';
        else {
            absHtml += '<table><thead><tr><th>Date</th><th>Cours</th><th>Statut</th></tr></thead><tbody>';
            data.absences.forEach(a => {
                let badge = a.statut_presence === 'retard' ? 'badge-present' : 'badge-absent';
                absHtml += `<tr><td>${a.date_cours}</td><td>${a.nom_cours}</td><td><span class="badge ${badge}">${a.statut_presence}</span></td></tr>`;
            });
            absHtml += '</tbody></table>';
        }

        return notesHtml + '<br>' + absHtml;
    }

    function renderTeacherDetails(data) {
        let coursHtml = '<h4><i class="fa-solid fa-book"></i> Cours Assurés</h4>';
        if (data.cours.length === 0) coursHtml += '<p>Aucun cours assigné.</p>';
        else {
            coursHtml += '<ul>';
            data.cours.forEach(c => {
                coursHtml += `<li><strong>${c.nom_cours}</strong> (${c.nom_promotion})</li>`;
            });
            coursHtml += '</ul>';
        }
        return coursHtml;
    }

    function closeUserProfileModal() { document.getElementById('userProfileModal').style.display = 'none'; }

    function switchTab(name) {
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        document.querySelectorAll('.sidebar-menu li a').forEach(b => b.classList.remove('active'));
        
        let targetContent = document.getElementById('tab-' + name);
        let targetBtn = document.getElementById('btn-' + name);
        
        if (targetContent) targetContent.style.display = 'block';
        if (targetBtn) targetBtn.classList.add('active');
        
        // Remove week parameter from URL when clicking away from schedule to prevent locking
        if (name !== 'courses_schedule' && window.location.search.includes('week=')) {
            window.history.pushState({}, document.title, window.location.pathname);
        }
    }

    // Initialize the active tab based on PHP logic
    switchTab('<?php echo $active_tab; ?>');

    function openEditModal(s) {
        document.getElementById('edit_user_id').value = s.id;
        document.getElementById('edit_nom').value = s.nom;
        document.getElementById('edit_prenom').value = s.prenom;
        document.getElementById('edit_email').value = s.email;
        document.getElementById('edit_promotion_id').value = s.promo_id;
        document.getElementById('edit_groupe_td_id').value = s.groupe_id || "";
        document.getElementById('edit_statut_parcours').value = s.statut_parcours;
        document.getElementById('editModal').style.display = 'block';
    }
    function closeEditModal() { document.getElementById('editModal').style.display = 'none'; }

    function openEditTeacherModal(t) {
        document.getElementById('edit_prof_id').value = t.id;
        document.getElementById('edit_prof_nom').value = t.nom;
        document.getElementById('edit_prof_prenom').value = t.prenom;
        document.getElementById('edit_prof_email').value = t.email;
        document.getElementById('editTeacherModal').style.display = 'block';
    }
    function closeEditTeacherModal() { document.getElementById('editTeacherModal').style.display = 'none'; }

    function openEditSessionModal(sess) {
        document.getElementById('edit_sess_id').value = sess.id;
        document.getElementById('edit_sess_cours').value = sess.nom_cours;
        document.getElementById('edit_sess_classe').value = 'Étudiants inscrits au cours';
        document.getElementById('edit_sess_prof').value = sess.enseignant_id;
        document.getElementById('edit_sess_salle').value = sess.salle_id;
        document.getElementById('edit_sess_date').value = sess.date_cours;
        document.getElementById('edit_sess_debut').value = sess.heure_debut.substring(0, 5);
        document.getElementById('edit_sess_fin').value = sess.heure_fin.substring(0, 5);
        document.getElementById('editSessionModal').style.display = 'block';
    }
    function closeEditSessionModal() { document.getElementById('editSessionModal').style.display = 'none'; }

    function filterSessions() {
        let input = document.getElementById("searchSessionInput");
        let filter = input.value.toUpperCase();
        let table = document.getElementById("sessionsTable");
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let rowText = tr[i].textContent || tr[i].innerText;
            if (rowText.toUpperCase().indexOf(filter) > -1) {
                tr[i].style.display = "";
            } else {
                tr[i].style.display = "none";
            }
        }
    }

    function applyScheduleFilter() {
        let filterVal = document.getElementById("scheduleFilter").value;
        let items = document.querySelectorAll(".session-item");

        items.forEach(item => {
            if (filterVal === "ALL") {
                item.style.display = "block";
            } else {
                let matchClass = item.getAttribute("data-class") === filterVal;
                let matchProf = item.getAttribute("data-prof") === filterVal;
                let matchCours = item.getAttribute("data-cours") === filterVal;
                
                if (matchClass || matchProf || matchCours) {
                    item.style.display = "block";
                } else {
                    item.style.display = "none";
                }
            }
        });
    }


    function filterCourseRegistrations() {
        let input = document.getElementById("searchCourseRegistration");
        if (!input) return;
        let filter = input.value.toUpperCase();
        let table = document.getElementById("courseRegistrationsTable");
        let tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) {
            let rowText = tr[i].textContent || tr[i].innerText;
            tr[i].style.display = rowText.toUpperCase().indexOf(filter) > -1 ? "" : "none";
        }
    }

    window.onclick = function(e) {
        if (e.target.className === 'modal') { closeEditModal(); closeEditTeacherModal(); closeEditSessionModal(); }
    }
</script>


<!-- jQuery : petites interactions visuelles sans changer les fonctionnalités -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
$(document).ready(function() {
    // Les messages de succès peuvent disparaître automatiquement après quelques secondes.
    $('.alert.success, .alert-success').delay(3500).fadeOut(600);

    // Petit effet visuel sur les lignes de tableaux.
    $('table tbody tr').hover(
        function() { $(this).addClass('table-active'); },
        function() { $(this).removeClass('table-active'); }
    );
});
</script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
