<?php
// ============================================================
//  test_mail.php — À SUPPRIMER après le test !
//  Accès : https://ton-serveur/gestion-auto/test_mail.php
// ============================================================
require_once 'config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── Adresse de test : change ici ──
$email_test = 'johndouglas.delgard@gmail.com';

echo "<pre style='font-family:monospace; background:#1e1e1e; color:#d4d4d4; padding:20px; border-radius:8px;'>";
echo "=== TEST PHPMAILER + MAILJET ===\n\n";

// Vérif constantes
echo "smtp_host     = " . smtp_host     . "\n";
echo "smtp_port     = " . smtp_port     . "\n";
echo "smtp_username = " . smtp_username . "\n";
echo "smtp_password = " . (smtp_password ? str_repeat('*', strlen(smtp_password)) : 'VIDE ❌') . "\n\n";

try {
    $mail = new PHPMailer(true);

    // Activer le debug SMTP complet
    $mail->SMTPDebug  = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = function($str, $level) {
        echo htmlspecialchars("[$level] $str") . "\n";
    };

    $mail->isSMTP();
    $mail->Host       = smtp_host;
    $mail->SMTPAuth   = true;
    $mail->Username   = smtp_username;
    $mail->Password   = smtp_password;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = smtp_port;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom('noreply@delgard.re', 'Test TERRACOOP');
    $mail->addAddress($email_test);

    $mail->isHTML(false);
    $mail->Subject = '[TEST] PHPMailer Mailjet TERRACOOP';
    $mail->Body    = "Bonjour,\n\nSi vous recevez cet email, PHPMailer fonctionne correctement !\n\n— Test TERRACOOP";

    $mail->send();
    echo "\n✅ EMAIL ENVOYÉ avec succès à : $email_test\n";

} catch (Exception $e) {
    echo "\n❌ ERREUR : " . $mail->ErrorInfo . "\n";
    echo "Exception  : " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<p style='color:red; font-weight:bold;'>⚠️ Supprime ce fichier après le test !</p>";
?>
