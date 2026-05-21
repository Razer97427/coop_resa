<?php
session_start();
require '../config.php'; // Assurez-vous que $pdo est défini ici

// Assurez-vous que les chemins d'accès vers PHPMailer sont corrects
require 'vendor/autoload.php'; 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ----------------------------------------------------
// Le message de succès doit rester vague pour des raisons de sécurité.
// Il ne doit pas confirmer si l'utilisateur existe ou si l'email a été trouvé.
$vague_success_message = "Si votre compte est enregistré, et qu'une adresse e-mail valide est associée, un lien de réinitialisation a été envoyé.";
// ----------------------------------------------------

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Récupérer l'entrée qui peut être un email ou un nom d'utilisateur
    $input = trim($_POST['input'] ?? '');

    if (empty($input)) {
        $error = "Veuillez entrer une adresse e-mail ou un nom d'utilisateur.";
    } else {
        try {
            // A. Rechercher l'utilisateur par e-mail OU nom d'utilisateur
            // L'ajout de LIMIT 1 est une bonne pratique
            $stmt = $pdo->prepare("SELECT id, username, email FROM utilisateurs WHERE email = ? OR username = ? LIMIT 1");
            $stmt->execute([$input, $input]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Important : Le traitement est le même pour l'utilisateur non trouvé (seul le log change)
            if ($user) {
                
                // NOUVEAU CONTRÔLE : Vérifier si un e-mail est présent dans la base
                if (empty($user['email']) || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                    // Loguer l'événement (pour l'admin) mais afficher le message vague à l'utilisateur
                    error_log("Tentative de réinitialisation par utilisateur sans email: ID=" . $user['id'] . ", User=" . $user['username']);
                    $message = $vague_success_message;
                    // On sort ici, sans générer de jeton ni envoyer d'e-mail
                    
                } else {
                    
                    // --- Le compte existe ET a une adresse email valide ---
                    
                    // B. Générer un jeton cryptographiquement sécurisé
                    $token = bin2hex(random_bytes(32)); // 64 caractères hexadécimaux
                    $expiry = date("Y-m-d H:i:s", time() + 1800); // 30 minutes d'expiration

                    // C. Nettoyer les anciens jetons pour cet utilisateur
                    $stmt_delete = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    $stmt_delete->execute([$user['id']]);

                    // D. Enregistrer le nouveau jeton en base de données
                    $stmt_insert = $pdo->prepare("INSERT INTO password_resets (user_id, token, token_expiry) VALUES (?, ?, ?)");
                    $stmt_insert->execute([$user['id'], $token, $expiry]);

                    // E. Construire le lien de réinitialisation
                    // Utiliser HTTPS est fortement recommandé, et le path relatif est plus robuste.
                    // NOTE : On utilise le même protocole que le site actuel pour plus de robustesse.
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
                    $reset_link = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/export_grospanier/reset.php?token=" . $token;
                    
                    // -------------------------------------------------------------
                    // F. ENVOI DE L'EMAIL VIA MAILJET (SMTP)
                    // -------------------------------------------------------------
                    
                    $subject = "Réinitialisation de votre mot de passe";
                    $body = "Bonjour " . htmlspecialchars($user['username']) . ",\n\n"
                          . "Vous avez demandé la réinitialisation de votre mot de passe.\n"
                          . "Cliquez sur le lien suivant pour choisir un nouveau mot de passe :\n\n"
                          . $reset_link . "\n\n"
                          . "Ce lien expirera dans 30 minutes. Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer cet e-mail.\n\n"
                          . "Cordialement,\nL'équipe de support.";
                          
                    $mail = new PHPMailer(true);

                    try {
						// DEFINITION DE L'ENCODAGE
						$mail->CharSet = 'UTF-8';
						
                        // Configuration du serveur SMTP (laissez vos paramètres Mailjet)
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.strato.com '; 
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'noreply-terracoop@cooperative-avirons.com';  
                        $mail->Password   = 'Avirons.974-RUN'; 
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
                        $mail->Port       = 587; 

                        // Expéditeur et destinataire
                        $mail->setFrom('noreply-terracoop@cooperative-avirons.com', 'Gros Panier'); 
                        $mail->addAddress($user['email'], htmlspecialchars($user['username'])); // Utiliser l'email trouvé
                        
                        // Contenu de l'email
                        $mail->isHTML(false); 
                        $mail->Subject = $subject;
                        $mail->Body    = $body; 

                        $mail->send();
                        
                        $message = $vague_success_message; // Message de succès
                    
                    } catch (Exception $e) {
                        // Loguer l'échec d'envoi d'email
                        error_log("Erreur PHPMailer/Mailjet (Token OK, Email FAILED): " . $e->getMessage()); 
                        $message = $vague_success_message;
                    }
                } // Fin de la logique si l'utilisateur a un e-mail
                
            } else {
                // L'utilisateur n'existe pas ou l'input est invalide. Afficher le message vague.
                 $message = $vague_success_message;
            }

        } catch (PDOException $e) {
            $error = "Erreur de base de données. Veuillez réessayer plus tard.";
            error_log("DB Error in forgot_password: " . $e->getMessage()); 
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Mot de Passe Oublié</title>
    <link rel="stylesheet" href="style.css"> 
</head>
<body>
<div class="container">
    <h2>🔒 Mot de Passe Oublié ?</h2>
    <p>Entrez votre adresse **e-mail** ou votre **nom d'utilisateur** pour recevoir un lien de réinitialisation.</p>

    <?php if ($error): ?>
        <p style="color: red; font-weight: bold;"><?php echo htmlspecialchars($error); ?></p>
    <?php elseif ($message): ?>
        <p style="color: green; font-weight: bold;"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>

    <form method="POST" action="forgot.php">
        <div>
            <label for="input">E-mail ou Nom d'utilisateur :</label>
            <input type="text" id="input" name="input" required>
        </div>
        <button type="submit">Envoyer le lien de réinitialisation</button>
    </form>
    
    <a href="index.php" style="margin-top: 20px; display: block;">← Retour à la connexion</a>
</div>
</body>
</html>