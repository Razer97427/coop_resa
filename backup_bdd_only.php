<?php
/**
 * backup_bdd_only.php — Sauvegarde complète de la base de données (dump .sql)
 * ---------------------------------------------------------------------------
 * - Génère un fichier .sql téléchargeable : structure (CREATE TABLE) + données
 *   (INSERT) de TOUTES les tables de la base courante.
 * - 100 % PHP (aucune dépendance à mysqldump / exec).
 * - Protégé par un code à usage unique envoyé par e-mail (jamais affiché à
 *   l'écran), + verrouillage après plusieurs échecs.
 *
 * ⚠️ SÉCURITÉ : le dump contient des données sensibles (mots de passe hachés,
 *    jetons…). SUPPRIMEZ ou renommez ce fichier après usage, et ne le laissez
 *    pas accessible publiquement.
 */

require_once '/home/terracoonz/www/gestion-auto/config.php';   // $conn (mysqli), DB_NAME, session, csrf_verify()
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Tables utilisées par l'application gestion-auto (liste blanche) ──────────
// La base peut contenir des tables d'autres applis : on ne sauvegarde QUE celles-ci.
// Ajoutez/retirez une table ici si l'application évolue.
$APP_TABLES = [
    'employes',
    'reservations',
    'affectations_fixes',
    'vehicules',
    'pointages_kilometrage',
    'conges',
	'conges_alertes',
    'login_attempts',
    'sessions_auto',
    'init_pass_auto',
    'email_verif_auto',
    'services',
    'etablissements',
    'maintenance_site',
];

// ─────────────────────────── Authentification par code e-mail ──────────────
// Le code n'est JAMAIS affiché dans la page : il est envoyé par e-mail à
// l'adresse admin ci-dessous. Personne ne peut donc le lire depuis la source
// HTML, contrairement à l'ancien calcul affiché en clair.
define('BACKUP_CODE_TTL', 300);       // validité du code : 5 minutes
define('BACKUP_MAX_TENTATIVES', 5);   // tentatives avant verrouillage
define('BACKUP_LOCK_DUREE', 900);     // durée du verrouillage : 15 minutes

// Adresse de destination du code : définissez BACKUP_ALERT_EMAIL dans le
// config.php racine (hors dépôt) pour la personnaliser.
$backup_dest_email = defined('BACKUP_ALERT_EMAIL') ? BACKUP_ALERT_EMAIL : 'service.informatique@terracoop.re';

if (!isset($_SESSION['bkp_auth'])) $_SESSION['bkp_auth'] = false;

function bkp_nouveau_code($destinataire) {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['bkp_code_hash']   = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['bkp_code_expiry'] = time() + BACKUP_CODE_TTL;
    $_SESSION['bkp_tentatives']  = 0;

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
        $mail->setFrom(smtp_from, 'Sauvegarde BDD TERRACOOP');
        $mail->addAddress($destinataire);
        $mail->isHTML(false);
        $mail->Subject = "Code d'accès — Sauvegarde BDD gestion-auto";
        $mail->Body    = "Code d'accès (valable 5 minutes) : $code\n\n"
                        . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("[BACKUP BDD] Échec envoi code — " . $mail->ErrorInfo);
        return false;
    }
}

