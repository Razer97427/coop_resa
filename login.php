<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error_message = '';
$logout_message = isset($_GET['logout']) && $_GET['logout'] == '1';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $matricule       = trim($_POST['matricule'] ?? '');
    $password_saisi  = $_POST['password'] ?? '';

    if (empty($matricule) || empty($password_saisi)) {
        $error_message = "Tous les champs sont obligatoires.";
    } else {
        $stmt = $conn->prepare("SELECT id_employe, nom, prenom, role, mot_de_passe, two_fa_secret FROM employes WHERE matricule = ? AND actif = TRUE");

        if ($stmt) {
            $stmt->bind_param("s", $matricule);
            $stmt->execute();
            $result = $stmt->get_result();
            $user   = $result->fetch_assoc();
            $stmt->close();

            if ($user && $user['mot_de_passe'] == $password_saisi) {

                if (!empty($user['two_fa_secret'])) {
                    $_SESSION['2fa_pending_user_id'] = $user['id_employe'];
                    header("Location: login_2fa_check.php");
                    exit();
                } else {
                    $error_message = "Connexion refusée : la double authentification (2FA) est obligatoire. Veuillez contacter le service informatique.";
                }

            } else {
                $error_message = "Matricule ou mot de passe incorrect.";
            }
        } else {
            $error_message = "Erreur technique. Veuillez réessayer.";
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

        <?php if ($error_message): ?>
            <div class="msg-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
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

</body>
</html>
