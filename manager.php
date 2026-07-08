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

        $stmt = $conn->prepare("UPDATE reservations SET statut_resa='Validée', id_vehicule=?, id_validateur=?, id_autorise_par=? WHERE id_reservation=?");
        $stmt->bind_param("iiii", $id_veh, $id_validateur, $id_autorise_par, $id);
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

// ================================================================
// FILTRES HISTORIQUE
// ================================================================
$search_kw   = trim($_GET['search'] ?? '');
$search_date = trim($_GET['date']   ?? '');
$filter_sql  = "";
$filter_params = [];
$filter_types  = "";

// Restriction par établissement (sauf Terracoop / manager sans établissement)
if (!$voit_tout) {
    $filter_sql .= " AND e.id_etablissement = ?";
    $filter_params[] = $mgr_etab;
    $filter_types  .= "i";
}

if (!empty($search_kw)) {
    $filter_sql .= " AND (e.nom LIKE ? OR e.prenom LIKE ? OR e.matricule LIKE ?)";
    $t = "%$search_kw%";
    array_push($filter_params, $t, $t, $t);
    $filter_types .= "sss";
}
if (!empty($search_date)) {
    $filter_sql .= " AND DATE(r.date_debut_resa) = ?";
    $filter_params[] = $search_date;
    $filter_types  .= "s";
}

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
    $stmt = $conn->prepare("
        SELECT v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal,
               COALESCE(et.nom, 'Sans établissement') AS etab_nom
        FROM vehicules v
        LEFT JOIN etablissements et ON et.id_etablissement = v.id_etablissement
        WHERE v.actif = 1
        AND (
            -- Communal libre sur ce créneau
            (
                v.est_communal = 1
                AND NOT EXISTS (
                    SELECT 1 FROM reservations r
                    WHERE r.id_vehicule = v.id_vehicule
                    AND r.statut_resa NOT IN ('Annulée','Refusée','Terminée')
                    AND r.date_debut_resa < ? AND r.date_fin_resa > ?
                )
            )
            OR
            -- Attitré dont le proprio est en congé sur ce créneau ET véhicule libre
            (
                v.est_communal = 0
                AND EXISTS (
                    SELECT 1 FROM affectations_fixes af
                    JOIN conges c ON af.id_employe = c.id_employe
                    WHERE af.id_vehicule = v.id_vehicule
                    AND c.date_debut <= DATE(?) AND c.date_fin >= DATE(?)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM reservations r
                    WHERE r.id_vehicule = v.id_vehicule
                    AND r.statut_resa NOT IN ('Annulée','Refusée','Terminée')
                    AND r.date_debut_resa < ? AND r.date_fin_resa > ?
                )
            )
        )
        ORDER BY etab_nom, v.est_communal DESC, v.marque
    ");
    $stmt->bind_param("ssssss", $date_fin, $date_debut, $date_debut, $date_fin, $date_fin, $date_debut);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($v = $res->fetch_assoc()) $list[] = $v;
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
if (!$voit_tout) $sql_demandes .= " AND e.id_etablissement = ?";
$sql_demandes .= " ORDER BY r.date_demande ASC";
$stmt_d = $conn->prepare($sql_demandes);
if (!$voit_tout) $stmt_d->bind_param("i", $mgr_etab);
$stmt_d->execute();
$demandes = $stmt_d->get_result();

// Historique
$sql_hist = "SELECT r.*, e.nom, e.prenom, e.matricule, v.marque, v.modele, v.immatriculation, v.kilometrage AS km_actuel FROM reservations r JOIN employes e ON r.id_employe=e.id_employe LEFT JOIN vehicules v ON r.id_vehicule=v.id_vehicule WHERE r.statut_resa != 'En attente' $filter_sql ORDER BY r.date_debut_resa DESC LIMIT 50";
$stmt_hist = $conn->prepare($sql_hist);
if (!empty($filter_params)) $stmt_hist->bind_param($filter_types, ...$filter_params);
$stmt_hist->execute();
$historique = $stmt_hist->get_result();
?>

<h2>Gestion des demandes</h2>

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
                <select name="id_vehicule" required style="width:100%; margin-bottom:6px; padding:8px; border:1.5px solid #dee2e6; border-radius:6px; font-size:.9em;">
                    <option value="">— Choisir un véhicule —</option>
                    <?php if (empty($veh_dispo)): ?>
                        <option disabled>Aucun véhicule disponible sur ce créneau</option>
                    <?php else: ?>
                        <?php
                        $veh_par_etab = [];
                        foreach ($veh_dispo as $v) { $veh_par_etab[$v['etab_nom']][] = $v; }
                        foreach ($veh_par_etab as $etab_lbl => $vehs): ?>
                            <optgroup label="🏢 <?php echo htmlspecialchars($etab_lbl); ?>">
                                <?php foreach ($vehs as $v): ?>
                                    <option value="<?php echo $v['id_vehicule']; ?>">
                                        <?php echo ($v['est_communal'] ? '[Communal] ' : '[Attitré] ').htmlspecialchars($v['marque'].' '.$v['modele'].' ('.$v['immatriculation'].')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (empty($veh_dispo)): ?>
                    <p style="color:#856404; font-size:.8em; margin:0 0 6px; background:#fff3cd; padding:5px 8px; border-radius:5px; border:1px solid #ffeeba;">
                        Aucun véhicule libre sur cette période. Vérifiez le parc ou refusez la demande.
                    </p>
                <?php endif; ?>
                <input type="text" list="liste_autorisateurs" class="autorise-input" required
                       placeholder="Autorisé par… (taper un nom)" autocomplete="off"
                       oninput="resoudreAutorisateur(this)"
                       style="width:100%; margin-bottom:6px; padding:8px; border:1.5px solid #dee2e6; border-radius:6px; font-size:.9em;">
                <input type="hidden" name="id_autorise_par" class="autorise-id" value="">
                <button type="submit" class="action-btn charge-btn" style="width:100%; margin:0;" onclick="return verifierAutorisateur(this.form)">Valider &amp; Attribuer</button>
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
<h3>Historique global</h3>

<div class="form-container" style="background:#f8f9fa; border:1px solid #dee2e6; padding:16px; margin-bottom:20px;">
    <form action="manager.php" method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:2; min-width:200px;">
            <label style="margin-top:0; font-size:.875rem;">Nom / Matricule</label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search_kw); ?>" placeholder="Rechercher...">
        </div>
        <div style="flex:1; min-width:150px;">
            <label style="margin-top:0; font-size:.875rem;">Date</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($search_date); ?>">
        </div>
        <div style="display:flex; gap:8px; padding-bottom:1px;">
            <button type="submit" class="action-btn charge-btn" style="width:auto; margin:0; padding:10px 18px;">Filtrer</button>
            <a href="manager.php" class="action-btn cancel-btn" style="text-decoration:none; padding:10px 18px;">Réinitialiser</a>
        </div>
    </form>
</div>

<table class="historique-global-table">
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
    <?php if ($historique->num_rows > 0): ?>
        <?php while ($row = $historique->fetch_assoc()): ?>
        <tr class="<?php echo in_array($row['statut_resa'], ['Annulée','Refusée']) ? 'archived' : ''; ?>">
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
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="8" style="text-align:center; color:#adb5bd; font-style:italic; padding:24px;">Aucun résultat pour cette recherche.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php $conn->close(); include 'includes/footer.php'; ?>
