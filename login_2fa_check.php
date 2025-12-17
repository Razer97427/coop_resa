<?php
// login_2fa_check.php
require_once 'config.php';
require_once 'GoogleAuthenticator.php';

// Si on n'est pas en attente de 2FA, retour au login
if (!isset($_SESSION['2fa_pending_user_id'])) {
    header("Location: login.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $code = $_POST['code'];
    $user_id = $_SESSION['2fa_pending_user_id'];

    // Récupérer le secret en base
    $stmt = $pdo->prepare("SELECT * FROM employes WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    $ga = new PHPGangsta_GoogleAuthenticator();
    // Vérification du code
    $checkResult = $ga->verifyCode($user['two_fa_secret'], $code, 2);

    if ($checkResult) {
        // Code OK : On connecte réellement l'utilisateur
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['matricule'] = $user['matricule'];
        $_SESSION['role'] = $user['role']; // Si vous gérez les rôles

        // On nettoie la variable temporaire
        unset($_SESSION['2fa_pending_user_id']);

        header("Location: index.php");
        exit;
    } else {
        $error = "Code incorrect.";
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
        body { font-family: sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f4f4f4; }
        .login-container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); text-align: center; }
        input { display: block; margin: 10px auto; padding: 8px; width: 80%; }
        button { padding: 10px 20px; cursor: pointer; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>Authentification Double Facteur</h2>
        <p>Entrez le code à 6 chiffres de votre application.</p>
        
        <form method="post">
            <input type="text" name="code" placeholder="Ex: 123456" required autocomplete="off" autofocus>
            <button type="submit">Vérifier</button>
        </form>
        
        <?php if($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        
        <br>
        <a href="login.php">Retour</a>
    </div>
</body>
</html>