<?php
// admin_2fa.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php'; 
require_once 'GoogleAuthenticator.php'; 

$ga = new PHPGangsta_GoogleAuthenticator();
$message = "";
$user_data = null;
$show_new_setup = false;

// 1. RECUPERATION DU MATRICULE
$matricule = isset($_POST['matricule']) ? trim($_POST['matricule']) : null;

// 2. LOGIQUE DE RECHERCHE D'UTILISATEUR
if ($matricule) {
    $stmt = $conn->prepare("SELECT matricule, nom, prenom, two_fa_secret FROM employes WHERE matricule = ?");
    $stmt->bind_param("s", $matricule);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();
    $stmt->close();
}

// 3. ETAPE : DEVERROUILLER LA MODIFICATION
if (isset($_POST['unlock_mod'])) {
    if ($_POST['pass_admin'] === PASS_ADMIN_TOTP) {
        $show_new_setup = true;
    } else {
        $message = "<div style='color:red; padding:10px; border:1px solid red;'>❌ Mot de passe maître incorrect.</div>";
    }
}

// 4. ETAPE : VERIFIER LE CODE TOTP ET ENREGISTRER
if (isset($_POST['verify_and_update'])) {
    $new_secret = $_POST['nouveau_secret'];
    $otp_code = $_POST['otp_to_check'];
    
    if ($ga->verifyCode($new_secret, $otp_code, 2)) {
        $stmt = $conn->prepare("UPDATE employes SET two_fa_secret = ? WHERE matricule = ?");
        $stmt->bind_param("ss", $new_secret, $matricule);
        if ($stmt->execute()) {
            $message = "<div style='background:green; color:white; padding:15px; border-radius:5px;'>✅ Succès ! Nouveau 2FA enregistré pour $matricule</div>";
            $user_data['two_fa_secret'] = $new_secret;
        }
        $stmt->close();
    } else {
        $message = "<div style='background:red; color:white; padding:15px; border-radius:5px;'>❌ Code TOTP invalide. Vérifiez l'heure de votre téléphone.</div>";
        $show_new_setup = true; 
    }
}

$secret_temp = isset($_POST['nouveau_secret']) ? $_POST['nouveau_secret'] : $ga->createSecret();
$titre_app = "Coop_Resa (" . ($matricule ?? 'Admin') . ")";
$qrCodeUrl = $ga->getQRCodeGoogleUrl($titre_app, $secret_temp);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Admin 2FA - Gestion</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0f2f5; display: flex; justify-content: center; padding: 40px; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); width: 100%; max-width: 500px; }
        .info-box { background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 6px solid #2196f3; }
        .time-box { background: #fff3e0; padding: 10px; border-radius: 6px; text-align: center; margin-bottom: 20px; border: 1px solid #ffe0b2; font-size: 0.9em; }
        input { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; cursor: pointer; border: none; border-radius: 6px; font-weight: bold; margin-top: 10px; }
        .btn-blue { background: #2196f3; color: white; }
        .btn-green { background: #4caf50; color: white; }
    </style>
</head>

<script>
function startLiveClock() {
    // On récupère l'heure affichée par PHP au chargement
    let clockElement = document.getElementById('server-clock');
    let timeParts = clockElement.innerText.split(':');
    
    let hours = parseInt(timeParts[0]);
    let minutes = parseInt(timeParts[1]);
    let seconds = parseInt(timeParts[2]);

    // On met à jour toutes les secondes
    setInterval(function() {
        seconds++;
        if (seconds >= 60) {
            seconds = 0;
            minutes++;
        }
        if (minutes >= 60) {
            minutes = 0;
            hours++;
        }
        if (hours >= 24) {
            hours = 0;
        }

        // Formatage 00:00:00
        let displayTime = 
            (hours < 10 ? '0' + hours : hours) + ':' + 
            (minutes < 10 ? '0' + minutes : minutes) + ':' + 
            (seconds < 10 ? '0' + seconds : seconds);
            
        clockElement.innerText = displayTime;
    }, 1000);
}

// Lancement au chargement de la page
window.onload = startLiveClock;
</script>

<body>

<div class="card">
    <h2 style="margin-top:0; color:#333; text-align:center;">Gestionnaire 2FA</h2>
    
    <div class="time-box">
    🕒 Heure serveur : <strong id="server-clock"><?php echo date('H:i:s'); ?></strong><br>
    <small>Fuseau : <?php echo date_default_timezone_get(); ?></small>
</div>

    <?php if($message) echo $message; ?>

    <form method="post">
        <label>Matricule de l'employé :</label>
        <input type="text" name="matricule" value="<?php echo htmlspecialchars($matricule ?? ''); ?>" required>
        <button type="submit" name="search_user" class="btn-blue">🔍 Rechercher</button>
    </form>

    <?php if ($user_data): ?>
        <div class="info-box">
            <strong>👤 Utilisateur :</strong> <?php echo htmlspecialchars($user_data['prenom'] . " " . $user_data['nom']); ?><br>
            <strong>Status 2FA :</strong> <?php echo $user_data['two_fa_secret'] ? "✅ Activé" : "❌ Non configuré"; ?>
        </div>

        <?php if (!$show_new_setup): ?>
            <form method="post">
                <input type="hidden" name="matricule" value="<?php echo htmlspecialchars($user_data['matricule']); ?>">
                <label>🔓 Mot de passe maître :</label>
                <input type="password" name="pass_admin" required autofocus>
                <button type="submit" name="unlock_mod" style="background:#607d8b; color:white;">Autoriser la modification</button>
            </form>
        <?php else: ?>
            <div style="text-align:center; background:#f9f9f9; padding:20px; border-radius:10px; border:1px dashed #ccc;">
                <h3>Nouveau QR Code</h3>
                <img src="<?php echo $qrCodeUrl; ?>" alt="QR Code">
                <form method="post">
                    <input type="hidden" name="matricule" value="<?php echo htmlspecialchars($user_data['matricule']); ?>">
                    <input type="hidden" name="nouveau_secret" value="<?php echo $secret_temp; ?>">
                    <label>Code de vérification :</label>
                    <input type="text" name="otp_to_check" placeholder="000000" required>
                    <button type="submit" name="verify_and_update" class="btn-green">💾 Enregistrer</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

</body>
</html>