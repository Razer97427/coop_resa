<?php
/**
 * premiere_connexion.php — Configuration autonome du 2FA lors de la toute
 * première connexion (quand employes.two_fa_secret est vide).
 * ---------------------------------------------------------------------------
 * Accès : uniquement via login.php, qui pose $_SESSION['2fa_setup_pending_user_id']
 * après avoir vérifié matricule + mot de passe. Jamais d'accès direct sans être
 * passé par cette vérification.
 */
require_once 'config.php';
require_once 'includes/premiere_connexion_code.php';
require_once 'GoogleAuthenticator.php';

if (empty($_SESSION['2fa_setup_pending_user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['2fa_setup_pending_user_id'];

// On ne fait jamais confiance à la seule session : on revérifie l'employé en base à chaque chargement.
$stmt = $conn->prepare("SELECT id_employe, matricule, nom, prenom, email, two_fa_secret FROM employes WHERE id_employe = ? AND actif = 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$employe = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$employe) {
    // Compte désactivé/supprimé entre-temps
    p2fa_reset();
    unset($_SESSION['2fa_setup_pending_user_id']);
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

// ── Garde-fou : 2FA déjà configuré (par l'IT entre-temps, ou accès direct à l'URL) ──
// Aucune modification possible depuis cette page dans ce cas.
$deja_configure = !empty($employe['two_fa_secret']);

$erreur       = '';
$succes_final = false;

if (!$deja_configure) {

    // Étape 1 : demande d'envoi du code
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['envoyer_code'])) {
        if (empty($employe['email'])) {
            $erreur = "Aucune adresse e-mail n'est enregistrée pour votre compte. Contactez le service informatique pour la faire ajouter.";
        } elseif (!p2fa_code_envoyer($employe['email'], $employe['prenom'])) {
            $erreur = "Échec de l'envoi de l'e-mail. Réessayez plus tard.";
        }
    }

    // Étape 2 : vérification du code reçu par e-mail
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verifier_code'])) {
        if (!p2fa_code_verifier($_POST['code_email'] ?? '')) {
            $erreur = p2fa_code_verrouille()
                ? 'Trop de tentatives. Réessayez dans 15 minutes.'
                : 'Code incorrect ou expiré.';
        }
    }

    // Étape 3 : activation définitive après scan du QR Code
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['activer_2fa']) && !empty($_SESSION['2fa_setup_code_verified'])) {
        $secret_hidden = $_POST['secret_hidden'] ?? '';
        $otp           = trim($_POST['otp'] ?? '');
        $ga            = new PHPGangsta_GoogleAuthenticator();

        if ($secret_hidden !== '' && $ga->verifyCode($secret_hidden, $otp, 2)) {
            $stmt_up = $conn->prepare("UPDATE employes SET two_fa_secret = ? WHERE id_employe = ?");
            $stmt_up->bind_param("si", $secret_hidden, $user_id);
            $stmt_up->execute();
            $stmt_up->close();

            p2fa_reset();
            unset($_SESSION['2fa_setup_pending_user_id']);
            $succes_final = true;
        } else {
            $erreur = "Code invalide. Vérifiez l'heure de votre téléphone et réessayez.";
        }
    }
}

// Secret temporaire affiché tant que l'activation n'est pas confirmée (porté par le formulaire, pas la session)
$secret_temp = '';
$qr_src      = '';
$code_verifie = !empty($_SESSION['2fa_setup_code_verified']);
if (!$deja_configure && !$succes_final && $code_verifie) {
    $secret_temp = $_POST['secret_hidden'] ?? '';
    if ($secret_temp === '') {
        $ga2 = new PHPGangsta_GoogleAuthenticator();
        $secret_temp = $ga2->createSecret();
    }
    $qr_title = 'TERRACOOP (' . $employe['matricule'] . ')';
    $ga3      = new PHPGangsta_GoogleAuthenticator();
    $qr_url   = $ga3->getQRCodeGoogleUrl($qr_title, $secret_temp, $employe['matricule']);

    // Génération côté serveur → data URI : évite le blocage CSP/COEP du navigateur
    // sur l'image externe api.qrserver.com (même technique que admin_2fa.php).
    $img_data = false;
    if (ini_get('allow_url_fopen')) {
        $ctx      = stream_context_create(['http' => ['timeout' => 6]]);
        $img_data = @file_get_contents($qr_url, false, $ctx);
    }
    if ($img_data === false && function_exists('curl_init')) {
        $ch = curl_init($qr_url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) $img_data = $resp;
    }
    if ($img_data !== false) {
        $qr_src = 'data:image/png;base64,' . base64_encode($img_data);
    }
}

