<?php
require_once 'config.php';
// POUR LA PARTIE ADMIN IL FAUT DIVISER LE CODE PAR 25 POUR OBTENIR LE MOT DE PASSE ATTENDU (ex: code=1234 → mot de passe attendu=49)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error_message = '';
$logout_message   = isset($_GET['logout']) && $_GET['logout'] == '1';
$session_expired  = isset($_GET['session_expired']) && $_GET['session_expired'] == '1';

// Pages autorisées comme cible de redirection post-login
$redirect_whitelist = ['index.php', 'manager.php', 'pointage_kilometrage.php', 'planning.php', 'settings.php'];
if (isset($_GET['redirect']) && in_array($_GET['redirect'], $redirect_whitelist, true)) {
    $_SESSION['redirect_after_login'] = $_GET['redirect'];
}

// Déjà connecté → rediriger directement sans repasser par le login
if (isset($_SESSION['user_id']) && !$logout_message && !$session_expired) {
    $cible = $_SESSION['redirect_after_login'] ?? null;
    if (!empty($cible) && in_array($cible, $redirect_whitelist, true)) {
        unset($_SESSION['redirect_after_login']);
        header('Location: ' . $cible);
    } else {
        header('Location: ' . (($_SESSION['user_role'] ?? '') === 'Manager' ? 'manager.php' : 'index.php'));
    }
    exit();
}

// ── Connexion administrateur 2FA ───────────────────────────────────────────
// Génère un code à 4 chiffres stocké en session ; le mot de passe attendu = floor(code / 25)
if (isset($_GET['admin']) && empty($_SESSION['admin_challenge'])) {
    $_SESSION['admin_challenge'] = random_int(1000, 9999);
}

