<?php
require_once 'config.php';

// Vérification de sécurité : utilisateur connecté
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Vérifier l'état de la 2FA pour cet utilisateur (pour afficher un badge d'état)
$status_2fa = "Inactif";
$color_2fa = "red";

$stmt = $conn->prepare("SELECT two_fa_secret FROM employes WHERE id_employe = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();

if (!empty($res['two_fa_secret'])) {
    $status_2fa = "Actif";
    $color_2fa = "green";
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres du compte</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .settings-dashboard {
            max-width: 800px;
            margin: 50px auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            padding: 20px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.2s;
        }
        
        .card:hover {
            transform: translateY(-5px);
        }

        .card h3 {
            margin-top: 0;
            color: #333;
            font-size: 1.5em;
        }

        .icon-large {
            font-size: 4em;
            display: block;
            margin-bottom: 20px;
        }

        .card p {
            color: #666;
            margin-bottom: 25px;
            min-height: 50px;
        }

        .btn-card {
            display: inline-block;
            padding: 12px 25px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            width: 80%;
        }

        .btn-card:hover {
            background-color: #0056b3;
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8em;
            color: white;
            background-color: #ccc;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>

<?php include 'includes/header.php'; ?>

<div style="text-align: center; margin-top: 40px;">
    <h2>⚙️ Paramètres de votre compte</h2>
    <p>Gérez vos préférences et votre sécurité</p>
</div>

<div class="settings-dashboard">
    
    <div class="card">
        <span class="icon-large">🔑</span>
        <h3>Mot de passe</h3>
        <p>Modifiez votre mot de passe régulièrement pour protéger votre accès.</p>
        <a href="update_password.php" class="btn-card">Modifier mon mot de passe</a>
    </div>

    <div class="card">
        <span class="icon-large">📱</span>
        <h3>Double Authentification</h3>
        <span class="badge" style="background-color: <?php echo $color_2fa; ?>;">
            État : <?php echo $status_2fa; ?>
        </span>
        <p>Configurez ou réinitialisez votre application (Google Authenticator) pour sécuriser la connexion.</p>
        <a href="setup_2fa.php" class="btn-card">Générer / Configurer 2FA</a>
    </div>

</div>

<div style="text-align: center; margin-bottom: 50px;">
    <a href="index.php" style="color: #666; text-decoration: none;">&larr; Retour à l'accueil</a>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>