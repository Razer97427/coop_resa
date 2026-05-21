<?php
require_once 'config.php';
require_once 'GoogleAuthenticator.php';

// Démarrage session si besoin
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vérification de connexion
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$ga = new PHPGangsta_GoogleAuthenticator();
$user_id = $_SESSION['user_id'];
$msg = "";
$msg_type = ""; 

// --- ACTION : RÉINITIALISATION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_2fa'])) {
    $stmt_reset = $conn->prepare("UPDATE employes SET two_fa_secret = NULL WHERE id_employe = ?");
    $stmt_reset->bind_param("i", $user_id);
    if($stmt_reset->execute()) {
        $stmt_reset->close();
        header("Location: setup_2fa.php?reset=success");
        exit();
    } else {
        $msg = "Erreur technique lors de la réinitialisation.";
        $msg_type = "error";
    }
}

if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    $msg = "Clé 2FA réinitialisée. Configurez la nouvelle ci-dessous.";
    $msg_type = "success";
}

// 1. Récupérer le secret actuel
$stmt = $conn->prepare("SELECT matricule, two_fa_secret FROM employes WHERE id_employe = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) die("Erreur utilisateur.");

$current_secret_in_db = $user['two_fa_secret'];
$secret_to_display = $current_secret_in_db;

// 2. Préparer un nouveau secret si besoin
if (empty($secret_to_display)) {
    if (isset($_POST['secret_hidden'])) {
        $secret_to_display = $_POST['secret_hidden'];
    } else {
        $secret_to_display = $ga->createSecret();
    }
}

// 3. Traitement activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code']) && !isset($_POST['reset_2fa'])) {
    $code = trim($_POST['code']);
    $secret_hidden = $_POST['secret_hidden'] ?? '';
    
    $checkResult = $ga->verifyCode($secret_hidden, $code, 2);
    
    if ($checkResult) {
        $stmt_update = $conn->prepare("UPDATE employes SET two_fa_secret = ? WHERE id_employe = ?");
        $stmt_update->bind_param("si", $secret_hidden, $user_id);
        if ($stmt_update->execute()) {
            $msg = "Succès ! La double authentification est active.";
            $msg_type = "success";
            $current_secret_in_db = $secret_hidden;
        } else {
            $msg = "Erreur SQL.";
            $msg_type = "error";
        }
        $stmt_update->close();
    } else {
        $msg = "Code incorrect. Réessayez.";
        $msg_type = "error";
        $secret_to_display = $secret_hidden; 
    }
}

// 4. URL QR Code
$qrCodeUrl = '';
if (empty($current_secret_in_db)) {
    $title = 'CoopResa (' . $user['matricule'] . ')';
    $qrCodeUrl = $ga->getQRCodeGoogleUrl($title, $secret_to_display, $user['matricule']);
}

// --- DEBUT DE L'AFFICHAGE ---
include 'includes/header.php'; 
?>

<style>
    .two-fa-container {
        max-width: 600px;
        margin: 40px auto;
        background: #fff;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        text-align: center;
    }
    .msg { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: bold; }
    .msg.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .msg.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    
    .code-input { 
        padding: 10px; font-size: 1.5em; text-align: center; width: 180px; 
        margin: 15px 0; letter-spacing: 5px; border: 2px solid #ddd; border-radius:5px;
    }
    
    .btn { display: inline-block; padding: 10px 20px; border-radius: 5px; text-decoration: none; cursor: pointer; border: none; font-size: 1em; transition: 0.3s; }
    .btn-primary { background-color: #007bff; color: white; }
    .btn-primary:hover { background-color: #0056b3; }
    .btn-danger { background-color: #dc3545; color: white; }
    .btn-danger:hover { background-color: #c82333; }
    
    .qr-box { margin: 20px auto; padding: 10px; background:#fff; border: 1px solid #eee; display: inline-block; }
    .manual-key { background: #f8f9fa; padding: 5px 10px; border: 1px dashed #ccc; font-family: monospace; font-size: 1.1em; }
</style>

<script>
    function confirmReset() {
        return confirm("⚠️ Attention !\n\nEn réinitialisant, votre code actuel ne fonctionnera plus.\nVous devrez re-scanner le nouveau code immédiatement.\n\nContinuer ?");
    }
</script>

<div class="two-fa-container">
    <h2>📱 Authentification Double Facteur</h2>
    
    <?php if (!empty($msg)) echo "<div class='msg $msg_type'>$msg</div>"; ?>

    <?php if (!empty($current_secret_in_db)): ?>
        <div style="margin-top: 30px;">
            <div style="font-size: 4em; margin-bottom: 10px;">✅</div>
            <h3 style="color:#28a745; margin:0;">Protection Active</h3>
            <p style="color:#666;">Votre compte est sécurisé.</p>
            
            <hr style="margin: 30px 0; border: 0; border-top: 1px solid #eee;">
            
            <p>Besoin de changer de téléphone ?</p>
            <form method="post" onsubmit="return confirmReset();">
                <input type="hidden" name="reset_2fa" value="1">
                <button type="submit" class="btn btn-danger">🔄 Regénérer le code</button>
            </form>
            
            <br>
            <a href="settings.php" style="color: #666; text-decoration: none;">&larr; Retour</a>
        </div>

    <?php else: ?>
        <p style="margin-bottom: 20px;">Scannez ce QR Code avec <strong>Google Authenticator</strong> :</p>
        
        <div class="qr-box">
            <img src="<?php echo $qrCodeUrl; ?>" alt="QR Code" style="max-width: 100%; height: auto;" />
        </div>
        
        <p style="font-size: 0.9em; color: #555;">
            Impossible de scanner ?<br>Clé manuelle : <span class="manual-key"><?php echo $secret_to_display; ?></span>
        </p>
        
        <form method="post" style="margin-top: 20px;">
            <input type="hidden" name="secret_hidden" value="<?php echo $secret_to_display; ?>">
            
            <label>Entrez le code à 6 chiffres :</label><br>
            <input type="text" name="code" class="code-input" placeholder="000 000" required autocomplete="off" inputmode="numeric" pattern="[0-9]*" autofocus>
            <br>
            <button type="submit" class="btn btn-primary">Activer</button>
        </form>
        
        <br>
        <a href="settings.php" style="color: #666; text-decoration: none;">Annuler</a>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>