<?php
require_once 'config.php';

// ================================================================
// TRAITEMENTS (avant tout output HTML)
// ================================================================

if (($_SESSION['user_role'] ?? '') !== 'Manager') {
    header('Location: index.php');
    exit();
}

// --- VÉHICULES ---
if (isset($_POST['ajout_vehicule'])) {
    $immat   = trim($_POST['immatriculation']);
    $marque  = trim($_POST['marque']);
    $modele  = trim($_POST['modele']);
    $carbu   = $_POST['type_carburant'];
    $commun  = isset($_POST['est_communal']) ? 1 : 0;
    $stmt = $conn->prepare("INSERT INTO vehicules (immatriculation, marque, modele, type_carburant, est_communal, actif, kilometrage) VALUES (?,?,?,?,?,1,0)");
    $stmt->bind_param("ssssi", $immat, $marque, $modele, $carbu, $commun);
    $ok = $stmt->execute();
    header('Location: parc.php?message=' . urlencode($ok ? '✅ Véhicule ajouté.' : '❌ Erreur (immatriculation déjà existante ?).') . '&type=' . ($ok ? 'success' : 'error'));
    exit();
}

if (isset($_GET['veh_action']) && isset($_GET['id'])) {
    $id  = (int)$_GET['id'];
    $act = $_GET['veh_action'];
    if ($act === 'desactiver')    $conn->query("UPDATE vehicules SET actif=0 WHERE id_vehicule=$id");
    elseif ($act === 'reactiver') $conn->query("UPDATE vehicules SET actif=1 WHERE id_vehicule=$id");
    elseif ($act === 'toggle')    $conn->query("UPDATE vehicules SET est_communal=1-est_communal WHERE id_vehicule=$id");
    elseif ($act === 'supprimer') {
        $conn->query("DELETE FROM reservations WHERE id_vehicule=$id");
        $conn->query("DELETE FROM affectations_fixes WHERE id_vehicule=$id");
        $conn->query("DELETE FROM vehicules WHERE id_vehicule=$id AND actif=0");
    }
    header('Location: parc.php?message=' . urlencode('✅ Véhicule mis à jour.') . '&type=success&tab=vehicules');
    exit();
}

// --- AFFECTATIONS ---
if (isset($_POST['ajout_affectation'])) {
    $id_emp = (int)$_POST['id_employe'];
    $id_veh = (int)$_POST['id_vehicule'];
    $check  = $conn->query("SELECT COUNT(*) as n FROM affectations_fixes WHERE id_employe=$id_emp OR id_vehicule=$id_veh")->fetch_assoc()['n'];
    if ($check > 0) {
        header('Location: parc.php?message=' . urlencode('❌ Cet employé ou ce véhicule est déjà affecté.') . '&type=error&tab=affectations');
    } else {
        $stmt = $conn->prepare("INSERT INTO affectations_fixes (id_employe, id_vehicule) VALUES (?,?)");
        $stmt->bind_param("ii", $id_emp, $id_veh);
        $stmt->execute();
        $conn->query("UPDATE vehicules SET est_communal=0 WHERE id_vehicule=$id_veh");
        header('Location: parc.php?message=' . urlencode('✅ Affectation enregistrée.') . '&type=success&tab=affectations');
    }
    exit();
}

if (isset($_GET['aff_action']) && $_GET['aff_action'] === 'supprimer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $row_aff = $conn->query("SELECT id_vehicule FROM affectations_fixes WHERE id_affectation=$id")->fetch_assoc();
    if ($row_aff) $conn->query("UPDATE vehicules SET est_communal=1 WHERE id_vehicule=".$row_aff['id_vehicule']);
    $conn->query("DELETE FROM affectations_fixes WHERE id_affectation=$id");
    header('Location: parc.php?message=' . urlencode('✅ Affectation supprimée. Véhicule repassé en communal.') . '&type=success&tab=affectations');
    exit();
}

include 'includes/header.php';

$message      = isset($_GET['message']) ? urldecode($_GET['message']) : '';
$message_type = $_GET['type'] ?? 'success';

// ================================================================
// DONNÉES
// ================================================================
$tab = $_GET['tab'] ?? 'vehicules';

