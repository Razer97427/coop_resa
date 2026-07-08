<?php
/**
 * api_alerte_revision.php
 * Envoie un email récapitulatif aux managers listant les véhicules
 * dont la révision générale ou le contrôle technique est à prévoir ou dépassé.
 *
 * Utilisation :
 *   - Via cron : /usr/local/php8.1/bin/php /home/terracoonz/www/gestion-auto/api_alerte_revision.php
 *   - Via URL  : https://terracoop.re/gestion-auto/api_alerte_revision.php?token=VOTRE_TOKEN_SECRET
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

$today    = date('Y-m-d');
$today_ts = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
$date_fr  = date('d/m/Y');

// ── Véhicules actifs avec alerte révision ou CT ───────────────────────────
$sql = "
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele, v.kilometrage,
           v.km_prochaine_revision, v.km_seuil_alerte_revision,
           v.date_prochain_ct, v.nb_jours_alerte_ct,
           e.nom, e.prenom, e.email AS emp_email
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON af.id_vehicule = v.id_vehicule
    LEFT JOIN employes e ON e.id_employe = af.id_employe
    WHERE v.actif = 1
      AND (
          (v.km_prochaine_revision IS NOT NULL
           AND v.kilometrage >= v.km_prochaine_revision - COALESCE(v.km_seuil_alerte_revision, 500))
          OR
          (v.date_prochain_ct IS NOT NULL
           AND v.date_prochain_ct <= DATE_ADD(CURDATE(), INTERVAL COALESCE(v.nb_jours_alerte_ct, 30) DAY))
      )
    ORDER BY v.marque, v.modele
";

$result           = $conn->query($sql);
$vehicules_alerte = $result->fetch_all(MYSQLI_ASSOC);
$nb_alertes       = count($vehicules_alerte);

echo "=== Alertes Révision / CT — $date_fr ===\n";
echo "$nb_alertes véhicule(s) avec alerte trouvé(s).\n\n";

if ($nb_alertes === 0) {
    echo "Aucune alerte à signaler. Aucun email envoyé.\n";
    exit(0);
}

// ── Récupérer les emails des managers ─────────────────────────────────────
$mgr_result = $conn->query("
    SELECT email, nom, prenom
    FROM employes
    WHERE role = 'Manager' AND actif = 1 AND email IS NOT NULL AND email != ''
");
$managers = $mgr_result->fetch_all(MYSQLI_ASSOC);

if (empty($managers)) {
    echo "Aucun manager avec email trouvé. Aucun email envoyé.\n";
    exit(0);
}


// ── Construire les lignes HTML et texte du récapitulatif ──────────────────
$lignes_html = '';
$lignes_txt  = '';

foreach ($vehicules_alerte as $v) {
    $immat   = htmlspecialchars($v['immatriculation']);
    $veh     = htmlspecialchars($v['marque'] . ' ' . $v['modele']);
    $cond    = ($v['nom'] !== null) ? htmlspecialchars($v['prenom'] . ' ' . $v['nom']) : '—';
    $km_cur  = number_format((int)$v['kilometrage'], 0, ',', ' ');
    $km_actuel = (int)$v['kilometrage'];

    // Statut révision
    if ($v['km_prochaine_revision'] !== null) {
        $km_rev   = (int)$v['km_prochaine_revision'];
        $km_seuil = (int)($v['km_seuil_alerte_revision'] ?? 500);
        if ($km_actuel >= $km_rev) {
            $rev_html = '<span style="background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:4px;font-size:.85em;">🔴 Dépassée — prévu à ' . number_format($km_rev, 0, ',', ' ') . ' km</span>';
            $rev_txt  = '🔴 Révision dépassée (prévu à ' . number_format($km_rev, 0, ',', ' ') . ' km)';
        } else {
            $reste    = $km_rev - $km_actuel;
            $rev_html = '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:4px;font-size:.85em;">⚠️ Dans ' . number_format($reste, 0, ',', ' ') . ' km (seuil : ' . number_format($km_rev, 0, ',', ' ') . ' km)</span>';
            $rev_txt  = '⚠️ Révision dans ' . number_format($reste, 0, ',', ' ') . ' km';
        }
    } else {
        $rev_html = '<span style="color:#aaa;">—</span>';
        $rev_txt  = '—';
    }

    // Statut contrôle technique
    if ($v['date_prochain_ct'] !== null) {
        $date_ct_ts = mktime(0, 0, 0,
            (int)substr($v['date_prochain_ct'], 5, 2),
            (int)substr($v['date_prochain_ct'], 8, 2),
            (int)substr($v['date_prochain_ct'], 0, 4)
        );
        $date_ct_fr   = date('d/m/Y', $date_ct_ts);
        $jours_restants = (int)(($date_ct_ts - $today_ts) / 86400);
        if ($v['date_prochain_ct'] <= $today) {
            $ct_html = '<span style="background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:4px;font-size:.85em;">🔴 CT dépassé (' . $date_ct_fr . ')</span>';
            $ct_txt  = '🔴 CT dépassé (' . $date_ct_fr . ')';
        } else {
            $ct_html = '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:4px;font-size:.85em;">⚠️ CT le ' . $date_ct_fr . ' (dans ' . $jours_restants . ' j.)</span>';
            $ct_txt  = '⚠️ CT le ' . $date_ct_fr . ' (dans ' . $jours_restants . ' j.)';
        }
    } else {
        $ct_html = '<span style="color:#aaa;">—</span>';
        $ct_txt  = '—';
    }

    $lignes_html .= "
      <tr style=\"border-bottom:1px solid #dee2e6;\">
        <td style=\"padding:8px 10px;\"><strong>$immat</strong><br><small style=\"color:#6c757d;\">$veh</small></td>
        <td style=\"padding:8px 10px;color:#555;\">$cond</td>
        <td style=\"padding:8px 10px;\">$km_cur km</td>
        <td style=\"padding:8px 10px;\">$rev_html</td>
        <td style=\"padding:8px 10px;\">$ct_html</td>
      </tr>";

    $lignes_txt .= "• $immat — $veh (conducteur : $cond) — KM : $km_cur km\n"
                 . "  Révision : $rev_txt\n"
                 . "  CT       : $ct_txt\n\n";

    echo "  · $immat — $veh — Révision : $rev_txt | CT : $ct_txt\n";
}

$app_url = 'https://terracoop.re/gestion-auto/parc.php?tab=vue';
$envoyes = 0;
$erreurs = 0;

if (!email_actif('alerte_revision')) {
    echo "\nEnvoi d'email désactivé (module 'alerte_revision') — aucun email envoyé.\n";
    exit(0);
}

// ── Envoi d'un email récap à chaque manager ───────────────────────────────
foreach ($managers as $mgr) {
    $prenom_mgr = htmlspecialchars($mgr['prenom']);

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
        $mail->addAddress($mgr['email'], $mgr['prenom'] . ' ' . $mgr['nom']);

        $mail->addCustomHeader('X-MJ-TrackClicks', '0');
        $mail->addCustomHeader('X-MJ-TrackOpens',  '0');

        $mail->isHTML(true);
        $mail->Subject = '[TERRACOOP] ⚠️ ' . $nb_alertes . ' véhicule(s) — Révision / CT à prévoir — ' . $date_fr;
        $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:720px;margin:auto;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
  <div style="background:#0d3b8c;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:1.15em;">⚠️ Alertes Révision &amp; Contrôle Technique</h2>
    <p style="color:#c5d5f0;margin:6px 0 0;font-size:.9em;">' . $date_fr . '</p>
  </div>
  <div style="padding:24px;">
    <p style="margin:0 0 16px;">Bonjour <strong>' . $prenom_mgr . '</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">
      <strong>' . $nb_alertes . ' véhicule(s)</strong> nécessitent votre attention pour la révision générale ou le contrôle technique :
    </p>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.9em;min-width:560px;">
      <thead>
        <tr style="background:#f0f4ff;">
          <th style="padding:9px 10px;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Véhicule</th>
          <th style="padding:9px 10px;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Conducteur</th>
          <th style="padding:9px 10px;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">KM actuel</th>
          <th style="padding:9px 10px;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">Révision</th>
          <th style="padding:9px 10px;text-align:left;border-bottom:2px solid #dee2e6;white-space:nowrap;">CT</th>
        </tr>
      </thead>
      <tbody>' . $lignes_html . '</tbody>
    </table>
    </div>
    <p style="margin:20px 0 0;color:#6c757d;font-size:.85em;">
      Pensez à mettre à jour les paramètres de révision dans la Vue d\'ensemble du parc après intervention.
    </p>
    <div style="text-align:center;margin-top:24px;">
      <a href="' . $app_url . '" style="background:#0d3b8c;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:1em;">
        Voir le parc automobile
      </a>
    </div>
  </div>
  <div style="background:#f8f9fa;padding:12px 24px;text-align:center;color:#999;font-size:.8em;border-top:1px solid #dee2e6;">
    Gestion de Flotte TERRACOOP — message automatique
  </div>
</div>';

        $mail->AltBody = "Bonjour {$mgr['prenom']},\n\n"
            . "$nb_alertes véhicule(s) nécessitent votre attention :\n\n"
            . $lignes_txt
            . "Voir le parc automobile : $app_url\n\n"
            . "Pensez à mettre à jour les paramètres de révision après intervention.\n\n"
            . "Gestion de Flotte TERRACOOP";

        $mail->send();
        echo "\n  ✓ Email envoyé à {$mgr['prenom']} {$mgr['nom']} ({$mgr['email']})\n";
        $envoyes++;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        echo "\n  ✗ Erreur pour {$mgr['prenom']} {$mgr['nom']} : " . $mail->ErrorInfo . "\n";
        error_log('[api_alerte_revision] Erreur PHPMailer pour ' . $mgr['email'] . ' : ' . $mail->ErrorInfo);
        $erreurs++;
    }
}

// ── Envoi d'un email personnalisé à chaque employé concerné ──────────────
echo "\n[Notifications employés]\n";
$envoyes_emp  = 0;
$erreurs_emp  = 0;
$skips_emp    = 0;

// Emails des managers déjà notifiés (pour éviter le doublon si manager = conducteur)
$emails_managers = array_map(fn($m) => strtolower(trim($m['email'])), $managers);

foreach ($vehicules_alerte as $v) {
    if (empty($v['emp_email'])) continue;

    // Si le conducteur est lui-même manager, il a déjà reçu le récap complet
    if (in_array(strtolower(trim($v['emp_email'])), $emails_managers, true)) {
        echo "  ~ Doublon ignoré : {$v['prenom']} {$v['nom']} ({$v['emp_email']}) est manager — récap déjà envoyé\n";
        $skips_emp++;
        continue;
    }

    $immat   = htmlspecialchars($v['immatriculation']);
    $veh     = htmlspecialchars($v['marque'] . ' ' . $v['modele']);
    $prenom  = htmlspecialchars($v['prenom']);
    $km_cur  = number_format((int)$v['kilometrage'], 0, ',', ' ');

    // Ligne révision
    if ($v['km_prochaine_revision'] !== null) {
        $km_rev    = (int)$v['km_prochaine_revision'];
        $km_actuel = (int)$v['kilometrage'];
        if ($km_actuel >= $km_rev) {
            $rev_ligne = '<span style="background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:4px;font-size:.85em;">🔴 Dépassée — prévu à ' . number_format($km_rev, 0, ',', ' ') . ' km</span>';
            $rev_txt   = '🔴 Révision dépassée (prévu à ' . number_format($km_rev, 0, ',', ' ') . ' km)';
        } else {
            $reste     = $km_rev - $km_actuel;
            $rev_ligne = '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:4px;font-size:.85em;">⚠️ Dans ' . number_format($reste, 0, ',', ' ') . ' km (seuil : ' . number_format($km_rev, 0, ',', ' ') . ' km)</span>';
            $rev_txt   = '⚠️ Révision dans ' . number_format($reste, 0, ',', ' ') . ' km';
        }
    } else {
        $rev_ligne = '<span style="color:#aaa;">—</span>';
        $rev_txt   = '—';
    }

    // Ligne CT
    if ($v['date_prochain_ct'] !== null) {
        $ct_ts = mktime(0, 0, 0,
            (int)substr($v['date_prochain_ct'], 5, 2),
            (int)substr($v['date_prochain_ct'], 8, 2),
            (int)substr($v['date_prochain_ct'], 0, 4)
        );
        $ct_fr = date('d/m/Y', $ct_ts);
        $j_ct  = (int)(($ct_ts - $today_ts) / 86400);
        if ($v['date_prochain_ct'] <= $today) {
            $ct_ligne = '<span style="background:#f8d7da;color:#721c24;padding:2px 8px;border-radius:4px;font-size:.85em;">🔴 CT dépassé (' . $ct_fr . ')</span>';
            $ct_txt   = '🔴 CT dépassé (' . $ct_fr . ')';
        } else {
            $ct_ligne = '<span style="background:#fff3cd;color:#856404;padding:2px 8px;border-radius:4px;font-size:.85em;">⚠️ CT le ' . $ct_fr . ' (dans ' . $j_ct . ' j.)</span>';
            $ct_txt   = '⚠️ CT le ' . $ct_fr . ' (dans ' . $j_ct . ' j.)';
        }
    } else {
        $ct_ligne = '<span style="color:#aaa;">—</span>';
        $ct_txt   = '—';
    }

    try {
        $mail_emp = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail_emp->isSMTP();
        $mail_emp->Host       = smtp_host;
        $mail_emp->SMTPAuth   = true;
        $mail_emp->Username   = smtp_username;
        $mail_emp->Password   = smtp_password;
        $mail_emp->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail_emp->Port       = smtp_port;
        $mail_emp->CharSet    = 'UTF-8';

        $mail_emp->setFrom(smtp_from, 'Gestion Flotte TERRACOOP');
        $mail_emp->addAddress($v['emp_email'], $v['prenom'] . ' ' . $v['nom']);

        $mail_emp->addCustomHeader('X-MJ-TrackClicks', '0');
        $mail_emp->addCustomHeader('X-MJ-TrackOpens',  '0');

        $mail_emp->isHTML(true);
        $mail_emp->Subject = '[TERRACOOP] ⚠️ Maintenance à prévoir — ' . $v['immatriculation'] . ' — ' . $date_fr;
        $mail_emp->Body = '
<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
  <div style="background:#0d3b8c;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:1.15em;">⚠️ Maintenance à prévoir sur votre véhicule</h2>
    <p style="color:#c5d5f0;margin:6px 0 0;font-size:.9em;">' . $date_fr . '</p>
  </div>
  <div style="padding:24px;">
    <p style="margin:0 0 16px;">Bonjour <strong>' . $prenom . '</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">
      Une ou plusieurs opérations de maintenance sont à prévoir sur votre véhicule attitré :
    </p>
    <table style="width:100%;border-collapse:collapse;font-size:.92em;margin-bottom:20px;">
      <tr style="background:#f8f9fa;">
        <td style="padding:9px 12px;font-weight:700;width:42%;border-bottom:1px solid #dee2e6;">Véhicule</td>
        <td style="padding:9px 12px;border-bottom:1px solid #dee2e6;"><strong>' . $immat . '</strong> — ' . $veh . '</td>
      </tr>
      <tr>
        <td style="padding:9px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">KM actuel</td>
        <td style="padding:9px 12px;border-bottom:1px solid #dee2e6;">' . $km_cur . ' km</td>
      </tr>
      <tr style="background:#f8f9fa;">
        <td style="padding:9px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">🔧 Révision</td>
        <td style="padding:9px 12px;border-bottom:1px solid #dee2e6;">' . $rev_ligne . '</td>
      </tr>
      <tr>
        <td style="padding:9px 12px;font-weight:700;">🔍 Contrôle technique</td>
        <td style="padding:9px 12px;">' . $ct_ligne . '</td>
      </tr>
    </table>
    <p style="margin:0;color:#6c757d;font-size:.85em;">
      Votre manager a également été informé. Merci de vous rapprocher de lui pour planifier l\'intervention.
    </p>
  </div>
  <div style="background:#f8f9fa;padding:12px 24px;text-align:center;color:#999;font-size:.8em;border-top:1px solid #dee2e6;">
    Gestion de Flotte TERRACOOP — message automatique
  </div>
</div>';

        $mail_emp->AltBody = "Bonjour {$v['prenom']},\n\n"
            . "Une ou plusieurs opérations de maintenance sont à prévoir sur votre véhicule :\n\n"
            . "Véhicule   : $immat — $veh\n"
            . "KM actuel  : $km_cur km\n"
            . "Révision   : $rev_txt\n"
            . "CT         : $ct_txt\n\n"
            . "Votre manager a également été informé.\n\n"
            . "Gestion de Flotte TERRACOOP";

        $mail_emp->send();
        echo "  ✓ Email employé envoyé à {$v['prenom']} {$v['nom']} ({$v['emp_email']}) — {$v['immatriculation']}\n";
        $envoyes_emp++;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        echo "  ✗ Erreur employé {$v['prenom']} {$v['nom']} : " . $mail_emp->ErrorInfo . "\n";
        error_log('[api_alerte_revision] Erreur PHPMailer employé ' . $v['emp_email'] . ' : ' . $mail_emp->ErrorInfo);
        $erreurs_emp++;
    }
}

echo "\n--- Résumé ---\n";
echo "Véhicules en alerte      : $nb_alertes\n";
echo "Emails managers          : $envoyes\n";
echo "Emails employés          : $envoyes_emp\n";
if ($skips_emp > 0)   echo "Doublons ignorés (mgr)   : $skips_emp\n";
if ($erreurs > 0)     echo "Erreurs managers         : $erreurs\n";
if ($erreurs_emp > 0) echo "Erreurs employés         : $erreurs_emp\n";
echo "Terminé.\n";
