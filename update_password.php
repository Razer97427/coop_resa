<?php
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$message       = '';
$message_type  = '';
$email_message = '';
$email_type    = '';

// Table de vérification email
$conn->query("CREATE TABLE IF NOT EXISTS email_verif_auto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    new_email VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    token_expiry DATETIME NOT NULL,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$stmt = $conn->prepare("SELECT nom, prenom, matricule, email, role, two_fa_secret, mot_de_passe FROM employes WHERE id_employe = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$employe = $stmt->get_result()->fetch_assoc();
$stmt->close();

$action = $_POST['action'] ?? '';

// ── Changement de mot de passe ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_password') {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass     = $_POST['new_password']     ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (empty($current_pass) || empty($new_pass) || empty($confirm_pass)) {
        $message = "Veuillez remplir tous les champs.";
        $message_type = "error";
    } elseif ($employe['mot_de_passe'] !== $current_pass) {
        $message = "Le mot de passe actuel est incorrect.";
        $message_type = "error";
    } elseif ($new_pass !== $confirm_pass) {
        $message = "Les nouveaux mots de passe ne correspondent pas.";
        $message_type = "error";
    } elseif (strlen($new_pass) < 6) {
        $message = "Le nouveau mot de passe doit contenir au moins 6 caractères.";
        $message_type = "error";
    } else {
        $stmt2 = $conn->prepare("UPDATE employes SET mot_de_passe = ? WHERE id_employe = ?");
        $stmt2->bind_param("si", $new_pass, $user_id);
        if ($stmt2->execute()) {
            $employe['mot_de_passe'] = $new_pass;
            $message = "Mot de passe modifié avec succès.";
            $message_type = "success";
        } else {
            $message = "Erreur lors de la mise à jour. Veuillez réessayer.";
            $message_type = "error";
        }
        $stmt2->close();
    }
}

// ── Changement d'adresse e-mail ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_email') {
    $new_email = trim($_POST['new_email'] ?? '');

    if (empty($new_email)) {
        $email_message = "Veuillez saisir une adresse e-mail.";
        $email_type    = "error";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $email_message = "L'adresse e-mail saisie n'est pas valide.";
        $email_type    = "error";
    } elseif (strtolower($new_email) === strtolower($employe['email'] ?? '')) {
        $email_message = "Cette adresse est déjà votre adresse actuelle.";
        $email_type    = "error";
    } else {
        // Vérifier si l'email est déjà utilisé par un autre employé
        $chk = $conn->prepare("SELECT id_employe FROM employes WHERE email = ? AND id_employe != ?");
        $chk->bind_param("si", $new_email, $user_id);
        $chk->execute();
        $already_used = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($already_used) {
            $email_message = "Cette adresse e-mail est déjà utilisée par un autre compte.";
            $email_type    = "error";
        } else {
            $token  = bin2hex(random_bytes(32));
            $expiry = date("Y-m-d H:i:s", time() + 3600); // 1 heure

            // Supprimer toute demande en attente pour cet utilisateur
            $del = $conn->prepare("DELETE FROM email_verif_auto WHERE user_id = ?");
            $del->bind_param("i", $user_id);
            $del->execute();
            $del->close();

            $ins = $conn->prepare("INSERT INTO email_verif_auto (user_id, new_email, token, token_expiry) VALUES (?, ?, ?, ?)");
            $ins->bind_param("isss", $user_id, $new_email, $token, $expiry);
            $ins->execute();
            $ins->close();

            $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base_path   = rtrim(dirname($_SERVER['PHP_SELF']), '/');
            $verify_link = $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path . '/verify_email.php?token=' . $token;

            try {
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host       = smtp_host;
                $mail->SMTPAuth   = true;
                $mail->Username   = smtp_username;
                $mail->Password   = smtp_password;
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = smtp_port;
                $mail->CharSet    = 'UTF-8';

                $mail->setFrom(smtp_from, 'Gestion Flotte TERRACOOP');
                $mail->addAddress($new_email, $employe['prenom'] . ' ' . $employe['nom']);

                $mail->isHTML(false);
                $mail->Subject = 'Confirmation de votre nouvelle adresse e-mail — TERRACOOP';
                $mail->Body    = "Bonjour " . $employe['prenom'] . " " . $employe['nom'] . ",\n\n"
                               . "Vous avez demandé à associer cette adresse e-mail à votre compte TERRACOOP.\n\n"
                               . "Cliquez sur le lien ci-dessous pour confirmer :\n"
                               . $verify_link . "\n\n"
                               . "Ce lien est valable 1 heure.\n"
                               . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.\n\n"
                               . "— Service Informatique TERRACOOP";

                $mail->send();
            } catch (Exception $e) {
                error_log("[EMAIL VERIF] Échec envoi vers " . $new_email . " — " . $mail->ErrorInfo);
            }

            $email_message = "Un e-mail de confirmation a été envoyé à <strong>" . htmlspecialchars($new_email) . "</strong>. Cliquez sur le lien reçu pour valider le changement.";
            $email_type    = "success";
        }
    }
}

