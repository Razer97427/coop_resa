<?php
// setup_2fa.php
require_once 'config.php';
require_once 'GoogleAuthenticator.php'; // Inclusion manuelle

// Vérification de connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$ga = new PHPGangsta_GoogleAuthenticator();
$user_id = $_SESSION['user_id'];

// Récupérer le secret actuel
$stmt = $pdo->prepare("SELECT matricule, two_fa_secret FROM employes WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

$secret = $user['two_fa_secret'];

// Si pas de secret, on en génère un nouveau
if (empty($secret)) {
    $secret = $ga->createSecret();
    // On ne sauvegarde pas tout de suite en base pour éviter de bloquer l'user s'il ne scanne pas
    // Mais pour faire simple ici, on peut l'afficher. 
    // L'idéal est de demander une confirmation du code avant update.
}

// Traitement du formulaire de confirmation
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code'])) {
    $code = $_POST['code'];
    $secret_hidden = $_POST['secret_hidden'];
    
    $checkResult = $ga->verifyCode($secret_hidden, $code, 2); // 2 = marge de 2x30sec
    
    if ($checkResult) {
        // Le code est bon, on sauvegarde le secret définitivement
        $update = $pdo->prepare("UPDATE employes SET two_fa_secret = ? WHERE id = ?");
        $update->execute([$secret_hidden, $user_id]);
        $msg = "Succès ! La double authentification est activée.";
        $secret = $secret_hidden; // On met à jour l'affichage
        // Recharger l'user pour être sûr
        $user['two_fa_secret'] = $secret;
    } else {
        $msg = "Code incorrect. Veuillez réessayer.";
    }
}

// Génération du QR Code
// Note: Le titre 'CoopResa' apparaitra dans l'appli
$qrCodeUrl = $ga->getQRCodeGoogleUrl('CoopResa', $secret, $user['matricule']);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Activer 2FA</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container">
        <h2>Configuration de la double authentification (2FA)</h2>
        
        <?php if (!empty($msg)) echo "<p><strong>$msg</strong></p>"; ?>

        <?php if (!empty($user['two_fa_secret'])): ?>
            <p style="color:green;">✅ Votre double authentification est active.</p>
            <p>Pour la réinitialiser, contactez un administrateur.</p>
        <?php else: ?>
            <p>Scannez ce QR Code avec votre application (Google Authenticator, Authy...) :</p>
            <img src="<?php echo $qrCodeUrl; ?>" />
            <p>Code secret : <?php echo $secret; ?></p>
            
            <form method="post">
                <input type="hidden" name="secret_hidden" value="<?php echo $secret; ?>">
                <label>Entrez le code à 6 chiffres affiché sur votre app :</label>
                <input type="text" name="code" required>
                <button type="submit">Activer</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>