<?php
// config.php - Paramètres de connexion et démarrage de session
require __DIR__ . '/../config.php';
date_default_timezone_set('Indian/Reunion');
//define('ALERTE_TOKEN', 'terracoop97425!');
define('BACKUP_ALERT_EMAIL', 'service.informatique@terracoop.re');

// ── Activation des notifications email (repli si non défini dans le config racine) ──
// Permet d'activer/couper l'envoi par module. Protégé pour ne jamais entrer en conflit
// avec une définition déjà présente dans le config racine (config.php / maitre_config.php).
if (!defined('EMAIL_ENABLED')) {
    define('EMAIL_ENABLED', true);   // Interrupteur GÉNÉRAL : false coupe TOUS les emails
}
if (!isset($GLOBALS['EMAIL_MODULES'])) {
    $GLOBALS['EMAIL_MODULES'] = [
        'nouvelle_demande'  => true,  // index.php               -> notif aux managers (nouvelle demande)
        'confirmation_resa' => true,  // manager.php             -> confirmation au demandeur
        'refus_resa'        => true,  // manager.php             -> refus au demandeur
        'superviseur'       => true,  // manager.php             -> info au superviseur du demandeur
        'alerte_revision'   => true,  // api_alerte_revision.php -> alertes révision / CT
        'alerte_km'         => true,  // alerte_kilometrage.php  -> rappel de pointage kilométrique
        'reset_password'    => true,  // forgot.php              -> lien de réinitialisation
        'change_email'      => true,  // update_password.php     -> vérification d'une nouvelle adresse email
    ];
}
if (!function_exists('email_actif')) {
    /**
     * Indique si l'envoi d'email d'un module donné est actif.
     * Renvoie true pour un module inconnu (on n'empêche pas un envoi non répertorié).
     */
    function email_actif($module) {
        if (!EMAIL_ENABLED) return false;
        $mods = $GLOBALS['EMAIL_MODULES'] ?? [];
        return !array_key_exists($module, $mods) || $mods[$module] === true;
    }
}

// ── En-têtes de sécurité HTTP ──────────────────────────────────────────────
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Embedder-Policy: require-corp');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://api.qrserver.com; font-src 'self'; connect-src 'self'; frame-ancestors 'none'");

// ── Session sécurisée ───────────────────────────────────────────────────────
if (session_status() == PHP_SESSION_NONE) {
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_verify() {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        die('Requête invalide (token CSRF incorrect).');
    }
}

// Connexion à la base de données MySQL
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

// Vérifier la connexion
if ($conn->connect_error) {
    die("Erreur de connexion à la base de données: " . $conn->connect_error);
}

// Définir l'encodage
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+04:00'");

$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
    ip VARCHAR(45) NOT NULL,
    fails INT NOT NULL DEFAULT 0,
    locked_until DATETIME DEFAULT NULL,
    PRIMARY KEY (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS sessions_auto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    ip VARCHAR(45),
    user_agent TEXT,
    login_time DATETIME NOT NULL,
    last_activity DATETIME NOT NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_token (session_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS pointages_kilometrage (
    id_pointage   INT AUTO_INCREMENT PRIMARY KEY,
    id_vehicule   INT NOT NULL,
    id_employe    INT NOT NULL,
    kilometrage_reel INT UNSIGNED NOT NULL,
    date_pointage DATE NOT NULL,
    mois          TINYINT UNSIGNED NOT NULL,
    annee         SMALLINT UNSIGNED NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_vehicule_mois (id_vehicule, mois, annee),
    INDEX idx_vehicule (id_vehicule),
    INDEX idx_employe  (id_employe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Vérifier que le token de session est toujours valide (non révoqué)
if (isset($_SESSION['user_id'], $_SESSION['session_token'])) {
    $tk  = $_SESSION['session_token'];
    $uid = (int)$_SESSION['user_id'];

    // SELECT pour détecter une révocation (affected_rows=0 sur UPDATE est trompeur si la valeur ne change pas)
    $chk = $conn->prepare("SELECT id FROM sessions_auto WHERE session_token = ? AND user_id = ?");
    if ($chk) {
        $chk->bind_param("si", $tk, $uid);
        $chk->execute();
        $chk->store_result();
        $exists = $chk->num_rows > 0;
        $chk->close();

        if (!$exists) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            header('Location: login.php?session_expired=1');
            exit;
        }

        // Mise à jour de last_activity (fire and forget)
        $upd = $conn->prepare("UPDATE sessions_auto SET last_activity = NOW() WHERE session_token = ?");
        if ($upd) {
            $upd->bind_param("s", $tk);
            $upd->execute();
            $upd->close();
        }
    }
}

// ── Rôle + établissement de l'utilisateur (contrôle d'accès par société) ────
// Accès complet = manager Terracoop (établissement 1). Autres sociétés / NULL = accès restreint.
$IS_MANAGER           = (($_SESSION['user_role'] ?? '') === 'Manager');
$USER_ETAB_ID         = null;
if (!empty($_SESSION['user_id'])) {
    $st_etab = $conn->prepare("SELECT id_etablissement FROM employes WHERE id_employe = ?");
    if ($st_etab) {
        $st_etab->bind_param("i", $_SESSION['user_id']);
        $st_etab->execute();
        $row_etab = $st_etab->get_result()->fetch_assoc();
        $st_etab->close();
        if ($row_etab && $row_etab['id_etablissement'] !== null) $USER_ETAB_ID = (int)$row_etab['id_etablissement'];
    }
}
$IS_TERRACOOP_MANAGER = ($IS_MANAGER && $USER_ETAB_ID === 1);
$IS_SOCIETE_MANAGER   = ($IS_MANAGER && !$IS_TERRACOOP_MANAGER); // manager d'une autre société (ou sans établissement)

// ── Établissements gérés par ce manager (le sien + ceux délégués, ex. RFL → Vivea) ──
// Défensif : si la colonne id_etablissement_gestion n'existe pas encore, on garde juste l'établissement propre.
$USER_ETAB_GERES = ($USER_ETAB_ID !== null) ? [$USER_ETAB_ID] : [];
if ($USER_ETAB_ID !== null) {
    try {
        if ($stg = $conn->prepare("SELECT id_etablissement FROM etablissements WHERE id_etablissement_gestion = ?")) {
            $stg->bind_param("i", $USER_ETAB_ID);
            if ($stg->execute()) {
                $rg = $stg->get_result();
                while ($row = $rg->fetch_assoc()) $USER_ETAB_GERES[] = (int)$row['id_etablissement'];
            }
            $stg->close();
        }
    } catch (\Throwable $e) { /* colonne pas encore créée */ }
}
?>