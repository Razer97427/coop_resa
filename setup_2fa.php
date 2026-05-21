<?php
require_once 'config.php';
require_once 'GoogleAuthenticator.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$ga      = new PHPGangsta_GoogleAuthenticator();
$user_id = $_SESSION['user_id'];
$msg     = '';
$msg_type = '';

// Réinitialisation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_2fa'])) {
    $stmt_reset = $conn->prepare("UPDATE employes SET two_fa_secret = NULL WHERE id_employe = ?");
    $stmt_reset->bind_param("i", $user_id);
    if ($stmt_reset->execute()) {
        $stmt_reset->close();
        header("Location: setup_2fa.php?reset=success");
        exit();
    } else {
        $msg = "Erreur technique lors de la réinitialisation.";
        $msg_type = "error";
    }
}

if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $msg = "Clé 2FA réinitialisée. Configurez la nouvelle ci-dessous.";
    $msg_type = "success";
}

// Récupération utilisateur
$stmt = $conn->prepare("SELECT matricule, two_fa_secret FROM employes WHERE id_employe = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) die("Erreur utilisateur.");

$current_secret_in_db = $user['two_fa_secret'];
$secret_to_display    = $current_secret_in_db;

// Nouveau secret si besoin
if (empty($secret_to_display)) {
    $secret_to_display = isset($_POST['secret_hidden']) ? $_POST['secret_hidden'] : $ga->createSecret();
}

// Activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code']) && !isset($_POST['reset_2fa'])) {
    $code          = trim($_POST['code']);
    $secret_hidden = $_POST['secret_hidden'] ?? '';

    if ($ga->verifyCode($secret_hidden, $code, 2)) {
        $stmt_update = $conn->prepare("UPDATE employes SET two_fa_secret = ? WHERE id_employe = ?");
        $stmt_update->bind_param("si", $secret_hidden, $user_id);
        if ($stmt_update->execute()) {
            $msg = "La double authentification est maintenant active sur votre compte.";
            $msg_type = "success";
            $current_secret_in_db = $secret_hidden;
        } else {
            $msg = "Erreur lors de l'enregistrement. Veuillez réessayer.";
            $msg_type = "error";
        }
        $stmt_update->close();
    } else {
        $msg = "Code incorrect. Vérifiez l'heure de votre téléphone et réessayez.";
        $msg_type = "error";
        $secret_to_display = $secret_hidden;
    }
}

// QR Code
$qrCodeUrl = '';
if (empty($current_secret_in_db)) {
    $title     = 'CoopResa (' . $user['matricule'] . ')';
    $qrCodeUrl = $ga->getQRCodeGoogleUrl($title, $secret_to_display, $user['matricule']);
}
?>
<?php include 'includes/header.php'; ?>