// ── Renvoi du lien de confirmation ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'resend_email_verif') {
    $stmt_rv = $conn->prepare("SELECT new_email FROM email_verif_auto WHERE user_id = ?");
    $stmt_rv->bind_param("i", $user_id);
    $stmt_rv->execute();
    $rv = $stmt_rv->get_result()->fetch_assoc();
    $stmt_rv->close();

    if ($rv) {
        $token_new  = bin2hex(random_bytes(32));
        $expiry_new = date("Y-m-d H:i:s", time() + 3600);

        $upd_rv = $conn->prepare("UPDATE email_verif_auto SET token = ?, token_expiry = ? WHERE user_id = ?");
        $upd_rv->bind_param("ssi", $token_new, $expiry_new, $user_id);
        $upd_rv->execute();
        $upd_rv->close();

        $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base_path   = rtrim(dirname($_SERVER['PHP_SELF']), '/');
        $verify_link = $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path . '/verify_email.php?token=' . $token_new;

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = smtp_username;
            $mail->Password   = smtp_password;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = smtp_port;
            $mail->CharSet    = 'UTF-8';
            $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
            $mail->setFrom(smtp_from, 'Gestion Flotte TERRACOOP');
            $mail->addAddress($rv['new_email'], $employe['prenom'] . ' ' . $employe['nom']);
            $mail->isHTML(false);
            $mail->Subject = 'Confirmation de votre nouvelle adresse e-mail — TERRACOOP';
            $mail->Body    = "Bonjour " . $employe['prenom'] . " " . $employe['nom'] . ",\n\n"
                           . "Voici votre nouveau lien de confirmation d'adresse e-mail :\n"
                           . $verify_link . "\n\n"
                           . "Ce lien est valable 1 heure.\n\n"
                           . "— Service Informatique TERRACOOP";
            $mail->send();
        } catch (Exception $e) {
            error_log("[EMAIL VERIF RESEND] Échec vers " . $rv['new_email'] . " — " . $mail->ErrorInfo);
        }

        $email_message = "Lien renvoyé à <strong>" . htmlspecialchars($rv['new_email']) . "</strong>.";
        $email_type    = "success";
    }
}

// Vérification d'une demande de changement email en attente
$pending_verif = null;
$stmt_pv = $conn->prepare("SELECT new_email FROM email_verif_auto WHERE user_id = ? AND token_expiry > NOW()");
$stmt_pv->bind_param("i", $user_id);
$stmt_pv->execute();
$pending_verif = $stmt_pv->get_result()->fetch_assoc();
$stmt_pv->close();

$initiales = strtoupper(substr($employe['prenom'] ?? 'U', 0, 1) . substr($employe['nom'] ?? '', 0, 1));
$a2fa_actif = !empty($employe['two_fa_secret']);
?>
<?php include 'includes/header.php'; ?>

