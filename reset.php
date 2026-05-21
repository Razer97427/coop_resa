<?php
session_start();
require '../config.php'; // Assurez-vous que $pdo est défini ici

$token = $_GET['token'] ?? '';
$user_id = 0;
$message = '';
$error = '';

// 1. VALIDATION DU JETON
if (empty($token) || strlen($token) !== 64) {
    $error = "Lien de réinitialisation invalide ou incomplet.";
} else {
    try {
        $stmt = $pdo->prepare("SELECT user_id, token_expiry FROM password_resets WHERE token = ?");
        $stmt->execute([$token]);
        $reset_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset_data) {
            $error = "Ce jeton n'existe pas ou a déjà été utilisé.";
        } elseif (strtotime($reset_data['token_expiry']) < time()) {
            $error = "Ce lien de réinitialisation a expiré.";
            // Optionnel : Supprimer le jeton expiré pour nettoyer la base
            $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
        } else {
            // Jeton valide
            $user_id = $reset_data['user_id'];
        }
    } catch (PDOException $e) {
        $error = "Erreur de base de données lors de la vérification du jeton.";
    }
}

// 2. TRAITEMENT DU FORMULAIRE DE NOUVEAU MOT DE PASSE (si le jeton est valide)
if ($user_id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Réinitialiser l'erreur si elle n'est pas liée au token (pour ne pas masquer le formulaire)
    $error = ''; 

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Veuillez entrer et confirmer le nouveau mot de passe.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Le nouveau mot de passe et la confirmation ne correspondent pas.";
    } elseif (strlen($new_password) < 8) {
        $error = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
    } else {
        try {
            // A. Hachage du nouveau mot de passe
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // B. Mise à jour du mot de passe
            $stmt_update = $pdo->prepare("UPDATE utilisateurs SET password = ? WHERE id = ?");
            
            if ($stmt_update->execute([$new_password_hash, $user_id])) {
                // C. SUPPRIMER LE JETON APRÈS UTILISATION (TRÈS IMPORTANT !)
                $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user_id]);
                
                $message = "Votre mot de passe a été réinitialisé avec succès ! Vous pouvez maintenant vous connecter.";
                // Invalider $user_id pour que le formulaire ne s'affiche plus
                $user_id = 0; 
            } else {
                $error = "Erreur lors de la mise à jour du mot de passe.";
            }
        } catch (PDOException $e) {
            $error = "Erreur de base de données : " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Nouveau Mot de Passe</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <h2>🔑 Nouveau Mot de Passe</h2>

    <?php if ($error): ?>
        <p style="color: red; font-weight: bold;"><?php echo htmlspecialchars($error); ?></p>
        
        <?php if ($user_id === 0): // Afficher le lien de demande si le token est invalide/expiré (user_id est 0) ?>
            <p><a href="forgot.php">Demander un nouveau lien de réinitialisation.</a></p>
        <?php endif; ?>

    <?php elseif ($message): // Afficher le succès si tout est OK ?>
        <p style="color: green; font-weight: bold;"><?php echo htmlspecialchars($message); ?></p>
        <a href="index.php">Se connecter maintenant</a>
        
    <?php endif; ?>
    
    <?php if ($user_id > 0 && !$message): ?>
        <p>Veuillez entrer votre nouveau mot de passe.</p>
        <form method="post" action="reset.php?token=<?php echo htmlspecialchars($token); ?>">
            <div>
                <label for="new_password">Nouveau mot de passe :</label>
                <input type="password" id="new_password" name="new_password" required>
            </div>
            <div>
                <label for="confirm_password">Confirmer mot de passe :</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit">Changer le Mot de Passe</button>
        </form>
    <?php endif; ?>
	
	<a href="index.php" style="margin-top: 20px; display: block;">← Retour à la connexion</a>
	
    <?php if ($user_id === 0 && !$message): ?>
         <a href="index.php" style="margin-top: 20px; display: block;">← Retour à la connexion</a>
    <?php endif; ?>
</div>
</body>
</html>