<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token   = trim($_GET['token'] ?? '');
$success = false;
$error   = '';

if (empty($token) || strlen($token) !== 64) {
    $error = "Lien de vérification invalide ou incomplet.";
} else {
    $stmt = $conn->prepare("SELECT user_id, new_email, token_expiry FROM email_verif_auto WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$data) {
        $error = "Ce lien n'existe pas ou a déjà été utilisé.";
    } elseif (strtotime($data['token_expiry']) < time()) {
        $error = "Ce lien a expiré. Veuillez faire une nouvelle demande depuis vos paramètres.";
        $del = $conn->prepare("DELETE FROM email_verif_auto WHERE token = ?");
        $del->bind_param("s", $token);
        $del->execute();
        $del->close();
    } else {
        // Vérifier que l'email n'est pas déjà pris
        $chk = $conn->prepare("SELECT id_employe FROM employes WHERE email = ? AND id_employe != ?");
        $chk->bind_param("si", $data['new_email'], $data['user_id']);
        $chk->execute();
        $already_used = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($already_used) {
            $error = "Cette adresse e-mail est désormais utilisée par un autre compte. Veuillez refaire une demande avec une autre adresse.";
            $del = $conn->prepare("DELETE FROM email_verif_auto WHERE token = ?");
            $del->bind_param("s", $token);
            $del->execute();
            $del->close();
        } else {
            $upd = $conn->prepare("UPDATE employes SET email = ? WHERE id_employe = ?");
            $upd->bind_param("si", $data['new_email'], $data['user_id']);
            if ($upd->execute()) {
                $upd->close();
                $del = $conn->prepare("DELETE FROM email_verif_auto WHERE user_id = ?");
                $del->bind_param("i", $data['user_id']);
                $del->execute();
                $del->close();
                $success    = true;
                $new_email  = $data['new_email'];
            } else {
                $upd->close();
                $error = "Erreur lors de la mise à jour. Veuillez réessayer.";
            }
        }
    }
}
?>
<?php include 'includes/header.php'; ?>

<style>
    .verif-wrap {
        max-width: 480px;
        margin: 48px auto;
        padding: 0 16px 48px;
    }

    .verif-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        padding: 36px 32px;
        text-align: center;
    }

    .verif-icon {
        width: 68px;
        height: 68px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        color: #fff;
    }

    .verif-icon.success { background: linear-gradient(135deg, #1a7f4b, #28a745); }
    .verif-icon.error   { background: linear-gradient(135deg, #a71d2a, #dc3545); }

    .verif-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 8px;
    }

    .verif-desc {
        font-size: .88rem;
        color: #6c757d;
        line-height: 1.6;
        margin-bottom: 28px;
    }

    .verif-email {
        display: inline-block;
        background: #e8f0fe;
        color: #0054b3;
        font-weight: 600;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: .9rem;
        margin-bottom: 24px;
    }

    .btn-retour {
        display: inline-block;
        padding: 11px 28px;
        background: #007bff;
        color: #fff;
        border-radius: 8px;
        font-size: .93rem;
        font-weight: 600;
        text-decoration: none;
        transition: background .2s;
    }

    .btn-retour:hover { background: #0062cc; }

    .btn-retour.outline {
        background: #fff;
        color: #007bff;
        border: 1.5px solid #007bff;
    }

    .btn-retour.outline:hover { background: #f0f4ff; }
</style>

<div class="verif-wrap">
    <div class="verif-card">

        <?php if ($success): ?>
            <div class="verif-icon success">
                <svg viewBox="0 0 24 24" fill="currentColor" width="32" height="32">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                </svg>
            </div>
            <div class="verif-title">Adresse e-mail confirmée</div>
            <div class="verif-desc">Votre adresse e-mail a été mise à jour avec succès.</div>
            <div class="verif-email"><?php echo htmlspecialchars($new_email); ?></div><br>
            <a href="update_password.php" class="btn-retour">Retour aux paramètres</a>

        <?php else: ?>
            <div class="verif-icon error">
                <svg viewBox="0 0 24 24" fill="currentColor" width="32" height="32">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
            </div>
            <div class="verif-title">Lien invalide</div>
            <div class="verif-desc"><?php echo htmlspecialchars($error); ?></div>
            <a href="update_password.php" class="btn-retour outline">Retour aux paramètres</a>
        <?php endif; ?>

    </div>
</div>

<?php include 'includes/footer.php'; ?>