$auth_error  = '';
$verrouille  = !empty($_SESSION['bkp_lock_until']) && time() < $_SESSION['bkp_lock_until'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['envoyer_code']) && !$verrouille) {
    csrf_verify();
    if (!bkp_nouveau_code($backup_dest_email)) {
        $auth_error = "Échec de l'envoi de l'e-mail. Réessayez plus tard.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_submit']) && !$verrouille) {
    csrf_verify();
    $code_saisi = trim($_POST['reponse'] ?? '');
    $expire     = $_SESSION['bkp_code_expiry'] ?? 0;
    $hash       = $_SESSION['bkp_code_hash'] ?? '';

    if ($hash && time() < $expire && password_verify($code_saisi, $hash)) {
        $_SESSION['bkp_auth']      = true;
        $_SESSION['bkp_tentatives'] = 0;
        unset($_SESSION['bkp_code_hash'], $_SESSION['bkp_code_expiry']);
    } else {
        $_SESSION['bkp_tentatives'] = ($_SESSION['bkp_tentatives'] ?? 0) + 1;
        if ($_SESSION['bkp_tentatives'] >= BACKUP_MAX_TENTATIVES) {
            $_SESSION['bkp_lock_until'] = time() + BACKUP_LOCK_DUREE;
            $auth_error = 'Trop de tentatives. Réessayez dans 15 minutes.';
        } else {
            $auth_error = (!$hash || time() >= $expire)
                ? "Aucun code actif ou code expiré — demandez-en un nouveau."
                : 'Code incorrect, réessayez.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock'])) {
    $_SESSION['bkp_auth'] = false;
    unset($_SESSION['bkp_code_hash'], $_SESSION['bkp_code_expiry']);
}

$authed     = ($_SESSION['bkp_auth'] === true);
$verrouille = !empty($_SESSION['bkp_lock_until']) && time() < $_SESSION['bkp_lock_until'];
$code_actif = !empty($_SESSION['bkp_code_hash']) && time() < ($_SESSION['bkp_code_expiry'] ?? 0);

// ─────────────────────────── Génération du dump ────────────────────────────
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_backup'])) {
    csrf_verify();

    $conn->set_charset('utf8mb4');

    // On coupe toute mise en tampon / sortie déjà émise, puis on envoie le fichier
    while (ob_get_level() > 0) { ob_end_clean(); }

    $dbname   = defined('DB_NAME') ? DB_NAME : 'database';
    $filename = 'backup_' . $dbname . '_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    // On ne garde que les tables de l'application réellement présentes dans la base
    $existantes = [];
    $rt = $conn->query('SHOW TABLES');
    while ($row = $rt->fetch_row()) $existantes[$row[0]] = true;
    $tables     = array_values(array_filter($APP_TABLES, fn($t) => isset($existantes[$t])));
    $manquantes = array_values(array_filter($APP_TABLES, fn($t) => !isset($existantes[$t])));

    echo "-- ============================================================\n";
    echo "-- Sauvegarde des tables de l'application gestion-auto\n";
    echo "-- Base : `$dbname`\n";
    echo "-- Générée le " . date('Y-m-d H:i:s') . " par backup_bdd_only.php\n";
    echo "-- Tables sauvegardées : " . count($tables) . " (" . implode(', ', $tables) . ")\n";
    if ($manquantes) echo "-- Tables absentes (ignorées) : " . implode(', ', $manquantes) . "\n";
    echo "-- ============================================================\n\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $t) {
        $esc = str_replace('`', '``', $t);

        echo "-- ------------------------------------------------------------\n";
        echo "-- Structure : `$esc`\n";
        echo "-- ------------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `$esc`;\n";

        $cr = $conn->query("SHOW CREATE TABLE `$esc`");
        if ($cr) {
            $rowcr = $cr->fetch_row();
            echo $rowcr[1] . ";\n\n";
        }

        // Données
        $res = $conn->query("SELECT * FROM `$esc`");
        if ($res && $res->num_rows > 0) {
            echo "-- Données : `$esc` ($res->num_rows ligne(s))\n";
            $nb = 0;
            while ($data = $res->fetch_assoc()) {
                $cols = array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($data));
                $vals = array_map(function ($v) use ($conn) {
                    if ($v === null) return 'NULL';
                    return "'" . $conn->real_escape_string($v) . "'";
                }, array_values($data));
                echo "INSERT INTO `$esc` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
                if ((++$nb % 200) === 0) { flush(); }   // vide le tampon régulièrement
            }
            echo "\n";
        }
        flush();
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    echo "-- Fin de la sauvegarde (" . count($tables) . " table(s)).\n";
    exit();
}

