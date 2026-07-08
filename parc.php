<?php
require_once 'config.php';

// ================================================================
// TRAITEMENTS (avant tout output HTML)
// ================================================================

if (($_SESSION['user_role'] ?? '') !== 'Manager') {
    header('Location: index.php');
    exit();
}

// ── Restriction par établissement : Terracoop gère tout ; les autres sociétés uniquement leur parc ──
$parc_all  = !empty($IS_TERRACOOP_MANAGER);
$parc_etab = ($USER_ETAB_ID !== null) ? (int)$USER_ETAB_ID : 0;   // 0 = ne matche aucun établissement réel

function parc_veh_autorise($conn, $id_veh, $etab) {
    $s = $conn->prepare("SELECT 1 FROM vehicules WHERE id_vehicule = ? AND id_etablissement = ?");
    $s->bind_param("ii", $id_veh, $etab); $s->execute(); $s->store_result();
    $ok = $s->num_rows > 0; $s->close(); return $ok;
}
function parc_emp_autorise($conn, $id_emp, $etab) {
    $s = $conn->prepare("SELECT 1 FROM employes WHERE id_employe = ? AND id_etablissement = ?");
    $s->bind_param("ii", $id_emp, $etab); $s->execute(); $s->store_result();
    $ok = $s->num_rows > 0; $s->close(); return $ok;
}
function parc_refus() {
    header('Location: parc.php?message=' . urlencode("⛔ Action non autorisée : cet élément n'appartient pas à votre établissement.") . '&type=error');
    exit();
}

// Fragments de filtrage SQL (vides pour Terracoop)
$fVehW   = $parc_all ? "" : " WHERE v.id_etablissement = $parc_etab";
$fEmpW   = $parc_all ? "" : " WHERE e.id_etablissement = $parc_etab";
$fVehAnd = $parc_all ? "" : " AND v.id_etablissement = $parc_etab";
$fEmpAnd = $parc_all ? "" : " AND e.id_etablissement = $parc_etab";
$fNoAliasAnd = $parc_all ? "" : " AND id_etablissement = $parc_etab";

// --- VÉHICULES ---
if (isset($_POST['ajout_vehicule'])) {
    csrf_verify();
    $immat   = trim($_POST['immatriculation']);
    $marque  = trim($_POST['marque']);
    $modele  = trim($_POST['modele']);
    $carbu   = $_POST['type_carburant'];
    $commun  = isset($_POST['est_communal']) ? 1 : 0;
    // Établissement : forcé au sien pour une société, choisi (ou NULL) pour Terracoop
    if ($parc_all) {
        $etab_veh = ($_POST['id_etablissement'] ?? '') !== '' ? (int)$_POST['id_etablissement'] : null;
    } else {
        $etab_veh = $parc_etab;
    }
    $stmt = $conn->prepare("INSERT INTO vehicules (immatriculation, marque, modele, type_carburant, est_communal, actif, kilometrage, id_etablissement) VALUES (?,?,?,?,?,1,0,?)");
    $stmt->bind_param("ssssii", $immat, $marque, $modele, $carbu, $commun, $etab_veh);
    $ok = $stmt->execute();
    header('Location: parc.php?message=' . urlencode($ok ? '✅ Véhicule ajouté.' : '❌ Erreur (immatriculation déjà existante ?).') . '&type=' . ($ok ? 'success' : 'error'));
    exit();
}

if (isset($_POST['modifier_vehicule'])) {
    csrf_verify();
    $id     = (int)$_POST['id_vehicule'];
    if (!$parc_all && !parc_veh_autorise($conn, $id, $parc_etab)) parc_refus();
    $immat  = trim($_POST['immatriculation']);
    $marque = trim($_POST['marque']);
    $modele = trim($_POST['modele']);
    $carbu  = $_POST['type_carburant'];
    if ($parc_all) {
        $etab_veh = ($_POST['id_etablissement'] ?? '') !== '' ? (int)$_POST['id_etablissement'] : null;
        $stmt = $conn->prepare("UPDATE vehicules SET immatriculation=?, marque=?, modele=?, type_carburant=?, id_etablissement=? WHERE id_vehicule=?");
        $stmt->bind_param("ssssii", $immat, $marque, $modele, $carbu, $etab_veh, $id);
    } else {
        $stmt = $conn->prepare("UPDATE vehicules SET immatriculation=?, marque=?, modele=?, type_carburant=? WHERE id_vehicule=?");
        $stmt->bind_param("ssssi", $immat, $marque, $modele, $carbu, $id);
    }
    $ok = $stmt->execute();
    $stmt->close();
    header('Location: parc.php?message=' . urlencode($ok ? '✅ Véhicule modifié.' : '❌ Erreur (immatriculation déjà existante ?).') . '&type=' . ($ok ? 'success' : 'error') . '&tab=vehicules');
    exit();
}