<style>
    .settings-wrap {
        max-width: 820px;
        margin: 32px auto;
        padding: 0 16px 48px;
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .settings-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        overflow: hidden;
    }

    .settings-card-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 16px 24px;
        border-bottom: 1px solid #f0f0f0;
    }

    .settings-card-header svg { color: #007bff; flex-shrink: 0; }

    .settings-card-header h2 {
        font-size: 0.97rem;
        font-weight: 700;
        color: #1a1a2e;
        margin: 0;
    }

    .settings-card-body { padding: 24px; }

    /* ── Profil ── */
    .profile-row {
        display: flex;
        align-items: center;
        gap: 20px;
    }

    .profile-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0054b3, #007bff);
        color: #fff;
        font-size: 1.4rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        letter-spacing: 0.04em;
    }

    .profile-info { flex: 1; }

    .profile-name {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 3px;
    }

    .profile-role {
        display: inline-block;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 2px 10px;
        border-radius: 20px;
        background: #e8f0fe;
        color: #0054b3;
    }

    .profile-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 14px;
        margin-top: 20px;
    }

    .profile-field label {
        display: block;
        font-size: 0.75rem;
        font-weight: 600;
        color: #888;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 4px;
    }

    .profile-field span {
        display: block;
        font-size: 0.93rem;
        color: #1a1a2e;
        font-weight: 500;
    }

    .profile-field span.empty { color: #bbb; font-style: italic; font-weight: 400; }

    /* ── Mot de passe ── */
    .field-group { margin-bottom: 16px; }

    .field-group label {
        display: block;
        font-size: 0.83rem;
        font-weight: 600;
        color: #495057;
        margin-bottom: 6px;
    }

    .input-wrap {
        position: relative;
    }

    .input-wrap input {
        width: 100%;
        padding: 10px 44px 10px 14px;
        border: 1.5px solid #dee2e6;
        border-radius: 7px;
        font-size: 0.93rem;
        font-family: inherit;
        background: #fff;
        box-sizing: border-box;
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .input-wrap input:focus {
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,0.12);
        outline: none;
    }

    .toggle-eye {
        position: absolute;
        right: 0;
        top: 0;
        bottom: 0;
        width: 42px;
        background: transparent;
        cursor: pointer;
        color: #c0c8d0;
        display: flex;
        align-items: center;
        justify-content: center;
        user-select: none;
        transition: color 0.15s;
    }

    .toggle-eye:hover { color: #007bff; }
    .toggle-eye .eye-off { display: none; }

    /* Indicateur de force */
    .strength-bar {
        display: flex;
        gap: 4px;
        margin-top: 7px;
    }

    .strength-bar span {
        flex: 1;
        height: 4px;
        border-radius: 2px;
        background: #e9ecef;
        transition: background 0.3s;
    }

    .strength-label {
        font-size: 0.75rem;
        color: #888;
        margin-top: 4px;
    }

    .pw-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0 20px;
    }

    .pw-form-grid .field-group:first-child {
        grid-column: 1 / -1;
    }

    .btn-save {
        padding: 10px 28px;
        background: #007bff;
        color: #fff;
        border: none;
        border-radius: 7px;
        font-size: 0.93rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.2s;
        margin-top: 4px;
    }

    .btn-save:hover { background: #0062cc; }

    /* ── Messages ── */
    .msg-box {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        padding: 11px 14px;
        border-radius: 7px;
        font-size: 0.875rem;
        margin-bottom: 18px;
        line-height: 1.5;
    }

    .msg-box.error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .msg-box.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }

    /* ── 2FA ── */
    .security-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 0;
        border-bottom: 1px solid #f5f5f5;
    }

    .security-row:last-child { border-bottom: none; padding-bottom: 0; }
    .security-row:first-child { padding-top: 0; }

    .security-info { display: flex; align-items: center; gap: 12px; }

    .security-icon {
        width: 38px;
        height: 38px;
        border-radius: 9px;
        background: #f0f4ff;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .security-icon svg { color: #007bff; }

    .security-label {
        font-size: 0.9rem;
        font-weight: 600;
        color: #1a1a2e;
    }

    .security-desc {
        font-size: 0.78rem;
        color: #888;
        margin-top: 1px;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.78rem;
        font-weight: 600;
    }

    .badge.on  { background: #d4edda; color: #155724; }
    .badge.off { background: #f8d7da; color: #721c24; }

    .badge-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
    }

    .badge.on  .badge-dot { background: #28a745; }
    .badge.off .badge-dot { background: #dc3545; }

    @media (max-width: 580px) {
        .profile-row { flex-direction: column; align-items: flex-start; }
        .pw-form-grid { grid-template-columns: 1fr; }
        .pw-form-grid .field-group:first-child { grid-column: 1; }
    }
</style>

<div class="settings-wrap">

    <!-- ── Profil ── -->
    <div class="settings-card">
        <div class="settings-card-header">
            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                <path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/>
            </svg>
            <h2>Mon profil</h2>
        </div>
        <div class="settings-card-body">
            <div class="profile-row">
                <div class="profile-avatar"><?php echo htmlspecialchars($initiales); ?></div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($employe['prenom'] . ' ' . $employe['nom']); ?></div>
                    <span class="profile-role"><?php echo htmlspecialchars($employe['role'] ?? 'Employé'); ?></span>
                </div>
            </div>
            <div class="profile-grid">
                <div class="profile-field">
                    <label>Matricule</label>
                    <span><?php echo htmlspecialchars($employe['matricule'] ?? '—'); ?></span>
                </div>
                <div class="profile-field">
                    <label>Adresse e-mail</label>
                    <span <?php echo empty($employe['email']) ? 'class="empty"' : ''; ?>>
                        <?php echo !empty($employe['email']) ? htmlspecialchars($employe['email']) : 'Non renseignée'; ?>
                    </span>
                </div>
                <div class="profile-field">
                    <label>Rôle</label>
                    <span><?php echo htmlspecialchars($employe['role'] ?? '—'); ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Mot de passe ── -->
    <div class="settings-card">
        <div class="settings-card-header">
            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
            </svg>
            <h2>Changer le mot de passe</h2>
        </div>
        <div class="settings-card-body">

            <?php if ($message): ?>
                <div class="msg-box <?php echo $message_type; ?>">
                    <?php if ($message_type === 'success'): ?>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" style="flex-shrink:0;margin-top:1px"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" style="flex-shrink:0;margin-top:1px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                <div class="pw-form-grid">
                    <div class="field-group">
                        <label for="current_password">Mot de passe actuel</label>
                        <div class="input-wrap">
                            <input type="password" id="current_password" name="current_password" required placeholder="••••••••">
                            <span class="toggle-eye" onclick="togglePw('current_password', this)" role="button" aria-label="Afficher/masquer">
                                <svg class="eye-on"  viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zm0 12.5c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75C21.27 7.61 17 4.5 12 4.5c-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zm7.53 5.53l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>
                            </span>
                        </div>
                    </div>
                    <div class="field-group">
                        <label for="new_password">Nouveau mot de passe</label>
                        <div class="input-wrap">
                            <input type="password" id="new_password" name="new_password" required placeholder="••••••••" oninput="checkStrength(this.value)">
                            <span class="toggle-eye" onclick="togglePw('new_password', this)" role="button" aria-label="Afficher/masquer">
                                <svg class="eye-on"  viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zm0 12.5c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75C21.27 7.61 17 4.5 12 4.5c-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zm7.53 5.53l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>
                            </span>
                        </div>
                        <div class="strength-bar" id="strength-bar">
                            <span id="s1"></span><span id="s2"></span><span id="s3"></span><span id="s4"></span>
                        </div>
                        <div class="strength-label" id="strength-label"></div>
                    </div>
                    <div class="field-group">
                        <label for="confirm_password">Confirmer le nouveau mot de passe</label>
                        <div class="input-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" required placeholder="••••••••">
                            <span class="toggle-eye" onclick="togglePw('confirm_password', this)" role="button" aria-label="Afficher/masquer">
                                <svg class="eye-on"  viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zm0 12.5c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                <svg class="eye-off" viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75C21.27 7.61 17 4.5 12 4.5c-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zm7.53 5.53l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>
                            </span>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn-save">Enregistrer le nouveau mot de passe</button>
            </form>
        </div>
    </div>

    <!-- ── Adresse e-mail ── -->
    <div class="settings-card">
        <div class="settings-card-header">
            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
            </svg>
            <h2>Adresse e-mail</h2>
        </div>
        <div class="settings-card-body">

            <?php if ($email_message): ?>
                <div class="msg-box <?php echo $email_type; ?>">
                    <?php if ($email_type === 'success'): ?>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" style="flex-shrink:0;margin-top:1px"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" style="flex-shrink:0;margin-top:1px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                    <?php endif; ?>
                    <span><?php echo $email_message; ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($employe['email'])): ?>
                <div style="margin-bottom:16px; padding:10px 14px; background:#f8f9fa; border-radius:7px; font-size:.88rem; color:#495057;">
                    Adresse actuelle : <strong><?php echo htmlspecialchars($employe['email']); ?></strong>
                </div>
            <?php endif; ?>

            <?php if ($pending_verif && $email_type !== 'error'): ?>
                <div style="margin-bottom:18px; padding:13px 15px; background:#fff8e1; border:1px solid #ffe082; border-radius:8px; font-size:.85rem; color:#795548;">
                    <div style="display:flex; align-items:center; gap:8px; margin-bottom:10px;">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" style="flex-shrink:0;color:#f59e0b"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        <span>Confirmation en attente pour <strong><?php echo htmlspecialchars($pending_verif['new_email']); ?></strong>. Vérifiez votre boîte mail.</span>
                    </div>
                    <div style="font-size:.8rem; color:#9e7c2e; margin-bottom:12px;">
                        Vous n'avez pas reçu l'e-mail ? Vérifiez vos spams, puis renvoyez le lien.
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="resend_email_verif">
                        <button type="submit" id="btnResend" class="btn-save" style="background:#f59e0b; font-size:.85rem; padding:8px 18px;" disabled>
                            <span id="resendLabel">Renvoyer le lien (<span id="countdown">30</span>s)</span>
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="action" value="change_email">
                <div class="field-group">
                    <label for="new_email"><?php echo empty($employe['email']) ? 'Ajouter une adresse e-mail' : 'Nouvelle adresse e-mail'; ?></label>
                    <div class="input-wrap">
                        <input type="email" id="new_email" name="new_email" placeholder="prenom.nom@exemple.com"
                               value="<?php echo htmlspecialchars($_POST['new_email'] ?? ''); ?>">
                    </div>
                </div>
                <button type="submit" class="btn-save">Envoyer le lien de confirmation</button>
            </form>

            <p style="margin-top:12px; font-size:.78rem; color:#888; line-height:1.5;">
                Un e-mail de confirmation sera envoyé à la nouvelle adresse. Le changement sera effectif uniquement après validation du lien reçu (valable 1 heure).
            </p>

            <?php if ($pending_verif): ?>
            <script>
            (function() {
                let s = 30;
                const btn   = document.getElementById('btnResend');
                const cd    = document.getElementById('countdown');
                const label = document.getElementById('resendLabel');
                const t = setInterval(() => {
                    s--;
                    if (s <= 0) {
                        clearInterval(t);
                        btn.disabled = false;
                        label.textContent = 'Renvoyer le lien';
                        btn.style.background = '#007bff';
                    } else {
                        cd.textContent = s;
                    }
                }, 1000);
            })();
            </script>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Sécurité ── -->
    <div class="settings-card">
        <div class="settings-card-header">
            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/>
            </svg>
            <h2>Sécurité du compte</h2>
        </div>
        <div class="settings-card-body">

            <div class="security-row">
                <div class="security-info">
                    <div class="security-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                            <path d="M17 1H7c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2zm-5 20c-.83 0-1.5-.67-1.5-1.5S11.17 18 12 18s1.5.67 1.5 1.5S12.83 21 12 21zm5-4H7V4h10v13z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="security-label">Double authentification (2FA)</div>
                        <div class="security-desc">Application TOTP (Google Authenticator, Authy…)</div>
                    </div>
                </div>
                <span class="badge <?php echo $a2fa_actif ? 'on' : 'off'; ?>">
                    <span class="badge-dot"></span>
                    <?php echo $a2fa_actif ? 'Activée' : 'Désactivée'; ?>
                </span>
            </div>

            <div class="security-row">
                <div class="security-info">
                    <div class="security-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                            <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="security-label">Adresse e-mail de récupération</div>
                        <div class="security-desc">Utilisée pour la réinitialisation du mot de passe</div>
                    </div>
                </div>
                <span class="badge <?php echo !empty($employe['email']) ? 'on' : 'off'; ?>">
                    <span class="badge-dot"></span>
                    <?php echo !empty($employe['email']) ? 'Configurée' : 'Non renseignée'; ?>
                </span>
            </div>

            <?php if (!$a2fa_actif): ?>
            <div style="margin-top:16px; padding:12px 14px; background:#fff8e1; border:1px solid #ffe082; border-radius:8px; font-size:0.82rem; color:#795548; display:flex; align-items:flex-start; gap:8px;">
                <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" style="flex-shrink:0;margin-top:1px;color:#f59e0b"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
                <span>La double authentification n'est pas activée. Contactez le <strong>service informatique</strong> pour la configurer.</span>
            </div>
            <?php endif; ?>
            <?php if (empty($employe['email'])): ?>
            <div style="margin-top:10px; padding:12px 14px; background:#fff8e1; border:1px solid #ffe082; border-radius:8px; font-size:0.82rem; color:#795548; display:flex; align-items:flex-start; gap:8px;">
                <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" style="flex-shrink:0;margin-top:1px;color:#f59e0b"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
                <span>Aucun e-mail renseigné — ajoutez-en un via la section ci-dessus. Si vous ne recevez pas le lien de confirmation, contactez le <strong>service informatique</strong>.</span>
            </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<script>
function togglePw(id, btn) {
    const input  = document.getElementById(id);
    const isText = input.type === 'text';
    input.type = isText ? 'password' : 'text';
    btn.querySelector('.eye-on').style.display  = isText ? '' : 'none';
    btn.querySelector('.eye-off').style.display = isText ? 'none' : '';
    btn.style.color = isText ? '' : '#007bff';
}

function checkStrength(val) {
    const bars   = ['s1','s2','s3','s4'];
    const label  = document.getElementById('strength-label');
    const colors = ['#dc3545','#fd7e14','#ffc107','#28a745'];
    const labels = ['Très faible','Faible','Moyen','Fort'];

    let score = 0;
    if (val.length >= 6)                        score++;
    if (val.length >= 10)                       score++;
    if (/[A-Z]/.test(val) && /[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val))              score++;

    bars.forEach((id, i) => {
        document.getElementById(id).style.background = i < score ? colors[score - 1] : '#e9ecef';
    });

    label.textContent  = val.length > 0 ? labels[score - 1] ?? '' : '';
    label.style.color  = score > 0 ? colors[score - 1] : '#888';
}
</script>

<?php include 'includes/footer.php'; ?>
