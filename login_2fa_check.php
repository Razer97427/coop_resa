<?php
require_once 'config.php';
require_once 'GoogleAuthenticator.php';

// Démarrer la session si ce n'est pas déjà fait dans config.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si on n'est pas en attente de 2FA, retour au login
if (!isset($_SESSION['2fa_pending_user_id'])) {
    header("Location: login.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $code = trim($_POST['code']);
    $user_id = $_SESSION['2fa_pending_user_id'];

    // CORRECTION : Utilisation de $conn (MySQLi) au lieu de $pdo
    // CORRECTION : Utilisation de 'id_employe' au lieu de 'id'
    $stmt = $conn->prepare("SELECT id_employe, nom, prenom, role, matricule, two_fa_secret FROM employes WHERE id_employe = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        $ga = new PHPGangsta_GoogleAuthenticator();
        // Vérification du code
        // La librairie veut le secret et le code soumis
        $checkResult = $ga->verifyCode($user['two_fa_secret'], $code, 2); // 2 = marge de tolérance (2x30sec)

        if ($checkResult) {
            // Code OK : On connecte réellement l'utilisateur
            // On recrée les variables de session comme dans le login classique
            $_SESSION['user_id'] = $user['id_employe'];
            $_SESSION['user_name'] = $user['prenom'] . ' ' . $user['nom'];
            $_SESSION['user_role'] = $user['role'];
            
            // On peut ajouter le matricule si besoin
            $_SESSION['matricule'] = $user['matricule'];

            // On nettoie la variable temporaire de 2FA
            unset($_SESSION['2fa_pending_user_id']);

            // Redirection selon le rôle
            $redirect = ($user['role'] === 'Manager') ? 'manager.php' : 'index.php';
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = "Code incorrect.";
        }
    } else {
        // Cas rare : l'utilisateur a disparu entre le login et la validation
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vérification 2FA</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Style de secours simple */
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f4f4f4; margin:0; }
        .login-container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); text-align: center; width: 100%; max-width: 400px;}
        h2 { color: #333; margin-bottom: 20px; }
        input[type="text"] { display: block; margin: 15px auto; padding: 10px; width: 80%; font-size: 18px; text-align: center; letter-spacing: 5px; border: 1px solid #ddd; border-radius: 4px; }
        button { padding: 12px 25px; cursor: pointer; background-color: #007bff; color: white; border: none; border-radius: 4px; font-size: 16px; width: 80%; }
        button:hover { background-color: #0056b3; }
        .error { color: #dc3545; margin-top: 15px; }
        .back-link { display: block; margin-top: 20px; color: #666; text-decoration: none; font-size: 0.9em; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>🔐 Double Authentification</h2>
        <p>Veuillez entrer le code à 6 chiffres généré par votre application mobile.</p>
        
        <form method="post">
            <input type="text" name="code" placeholder="000 000" required autocomplete="off" autofocus maxlength="6" pattern="[0-9]*" inputmode="numeric">
            <button type="submit">Vérifier</button>
        </form>
        
        <?php if($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        
        <a href="login.php" class="back-link">Annuler et retourner à la connexion</a>
    </div>
</body>
</html>