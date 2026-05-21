<?php
require_once 'config.php';
require_once 'GoogleAuthenticator.php';

// Démarrer la session si ce n'est pas déjà fait dans config.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si on n'est pas en attente de 2FA, retour au login
if (!isset($_SESSION['2fa_pending_user_id'])) {
    header("Location: login.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $code = trim($_POST['code']);
    $user_id = $_SESSION['2fa_pending_user_id'];

    // CORRECTION : Utilisation de $conn (MySQLi) au lieu de $pdo
    // CORRECTION : Utilisation de 'id_employe' au lieu de 'id'
    $stmt = $conn->prepare("SELECT id_employe, nom, prenom, role, matricule, two_fa_secret FROM employes WHERE id_employe = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        $ga = new PHPGangsta_GoogleAuthenticator();
        // Vérification du code
        // La librairie veut le secret et le code soumis
        $checkResult = $ga->verifyCode($user['two_fa_secret'], $code, 2); // 2 = marge de tolérance (2x30sec)

        if ($checkResult) {
            // Code OK : On connecte réellement l'utilisateur
            // On recrée les variables de session comme dans le login classique
            $_SESSION['user_id'] = $user['id_employe'];
            $_SESSION['user_name'] = $user['prenom'] . ' ' . $user['nom'];
            $_SESSION['user_role'] = $user['role'];

            // On peut ajouter le matricule si besoin
            $_SESSION['matricule'] = $user['matricule'];

            // On nettoie la variable temporaire de 2FA
            unset($_SESSION['2fa_pending_user_id']);

            // Redirection selon le rôle
            $redirect = ($user['role'] === 'Manager') ? 'manager.php' : 'index.php';
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = "Code incorrect.";
        }
    } else {
        // Cas rare : l'utilisateur a disparu entre le login et la validation
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification 2FA — TERRACOOP</title>
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
        .login-logo svg { width: 28px; height: 28px; fill: #fff; }
        .login-brand-name { color: #fff; font-size: 1.15rem; font-weight: 800; letter-spacing: 0.06em; line-height: 1.1; }
        .login-brand-sub  { color: rgba(255,255,255,0.72); font-size: 0.72rem; font-weight: 400; margin-top: 3px; letter-spacing: 0.02em; }

        .login-card-body {
            padding: 28px 32px 32px;
            text-align: center;
        }

        .shield-icon {
            width: 54px;
            height: 54px;
            background: #e8f0fe;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
        }
        .shield-icon svg { width: 27px; height: 27px; fill: #1a56db; }

        .step-badge {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            color: #1a56db;
            background: #e8f0fe;
            border-radius: 20px;
            padding: 3px 12px;
            margin-bottom: 12px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .login-card-body h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #212529;
            margin-bottom: 6px;
        }

        .subtitle {
            font-size: 0.875rem;
            color: #6c757d;
            margin: 0 0 24px;
            line-height: 1.55;
        }

        .msg-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            border-radius: 7px;
            padding: 11px 14px;
            font-size: 0.875rem;
            margin-bottom: 18px;
            text-align: left;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }
        .msg-error svg { flex-shrink: 0; margin-top: 1px; }

        .otp-group {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 24px;
        }

        .otp-input {
            width: 46px;
            height: 56px;
            font-size: 1.5rem;
            font-weight: 700;
            text-align: center;
            border: 1.5px solid #dee2e6;
            border-radius: 8px;
            color: #212529;
            background: #f8f9fa;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            outline: none;
        }
        .otp-input:focus  { border-color: #007bff; box-shadow: 0 0 0 3px rgba(0,123,255,0.15); background: #fff; }
        .otp-input.filled { border-color: #28a745; background: #fff; color: #155724; }

        #code-hidden { display: none; }

        .btn-submit {
            width: 100%;
            padding: 11px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 7px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            letter-spacing: 0.02em;
        }
        .btn-submit:hover   { background: #0062cc; }
        .btn-submit:active  { transform: scale(0.99); }
        .btn-submit:disabled { background: #adb5bd; cursor: not-allowed; }

        .divider { border: none; border-top: 1px solid #dee2e6; margin: 22px 0 16px; }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #6c757d;
            text-decoration: none;
            font-size: 0.875rem;
            transition: color 0.2s;
        }
        .back-link:hover { color: #007bff; }
        .back-link svg { width: 14px; height: 14px; fill: currentColor; }

        .help-text { font-size: 0.75rem; color: #adb5bd; margin-top: 16px; line-height: 1.5; }

        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 0.78rem;
            color: rgba(255,255,255,0.60);
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

        <div class="shield-icon">
            <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-1 13l-3-3 1.41-1.41L11 11.17l4.59-4.58L17 8l-6 6z"/></svg>
        </div>

        <span class="step-badge">Étape 2 / 2 &mdash; Vérification</span>

        <h2>Double Authentification</h2>
        <p class="subtitle">Entrez le code à 6 chiffres affiché dans<br>votre application d'authentification.</p>

        <?php if ($error): ?>
            <div class="msg-error">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="#721c24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                <span><strong>Code invalide —</strong> <?= htmlspecialchars($error) ?> Veuillez réessayer.</span>
            </div>
        <?php endif; ?>

        <form method="post" id="otp-form">
            <div class="otp-group" id="otp-group">
                <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" tabindex="1">
                <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" tabindex="2">
                <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" tabindex="3">
                <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" tabindex="4">
                <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" tabindex="5">
                <input class="otp-input" type="text" inputmode="numeric" maxlength="1" pattern="[0-9]" autocomplete="off" tabindex="6">
            </div>
            <input type="hidden" name="code" id="code-hidden">
            <button type="submit" class="btn-submit" id="btn-verify" disabled>Vérifier le code</button>
        </form>

        <hr class="divider">

        <a href="login.php" class="back-link">
            <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
            Annuler et revenir à la connexion
        </a>

        <p class="help-text">Code valable 30 secondes &bull; Application : Google Authenticator, Authy&hellip;</p>

    </div>
</div>

<p class="login-footer">Accès réservé au personnel TERRACOOP</p>

<script>
(function () {
    const inputs = Array.from(document.querySelectorAll('.otp-input'));
    const hidden = document.getElementById('code-hidden');
    const btn    = document.getElementById('btn-verify');
    const form   = document.getElementById('otp-form');

    function updateState() {
        const val = inputs.map(i => i.value).join('');
        hidden.value = val;
        btn.disabled = val.length < 6;
        inputs.forEach(i => i.classList.toggle('filled', i.value !== ''));
    }

    inputs.forEach((input, idx) => {
        input.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').slice(-1);
            if (this.value && idx < inputs.length - 1) inputs[idx + 1].focus();
            updateState();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && !this.value && idx > 0) {
                inputs[idx - 1].value = '';
                inputs[idx - 1].focus();
                updateState();
            }
        });
        input.addEventListener('paste', function (e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            pasted.split('').slice(0, 6).forEach((ch, i) => { if (inputs[i]) inputs[i].value = ch; });
            const next = Math.min(pasted.length, inputs.length - 1);
            inputs[next].focus();
            updateState();
        });
    });

    form.addEventListener('submit', function () { updateState(); });
    inputs[0].focus();
})();
</script>

</body>
</html>
