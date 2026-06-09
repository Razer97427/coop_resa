<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$uid = (int)$_SESSION['user_id'];

// ================================================================
// TRAITEMENTS POST — avant tout output HTML
// ================================================================

// 1. Nouvelle demande
if (isset($_POST['reservation_submit'])) {
    csrf_verify();
    $destination = trim($_POST['destination'] ?? '');
    $motif       = trim($_POST['motif']       ?? '');
    $date_debut  = ($_POST['date_debut']  ?? '') . ' ' . ($_POST['heure_debut'] ?? '00:00') . ':00';
    $date_fin    = ($_POST['date_fin']    ?? '') . ' ' . ($_POST['heure_fin']   ?? '00:00') . ':00';

    if (strtotime($date_debut) >= strtotime($date_fin)) {
        header('Location: index.php?message=' . urlencode('❌ La date/heure de fin doit être après le début.') . '&type=error');
        exit();
    } elseif (empty($destination)) {
        header('Location: index.php?message=' . urlencode('❌ La destination est obligatoire.') . '&type=error');
        exit();
    } else {
        $stmt = $conn->prepare("INSERT INTO reservations (id_employe, id_vehicule, date_debut_resa, date_fin_resa, motif, destination, statut_resa, km_debut, date_demande) VALUES (?, NULL, ?, ?, ?, ?, 'En attente', 0, NOW())");
        $stmt->bind_param("issss", $uid, $date_debut, $date_fin, $motif, $destination);
        if ($stmt->execute()) {
            // ── Notifier tous les managers via PHPMailer + Mailjet ──
            $managers = $conn->query("SELECT email, nom, prenom FROM employes WHERE role='Manager' AND actif=1 AND email IS NOT NULL AND email != ''");
            if ($managers && $managers->num_rows > 0) {
                require_once __DIR__ . '/vendor/autoload.php';

                $nom_demandeur = $_SESSION['user_name'] ?? 'Un employé';
                $debut_fmt     = date('d/m/Y à H:i', strtotime($date_debut));
                $fin_fmt       = date('d/m/Y à H:i', strtotime($date_fin));
                $app_dir       = dirname($_SERVER['SCRIPT_NAME']);
                $url_manager   = 'https://' . $_SERVER['HTTP_HOST'] . $app_dir . '/manager.php';

                while ($mgr = $managers->fetch_assoc()) {
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

                        // Désactiver le tracking Mailjet (clics + ouvertures)
                        $mail->addCustomHeader('X-MJ-TrackClicks',  '0');
                        $mail->addCustomHeader('X-MJ-TrackOpens',   '0');

                        $mail->isHTML(true);
                        $mail->Subject = '[TERRACOOP] Nouvelle demande de véhicule — ' . $nom_demandeur;
                        $mail->Body    = '
<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
  <div style="background:#007bff;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:1.2em;">🚘 Nouvelle demande de véhicule</h2>
  </div>
  <div style="padding:24px;">
    <p style="margin:0 0 16px;">Bonjour <strong>' . htmlspecialchars($mgr['prenom']) . '</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">Une demande attend votre validation :</p>
    <table style="width:100%;border-collapse:collapse;font-size:.95em;">
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;width:40%;">Demandeur</td><td style="padding:8px 12px;">' . htmlspecialchars($nom_demandeur) . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;">Destination</td><td style="padding:8px 12px;">' . htmlspecialchars($destination) . '</td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;">Motif</td><td style="padding:8px 12px;">' . htmlspecialchars($motif ?: 'Non précisé') . '</td></tr>
      <tr><td style="padding:8px 12px;font-weight:700;">Départ</td><td style="padding:8px 12px;">' . $debut_fmt . '</td></tr>
      <tr style="background:#f8f9fa;"><td style="padding:8px 12px;font-weight:700;">Retour</td><td style="padding:8px 12px;">' . $fin_fmt . '</td></tr>
    </table>
    <div style="text-align:center;margin-top:28px;">
      <a href="' . $url_manager . '" style="background:#007bff;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:700;font-size:1em;">
        ✅ Voir et valider la demande
      </a>
    </div>
  </div>
  <div style="background:#f8f9fa;padding:12px 24px;text-align:center;color:#999;font-size:.8em;border-top:1px solid #dee2e6;">
    Gestion de Flotte TERRACOOP — message automatique
  </div>
</div>';
                        $mail->AltBody = "Bonjour,\n\nNouvelle demande de $nom_demandeur.\nDestination : $destination\nMotif : " . ($motif ?: 'Non précisé') . "\nDépart : $debut_fmt — Retour : $fin_fmt\n\nValidez ici : $url_manager";

                        $mail->send();
                    } catch (\PHPMailer\PHPMailer\Exception $e) {
                        error_log('PHPMailer erreur : ' . $mail->ErrorInfo);
                    }
                }
            }

            header('Location: index.php?message=' . urlencode('✅ Demande envoyée ! Le manager va vous attribuer un véhicule.') . '&type=success');
            exit();
        }
        header('Location: index.php?message=' . urlencode('❌ Erreur technique. Réessayez.') . '&type=error');
        exit();
    }
}

