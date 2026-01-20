<?php
require_once 'config.php';

// On s'assure que la session est démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
	check_csrf();
    $matricule = trim($_POST['matricule'] ?? '');
    $password_saisi = $_POST['password'] ?? '';

    if (empty($matricule) || empty($password_saisi)) {
        $error_message = "Champs obligatoires.";
    } else {
        // On récupère id, nom, role, pass ET le secret 2FA
        $stmt = $conn->prepare("SELECT id_employe, nom, prenom, role, mot_de_passe, two_fa_secret FROM employes WHERE matricule = ? AND actif = TRUE");
        
        if ($stmt) {
            $stmt->bind_param("s", $matricule);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();

            // Vérification du mot de passe
            if ($user && $user['mot_de_passe'] == $password_saisi) {
                
                // --- LOGIQUE 2FA OBLIGATOIRE ---
                
                if (!empty($user['two_fa_secret'])) {
                    // CAS 1 : L'utilisateur a bien la 2FA -> On procède à la vérification
                    $_SESSION['2fa_pending_user_id'] = $user['id_employe'];
                    header("Location: login_2fa_check.php");
                    exit();
                } else {
                    // CAS 2 : L'utilisateur n'a PAS de 2FA -> ON BLOQUE
                    // On ne crée pas de session, on affiche juste l'erreur.
                    $error_message = "Connexion refusée : La double authentification (2FA) est obligatoire. Veuillez contacter le service informatique pour configurer votre accès.";
                }

            } else {
                $error_message = "Identifiants incorrects.";
            }
        } else {
            $error_message = "Erreur technique base de données.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion</title>
	
	<h1 class="titre-de-connexion">GESTION AUTOMOBILE TERRACOOP</h1>
	
    <link rel="stylesheet" href="styles.css">
    <style>
		.h1 {
			background-color: red;
		}
	
        body.login-body {
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f4f4f4;
            margin: 0;
        }
        .login-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        .login-container h2 {
            margin-bottom: 20px;
            color: #333;
        }
        .input-group {
            margin-bottom: 15px;
            text-align: left;
        }
        .input-group label {
            display: block;
            margin-bottom: 5px;
            color: #666;
        }
        .input-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .btn-submit {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        .btn-submit:hover {
            background-color: #0056b3;
        }
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
            font-size: 14px;
            line-height: 1.4;
        }
        .links {
            margin-top: 15px;
            font-size: 0.9em;
        }
        .links a {
            color: #007bff;
            text-decoration: none;
        }
    </style>
</head>
<body class="login-body">

<div class="login-container">
    <h2>Connexion</h2>
    
    <?php if ($error_message): ?>
        <div class="message error">
            <strong>Erreur :</strong><br>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <form action="login.php" method="POST">
        <div class="input-group">
            <?php csrf_field(); ?> <label for="matricule">Matricule</label>
            <input type="text" id="matricule" name="matricule" required autofocus placeholder="Entrez votre matricule">
        </div>
        
        <div class="input-group">
            <label for="password">Mot de passe</label>
            <input type="password" id="password" name="password" required placeholder="Votre mot de passe">
        </div>

        <button type="submit" class="btn-submit">Se connecter</button>
    </form>
    
    <!--<div class="links">
        <a href="forgot.php">Mot de passe oublié ?</a>
    </div>-->
</div>

</body>
</html>