$vehicules = $conn->query("
    SELECT v.*, af.id_affectation, e.nom, e.prenom, e.matricule
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON v.id_vehicule=af.id_vehicule
    LEFT JOIN employes e ON af.id_employe=e.id_employe
    ORDER BY v.actif DESC, v.est_communal DESC, v.marque
");

$emp_sans_veh   = $conn->query("SELECT id_employe, nom, prenom, matricule FROM employes WHERE actif=1 AND id_employe NOT IN (SELECT id_employe FROM affectations_fixes) ORDER BY nom");
$veh_attitrable = $conn->query("SELECT id_vehicule, marque, modele, immatriculation FROM vehicules WHERE actif=1 AND id_vehicule NOT IN (SELECT id_vehicule FROM affectations_fixes) ORDER BY marque");

$affectations = $conn->query("
    SELECT af.id_affectation, e.nom, e.prenom, e.matricule, e.id_employe,
           v.marque, v.modele, v.immatriculation
    FROM affectations_fixes af
    JOIN employes e ON af.id_employe=e.id_employe
    JOIN vehicules v ON af.id_vehicule=v.id_vehicule
    ORDER BY e.nom
");

$employes_aff = $conn->query("
    SELECT e.id_employe, e.nom, e.prenom, e.matricule,
           v.marque, v.modele, v.immatriculation
    FROM employes e
    JOIN affectations_fixes af ON e.id_employe=af.id_employe
    JOIN vehicules v ON af.id_vehicule=v.id_vehicule
    WHERE e.actif=1
    ORDER BY e.nom
");

if ($message) echo '<div class="message '.$message_type.'">'.htmlspecialchars($message).'</div>';
?>

<h2>Parc Automobile</h2>

<!-- ================================================================ -->
<!-- ONGLETS                                                           -->
<!-- ================================================================ -->
<div class="tabs" style="display:flex; gap:4px; margin-bottom:24px; border-bottom:2px solid #dee2e6;">
    <a href="parc.php?tab=vehicules"    class="tab-btn <?php echo $tab==='vehicules'    ? 'active' : ''; ?>">🚗 Véhicules</a>
    <a href="parc.php?tab=affectations" class="tab-btn <?php echo $tab==='affectations' ? 'active' : ''; ?>">🔑 Affectations</a>
    <a href="parc.php?tab=conges"       class="tab-btn <?php echo $tab==='conges'       ? 'active' : ''; ?>">🏖️ Absences</a>
    <a href="parc.php?tab=vue"          class="tab-btn <?php echo $tab==='vue'          ? 'active' : ''; ?>">📊 Vue d'ensemble</a>
</div>

<style>
/* ── Onglets ── */
.tab-btn { padding:10px 20px; text-decoration:none; color:#555; border-radius:6px 6px 0 0; border:1px solid transparent; border-bottom:none; font-weight:500; transition:.2s; }
.tab-btn:hover  { background:#f0f2f5; color:#333; }
.tab-btn.active { background:#fff; border-color:#dee2e6; color:var(--primary); font-weight:700; margin-bottom:-2px; }

/* ── Barre de filtres ── */
.filter-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 16px;
    padding: 14px 16px;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 8px;
}

.filter-bar .search-wrap {
    position: relative;
    flex: 1;
    min-width: 220px;
}

.filter-bar .search-wrap svg.ico-search {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    fill: #adb5bd;
    pointer-events: none;
}

.filter-bar .search-wrap input[type="text"] {
    width: 100%;
    padding: 9px 34px 9px 36px;
    border: 1.5px solid #dee2e6;
    border-radius: 6px;
    font-size: .9rem;
    background: #fff;
    transition: border-color .2s, box-shadow .2s;
    box-sizing: border-box;
}

.filter-bar .search-wrap input[type="text"]:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,123,255,.12);
}

.clear-btn {
    position: absolute;
    right: 9px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: #adb5bd;
    font-size: 1.1rem;
    line-height: 1;
    padding: 0 2px;
    display: none;
}

.clear-btn:hover { color: #495057; }

.filter-bar select {
    padding: 9px 12px;
    border: 1.5px solid #dee2e6;
    border-radius: 6px;
    font-size: .9rem;
    background: #fff;
    cursor: pointer;
    transition: border-color .2s;
}

.filter-bar select:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,123,255,.12);
}

.filter-bar select.active-filter {
    border-color: #007bff;
    background: #e8f0fe;
    color: #0056b3;
    font-weight: 600;
}

.btn-reset {
    padding: 9px 16px;
    background: #6c757d;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    white-space: nowrap;
    font-size: .9rem;
    transition: background .15s;
}
.btn-reset:hover { background: #5a6268; }

/* ── En-tête tableau avec compteur ── */
.table-toolbar {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin: 20px 0 10px;
}
.table-toolbar h3 { margin: 0; }
.count-badge {
    font-size: .78em;
    color: #6c757d;
    font-weight: 400;
    background: #e9ecef;
    padding: 2px 10px;
    border-radius: 20px;
}

/* ── Tri des colonnes ── */
th.sortable {
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
}
th.sortable:hover { background: #e9ecef; }
th.sortable::after { content: ' ⇅'; opacity: .3; font-size: .75em; }
th.sort-asc::after  { content: ' ↑'; opacity: 1; color: #007bff; }
th.sort-desc::after { content: ' ↓'; opacity: 1; color: #007bff; }

/* ── État vide ── */
tr.no-result td {
    text-align: center;
    padding: 28px 16px;
    color: #adb5bd;
    font-style: italic;
}

/* ── Formulaire affectation ── */
.aff-search-group {
    position: relative;
    margin-bottom: 6px;
}
.aff-search-group input[type="text"] {
    width: 100%;
    padding: 9px 12px 9px 36px;
    border: 1.5px solid #dee2e6;
    border-radius: 6px 6px 0 0;
    font-size: .9rem;
    box-sizing: border-box;
    transition: border-color .2s;
}
.aff-search-group input[type="text"]:focus {
    border-color: #007bff;
    outline: none;
}
.aff-search-group svg {
    position: absolute;
    left: 11px;
    top: 50%;
    transform: translateY(-50%);
    width: 15px;
    height: 15px;
    fill: #adb5bd;
    pointer-events: none;
}
.aff-select {
    width: 100%;
    border: 1.5px solid #dee2e6;
    border-top: none;
    border-radius: 0 0 6px 6px;
    font-size: .9rem;
    padding: 6px 8px;
    background: #fff;
    cursor: pointer;
    max-height: 160px;
    overflow-y: auto;
    box-sizing: border-box;
}
.aff-select:focus {
    border-color: #007bff;
    outline: none;
}
.aff-select option { padding: 6px 8px; }

/* ================================================================
   MOBILE (≤ 767px)
   ================================================================ */
@media (max-width: 767px) {

    /* ── Onglets : 2 par ligne ── */
    .tabs {
        flex-wrap: wrap !important;
        border-bottom: none !important;
        gap: 6px !important;
        margin-bottom: 16px !important;
    }
    .tab-btn {
        flex: 1 1 calc(50% - 4px);
        min-width: 0;
        padding: 9px 6px;
        font-size: .82em;
        text-align: center;
        border-radius: 8px !important;
        border: 1.5px solid #dee2e6 !important;
        margin-bottom: 0 !important;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .tab-btn.active {
        background: var(--primary) !important;
        color: #fff !important;
        border-color: var(--primary) !important;
        margin-bottom: 0 !important;
    }

    /* ── Barre de filtres ── */
    .filter-bar { padding: 12px; gap: 8px; }
    .filter-bar .search-wrap { min-width: 100%; flex: none; }
    .filter-bar select { width: 100%; flex: none; }
    .btn-reset { width: 100%; }

    /* ── Boutons d'action dans les cellules de tableau ── */
    table:not([class*='fc-']) td .action-btn {
        display: block !important;
        width: 100% !important;
        margin: 4px 0 !important;
        box-sizing: border-box;
    }

    /* ── Formulaire véhicule : empiler les 2 champs carburant/communal ── */
    .filter-bar + div .time-group,
    .form-container .time-group {
        flex-direction: column;
    }

    /* ── Icônes de tri : inutiles en vue carte ── */
    th.sortable::after,
    th.sort-asc::after,
    th.sort-desc::after { content: none; }

    /* ── Ligne expandable absences : forcer pleine largeur ── */
    #tableAbs tr[id^="detail-"] td { padding-left: 0 !important; text-align: left !important; }
    #tableAbs tr[id^="detail-"] td:before { content: none !important; }
}
</style>

<!-- ================================================================ -->
<!-- ONGLET VÉHICULES                                                  -->
<!-- ================================================================ -->
<?php if ($tab === 'vehicules'): ?>

<div class="form-container" style="max-width:700px;">
    <h3 style="margin-top:0;">➕ Ajouter un véhicule</h3>
    <form action="parc.php" method="POST">
        <input type="hidden" name="ajout_vehicule" value="1">
        <div class="time-group">
            <div>
                <label>Immatriculation</label>
                <input type="text" name="immatriculation" required placeholder="AA-123-BB">
            </div>
            <div>
                <label>Marque</label>
                <input type="text" name="marque" required placeholder="Renault">
            </div>
            <div>
                <label>Modèle</label>
                <input type="text" name="modele" required placeholder="Kangoo">
            </div>
        </div>
        <div class="time-group" style="margin-top:10px;">
            <div>
                <label>Carburant</label>
                <select name="type_carburant">
                    <option>Diesel</option><option>Essence</option><option>Électrique</option><option>Hybride</option><option>GPL</option>
                </select>
            </div>
            <div style="display:flex; align-items:flex-end; padding-bottom:2px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; margin:0; font-weight:normal;">
                    <input type="checkbox" name="est_communal" checked style="width:auto; margin:0;"> Véhicule communal (partagé)
                </label>
            </div>
        </div>
        <button type="submit" style="margin-top:16px;">Ajouter</button>
    </form>
</div>

<div class="filter-bar">
    <div class="search-wrap">
        <svg class="ico-search" viewBox="0 0 24 24"><path d="M21 20l-4.35-4.35A7.5 7.5 0 1 0 15.65 17.65L20 22l1-2zm-9-3a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
        <input type="text" id="searchVeh" placeholder="Immat., marque, modèle, affecté à…" oninput="filtrerVehicules()" autocomplete="off">
        <button class="clear-btn" id="clearSearchVeh" onclick="clearAndFilter('searchVeh','clearSearchVeh',filtrerVehicules)" title="Effacer">&times;</button>
    </div>
    <select id="filtreTypeVeh" onchange="filtrerVehicules()">
        <option value="">— Tous les types —</option>
        <option value="communal">🏢 Communaux</option>
        <option value="attitré">🔑 Attitrés</option>
    </select>
    <select id="filtreEtatVeh" onchange="filtrerVehicules()">
        <option value="">— Tous les états —</option>
        <option value="actif">Actifs</option>
        <option value="hors">Hors service</option>
    </select>
    <button class="btn-reset" onclick="resetVeh()">↺ Réinitialiser</button>
</div>

<div class="table-toolbar">
    <h3>Liste des véhicules</h3>
    <span class="count-badge" id="countVeh"></span>
</div>

<table id="tableVeh">
    <thead>
        <tr>
            <th class="sortable" onclick="sortTable('tableVeh',0)">Immat.</th>
            <th class="sortable" onclick="sortTable('tableVeh',1)">Véhicule</th>
            <th>Type</th>
            <th class="sortable" onclick="sortTable('tableVeh',3)">Affecté à</th>
            <th>Carburant</th>
            <th class="sortable" onclick="sortTable('tableVeh',5)">KM</th>
            <th>État</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($row = $vehicules->fetch_assoc()):
        $type_str = $row['est_communal'] ? 'communal' : 'attitré';
        $etat_str = $row['actif'] ? 'actif' : 'hors';
    ?>
    <tr class="<?php echo $row['actif'] ? '' : 'archived'; ?>"
        data-search="<?php echo strtolower(htmlspecialchars($row['immatriculation'].' '.$row['marque'].' '.$row['modele'].' '.($row['nom']??'').' '.($row['prenom']??''))); ?>"
        data-type="<?php echo $type_str; ?>"
        data-etat="<?php echo $etat_str; ?>">
        <td data-label="Immat."><strong><?php echo htmlspecialchars($row['immatriculation']); ?></strong></td>
        <td data-label="Véhicule"><?php echo htmlspecialchars($row['marque'].' '.$row['modele']); ?></td>
        <td data-label="Type">
            <?php if ($row['est_communal']): ?>
                <span class="badge badge-communal">Communal</span>
            <?php else: ?>
                <span class="badge badge-attitre">Attitré</span>
            <?php endif; ?>
        </td>
        <td data-label="Affecté à">
            <?php echo !empty($row['nom']) ? htmlspecialchars($row['prenom'].' '.$row['nom']) : '<span class="text-muted">—</span>'; ?>
        </td>
        <td data-label="Carburant"><small><?php echo htmlspecialchars($row['type_carburant']); ?></small></td>
        <td data-label="KM" data-km="<?php echo $row['kilometrage']; ?>"><?php echo number_format($row['kilometrage'],0,',',' '); ?> km</td>
        <td data-label="État">
            <span class="badge <?php echo $row['actif'] ? 'badge-active' : 'badge-inactive'; ?>">
                <?php echo $row['actif'] ? 'Actif' : 'Hors service'; ?>
            </span>
        </td>
        <td data-label="Actions">
            <?php if ($row['actif']): ?>
                <a href="parc.php?veh_action=toggle&id=<?php echo $row['id_vehicule']; ?>&tab=vehicules" class="action-btn return-btn" onclick="return confirm('Changer le type communal/attitré ?')" style="margin:2px;">🔁 Type</a>
                <a href="parc.php?veh_action=desactiver&id=<?php echo $row['id_vehicule']; ?>&tab=vehicules" class="action-btn cancel-btn" onclick="return confirm('Désactiver ?')" style="margin:2px;">Désactiver</a>
            <?php else: ?>
                <a href="parc.php?veh_action=reactiver&id=<?php echo $row['id_vehicule']; ?>&tab=vehicules" class="action-btn charge-btn" style="margin:2px;">Réactiver</a>
                <a href="parc.php?veh_action=supprimer&id=<?php echo $row['id_vehicule']; ?>&tab=vehicules" class="action-btn cancel-btn" onclick="return confirm('SUPPRIMER définitivement ?')" style="margin:2px;">Supprimer</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    <tr class="no-result" style="display:none;"><td colspan="8">Aucun véhicule ne correspond à cette recherche.</td></tr>
    </tbody>
</table>

<script>
function filtrerVehicules() {
    const kw   = document.getElementById('searchVeh').value.toLowerCase();
    const type = document.getElementById('filtreTypeVeh').value;
    const etat = document.getElementById('filtreEtatVeh').value;

    document.getElementById('clearSearchVeh').style.display = kw ? 'block' : 'none';
    document.getElementById('filtreTypeVeh').classList.toggle('active-filter', !!type);
    document.getElementById('filtreEtatVeh').classList.toggle('active-filter', !!etat);

    let nb = 0;
    document.querySelectorAll('#tableVeh tbody tr:not(.no-result)').forEach(tr => {
        const ok = (!kw || tr.dataset.search.includes(kw))
                && (!type || tr.dataset.type === type)
                && (!etat || tr.dataset.etat === etat);
        tr.style.display = ok ? '' : 'none';
        if (ok) nb++;
    });
    document.getElementById('countVeh').textContent = nb + ' véhicule' + (nb > 1 ? 's' : '');
    document.querySelector('#tableVeh .no-result').style.display = nb === 0 ? '' : 'none';
}
function resetVeh() {
    document.getElementById('searchVeh').value = '';
    document.getElementById('filtreTypeVeh').value = '';
    document.getElementById('filtreEtatVeh').value = '';
    filtrerVehicules();
}
document.addEventListener('DOMContentLoaded', filtrerVehicules);
document.getElementById('searchVeh').addEventListener('keydown', e => { if (e.key === 'Escape') resetVeh(); });
</script>

<!-- ================================================================ -->
<!-- ONGLET AFFECTATIONS                                               -->
<!-- ================================================================ -->
<?php elseif ($tab === 'affectations'): ?>

<div class="form-container" style="max-width:600px;">
    <h3 style="margin-top:0;">🔑 Nouvelle affectation</h3>
    <p class="text-muted" style="margin-bottom:16px;">Attribuer un véhicule attitré à un employé. Le véhicule passera automatiquement en type "Attitré".</p>
    <form action="parc.php" method="POST" id="formAff">
        <input type="hidden" name="ajout_affectation" value="1">

        <label>Employé <small class="text-muted">(sans véhicule attitré)</small></label>
        <div class="aff-search-group">
            <svg viewBox="0 0 24 24"><path d="M21 20l-4.35-4.35A7.5 7.5 0 1 0 15.65 17.65L20 22l1-2zm-9-3a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
            <input type="text" id="filterEmp" placeholder="Taper nom ou matricule…" oninput="filterSelect('selectEmp', this.value)" autocomplete="off">
        </div>
        <select name="id_employe" id="selectEmp" required class="aff-select">
            <option value="">— Choisir un employé —</option>
            <?php while ($e = $emp_sans_veh->fetch_assoc()): ?>
                <option value="<?php echo $e['id_employe']; ?>"
                        data-search="<?php echo strtolower(htmlspecialchars($e['nom'].' '.$e['prenom'].' '.$e['matricule'])); ?>">
                    <?php echo htmlspecialchars($e['nom'].' '.$e['prenom'].' ('.$e['matricule'].')'); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <label style="margin-top:20px;">Véhicule à attribuer</label>
        <div class="aff-search-group">
            <svg viewBox="0 0 24 24"><path d="M21 20l-4.35-4.35A7.5 7.5 0 1 0 15.65 17.65L20 22l1-2zm-9-3a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
            <input type="text" id="filterVehAff" placeholder="Taper marque, modèle ou immat…" oninput="filterSelect('selectVehAff', this.value)" autocomplete="off">
        </div>
        <select name="id_vehicule" id="selectVehAff" required class="aff-select">
            <option value="">— Choisir un véhicule —</option>
            <?php while ($v = $veh_attitrable->fetch_assoc()): ?>
                <option value="<?php echo $v['id_vehicule']; ?>"
                        data-search="<?php echo strtolower(htmlspecialchars($v['marque'].' '.$v['modele'].' '.$v['immatriculation'])); ?>">
                    <?php echo htmlspecialchars($v['marque'].' '.$v['modele'].' ('.$v['immatriculation'].')'); ?>
                </option>
            <?php endwhile; ?>
        </select>

        <button type="submit" style="margin-top:20px; width:100%;">✅ Enregistrer l'affectation</button>
    </form>
</div>

<script>
function filterSelect(selectId, kw) {
    kw = kw.toLowerCase();
    const sel = document.getElementById(selectId);
    let visible = 0;
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) { opt.style.display = ''; return; }
        const show = !kw || (opt.dataset.search || '').includes(kw);
        opt.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    // Réinitialise la sélection si l'option choisie est maintenant cachée
    if (sel.selectedOptions[0] && sel.selectedOptions[0].style.display === 'none') sel.value = '';
}
</script>

<div class="filter-bar" style="margin-top:32px;">
    <div class="search-wrap">
        <svg class="ico-search" viewBox="0 0 24 24"><path d="M21 20l-4.35-4.35A7.5 7.5 0 1 0 15.65 17.65L20 22l1-2zm-9-3a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
        <input type="text" id="searchAff" placeholder="Nom, prénom, matricule, immatriculation…" oninput="filtrerAff()" autocomplete="off">
        <button class="clear-btn" id="clearSearchAff" onclick="clearAndFilter('searchAff','clearSearchAff',filtrerAff)" title="Effacer">&times;</button>
    </div>
    <button class="btn-reset" onclick="resetAff()">↺ Réinitialiser</button>
</div>

<div class="table-toolbar">
    <h3>Affectations en cours</h3>
    <span class="count-badge" id="countAff"></span>
</div>

<table id="tableAff">
    <thead>
        <tr>
            <th class="sortable" onclick="sortTable('tableAff',0)">Employé</th>
            <th class="sortable" onclick="sortTable('tableAff',1)">Matricule</th>
            <th class="sortable" onclick="sortTable('tableAff',2)">Véhicule attitré</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($row = $affectations->fetch_assoc()): ?>
    <tr data-search="<?php echo strtolower(htmlspecialchars($row['nom'].' '.$row['prenom'].' '.$row['matricule'].' '.$row['immatriculation'])); ?>">
        <td data-label="Employé"><strong><?php echo htmlspecialchars($row['prenom'].' '.$row['nom']); ?></strong></td>
        <td data-label="Matricule"><small class="text-muted"><?php echo htmlspecialchars($row['matricule']); ?></small></td>
        <td data-label="Véhicule attitré">
            🔑 <?php echo htmlspecialchars($row['marque'].' '.$row['modele']); ?><br>
            <small class="text-muted"><?php echo htmlspecialchars($row['immatriculation']); ?></small>
        </td>
        <td data-label="Actions">
            <a href="parc.php?aff_action=supprimer&id=<?php echo $row['id_affectation']; ?>&tab=affectations"
               class="action-btn cancel-btn"
               onclick="return confirm('Libérer ce véhicule ? Il repassera en communal.')">Libérer</a>
        </td>
    </tr>
    <?php endwhile; ?>
    <tr class="no-result" style="display:none;"><td colspan="4">Aucune affectation ne correspond à cette recherche.</td></tr>
    </tbody>
</table>

<script>
function filtrerAff() {
    const kw = document.getElementById('searchAff').value.toLowerCase();
    document.getElementById('clearSearchAff').style.display = kw ? 'block' : 'none';
    let nb = 0;
    document.querySelectorAll('#tableAff tbody tr:not(.no-result)').forEach(tr => {
        const ok = !kw || tr.dataset.search.includes(kw);
        tr.style.display = ok ? '' : 'none';
        if (ok) nb++;
    });
    document.getElementById('countAff').textContent = nb + ' affectation' + (nb > 1 ? 's' : '');
    document.querySelector('#tableAff .no-result').style.display = nb === 0 ? '' : 'none';
}
function resetAff() {
    document.getElementById('searchAff').value = '';
    filtrerAff();
}
document.addEventListener('DOMContentLoaded', filtrerAff);
document.getElementById('searchAff').addEventListener('keydown', e => { if (e.key === 'Escape') resetAff(); });
</script>

<!-- ================================================================ -->
<!-- ONGLET CONGÉS / ABSENCES                                         -->
<!-- ================================================================ -->
<?php elseif ($tab === 'conges'): ?>

<div style="background:#e8f4fd; border:1px solid #bee5eb; border-radius:8px; padding:14px 18px; margin-bottom:20px; color:#0c5460;">
    ℹ️ Les absences sont importées automatiquement depuis votre système RH via script CSV.<br>
    <small>Ce tableau est en <strong>lecture seule</strong>. Pour toute correction, modifiez le fichier source CSV.</small>
</div>

<div class="filter-bar">
    <div class="search-wrap">
        <svg class="ico-search" viewBox="0 0 24 24"><path d="M21 20l-4.35-4.35A7.5 7.5 0 1 0 15.65 17.65L20 22l1-2zm-9-3a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
        <input type="text" id="searchAbs" placeholder="Rechercher un employé…" oninput="filtrerAbs()" autocomplete="off">
        <button class="clear-btn" id="clearSearchAbs" onclick="clearAndFilter('searchAbs','clearSearchAbs',filtrerAbs)" title="Effacer">&times;</button>
    </div>
    <select id="filtreStatutAbs" onchange="filtrerAbs()">
        <option value="">— Toutes les absences —</option>
        <option value="en-cours">🟢 En cours</option>
        <option value="a-venir">📅 À venir</option>
        <option value="aucune">Aucune à venir</option>
    </select>
    <button class="btn-reset" onclick="resetAbs()">↺ Réinitialiser</button>
</div>

<div class="table-toolbar">
    <h3>Absences par employé</h3>
    <span class="count-badge" id="countAbs"></span>
    <small class="text-muted" style="font-size:.78em; margin-left:4px;">Cliquez sur "Voir" pour le détail</small>
</div>

<?php if ($employes_aff->num_rows === 0): ?>
    <p class="text-muted" style="font-style:italic;">Aucun employé avec véhicule attitré.</p>
<?php else: ?>
<table id="tableAbs">
    <thead>
        <tr>
            <th class="sortable" onclick="sortTable('tableAbs',0)">Employé</th>
            <th>Matricule</th>
            <th>Véhicule</th>
            <th>Prochaine absence</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php while ($emp = $employes_aff->fetch_assoc()):
        $stmt_c = $conn->prepare("SELECT date_debut, date_fin, motif FROM conges WHERE id_employe=? ORDER BY date_debut ASC");
        $stmt_c->bind_param("i", $emp['id_employe']);
        $stmt_c->execute();
        $cres = $stmt_c->get_result();
        $all_conges = [];
        while ($c = $cres->fetch_assoc()) $all_conges[] = $c;

        $prochaine = null;
        foreach ($all_conges as $c) {
            if (strtotime($c['date_fin']) >= time()) { $prochaine = $c; break; }
        }
        $nb_conges = count($all_conges);
        $en_cours_now = $prochaine && strtotime($prochaine['date_debut']) <= time() && strtotime($prochaine['date_fin']) >= time();
        $statut_abs = $prochaine ? ($en_cours_now ? 'en-cours' : 'a-venir') : 'aucune';
    ?>
    <tr class="emp-row"
        data-id="<?php echo $emp['id_employe']; ?>"
        data-search="<?php echo strtolower(htmlspecialchars($emp['nom'].' '.$emp['prenom'].' '.$emp['matricule'])); ?>"
        data-statut="<?php echo $statut_abs; ?>">
        <td data-label="Employé"><strong><?php echo htmlspecialchars($emp['prenom'].' '.$emp['nom']); ?></strong></td>
        <td data-label="Matricule"><small class="text-muted"><?php echo htmlspecialchars($emp['matricule']); ?></small></td>
        <td data-label="Véhicule">
            🔑 <?php echo htmlspecialchars($emp['marque'].' '.$emp['modele']); ?><br>
            <small class="text-muted"><?php echo htmlspecialchars($emp['immatriculation']); ?></small>
        </td>
        <td data-label="Prochaine absence">
            <?php if ($prochaine): ?>
                <span style="background:<?php echo $en_cours_now?'#d4edda':'#fff3cd'; ?>;color:<?php echo $en_cours_now?'#155724':'#856404'; ?>;font-size:.82em;padding:3px 8px;border-radius:4px;">
                    <?php echo $en_cours_now ? '🟢 En cours' : '📅 À venir'; ?>
                    — du <?php echo date('d/m/Y', strtotime($prochaine['date_debut'])); ?> au <?php echo date('d/m/Y', strtotime($prochaine['date_fin'])); ?>
                </span>
            <?php else: ?>
                <span class="text-muted" style="font-size:.85em;">Aucune à venir</span>
            <?php endif; ?>
        </td>
        <td style="text-align:right;">
            <?php if ($nb_conges > 0): ?>
                <button onclick="toggleAbs(<?php echo $emp['id_employe']; ?>, this)" class="action-btn charge-btn" style="width:auto;margin:0;padding:6px 14px;font-size:.85em;">
                    Voir (<?php echo $nb_conges; ?>)
                </button>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php if ($nb_conges > 0): ?>
    <tr id="detail-<?php echo $emp['id_employe']; ?>" style="display:none; background:#f8f9fa;">
        <td colspan="5" style="padding:0;">
            <table style="width:100%; margin:0; box-shadow:none; border-radius:0; background:#f8f9fa;">
                <thead>
                    <tr style="background:#e9ecef;">
                        <th style="padding:8px 16px; font-size:.82em;">Période</th>
                        <th style="padding:8px 16px; font-size:.82em;">Motif</th>
                        <th style="padding:8px 16px; font-size:.82em;">Statut</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($all_conges as $c):
                    $en = strtotime($c['date_debut']) <= time() && strtotime($c['date_fin']) >= time();
                    $pa = strtotime($c['date_fin']) < time();
                ?>
                <tr style="border-bottom:1px solid #dee2e6;">
                    <td style="padding:8px 16px; font-size:.88em;">
                        Du <strong><?php echo date('d/m/Y', strtotime($c['date_debut'])); ?></strong>
                        au <strong><?php echo date('d/m/Y', strtotime($c['date_fin'])); ?></strong>
                    </td>
                    <td style="padding:8px 16px; font-size:.88em;"><?php echo htmlspecialchars($c['motif'] ?: '—'); ?></td>
                    <td style="padding:8px 16px;">
                        <?php if ($en): ?>
                            <span style="background:#d4edda;color:#155724;font-size:.8em;padding:2px 8px;border-radius:50px;">🟢 En cours</span>
                        <?php elseif ($pa): ?>
                            <span style="background:#e2e3e5;color:#383d41;font-size:.8em;padding:2px 8px;border-radius:50px;">Terminée</span>
                        <?php else: ?>
                            <span style="background:#fff3cd;color:#856404;font-size:.8em;padding:2px 8px;border-radius:50px;">📅 À venir</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </td>
    </tr>
    <?php endif; ?>
    <?php endwhile; ?>
    <tr class="no-result" style="display:none;"><td colspan="5">Aucun employé ne correspond à cette recherche.</td></tr>
    </tbody>
</table>
<?php endif; ?>

<script>
function filtrerAbs() {
    const kw     = document.getElementById('searchAbs').value.toLowerCase();
    const statut = document.getElementById('filtreStatutAbs').value;

    document.getElementById('clearSearchAbs').style.display = kw ? 'block' : 'none';
    document.getElementById('filtreStatutAbs').classList.toggle('active-filter', !!statut);

    let nb = 0;
    document.querySelectorAll('#tableAbs tbody tr.emp-row').forEach(tr => {
        const ok = (!kw || tr.dataset.search.includes(kw))
                && (!statut || tr.dataset.statut === statut);
        tr.style.display = ok ? '' : 'none';
        // Fermer le détail si la ligne est masquée
        const detail = document.getElementById('detail-' + tr.dataset.id);
        if (detail && !ok) detail.style.display = 'none';
        if (ok) nb++;
    });
    document.getElementById('countAbs').textContent = nb + ' employé' + (nb > 1 ? 's' : '');
    document.querySelector('#tableAbs .no-result').style.display = nb === 0 ? '' : 'none';
}
function resetAbs() {
    document.getElementById('searchAbs').value = '';
    document.getElementById('filtreStatutAbs').value = '';
    filtrerAbs();
}
function toggleAbs(id, btn) {
    const d = document.getElementById('detail-' + id);
    if (!d) return;
    const open = d.style.display !== 'none';
    d.style.display = open ? 'none' : 'table-row';
    btn.textContent = open ? btn.textContent.replace('▲','').trim() + ' ▼' : btn.textContent.replace('▼','').trim() + ' ▲';
}
document.addEventListener('DOMContentLoaded', filtrerAbs);
document.getElementById('searchAbs').addEventListener('keydown', e => { if (e.key === 'Escape') resetAbs(); });
</script>

<!-- ================================================================ -->
<!-- ONGLET VUE D'ENSEMBLE                                            -->
<!-- ================================================================ -->
<?php elseif ($tab === 'vue'): ?>

<?php
$parc_vue = $conn->query("
    SELECT v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal, v.kilometrage,
           e.nom, e.prenom, e.matricule,
           (SELECT c.date_debut FROM conges c WHERE c.id_employe=e.id_employe AND c.date_fin >= CURDATE() ORDER BY c.date_debut ASC LIMIT 1) AS conge_debut,
           (SELECT c.date_fin   FROM conges c WHERE c.id_employe=e.id_employe AND c.date_fin >= CURDATE() ORDER BY c.date_debut ASC LIMIT 1) AS conge_fin
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON v.id_vehicule=af.id_vehicule
    LEFT JOIN employes e ON af.id_employe=e.id_employe
    WHERE v.actif=1
    ORDER BY v.est_communal DESC, v.marque
");
?>

<div class="filter-bar">
    <div class="search-wrap">
        <svg class="ico-search" viewBox="0 0 24 24"><path d="M21 20l-4.35-4.35A7.5 7.5 0 1 0 15.65 17.65L20 22l1-2zm-9-3a5.5 5.5 0 1 1 0-11 5.5 5.5 0 0 1 0 11z"/></svg>
        <input type="text" id="rechercheVue" placeholder="Véhicule, immat., employé…" oninput="filtrerVue()" autocomplete="off">
        <button class="clear-btn" id="clearSearchVue" onclick="clearAndFilter('rechercheVue','clearSearchVue',filtrerVue)" title="Effacer">&times;</button>
    </div>
    <select id="filtreTypeVue" onchange="filtrerVue()">
        <option value="">— Tous les types —</option>
        <option value="communal">🏢 Communaux</option>
        <option value="attitré">🔑 Attitrés</option>
    </select>
    <select id="filtreDispoVue" onchange="filtrerVue()">
        <option value="">— Toutes dispo —</option>
        <option value="dispo">✅ Disponibles</option>
        <option value="indispo">🔴 Indisponibles</option>
    </select>
    <button class="btn-reset" onclick="resetVue()">↺ Réinitialiser</button>
</div>

<div class="table-toolbar">
    <h3>📊 Vue d'ensemble du parc</h3>
    <span class="count-badge" id="countVue"></span>
</div>

<table id="tableVue">
    <thead>
        <tr>
            <th class="sortable" onclick="sortTable('tableVue',0)">Véhicule</th>
            <th>Type</th>
            <th class="sortable" onclick="sortTable('tableVue',2)">Attitré à</th>
            <th>Disponibilité</th>
            <th class="sortable" onclick="sortTable('tableVue',4)">KM</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($v = $parc_vue->fetch_assoc()):
        $a_proprio    = !empty($v['nom']);
        $a_conge      = !empty($v['conge_debut']);
        $en_conge_now = $a_conge && strtotime($v['conge_debut']) <= time() && strtotime($v['conge_fin']) >= time();
        $type_str     = $v['est_communal'] ? 'communal' : 'attitré';
        if ($v['est_communal'])  $dispo_str = 'dispo';
        elseif ($en_conge_now)   $dispo_str = 'dispo';
        elseif ($a_conge)        $dispo_str = 'dispo';
        else                     $dispo_str = 'indispo';
    ?>
    <tr data-search="<?php echo strtolower(htmlspecialchars($v['marque'].' '.$v['modele'].' '.$v['immatriculation'].' '.($v['nom']??'').' '.($v['prenom']??''))); ?>"
        data-type="<?php echo $type_str; ?>"
        data-dispo="<?php echo $dispo_str; ?>">
        <td data-label="Véhicule">
            <strong><?php echo htmlspecialchars($v['marque'].' '.$v['modele']); ?></strong><br>
            <small class="text-muted"><?php echo htmlspecialchars($v['immatriculation']); ?></small>
        </td>
        <td data-label="Type">
            <?php if ($v['est_communal']): ?>
                <span style="background:#d4edda;color:#155724;font-size:.8em;padding:3px 8px;border-radius:50px;">🏢 Communal</span>
            <?php else: ?>
                <span style="background:#e2d9f3;color:#4a235a;font-size:.8em;padding:3px 8px;border-radius:50px;">🔑 Attitré</span>
            <?php endif; ?>
        </td>
        <td data-label="Attitré à">
            <?php echo $a_proprio ? htmlspecialchars($v['prenom'].' '.$v['nom']) : '<span class="text-muted">—</span>'; ?>
        </td>
        <td data-label="Disponibilité">
            <?php if ($v['est_communal']): ?>
                <span style="color:#28a745;font-weight:500;">✅ Toujours disponible</span>
            <?php elseif ($en_conge_now): ?>
                <span style="color:#155724;">🟢 Dispo jusqu'au <?php echo date('d/m/Y', strtotime($v['conge_fin'])); ?></span>
            <?php elseif ($a_conge): ?>
                <span style="color:#856404;">📅 Dispo du <?php echo date('d/m', strtotime($v['conge_debut'])); ?> au <?php echo date('d/m/Y', strtotime($v['conge_fin'])); ?></span>
            <?php else: ?>
                <span style="color:#dc3545;">🔴 Non disponible (pas d'absence prévue)</span>
            <?php endif; ?>
        </td>
        <td data-label="KM" data-km="<?php echo $v['kilometrage']; ?>"><?php echo number_format($v['kilometrage'],0,',',' '); ?> km</td>
    </tr>
    <?php endwhile; ?>
    <tr class="no-result" style="display:none;"><td colspan="5">Aucun véhicule ne correspond à cette recherche.</td></tr>
    </tbody>
</table>

<script>
function filtrerVue() {
    const kw    = document.getElementById('rechercheVue').value.toLowerCase();
    const type  = document.getElementById('filtreTypeVue').value;
    const dispo = document.getElementById('filtreDispoVue').value;

    document.getElementById('clearSearchVue').style.display = kw ? 'block' : 'none';
    document.getElementById('filtreTypeVue').classList.toggle('active-filter', !!type);
    document.getElementById('filtreDispoVue').classList.toggle('active-filter', !!dispo);

    let nb = 0;
    document.querySelectorAll('#tableVue tbody tr:not(.no-result)').forEach(tr => {
        const ok = (!kw    || tr.dataset.search.includes(kw))
                && (!type  || tr.dataset.type  === type)
                && (!dispo || tr.dataset.dispo  === dispo);
        tr.style.display = ok ? '' : 'none';
        if (ok) nb++;
    });
    document.getElementById('countVue').textContent = nb + ' véhicule' + (nb > 1 ? 's' : '');
    document.querySelector('#tableVue .no-result').style.display = nb === 0 ? '' : 'none';
}
function resetVue() {
    document.getElementById('rechercheVue').value = '';
    document.getElementById('filtreTypeVue').value = '';
    document.getElementById('filtreDispoVue').value = '';
    filtrerVue();
}
document.addEventListener('DOMContentLoaded', filtrerVue);
document.getElementById('rechercheVue').addEventListener('keydown', e => { if (e.key === 'Escape') resetVue(); });
</script>

<?php endif; ?>

<!-- Fonction partagée pour le bouton × des champs de recherche -->
<script>
function clearAndFilter(inputId, btnId, filterFn) {
    document.getElementById(inputId).value = '';
    document.getElementById(btnId).style.display = 'none';
    filterFn();
    document.getElementById(inputId).focus();
}

function sortTable(tableId, colIdx) {
    const tbody = document.querySelector('#' + tableId + ' tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr:not(.no-result)'));
    const ths   = document.querySelectorAll('#' + tableId + ' th.sortable');
    const th    = document.querySelectorAll('#' + tableId + ' th')[colIdx];

    const asc = !th.classList.contains('sort-asc');
    ths.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
    th.classList.add(asc ? 'sort-asc' : 'sort-desc');

    rows.sort((a, b) => {
        // Pour les colonnes KM on compare les data-km numériquement
        const aKm = a.cells[colIdx]?.dataset.km;
        const bKm = b.cells[colIdx]?.dataset.km;
        if (aKm !== undefined && bKm !== undefined) {
            return asc ? Number(aKm) - Number(bKm) : Number(bKm) - Number(aKm);
        }
        const av = a.cells[colIdx]?.textContent.trim().toLowerCase() || '';
        const bv = b.cells[colIdx]?.textContent.trim().toLowerCase() || '';
        return asc ? av.localeCompare(bv, 'fr') : bv.localeCompare(av, 'fr');
    });
    rows.forEach(r => tbody.appendChild(r));
    tbody.appendChild(tbody.querySelector('.no-result'));
}
</script>

<?php $conn->close(); include 'includes/footer.php'; ?>