// ─────────────────────────── Infos d'affichage ─────────────────────────────
$nb_tables = 0; $nb_manquantes = 0;
if ($authed) {
    $set = [];
    $r = $conn->query('SHOW TABLES');
    if ($r) while ($row = $r->fetch_row()) $set[$row[0]] = true;
    foreach ($APP_TABLES as $t) { isset($set[$t]) ? $nb_tables++ : $nb_manquantes++; }
}
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sauvegarde BDD</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f4f6f9; color: #263238; margin: 0; padding: 24px; }
    .wrap { max-width: 640px; margin: auto; }
    h1 { font-size: 1.35rem; margin: 0 0 4px; }
    .sub { color: #6c757d; font-size: .9rem; margin: 0 0 20px; }
    .card { background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 22px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
    .warn { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; border-radius: 8px; padding: 12px 16px; font-size: .88rem; margin-bottom: 18px; }
    .err { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; border-radius: 8px; padding: 10px 14px; font-size: .9rem; margin-bottom: 12px; }
    label { font-weight: 600; font-size: .85rem; display: block; margin-bottom: 6px; }
    input[type=number] { padding: 8px 10px; border: 1.5px solid #dee2e6; border-radius: 6px; font-size: .95rem; }
    button { background: #0d3b8c; color: #fff; border: none; border-radius: 6px; padding: 11px 22px; font-weight: 600; cursor: pointer; font-size: .95rem; }
    button.grey { background: #6c757d; padding: 8px 16px; font-size: .85rem; }
    .stat { display: inline-block; background: #eef2ff; color: #0d3b8c; border-radius: 8px; padding: 8px 16px; font-weight: 600; }
</style>
</head>
<body>
<div class="wrap">
    <h1>💾 Sauvegarde des tables gestion-auto</h1>
    <p class="sub">Génère un fichier <code>.sql</code> (structure + données) des <strong>tables de l'application uniquement</strong>, prêt à réimporter dans phpMyAdmin.</p>

    <div class="warn">⚠️ Le fichier contient des <strong>données sensibles</strong> (mots de passe hachés, jetons). <strong>Supprimez ou renommez ce script après usage</strong> et ne le laissez pas accessible publiquement.</div>

<?php if (!$authed): ?>
    <div class="card" style="max-width:420px;">
        <h2 style="margin-top:0;font-size:1.05rem;">Vérification d'accès</h2>
        <?php if ($auth_error): ?><div class="err"><?php echo htmlspecialchars($auth_error); ?></div><?php endif; ?>

        <?php if ($verrouille): ?>
            <p class="sub" style="margin:0;">🔒 Accès verrouillé suite à plusieurs échecs. Réessayez dans quelques minutes.</p>
        <?php elseif (!$code_actif): ?>
            <p class="sub" style="margin:0 0 14px;">Un code d'accès à usage unique doit être envoyé par e-mail avant de continuer.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="envoyer_code" value="1">
                <button type="submit">📧 Envoyer le code par e-mail</button>
            </form>
        <?php else: ?>
            <p class="sub" style="margin:0 0 14px;">Un code a été envoyé par e-mail. Entrez-le ci-dessous (valable 5 minutes).</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="auth_submit" value="1">
                <label>Code reçu par e-mail</label>
                <div style="display:flex;gap:8px;">
                    <input type="text" name="reponse" required autofocus maxlength="6" inputmode="numeric" pattern="[0-9]{6}" style="flex:1;">
                    <button type="submit">Valider</button>
                </div>
            </form>
            <form method="POST" style="margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="envoyer_code" value="1">
                <button type="submit" class="grey">🔄 Renvoyer un code</button>
            </form>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1.05rem;">Générer la sauvegarde</h2>
        <p style="margin:0 0 8px;">Base : <span class="stat"><?php echo htmlspecialchars(defined('DB_NAME') ? DB_NAME : '—'); ?></span>
           &nbsp; Tables de l'app : <span class="stat"><?php echo (int)$nb_tables; ?></span></p>
        <?php if ($nb_manquantes > 0): ?>
            <p class="sub" style="margin:0 0 16px;color:#856404;">⚠️ <?php echo (int)$nb_manquantes; ?> table(s) de la liste absente(s) de la base (ignorée(s)).</p>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="do_backup" value="1">
            <button type="submit">⬇️ Télécharger le dump .sql complet</button>
        </form>
        <p class="sub" style="margin:14px 0 0;">Le téléchargement démarre immédiatement (nom : <code>backup_<?php echo htmlspecialchars(defined('DB_NAME') ? DB_NAME : 'base'); ?>_AAAAMMJJ_HHMMSS.sql</code>).</p>
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <button type="submit" name="lock" value="1" class="grey">🔒 Verrouiller l'accès</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
