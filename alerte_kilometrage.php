<?php
/**
 * alerte_kilometrage.php
 * Envoie un email de rappel aux employés ayant un véhicule attitré
 * dont le pointage kilométrique du mois en cours n'est pas encore renseigné.
 *
 * Utilisation :
 *   - Via cron : /usr/local/php8.1/bin/php /home/terracoonz/www/gestion-auto/alerte_kilometrage.php
 *   - Via URL  : https://terracoop.re/gestion-auto/alerte_kilometrage.php?token=VOTRE_TOKEN_SECRET
 */
ini_set('display_errors', 'Off');
error_reporting(E_ALL);

date_default_timezone_set('Indian/Reunion');

require_once '/home/terracoonz/www/config.php';
require_once '/home/terracoonz/www/gestion-auto/vendor/autoload.php';

// ── Sécurité : CLI uniquement OU token secret ──────────────────────────────
if (!defined('ALERTE_TOKEN')) {
    define('ALERTE_TOKEN', 'terracoop97425!');
}

$is_cli = (php_sapi_name() === 'cli');
$is_web = !$is_cli;

if ($is_web) {
    if (!isset($_GET['token']) || !hash_equals(ALERTE_TOKEN, $_GET['token'])) {
        http_response_code(403);
        exit('Accès refusé.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$mois_courant   = (int)date('n');
$annee_courante = (int)date('Y');
$mois_fr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$mois_label = $mois_fr[$mois_courant] . ' ' . $annee_courante;

// ── Requête : employés avec véhicule attitré + email + pas de pointage ce mois ──
$sql = "
    SELECT e.id_employe, e.nom, e.prenom, e.email,
           v.immatriculation, v.marque, v.modele
    FROM employes e
    JOIN affectations_fixes af ON af.id_employe = e.id_employe
    JOIN vehicules v ON v.id_vehicule = af.id_vehicule
    WHERE e.actif = 1
      AND e.email IS NOT NULL AND e.email != ''
      AND NOT EXISTS (
          SELECT 1 FROM pointages_kilometrage pk
          WHERE pk.id_vehicule = af.id_vehicule
            AND pk.mois = ?
            AND pk.annee = ?
      )
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $mois_courant, $annee_courante);
$stmt->execute();
$employes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total   = count($employes);
$envoyes = 0;
$erreurs = 0;

echo "=== Alerte kilométrage — $mois_label ===\n";
echo "$total employé(s) sans pointage trouvé(s).\n\n";

if ($total === 0) {
    echo "Aucun email à envoyer.\n";
    exit(0);
}

if (!email_actif('alerte_km')) {
    echo "Envoi d'email désactivé (module 'alerte_km') — aucun email envoyé.\n";
    exit(0);
}

$app_url = 'https://terracoop.re/gestion-auto/login.php?redirect=pointage_kilometrage.php';

foreach ($employes as $emp) {
    $prenom = htmlspecialchars($emp['prenom']);
    $nom    = htmlspecialchars($emp['nom']);
    $veh    = htmlspecialchars($emp['marque'] . ' ' . $emp['modele']);
    $immat  = htmlspecialchars($emp['immatriculation']);

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = smtp_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = smtp_username;
        $mail->Password   = smtp_password;
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = smtp_port;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(smtp_from, 'Gestion Flotte TERRACOOP');
        $mail->addAddress($emp['email'], $emp['prenom'] . ' ' . $emp['nom']);

        $mail->addCustomHeader('X-MJ-TrackClicks', '0');
        $mail->addCustomHeader('X-MJ-TrackOpens',  '0');

        $mail->isHTML(true);
        $mail->Subject = '[TERRACOOP] Rappel : pointage kilométrage ' . $mois_label;
        $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
  <div style="background:#0d3b8c;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:1.15em;">&#128205; Rappel — Pointage kilométrique</h2>
  </div>
  <div style="padding:24px;">
    <p style="margin:0 0 16px;">Bonjour <strong>' . $prenom . '</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">
      Le pointage kilométrique du mois de <strong>' . $mois_label . '</strong> pour votre véhicule n\'a pas encore été renseigné.
    </p>
    <table style="width:100%;border-collapse:collapse;font-size:.95em;margin-bottom:20px;">
      <tr style="background:#f8f9fa;">
        <td style="padding:8px 12px;font-weight:700;width:45%;">Véhicule</td>
        <td style="padding:8px 12px;">' . $veh . '</td>
      </tr>
      <tr>
        <td style="padding:8px 12px;font-weight:700;">Immatriculation</td>
        <td style="padding:8px 12px;">' . $immat . '</td>
      </tr>
      <tr style="background:#f8f9fa;">
        <td style="padding:8px 12px;font-weight:700;">Mois concerné</td>
        <td style="padding:8px 12px;">' . $mois_label . '</td>
      </tr>
    </table>
    <p style="margin:0 0 24px;color:#856404;background:#fff3cd;padding:10px 14px;border-radius:6px;border:1px solid #ffeeba;font-size:.9em;">
      Merci de renseigner le kilométrage réel de votre véhicule dès que possible.
    </p>
    <div style="text-align:center;">
      <a href="' . $app_url . '" style="background:#0d3b8c;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:1em;">
        Saisir mon kilométrage
      </a>
    </div>
  </div>
  <div style="background:#f8f9fa;padding:12px 24px;text-align:center;color:#999;font-size:.8em;border-top:1px solid #dee2e6;">
    Gestion de Flotte TERRACOOP — message automatique
  </div>
</div>';

        $mail->AltBody = "Bonjour {$emp['prenom']},\n\n"
            . "Le pointage kilométrique de $mois_label pour votre véhicule ($veh - $immat) n'a pas encore été renseigné.\n\n"
            . "Merci de vous connecter pour saisir le kilométrage réel :\n$app_url\n\n"
            . "Gestion de Flotte TERRACOOP";

        $mail->send();

        echo "  ✓ Email envoyé à {$emp['prenom']} {$emp['nom']} ({$emp['email']})\n";
        $envoyes++;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        echo "  ✗ Erreur pour {$emp['prenom']} {$emp['nom']} : " . $mail->ErrorInfo . "\n";
        error_log('[alerte_kilometrage] Erreur PHPMailer pour ' . $emp['email'] . ' : ' . $mail->ErrorInfo);
        $erreurs++;
    }
}

echo "\n--- Résumé ---\n";
echo "Envoyés : $envoyes\n";
if ($erreurs > 0) echo "Erreurs  : $erreurs\n";
echo "Terminé.\n";
