<?php
require_once 'config.php';

// 1. Sécurité : L'utilisateur doit être connecté
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$message = "";
$message_type = ""; // 'success' ou 'error'

// 2. Traitement du formulaire
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	check_csrf();
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';

    if (empty($new_pass) || empty($confirm_pass)) {
        $message = "Veuillez remplir tous les champs.";
        $message_type = "error";
    } elseif ($new_pass !== $confirm_pass) {
        $message = "Les mots de passe ne correspondent pas.";
        $message_type = "error";
    } else {
        // Mise à jour en base de données
        $user_id = $_SESSION['user_id'];
        
        $stmt = $conn->prepare("UPDATE employes SET mot_de_passe = ? WHERE id_employe = ?");
        $stmt->bind_param("si", $new_pass, $user_id);

        if ($stmt->execute()) {
            $message = "✅ Votre mot de passe a été modifié avec succès !";
            $message_type = "success";
        } else {
            $message = "Erreur lors de la mise à jour : " . $conn->error;
            $message_type = "error";
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changer mon mot de passe</title>
    <link rel="stylesheet" href="styles.css">
    <style>
        .settings-container {
            max-width: 500px;
            margin: 50px auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; color: #333; font-weight: bold; }
        .form-group input { 
            width: 100%; padding: 10px; 
            border: 1px solid #ddd; border-radius: 4px; 
            box-sizing: border-box; 
        }
        .btn-update {
            width: 100%; padding: 12px;
            background-color: #28a745; color: white;
            border: none; border-radius: 4px; cursor: pointer;
            font-size: 16px; margin-top: 10px;
        }
        .btn-update:hover { background-color: #218838; }
        .msg { padding: 15px; border-radius: 4px; margin-bottom: 20px; text-align: center; }
        .msg.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .msg.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
    </style>
    
    <script>
        function confirmerModification() {
            // Cette fonction affiche une boite de dialogue native
            return confirm("⚠️ Attention !\n\nÊtes-vous sûr de vouloir modifier votre mot de passe ?");
        }
    </script>
</head>
<body>

<?php include 'includes/header.php'; ?>

<div class="settings-container">
    <h2 style="text-align:center;">⚙️ Paramètres du compte</h2>
    <p style="text-align:center; color:#666; margin-bottom: 20px;">Réinitialiser mon mot de passe</p>

    <?php if ($message): ?>
        <div class="msg <?php echo $message_type; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="" onsubmit="return confirmerModification()">
	<?php csrf_field(); ?>
        <div class="form-group">
            <label for="new_password">Nouveau mot de passe :</label>
            <input type="password" id="new_password" name="new_password" required placeholder="Entrez le nouveau mot de passe">
        </div>

        <div class="form-group">
            <label for="confirm_password">Confirmer le mot de passe :</label>
            <input type="password" id="confirm_password" name="confirm_password" required placeholder="Répétez le mot de passe">
        </div>

        <button type="submit" class="btn-update">Valider la modification</button>
    </form>
    
    <div style="text-align: center; margin-top: 20px;">
        <a href="index.php" style="color: #666; text-decoration: none;">&larr; Retour à l'accueil</a>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>