<?php
require_once 'config.php';

$token    = trim($_GET['token'] ?? '');
$user_id  = 0;
$message  = '';
$error    = '';

// 1. Validation du token
if (empty($token) || strlen($token) !== 64) {
    $error = "Lien de réinitialisation invalide ou incomplet.";
} else {
    $stmt = $conn->prepare("SELECT user_id, token_expiry FROM init_pass_auto WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $reset_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$reset_data) {
        $error = "Ce lien n'existe pas ou a déjà été utilisé.";
    } elseif (strtotime($reset_data['token_expiry']) < time()) {
        $error = "Ce lien a expiré. Veuillez faire une nouvelle demande.";
        $stmt_del = $conn->prepare("DELETE FROM init_pass_auto WHERE token = ?");
        $stmt_del->bind_param("s", $token);
        $stmt_del->execute();
        $stmt_del->close();
    } else {
        $user_id = (int)$reset_data['user_id'];
    }
}

// 2. Traitement du formulaire
if ($user_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password     = $_POST['new_password']     ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $error = '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Veuillez remplir tous les champs.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Les mots de passe ne correspondent pas.";
    } elseif (strlen($new_password) < 6) {
        $error = "Le mot de passe doit contenir au moins 6 caractères.";
    } else {
        $stmt = $conn->prepare("UPDATE employes SET mot_de_passe = ? WHERE id_employe = ?");
        $stmt->bind_param("si", $new_password, $user_id);

        if ($stmt->execute()) {
            $stmt->close();
            $stmt_del = $conn->prepare("DELETE FROM init_pass_auto WHERE user_id = ?");
            $stmt_del->bind_param("i", $user_id);
            $stmt_del->execute();
            $stmt_del->close();
            $message  = "Votre mot de passe a été modifié avec succès. Vous pouvez maintenant vous connecter.";
            $user_id  = 0;
        } else {
            $stmt->close();
            $error = "Erreur lors de la mise à jour. Veuillez réessayer.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouveau mot de passe — TERRACOOP</title>
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

        .login-logo svg { width: 26px; height: 26px; fill: #fff; }

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

        .login-card-body { padding: 28px 32px 32px; }

        .login-card-body h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #212529;
            margin-bottom: 20px;
        }

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

        .msg-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            border-radius: 7px;
            padding: 11px 14px;
            font-size: 0.875rem;
            margin-bottom: 18px;
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .field-group { margin-bottom: 16px; }

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
            margin-top: 8px;
            transition: background 0.2s, transform 0.1s;
        }

        .btn-submit:hover  { background: #0062cc; }
        .btn-submit:active { transform: scale(0.99); }

        .btn-login-link {
            display: block;
            width: 100%;
            padding: 11px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 7px;
            font-size: 0.95rem;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            margin-top: 8px;
            transition: background 0.2s;
        }

        .btn-login-link:hover { background: #0062cc; }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 18px;
            font-size: 0.83rem;
            color: #6c757d;
            text-decoration: none;
        }

        .back-link:hover { color: #007bff; }

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
                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
            </svg>
        </div>
        <div>
            <div class="login-brand-name">TERRACOOP</div>
            <div class="login-brand-sub">Réinitialisation du mot de passe</div>
        </div>
    </div>

    <div class="login-card-body">
        <h2>Nouveau mot de passe</h2>

        <?php if ($message): ?>
            <div class="msg-success">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="#155724" style="flex-shrink:0;margin-top:1px"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <a href="login.php" class="btn-login-link">Se connecter</a>

        <?php elseif ($error): ?>
            <div class="msg-error"><?php echo htmlspecialchars($error); ?></div>
            <?php if ($user_id === 0): ?>
                <a href="forgot.php" class="btn-login-link">Faire une nouvelle demande</a>
            <?php endif; ?>

        <?php else: ?>
            <form method="POST" action="reset.php?token=<?php echo htmlspecialchars($token); ?>">
                <div class="field-group">
                    <label for="new_password">Nouveau mot de passe</label>
                    <input type="password" id="new_password" name="new_password" required placeholder="••••••••" minlength="6">
                </div>
                <div class="field-group">
                    <label for="confirm_password">Confirmer le mot de passe</label>
                    <input type="password" id="confirm_password" name="confirm_password" required placeholder="••••••••" minlength="6">
                </div>
                <button type="submit" class="btn-submit">Valider le nouveau mot de passe</button>
            </form>
        <?php endif; ?>

        <a href="login.php" class="back-link">← Retour à la connexion</a>
    </div>

</div>

<p class="login-footer">Accès réservé au personnel TERRACOOP</p>

</body>
</html>
