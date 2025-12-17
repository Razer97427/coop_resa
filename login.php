<?php
require_once 'config.php'; 

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $matricule = trim($_POST['matricule'] ?? '');
    $password_saisi = $_POST['password'] ?? '';

    if (empty($matricule) || empty($password_saisi)) {
        $error_message = "Champs obligatoires.";
    } else {
        $stmt = $conn->prepare("SELECT id_employe, nom, prenom, role, mot_de_passe FROM employes WHERE matricule = ? AND actif = TRUE");
        $stmt->bind_param("s", $matricule);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && $user['mot_de_passe'] == $password_saisi) {
            $_SESSION['user_id'] = $user['id_employe'];
            $_SESSION['user_name'] = $user['prenom'] . ' ' . $user['nom'];
            $_SESSION['user_role'] = $user['role'];
            
            $redirect = ($user['role'] === 'Manager') ? 'manager.php' : 'index.php';
            header('Location: ' . $redirect);
            exit();
        } else {
            $error_message = "Identifiants incorrects.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="login-body">

<div class="login-container">
    <h2>🔑 Connexion</h2>
    
    <?php if ($error_message): ?>
        <div class="message error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <form action="login.php" method="POST">
        <label>Matricule</label>
        <input type="text" name="matricule" required placeholder="Ex: M1234">
        
        <label>Mot de passe</label>
        <input type="password" name="password" required>
        
        <button type="submit">Se Connecter</button>
		
    </form>
</div>

</body>
</html>