$admin_error = '';
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['admin_login'])) {
    csrf_verify();
    $challenge  = (int)($_SESSION['admin_challenge'] ?? 0);
    $expected   = (int)floor($challenge / 25);
    $admin_pass = (int)($_POST['admin_password'] ?? -1);
    // Régénère le code après chaque tentative (valide ou non)
    $_SESSION['admin_challenge'] = random_int(1000, 9999);
    if ($challenge > 0 && $admin_pass === $expected) {
        session_regenerate_id(true);
        $_SESSION['admin_2fa_access'] = true;
        header('Location: admin_2fa.php');
        exit();
    }
    $admin_error = "Code incorrect.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['admin_login'])) {
    csrf_verify();
    $matricule       = trim($_POST['matricule'] ?? '');
    $password_saisi  = $_POST['password'] ?? '';

    if (empty($matricule) || empty($password_saisi)) {
        $error_message = "Tous les champs sont obligatoires.";
    } else {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1')[0]);

        $rl = $conn->prepare("SELECT fails, locked_until FROM login_attempts WHERE ip = ?");
        $rl->bind_param("s", $ip);
        $rl->execute();
        $rl_row = $rl->get_result()->fetch_assoc();
        $rl->close();

        if ($rl_row && $rl_row['locked_until'] && strtotime($rl_row['locked_until']) > time()) {
            $mins = max(1, (int)ceil((strtotime($rl_row['locked_until']) - time()) / 60));
            $error_message = "Trop de tentatives. Réessayez dans $mins minute(s).";
        } else {
            $stmt = $conn->prepare("SELECT id_employe, nom, prenom, role, mot_de_passe, two_fa_secret FROM employes WHERE matricule = ? AND actif = TRUE");

            if ($stmt) {
                $stmt->bind_param("s", $matricule);
                $stmt->execute();
                $result = $stmt->get_result();
                $user   = $result->fetch_assoc();
                $stmt->close();

                if ($user && $user['mot_de_passe'] == $password_saisi) {
                    $del_rl = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
                    $del_rl->bind_param("s", $ip);
                    $del_rl->execute();
                    $del_rl->close();

                    if (!empty($user['two_fa_secret'])) {
                        $_SESSION['2fa_pending_user_id'] = $user['id_employe'];
                        header("Location: login_2fa_check.php");
                        exit();
                    } else {
                        $error_message = "Connexion refusée : la double authentification (2FA) est obligatoire. Veuillez contacter le service informatique.";
                    }

                } else {
                    $upsert = $conn->prepare("INSERT INTO login_attempts (ip, fails, locked_until) VALUES (?, 1, NULL) ON DUPLICATE KEY UPDATE fails = fails + 1, locked_until = IF(fails + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)");
                    $upsert->bind_param("s", $ip);
                    $upsert->execute();
                    $upsert->close();
                    $error_message = "Matricule ou mot de passe incorrect.";
                }
            } else {
                $error_message = "Erreur technique. Veuillez réessayer.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — TERRACOOP</title>
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
            max-width: 420px;
            overflow: hidden;
        }

        /* En-tête de la carte */
        .login-card-header {
            background: linear-gradient(135deg, #0054b3, #007bff);
            padding: 28px 32px 24px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .login-logo {
            width: 46px;
            height: 46px;
            background: rgba(255,255,255,0.18);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .login-logo svg {
            width: 28px;
            height: 28px;
            fill: #fff;
        }

        .login-brand-name {
            color: #fff;
            font-size: 1.15rem;
            font-weight: 800;
            letter-spacing: 0.06em;
            line-height: 1.1;
        }

        .login-brand-sub {
            color: rgba(255,255,255,0.75);
            font-size: 0.74rem;
            font-weight: 400;
            margin-top: 3px;
            letter-spacing: 0.02em;
        }

        /* Corps de la carte */
        .login-card-body {
            padding: 28px 32px 32px;
        }

        .login-card-body h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #212529;
            margin-bottom: 20px;
        }

        /* Message d'erreur */
        .msg-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 7px;
            padding: 11px 14px;
            font-size: 0.875rem;
            margin-bottom: 18px;
            line-height: 1.5;
        }

        /* Groupes de champs */
        .field-group {
            margin-bottom: 16px;
        }

        .field-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 6px;
        }

        .field-group input {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #dee2e6;
            border-radius: 7px;
            font-size: 0.95rem;
            font-family: inherit;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .field-group input:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.12);
            outline: none;
        }

        /* Bouton */
        .btn-login {
            width: 100%;
            padding: 11px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 7px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 8px;
            transition: background 0.2s, transform 0.1s;
        }

        .btn-login:hover  { background: #0062cc; }
        .btn-login:active { transform: scale(0.99); }

        /* Pied de page */
        .login-footer {
            text-align: center;
            margin-top: 18px;
            font-size: 0.78rem;
            color: rgba(255,255,255,0.65);
        }
    </style>
</head>
<body>

<div class="login-card">

    <div class="login-card-header">
        <div class="login-logo">
            <svg viewBox="0 0 24 24">
                <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
            </svg>
        </div>
        <div>
            <div class="login-brand-name">TERRACOOP</div>
            <div class="login-brand-sub">Gestion de Flotte Automobile</div>
        </div>
    </div>

    <div class="login-card-body">
        <h2>Connexion</h2>

        <?php if ($logout_message): ?>
            <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; border-radius:7px; padding:11px 14px; font-size:0.875rem; margin-bottom:18px; display:flex; align-items:center; gap:8px;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="#155724"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                Vous avez été déconnecté avec succès.
            </div>
        <?php endif; ?>
        <?php if ($session_expired): ?>
            <div style="background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; border-radius:7px; padding:11px 14px; font-size:0.875rem; margin-bottom:18px; display:flex; align-items:center; gap:8px;">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="#721c24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                Cette session a été révoquée. Veuillez vous reconnecter.
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="msg-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="field-group">
                <label for="matricule">Matricule</label>
                <input type="text" id="matricule" name="matricule" required autofocus
                       placeholder="Votre matricule"
                       value="<?php echo htmlspecialchars($_POST['matricule'] ?? ''); ?>">
            </div>
            <div class="field-group">
                <label for="password">Mot de passe</label>
                <input type="password" id="password" name="password" required placeholder="••••••••">
            </div>
            <button type="submit" class="btn-login">Se connecter</button>
        </form>

        <a href="forgot.php" style="display:block; text-align:center; margin-top:14px; font-size:0.83rem; color:#6c757d; text-decoration:none;">
            Mot de passe oublié ?
        </a>
    </div>

</div>

<p class="login-footer">Accès réservé au personnel TERRACOOP</p>

<?php if (isset($_GET['admin'])): ?>

<div class="login-card" style="margin-top:24px;">
    <div class="login-card-header" style="background: linear-gradient(135deg, #1a1a2e, #16213e);">
        <div class="login-logo" style="background:rgba(255,255,255,0.12);">
            <svg viewBox="0 0 24 24">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4.18l6 2.67V11c0 3.82-2.56 7.4-6 8.54-3.44-1.14-6-4.72-6-8.54V7.85l6-2.67z"/>
            </svg>
        </div>
        <div>
            <div class="login-brand-name">TERRACOOP</div>
            <div class="login-brand-sub">Accès Administrateur</div>
        </div>
    </div>
    <div class="login-card-body">
        <h2>Gestion 2FA</h2>

        <?php if ($admin_error): ?>
            <div class="msg-error"><?php echo htmlspecialchars($admin_error); ?></div>
        <?php endif; ?>

        <div style="text-align:center; margin-bottom:20px;">
            <div style="font-size:.8rem; color:#6c757d; margin-bottom:6px;">Code de vérification</div>
            <div style="font-size:2.4rem; font-weight:700; letter-spacing:.25em; color:#1a1a2e; font-family:monospace;">
                <?php echo $_SESSION['admin_challenge']; ?>
            </div>
        </div>

        <form action="login.php?admin=1" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="admin_login" value="1">
            <div class="field-group">
                <label for="admin_password">Réponse</label>
                <input type="number" id="admin_password" name="admin_password" required autofocus placeholder="…" autocomplete="off">
            </div>
            <button type="submit" class="btn-login" style="background:#1a1a2e;">Accéder à l'administration</button>
        </form>

        <a href="login.php" style="display:block; text-align:center; margin-top:14px; font-size:0.83rem; color:#6c757d; text-decoration:none;">
            ← Retour à la connexion
        </a>
    </div>
</div>

<?php else: ?>
<p style="text-align:center; margin-top:10px;">
    <a href="login.php?admin=1" style="color:rgba(255,255,255,0.2); font-size:0.65rem; text-decoration:none; letter-spacing:0.05em;">⚙</a>
</p>
<?php endif; ?>

</body>
</html>
