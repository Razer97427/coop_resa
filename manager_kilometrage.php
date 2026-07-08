<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
if (($_SESSION['user_role'] ?? '') !== 'Manager') {
    header('Location: index.php?message=' . urlencode('Accès réservé aux managers.') . '&type=error');
    exit();
}
// Suivi kilométrique global réservé à Terracoop ; les managers des autres sociétés font le pointage individuel.
if (empty($IS_TERRACOOP_MANAGER)) {
    header('Location: pointage_kilometrage.php');
    exit();
}

$uid            = (int)$_SESSION['user_id'];
$mois_fr        = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$mois_courant   = (int)date('n');
$annee_courante = (int)date('Y');
$annee_min      = 2023;

// ── Tous les véhicules actifs ─────────────────────────────────────────────────
$res_veh = $conn->query("
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele, v.type_carburant, v.kilometrage,
           v.km_prochaine_revision, v.km_seuil_alerte_revision, v.date_prochain_ct, v.nb_jours_alerte_ct,
           e.nom AS emp_nom, e.prenom AS emp_prenom
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON af.id_vehicule = v.id_vehicule
    LEFT JOIN employes e ON e.id_employe = af.id_employe
    WHERE v.actif = 1
    ORDER BY v.marque, v.modele, v.immatriculation
");
$vehicules_liste = $res_veh ? $res_veh->fetch_all(MYSQLI_ASSOC) : [];

// ── Véhicule attitré du manager + statut mois courant ─────────────────────────
$stmt_own = $conn->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele, v.kilometrage
    FROM affectations_fixes af
    JOIN vehicules v ON v.id_vehicule = af.id_vehicule
    WHERE af.id_employe = ? AND v.actif = 1
    LIMIT 1
");
$stmt_own->bind_param("i", $uid);
$stmt_own->execute();
$vehicule_manager    = $stmt_own->get_result()->fetch_assoc();
$vehicule_manager_id = $vehicule_manager ? (int)$vehicule_manager['id_vehicule'] : 0;
$stmt_own->close();

$pointage_manager_mois = null;
if ($vehicule_manager) {
    $stmt_pm = $conn->prepare("
        SELECT kilometrage_reel, date_pointage
        FROM pointages_kilometrage
        WHERE id_vehicule = ? AND mois = ? AND annee = ?
    ");
    $stmt_pm->bind_param("iii", $vehicule_manager_id, $mois_courant, $annee_courante);
    $stmt_pm->execute();
    $pointage_manager_mois = $stmt_pm->get_result()->fetch_assoc();
    $stmt_pm->close();
}

// ── Sélection (GET ou POST en cas d'erreur) ───────────────────────────────────
$is_post     = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_km_manager']));
$id_veh_sel  = $is_post ? (int)($_POST['id_vehicule'] ?? 0) : (isset($_GET['id_vehicule']) ? (int)$_GET['id_vehicule'] : 0);
$mois_sel    = $is_post ? (int)($_POST['mois']        ?? 0) : (isset($_GET['mois'])        ? (int)$_GET['mois']        : 0);
$annee_sel   = $is_post ? (int)($_POST['annee']       ?? 0) : (isset($_GET['annee'])       ? (int)$_GET['annee']       : 0);
$km_val_post = $is_post ? filter_input(INPUT_POST, 'kilometrage_reel', FILTER_VALIDATE_INT) : null;

if ($mois_sel  < 1 || $mois_sel  > 12)                              $mois_sel  = 0;
if ($annee_sel < $annee_min || $annee_sel > $annee_courante)        $annee_sel = 0;
if ($annee_sel === $annee_courante && $mois_sel > $mois_courant)    $mois_sel  = 0;

$annee_extra = isset($_GET['annee_extra']) ? (int)$_GET['annee_extra'] : 0;
if ($annee_extra < $annee_min || $annee_extra >= $annee_courante - 1) $annee_extra = 0;

// ── Chargement des données ────────────────────────────────────────────────────
$vehicule_sel  = null;
$pointage_sel  = null;
$km_plancher   = 0;
$km_plafond    = 0;   // 0 = pas de plafond (aucun mois suivant renseigné)
$pointages_map = [];

if ($id_veh_sel > 0) {
    foreach ($vehicules_liste as $v) {
        if ((int)$v['id_vehicule'] === $id_veh_sel) { $vehicule_sel = $v; break; }
    }

    if ($vehicule_sel) {
        $stmt_all = $conn->prepare("
            SELECT pk.kilometrage_reel, pk.date_pointage, pk.mois, pk.annee,
                   e.nom, e.prenom
            FROM pointages_kilometrage pk
            JOIN employes e ON e.id_employe = pk.id_employe
            WHERE pk.id_vehicule = ?
        ");
        $stmt_all->bind_param("i", $id_veh_sel);
        $stmt_all->execute();
        foreach ($stmt_all->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $pointages_map[$row['annee'] . '-' . $row['mois']] = $row;
        }
        $stmt_all->close();

        if ($mois_sel > 0 && $annee_sel > 0) {
            $pointage_sel = $pointages_map[$annee_sel . '-' . $mois_sel] ?? null;

            $stmt_kp = $conn->prepare("
                SELECT MAX(kilometrage_reel) AS km_max
                FROM pointages_kilometrage
                WHERE id_vehicule = ? AND (annee < ? OR (annee = ? AND mois < ?))
            ");
            $stmt_kp->bind_param("iiii", $id_veh_sel, $annee_sel, $annee_sel, $mois_sel);
            $stmt_kp->execute();
            $row_kp = $stmt_kp->get_result()->fetch_assoc();
            $stmt_kp->close();
            $km_plancher = ($row_kp['km_max'] !== null)
                ? (int)$row_kp['km_max']
                : max(0, (int)$vehicule_sel['kilometrage']);

            // Plafond = plus petit pointage des mois SUIVANTS (cohérence lors d'une modification)
            $stmt_pf = $conn->prepare("
                SELECT MIN(kilometrage_reel) AS km_min
                FROM pointages_kilometrage
                WHERE id_vehicule = ? AND (annee > ? OR (annee = ? AND mois > ?))
            ");
            $stmt_pf->bind_param("iiii", $id_veh_sel, $annee_sel, $annee_sel, $mois_sel);
            $stmt_pf->execute();
            $row_pf = $stmt_pf->get_result()->fetch_assoc();
            $stmt_pf->close();
            $km_plafond = ($row_pf['km_min'] !== null) ? (int)$row_pf['km_min'] : 0;
        }
    }
}

// ── Statistiques globales ─────────────────────────────────────────────────────
$total_entered = 0;
$total_missing = 0;
if ($vehicule_sel) {
    $annees_stats = [$annee_courante, $annee_courante - 1];
    if ($annee_extra > 0) $annees_stats[] = $annee_extra;
    foreach ($annees_stats as $y) {
        for ($m = 1; $m <= 12; $m++) {
            if (($y > $annee_courante) || ($y === $annee_courante && $m > $mois_courant)) continue;
            if (isset($pointages_map["$y-$m"])) $total_entered++;
            else $total_missing++;
        }
    }
}

// ── Traitement POST ───────────────────────────────────────────────────────────
$erreur       = '';
$message      = isset($_GET['message']) ? htmlspecialchars(urldecode($_GET['message'])) : '';
$message_type = $_GET['type'] ?? 'success';

if ($is_post) {
    csrf_verify();
    $ok = true;

    if ($id_veh_sel <= 0 || $mois_sel < 1 || $annee_sel < $annee_min || $annee_sel > $annee_courante) {
        $erreur = "Sélection invalide."; $ok = false;
    }
    if ($ok && $annee_sel === $annee_courante && $mois_sel > $mois_courant) {
        $erreur = "Impossible de saisir un pointage pour une période future."; $ok = false;
    }
    // Modification autorisée (accès réservé aux managers Terracoop) : plus de verrouillage.
    if ($ok) {
        if ($km_val_post === false || $km_val_post === null || $km_val_post <= 0) {
            $erreur = "Veuillez saisir un kilométrage valide (nombre entier positif)."; $ok = false;
        } elseif ($km_val_post < $km_plancher) {
            $erreur = "Le kilométrage saisi (" . number_format($km_val_post, 0, ',', ' ') . " km) est inférieur au pointage du mois précédent (" . number_format($km_plancher, 0, ',', ' ') . " km)."; $ok = false;
        } elseif ($km_plafond > 0 && $km_val_post > $km_plafond) {
            $erreur = "Le kilométrage saisi (" . number_format($km_val_post, 0, ',', ' ') . " km) est supérieur au pointage d'un mois suivant (" . number_format($km_plafond, 0, ',', ' ') . " km)."; $ok = false;
        }
    }

    if ($ok) {
        if ($pointage_sel) {
            $stmt_up = $conn->prepare("UPDATE pointages_kilometrage SET kilometrage_reel = ?, id_employe = ?, date_pointage = CURDATE() WHERE id_vehicule = ? AND mois = ? AND annee = ?");
            $stmt_up->bind_param("iiiii", $km_val_post, $uid, $id_veh_sel, $mois_sel, $annee_sel);
            $stmt_up->execute();
            $stmt_up->close();
            $msg_ok = "Pointage de " . $mois_fr[$mois_sel] . " " . $annee_sel . " modifié avec succès.";
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO pointages_kilometrage (id_vehicule, id_employe, kilometrage_reel, date_pointage, mois, annee) VALUES (?, ?, ?, CURDATE(), ?, ?)");
            $stmt_ins->bind_param("iiiii", $id_veh_sel, $uid, $km_val_post, $mois_sel, $annee_sel);
            $stmt_ins->execute();
            $stmt_ins->close();
            $msg_ok = "Pointage de " . $mois_fr[$mois_sel] . " " . $annee_sel . " enregistré avec succès.";
        }

        // Recalcule le compteur du véhicule = plus grand pointage enregistré
        $stmt_vu = $conn->prepare("UPDATE vehicules SET kilometrage = (SELECT MAX(kilometrage_reel) FROM pointages_kilometrage WHERE id_vehicule = ?) WHERE id_vehicule = ?");
        $stmt_vu->bind_param("ii", $id_veh_sel, $id_veh_sel);
        $stmt_vu->execute();
        $stmt_vu->close();

        header('Location: manager_kilometrage.php?id_vehicule=' . $id_veh_sel . '&mois=' . $mois_sel . '&annee=' . $annee_sel . '&message=' . urlencode($msg_ok) . '&type=success');
        exit();
    }
}

include 'includes/header.php';
?>

<style>
.km-cell-missing { transition: box-shadow .15s, transform .12s; }
.km-cell-missing:hover { box-shadow: 0 3px 10px rgba(220,53,69,.28); transform: translateY(-1px); text-decoration: none; }
.km-cell-entered { }
.km-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 7px; }
@media (max-width: 480px) { .km-grid { grid-template-columns: repeat(3, 1fr); } }
</style>

<div class="page-narrow">

<?php if ($message): ?>
    <div class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo $message; ?></div>
<?php endif; ?>

<?php if ($erreur): ?>
    <div class="message error"><?php echo htmlspecialchars($erreur); ?></div>
<?php endif; ?>

<!-- ── Bannière statut mon véhicule ── -->
<?php if ($vehicule_manager): ?>
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 16px;
            background:<?php echo $pointage_manager_mois ? '#d4edda' : '#fff3cd'; ?>;
            border:1px solid <?php echo $pointage_manager_mois ? '#c3e6cb' : '#ffeeba'; ?>;
            border-radius:8px;margin-bottom:16px;font-size:0.85rem;">
    <svg viewBox="0 0 24 24" fill="<?php echo $pointage_manager_mois ? '#155724' : '#856404'; ?>" width="18" height="18" style="flex-shrink:0;">
        <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
    </svg>
    <span style="font-weight:600;color:#343a40;">Mon véhicule :</span>
    <span style="color:#555;"><?php echo htmlspecialchars($vehicule_manager['marque'] . ' ' . $vehicule_manager['modele'] . ' · ' . $vehicule_manager['immatriculation']); ?></span>
    <span style="margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="color:#6c757d;font-size:0.8rem;"><?php echo $mois_fr[$mois_courant] . ' ' . $annee_courante; ?> :</span>
        <?php if ($pointage_manager_mois): ?>
            <span style="color:#155724;font-weight:700;">✓ <?php echo number_format((int)$pointage_manager_mois['kilometrage_reel'], 0, ',', ' '); ?> km</span>
            <span style="color:#28a745;font-size:0.78rem;">saisi le <?php echo date('d/m/Y', strtotime($pointage_manager_mois['date_pointage'])); ?></span>
        <?php else: ?>
            <span style="color:#856404;font-weight:600;">⚠ Non renseigné</span>
            <a href="manager_kilometrage.php?id_vehicule=<?php echo $vehicule_manager_id; ?>&mois=<?php echo $mois_courant; ?>&annee=<?php echo $annee_courante; ?>#form-saisie"
               style="color:#fff;background:#dc3545;padding:3px 11px;border-radius:4px;font-size:0.78rem;text-decoration:none;font-weight:600;white-space:nowrap;">
                → Saisir
            </a>
        <?php endif; ?>
    </span>
</div>
<?php endif; ?>

<!-- ── Sélecteur véhicule ── -->
<div class="form-section" style="margin-bottom:20px;">
    <h2 style="margin:0 0 4px;font-size:1.15rem;">Suivi kilométrique — Saisie manuelle</h2>
    <p style="margin:0 0 16px;color:#6c757d;font-size:0.85rem;">
        Sélectionnez un véhicule pour visualiser l'état de ses pointages et compléter les mois manquants.
    </p>
    <form method="GET" action="manager_kilometrage.php">
        <label for="id_vehicule" style="margin-bottom:4px;font-size:0.85rem;">Véhicule</label>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <select name="id_vehicule" id="id_vehicule" onchange="this.form.submit()"
                style="flex:1;min-width:220px;max-width:500px;margin-bottom:0;">
                <option value="0">— Sélectionnez un véhicule —</option>
                <?php if ($vehicule_manager): ?>
                    <optgroup label="★ Mon véhicule">
                        <option value="<?php echo $vehicule_manager_id; ?>"
                            <?php echo $id_veh_sel === $vehicule_manager_id ? 'selected' : ''; ?>>
                            ★ <?php echo htmlspecialchars($vehicule_manager['marque'] . ' ' . $vehicule_manager['modele'] . ' · ' . $vehicule_manager['immatriculation']); ?>
                        </option>
                    </optgroup>
                    <optgroup label="Flotte complète">
                <?php endif; ?>
                <?php foreach ($vehicules_liste as $v):
                    if ($vehicule_manager_id > 0 && (int)$v['id_vehicule'] === $vehicule_manager_id) continue;
                ?>
                    <option value="<?php echo (int)$v['id_vehicule']; ?>"
                        <?php echo $id_veh_sel === (int)$v['id_vehicule'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($v['marque'] . ' ' . $v['modele'] . ' · ' . $v['immatriculation']); ?>
                        <?php if ($v['emp_nom']): ?>
                            (<?php echo htmlspecialchars($v['emp_prenom'] . ' ' . $v['emp_nom']); ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
                <?php if ($vehicule_manager): ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <?php if ($id_veh_sel > 0): ?>
                <a href="manager_kilometrage.php"
                   style="color:#6c757d;font-size:0.85rem;text-decoration:none;white-space:nowrap;">
                    ✕ Effacer
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($vehicule_sel): ?>

<!-- ── Carte véhicule + compteurs ── -->
<div class="form-section" style="margin-bottom:20px;">
    <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap;">
        <div style="width:48px;height:48px;background:var(--primary);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <svg viewBox="0 0 24 24" fill="#fff" width="26" height="26">
                <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
            </svg>
        </div>
        <div style="flex:1;min-width:160px;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                <strong style="font-size:1.08rem;"><?php echo htmlspecialchars($vehicule_sel['marque'] . ' ' . $vehicule_sel['modele']); ?></strong>
                <?php if ($vehicule_sel['emp_nom']): ?>
                    <span class="badge badge-attitre">Attitré : <?php echo htmlspecialchars($vehicule_sel['emp_prenom'] . ' ' . $vehicule_sel['emp_nom']); ?></span>
                <?php else: ?>
                    <span class="badge badge-employe">Non assigné</span>
                <?php endif; ?>
            </div>
            <div style="color:#555;font-size:0.83rem;display:flex;gap:16px;flex-wrap:wrap;">
                <span>Immat. : <strong><?php echo htmlspecialchars($vehicule_sel['immatriculation']); ?></strong></span>
                <span>Carburant : <strong><?php echo htmlspecialchars($vehicule_sel['type_carburant']); ?></strong></span>
                <span>Km actuel : <strong><?php echo number_format((int)$vehicule_sel['kilometrage'], 0, ',', ' '); ?> km</strong></span>
            </div>
        </div>
        <!-- Compteurs -->
        <div style="display:flex;gap:8px;flex-shrink:0;">
            <div style="text-align:center;background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:8px 14px;min-width:72px;">
                <div style="font-size:1.35rem;font-weight:700;color:#155724;line-height:1.1;"><?php echo $total_entered; ?></div>
                <div style="font-size:0.68rem;color:#155724;margin-top:2px;">Renseignés</div>
            </div>
            <?php if ($total_missing > 0): ?>
                <div style="text-align:center;background:#f8d7da;border:1px solid #f5c6cb;border-radius:8px;padding:8px 14px;min-width:72px;">
                    <div style="font-size:1.35rem;font-weight:700;color:#721c24;line-height:1.1;"><?php echo $total_missing; ?></div>
                    <div style="font-size:0.68rem;color:#721c24;margin-top:2px;">Manquants</div>
                </div>
            <?php else: ?>
                <div style="text-align:center;background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:8px 14px;min-width:72px;">
                    <div style="font-size:1.2rem;font-weight:700;color:#155724;line-height:1.3;">✓</div>
                    <div style="font-size:0.68rem;color:#155724;margin-top:2px;">Complet</div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Bloc Révision / CT ── -->
    <?php
    $today_maint    = date('Y-m-d');
    $today_maint_ts = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));
    $km_act         = (int)$vehicule_sel['kilometrage'];

    // Révision générale
    if ($vehicule_sel['km_prochaine_revision'] !== null) {
        $km_rev   = (int)$vehicule_sel['km_prochaine_revision'];
        $km_seuil = (int)($vehicule_sel['km_seuil_alerte_revision'] ?? 500);
        if ($km_act >= $km_rev) {
            $rev_bg = '#f8d7da'; $rev_border = '#f5c6cb'; $rev_color = '#721c24';
            $rev_titre = '🔴 Révision dépassée';
            $rev_sous  = 'Prévue à ' . number_format($km_rev, 0, ',', ' ') . ' km · actuel : ' . number_format($km_act, 0, ',', ' ') . ' km';
        } elseif ($km_act >= $km_rev - $km_seuil) {
            $rev_bg = '#fff3cd'; $rev_border = '#ffeeba'; $rev_color = '#856404';
            $reste = $km_rev - $km_act;
            $rev_titre = '⚠️ Révision à prévoir';
            $rev_sous  = 'Seuil : ' . number_format($km_rev, 0, ',', ' ') . ' km · dans ' . number_format($reste, 0, ',', ' ') . ' km';
        } else {
            $rev_bg = '#d4edda'; $rev_border = '#c3e6cb'; $rev_color = '#155724';
            $reste = $km_rev - $km_act;
            $rev_titre = '✅ Révision OK';
            $rev_sous  = 'Seuil : ' . number_format($km_rev, 0, ',', ' ') . ' km · dans ' . number_format($reste, 0, ',', ' ') . ' km';
        }
        $rev_configured = true;
    } else {
        $rev_configured = false;
        $rev_bg = '#f8f9fa'; $rev_border = '#dee2e6'; $rev_color = '#adb5bd';
        $rev_titre = '— Révision';
        $rev_sous  = 'Non configuré';
    }

    // Contrôle technique
    if ($vehicule_sel['date_prochain_ct'] !== null) {
        $ct_ts  = mktime(0, 0, 0,
            (int)substr($vehicule_sel['date_prochain_ct'], 5, 2),
            (int)substr($vehicule_sel['date_prochain_ct'], 8, 2),
            (int)substr($vehicule_sel['date_prochain_ct'], 0, 4)
        );
        $ct_fr   = date('d/m/Y', $ct_ts);
        $nb_j_ct = (int)($vehicule_sel['nb_jours_alerte_ct'] ?? 30);
        $j_rest  = (int)(($ct_ts - $today_maint_ts) / 86400);
        if ($vehicule_sel['date_prochain_ct'] <= $today_maint) {
            $ct_bg = '#f8d7da'; $ct_border = '#f5c6cb'; $ct_color = '#721c24';
            $ct_titre = '🔴 CT dépassé';
            $ct_sous  = 'Était prévu le ' . $ct_fr;
        } elseif ($j_rest <= $nb_j_ct) {
            $ct_bg = '#fff3cd'; $ct_border = '#ffeeba'; $ct_color = '#856404';
            $ct_titre = '⚠️ CT le ' . $ct_fr;
            $ct_sous  = 'Dans ' . $j_rest . ' jour' . ($j_rest > 1 ? 's' : '');
        } else {
            $ct_bg = '#d4edda'; $ct_border = '#c3e6cb'; $ct_color = '#155724';
            $ct_titre = '✅ CT le ' . $ct_fr;
            $ct_sous  = 'Dans ' . $j_rest . ' jour' . ($j_rest > 1 ? 's' : '');
        }
        $ct_configured = true;
    } else {
        $ct_configured = false;
        $ct_bg = '#f8f9fa'; $ct_border = '#dee2e6'; $ct_color = '#adb5bd';
        $ct_titre = '— Contrôle technique';
        $ct_sous  = 'Non configuré';
    }
    ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;width:100%;margin-top:14px;padding-top:14px;border-top:1px solid #e9ecef;">
        <div style="flex:1;min-width:200px;background:<?php echo $rev_bg; ?>;border:1px solid <?php echo $rev_border; ?>;border-radius:8px;padding:10px 14px;">
            <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:<?php echo $rev_color; ?>;margin-bottom:5px;">🔧 Révision générale</div>
            <div style="font-size:0.88rem;font-weight:700;color:<?php echo $rev_color; ?>;"><?php echo $rev_titre; ?></div>
            <div style="font-size:0.75rem;color:<?php echo $rev_color; ?>;margin-top:3px;opacity:.9;"><?php echo $rev_sous; ?></div>
        </div>
        <div style="flex:1;min-width:200px;background:<?php echo $ct_bg; ?>;border:1px solid <?php echo $ct_border; ?>;border-radius:8px;padding:10px 14px;">
            <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:<?php echo $ct_color; ?>;margin-bottom:5px;">🔍 Contrôle technique</div>
            <div style="font-size:0.88rem;font-weight:700;color:<?php echo $ct_color; ?>;"><?php echo $ct_titre; ?></div>
            <div style="font-size:0.75rem;color:<?php echo $ct_color; ?>;margin-top:3px;opacity:.9;"><?php echo $ct_sous; ?></div>
        </div>
        <?php if (!$rev_configured && !$ct_configured): ?>
        <div style="width:100%;font-size:0.75rem;color:#adb5bd;margin-top:4px;">
            Paramétrable depuis <a href="parc.php?tab=vehicules" style="color:#6c757d;">Parc → bouton ⚙️ Révision</a>.
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Légende ── -->
<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:16px;font-size:0.78rem;color:#555;">
    <span style="display:flex;align-items:center;gap:5px;">
        <span style="display:inline-block;width:12px;height:12px;background:#d4edda;border:1px solid #c3e6cb;border-radius:3px;"></span> Renseigné — cliquer pour modifier
    </span>
    <span style="display:flex;align-items:center;gap:5px;">
        <span style="display:inline-block;width:12px;height:12px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:3px;"></span> Manquant — cliquer pour saisir
    </span>
    <span style="display:flex;align-items:center;gap:5px;">
        <span style="display:inline-block;width:12px;height:12px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:3px;opacity:.5;"></span> Période future
    </span>
</div>

<!-- ── Grille par année ── -->
<?php
$annees_affichage = [$annee_courante, $annee_courante - 1];
if ($annee_extra > 0) $annees_affichage[] = $annee_extra;
foreach ($annees_affichage as $y):
    $is_extra_year = ($annee_extra > 0 && $y === $annee_extra);
    $show_grid = true;
    if ($is_extra_year) {
        $extra_has_data = false;
        for ($m = 1; $m <= 12; $m++) {
            if (isset($pointages_map[$y . '-' . $m])) { $extra_has_data = true; break; }
        }
        if (!$extra_has_data) $show_grid = false;
    }
    if ($show_grid) {
        $y_entered = 0; $y_missing = 0;
        for ($m = 1; $m <= 12; $m++) {
            if (($y > $annee_courante) || ($y === $annee_courante && $m > $mois_courant)) continue;
            isset($pointages_map["$y-$m"]) ? $y_entered++ : $y_missing++;
        }
    }
?>
<?php if (!$show_grid): ?>
<div style="padding:14px 16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;margin-bottom:22px;font-size:0.85rem;color:#6c757d;">
    Aucun pointage enregistré pour <strong><?php echo $y; ?></strong>.
</div>
<?php else: ?>

<div style="margin-bottom:22px;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <span style="font-size:0.95rem;font-weight:700;color:#343a40;"><?php echo $y; ?></span>
        <?php if ($y_missing === 0 && ($y_entered > 0 || $y < $annee_courante)): ?>
            <span class="badge badge-active" style="font-size:0.68rem;padding:3px 8px;">✓ Complet</span>
        <?php elseif ($y_missing > 0): ?>
            <span class="badge badge-inactive" style="font-size:0.68rem;padding:3px 8px;">
                <?php echo $y_missing; ?> manquant<?php echo $y_missing > 1 ? 's' : ''; ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="km-grid">
        <?php for ($m = 1; $m <= 12; $m++):
            $is_future   = ($y > $annee_courante) || ($y === $annee_courante && $m > $mois_courant);
            $is_current  = ($y === $annee_courante && $m === $mois_courant);
            $is_selected = ($annee_sel > 0 && $y === $annee_sel && $m === $mois_sel);
            $entry       = $pointages_map["$y-$m"] ?? null;
            $cell_url    = 'manager_kilometrage.php?id_vehicule=' . $id_veh_sel . '&mois=' . $m . '&annee=' . $y . ($annee_extra > 0 ? '&annee_extra=' . $annee_extra : '') . '#form-saisie';
        ?>

        <?php if ($is_future): ?>
            <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:9px 10px;min-height:70px;opacity:.4;pointer-events:none;">
                <div style="font-size:0.76rem;font-weight:600;color:#adb5bd;margin-bottom:3px;"><?php echo $mois_fr[$m]; ?></div>
                <div style="font-size:0.7rem;color:#ced4da;">—</div>
            </div>

        <?php elseif ($entry): ?>
            <a href="<?php echo $cell_url; ?>" class="km-cell-entered"
                 style="display:block;text-decoration:none;
                        background:<?php echo $is_selected ? '#b8dfc4' : '#d4edda'; ?>;
                        border:<?php echo $is_current ? '2px solid var(--primary)' : ($is_selected ? '2px solid #155724' : '1px solid #c3e6cb'); ?>;
                        border-radius:8px;padding:9px 10px;min-height:70px;">
                <div style="font-size:0.76rem;font-weight:600;color:#155724;margin-bottom:3px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                    <?php echo $mois_fr[$m]; ?>
                    <?php if ($is_current): ?>
                        <span style="font-size:0.6rem;background:var(--primary);color:#fff;border-radius:3px;padding:1px 5px;">Ce mois</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.82rem;font-weight:700;color:#155724;"><?php echo number_format((int)$entry['kilometrage_reel'], 0, ',', ' '); ?> km</div>
                <div style="font-size:0.65rem;color:#28a745;margin-top:3px;"><?php echo date('d/m/Y', strtotime($entry['date_pointage'])); ?></div>
                <div style="font-size:0.6rem;color:#6c757d;margin-top:1px;"><?php echo htmlspecialchars($entry['prenom'] . ' ' . $entry['nom']); ?></div>
                <div style="font-size:0.62rem;font-weight:600;margin-top:4px;color:<?php echo $is_selected ? '#0d6efd' : '#198754'; ?>;"><?php echo $is_selected ? '▼ Modifier' : '✎ Modifier'; ?></div>
            </a>

        <?php else: ?>
            <a href="<?php echo $cell_url; ?>" class="km-cell-missing"
               style="display:block;
                      background:<?php echo $is_selected ? '#f1aeb5' : '#f8d7da'; ?>;
                      border:<?php echo $is_selected ? '2px solid #0d6efd' : ($is_current ? '2px solid #dc3545' : '1px solid #f5c6cb'); ?>;
                      border-radius:8px;padding:9px 10px;min-height:70px;">
                <div style="font-size:0.76rem;font-weight:600;color:#721c24;margin-bottom:3px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                    <?php echo $mois_fr[$m]; ?>
                    <?php if ($is_current): ?>
                        <span style="font-size:0.6rem;background:#dc3545;color:#fff;border-radius:3px;padding:1px 5px;">Ce mois</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:0.7rem;color:#dc3545;">Non renseigné</div>
                <div style="font-size:0.67rem;font-weight:600;margin-top:5px;color:<?php echo $is_selected ? '#0d6efd' : '#c82333'; ?>;">
                    <?php echo $is_selected ? '▼ Formulaire' : '→ Saisir'; ?>
                </div>
            </a>
        <?php endif; ?>

        <?php endfor; ?>
    </div>
</div>

<?php endif; ?>
<?php endforeach; ?>

<!-- ── Recherche année antérieure ── -->
<?php if ($annee_courante - 2 >= $annee_min): ?>
<div style="margin-bottom:20px;padding:12px 16px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;">
    <form method="GET" action="manager_kilometrage.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <input type="hidden" name="id_vehicule" value="<?php echo $id_veh_sel; ?>">
        <?php if ($annee_sel > 0 && $mois_sel > 0): ?>
            <input type="hidden" name="mois" value="<?php echo $mois_sel; ?>">
            <input type="hidden" name="annee" value="<?php echo $annee_sel; ?>">
        <?php endif; ?>
        <label style="font-size:0.85rem;color:#495057;margin:0;font-weight:600;">Années antérieures :</label>
        <input type="number" name="annee_extra"
               min="<?php echo $annee_min; ?>" max="<?php echo $annee_courante - 2; ?>"
               value="<?php echo $annee_extra > 0 ? $annee_extra : ''; ?>"
               style="width:90px;margin:0;"
               placeholder="<?php echo $annee_min . '–' . ($annee_courante - 2); ?>">
        <button type="submit" style="margin:0;padding:6px 16px;font-size:0.85rem;max-width:none;width:auto;">Rechercher</button>
        <?php if ($annee_extra > 0): ?>
            <a href="manager_kilometrage.php?id_vehicule=<?php echo $id_veh_sel; ?><?php echo ($annee_sel > 0 && $mois_sel > 0) ? '&mois=' . $mois_sel . '&annee=' . $annee_sel : ''; ?>"
               style="color:#6c757d;font-size:0.85rem;text-decoration:none;white-space:nowrap;">✕ Masquer <?php echo $annee_extra; ?></a>
        <?php endif; ?>
    </form>
</div>
<?php endif; ?>

<!-- ── Formulaire de saisie ── -->
<?php if ($mois_sel > 0 && $annee_sel > 0): ?>

<div id="form-saisie" class="form-section"
     style="margin-top:8px;border:2px solid <?php echo $pointage_sel ? '#198754' : '#0d6efd'; ?>;">

    <h3 style="margin-top:0;margin-bottom:14px;font-size:1rem;color:<?php echo $pointage_sel ? '#198754' : '#0d6efd'; ?>;">
        ✎ <?php echo $pointage_sel ? 'Modifier' : 'Saisir'; ?> —
        <?php echo $mois_fr[$mois_sel] . ' ' . $annee_sel; ?>
        — <?php echo htmlspecialchars($vehicule_sel['marque'] . ' ' . $vehicule_sel['modele']); ?>
    </h3>

    <?php if ($pointage_sel): ?>
        <p style="margin:0 0 12px;font-size:0.83rem;color:#155724;background:#d4edda;border:1px solid #c3e6cb;border-radius:6px;padding:8px 12px;">
            Pointage actuel : <strong><?php echo number_format((int)$pointage_sel['kilometrage_reel'], 0, ',', ' '); ?> km</strong>,
            saisi le <?php echo date('d/m/Y', strtotime($pointage_sel['date_pointage'])); ?>
            par <?php echo htmlspecialchars($pointage_sel['prenom'] . ' ' . $pointage_sel['nom']); ?>.
        </p>
    <?php endif; ?>

    <form method="POST" action="manager_kilometrage.php" id="km-form">
        <input type="hidden" name="csrf_token"       value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="submit_km_manager" value="1">
        <input type="hidden" name="id_vehicule"      value="<?php echo $id_veh_sel; ?>">
        <input type="hidden" name="mois"             value="<?php echo $mois_sel; ?>">
        <input type="hidden" name="annee"            value="<?php echo $annee_sel; ?>">

        <label for="kilometrage_reel" style="font-size:0.875rem;">
            Kilométrage réel pour <?php echo $mois_fr[$mois_sel] . ' ' . $annee_sel; ?> (km)
        </label>
        <input type="number" id="kilometrage_reel" name="kilometrage_reel"
            min="<?php echo $km_plancher; ?>" <?php if ($km_plafond > 0) echo 'max="' . $km_plafond . '"'; ?> step="1" required
            style="max-width:260px;"
            value="<?php echo ($km_val_post !== null && $km_val_post !== false) ? (int)$km_val_post : ($pointage_sel ? (int)$pointage_sel['kilometrage_reel'] : ($km_plancher > 0 ? $km_plancher : '')); ?>"
            placeholder="<?php echo $km_plancher > 0 ? number_format($km_plancher, 0, ',', ' ') . ' km min.' : 'Ex. : 12 000'; ?>">

        <p style="margin:5px 0 0;font-size:0.8rem;color:#6c757d;">
            <?php if ($km_plancher > 0): ?>Minimum : <?php echo number_format($km_plancher, 0, ',', ' '); ?> km (mois précédent).<?php endif; ?>
            <?php if ($km_plafond > 0): ?> Maximum : <?php echo number_format($km_plafond, 0, ',', ' '); ?> km (mois suivant).<?php endif; ?>
        </p>

        <p style="margin:12px 0 0;font-size:0.855rem;color:#856404;background:#fff3cd;padding:10px 14px;border-radius:6px;border:1px solid #ffeeba;line-height:1.5;">
            <strong>Attention :</strong> ce pointage sert de base au suivi kilométrique. Vérifiez la valeur avant de valider ; elle restera cohérente avec les mois précédent et suivant.
        </p>

        <button type="button" onclick="demanderConfirmation()" style="max-width:260px;margin-top:16px;">
            <?php echo $pointage_sel ? 'Modifier le pointage' : 'Enregistrer le pointage'; ?>
        </button>
    </form>
</div>

<?php elseif ($total_missing > 0): ?>
    <div style="text-align:center;padding:14px 20px;background:#fff3cd;border:1px solid #ffeeba;border-radius:8px;margin-top:4px;">
        <p style="margin:0;font-size:0.875rem;color:#856404;">
            <strong><?php echo $total_missing; ?> mois</strong> sans pointage — cliquez sur une cellule rouge pour saisir le kilométrage.
        </p>
    </div>
<?php endif; ?>

<?php else: ?>

<!-- ── État vide (aucun véhicule sélectionné) ── -->
<div style="text-align:center;padding:50px 20px;color:#6c757d;">
    <svg viewBox="0 0 24 24" fill="currentColor" width="52" height="52" style="opacity:.22;margin-bottom:14px;display:block;margin-left:auto;margin-right:auto;">
        <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
    </svg>
    <p style="margin:0;font-size:0.95rem;">Sélectionnez un véhicule pour afficher l'état de ses pointages.</p>
</div>

<?php endif; ?>
</div>

<!-- ── Modal de confirmation ── -->
<div id="modal-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:30px 26px;max-width:450px;width:92%;box-shadow:0 8px 32px rgba(0,0,0,0.22);">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
            <div style="width:40px;height:40px;background:#fff3cd;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg viewBox="0 0 24 24" fill="#856404" width="20" height="20">
                    <path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>
                </svg>
            </div>
            <h3 style="margin:0;font-size:1rem;">Confirmation requise</h3>
        </div>
        <p style="margin:0 0 14px;color:#495057;font-size:0.875rem;line-height:1.55;">
            Vérifiez le kilométrage avant de valider.<br>
            Il servira de référence pour le suivi et devra rester cohérent avec les mois précédent et suivant.
        </p>
        <div style="background:#f8f9fa;border-radius:8px;padding:12px 15px;margin-bottom:20px;font-size:0.85rem;line-height:1.8;">
            <div><span style="color:#6c757d;display:inline-block;min-width:90px;">Véhicule :</span> <strong id="modal-vehicule">—</strong></div>
            <div><span style="color:#6c757d;display:inline-block;min-width:90px;">Période :</span> <strong id="modal-periode">—</strong></div>
            <div><span style="color:#6c757d;display:inline-block;min-width:90px;">Kilométrage :</span> <strong id="modal-km">—</strong></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;">
            <button type="button" onclick="fermerModal()"
                style="background:#6c757d;max-width:none;width:auto;padding:9px 20px;font-size:0.875rem;">
                Annuler
            </button>
            <button type="button" onclick="confirmerSaisie()"
                style="background:#dc3545;max-width:none;width:auto;padding:9px 20px;font-size:0.875rem;">
                Confirmer l'enregistrement
            </button>
        </div>
    </div>
</div>

<script>
const MOIS_FR_JS = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];

function demanderConfirmation() {
    const input = document.getElementById('kilometrage_reel');
    if (!input) return;
    input.setCustomValidity('');
    if (!input.reportValidity()) return;
    const km = parseInt(input.value, 10);
    if (isNaN(km) || km <= 0) {
        input.setCustomValidity('Veuillez saisir un kilométrage valide.');
        input.reportValidity(); return;
    }
    const kmMin = parseInt(input.min || '0', 10);
    if (km < kmMin) {
        input.setCustomValidity('Valeur minimale autorisée : ' + kmMin.toLocaleString('fr-FR') + ' km.');
        input.reportValidity(); return;
    }
    const vehicule = <?php echo json_encode($vehicule_sel
        ? htmlspecialchars($vehicule_sel['marque'] . ' ' . $vehicule_sel['modele'] . ' · ' . $vehicule_sel['immatriculation'], ENT_QUOTES)
        : ''); ?>;
    const mois  = <?php echo (int)$mois_sel; ?>;
    const annee = <?php echo (int)$annee_sel; ?>;
    document.getElementById('modal-vehicule').textContent = vehicule;
    document.getElementById('modal-periode').textContent  = MOIS_FR_JS[mois] + ' ' + annee;
    document.getElementById('modal-km').textContent       = km.toLocaleString('fr-FR') + ' km';
    document.getElementById('modal-overlay').style.display = 'flex';
}

function fermerModal()     { document.getElementById('modal-overlay').style.display = 'none'; }
function confirmerSaisie() { fermerModal(); document.getElementById('km-form').submit(); }

document.getElementById('modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) fermerModal();
});

<?php if ($mois_sel > 0 && $annee_sel > 0): ?>
window.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('form-saisie');
    if (el) setTimeout(function () { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 150);
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>