if (isset($_GET['veh_action']) && isset($_GET['id'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        http_response_code(403); exit;
    }
    $id  = (int)$_GET['id'];
    if (!$parc_all && !parc_veh_autorise($conn, $id, $parc_etab)) parc_refus();
    $act = $_GET['veh_action'];
    if ($act === 'desactiver' || $act === 'reactiver') {
        $actif_v = ($act === 'reactiver') ? 1 : 0;
        $s = $conn->prepare("UPDATE vehicules SET actif=? WHERE id_vehicule=?");
        $s->bind_param("ii", $actif_v, $id); $s->execute(); $s->close();
    } elseif ($act === 'toggle') {
        $s = $conn->prepare("UPDATE vehicules SET est_communal=1-est_communal WHERE id_vehicule=?");
        $s->bind_param("i", $id); $s->execute(); $s->close();
    } elseif ($act === 'supprimer') {
        $chk = $conn->prepare("SELECT COUNT(*) as n FROM reservations WHERE id_vehicule=?");
        $chk->bind_param("i", $id); $chk->execute();
        $nb = $chk->get_result()->fetch_assoc()['n']; $chk->close();
        if ($nb > 0) {
            header('Location: parc.php?message=' . urlencode('❌ Ce véhicule a un historique de ' . $nb . ' réservation(s). Suppression impossible — il restera désactivé dans les archives.') . '&type=error&tab=vehicules');
            exit();
        }
        $s2 = $conn->prepare("DELETE FROM affectations_fixes WHERE id_vehicule=?");
        $s2->bind_param("i", $id); $s2->execute(); $s2->close();
        $s3 = $conn->prepare("DELETE FROM vehicules WHERE id_vehicule=? AND actif=0");
        $s3->bind_param("i", $id); $s3->execute(); $s3->close();
    }
    header('Location: parc.php?message=' . urlencode('✅ Véhicule mis à jour.') . '&type=success&tab=vehicules');
    exit();
}

// --- RÉVISION / CT ---
if (isset($_POST['modifier_revision_vehicule'])) {
    csrf_verify();
    $id       = (int)$_POST['id_vehicule'];
    if (!$parc_all && !parc_veh_autorise($conn, $id, $parc_etab)) parc_refus();
    $km_rev   = ($_POST['km_prochaine_revision'] ?? '') !== '' ? (int)$_POST['km_prochaine_revision'] : null;
    $km_seuil = ($_POST['km_seuil_alerte'] ?? '') !== '' ? (int)$_POST['km_seuil_alerte'] : 500;
    $date_ct  = ($_POST['date_prochain_ct'] ?? '') !== '' ? $_POST['date_prochain_ct'] : null;
    $jours_ct = ($_POST['nb_jours_alerte_ct'] ?? '') !== '' ? (int)$_POST['nb_jours_alerte_ct'] : 30;
    $stmt = $conn->prepare("UPDATE vehicules SET km_prochaine_revision=?, km_seuil_alerte_revision=?, date_prochain_ct=?, nb_jours_alerte_ct=? WHERE id_vehicule=?");
    $stmt->bind_param("iisii", $km_rev, $km_seuil, $date_ct, $jours_ct, $id);
    $ok = $stmt->execute();
    $stmt->close();
    header('Location: parc.php?message=' . urlencode($ok ? '✅ Paramètres de révision mis à jour.' : '❌ Erreur lors de la mise à jour.') . '&type=' . ($ok ? 'success' : 'error') . '&tab=vehicules');
    exit();
}

// --- AFFECTATIONS ---
if (isset($_POST['ajout_affectation'])) {
    csrf_verify();
    $id_emp = (int)$_POST['id_employe'];
    $id_veh = (int)$_POST['id_vehicule'];
    // Société : uniquement ses employés, et un véhicule de son parc OU non encore rattaché (communal à réclamer)
    if (!$parc_all) {
        if (!parc_emp_autorise($conn, $id_emp, $parc_etab)) parc_refus();
        $sv = $conn->prepare("SELECT id_etablissement FROM vehicules WHERE id_vehicule = ?");
        $sv->bind_param("i", $id_veh); $sv->execute();
        $vr = $sv->get_result()->fetch_assoc(); $sv->close();
        if (!$vr || ($vr['id_etablissement'] !== null && (int)$vr['id_etablissement'] !== $parc_etab)) parc_refus();
    }
    $chk_aff = $conn->prepare("SELECT COUNT(*) as n FROM affectations_fixes WHERE id_employe=? OR id_vehicule=?");
    $chk_aff->bind_param("ii", $id_emp, $id_veh);
    $chk_aff->execute();
    $check = $chk_aff->get_result()->fetch_assoc()['n'];
    $chk_aff->close();
    if ($check > 0) {
        header('Location: parc.php?message=' . urlencode('❌ Cet employé ou ce véhicule est déjà affecté.') . '&type=error&tab=affectations');
    } else {
        $stmt = $conn->prepare("INSERT INTO affectations_fixes (id_employe, id_vehicule) VALUES (?,?)");
        $stmt->bind_param("ii", $id_emp, $id_veh);
        $stmt->execute();
        $stmt->close();
        // Le véhicule devient attitré ET hérite AUTOMATIQUEMENT de l'établissement de l'employé
        $su = $conn->prepare("UPDATE vehicules v JOIN employes e ON e.id_employe = ? SET v.est_communal = 0, v.id_etablissement = e.id_etablissement WHERE v.id_vehicule = ?");
        $su->bind_param("ii", $id_emp, $id_veh); $su->execute(); $su->close();
        header('Location: parc.php?message=' . urlencode('✅ Affectation enregistrée.') . '&type=success&tab=affectations');
    }
    exit();
}

if (isset($_GET['aff_action']) && $_GET['aff_action'] === 'supprimer' && isset($_GET['id'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        http_response_code(403); exit;
    }
    $id = (int)$_GET['id'];
    $sa = $conn->prepare("SELECT id_vehicule FROM affectations_fixes WHERE id_affectation=?");
    $sa->bind_param("i", $id); $sa->execute();
    $row_aff = $sa->get_result()->fetch_assoc(); $sa->close();
    if (!$parc_all && (!$row_aff || !parc_veh_autorise($conn, (int)$row_aff['id_vehicule'], $parc_etab))) parc_refus();
    if ($row_aff) {
        $sv = $conn->prepare("UPDATE vehicules SET est_communal=1 WHERE id_vehicule=?");
        $sv->bind_param("i", $row_aff['id_vehicule']); $sv->execute(); $sv->close();
    }
    $sd = $conn->prepare("DELETE FROM affectations_fixes WHERE id_affectation=?");
    $sd->bind_param("i", $id); $sd->execute(); $sd->close();
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
    SELECT v.*, af.id_affectation, e.nom, e.prenom, e.matricule, et.nom AS etab_nom
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON v.id_vehicule=af.id_vehicule
    LEFT JOIN employes e ON af.id_employe=e.id_employe
    LEFT JOIN etablissements et ON et.id_etablissement = v.id_etablissement
    ORDER BY (v.id_etablissement IS NULL), v.id_etablissement, v.actif DESC, v.est_communal DESC, v.marque
");

$emp_sans_veh   = $conn->query("SELECT id_employe, nom, prenom, matricule FROM employes WHERE actif=1 AND id_employe NOT IN (SELECT id_employe FROM affectations_fixes)$fNoAliasAnd ORDER BY nom");
$fVehAttri = $parc_all ? "" : " AND (id_etablissement = $parc_etab OR id_etablissement IS NULL)";
$veh_attitrable = $conn->query("SELECT id_vehicule, marque, modele, immatriculation FROM vehicules WHERE actif=1 AND id_vehicule NOT IN (SELECT id_vehicule FROM affectations_fixes)$fVehAttri ORDER BY marque");

$affectations = $conn->query("
    SELECT af.id_affectation, e.nom, e.prenom, e.matricule, e.id_employe,
           v.marque, v.modele, v.immatriculation
    FROM affectations_fixes af
    JOIN employes e ON af.id_employe=e.id_employe
    JOIN vehicules v ON af.id_vehicule=v.id_vehicule
    $fEmpW
    ORDER BY e.nom
");

$employes_aff = $conn->query("
    SELECT e.id_employe, e.nom, e.prenom, e.matricule,
           v.marque, v.modele, v.immatriculation
    FROM employes e
    JOIN affectations_fixes af ON e.id_employe=af.id_employe
    JOIN vehicules v ON af.id_vehicule=v.id_vehicule
    WHERE e.actif=1$fEmpAnd
    ORDER BY e.nom
");

// Liste des établissements (pour le filtre) + nom de l'établissement du manager
$etablissements_liste = [];
$mon_etab_nom = '';
$re_etab = $conn->query("SELECT id_etablissement, nom FROM etablissements ORDER BY nom");
if ($re_etab) while ($x = $re_etab->fetch_assoc()) {
    $etablissements_liste[] = $x;
    if ((int)$x['id_etablissement'] === $parc_etab) $mon_etab_nom = $x['nom'];
}

if ($message) echo '<div class="message '.$message_type.'">'.htmlspecialchars($message).'</div>';
?>

<h2>Parc Automobile</h2>

<!-- ================================================================ -->
<!-- ONGLETS                                                           -->
<!-- ================================================================ -->
<div class="tabs" style="display:flex; gap:4px; margin-bottom:24px; border-bottom:2px solid #dee2e6;">
    <a href="parc.php?tab=vehicules"    class="tab-btn <?php echo $tab==='vehicules'    ? 'active' : ''; ?>">🚗 Véhicules</a>
    <a href="parc.php?tab=affectations" class="tab-btn <?php echo $tab==='affectations' ? 'active' : ''; ?>">🔑 Affectations</a>
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

/* ── Pagination ── */
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

    /* ── Pagination mobile ── */
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
</style>

<!-- ================================================================ -->
<!-- MODAL RÉVISION / CT                                               -->
<!-- ================================================================ -->
<div id="modalRevision" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;padding:28px;max-width:500px;width:92%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);">
        <h3 style="margin:0 0 4px;font-size:1.1em;">⚙️ Paramètres de maintenance</h3>
        <p id="rev_veh_label" style="margin:0 0 20px;font-weight:600;color:#0d3b8c;font-size:.95em;"></p>
        <form id="formRevision" action="parc.php" method="POST">
            <input type="hidden" name="modifier_revision_vehicule" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="id_vehicule" id="rev_id_vehicule" value="">

            <fieldset style="border:1px solid #dee2e6;border-radius:6px;padding:14px 16px;margin-bottom:16px;">
                <legend style="font-weight:600;font-size:.88em;color:#0d3b8c;padding:0 6px;">🔧 Révision générale (km)</legend>
                <div class="time-group" style="margin-top:8px;">
                    <div>
                        <label>Prochain seuil km</label>
                        <input type="number" name="km_prochaine_revision" id="rev_km_revision" min="0" step="500" placeholder="ex : 120000">
                    </div>
                    <div>
                        <label>Alerter à (km avant)</label>
                        <input type="number" name="km_seuil_alerte" id="rev_km_seuil" min="0" step="100" placeholder="500">
                    </div>
                </div>
                <small style="color:#6c757d;display:block;margin-top:6px;">Laisser vide pour désactiver le suivi révision.</small>
            </fieldset>

            <fieldset style="border:1px solid #dee2e6;border-radius:6px;padding:14px 16px;margin-bottom:20px;">
                <legend style="font-weight:600;font-size:.88em;color:#0d3b8c;padding:0 6px;">🔍 Contrôle technique (date)</legend>
                <div class="time-group" style="margin-top:8px;">
                    <div>
                        <label>Date du prochain CT</label>
                        <input type="date" name="date_prochain_ct" id="rev_date_ct">
                    </div>
                    <div>
                        <label>Alerter (jours avant)</label>
                        <input type="number" name="nb_jours_alerte_ct" id="rev_jours_ct" min="0" step="1" placeholder="30">
                    </div>
                </div>
                <small style="color:#6c757d;display:block;margin-top:6px;">Laisser vide pour désactiver le suivi CT.</small>
            </fieldset>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="fermerModalRevision()" style="background:#6c757d;">Annuler</button>
                <button type="submit">Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<script>
function ouvrirModalRevision(btn) {
    document.getElementById('rev_id_vehicule').value  = btn.dataset.id;
    document.getElementById('rev_veh_label').textContent = btn.dataset.label;
    document.getElementById('rev_km_revision').value  = btn.dataset.kmRev  || '';
    document.getElementById('rev_km_seuil').value     = btn.dataset.kmSeuil || '500';
    document.getElementById('rev_date_ct').value      = btn.dataset.dateCt  || '';
    document.getElementById('rev_jours_ct').value     = btn.dataset.joursCt || '30';
    const m = document.getElementById('modalRevision');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function fermerModalRevision() {
    document.getElementById('modalRevision').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('modalRevision').addEventListener('click', function(e) {
    if (e.target === this) fermerModalRevision();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fermerModalRevision();
});
</script>

<!-- ================================================================ -->
<!-- MODAL MODIFIER VÉHICULE                                           -->
<!-- ================================================================ -->
<div id="modalModifVeh" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;padding:28px;max-width:480px;width:92%;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25);">
        <h3 style="margin:0 0 20px;font-size:1.1em;">✏️ Modifier le véhicule</h3>
        <form id="formModifVeh" action="parc.php" method="POST">
            <input type="hidden" name="modifier_vehicule" value="1">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="id_vehicule" id="modif_id_vehicule" value="">

            <div class="time-group">
                <div>
                    <label>Immatriculation</label>
                    <input type="text" name="immatriculation" id="modif_immat" required placeholder="AA-123-BB">
                </div>
                <div>
                    <label>Marque</label>
                    <input type="text" name="marque" id="modif_marque" required placeholder="Renault">
                </div>
            </div>
            <div class="time-group" style="margin-top:10px;">
                <div>
                    <label>Modèle</label>
                    <input type="text" name="modele" id="modif_modele" required placeholder="Kangoo">
                </div>
                <div>
                    <label>Carburant</label>
                    <select name="type_carburant" id="modif_carbu">
                        <option value="Diesel">Diesel</option>
                        <option value="Essence">Essence</option>
                        <option value="Électrique">Électrique</option>
                        <option value="Hybride">Hybride</option>
                        <option value="GPL">GPL</option>
                    </select>
                </div>
            </div>

            <?php if ($parc_all): ?>
            <div style="margin-top:10px;">
                <label>Établissement</label>
                <select name="id_etablissement" id="modif_etab">
                    <option value="">— Sans établissement —</option>
                    <?php foreach ($etablissements_liste as $e): ?>
                        <option value="<?php echo (int)$e['id_etablissement']; ?>"><?php echo htmlspecialchars($e['nom']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px;">
                <button type="button" onclick="fermerModalModifVeh()" style="background:#6c757d;">Annuler</button>
                <button type="submit">💾 Enregistrer</button>
            </div>
        </form>
    </div>
</div>
<script>
function ouvrirModalModifVeh(btn) {
    document.getElementById('modif_id_vehicule').value = btn.dataset.id;
    document.getElementById('modif_immat').value       = btn.dataset.immat;
    document.getElementById('modif_marque').value      = btn.dataset.marque;
    document.getElementById('modif_modele').value      = btn.dataset.modele;
    document.getElementById('modif_carbu').value       = btn.dataset.carbu;
    const etabSel = document.getElementById('modif_etab');
    if (etabSel) etabSel.value = btn.dataset.etab || '';
    const m = document.getElementById('modalModifVeh');
    m.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function fermerModalModifVeh() {
    document.getElementById('modalModifVeh').style.display = 'none';
    document.body.style.overflow = '';
}
document.getElementById('modalModifVeh').addEventListener('click', function(e) {
    if (e.target === this) fermerModalModifVeh();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') fermerModalModifVeh();
});
</script>

<!-- ================================================================ -->
<!-- ONGLET VÉHICULES                                                  -->
<!-- ================================================================ -->
<?php if ($tab === 'vehicules'): ?>

<div class="form-container" style="max-width:700px;">
    <h3 style="margin-top:0;">➕ Ajouter un véhicule</h3>
    <form action="parc.php" method="POST">
        <input type="hidden" name="ajout_vehicule" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
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
    <select id="filtreEtabVeh" onchange="filtrerVehicules()">
        <option value="">— Tous les établissements —</option>
        <?php foreach ($etablissements_liste as $e): ?>
            <option value="<?php echo htmlspecialchars($e['nom']); ?>" <?php echo (!$parc_all && $e['nom'] === $mon_etab_nom) ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['nom']); ?></option>
        <?php endforeach; ?>
        <option value="(sans)">Sans établissement</option>
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
        // Terracoop gère tout ; une société ne gère que les véhicules de son établissement
        $peut_gerer = $parc_all || ((int)($row['id_etablissement'] ?? 0) === $parc_etab);
    ?>
    <tr class="<?php echo $row['actif'] ? '' : 'archived'; ?>"
        data-search="<?php echo strtolower(htmlspecialchars($row['immatriculation'].' '.$row['marque'].' '.$row['modele'].' '.($row['nom']??'').' '.($row['prenom']??''))); ?>"
        data-type="<?php echo $type_str; ?>"
        data-etat="<?php echo $etat_str; ?>"
        data-etab="<?php echo htmlspecialchars($row['etab_nom'] ?: '(sans)'); ?>">
        <td data-label="Immat."><strong><?php echo htmlspecialchars($row['immatriculation']); ?></strong></td>
        <td data-label="Véhicule">
            <?php echo htmlspecialchars($row['marque'].' '.$row['modele']); ?>
            <br><small class="text-muted"><?php echo $row['etab_nom'] ? '🏢 '.htmlspecialchars($row['etab_nom']) : '<span style="color:#dc3545;">⚠ sans établissement</span>'; ?></small>
        </td>
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
            <?php $ct = urlencode($_SESSION['csrf_token']); ?>
            <?php if (!$peut_gerer): ?>
                <span class="text-muted" title="Véhicule d'un autre établissement">🔒 Autre établissement</span>
            <?php elseif ($row['actif']): ?>
                <button class="action-btn"
                    onclick="ouvrirModalModifVeh(this)"
                    data-id="<?php echo $row['id_vehicule']; ?>"
                    data-immat="<?php echo htmlspecialchars($row['immatriculation']); ?>"
                    data-marque="<?php echo htmlspecialchars($row['marque']); ?>"
                    data-modele="<?php echo htmlspecialchars($row['modele']); ?>"
                    data-carbu="<?php echo htmlspecialchars($row['type_carburant']); ?>"
                    data-etab="<?php echo $row['id_etablissement'] !== null ? (int)$row['id_etablissement'] : ''; ?>"
                    style="margin:2px;background:#0d6efd;color:#fff;border:none;cursor:pointer;">✏️ Modifier</button>
                <button class="action-btn"
                    onclick="ouvrirModalRevision(this)"
                    data-id="<?php echo $row['id_vehicule']; ?>"
                    data-label="<?php echo htmlspecialchars($row['immatriculation'] . ' — ' . $row['marque'] . ' ' . $row['modele']); ?>"
                    data-km-rev="<?php echo $row['km_prochaine_revision'] ?? ''; ?>"
                    data-km-seuil="<?php echo $row['km_seuil_alerte_revision'] ?? '500'; ?>"
                    data-date-ct="<?php echo $row['date_prochain_ct'] ?? ''; ?>"
                    data-jours-ct="<?php echo $row['nb_jours_alerte_ct'] ?? '30'; ?>"
                    style="margin:2px;background:#6f42c1;color:#fff;border:none;cursor:pointer;">⚙️ Révision</button>
                <a href="parc.php?veh_action=toggle&id=<?php echo $row['id_vehicule']; ?>&tab=vehicules&csrf_token=<?php echo $ct; ?>" class="action-btn return-btn" onclick="return confirm('Changer le type communal/attitré ?')" style="margin:2px;">🔁 Type</a>
                <a href="parc.php?veh_action=desactiver&id=<?php echo $row['id_vehicule']; ?>&tab=vehicules&csrf_token=<?php echo $ct; ?>" class="action-btn cancel-btn" onclick="return confirm('Désactiver ?')" style="margin:2px;">Désactiver</a>
            <?php else: ?>
                <a href="parc.php?veh_action=reactiver&id=<?php echo $row['id_vehicule']; ?>&tab=vehicules&csrf_token=<?php echo $ct; ?>" class="action-btn charge-btn" style="margin:2px;">Réactiver</a>
                <a href="parc.php?veh_action=supprimer&id=<?php echo $row['id_vehicule']; ?>&tab=vehicules&csrf_token=<?php echo $ct; ?>" class="action-btn cancel-btn" onclick="return confirm('SUPPRIMER définitivement ?')" style="margin:2px;">Supprimer</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    <tr class="no-result" style="display:none;"><td colspan="8">Aucun véhicule ne correspond à cette recherche.</td></tr>
    </tbody>
</table>

<div id="paginationVeh"></div>

<script>
let vehPage    = 1;
let vehPerPage = 10;
let vehRows    = []; // lignes filtrées courantes

function filtrerVehicules() {
    const kw   = document.getElementById('searchVeh').value.toLowerCase();
    const type = document.getElementById('filtreTypeVeh').value;
    const etat = document.getElementById('filtreEtatVeh').value;
    const etab = document.getElementById('filtreEtabVeh').value;

    document.getElementById('clearSearchVeh').style.display = kw ? 'block' : 'none';
    document.getElementById('filtreTypeVeh').classList.toggle('active-filter', !!type);
    document.getElementById('filtreEtatVeh').classList.toggle('active-filter', !!etat);
    document.getElementById('filtreEtabVeh').classList.toggle('active-filter', !!etab);

    const all = Array.from(document.querySelectorAll('#tableVeh tbody tr:not(.no-result)'));
    vehRows = all.filter(tr =>
        (!kw   || tr.dataset.search.includes(kw))
     && (!type || tr.dataset.type === type)
     && (!etat || tr.dataset.etat === etat)
     && (!etab || tr.dataset.etab === etab)
    );
    all.forEach(tr => tr.style.display = 'none');
    vehPage = 1;
    afficherPageVeh();
}

function afficherPageVeh() {
    const total      = vehRows.length;
    const totalPages = Math.max(1, Math.ceil(total / vehPerPage));
    vehPage = Math.min(Math.max(1, vehPage), totalPages);

    const debut = (vehPage - 1) * vehPerPage;
    const fin   = debut + vehPerPage;
    vehRows.forEach((tr, i) => tr.style.display = (i >= debut && i < fin) ? '' : 'none');

    document.getElementById('countVeh').textContent = total + ' véhicule' + (total > 1 ? 's' : '');
    document.querySelector('#tableVeh .no-result').style.display = total === 0 ? '' : 'none';
    renderPaginationVeh(total, totalPages);
}

function renderPaginationVeh(total, totalPages) {
    const el = document.getElementById('paginationVeh');
    if (total === 0) { el.innerHTML = ''; return; }

    const debut = (vehPage - 1) * vehPerPage + 1;
    const fin   = Math.min(vehPage * vehPerPage, total);

    const opts = [10, 25, 50].map(n =>
        `<option value="${n}" ${vehPerPage===n?'selected':''}>${n}</option>`
    ).join('');

    const s = (extra, label, click, disabled) =>
        `<span class="pag-btn${extra}" style="cursor:${disabled?'default':'pointer'};opacity:${disabled?.35:1};" ${disabled?'':` onclick="${click}"`}>${label}</span>`;

    let pages = '';
    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || Math.abs(p - vehPage) <= 1) {
            pages += s(p === vehPage ? ' pag-active' : '', p, `vehPage=${p};afficherPageVeh();`, false);
        } else if (Math.abs(p - vehPage) === 2) {
            pages += '<span class="pag-dots">…</span>';
        }
    }

    el.innerHTML = `
    <div class="pagination-bar">
        <div class="pag-per-page">
            <span>Afficher</span>
            <select onchange="vehPerPage=parseInt(this.value);vehPage=1;afficherPageVeh();">${opts}</select>
            <span>par page</span>
        </div>
        <span class="pag-info">${debut}–${fin} sur <strong>${total}</strong></span>
        <div class="pag-buttons">
            ${s(' pag-nav', '← Préc.', 'vehPage--;afficherPageVeh();', vehPage <= 1)}
            ${pages}
            ${s(' pag-nav', 'Suiv. →', 'vehPage++;afficherPageVeh();', vehPage >= totalPages)}
        </div>
    </div>`;
}

function resetVeh() {
    document.getElementById('searchVeh').value = '';
    document.getElementById('filtreTypeVeh').value = '';
    document.getElementById('filtreEtatVeh').value = '';
    document.getElementById('filtreEtabVeh').value = '';
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

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
            <a href="parc.php?aff_action=supprimer&id=<?php echo $row['id_affectation']; ?>&tab=affectations&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>"
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
<!-- ONGLET VUE D'ENSEMBLE                                            -->
<!-- ================================================================ -->
<?php elseif ($tab === 'vue'): ?>

<?php
$parc_vue = $conn->query("
    SELECT v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal, v.kilometrage,
           v.km_prochaine_revision, v.km_seuil_alerte_revision, v.date_prochain_ct, v.nb_jours_alerte_ct,
           e.nom, e.prenom, e.matricule, et.nom AS etab_nom,
           (SELECT c.date_debut FROM conges c WHERE c.id_employe=e.id_employe AND c.date_fin >= CURDATE() ORDER BY c.date_debut ASC LIMIT 1) AS conge_debut,
           (SELECT c.date_fin   FROM conges c WHERE c.id_employe=e.id_employe AND c.date_fin >= CURDATE() ORDER BY c.date_debut ASC LIMIT 1) AS conge_fin
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON v.id_vehicule=af.id_vehicule
    LEFT JOIN employes e ON af.id_employe=e.id_employe
    LEFT JOIN etablissements et ON et.id_etablissement = v.id_etablissement
    WHERE v.actif=1
    ORDER BY
        CASE
            WHEN v.est_communal = 1 THEN 0
            WHEN (SELECT c.date_fin FROM conges c WHERE c.id_employe=e.id_employe AND c.date_fin >= CURDATE() ORDER BY c.date_debut ASC LIMIT 1) IS NOT NULL THEN 1
            ELSE 2
        END,
        v.marque
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
    <select id="filtreMaintenanceVue" onchange="filtrerVue()">
        <option value="">— Toutes maintenances —</option>
        <option value="urgent">🔴 Urgentes</option>
        <option value="alerte">⚠️ À prévoir</option>
        <option value="ok">✅ OK</option>
    </select>
    <select id="filtreEtabVue" onchange="filtrerVue()">
        <option value="">— Tous les établissements —</option>
        <?php foreach ($etablissements_liste as $e): ?>
            <option value="<?php echo htmlspecialchars($e['nom']); ?>" <?php echo (!$parc_all && $e['nom'] === $mon_etab_nom) ? 'selected' : ''; ?>><?php echo htmlspecialchars($e['nom']); ?></option>
        <?php endforeach; ?>
        <option value="(sans)">Sans établissement</option>
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
            <th>🔧 Révision</th>
            <th>🔍 CT</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $today_vue    = date('Y-m-d');
    $today_vue_ts = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
    while ($v = $parc_vue->fetch_assoc()):
        $a_proprio    = !empty($v['nom']);
        $a_conge      = !empty($v['conge_debut']);
        $en_conge_now = $a_conge && strtotime($v['conge_debut']) <= time() && strtotime($v['conge_fin']) >= time();
        $type_str     = $v['est_communal'] ? 'communal' : 'attitré';
        if ($v['est_communal'])  $dispo_str = 'dispo';
        elseif ($en_conge_now)   $dispo_str = 'dispo';
        elseif ($a_conge)        $dispo_str = 'dispo';
        else                     $dispo_str = 'indispo';

        // ── Calcul statut révision ──
        $km_actuel = (int)$v['kilometrage'];
        if ($v['km_prochaine_revision'] !== null) {
            $km_rev   = (int)$v['km_prochaine_revision'];
            $km_seuil = (int)($v['km_seuil_alerte_revision'] ?? 500);
            if ($km_actuel >= $km_rev) {
                $rev_badge    = '<span style="background:#f8d7da;color:#721c24;font-size:.78em;padding:2px 8px;border-radius:50px;white-space:nowrap;">🔴 Dépassée</span><br><small style="color:#6c757d;">prévu à&nbsp;'.number_format($km_rev,0,',',' ').'&nbsp;km</small>';
                $rev_data_str = 'urgent';
            } elseif ($km_actuel >= $km_rev - $km_seuil) {
                $reste        = $km_rev - $km_actuel;
                $rev_badge    = '<span style="background:#fff3cd;color:#856404;font-size:.78em;padding:2px 8px;border-radius:50px;white-space:nowrap;">⚠️ '.number_format($km_rev,0,',',' ').'&nbsp;km</span><br><small style="color:#6c757d;">dans&nbsp;'.number_format($reste,0,',',' ').'&nbsp;km</small>';
                $rev_data_str = 'alerte';
            } else {
                $reste        = $km_rev - $km_actuel;
                $rev_badge    = '<span style="background:#d4edda;color:#155724;font-size:.78em;padding:2px 8px;border-radius:50px;white-space:nowrap;">✅ OK</span><br><small style="color:#6c757d;">dans&nbsp;'.number_format($reste,0,',',' ').'&nbsp;km</small>';
                $rev_data_str = 'ok';
            }
        } else {
            $rev_badge    = '<span style="color:#adb5bd;font-size:.85em;">—</span>';
            $rev_data_str = '';
        }

        // ── Calcul statut CT ──
        if ($v['date_prochain_ct'] !== null) {
            $ct_ts   = mktime(0, 0, 0,
                (int)substr($v['date_prochain_ct'], 5, 2),
                (int)substr($v['date_prochain_ct'], 8, 2),
                (int)substr($v['date_prochain_ct'], 0, 4)
            );
            $ct_fr        = date('d/m/Y', $ct_ts);
            $jours_ct     = (int)($v['nb_jours_alerte_ct'] ?? 30);
            $jours_restants = (int)(($ct_ts - $today_vue_ts) / 86400);
            if ($v['date_prochain_ct'] <= $today_vue) {
                $ct_badge    = '<span style="background:#f8d7da;color:#721c24;font-size:.78em;padding:2px 8px;border-radius:50px;white-space:nowrap;">🔴 CT dépassé</span><br><small style="color:#6c757d;">'.$ct_fr.'</small>';
                $ct_data_str = 'urgent';
            } elseif ($jours_restants <= $jours_ct) {
                $ct_badge    = '<span style="background:#fff3cd;color:#856404;font-size:.78em;padding:2px 8px;border-radius:50px;white-space:nowrap;">⚠️ '.$ct_fr.'</span><br><small style="color:#6c757d;">dans&nbsp;'.$jours_restants.'&nbsp;j.</small>';
                $ct_data_str = 'alerte';
            } else {
                $ct_badge    = '<span style="background:#d4edda;color:#155724;font-size:.78em;padding:2px 8px;border-radius:50px;white-space:nowrap;">✅ '.$ct_fr.'</span>';
                $ct_data_str = 'ok';
            }
        } else {
            $ct_badge    = '<span style="color:#adb5bd;font-size:.85em;">—</span>';
            $ct_data_str = '';
        }

        // Statut maintenance global (le pire des deux)
        $maint_levels = ['urgent' => 2, 'alerte' => 1, 'ok' => 0, '' => -1];
        $rev_level = $maint_levels[$rev_data_str] ?? -1;
        $ct_level  = $maint_levels[$ct_data_str]  ?? -1;
        $maint_str = array_search(max($rev_level, $ct_level), $maint_levels) ?: '';
    ?>
    <tr data-search="<?php echo strtolower(htmlspecialchars($v['marque'].' '.$v['modele'].' '.$v['immatriculation'].' '.($v['nom']??'').' '.($v['prenom']??''))); ?>"
        data-type="<?php echo $type_str; ?>"
        data-dispo="<?php echo $dispo_str; ?>"
        data-maintenance="<?php echo $maint_str; ?>"
        data-etab="<?php echo htmlspecialchars($v['etab_nom'] ?: '(sans)'); ?>">
        <td data-label="Véhicule">
            <strong><?php echo htmlspecialchars($v['marque'].' '.$v['modele']); ?></strong><br>
            <small class="text-muted"><?php echo htmlspecialchars($v['immatriculation']); ?></small><br>
            <small class="text-muted"><?php echo $v['etab_nom'] ? '🏢 '.htmlspecialchars($v['etab_nom']) : '<span style="color:#dc3545;">⚠ sans établissement</span>'; ?></small>
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
        <td data-label="Révision"><?php echo $rev_badge; ?></td>
        <td data-label="CT"><?php echo $ct_badge; ?></td>
    </tr>
    <?php endwhile; ?>
    <tr class="no-result" style="display:none;"><td colspan="7">Aucun véhicule ne correspond à cette recherche.</td></tr>
    </tbody>
</table>

<div id="paginationVue"></div>

<script>
let vuePage    = 1;
let vuePerPage = 10;
let vueRows    = [];

function filtrerVue() {
    const kw         = document.getElementById('rechercheVue').value.toLowerCase();
    const type       = document.getElementById('filtreTypeVue').value;
    const dispo      = document.getElementById('filtreDispoVue').value;
    const maintenance= document.getElementById('filtreMaintenanceVue').value;
    const etab       = document.getElementById('filtreEtabVue').value;

    document.getElementById('clearSearchVue').style.display = kw ? 'block' : 'none';
    document.getElementById('filtreTypeVue').classList.toggle('active-filter', !!type);
    document.getElementById('filtreDispoVue').classList.toggle('active-filter', !!dispo);
    document.getElementById('filtreMaintenanceVue').classList.toggle('active-filter', !!maintenance);
    document.getElementById('filtreEtabVue').classList.toggle('active-filter', !!etab);

    const all = Array.from(document.querySelectorAll('#tableVue tbody tr:not(.no-result)'));
    vueRows = all.filter(tr =>
        (!kw          || tr.dataset.search.includes(kw))
     && (!type        || tr.dataset.type        === type)
     && (!dispo       || tr.dataset.dispo       === dispo)
     && (!maintenance || tr.dataset.maintenance === maintenance)
     && (!etab        || tr.dataset.etab        === etab)
    );
    all.forEach(tr => tr.style.display = 'none');
    vuePage = 1;
    afficherPageVue();
}

function afficherPageVue() {
    const total      = vueRows.length;
    const totalPages = Math.max(1, Math.ceil(total / vuePerPage));
    vuePage = Math.min(Math.max(1, vuePage), totalPages);

    const debut = (vuePage - 1) * vuePerPage;
    const fin   = debut + vuePerPage;
    vueRows.forEach((tr, i) => tr.style.display = (i >= debut && i < fin) ? '' : 'none');

    document.getElementById('countVue').textContent = total + ' véhicule' + (total > 1 ? 's' : '');
    document.querySelector('#tableVue .no-result').style.display = total === 0 ? '' : 'none';
    renderPaginationVue(total, totalPages);
}

function renderPaginationVue(total, totalPages) {
    const el = document.getElementById('paginationVue');
    if (total === 0) { el.innerHTML = ''; return; }

    const debut = (vuePage - 1) * vuePerPage + 1;
    const fin   = Math.min(vuePage * vuePerPage, total);

    const opts = [10, 25, 50].map(n =>
        `<option value="${n}" ${vuePerPage===n?'selected':''}>${n}</option>`
    ).join('');

    const s = (extra, label, click, disabled) =>
        `<span class="pag-btn${extra}" style="cursor:${disabled?'default':'pointer'};opacity:${disabled?.35:1};" ${disabled?'':` onclick="${click}"`}>${label}</span>`;

    let pages = '';
    for (let p = 1; p <= totalPages; p++) {
        if (p === 1 || p === totalPages || Math.abs(p - vuePage) <= 1) {
            pages += s(p === vuePage ? ' pag-active' : '', p, `vuePage=${p};afficherPageVue();`, false);
        } else if (Math.abs(p - vuePage) === 2) {
            pages += '<span class="pag-dots">…</span>';
        }
    }

    el.innerHTML = `
    <div class="pagination-bar">
        <div class="pag-per-page">
            <span>Afficher</span>
            <select onchange="vuePerPage=parseInt(this.value);vuePage=1;afficherPageVue();">${opts}</select>
            <span>par page</span>
        </div>
        <span class="pag-info">${debut}–${fin} sur <strong>${total}</strong></span>
        <div class="pag-buttons">
            ${s(' pag-nav', '← Préc.', 'vuePage--;afficherPageVue();', vuePage <= 1)}
            ${pages}
            ${s(' pag-nav', 'Suiv. →', 'vuePage++;afficherPageVue();', vuePage >= totalPages)}
        </div>
    </div>`;
}

function resetVue() {
    document.getElementById('rechercheVue').value = '';
    document.getElementById('filtreTypeVue').value = '';
    document.getElementById('filtreDispoVue').value = '';
    document.getElementById('filtreMaintenanceVue').value = '';
    document.getElementById('filtreEtabVue').value = '';
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

    // Remettre à jour la pagination après un tri
    if (tableId === 'tableVeh') {
        vehRows = rows.filter(tr => tr.style.display !== 'none');
        afficherPageVeh();
    } else if (tableId === 'tableVue') {
        vueRows = rows.filter(tr => tr.style.display !== 'none');
        afficherPageVue();
    }
}
</script>

<!-- ================================================================ -->
</script>

<?php $conn->close(); include 'includes/footer.php'; ?>
