<?php
require_once 'config.php';

$erreur = "";

// Vérifier si le formulaire a été soumis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Nettoyer les entrées de saisie (Protection minimale contre les failles XSS)
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    $password = trim($_POST['password']);

    if (!empty($email) && !empty($password)) {
        // Préparer la requête pour éviter les injections SQL (Sécurité obligatoire)
        $stmt = $pdo->prepare("SELECT id, email, nom, prenom, role, mot_de_pass FROM utilisateurs WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Vérification du mot de passe
        // Note : En situation réelle, on utilise password_verify(). 
        // Ici, pour ton jeu de données de test, on compare en texte brut car 'password123' n'est pas haché.
        if ($user && $password === $user['mot_de_pass']) {
            
            // Stockage des variables de session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nom'] = $user['nom'];
            $_SESSION['user_prenom'] = $user['prenom'];
            $_SESSION['user_role'] = $user['role'];

            // Redirection automatique vers le bon espace selon le Rôle (Dépendance complexe)
            if ($user['role'] === 'etudiant') {
                header('Location: dashboard_etudiant.php');
            } elseif ($user['role'] === 'enseignant') {
                header('Location: dashboard_enseignant.php');
            } elseif ($user['role'] === 'admin') {
                header('Location: dashboard_admin.php');
            }
            exit();
        } else {
            $erreur = "Identifiants incorrects ou utilisateur inconnu.";
        }
    } else {
        $erreur = "Veuillez remplir tous les champs.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>SmartCampus - Connexion</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #0A2240; /* Bleu marine du sujet */
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            width: 350px;
            text-align: center;
        }
        h2 { color: #0A2240; margin-bottom: 25px; }
        h2 span { color: #D9383A; } /* Touche de rouge */
        .input-group { margin-bottom: 20px; text-align: left; }
        label { display: block; margin-bottom: 5px; color: #4A5568; font-size: 14px; }
        input { width: 100%; padding: 10px; border: 1px solid #E2E8F0; border-radius: 4px; box-sizing: border-box; }
        .btn-submit { background-color: #D9383A; color: white; border: none; width: 100%; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; transition: 0.3s; }
        .btn-submit:hover { background-color: #B8282A; }
        .error-msg { color: #D9383A; font-size: 14px; margin-bottom: 15px; }
    </style>
</head>
<body>

<div class="login-box">
    <h2>Smart<span>Campus</span></h2>
    
    <?php if(!empty($erreur)): ?>
        <div class="error-msg"><?php echo $erreur; ?></div>
    <?php endif; ?>

    <form action="login.php" method="POST">
        <div class="input-group">
            <label>Adresse Email École</label>
            <input type="email" name="email" required placeholder="ex: emma.martin@ecole.fr">
        </div>
        <div class="input-group">
            <label>Mot de passe</label>
            <input type="password" name="password" required placeholder="••••••••">
        </div>
        <button type="submit" class="btn-submit">Se connecter</button>
    </form>
</div>

</body>
</html>