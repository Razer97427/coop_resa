<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Création automatique de la table si elle n'existe pas encore
$conn->query("CREATE TABLE IF NOT EXISTS init_pass_auto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    token_expiry DATETIME NOT NULL,
    INDEX idx_token (token),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$vague_message = "Si votre matricule est enregistré avec une adresse e-mail valide, un lien de réinitialisation vous a été envoyé.";
$message = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $matricule = trim($_POST['matricule'] ?? '');

    if (empty($matricule)) {
        $error = "Veuillez saisir votre matricule.";
    } else {
        $stmt = $conn->prepare("SELECT id_employe, nom, prenom, email FROM employes WHERE matricule = ? AND actif = 1");
        $stmt->bind_param("s", $matricule);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && !empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {

            $token  = bin2hex(random_bytes(32)); // 64 caractères
            $expiry = date("Y-m-d H:i:s", time() + 1800); // 30 minutes

            $stmt_del = $conn->prepare("DELETE FROM init_pass_auto WHERE user_id = ?");
            $stmt_del->bind_param("i", $user['id_employe']);
            $stmt_del->execute();
            $stmt_del->close();

            $stmt_ins = $conn->prepare("INSERT INTO init_pass_auto (user_id, token, token_expiry) VALUES (?, ?, ?)");
            $stmt_ins->bind_param("iss", $user['id_employe'], $token, $expiry);
            $stmt_ins->execute();
            $stmt_ins->close();

            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base_path  = rtrim(dirname($_SERVER['PHP_SELF']), '/');
            $reset_link = $protocol . '://' . $_SERVER['HTTP_HOST'] . $base_path . '/reset.php?token=' . $token;

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
                $mail->addAddress($user['email'], $user['prenom'] . ' ' . $user['nom']);

                $mail->isHTML(false);
                $mail->Subject = 'Réinitialisation de votre mot de passe — TERRACOOP';
                $mail->Body    = "Bonjour " . $user['prenom'] . " " . $user['nom'] . ",\n\n"
                               . "Vous avez demandé la réinitialisation de votre mot de passe.\n\n"
                               . "Cliquez sur le lien ci-dessous pour choisir un nouveau mot de passe :\n"
                               . $reset_link . "\n\n"
                               . "Ce lien est valable 30 minutes.\n"
                               . "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.\n\n"
                               . "— Service Informatique TERRACOOP";

                $mail->send();
            } catch (Exception $e) {
                error_log("[RESET MDP] Échec envoi email vers " . $user['email'] . " — " . $mail->ErrorInfo);
            }
        }

        $message = $vague_message;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mot de passe oublié — TERRACOOP</title>
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
            margin-bottom: 8px;
        }

        .login-card-body p.intro {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 20px;
            line-height: 1.5;
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
        <h2>Mot de passe oublié ?</h2>
        <p class="intro">Saisissez votre matricule. Si une adresse e-mail est associée à votre compte, vous recevrez un lien de réinitialisation.</p>

        <?php if ($error): ?>
            <div class="msg-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="msg-success">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="#155724" style="flex-shrink:0;margin-top:1px"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php else: ?>
            <form action="forgot.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div class="field-group">
                    <label for="matricule">Matricule</label>
                    <input type="text" id="matricule" name="matricule" required autofocus
                           placeholder="Votre matricule"
                           value="<?php echo htmlspecialchars($_POST['matricule'] ?? ''); ?>">
                </div>
                <button type="submit" class="btn-submit">Envoyer le lien de réinitialisation</button>
            </form>
        <?php endif; ?>

        <a href="login.php" class="back-link">← Retour à la connexion</a>
    </div>

</div>

<p class="login-footer">Accès réservé au personnel TERRACOOP</p>

</body>
</html>