// 2. Prise en charge (départ)
if (isset($_POST['prise_en_charge_submit'])) {
    csrf_verify();
    $id_resa            = (int)$_POST['id_reservation'];
    $km_debut           = (int)$_POST['km_debut'];
    $commentaire_depart = $_POST['commentaire_depart'] ?? '';
    $stmt = $conn->prepare("UPDATE reservations SET km_debut=?, date_depart_reel=NOW(), commentaire_depart=?, statut_resa='En cours' WHERE id_reservation=? AND id_employe=?");
    $stmt->bind_param("isii", $km_debut, $commentaire_depart, $id_resa, $uid);
    $stmt->execute();
    header('Location: index.php?message=' . urlencode('🚗 Bonne route ! Prise en charge enregistrée.') . '&type=success');
    exit();
}

// 3. Restitution
if (isset($_POST['restitution_submit'])) {
    csrf_verify();
    $id_resa     = (int)$_POST['id_reservation'];
    $km_fin      = (int)$_POST['km_fin'];
    $date_retour = str_replace('T', ' ', $_POST['date_retour_reel'] ?? date('Y-m-d H:i:s'));
    $comment     = $_POST['commentaire_retour'] ?? '';
    $stmt = $conn->prepare("UPDATE reservations SET km_fin=?, date_retour_reel=?, commentaire_retour=?, statut_resa='Terminée' WHERE id_reservation=? AND id_employe=?");
    $stmt->bind_param("issii", $km_fin, $date_retour, $comment, $id_resa, $uid);
    if ($stmt->execute()) {
        $stmt_veh_id = $conn->prepare("SELECT id_vehicule FROM reservations WHERE id_reservation=? AND id_employe=?");
        $stmt_veh_id->bind_param("ii", $id_resa, $uid);
        $stmt_veh_id->execute();
        $row_veh_id = $stmt_veh_id->get_result()->fetch_assoc();
        $stmt_veh_id->close();
        if ($row_veh_id && $row_veh_id['id_vehicule']) {
            $stmt_km = $conn->prepare("UPDATE vehicules SET kilometrage=? WHERE id_vehicule=?");
            $stmt_km->bind_param("ii", $km_fin, $row_veh_id['id_vehicule']);
            $stmt_km->execute();
            $stmt_km->close();
        }
        header('Location: index.php?message=' . urlencode('✅ Véhicule restitué. Merci !') . '&type=success');
        exit();
    }
}

// ================================================================
// OUTPUT HTML — après tous les traitements POST
// ================================================================
include 'includes/header.php';

$message        = isset($_GET['message']) ? urldecode($_GET['message']) : '';
$message_type   = $_GET['type'] ?? 'success';
$restitution_id = (int)($_GET['restitution_id'] ?? 0);

// ================================================================
// DONNÉES
// ================================================================

