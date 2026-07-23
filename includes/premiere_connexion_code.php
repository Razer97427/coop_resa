<?php
/**
 * includes/premiere_connexion_code.php — Code à usage unique par e-mail pour
 * la configuration initiale du 2FA (premiere_connexion.php).
 * ---------------------------------------------------------------------------
 * Même principe que includes/admin_code.php : code jamais affiché à l'écran,
 * envoyé par e-mail, hashé en session, expiration + verrouillage anti brute-force.
 * Séparé de admin_code.php car lié à un employé précis (pas à l'accès admin).
 */
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

define('P2FA_CODE_TTL', 300);          // validité du code : 5 minutes
define('P2FA_CODE_MAX_TENTATIVES', 5); // tentatives avant verrouillage
define('P2FA_CODE_LOCK_DUREE', 900);   // durée du verrouillage : 15 minutes

function p2fa_code_verrouille() {
    return !empty($_SESSION['2fa_setup_lock_until']) && time() < $_SESSION['2fa_setup_lock_until'];
}

function p2fa_code_actif() {
    return !empty($_SESSION['2fa_setup_code_hash']) && time() < ($_SESSION['2fa_setup_code_expiry'] ?? 0);
}

function p2fa_code_envoyer($destinataire, $prenom) {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['2fa_setup_code_hash']       = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['2fa_setup_code_expiry']     = time() + P2FA_CODE_TTL;
    $_SESSION['2fa_setup_tentatives']      = 0;

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
        $mail->addAddress($destinataire, $prenom);
        $mail->isHTML(false);
        $mail->Subject = "Code de vérification — Configuration 2FA TERRACOOP";
        $mail->Body    = "Bonjour $prenom,\n\n"
                        . "Code de vérification (valable 5 minutes) : $code\n\n"
                        . "Ce code vous permet de configurer la double authentification (2FA) de votre compte.\n"
                        . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.\n\n"
                        . "— Service Informatique TERRACOOP";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[PREMIERE CONNEXION] Échec envoi code — " . $mail->ErrorInfo);
        return false;
    }
}

// Retourne true si le code saisi est valide ; marque la vérification en session.
function p2fa_code_verifier($saisi) {
    if (p2fa_code_verrouille()) return false;

    $expire = $_SESSION['2fa_setup_code_expiry'] ?? 0;
    $hash   = $_SESSION['2fa_setup_code_hash'] ?? '';

    if ($hash && time() < $expire && password_verify(trim((string)$saisi), $hash)) {
        $_SESSION['2fa_setup_code_verified'] = true;
        $_SESSION['2fa_setup_tentatives']    = 0;
        unset($_SESSION['2fa_setup_code_hash'], $_SESSION['2fa_setup_code_expiry']);
        return true;
    }

    $_SESSION['2fa_setup_tentatives'] = ($_SESSION['2fa_setup_tentatives'] ?? 0) + 1;
    if ($_SESSION['2fa_setup_tentatives'] >= P2FA_CODE_MAX_TENTATIVES) {
        $_SESSION['2fa_setup_lock_until'] = time() + P2FA_CODE_LOCK_DUREE;
    }
    return false;
}

// Nettoie tout l'état de la configuration en cours (succès, annulation, compte introuvable…)
function p2fa_reset() {
    unset(
        $_SESSION['2fa_setup_code_hash'],
        $_SESSION['2fa_setup_code_expiry'],
        $_SESSION['2fa_setup_tentatives'],
        $_SESSION['2fa_setup_lock_until'],
        $_SESSION['2fa_setup_code_verified']
    );
}
