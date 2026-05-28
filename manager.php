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
        $stmt = $conn->prepare("UPDATE reservations SET statut_resa='Validée', id_vehicule=? WHERE id_reservation=?");
        $stmt->bind_param("ii", $id_veh, $id);
        $stmt->execute();
        header('Location: manager.php?message=' . urlencode('✅ Demande validée et véhicule attribué.') . '&type=success');
        exit();
    } elseif ($action === 'refuser') {
        $motif_refus = trim($_POST['motif_refus'] ?? '');
        $stmt = $conn->prepare("UPDATE reservations SET statut_resa='Refusée', motif_refus=? WHERE id_reservation=?");
        $stmt->bind_param("si", $motif_refus, $id);
        $stmt->execute();
        header('Location: manager.php?message=' . urlencode('❌ Demande refusée.') . '&type=error');
        exit();
    }
}

include 'includes/header.php';
if (!$is_manager) { header('Location: index.php'); exit(); }

// ================================================================
// FILTRES HISTORIQUE
// ================================================================
$search_kw   = trim($_GET['search'] ?? '');
$search_date = trim($_GET['date']   ?? '');
$filter_sql  = "";
$filter_params = [];
$filter_types  = "";

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
        SELECT v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal
        FROM vehicules v
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
        ORDER BY v.est_communal DESC, v.marque
    ");
    $stmt->bind_param("ssssss", $date_fin, $date_debut, $date_debut, $date_fin, $date_fin, $date_debut);
    $stmt->execute();
    $res = $stmt->get_result();
    $list = [];
    while ($v = $res->fetch_assoc()) $list[] = $v;
    return $list;
}

// Demandes en attente
$stmt_d = $conn->prepare("SELECT r.id_reservation, r.date_debut_resa, r.date_fin_resa, r.motif, r.destination, r.date_demande, e.nom, e.prenom, e.matricule FROM reservations r JOIN employes e ON r.id_employe=e.id_employe WHERE r.statut_resa='En attente' ORDER BY r.date_demande ASC");
$stmt_d->execute();
$demandes = $stmt_d->get_result();

// Historique
$sql_hist = "SELECT r.*, e.nom, e.prenom, e.matricule, v.marque, v.modele, v.immatriculation FROM reservations r JOIN employes e ON r.id_employe=e.id_employe LEFT JOIN vehicules v ON r.id_vehicule=v.id_vehicule WHERE r.statut_resa != 'En attente' $filter_sql ORDER BY r.date_debut_resa DESC LIMIT 50";
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
                        <?php foreach ($veh_dispo as $v): ?>
                            <option value="<?php echo $v['id_vehicule']; ?>">
                                <?php echo ($v['est_communal'] ? '[Communal] ' : '[Attitré] ').htmlspecialchars($v['marque'].' '.$v['modele'].' ('.$v['immatriculation'].')'); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <?php if (empty($veh_dispo)): ?>
                    <p style="color:#856404; font-size:.8em; margin:0 0 6px; background:#fff3cd; padding:5px 8px; border-radius:5px; border:1px solid #ffeeba;">
                        Aucun véhicule libre sur cette période. Vérifiez le parc ou refusez la demande.
                    </p>
                <?php endif; ?>
                <button type="submit" class="action-btn charge-btn" style="width:100%; margin:0;">Valider &amp; Attribuer</button>
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
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr><td colspan="7" style="text-align:center; color:#adb5bd; font-style:italic; padding:24px;">Aucun résultat pour cette recherche.</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<?php $conn->close(); include 'includes/footer.php'; ?>