// Véhicule attitré de l'employé
$stmt_aff = $conn->prepare("
    SELECT v.marque, v.modele, v.immatriculation, v.type_carburant, v.kilometrage
    FROM affectations_fixes af
    JOIN vehicules v ON af.id_vehicule = v.id_vehicule
    WHERE af.id_employe = ? AND v.actif = 1
");
$stmt_aff->bind_param("i", $uid);
$stmt_aff->execute();
$mon_vehicule = $stmt_aff->get_result()->fetch_assoc();

// Demandes actives (en attente, validées, en cours)
$stmt_actives = $conn->prepare("
    SELECT r.id_reservation, r.date_debut_resa, r.date_fin_resa, r.motif, r.destination,
           r.statut_resa, r.km_debut, r.km_fin, r.date_depart_reel, r.date_retour_reel,
           v.marque, v.modele, v.immatriculation, v.kilometrage AS km_actuel
    FROM reservations r
    LEFT JOIN vehicules v ON r.id_vehicule = v.id_vehicule
    WHERE r.id_employe = ? AND r.statut_resa IN ('En attente', 'Validée', 'En cours')
    ORDER BY r.date_debut_resa ASC
");
$stmt_actives->bind_param("i", $uid);
$stmt_actives->execute();
$demandes_actives = $stmt_actives->get_result();

// Historique passé (terminées, refusées, annulées)
$stmt_h = $conn->prepare("
    SELECT r.id_reservation, r.date_debut_resa, r.date_fin_resa, r.motif, r.destination,
           r.statut_resa, r.km_debut, r.km_fin, r.date_depart_reel, r.date_retour_reel,
           r.motif_refus,
           v.marque, v.modele, v.immatriculation
    FROM reservations r
    LEFT JOIN vehicules v ON r.id_vehicule = v.id_vehicule
    WHERE r.id_employe = ? AND r.statut_resa IN ('Terminée', 'Refusée', 'Annulée')
    ORDER BY r.date_debut_resa DESC LIMIT 50
");
$stmt_h->bind_param("i", $uid);
$stmt_h->execute();
$historique = $stmt_h->get_result();
?>

<div class="page-narrow">

<?php if ($message): ?>
<div class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<?php if ($restitution_id > 0):
    $stmt_r = $conn->prepare("SELECT r.*, v.marque, v.modele, v.kilometrage AS km_vehicule FROM reservations r JOIN vehicules v ON r.id_vehicule=v.id_vehicule WHERE r.id_reservation=? AND r.id_employe=?");
    $stmt_r->bind_param("ii", $restitution_id, $uid);
    $stmt_r->execute();
    $rd = $stmt_r->get_result()->fetch_assoc();
    if (!$rd) { header('Location: index.php'); exit(); }
    $km_ref = ($rd['km_debut'] > 0) ? $rd['km_debut'] : $rd['km_vehicule'];
?>
<!-- ===== MODE RESTITUTION ===== -->
<div class="restitution-container">
    <h3>Restitution — <?php echo htmlspecialchars($rd['marque'].' '.$rd['modele']); ?></h3>
    <p>Kilométrage de départ : <strong><?php echo number_format($rd['km_debut'], 0, ',', ' '); ?> km</strong></p>
    <form action="index.php" method="POST">
        <input type="hidden" name="restitution_submit" value="1">
        <input type="hidden" name="id_reservation" value="<?php echo $restitution_id; ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <label>Date & heure de retour :</label>
        <input type="datetime-local" name="date_retour_reel" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
        <label>Kilométrage fin :</label>
        <input type="number" name="km_fin" required min="<?php echo $km_ref; ?>" placeholder="<?php echo $km_ref; ?>">
        <label>Commentaire (état du véhicule) :</label>
        <textarea name="commentaire_retour" rows="3" placeholder="RAS — ou décrivez un problème rencontré..."></textarea>
        <button type="submit" class="action-btn return-btn" style="width:100%; margin-top:16px;">✅ Valider la restitution</button>
        <div style="text-align:center; margin-top:10px;"><a href="index.php">Annuler</a></div>
    </form>
</div>
<?php else: ?>

<!-- ===== VÉHICULE ATTITRÉ ===== -->
<?php if ($mon_vehicule): ?>
<div style="background:#e8f4fd; border:1px solid #bee5eb; border-radius:10px; padding:16px 20px; margin:0 auto 24px; max-width:600px; display:flex; align-items:center; gap:18px;">
    <div style="font-size:2.2em; line-height:1;">🔑</div>
    <div style="flex:1;">
        <div style="font-weight:700; font-size:1.05em; color:#0c5460; margin-bottom:3px;">Votre véhicule attitré</div>
        <div style="font-size:1.1em; font-weight:600; color:#155724;">
            <?php echo htmlspecialchars($mon_vehicule['marque'].' '.$mon_vehicule['modele']); ?>
        </div>
        <div style="color:#0c5460; font-size:.95em; margin-top:2px;">
            <strong><?php echo htmlspecialchars($mon_vehicule['immatriculation']); ?></strong>
            &nbsp;·&nbsp; <?php echo htmlspecialchars($mon_vehicule['type_carburant']); ?>
            &nbsp;·&nbsp; <?php echo number_format($mon_vehicule['kilometrage'], 0, ',', ' '); ?> km
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===== FORMULAIRE DEMANDE ===== -->
<div class="form-container" style="max-width:600px;">
    <h3 style="margin-top:0;">Nouvelle demande de véhicule</h3>
    <p class="text-muted" style="margin-bottom:16px;">
        Renseignez vos dates et destination. Le manager vous attribuera un véhicule et validera votre demande.
    </p>
    <form action="index.php" method="POST">
        <input type="hidden" name="reservation_submit" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="time-group">
            <div>
                <label>Date de départ</label>
                <input type="date" name="date_debut" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div>
                <label>Date de retour</label>
                <input type="date" name="date_fin" required value="<?php echo date('Y-m-d'); ?>">
            </div>
        </div>
        <div class="time-group">
            <div>
                <label>Heure de départ</label>
                <input type="time" name="heure_debut" required value="08:00">
            </div>
            <div>
                <label>Heure de retour</label>
                <input type="time" name="heure_fin" required value="17:00">
            </div>
        </div>
        <label>Destination <span style="color:#dc3545;">*</span></label>
        <input type="text" name="destination" required placeholder="Ex : Saint-Denis, Site Nord...">
        <label>Motif <span class="text-muted" style="font-weight:400;">(optionnel)</span></label>
        <input type="text" name="motif" placeholder="Ex : Visite chantier, Livraison...">
        <button type="submit" style="margin-top:16px;">Envoyer la demande</button>
    </form>
</div>

<?php endif; ?>

<hr>

<!-- ===== MES DEMANDES ===== -->
<h3 style="margin-bottom:16px;">Mes demandes</h3>

<!-- DEMANDES EN COURS -->
<?php if ($demandes_actives->num_rows === 0): ?>
    <p class="text-muted" style="font-style:italic; margin-bottom:20px;">Aucune demande en cours.</p>
<?php else: ?>
<div style="display:flex; flex-direction:column; gap:14px; margin:0 auto 28px; max-width:720px;">
<?php while ($row = $demandes_actives->fetch_assoc()):
    $statut_css = strtolower(str_replace([' ','é','è'],['-','e','e'], $row['statut_resa']));
?>
<div style="border:1.5px solid #dee2e6; border-radius:10px; padding:18px 20px; background:#fff; box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:14px;">
        <span class="status-tag <?php echo $statut_css; ?>" style="font-size:.9em;">
            <?php echo htmlspecialchars($row['statut_resa']); ?>
        </span>
        <span style="color:#6c757d; font-size:.88em;">
            Du <strong><?php echo date('d/m/Y à H:i', strtotime($row['date_debut_resa'])); ?></strong>
            &nbsp;→&nbsp;
            <strong><?php echo date('d/m/Y à H:i', strtotime($row['date_fin_resa'])); ?></strong>
        </span>
    </div>
    <div style="display:flex; gap:32px; flex-wrap:wrap; margin-bottom:14px;">
        <div>
            <div style="font-size:.72em; color:#6c757d; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px;">Destination</div>
            <strong><?php echo htmlspecialchars($row['destination'] ?: '—'); ?></strong>
            <?php if (!empty($row['motif'])): ?>
                <br><small class="text-muted"><?php echo htmlspecialchars($row['motif']); ?></small>
            <?php endif; ?>
        </div>
        <div>
            <div style="font-size:.72em; color:#6c757d; text-transform:uppercase; letter-spacing:.06em; margin-bottom:3px;">Véhicule attribué</div>
            <?php if (!empty($row['marque'])): ?>
                <strong>🚗 <?php echo htmlspecialchars($row['marque'].' '.$row['modele']); ?></strong><br>
                <small class="text-muted"><?php echo htmlspecialchars($row['immatriculation']); ?></small>
            <?php else: ?>
                <span style="color:#856404; font-size:.9em;">⏳ En attente d'attribution</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end;">
        <?php if ($row['statut_resa'] === 'Validée'): ?>
            <form action="index.php" method="POST" style="display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin:0;">
                <input type="hidden" name="prise_en_charge_submit" value="1">
                <input type="hidden" name="id_reservation" value="<?php echo $row['id_reservation']; ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <div>
                    <label style="font-size:.78em; margin:0 0 3px; display:block; color:#495057;">Km départ</label>
                    <input type="number" name="km_debut" value="<?php echo $row['km_actuel']; ?>" min="<?php echo $row['km_actuel']; ?>" required style="width:110px; margin:0;">
                </div>
                <div>
                    <label style="font-size:.78em; margin:0 0 3px; display:block; color:#495057;">Note état (optionnel)</label>
                    <input type="text" name="commentaire_depart" placeholder="Ex: Rayure porte…" style="width:190px; margin:0;">
                </div>
                <button type="submit" class="action-btn charge-btn" style="margin:0;">🚗 Valider départ</button>
            </form>
            <a href="actions.php?action=annuler&id=<?php echo $row['id_reservation']; ?>" class="action-btn cancel-btn" style="margin:0;" onclick="return confirm('Annuler cette demande ?')">Annuler</a>
        <?php elseif ($row['statut_resa'] === 'En cours'): ?>
            <a href="index.php?restitution_id=<?php echo $row['id_reservation']; ?>" class="action-btn return-btn" style="margin:0;">📦 Restituer le véhicule</a>
        <?php elseif ($row['statut_resa'] === 'En attente'): ?>
            <a href="actions.php?action=annuler&id=<?php echo $row['id_reservation']; ?>" class="action-btn cancel-btn" style="margin:0;" onclick="return confirm('Annuler cette demande ?')">Annuler</a>
        <?php endif; ?>
    </div>
</div>
<?php endwhile; ?>
</div>
<?php endif; ?>

<!-- HISTORIQUE PRÉCÉDENT -->
<?php if ($historique->num_rows > 0): ?>
<div style="max-width:720px; margin: 0 auto;">
    <button onclick="toggleHistorique()" id="btnHistorique"
            style="background:#f8f9fa; border:1.5px solid #dee2e6; border-radius:8px; padding:10px 18px; cursor:pointer; font-size:.92em; color:#495057; display:inline-flex; align-items:center; gap:8px; transition:border-color .2s, color .2s; margin-bottom:0;">
        <span id="iconHisto" style="font-size:.8em;">▶</span>
        Voir les demandes précédentes
        <span style="background:#e9ecef; color:#6c757d; font-size:.8em; padding:2px 9px; border-radius:20px;"><?php echo $historique->num_rows; ?></span>
    </button>

    <div id="sectionHistorique" style="display:none; margin-top:14px;">
        <input type="text" id="searchHistorique"
               placeholder="Rechercher — destination, véhicule, statut…"
               oninput="filtrerHistorique(this.value)"
               style="width:100%; padding:9px 14px; border:1.5px solid #dee2e6; border-radius:6px; font-size:.9em; margin-bottom:12px; box-sizing:border-box;">

        <p id="msgAucunResultat" style="display:none; color:#adb5bd; font-style:italic; font-size:.9em;">Aucun résultat pour cette recherche.</p>

        <table id="tableHistorique" style="font-size:.9em;">
            <thead>
                <tr>
                    <th>Statut</th>
                    <th>Période</th>
                    <th>Destination</th>
                    <th>Véhicule</th>
                </tr>
            </thead>
            <tbody>
            <?php while ($row = $historique->fetch_assoc()):
                $search_str = strtolower(
                    $row['destination'].' '.$row['motif'].' '.$row['statut_resa'].' '.
                    ($row['marque']??'').' '.($row['modele']??'').' '.($row['immatriculation']??'').' '.
                    ($row['motif_refus']??'')
                );
            ?>
            <tr data-search="<?php echo htmlspecialchars($search_str); ?>"
                class="<?php echo in_array($row['statut_resa'], ['Refusée','Annulée']) ? 'archived' : ''; ?>">
                <td data-label="Statut">
                    <span class="status-tag <?php echo strtolower(str_replace([' ','é','è'],['-','e','e'],$row['statut_resa'])); ?>" style="font-size:.82em;">
                        <?php echo htmlspecialchars($row['statut_resa']); ?>
                    </span>
                </td>
                <td data-label="Période" style="white-space:nowrap; font-size:.87em; color:#555;">
                    <?php echo date('d/m/Y', strtotime($row['date_debut_resa'])); ?><br>
                    <span style="color:#adb5bd;">→</span> <?php echo date('d/m/Y', strtotime($row['date_fin_resa'])); ?>
                </td>
                <td data-label="Destination">
                    <strong><?php echo htmlspecialchars($row['destination'] ?: '—'); ?></strong>
                    <?php if (!empty($row['motif'])): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($row['motif']); ?></small>
                    <?php endif; ?>
                    <?php if ($row['statut_resa'] === 'Refusée' && !empty($row['motif_refus'])): ?>
                        <div style="margin-top:5px; background:#f8d7da; border:1px solid #f5c6cb; border-radius:5px; padding:5px 9px; font-size:.82em; color:#842029;">
                            <strong>Motif du refus :</strong> <?php echo htmlspecialchars($row['motif_refus']); ?>
                        </div>
                    <?php elseif ($row['statut_resa'] === 'Refusée'): ?>
                        <div style="margin-top:5px; color:#6c757d; font-size:.82em; font-style:italic;">Aucun motif précisé.</div>
                    <?php endif; ?>
                </td>
                <td data-label="Véhicule">
                    <?php if (!empty($row['marque'])): ?>
                        <?php echo htmlspecialchars($row['marque'].' '.$row['modele']); ?><br>
                        <small class="text-muted"><?php echo htmlspecialchars($row['immatriculation']); ?></small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleHistorique() {
    const s    = document.getElementById('sectionHistorique');
    const icon = document.getElementById('iconHisto');
    const btn  = document.getElementById('btnHistorique');
    const open = s.style.display === 'none';
    s.style.display  = open ? 'block' : 'none';
    icon.textContent = open ? '▼' : '▶';
    btn.style.borderColor = open ? '#0d6efd' : '#dee2e6';
    btn.style.color       = open ? '#0d6efd' : '#495057';
    if (open) document.getElementById('searchHistorique').focus();
}
function filtrerHistorique(kw) {
    kw = kw.toLowerCase().trim();
    let nb = 0;
    document.querySelectorAll('#tableHistorique tbody tr').forEach(tr => {
        const ok = !kw || tr.dataset.search.includes(kw);
        tr.style.display = ok ? '' : 'none';
        if (ok) nb++;
    });
    document.getElementById('msgAucunResultat').style.display = (kw && nb === 0) ? 'block' : 'none';
    document.getElementById('tableHistorique').style.display  = (kw && nb === 0) ? 'none'  : '';
}
</script>
<?php endif; ?>

</div><!-- /.page-narrow -->

<?php $conn->close(); include 'includes/footer.php'; ?>