<style>
    .twofa-wrap {
        max-width: 520px;
        margin: 36px auto;
        padding: 0 16px 48px;
    }

    .twofa-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        overflow: hidden;
    }

    .twofa-card-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 18px 24px;
        border-bottom: 1px solid #f0f0f0;
    }

    .twofa-card-header h2 {
        font-size: 0.97rem;
        font-weight: 700;
        color: #1a1a2e;
        margin: 0;
        flex: 1;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .badge.on  { background: #d4edda; color: #155724; }
    .badge.off { background: #f8d7da; color: #721c24; }

    .badge-dot { width: 6px; height: 6px; border-radius: 50%; }
    .badge.on  .badge-dot { background: #28a745; }
    .badge.off .badge-dot { background: #dc3545; }

    .twofa-card-body {
        padding: 28px 28px 24px;
        text-align: center;
    }

    /* ── Message ── */
    .msg-box {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        padding: 11px 14px;
        border-radius: 8px;
        font-size: 0.875rem;
        margin-bottom: 22px;
        line-height: 1.5;
        text-align: left;
    }

    .msg-box.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .msg-box.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

    /* ── État actif ── */
    .active-icon {
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: linear-gradient(135deg, #1a7f4b, #28a745);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 18px;
        color: #fff;
    }

    .active-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 6px;
    }

    .active-desc {
        font-size: 0.85rem;
        color: #888;
        margin-bottom: 28px;
        line-height: 1.5;
    }

    .divider {
        border: none;
        border-top: 1px solid #f0f0f0;
        margin: 0 -28px 24px;
    }

    .reset-section-title {
        font-size: 0.83rem;
        font-weight: 600;
        color: #495057;
        margin-bottom: 6px;
    }

    .reset-section-desc {
        font-size: 0.8rem;
        color: #888;
        margin-bottom: 16px;
        line-height: 1.4;
    }

    /* ── QR Code ── */
    .qr-intro {
        font-size: 0.88rem;
        color: #495057;
        margin-bottom: 20px;
        line-height: 1.6;
    }

    .qr-box {
        display: inline-block;
        padding: 12px;
        background: #fff;
        border: 1.5px solid #e9ecef;
        border-radius: 10px;
        margin-bottom: 18px;
    }

    .qr-box img { display: block; }

    .manual-key-block {
        background: #f8f9fa;
        border: 1.5px dashed #dee2e6;
        border-radius: 8px;
        padding: 10px 16px;
        margin-bottom: 24px;
    }

    .manual-key-label {
        font-size: 0.75rem;
        color: #888;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 4px;
    }

    .manual-key-value {
        font-family: 'Courier New', monospace;
        font-size: 1rem;
        font-weight: 700;
        color: #0054b3;
        letter-spacing: 0.08em;
    }

    /* ── Code input ── */
    .code-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #495057;
        display: block;
        margin-bottom: 10px;
    }

    .code-input {
        width: 180px;
        padding: 12px 14px;
        font-size: 1.4rem;
        font-family: 'Courier New', monospace;
        text-align: center;
        letter-spacing: 0.25em;
        border: 1.5px solid #dee2e6;
        border-radius: 9px;
        background: #fff;
        transition: border-color 0.2s, box-shadow 0.2s;
        display: block;
        margin: 0 auto 20px;
    }

    .code-input:focus {
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,0.12);
        outline: none;
    }

    /* ── Boutons ── */
    .btn-primary {
        width: 100%;
        padding: 11px;
        background: #007bff;
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
        margin-bottom: 4px;
    }

    .btn-primary:hover { background: #0062cc; }

    .btn-danger {
        width: 100%;
        padding: 10px;
        background: #fff;
        color: #dc3545;
        border: 1.5px solid #dc3545;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s, color 0.2s;
    }

    .btn-danger:hover { background: #dc3545; color: #fff; }

    .back-link {
        display: block;
        text-align: center;
        margin-top: 18px;
        font-size: 0.83rem;
        color: #6c757d;
        text-decoration: none;
    }

    .back-link:hover { color: #007bff; }
</style>

<div class="twofa-wrap">
    <div class="twofa-card">

        <div class="twofa-card-header">
            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18" style="color:#007bff;flex-shrink:0">
                <path d="M17 1H7c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2zm-5 20c-.83 0-1.5-.67-1.5-1.5S11.17 18 12 18s1.5.67 1.5 1.5S12.83 21 12 21zm5-4H7V4h10v13z"/>
            </svg>
            <h2>Double authentification (2FA)</h2>
            <span class="badge <?php echo !empty($current_secret_in_db) ? 'on' : 'off'; ?>">
                <span class="badge-dot"></span>
                <?php echo !empty($current_secret_in_db) ? 'Activée' : 'Désactivée'; ?>
            </span>
        </div>

        <div class="twofa-card-body">

            <?php if (!empty($msg)): ?>
                <div class="msg-box <?php echo $msg_type; ?>">
                    <?php if ($msg_type === 'success'): ?>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" style="flex-shrink:0;margin-top:1px"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" style="flex-shrink:0;margin-top:1px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($current_secret_in_db)): ?>

                <div class="active-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="34" height="34">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
                    </svg>
                </div>
                <div class="active-title">Compte sécurisé</div>
                <div class="active-desc">La double authentification est active.<br>Un code est requis à chaque connexion.</div>

                <hr class="divider">

                <div class="reset-section-title">Changer de téléphone ?</div>
                <div class="reset-section-desc">Réinitialisez votre clé 2FA puis re-scannez le nouveau QR Code avec votre application.</div>

                <form method="POST" onsubmit="return confirm('En réinitialisant, votre code actuel ne fonctionnera plus.\nVous devrez re-scanner le nouveau QR Code immédiatement.\n\nContinuer ?');">
                    <input type="hidden" name="reset_2fa" value="1">
                    <button type="submit" class="btn-danger">Réinitialiser la clé 2FA</button>
                </form>

            <?php else: ?>

                <p class="qr-intro">
                    Scannez ce QR Code avec <strong>Google Authenticator</strong>, <strong>Authy</strong> ou toute application TOTP compatible.
                </p>

                <div class="qr-box">
                    <img src="<?php echo htmlspecialchars($qrCodeUrl); ?>" alt="QR Code 2FA" width="180" height="180">
                </div>

                <div class="manual-key-block">
                    <div class="manual-key-label">Clé manuelle (si QR Code illisible)</div>
                    <div class="manual-key-value"><?php echo htmlspecialchars($secret_to_display); ?></div>
                </div>

                <form method="POST">
                    <input type="hidden" name="secret_hidden" value="<?php echo htmlspecialchars($secret_to_display); ?>">
                    <label class="code-label" for="code">Entrez le code à 6 chiffres affiché par l'application :</label>
                    <input type="text" id="code" name="code" class="code-input"
                           placeholder="000000" required autocomplete="off"
                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autofocus>
                    <button type="submit" class="btn-primary">Activer la 2FA</button>
                </form>

            <?php endif; ?>

        </div>
    </div>

    <a href="settings.php" class="back-link">← Retour aux paramètres</a>
</div>

<?php include 'includes/footer.php'; ?>
