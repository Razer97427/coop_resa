<?php
require_once 'config.php';

// ================================================================
// TRAITEMENT : Validation / Refus  (AVANT tout affichage HTML)
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_resa'])) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $is_manager_check = ($_SESSION['user_role'] ?? '') === 'Manager';
    if (!$is_manager_check) { header('Location: index.php'); exit(); }
    csrf_verify();

    $action = $_POST['action_resa'];
    $id     = (int)($_POST['id_reservation'] ?? 0);

    if ($action === 'valider' && !empty($_POST['id_vehicule'])) {
        $id_veh = (int)$_POST['id_vehicule'];
        $id_validateur = (int)$_SESSION['user_id'];
        $id_autorise_par = (int)($_POST['id_autorise_par'] ?? 0);
        $motif_pret = trim($_POST['motif_pret'] ?? '');
        if ($motif_pret === '') $motif_pret = null;

        // Vérifie que la personne ayant donné l'accord existe (champ obligatoire)
        $autorise_ok = false;
        if ($id_autorise_par > 0) {
            $chk_a = $conn->prepare("SELECT 1 FROM employes WHERE id_employe = ?");
            $chk_a->bind_param("i", $id_autorise_par);
            $chk_a->execute();
            $chk_a->store_result();
            $autorise_ok = $chk_a->num_rows > 0;
            $chk_a->close();
        }
        if (!$autorise_ok) {
            header('Location: manager.php?message=' . urlencode('⚠️ Veuillez indiquer la personne ayant autorisé l\'attribution.') . '&type=warning');
            exit();
        }

        // Période de la demande à valider
        $stmt_per = $conn->prepare("SELECT date_debut_resa, date_fin_resa FROM reservations WHERE id_reservation = ?");
        $stmt_per->bind_param("i", $id);
        $stmt_per->execute();
        $periode = $stmt_per->get_result()->fetch_assoc();
        $stmt_per->close();
        if (!$periode) {
            header('Location: manager.php?message=' . urlencode('⚠️ Demande introuvable.') . '&type=warning');
            exit();
        }

        // ── Re-vérification ATOMIQUE de la disponibilité (anti double-attribution) ──
        // On verrouille la ligne véhicule le temps du contrôle pour sérialiser deux managers
        // qui valideraient le même véhicule en même temps.
        $conn->begin_transaction();
        $conflit = false;
        $raison  = '';

        $stmt_lock = $conn->prepare("SELECT actif FROM vehicules WHERE id_vehicule = ? FOR UPDATE");
        $stmt_lock->bind_param("i", $id_veh);
        $stmt_lock->execute();
        $veh_row = $stmt_lock->get_result()->fetch_assoc();
        $stmt_lock->close();

        if (!$veh_row || (int)$veh_row['actif'] !== 1) {
            $conflit = true;
            $raison  = "Ce véhicule n'est plus actif.";
        } else {
            // Une autre réservation Validée/En cours chevauche-t-elle le créneau ?
            $stmt_ov = $conn->prepare("
                SELECT COUNT(*) AS n
                FROM reservations
                WHERE id_vehicule = ?
                  AND id_reservation <> ?
                  AND statut_resa IN ('Validée','En cours')
                  AND date_debut_resa < ? AND date_fin_resa > ?
            ");
            $stmt_ov->bind_param("iiss", $id_veh, $id, $periode['date_fin_resa'], $periode['date_debut_resa']);
            $stmt_ov->execute();
            $ov = $stmt_ov->get_result()->fetch_assoc();
            $stmt_ov->close();
            if ((int)$ov['n'] > 0) {
                $conflit = true;
                $raison  = "Ce véhicule vient d'être attribué à une autre demande sur ce créneau. Veuillez en choisir un autre.";
            }
        }

        if ($conflit) {
            $conn->rollback();
            header('Location: manager.php?message=' . urlencode('⚠️ ' . $raison) . '&type=warning');
            exit();
        }

        $stmt = $conn->prepare("UPDATE reservations SET statut_resa='Validée', id_vehicule=?, id_validateur=?, id_autorise_par=?, motif_pret=? WHERE id_reservation=?");
        $stmt->bind_param("iiisi", $id_veh, $id_validateur, $id_autorise_par, $motif_pret, $id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();

        // ── Email de confirmation à l'employé ──────────────────────────────
        $stmt_info = $conn->prepare("
            SELECT r.date_debut_resa, r.date_fin_resa, r.destination, r.motif,
                   e.email, e.prenom, e.nom,
                   v.marque, v.modele, v.immatriculation, v.type_carburant,
                   CONCAT(mgr.prenom, ' ', mgr.nom) AS nom_validateur
            FROM reservations r
            JOIN employes e   ON e.id_employe   = r.id_employe
            JOIN vehicules v  ON v.id_vehicule  = ?
            JOIN employes mgr ON mgr.id_employe = ?
            WHERE r.id_reservation = ?
        ");
        $stmt_info->bind_param("iii", $id_veh, $id_validateur, $id);
        $stmt_info->execute();
        $info = $stmt_info->get_result()->fetch_assoc();
        $stmt_info->close();

        if (email_actif('confirmation_resa') && $info && !empty($info['email'])) {
            require_once __DIR__ . '/vendor/autoload.php';
            $debut_fmt = date('d/m/Y à H:i', strtotime($info['date_debut_resa']));
            $fin_fmt   = date('d/m/Y à H:i', strtotime($info['date_fin_resa']));
            $app_url   = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/index.php';
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
                $mail->addAddress($info['email'], $info['prenom'] . ' ' . $info['nom']);
                $mail->addCustomHeader('X-MJ-TrackClicks', '0');
                $mail->addCustomHeader('X-MJ-TrackOpens',  '0');
                $mail->isHTML(true);
                $mail->Subject = '[TERRACOOP] ✅ Demande de véhicule confirmée — ' . $info['immatriculation'];
                $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
  <div style="background:#28a745;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:1.15em;">✅ Votre demande de véhicule est confirmée</h2>
  </div>
  <div style="padding:24px;">
    <p style="margin:0 0 16px;">Bonjour <strong>' . htmlspecialchars($info['prenom']) . '</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">Votre demande a été <strong style="color:#28a745;">validée</strong> par <strong>' . htmlspecialchars($info['nom_validateur'] ?? 'votre manager') . '</strong>. Voici le véhicule qui vous a été attribué :</p>
    <table style="width:100%;border-collapse:collapse;font-size:.93em;margin-bottom:20px;">
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;width:42%;border-bottom:1px solid #dee2e6;">Véhicule</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;"><strong>' . htmlspecialchars($info['marque'] . ' ' . $info['modele']) . '</strong></td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Immatriculation</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;"><strong>' . htmlspecialchars($info['immatriculation']) . '</strong></td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Carburant</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . htmlspecialchars($info['type_carburant']) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Destination</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . htmlspecialchars($info['destination'] ?: '—') . '</td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Départ</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . $debut_fmt . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;">Retour prévu</td><td style="padding:8px 12px;">' . $fin_fmt . '</td></tr>
    </table>
    <div style="background:#fff3cd;border:1px solid #ffeeba;border-radius:8px;padding:16px 18px;margin-bottom:20px;">
      <p style="margin:0 0 10px;font-weight:700;color:#856404;font-size:.95em;">⚠️ Actions obligatoires au départ et au retour</p>
      <ul style="margin:0;padding-left:18px;color:#6c757d;font-size:.88em;line-height:1.9;">
        <li>📍 <strong>Avant de partir :</strong> notez le <strong>kilométrage au départ</strong> affiché sur le compteur</li>
        <li>📝 <strong>Notez toute observation</strong> sur l\'état du véhicule avant départ (rayures, carburant, etc.)</li>
        <li>🔑 <strong>Au retour :</strong> renseignez le <strong>kilométrage de retour</strong> dans l\'application</li>
        <li>⛽ Vérifiez et signalez le niveau de carburant à votre manager si nécessaire</li>
      </ul>
    </div>
    <div style="text-align:center;">
      <a href="' . $app_url . '" style="background:#0d3b8c;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:1em;">
        Accéder à mon espace
      </a>
    </div>
  </div>
  <div style="background:#f8f9fa;padding:12px 24px;text-align:center;color:#999;font-size:.8em;border-top:1px solid #dee2e6;">
    Gestion de Flotte TERRACOOP — message automatique
  </div>
</div>';
                $mail->AltBody = "Bonjour {$info['prenom']},\n\n"
                    . "Votre demande de véhicule a été VALIDÉE par " . ($info['nom_validateur'] ?? 'votre manager') . ".\n\n"
                    . "Véhicule     : {$info['marque']} {$info['modele']} ({$info['immatriculation']})\n"
                    . "Carburant    : {$info['type_carburant']}\n"
                    . "Destination  : " . ($info['destination'] ?: '—') . "\n"
                    . "Départ       : $debut_fmt\n"
                    . "Retour prévu : $fin_fmt\n\n"
                    . "⚠️ ACTIONS OBLIGATOIRES :\n"
                    . "- Avant le départ : notez le kilométrage au compteur\n"
                    . "- Notez l'état du véhicule et toute observation\n"
                    . "- Au retour : renseignez le kilométrage de retour dans l'application\n\n"
                    . "Accéder à l'application : $app_url\n\n"
                    . "Gestion de Flotte TERRACOOP";
                $mail->send();
            } catch (\PHPMailer\PHPMailer\Exception $e) {
                error_log('[manager.php] Erreur email confirmation ' . $info['email'] . ' : ' . $mail->ErrorInfo);
            }
        }

        // ── Email d'information au superviseur du demandeur (si défini) ─────
        $stmt_sup = $conn->prepare("
            SELECT sup.email AS sup_email, sup.prenom AS sup_prenom, sup.nom AS sup_nom,
                   emp.prenom AS emp_prenom, emp.nom AS emp_nom
            FROM reservations r
            JOIN employes emp ON emp.id_employe = r.id_employe
            JOIN employes sup ON sup.id_employe = emp.id_superviseur
            WHERE r.id_reservation = ?
        ");
        $stmt_sup->bind_param("i", $id);
        $stmt_sup->execute();
        $sup = $stmt_sup->get_result()->fetch_assoc();
        $stmt_sup->close();

        if (email_actif('superviseur') && $sup && !empty($sup['sup_email']) && $info) {
            require_once __DIR__ . '/vendor/autoload.php';
            $debut_fmt = date('d/m/Y à H:i', strtotime($info['date_debut_resa']));
            $fin_fmt   = date('d/m/Y à H:i', strtotime($info['date_fin_resa']));
            $date_aff  = date('d/m/Y');
            $app_url   = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/index.php';
            $demandeur = $info['prenom'] . ' ' . $info['nom'];
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
                $mail->addAddress($sup['sup_email'], $sup['sup_prenom'] . ' ' . $sup['sup_nom']);
                $mail->addCustomHeader('X-MJ-TrackClicks', '0');
                $mail->addCustomHeader('X-MJ-TrackOpens',  '0');
                $mail->isHTML(true);
                $mail->Subject = '[TERRACOOP] ℹ️ Véhicule affecté à ' . $demandeur . ' — ' . $info['immatriculation'];
                $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
  <div style="background:#0d3b8c;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:1.15em;">ℹ️ Affectation d\'un véhicule à un membre de votre équipe</h2>
  </div>
  <div style="padding:24px;">
    <p style="margin:0 0 16px;">Bonjour <strong>' . htmlspecialchars($sup['sup_prenom']) . '</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">Pour information, un véhicule vient d\'être affecté à <strong>' . htmlspecialchars($demandeur) . '</strong> suite à sa demande de réservation. Voici le détail :</p>
    <table style="width:100%;border-collapse:collapse;font-size:.93em;margin-bottom:20px;">
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;width:42%;border-bottom:1px solid #dee2e6;">Collaborateur</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;"><strong>' . htmlspecialchars($demandeur) . '</strong></td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Véhicule</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;"><strong>' . htmlspecialchars($info['marque'] . ' ' . $info['modele']) . '</strong></td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Immatriculation</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;"><strong>' . htmlspecialchars($info['immatriculation']) . '</strong></td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Motif</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . htmlspecialchars($info['motif'] ?: '—') . '</td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Destination</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . htmlspecialchars($info['destination'] ?: '—') . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Départ</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . $debut_fmt . '</td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Retour prévu</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . $fin_fmt . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;">Date d\'affectation</td><td style="padding:8px 12px;">' . $date_aff . '</td></tr>
    </table>
    <p style="margin:0 0 20px;color:#6c757d;font-size:.88em;">
      Ce message est purement informatif — aucune action n\'est requise de votre part.
    </p>
    <div style="text-align:center;">
      <a href="' . $app_url . '" style="background:#0d3b8c;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:1em;">
        Accéder à l\'application
      </a>
    </div>
  </div>
  <div style="background:#f8f9fa;padding:12px 24px;text-align:center;color:#999;font-size:.8em;border-top:1px solid #dee2e6;">
    Gestion de Flotte TERRACOOP — message automatique
  </div>
</div>';
                $mail->AltBody = "Bonjour {$sup['sup_prenom']},\n\n"
                    . "Pour information, un véhicule a été affecté à $demandeur suite à sa demande de réservation.\n\n"
                    . "Collaborateur      : $demandeur\n"
                    . "Véhicule           : {$info['marque']} {$info['modele']} ({$info['immatriculation']})\n"
                    . "Motif              : " . ($info['motif'] ?: '—') . "\n"
                    . "Destination        : " . ($info['destination'] ?: '—') . "\n"
                    . "Départ             : $debut_fmt\n"
                    . "Retour prévu       : $fin_fmt\n"
                    . "Date d'affectation : $date_aff\n\n"
                    . "Ce message est purement informatif.\n\n"
                    . "Gestion de Flotte TERRACOOP";
                $mail->send();
            } catch (\PHPMailer\PHPMailer\Exception $e) {
                error_log('[manager.php] Erreur email superviseur ' . $sup['sup_email'] . ' : ' . $mail->ErrorInfo);
            }
        }

        header('Location: manager.php?message=' . urlencode('✅ Demande validée et véhicule attribué.') . '&type=success');
        exit();

    } elseif ($action === 'refuser') {
        $motif_refus = trim($_POST['motif_refus'] ?? '');
        $stmt = $conn->prepare("UPDATE reservations SET statut_resa='Refusée', motif_refus=? WHERE id_reservation=?");
        $stmt->bind_param("si", $motif_refus, $id);
        $stmt->execute();
        $stmt->close();

        // ── Email de refus à l'employé ─────────────────────────────────────
        $stmt_ref = $conn->prepare("
            SELECT r.date_debut_resa, r.date_fin_resa, r.destination, r.motif,
                   e.email, e.prenom, e.nom
            FROM reservations r
            JOIN employes e ON e.id_employe = r.id_employe
            WHERE r.id_reservation = ?
        ");
        $stmt_ref->bind_param("i", $id);
        $stmt_ref->execute();
        $info_ref = $stmt_ref->get_result()->fetch_assoc();
        $stmt_ref->close();

        if (email_actif('refus_resa') && $info_ref && !empty($info_ref['email'])) {
            require_once __DIR__ . '/vendor/autoload.php';
            $debut_fmt = date('d/m/Y à H:i', strtotime($info_ref['date_debut_resa']));
            $fin_fmt   = date('d/m/Y à H:i', strtotime($info_ref['date_fin_resa']));
            $app_url   = 'https://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/index.php';
            $motif_ligne = $motif_refus
                ? '<tr><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Motif du refus</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;color:#721c24;">' . htmlspecialchars($motif_refus) . '</td></tr>'
                : '';
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
                $mail->addAddress($info_ref['email'], $info_ref['prenom'] . ' ' . $info_ref['nom']);
                $mail->addCustomHeader('X-MJ-TrackClicks', '0');
                $mail->addCustomHeader('X-MJ-TrackOpens',  '0');
                $mail->isHTML(true);
                $mail->Subject = '[TERRACOOP] ❌ Demande de véhicule refusée';
                $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
  <div style="background:#dc3545;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:1.15em;">❌ Votre demande de véhicule a été refusée</h2>
  </div>
  <div style="padding:24px;">
    <p style="margin:0 0 16px;">Bonjour <strong>' . htmlspecialchars($info_ref['prenom']) . '</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">Votre demande de véhicule n\'a pas pu être accordée. Voici le récapitulatif :</p>
    <table style="width:100%;border-collapse:collapse;font-size:.93em;margin-bottom:20px;">
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;width:42%;border-bottom:1px solid #dee2e6;">Destination</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . htmlspecialchars($info_ref['destination'] ?: '—') . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Départ demandé</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . $debut_fmt . '</td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;border-bottom:1px solid #dee2e6;">Retour demandé</td><td style="padding:8px 12px;border-bottom:1px solid #dee2e6;">' . $fin_fmt . '</td></tr>
      ' . $motif_ligne . '
    </table>
    <p style="margin:0 0 20px;color:#6c757d;font-size:.88em;">
      Si vous avez des questions, rapprochez-vous de votre manager. Vous pouvez soumettre une nouvelle demande si nécessaire.
    </p>
    <div style="text-align:center;">
      <a href="' . $app_url . '" style="background:#0d3b8c;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:1em;">
        Faire une nouvelle demande
      </a>
    </div>
  </div>
  <div style="background:#f8f9fa;padding:12px 24px;text-align:center;color:#999;font-size:.8em;border-top:1px solid #dee2e6;">
    Gestion de Flotte TERRACOOP — message automatique
  </div>
</div>';
                $mail->AltBody = "Bonjour {$info_ref['prenom']},\n\n"
                    . "Votre demande de véhicule a été REFUSÉE.\n\n"
                    . "Destination  : " . ($info_ref['destination'] ?: '—') . "\n"
                    . "Départ       : $debut_fmt\n"
                    . "Retour       : $fin_fmt\n"
                    . ($motif_refus ? "Motif refus  : $motif_refus\n" : '')
                    . "\nPour toute question, rapprochez-vous de votre manager.\n\n"
                    . "Gestion de Flotte TERRACOOP";
                $mail->send();
            } catch (\PHPMailer\PHPMailer\Exception $e) {
                error_log('[manager.php] Erreur email refus ' . $info_ref['email'] . ' : ' . $mail->ErrorInfo);
            }
        }

        header('Location: manager.php?message=' . urlencode('❌ Demande refusée.') . '&type=error');
        exit();

    } elseif ($action === 'depart') {
        // Le manager enregistre la prise en charge (départ) à la place de l'employé
        $km_debut = (int)($_POST['km_debut'] ?? 0);
        $comm     = trim($_POST['commentaire_depart'] ?? '');
        if ($km_debut <= 0) {
            header('Location: manager.php?message=' . urlencode('⚠️ Kilométrage de départ invalide.') . '&type=warning');
            exit();
        }
        $stmt = $conn->prepare("UPDATE reservations SET km_debut=?, date_depart_reel=NOW(), commentaire_depart=?, statut_resa='En cours' WHERE id_reservation=? AND statut_resa='Validée'");
        $stmt->bind_param("isi", $km_debut, $comm, $id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        $msg = $ok ? '🚗 Départ enregistré.' : '⚠️ Action impossible (la demande n\'est pas au statut « Validée »).';
        header('Location: manager.php?message=' . urlencode($msg) . '&type=' . ($ok ? 'success' : 'warning'));
        exit();

    } elseif ($action === 'retour') {
        // Le manager enregistre la restitution (retour) à la place de l'employé
        $km_fin = (int)($_POST['km_fin'] ?? 0);
        $comm   = trim($_POST['commentaire_retour'] ?? '');
        if ($km_fin <= 0) {
            header('Location: manager.php?message=' . urlencode('⚠️ Kilométrage de retour invalide.') . '&type=warning');
            exit();
        }
        $stmt = $conn->prepare("UPDATE reservations SET km_fin=?, date_retour_reel=NOW(), commentaire_retour=?, statut_resa='Terminée' WHERE id_reservation=? AND statut_resa='En cours'");
        $stmt->bind_param("isi", $km_fin, $comm, $id);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();

        if ($ok) {
            // Met à jour le compteur du véhicule (sans jamais le faire reculer)
            $g = $conn->prepare("SELECT id_vehicule FROM reservations WHERE id_reservation=?");
            $g->bind_param("i", $id);
            $g->execute();
            $gr = $g->get_result()->fetch_assoc();
            $g->close();
            if ($gr && $gr['id_vehicule']) {
                $uk = $conn->prepare("UPDATE vehicules SET kilometrage=? WHERE id_vehicule=? AND kilometrage <= ?");
                $uk->bind_param("iii", $km_fin, $gr['id_vehicule'], $km_fin);
                $uk->execute();
                $uk->close();
            }
        }
        $msg = $ok ? '✅ Restitution enregistrée.' : '⚠️ Action impossible (la demande n\'est pas au statut « En cours »).';
        header('Location: manager.php?message=' . urlencode($msg) . '&type=' . ($ok ? 'success' : 'warning'));
        exit();
    }
}

include 'includes/header.php';
if (!$is_manager) { header('Location: index.php'); exit(); }

// ================================================================
// ÉTABLISSEMENT DU MANAGER (Terracoop = 1 voit tout ; NULL = repli voit tout)
// ================================================================
$mgr_etab = null;
$stmt_me = $conn->prepare("SELECT id_etablissement FROM employes WHERE id_employe = ?");
$stmt_me->bind_param("i", $_SESSION['user_id']);
$stmt_me->execute();
$me = $stmt_me->get_result()->fetch_assoc();
$stmt_me->close();
if ($me && $me['id_etablissement'] !== null) $mgr_etab = (int)$me['id_etablissement'];
$voit_tout = ($mgr_etab === 1); // accès complet réservé à Terracoop ; autres sociétés / NULL = restreint
// Établissements gérés par ce manager : le sien + les délégués (ex. RFL géré par Vivea), depuis config.php
$etabs_geres = !empty($USER_ETAB_GERES) ? $USER_ETAB_GERES : ($mgr_etab !== null ? [$mgr_etab] : []);
$etabs_in    = $etabs_geres ? implode(',', array_map('intval', $etabs_geres)) : '0';

// ================================================================
// FILTRES HISTORIQUE
// ================================================================
$search_kw   = trim($_GET['search'] ?? '');
$search_date = trim($_GET['date']   ?? '');
$filter_sql  = "";
$filter_params = [];
$filter_types  = "";

// Restriction par établissement (le sien + délégués) ; Terracoop voit tout
if (!$voit_tout) {
    $filter_sql .= " AND e.id_etablissement IN ($etabs_in)";
}

// Recherche / statut / dates / établissement : filtrage désormais CÔTÉ CLIENT (voir l'historique plus bas).
// $filter_sql ne conserve que la restriction d'établissement (sécurité serveur).

// Message flash
$message      = isset($_GET['message']) ? urldecode($_GET['message']) : '';
$allowed_msg_types = ['success', 'error', 'info', 'warning'];
$message_type = in_array($_GET['type'] ?? '', $allowed_msg_types) ? $_GET['type'] : 'success';
if ($message) echo '<div class="message '.htmlspecialchars($message_type).'">'.htmlspecialchars($message).'</div>';

// ================================================================
// DONNÉES
// ================================================================

// Fonction : véhicules disponibles pour une période donnée
// Communaux non déjà réservés + Attitrés dont le proprio est en congé sur toute la période
function getVehiculesDispos($conn, $date_debut, $date_fin) {
    // Tous les véhicules actifs LIBRES sur le créneau (communaux + attitrés).
    // owner_en_conge : le propriétaire (si attitré) est-il en congé sur toute la période ?
    $stmt = $conn->prepare("
        SELECT v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal,
               COALESCE(et.nom, 'Sans établissement') AS etab_nom,
               owner.prenom AS o_prenom, owner.nom AS o_nom,
               (SELECT 1 FROM affectations_fixes af2
                  JOIN conges c ON af2.id_employe = c.id_employe
                  WHERE af2.id_vehicule = v.id_vehicule
                  AND c.date_debut <= DATE(?) AND c.date_fin >= DATE(?) LIMIT 1) AS owner_en_conge
        FROM vehicules v
        LEFT JOIN etablissements et    ON et.id_etablissement = v.id_etablissement
        LEFT JOIN affectations_fixes af ON af.id_vehicule      = v.id_vehicule
        LEFT JOIN employes owner        ON owner.id_employe     = af.id_employe
        WHERE v.actif = 1
          AND NOT EXISTS (
              SELECT 1 FROM reservations r
              WHERE r.id_vehicule = v.id_vehicule
              AND r.statut_resa NOT IN ('Annulée','Refusée','Terminée')
              AND r.date_debut_resa < ? AND r.date_fin_resa > ?
          )
        ORDER BY etab_nom, v.est_communal DESC, v.marque
    ");
    $stmt->bind_param("ssss", $date_fin, $date_debut, $date_fin, $date_debut);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($v = $res->fetch_assoc()) {
        $v['proprio'] = trim(($v['o_prenom'] ?? '') . ' ' . ($v['o_nom'] ?? ''));
        // Prêt exceptionnel : véhicule attitré dont le propriétaire n'est PAS en congé
        $v['pret_exceptionnel'] = ((int)$v['est_communal'] === 0 && empty($v['owner_en_conge']) && $v['proprio'] !== '') ? 1 : 0;
        $list[] = $v;
    }
    return $list;
}

// Liste de tous les employés (pour le champ « Autorisé par »)
$employes_autorisateurs = [];
$res_emp = $conn->query("SELECT id_employe, prenom, nom, matricule FROM employes ORDER BY nom, prenom");
if ($res_emp) {
    while ($e = $res_emp->fetch_assoc()) {
        $label = trim($e['prenom'] . ' ' . $e['nom']) . ($e['matricule'] ? ' (' . $e['matricule'] . ')' : '');
        $employes_autorisateurs[] = ['id' => (int)$e['id_employe'], 'label' => $label];
    }
}

// Demandes en attente (filtrées par établissement sauf Terracoop / manager sans établissement)
$sql_demandes = "SELECT r.id_reservation, r.date_debut_resa, r.date_fin_resa, r.motif, r.destination, r.date_demande, e.nom, e.prenom, e.matricule FROM reservations r JOIN employes e ON r.id_employe=e.id_employe WHERE r.statut_resa='En attente'";
if (!$voit_tout) $sql_demandes .= " AND e.id_etablissement IN ($etabs_in)";
$sql_demandes .= " ORDER BY r.date_demande ASC";
$stmt_d = $conn->prepare($sql_demandes);
$stmt_d->execute();
$demandes = $stmt_d->get_result();

// Établissements (pour le filtre de l'historique)
$etabs_hist = [];
$re_eh = $conn->query("SELECT id_etablissement, nom FROM etablissements ORDER BY nom");
if ($re_eh) while ($x = $re_eh->fetch_assoc()) $etabs_hist[] = $x;

// Historique — tri : En cours → Validée → Terminée → Refusée → Annulée, puis date la plus récente
$sql_hist = "SELECT r.*, e.nom, e.prenom, e.matricule, et.nom AS emp_etab,
                    v.marque, v.modele, v.immatriculation, v.kilometrage AS km_actuel
             FROM reservations r
             JOIN employes e ON r.id_employe = e.id_employe
             LEFT JOIN etablissements et ON et.id_etablissement = e.id_etablissement
             LEFT JOIN vehicules v ON r.id_vehicule = v.id_vehicule
             WHERE r.statut_resa != 'En attente' $filter_sql
             ORDER BY FIELD(r.statut_resa, 'En cours', 'Validée', 'Terminée', 'Refusée', 'Annulée'), r.date_debut_resa DESC
             LIMIT 1000";
$stmt_hist = $conn->prepare($sql_hist);
if (!empty($filter_params)) $stmt_hist->bind_param($filter_types, ...$filter_params);
$stmt_hist->execute();
$historique = $stmt_hist->get_result();

// Répartition : actives (En cours + Validée) affichées par défaut ; le reste dans l'historique repliable
$hist_actives = []; $hist_archive = [];
while ($row = $historique->fetch_assoc()) {
    if (in_array($row['statut_resa'], ['En cours', 'Validée'], true)) $hist_actives[] = $row;
    else $hist_archive[] = $row;
}

// Véhicules à récupérer (congé long ce mois) : bandeau RÉSERVÉ aux managers Terracoop
// (c'est Terracoop qui récupère les véhicules, quel que soit l'établissement du salarié)
$vehicules_recup = [];
if ($IS_TERRACOOP_MANAGER) {
    $cond_conge_mois = "DATEDIFF(c.date_fin, c.date_debut) >= 7 AND YEAR(c.date_debut) = YEAR(CURDATE()) AND MONTH(c.date_debut) = MONTH(CURDATE())";
    $sql_recup = "
        SELECT e.nom, e.prenom, v.marque, v.modele, v.immatriculation,
               (SELECT c.date_debut FROM conges c WHERE c.id_employe = e.id_employe AND $cond_conge_mois ORDER BY c.date_debut ASC LIMIT 1) AS conge_debut,
               (SELECT c.date_fin   FROM conges c WHERE c.id_employe = e.id_employe AND $cond_conge_mois ORDER BY c.date_debut ASC LIMIT 1) AS conge_fin
        FROM affectations_fixes af
        JOIN employes e  ON e.id_employe  = af.id_employe
        JOIN vehicules v ON v.id_vehicule = af.id_vehicule
        WHERE EXISTS (SELECT 1 FROM conges c WHERE c.id_employe = e.id_employe AND $cond_conge_mois)
        ORDER BY conge_debut
    ";
    $recup_res = $conn->query($sql_recup);
    $vehicules_recup = $recup_res ? $recup_res->fetch_all(MYSQLI_ASSOC) : [];
}

// Rendu d'une ligne (réutilisé par le tableau actif ET l'historique repliable)
function renderHistRow($row) {
    ob_start(); ?>
        <tr class="<?php echo in_array($row['statut_resa'], ['Annulée','Refusée']) ? 'archived' : ''; ?>"
            data-statut="<?php echo htmlspecialchars($row['statut_resa']); ?>"
            data-date="<?php echo date('Y-m-d', strtotime($row['date_debut_resa'])); ?>"
            data-etab="<?php echo htmlspecialchars($row['emp_etab'] ?: '(sans)'); ?>"
            data-search="<?php echo strtolower(htmlspecialchars($row['nom'].' '.$row['prenom'].' '.$row['matricule'].' '.($row['destination']??'').' '.($row['motif']??'').' '.($row['marque']??'').' '.($row['modele']??'').' '.($row['immatriculation']??'').' '.$row['statut_resa'])); ?>">
            <td data-label="Statut">
                <span class="status-tag <?php echo strtolower(str_replace([' ','é','è'],['-','e','e'],$row['statut_resa'])); ?>">
                    <?php echo htmlspecialchars($row['statut_resa']); ?>
                </span>
            </td>
            <td data-label="Employé">
                <strong><?php echo htmlspecialchars($row['nom'].' '.$row['prenom']); ?></strong><br>
                <small class="text-muted"><?php echo htmlspecialchars($row['matricule']); ?></small>
            </td>
            <td data-label="Véhicule">
                <?php if (!empty($row['marque'])): ?>
                    <?php echo htmlspecialchars($row['marque'].' '.$row['modele']); ?><br>
                    <small class="text-muted"><?php echo htmlspecialchars($row['immatriculation']); ?></small>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td data-label="Période" style="white-space:nowrap;">
                <?php echo date('d/m H:i', strtotime($row['date_debut_resa'])); ?> →<br>
                <?php echo date('d/m H:i', strtotime($row['date_fin_resa'])); ?>
            </td>
            <td data-label="Départ réel">
                <?php if (!empty($row['date_depart_reel'])): ?>
                    <?php echo date('d/m H:i', strtotime($row['date_depart_reel'])); ?><br>
                    <small style="color:#0056b3;font-weight:600;"><?php echo number_format($row['km_debut'],0,',',' '); ?> km</small>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td data-label="Retour réel">
                <?php if (!empty($row['date_retour_reel'])): ?>
                    <?php echo date('d/m H:i', strtotime($row['date_retour_reel'])); ?><br>
                    <small style="color:#0056b3;font-weight:600;"><?php echo number_format($row['km_fin'],0,',',' '); ?> km</small>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td data-label="Destination">
                <strong><?php echo htmlspecialchars($row['destination'] ?: '—'); ?></strong>
                <?php if (!empty($row['motif'])): ?><br><small class="text-muted"><?php echo htmlspecialchars($row['motif']); ?></small><?php endif; ?>
                <?php if (!empty($row['motif_refus'])): ?>
                    <div style="margin-top:4px; background:#f8d7da; padding:4px 8px; border-radius:4px; font-size:.8em; border:1px solid #f5c6cb;">
                        <strong>Motif refus :</strong> <?php echo htmlspecialchars($row['motif_refus']); ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($row['commentaire_depart'])): ?>
                    <div style="margin-top:4px; background:#fff3cd; padding:3px 7px; border-radius:4px; font-size:.8em; border:1px solid #ffeeba;"><?php echo htmlspecialchars($row['commentaire_depart']); ?></div>
                <?php endif; ?>
                <?php if (!empty($row['commentaire_retour'])): ?>
                    <div style="margin-top:4px; background:#f8d7da; padding:3px 7px; border-radius:4px; font-size:.8em; border:1px solid #f5c6cb;"><?php echo htmlspecialchars($row['commentaire_retour']); ?></div>
                <?php endif; ?>
            </td>
            <td data-label="Actions">
                <?php if ($row['statut_resa'] === 'Validée'): ?>
                    <form method="POST" action="manager.php" style="display:flex; flex-direction:column; gap:4px; margin:0;">
                        <input type="hidden" name="action_resa"    value="depart">
                        <input type="hidden" name="id_reservation" value="<?php echo $row['id_reservation']; ?>">
                        <input type="hidden" name="csrf_token"     value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="number" name="km_debut" required
                               value="<?php echo (int)$row['km_actuel']; ?>" min="<?php echo (int)$row['km_actuel']; ?>"
                               style="width:100px; margin:0; padding:5px; font-size:.85em;" title="Km départ">
                        <button type="submit" class="action-btn charge-btn" style="margin:0; padding:5px 8px; font-size:.82em;">🚗 Départ</button>
                    </form>
                <?php elseif ($row['statut_resa'] === 'En cours'): ?>
                    <form method="POST" action="manager.php" style="display:flex; flex-direction:column; gap:4px; margin:0;">
                        <input type="hidden" name="action_resa"    value="retour">
                        <input type="hidden" name="id_reservation" value="<?php echo $row['id_reservation']; ?>">
                        <input type="hidden" name="csrf_token"     value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="number" name="km_fin" required
                               value="<?php echo (int)$row['km_debut']; ?>" min="<?php echo (int)$row['km_debut']; ?>"
                               style="width:100px; margin:0; padding:5px; font-size:.85em;" title="Km retour">
                        <button type="submit" class="action-btn return-btn" style="margin:0; padding:5px 8px; font-size:.82em;">📦 Retour</button>
                    </form>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php return ob_get_clean();
}
?>

<h2>Gestion des demandes</h2>

<?php if (!empty($vehicules_recup)):
    // Clignote tout départ imminent : dans les 7 prochains jours (aujourd'hui inclus)
    $today_recup   = date('Y-m-d');
    $fenetre_jours = 9;
?>
<style>
@keyframes blinkRecup { 0%, 100% { opacity: 1; } 50% { opacity: .4; } }
#bandeauRecup li.recup-urgent {
    animation: blinkRecup 1.1s ease-in-out infinite;
    list-style: none; margin-left: -18px;
    background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px;
    padding: 5px 10px; color: #721c24; font-weight: 700;
}

/* ── Pagination (aligné sur parc.php) ── */
.pagination-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 16px;
    padding: 12px 16px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
}
.pag-per-page {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: .83rem;
    color: #6c757d;
    white-space: nowrap;
    flex-shrink: 0;
}
.pag-per-page select {
    padding: 5px 8px;
    border: 1.5px solid #dee2e6;
    border-radius: 6px;
    font-size: .83rem;
    cursor: pointer;
    background: #fff;
    color: #495057;
}
.pag-info {
    font-size: .83rem;
    color: #6c757d;
    white-space: nowrap;
    flex-shrink: 0;
}
.pag-buttons {
    display: flex;
    align-items: center;
    gap: 3px;
    flex-shrink: 0;
}
.pag-btn {
    min-width: 34px;
    height: 32px;
    padding: 0 10px;
    border: 1.5px solid #dee2e6;
    border-radius: 6px;
    background: #fff;
    color: #495057;
    font-size: .83rem;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s, border-color .15s, color .15s;
    white-space: nowrap;
    line-height: 1;
}
.pag-btn:hover { background: #e8f0fe; border-color: #007bff; color: #007bff; }
.pag-btn.pag-active { background: #007bff !important; border-color: #007bff !important; color: #fff !important; font-weight: 700; cursor: default; }
.pag-btn.pag-active:hover { background: #007bff; }
.pag-dots { padding: 0 2px; color: #adb5bd; font-size: .85rem; line-height: 32px; }

@media (max-width: 767px) {
    .pagination-bar {
        flex-direction: column;
        align-items: center;
        gap: 10px;
        padding: 12px;
    }
    .pag-per-page { display: none; }
    .pag-buttons { flex-wrap: wrap; justify-content: center; gap: 4px; }
    .pag-btn { min-width: 38px; height: 38px; font-size: .88rem; }
    .pag-dots { line-height: 38px; }
}

/* ── Recherche véhicule (attribution d'une demande) ── */
.veh-search { position: relative; margin-bottom: 6px; }
.veh-search-input {
    width: 100%;
    box-sizing: border-box;
    padding: 8px;
    border: 1.5px solid #dee2e6;
    border-radius: 6px;
    font-size: .9em;
}
.veh-search-input:focus { border-color: #007bff; outline: none; }
.veh-options-list {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 220px;
    overflow-y: auto;
    background: #fff;
    border: 1px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 6px 6px;
    z-index: 20;
    box-shadow: 0 4px 10px rgba(0,0,0,.08);
}
.veh-options-list.show { display: block; }
.veh-option-item { padding: 8px 10px; font-size: .88em; cursor: pointer; border-bottom: 1px solid #f1f3f5; }
.veh-option-item:last-child { border-bottom: none; }
.veh-option-item:hover { background: #e8f0fe; color: #007bff; }
.veh-option-item small { display: block; color: #adb5bd; font-size: .82em; }
.veh-no-result { padding: 10px; color: #adb5bd; font-style: italic; text-align: center; font-size: .85em; }
</style>
<div id="bandeauRecup" style="background:#fff3cd; border:1px solid #ffeeba; border-radius:8px; padding:12px 16px; margin-bottom:18px;">
    <strong style="color:#856404;">🏖️ <?php echo count($vehicules_recup); ?> véhicule(s) à récupérer</strong>
    <span style="color:#856404; font-size:.9em;">— propriétaire en congé long :</span>
    <ul style="margin:8px 0 0; padding-left:20px; color:#856404; font-size:.88em; line-height:1.8;">
        <?php foreach ($vehicules_recup as $r):
            $jours  = (!empty($r['conge_debut'])) ? (int)floor((strtotime($r['conge_debut']) - strtotime($today_recup)) / 86400) : null;
            $is_urg = ($jours !== null && $jours >= 0 && $jours <= $fenetre_jours);
        ?>
            <li<?php echo $is_urg ? ' class="recup-urgent"' : ''; ?>>
                <?php if ($is_urg): ?>🔴 <?php endif; ?>
                <strong><?php echo htmlspecialchars($r['prenom'].' '.$r['nom']); ?></strong>
                — <?php echo htmlspecialchars($r['marque'].' '.$r['modele'].' ('.$r['immatriculation'].')'); ?>
                <?php if (!empty($r['conge_debut'])): ?>
                    · du <?php echo date('d/m/Y', strtotime($r['conge_debut'])); ?> au <?php echo date('d/m/Y', strtotime($r['conge_fin'])); ?>
                    <?php if ($is_urg && $jours !== null): ?>
                        — <?php echo $jours <= 0 ? "part aujourd'hui !" : "part dans $jours jour" . ($jours > 1 ? 's' : '') . " !"; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ================================================================ -->
<!-- DEMANDES EN ATTENTE                                               -->
<!-- ================================================================ -->
<div style="display:flex; align-items:center; gap:12px; margin-bottom:14px; flex-wrap:wrap;">
    <h3 style="margin:0;">Demandes en attente</h3>
    <?php if ($demandes->num_rows > 0): ?>
        <span class="nav-badge" style="position:static; font-size:.85em; padding:3px 10px; border-radius:20px;"><?php echo $demandes->num_rows; ?></span>
    <?php endif; ?>
    <input type="text" placeholder="Filtrer par nom, matricule, destination…" oninput="filtrerDemandes(this.value)"
           style="margin-left:auto; padding:8px 12px; border:1.5px solid #dee2e6; border-radius:6px; min-width:260px; font-size:.9em;">
</div>
<script>
function filtrerDemandes(kw) {
    kw = kw.toLowerCase();
    document.querySelectorAll('#tableDemandes tbody tr').forEach(tr => {
        tr.style.display = !kw || tr.dataset.search.includes(kw) ? '' : 'none';
    });
}
</script>

<?php if ($demandes->num_rows === 0): ?>
    <p class="text-muted" style="font-style:italic; margin-bottom:30px;">Aucune demande en attente.</p>
<?php else: ?>
<datalist id="liste_autorisateurs">
    <?php foreach ($employes_autorisateurs as $ea): ?>
        <option data-id="<?php echo $ea['id']; ?>" value="<?php echo htmlspecialchars($ea['label']); ?>"></option>
    <?php endforeach; ?>
</datalist>
<script>
const AUTORISATEURS = <?php echo json_encode(array_column($employes_autorisateurs, 'id', 'label'), JSON_UNESCAPED_UNICODE); ?>;
// Résout le nom tapé vers l'id employé et le place dans le champ caché du même formulaire
function resoudreAutorisateur(input) {
    const id = AUTORISATEURS[input.value] || '';
    const hidden = input.parentElement.querySelector('.autorise-id');
    if (hidden) hidden.value = id;
    input.setCustomValidity(id ? '' : 'Sélectionnez une personne dans la liste.');
}
// Bloque la validation si aucune personne valide n'est sélectionnée
function verifierAutorisateur(form) {
    const input  = form.querySelector('.autorise-input');
    const hidden = form.querySelector('.autorise-id');
    if (!hidden.value || !(input.value in AUTORISATEURS)) {
        input.setCustomValidity('Veuillez sélectionner la personne ayant autorisé l\'attribution.');
        input.reportValidity();
        return false;
    }
    return true;
}
// Affiche l'avertissement + champ motif quand le véhicule choisi est un prêt exceptionnel
function majPretExceptionnel(sel) {
    const opt  = sel.options[sel.selectedIndex];
    const warn = sel.form.querySelector('.pret-warn');
    if (!warn) return;
    if (opt && opt.dataset.exceptionnel === '1') {
        warn.querySelector('.pret-proprio').textContent = opt.dataset.proprio || 'un employé';
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}
// Validation : véhicule sélectionné, contrôle "autorisé par", puis confirmation si prêt exceptionnel
function avantValidation(form) {
    const sel = form.querySelector('select[name="id_vehicule"]');
    if (!sel.value) {
        const input = form.querySelector('.veh-search-input');
        if (input) { input.setCustomValidity('Sélectionnez un véhicule dans la liste.'); input.reportValidity(); }
        else { sel.reportValidity(); }
        return false;
    }
    if (!verifierAutorisateur(form)) return false;
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.exceptionnel === '1') {
        return confirm('Ce véhicule est attitré à ' + (opt.dataset.proprio || 'un employé') + '.\nConfirmer le prêt exceptionnel ?');
    }
    return true;
}

// Recherche véhicule (marque, modèle, immat, établissement) au-dessus du select réel (conservé caché)
function setupVehSearch(select) {
    const wrap = select.previousElementSibling;
    if (!wrap || !wrap.classList.contains('veh-search')) return;
    const input = wrap.querySelector('.veh-search-input');
    const list  = wrap.querySelector('.veh-options-list');

    const items = [];
    Array.from(select.children).forEach(node => {
        if (node.tagName === 'OPTGROUP') {
            Array.from(node.children).forEach(opt => items.push({ opt, group: node.label }));
        } else if (node.tagName === 'OPTION' && node.value) {
            items.push({ opt: node, group: null });
        }
    });

    function render(filter) {
        const f = (filter || '').toLowerCase();
        list.innerHTML = '';
        let found = false;
        items.forEach(({ opt, group }) => {
            const hay = (opt.textContent + ' ' + (group || '')).toLowerCase();
            if (!f || hay.includes(f)) {
                found = true;
                const div = document.createElement('div');
                div.className = 'veh-option-item';
                div.innerHTML = (group ? `<small>${group}</small>` : '') + opt.textContent.trim();
                div.onclick = () => {
                    input.value = opt.textContent.trim();
                    input.setCustomValidity('');
                    select.value = opt.value;
                    select.dispatchEvent(new Event('change'));
                    list.classList.remove('show');
                };
                list.appendChild(div);
            }
        });
        if (!found) list.innerHTML = '<div class="veh-no-result">Aucun véhicule trouvé</div>';
        list.classList.add('show');
    }

    input.addEventListener('input', () => { input.setCustomValidity(''); render(input.value); });
    input.addEventListener('focus', () => render(input.value));
    document.addEventListener('click', (e) => {
        if (!wrap.contains(e.target)) list.classList.remove('show');
    });
}
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.veh-select').forEach(setupVehSearch);
});
</script>
<table id="tableDemandes" style="margin-bottom:30px;">
    <thead>
        <tr>
            <th>Demandeur</th>
            <th>Période souhaitée</th>
            <th>Destination / Motif</th>
            <th style="min-width:240px;">Attribuer &amp; Valider</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($row = $demandes->fetch_assoc()): ?>
    <tr data-search="<?php echo strtolower(htmlspecialchars($row['nom'].' '.$row['prenom'].' '.$row['matricule'].' '.$row['destination'].' '.$row['motif'])); ?>">
        <td data-label="Demandeur">
            <strong><?php echo htmlspecialchars($row['prenom'].' '.$row['nom']); ?></strong><br>
            <small class="text-muted"><?php echo htmlspecialchars($row['matricule']); ?></small><br>
            <small class="text-muted">Reçue le <?php echo date('d/m/Y à H:i', strtotime($row['date_demande'])); ?></small>
        </td>
        <td data-label="Période">
            Du <strong><?php echo date('d/m/Y', strtotime($row['date_debut_resa'])); ?></strong> à <?php echo date('H:i', strtotime($row['date_debut_resa'])); ?><br>
            Au <strong><?php echo date('d/m/Y', strtotime($row['date_fin_resa'])); ?></strong> à <?php echo date('H:i', strtotime($row['date_fin_resa'])); ?>
        </td>
        <td data-label="Destination">
            <strong><?php echo htmlspecialchars($row['destination'] ?: '—'); ?></strong><br>
            <small class="text-muted"><?php echo htmlspecialchars($row['motif'] ?: ''); ?></small>
        </td>
        <td data-label="Actions">
            <form method="POST" action="manager.php" style="margin-bottom:8px;">
                <input type="hidden" name="action_resa"    value="valider">
                <input type="hidden" name="id_reservation" value="<?php echo $row['id_reservation']; ?>">
                <input type="hidden" name="csrf_token"     value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php $veh_dispo = getVehiculesDispos($conn, $row['date_debut_resa'], $row['date_fin_resa']); ?>
                <?php if (!empty($veh_dispo)): ?>
                <div class="veh-search">
                    <input type="text" class="veh-search-input" placeholder="Rechercher marque, modèle, immat…" autocomplete="off">
                    <div class="veh-options-list"></div>
                </div>
                <?php endif; ?>
                <select name="id_vehicule" required onchange="majPretExceptionnel(this)" class="veh-select"
                        style="<?php echo empty($veh_dispo) ? 'width:100%; margin-bottom:6px; padding:8px; border:1.5px solid #dee2e6; border-radius:6px; font-size:.9em;' : 'display:none;'; ?>">
                    <option value="">— Choisir un véhicule —</option>
                    <?php if (empty($veh_dispo)): ?>
                        <option disabled>Aucun véhicule disponible sur ce créneau</option>
                    <?php else:
                        $veh_normaux = array_filter($veh_dispo, fn($v) => !$v['pret_exceptionnel']);
                        $veh_except  = array_filter($veh_dispo, fn($v) =>  $v['pret_exceptionnel']);
                        $par_etab = [];
                        foreach ($veh_normaux as $v) { $par_etab[$v['etab_nom']][] = $v; }
                        foreach ($par_etab as $etab_lbl => $vehs): ?>
                            <optgroup label="🏢 <?php echo htmlspecialchars($etab_lbl); ?>">
                                <?php foreach ($vehs as $v): ?>
                                    <option value="<?php echo $v['id_vehicule']; ?>">
                                        <?php echo ($v['est_communal'] ? '[Communal] ' : '[Attitré] ').htmlspecialchars($v['marque'].' '.$v['modele'].' ('.$v['immatriculation'].')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                        <?php if (!empty($veh_except)): ?>
                            <optgroup label="⚠️ Prêt exceptionnel (véhicule attitré)">
                                <?php foreach ($veh_except as $v): ?>
                                    <option value="<?php echo $v['id_vehicule']; ?>"
                                            data-exceptionnel="1"
                                            data-proprio="<?php echo htmlspecialchars($v['proprio'], ENT_QUOTES); ?>">
                                        <?php echo htmlspecialchars($v['marque'].' '.$v['modele'].' ('.$v['immatriculation'].') — attitré à '.$v['proprio']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endif; ?>
                    <?php endif; ?>
                </select>
                <?php if (empty($veh_dispo)): ?>
                    <p style="color:#856404; font-size:.8em; margin:0 0 6px; background:#fff3cd; padding:5px 8px; border-radius:5px; border:1px solid #ffeeba;">
                        Aucun véhicule libre sur cette période. Vérifiez le parc ou refusez la demande.
                    </p>
                <?php endif; ?>
                <div class="pret-warn" style="display:none; margin:0 0 6px; background:#fff3cd; border:1px solid #ffeeba; border-radius:5px; padding:6px 8px; font-size:.8em; color:#856404;">
                    ⚠️ Véhicule attitré à <strong class="pret-proprio"></strong> — prêt exceptionnel.
                    <input type="text" name="motif_pret" placeholder="Motif du prêt (optionnel)"
                           style="width:100%; margin-top:4px; padding:5px; border:1px solid #ffeeba; border-radius:4px; font-size:.95em;">
                </div>
                <input type="text" list="liste_autorisateurs" class="autorise-input" required
                       placeholder="Autorisé par… (taper un nom)" autocomplete="off"
                       oninput="resoudreAutorisateur(this)"
                       style="width:100%; margin-bottom:6px; padding:8px; border:1.5px solid #dee2e6; border-radius:6px; font-size:.9em;">
                <input type="hidden" name="id_autorise_par" class="autorise-id" value="">
                <button type="submit" class="action-btn charge-btn" style="width:100%; margin:0;" onclick="return avantValidation(this.form)">Valider &amp; Attribuer</button>
            </form>
            <button type="button" class="action-btn cancel-btn" style="width:100%; margin:0;"
                    onclick="ouvrirModalRefus(<?php echo $row['id_reservation']; ?>)">Refuser</button>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Modal Refus -->
<div id="modalRefus" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:10px; padding:28px; max-width:480px; width:90%; box-shadow:0 8px 32px rgba(0,0,0,.2);">
        <h3 style="margin-top:0; color:#c0392b;">Refuser la demande</h3>
        <form method="POST" action="manager.php" id="formRefus">
            <input type="hidden" name="action_resa"    value="refuser">
            <input type="hidden" name="id_reservation" id="refus_id_reservation">
            <input type="hidden" name="csrf_token"     value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <label for="refus_motif" style="font-weight:600; display:block; margin-bottom:6px;">
                Motif du refus <small class="text-muted" style="font-weight:normal;">(optionnel)</small>
            </label>
            <textarea id="refus_motif" name="motif_refus" rows="4"
                      placeholder="Expliquez pourquoi la demande est refusée…"
                      style="width:100%; box-sizing:border-box; padding:10px; border:1.5px solid #dee2e6; border-radius:6px; font-size:.9em; resize:vertical;"></textarea>
            <div style="display:flex; gap:8px; margin-top:16px; justify-content:flex-end;">
                <button type="button" onclick="fermerModalRefus()"
                        style="padding:9px 18px; border:1.5px solid #6c757d; background:#fff; color:#6c757d; border-radius:6px; cursor:pointer;">Annuler</button>
                <button type="submit" class="action-btn cancel-btn" style="margin:0;">Confirmer le refus</button>
            </div>
        </form>
    </div>
</div>
<script>
function ouvrirModalRefus(idResa) {
    document.getElementById('refus_id_reservation').value = idResa;
    document.getElementById('refus_motif').value = '';
    const m = document.getElementById('modalRefus');
    m.style.display = 'flex';
    setTimeout(() => document.getElementById('refus_motif').focus(), 50);
}
function fermerModalRefus() {
    document.getElementById('modalRefus').style.display = 'none';
}
document.getElementById('modalRefus').addEventListener('click', function(e) {
    if (e.target === this) fermerModalRefus();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fermerModalRefus();
});
</script>

<hr>

<!-- ================================================================ -->
<!-- HISTORIQUE                                                         -->
<!-- ================================================================ -->
<h3>Réservations actives</h3>
<table class="historique-global-table" style="margin-bottom:26px;">
    <thead>
        <tr><th>Statut</th><th>Employé</th><th>Véhicule</th><th>Période</th><th>Départ réel</th><th>Retour réel</th><th>Destination</th><th>Actions</th></tr>
    </thead>
    <tbody>
        <?php if ($hist_actives): foreach ($hist_actives as $row) echo renderHistRow($row); ?>
        <?php else: ?>
            <tr><td colspan="8" style="text-align:center; color:#adb5bd; font-style:italic; padding:20px;">Aucune réservation en cours ou validée.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<hr>

<button type="button" id="btnArchive" onclick="toggleArchive()"
        style="background:#f8f9fa; border:1.5px solid #dee2e6; border-radius:8px; padding:10px 18px; cursor:pointer; font-size:.92em; color:#495057; display:inline-flex; align-items:center; gap:8px; margin:6px 0 4px;">
    <span id="iconArchive" style="font-size:.8em;">▶</span>
    Historique — terminées / refusées / annulées
    <span style="background:#e9ecef; color:#6c757d; font-size:.85em; padding:2px 9px; border-radius:20px;"><?php echo count($hist_archive); ?></span>
</button>

<div id="archiveSection" style="display:none; margin-top:14px;">
<div class="form-container" style="background:#f8f9fa; border:1px solid #dee2e6; padding:16px; margin-bottom:14px;">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:2; min-width:200px;">
            <label style="margin-top:0; font-size:.875rem;">Recherche</label>
            <input type="text" id="searchHist" oninput="filtrerHistorique()" placeholder="Nom, matricule, destination, véhicule, immat…" autocomplete="off" style="width:100%;">
        </div>
        <div style="flex:1; min-width:140px;">
            <label style="margin-top:0; font-size:.875rem;">Statut</label>
            <select id="filtreStatutHist" onchange="filtrerHistorique()" style="width:100%;">
                <option value="">— Tous —</option>
                <option value="Terminée">Terminée</option>
                <option value="Refusée">Refusée</option>
                <option value="Annulée">Annulée</option>
            </select>
        </div>
        <div style="flex:1; min-width:130px;">
            <label style="margin-top:0; font-size:.875rem;">Du</label>
            <input type="date" id="dateDebutHist" onchange="filtrerHistorique()" style="width:100%;">
        </div>
        <div style="flex:1; min-width:130px;">
            <label style="margin-top:0; font-size:.875rem;">Au</label>
            <input type="date" id="dateFinHist" onchange="filtrerHistorique()" style="width:100%;">
        </div>
        <div style="flex:1; min-width:150px;">
            <label style="margin-top:0; font-size:.875rem;">Établissement</label>
            <select id="filtreEtabHist" onchange="filtrerHistorique()" style="width:100%;">
                <option value="">— Tous —</option>
                <?php foreach ($etabs_hist as $eh): ?>
                    <option value="<?php echo htmlspecialchars($eh['nom']); ?>"><?php echo htmlspecialchars($eh['nom']); ?></option>
                <?php endforeach; ?>
                <option value="(sans)">Sans établissement</option>
            </select>
        </div>
        <button type="button" class="action-btn cancel-btn" style="padding:10px 18px;" onclick="resetHist()">↺ Réinitialiser</button>
    </div>
</div>

<div style="display:flex; align-items:center; gap:12px; margin-bottom:8px;">
    <span class="count-badge" id="countHist"></span>
</div>

<table id="tableHist" class="historique-global-table">
    <thead>
        <tr>
            <th>Statut</th>
            <th>Employé</th>
            <th>Véhicule</th>
            <th>Période</th>
            <th>Départ réel</th>
            <th>Retour réel</th>
            <th>Destination</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($hist_archive as $row) echo renderHistRow($row); ?>
        <tr class="no-result" style="display:none;"><td colspan="8" style="text-align:center; color:#adb5bd; font-style:italic; padding:24px;">Aucun résultat pour ces critères.</td></tr>
    </tbody>
</table>
<div id="paginationHist"></div>
</div><!-- /archiveSection -->

<script>
let histRows = [], histPage = 1, histPerPage = 10;

function filtrerHistorique() {
    const kw     = document.getElementById('searchHist').value.toLowerCase().trim();
    const statut = document.getElementById('filtreStatutHist').value;
    const etab   = document.getElementById('filtreEtabHist').value;
    const dDeb   = document.getElementById('dateDebutHist').value; // YYYY-MM-DD ou ''
    const dFin   = document.getElementById('dateFinHist').value;

    const all = Array.from(document.querySelectorAll('#tableHist tbody tr:not(.no-result)'));
    histRows = all.filter(tr =>
        (!kw     || tr.dataset.search.includes(kw))
     && (!statut || tr.dataset.statut === statut)
     && (!etab   || tr.dataset.etab === etab)
     && (!dDeb   || tr.dataset.date >= dDeb)
     && (!dFin   || tr.dataset.date <= dFin)
    );
    all.forEach(tr => tr.style.display = 'none');
    histPage = 1;
    afficherPageHist();
}

function afficherPageHist() {
    const total      = histRows.length;
    const totalPages = Math.max(1, Math.ceil(total / histPerPage));
    histPage = Math.min(Math.max(1, histPage), totalPages);
    const debut = (histPage - 1) * histPerPage;
    const fin   = debut + histPerPage;
    histRows.forEach((tr, i) => tr.style.display = (i >= debut && i < fin) ? '' : 'none');

    document.getElementById('countHist').textContent = total + ' demande' + (total > 1 ? 's' : '');
    document.querySelector('#tableHist .no-result').style.display = total === 0 ? '' : 'none';
    renderPaginationHist(total, totalPages);
}

function renderPaginationHist(total, totalPages) {
    const el = document.getElementById('paginationHist');
    if (total === 0) { el.innerHTML = ''; return; }
    const debut = (histPage - 1) * histPerPage + 1;
    const fin   = Math.min(histPage * histPerPage, total);
    const opts  = [10, 30, 50].map(n => `<option value="${n}" ${histPerPage===n?'selected':''}>${n}</option>`).join('');
    const s = (extra, label, click, disabled) =>
        `<span class="pag-btn${extra}" style="cursor:${disabled?'default':'pointer'};opacity:${disabled?.35:1};" ${disabled?'':`onclick="${click}"`}>${label}</span>`;
    let pages = '';
    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || Math.abs(p - histPage) <= 1) {
            pages += s(p === histPage ? ' pag-active' : '', p, `histPage=${p};afficherPageHist();`, false);
        } else if (Math.abs(p - histPage) === 2) {
            pages += '<span class="pag-dots">…</span>';
        }
    }
    el.innerHTML = `
    <div class="pagination-bar">
        <div class="pag-per-page">
            <span>Afficher</span>
            <select onchange="histPerPage=parseInt(this.value);histPage=1;afficherPageHist();">${opts}</select>
            <span>par page</span>
        </div>
        <span class="pag-info">${debut}–${fin} sur <strong>${total}</strong></span>
        <div class="pag-buttons">
            ${s(' pag-nav', '← Préc.', 'histPage--;afficherPageHist();', histPage <= 1)}
            ${pages}
            ${s(' pag-nav', 'Suiv. →', 'histPage++;afficherPageHist();', histPage >= totalPages)}
        </div>
    </div>`;
}

function resetHist() {
    document.getElementById('searchHist').value = '';
    document.getElementById('filtreStatutHist').value = '';
    document.getElementById('filtreEtabHist').value = '';
    document.getElementById('dateDebutHist').value = '';
    document.getElementById('dateFinHist').value = '';
    filtrerHistorique();
}

function toggleArchive() {
    const sec = document.getElementById('archiveSection');
    const ic  = document.getElementById('iconArchive');
    const open = sec.style.display === 'none';
    sec.style.display = open ? 'block' : 'none';
    if (ic) ic.textContent = open ? '▼' : '▶';
}

document.addEventListener('DOMContentLoaded', filtrerHistorique);
</script>

<?php $conn->close(); include 'includes/footer.php'; ?>
