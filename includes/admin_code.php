<?php
/**
 * includes/admin_code.php — Vérification admin par code à usage unique envoyé par e-mail.
 * ---------------------------------------------------------------------------------------
 * Remplace l'ancien calcul "/25" partagé entre login.php (entrée admin ?admin=1) et
 * admin_2fa.php (déverrouillage 2FA, révocation). Le code n'est jamais affiché à l'écran :
 * il est envoyé par e-mail à ADMIN_CODE_EMAIL. Une fois validé, $_SESSION['admin_code_auth']
 * reste vrai pour toute la session — inutile de le ressaisir à chaque action admin.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('ADMIN_CODE_TTL', 300);          // validité du code : 5 minutes
define('ADMIN_CODE_MAX_TENTATIVES', 5); // tentatives avant verrouillage
define('ADMIN_CODE_LOCK_DUREE', 900);   // durée du verrouillage : 15 minutes

function admin_code_email() {
    return defined('BACKUP_ALERT_EMAIL') ? BACKUP_ALERT_EMAIL : 'service.informatique@terracoop.re';
}

function admin_code_verrouille() {
    return !empty($_SESSION['admin_code_lock_until']) && time() < $_SESSION['admin_code_lock_until'];
}

function admin_code_actif() {
    return !empty($_SESSION['admin_code_hash']) && time() < ($_SESSION['admin_code_expiry'] ?? 0);
}

function admin_code_envoyer() {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['admin_code_hash']       = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['admin_code_expiry']     = time() + ADMIN_CODE_TTL;
    $_SESSION['admin_code_tentatives'] = 0;

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
        $mail->setFrom(smtp_from, 'Accès Admin TERRACOOP');
        $mail->addAddress(admin_code_email());
        $mail->isHTML(false);
        $mail->Subject = "Code d'accès — Administration TERRACOOP";
        $mail->Body    = "Code d'accès (valable 5 minutes) : $code\n\n"
                        . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[ADMIN CODE] Échec envoi code — " . $mail->ErrorInfo);
        return false;
    }
}

// Retourne true si le code saisi est valide (et ouvre la session admin). false sinon
// (incorrect, expiré ou verrouillé) ; incrémente le compteur de tentatives.
function admin_code_verifier($saisi) {
    if (admin_code_verrouille()) return false;

    $expire = $_SESSION['admin_code_expiry'] ?? 0;
    $hash   = $_SESSION['admin_code_hash'] ?? '';

    if ($hash && time() < $expire && password_verify(trim((string)$saisi), $hash)) {
        $_SESSION['admin_code_auth']       = true;
        $_SESSION['admin_code_tentatives'] = 0;
        unset($_SESSION['admin_code_hash'], $_SESSION['admin_code_expiry']);
        return true;
    }

    $_SESSION['admin_code_tentatives'] = ($_SESSION['admin_code_tentatives'] ?? 0) + 1;
    if ($_SESSION['admin_code_tentatives'] >= ADMIN_CODE_MAX_TENTATIVES) {
        $_SESSION['admin_code_lock_until'] = time() + ADMIN_CODE_LOCK_DUREE;
    }
    return false;
}

function admin_code_deverrouiller() {
    unset(
        $_SESSION['admin_code_auth'],
        $_SESSION['admin_code_hash'],
        $_SESSION['admin_code_expiry'],
        $_SESSION['admin_code_tentatives'],
        $_SESSION['admin_code_lock_until']
    );
}