$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Première connexion — TERRACOOP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0054b3 0%, #007bff 55%, #3395ff 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.22);
            width: 100%;
            max-width: 460px;
            overflow: hidden;
        }

        .login-card-header {
            background: linear-gradient(135deg, #0054b3, #007bff);
            padding: 26px 32px 22px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .login-logo {
            width: 46px; height: 46px;
            background: rgba(255,255,255,0.18);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .login-logo svg { width: 26px; height: 26px; fill: #fff; }

        .login-brand-name { color: #fff; font-size: 1.1rem; font-weight: 800; letter-spacing: 0.06em; line-height: 1.1; }
        .login-brand-sub  { color: rgba(255,255,255,0.75); font-size: 0.74rem; margin-top: 3px; }

        .login-card-body { padding: 26px 32px 30px; }

        .step-badges { display: flex; gap: 6px; margin-bottom: 18px; }
        .step-badge {
            flex: 1; height: 5px; border-radius: 3px; background: #e9ecef;
        }
        .step-badge.done   { background: #28a745; }
        .step-badge.active { background: #007bff; }

        .intro { font-size: 0.85rem; color: #495057; margin-bottom: 18px; line-height: 1.55; }
        .intro strong { color: #0054b3; }

        .msg-error, .msg-success, .msg-info {
            border-radius: 7px; padding: 11px 14px; font-size: 0.875rem;
            margin-bottom: 18px; line-height: 1.5;
        }
        .msg-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .msg-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-info    { background: #e7f3ff; color: #0d3b8c; border: 1px solid #b6d4fe; }

        .field-group { margin-bottom: 16px; }
        .field-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #495057; margin-bottom: 6px; }
        .field-group input {
            width: 100%; padding: 10px 14px; border: 1.5px solid #dee2e6; border-radius: 7px;
            font-size: 0.95rem; font-family: inherit; background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .field-group input:focus { border-color: #007bff; box-shadow: 0 0 0 3px rgba(0,123,255,0.12); outline: none; }

        .btn-submit {
            width: 100%; padding: 11px; background: #007bff; color: #fff; border: none;
            border-radius: 7px; font-size: 0.95rem; font-weight: 600; cursor: pointer;
            margin-top: 4px; transition: background 0.2s, transform 0.1s;
        }
        .btn-submit:hover  { background: #0062cc; }
        .btn-submit:active { transform: scale(0.99); }
        .btn-grey {
            width: 100%; padding: 9px; background: #6c757d; color: #fff; border: none;
            border-radius: 7px; font-size: 0.85rem; cursor: pointer; margin-top: 10px;
        }

        .qr-box { text-align: center; margin-bottom: 16px; }
        .qr-box img { border-radius: 8px; border: 4px solid #f8f9fa; box-shadow: 0 2px 12px rgba(0,0,0,0.1); }
        .manual-key-block {
            background: #f8f9fa; border: 1.5px dashed #dee2e6; border-radius: 8px;
            padding: 10px 16px; margin-bottom: 20px; text-align: center;
        }
        .manual-key-label { font-size: 0.72rem; color: #888; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
        .manual-key-value { font-family: 'Courier New', monospace; font-size: 0.95rem; font-weight: 700; color: #0054b3; letter-spacing: 0.06em; }

        .back-link { display: block; text-align: center; margin-top: 16px; font-size: 0.83rem; color: #6c757d; text-decoration: none; }
        .back-link:hover { color: #007bff; }

        .login-footer { text-align: center; margin-top: 18px; font-size: 0.78rem; color: rgba(255,255,255,0.65); }
    </style>
</head>
<body>

<div class="login-card">

    <div class="login-card-header">
        <div class="login-logo">
            <svg viewBox="0 0 24 24">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4.18l6 2.67V11c0 3.82-2.56 7.4-6 8.54-3.44-1.14-6-4.72-6-8.54V7.85l6-2.67z"/>
            </svg>
        </div>
        <div>
            <div class="login-brand-name">TERRACOOP</div>
            <div class="login-brand-sub">Configuration de votre double authentification</div>
        </div>
    </div>

    <div class="login-card-body">

        <?php if ($deja_configure): ?>

            <div class="msg-info">
                <strong>Votre 2FA est déjà configuré.</strong><br>
                Si vous avez perdu l'accès à votre téléphone ou à votre application d'authentification,
                cette page ne peut pas le réinitialiser à votre place — contactez le <strong>service informatique</strong>
                pour qu'il réinitialise votre double authentification.
            </div>
            <a href="login.php" class="back-link">← Retour à la connexion</a>

        <?php elseif ($succes_final): ?>

            <div class="msg-success">
                ✅ <strong>Double authentification activée avec succès.</strong><br>
                Vous pouvez maintenant vous connecter normalement avec votre matricule, votre mot de passe,
                puis le code généré par votre application.
            </div>
            <a href="login.php" class="btn-submit" style="display:block; text-align:center; text-decoration:none; line-height:1.6;">Se connecter</a>

        <?php else: ?>

            <p class="intro">
                Bonjour <strong><?php echo htmlspecialchars($employe['prenom']); ?></strong>, votre compte n'a pas encore de double authentification (2FA) configurée.
                C'est obligatoire pour vous connecter. Suivez les 3 étapes ci-dessous pour l'activer vous-même, sans passer par le service informatique.
            </p>

            <div class="step-badges">
                <div class="step-badge <?php echo ($code_verifie || p2fa_code_actif()) ? 'done' : 'active'; ?>"></div>
                <div class="step-badge <?php echo $code_verifie ? 'done' : (p2fa_code_actif() ? 'active' : ''); ?>"></div>
                <div class="step-badge <?php echo $code_verifie ? 'active' : ''; ?>"></div>
            </div>

            <?php if ($erreur): ?>
                <div class="msg-error"><?php echo htmlspecialchars($erreur); ?></div>
            <?php endif; ?>

            <?php if ($code_verifie): ?>

                <!-- Étape 3 : scan du QR Code + confirmation -->
                <div class="msg-success" style="margin-bottom:16px;">✓ Code e-mail vérifié. Dernière étape ci-dessous.</div>
                <p class="intro">Scannez ce QR Code avec <strong>Google Authenticator</strong>, <strong>Authy</strong> ou toute application TOTP compatible, puis entrez le code à 6 chiffres qu'elle affiche.</p>

                <div class="qr-box">
                    <?php if ($qr_src): ?>
                        <img src="<?php echo htmlspecialchars($qr_src); ?>" alt="QR Code 2FA" width="180" height="180">
                    <?php else: ?>
                        <p class="msg-error">Impossible de générer le QR code. Utilisez la clé manuelle ci-dessous pour l'ajouter dans votre application.</p>
                    <?php endif; ?>
                </div>
                <div class="manual-key-block">
                    <div class="manual-key-label">Clé manuelle (si QR Code illisible)</div>
                    <div class="manual-key-value"><?php echo htmlspecialchars($secret_temp); ?></div>
                </div>

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="activer_2fa" value="1">
                    <input type="hidden" name="secret_hidden" value="<?php echo htmlspecialchars($secret_temp); ?>">
                    <div class="field-group">
                        <label for="otp">Code à 6 chiffres de votre application</label>
                        <input type="text" id="otp" name="otp" required autofocus maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" style="text-align:center; letter-spacing:0.3em; font-size:1.1rem;">
                    </div>
                    <button type="submit" class="btn-submit">Activer la 2FA</button>
                </form>

            <?php elseif (p2fa_code_verrouille()): ?>

                <p class="intro" style="text-align:center;">🔒 Accès verrouillé suite à plusieurs échecs. Réessayez dans quelques minutes, ou contactez le service informatique si le problème persiste.</p>

            <?php elseif (p2fa_code_actif()): ?>

                <!-- Étape 2 : saisie du code reçu par e-mail -->
                <div class="msg-info">Un code a été envoyé à votre adresse e-mail (<?php echo htmlspecialchars($employe['email']); ?>). Entrez-le ci-dessous (valable 5 minutes).</div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="verifier_code" value="1">
                    <div class="field-group">
                        <label for="code_email">Code reçu par e-mail</label>
                        <input type="text" id="code_email" name="code_email" required autofocus maxlength="6" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" style="text-align:center; letter-spacing:0.3em; font-size:1.1rem;">
                    </div>
                    <button type="submit" class="btn-submit">Valider le code</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="envoyer_code" value="1">
                    <button type="submit" class="btn-grey">🔄 Renvoyer un code</button>
                </form>

            <?php else: ?>

                <!-- Étape 1 : envoi du code -->
                <p class="intro">Étape 1 sur 3 : nous allons vérifier que vous avez bien accès à votre boîte e-mail professionnelle avant de configurer votre 2FA.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="envoyer_code" value="1">
                    <button type="submit" class="btn-submit">📧 Envoyer le code par e-mail</button>
                </form>

            <?php endif; ?>

            <a href="login.php" class="back-link">← Annuler et revenir à la connexion</a>

        <?php endif; ?>

    </div>
</div>

<p class="login-footer">Accès réservé au personnel TERRACOOP</p>

</body>
</html>
