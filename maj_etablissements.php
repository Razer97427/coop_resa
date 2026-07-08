<?php
/**
 * maj_etablissements.php — Outil manuel de rattachement Service → Établissement
 * ---------------------------------------------------------------------------
 * 1. Authentification par petit calcul (÷ 25).
 * 2. Import du CSV (matricule;id_service;nom) : alimente `services` et
 *    renseigne `employes.id_service` (les matricules présents dans plusieurs
 *    services sont listés comme conflits, non assignés).
 * 3. Écran interactif : on range chaque service dans un établissement, puis
 *    `employes.id_etablissement` est propagé automatiquement.
 * 4. Journalisation : fichier logs/*.txt + fenêtre de logs dans le navigateur.
 *
 * ⚠️ SÉCURITÉ : outil d'administration. Supprimez-le (ou renommez-le) après usage.
 */
ini_set('display_errors', 1); error_reporting(E_ALL);

require_once '/home/terracoonz/www/gestion-auto/config.php';   // charge aussi le config maître (../config.php) : $conn, session, csrf_verify(), csrf_token
// ───────────────────────────── Journalisation ──────────────────────────────
$LOG = [];
function jlog($msg) { global $LOG; $LOG[] = '[' . date('H:i:s') . '] ' . $msg; }
function ecrire_log() {
    global $LOG;
    if (!$LOG) return null;
    $dir = __DIR__ . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/maj_etablissements_' . date('Ymd-His') . '.txt';
    @file_put_contents($file, implode("\n", $LOG) . "\n");
    return $file;
}

// ─────────────────────────── Authentification /25 ──────────────────────────
if (!isset($_SESSION['maj_auth']))  $_SESSION['maj_auth'] = false;
function nouveau_defi() { $_SESSION['maj_defi'] = random_int(4, 120) * 25; } // multiple de 25
if (empty($_SESSION['maj_defi'])) nouveau_defi();

$auth_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_submit'])) {
    csrf_verify();
    $rep = (int)($_POST['reponse'] ?? -1);
    if ($rep === (int)($_SESSION['maj_defi'] / 25)) {
        $_SESSION['maj_auth'] = true;
    } else {
        $auth_error = 'Réponse incorrecte, réessayez.';
        nouveau_defi();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lock'])) {
    $_SESSION['maj_auth'] = false;
    nouveau_defi();
}
$authed = ($_SESSION['maj_auth'] === true);

// ─────────────────────────────── ACTIONS ───────────────────────────────────
$conflits = [];   // matricule => [id_service, ...]
$flash    = '';

// 1) IMPORT DU CSV
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    csrf_verify();

    $path = __DIR__ . '/conges_users.csv';
    $src  = 'conges_users.csv (fichier local)';
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $path = $_FILES['csv']['tmp_name'];
        $src  = htmlspecialchars($_FILES['csv']['name']);
    }

    if (!is_readable($path)) {
        jlog("ERREUR : CSV introuvable ou illisible ($src).");
    } else {
        jlog("Import depuis : $src");
        $lignes = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $services_csv = [];   // id_service => nom
        $mat_services = [];   // matricule  => [id_service => true]
        foreach ($lignes as $ln) {
            $c = array_map('trim', explode(';', $ln));
            if (count($c) < 3) continue;
            $mat  = $c[0];
            $sid  = (int)$c[1];
            $snom = $c[2];
            if ($sid <= 0 || $mat === '') continue;
            $services_csv[$sid] = $snom;
            $mat_services[$mat][$sid] = true;
        }
        jlog(count($lignes) . ' lignes lues · ' . count($services_csv) . ' services distincts · ' . count($mat_services) . ' matricules.');

        // 1a. Upsert des services
        $stmt_s = $conn->prepare("INSERT INTO services (id_service, nom) VALUES (?, ?) ON DUPLICATE KEY UPDATE nom = VALUES(nom)");
        foreach ($services_csv as $sid => $snom) {
            $stmt_s->bind_param("is", $sid, $snom);
            $stmt_s->execute();
        }
        $stmt_s->close();
        jlog(count($services_csv) . ' service(s) créés/mis à jour dans la table `services`.');

        // 1b. Affectation employes.id_service (hors conflits)
        $maj = 0; $deja = 0; $introuvables = [];
        $stmt_u   = $conn->prepare("UPDATE employes SET id_service = ? WHERE matricule = ?");
        $stmt_chk = $conn->prepare("SELECT 1 FROM employes WHERE matricule = ? LIMIT 1");
        foreach ($mat_services as $mat => $set) {
            $ids = array_keys($set);
            if (count($ids) > 1) { $conflits[$mat] = $ids; continue; }
            $sid = $ids[0];
            $stmt_u->bind_param("is", $sid, $mat);
            $stmt_u->execute();
            if ($stmt_u->affected_rows > 0) {
                $maj++;
            } else {
                $stmt_chk->bind_param("s", $mat);
                $stmt_chk->execute();
                $stmt_chk->store_result();
                if ($stmt_chk->num_rows > 0) $deja++; else $introuvables[] = $mat;
                $stmt_chk->free_result();
            }
        }
        $stmt_u->close();
        $stmt_chk->close();

        jlog("✓ $maj employé(s) mis à jour (id_service).");
        jlog("• $deja déjà à jour.");
        jlog("• " . count($introuvables) . " matricule(s) introuvable(s) dans `employes`" . ($introuvables ? " : " . implode(', ', array_slice($introuvables, 0, 40)) . (count($introuvables) > 40 ? '…' : '') : '.'));
        jlog("• " . count($conflits) . " matricule(s) en CONFLIT (plusieurs services) — NON assignés :");
        foreach ($conflits as $mat => $ids) {
            jlog("    - matricule $mat → services " . implode(', ', $ids));
        }
        $flash = "Import terminé : $maj mis à jour, " . count($conflits) . " conflits, " . count($introuvables) . " introuvables.";
    }
    $log_file = ecrire_log();
    if ($log_file) jlog("Log enregistré : " . basename($log_file));
}

// 2) RATTACHEMENT SERVICE → ÉTABLISSEMENT + PROPAGATION
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_assign'])) {
    csrf_verify();
    $etabs = $_POST['etab'] ?? [];   // [id_service => id_etablissement]

    $stmt_set  = $conn->prepare("UPDATE services SET id_etablissement = ?    WHERE id_service = ?");
    $stmt_null = $conn->prepare("UPDATE services SET id_etablissement = NULL WHERE id_service = ?");
    $nb_assign = 0; $nb_vides = 0;
    foreach ($etabs as $sid => $eid) {
        $sid = (int)$sid; $eid = (int)$eid;
        if ($eid > 0) { $stmt_set->bind_param("ii", $eid, $sid); $stmt_set->execute(); $nb_assign++; }
        else          { $stmt_null->bind_param("i", $sid);       $stmt_null->execute(); $nb_vides++; }
    }
    $stmt_set->close();
    $stmt_null->close();
    jlog("Rattachements enregistrés : $nb_assign service(s) assigné(s), $nb_vides laissé(s) sans établissement.");

    // Propagation vers employes.id_etablissement (cache)
    $conn->query("UPDATE employes e JOIN services s ON e.id_service = s.id_service SET e.id_etablissement = s.id_etablissement");
    jlog("✓ Propagation : " . $conn->affected_rows . " employé(s) ont vu leur établissement recalculé.");
    $flash = "Rattachements appliqués et établissements propagés.";
    $log_file = ecrire_log();
    if ($log_file) jlog("Log enregistré : " . basename($log_file));
}

// ─────────────────────────── Données d'affichage ───────────────────────────
$etablissements = [];
$services_rows  = [];
$stats          = ['emp_total' => 0, 'emp_avec_service' => 0, 'emp_avec_etab' => 0, 'services_non_ranges' => 0];
if ($authed) {
    $r = $conn->query("SELECT id_etablissement, nom FROM etablissements ORDER BY id_etablissement");
    while ($x = $r->fetch_assoc()) $etablissements[] = $x;

    $r = $conn->query("
        SELECT s.id_service, s.nom, s.id_etablissement,
               (SELECT COUNT(*) FROM employes e WHERE e.id_service = s.id_service) AS nb
        FROM services s
        ORDER BY (s.id_etablissement IS NULL) DESC, s.nom
    ");
    if ($r) while ($x = $r->fetch_assoc()) $services_rows[] = $x;

    $stats['emp_total']           = (int)($conn->query("SELECT COUNT(*) FROM employes")->fetch_row()[0] ?? 0);
    $stats['emp_avec_service']    = (int)($conn->query("SELECT COUNT(*) FROM employes WHERE id_service IS NOT NULL")->fetch_row()[0] ?? 0);
    $stats['emp_avec_etab']       = (int)($conn->query("SELECT COUNT(*) FROM employes WHERE id_etablissement IS NOT NULL")->fetch_row()[0] ?? 0);
    $stats['services_non_ranges'] = (int)($conn->query("SELECT COUNT(*) FROM services WHERE id_etablissement IS NULL")->fetch_row()[0] ?? 0);
}
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MàJ Établissements — Outil d'administration</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f4f6f9; color: #263238; margin: 0; padding: 24px; }
    .wrap { max-width: 1000px; margin: auto; }
    h1 { font-size: 1.35rem; margin: 0 0 4px; }
    .sub { color: #6c757d; font-size: .9rem; margin: 0 0 20px; }
    .card { background: #fff; border: 1px solid #dee2e6; border-radius: 10px; padding: 20px 22px; margin-bottom: 18px; box-shadow: 0 2px 8px rgba(0,0,0,.04); }
    .warn { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; border-radius: 8px; padding: 12px 16px; font-size: .88rem; margin-bottom: 18px; }
    .flash { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; border-radius: 8px; padding: 12px 16px; font-size: .9rem; margin-bottom: 18px; }
    .err { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; border-radius: 8px; padding: 10px 14px; font-size: .9rem; margin-bottom: 12px; }
    label { font-weight: 600; font-size: .85rem; display: block; margin-bottom: 6px; }
    input[type=number], input[type=file], select { padding: 8px 10px; border: 1.5px solid #dee2e6; border-radius: 6px; font-size: .9rem; }
    button { background: #0d3b8c; color: #fff; border: none; border-radius: 6px; padding: 9px 18px; font-weight: 600; cursor: pointer; font-size: .9rem; }
    button.grey { background: #6c757d; }
    button.green { background: #28a745; }
    table { width: 100%; border-collapse: collapse; font-size: .88rem; }
    th, td { padding: 7px 10px; border-bottom: 1px solid #eceff1; text-align: left; }
    th { background: #f0f4ff; position: sticky; top: 0; }
    .badge { font-size: .7rem; padding: 2px 8px; border-radius: 20px; background: #e9ecef; color: #495057; }
    .badge.none { background: #f8d7da; color: #721c24; }
    .stats { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
    .stat { flex: 1; min-width: 120px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 10px 14px; text-align: center; }
    .stat b { display: block; font-size: 1.3rem; color: #0d3b8c; }
    .stat span { font-size: .72rem; color: #6c757d; }
    .logbox { background: #0f1720; color: #b7f5c1; font-family: Consolas, monospace; font-size: .8rem; border-radius: 8px; padding: 14px; max-height: 320px; overflow: auto; white-space: pre-wrap; }
    .svc-table-wrap { max-height: 460px; overflow: auto; border: 1px solid #dee2e6; border-radius: 8px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>🏢 Rattachement Service → Établissement</h1>
    <p class="sub">Import du CSV, affectation des services aux établissements, mise à jour de <code>employes</code>.</p>

    <div class="warn">⚠️ Outil d'administration à accès restreint. <strong>Supprimez ou renommez ce fichier après usage.</strong></div>

<?php if (!$authed): ?>
    <!-- ─────────── Écran d'authentification (calcul /25) ─────────── -->
    <div class="card" style="max-width:420px;">
        <h2 style="margin-top:0;font-size:1.05rem;">Vérification d'accès</h2>
        <?php if ($auth_error): ?><div class="err"><?php echo htmlspecialchars($auth_error); ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="auth_submit" value="1">
            <label>Combien font <?php echo (int)$_SESSION['maj_defi']; ?> ÷ 25 ?</label>
            <div style="display:flex;gap:8px;">
                <input type="number" name="reponse" required autofocus style="flex:1;">
                <button type="submit">Valider</button>
            </div>
        </form>
    </div>

<?php else: ?>
    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <!-- ─────────── Statistiques ─────────── -->
    <div class="stats">
        <div class="stat"><b><?php echo $stats['emp_total']; ?></b><span>Employés</span></div>
        <div class="stat"><b><?php echo $stats['emp_avec_service']; ?></b><span>Avec service</span></div>
        <div class="stat"><b><?php echo $stats['emp_avec_etab']; ?></b><span>Avec établissement</span></div>
        <div class="stat"><b><?php echo $stats['services_non_ranges']; ?></b><span>Services non rangés</span></div>
    </div>

    <!-- ─────────── Étape 1 : Import CSV ─────────── -->
    <div class="card">
        <h2 style="margin-top:0;font-size:1.05rem;">1. Importer le CSV</h2>
        <p class="sub" style="margin-bottom:14px;">Format attendu : <code>matricule;id_service;nom</code>. Sans fichier joint, <code>conges_users.csv</code> local est utilisé. Les matricules présents dans plusieurs services sont signalés comme conflits (non assignés).</p>
        <form method="POST" enctype="multipart/form-data" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="do_import" value="1">
            <div>
                <label>Fichier CSV (optionnel)</label>
                <input type="file" name="csv" accept=".csv,text/csv">
            </div>
            <button type="submit" class="green">Importer &amp; renseigner id_service</button>
        </form>
    </div>

    <!-- ─────────── Étape 2 : Rattachement ─────────── -->
    <div class="card">
        <h2 style="margin-top:0;font-size:1.05rem;">2. Ranger chaque service dans son établissement</h2>
        <p class="sub" style="margin-bottom:14px;">Choisissez l'établissement de chaque service, puis enregistrez. <code>employes.id_etablissement</code> sera recalculé automatiquement.</p>
        <?php if (!$services_rows): ?>
            <p class="sub">Aucun service en base. Lancez d'abord l'import (ou le seed SQL).</p>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="do_assign" value="1">
            <div class="svc-table-wrap">
                <table>
                    <thead>
                        <tr><th>Service</th><th style="width:80px;">Employés</th><th style="width:260px;">Établissement</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($services_rows as $s): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['nom']); ?> <span class="badge">#<?php echo (int)$s['id_service']; ?></span></td>
                            <td><?php echo (int)$s['nb']; ?></td>
                            <td>
                                <select name="etab[<?php echo (int)$s['id_service']; ?>]" style="width:100%;">
                                    <option value="0">— Non rangé —</option>
                                    <?php foreach ($etablissements as $e): ?>
                                        <option value="<?php echo (int)$e['id_etablissement']; ?>"
                                            <?php echo ((int)$s['id_etablissement'] === (int)$e['id_etablissement']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($e['nom']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:14px;">
                <button type="submit" class="green">Enregistrer les rattachements &amp; propager</button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- ─────────── Fenêtre de logs ─────────── -->
    <?php if ($LOG): ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:1.05rem;">Journal de la dernière opération</h2>
        <div class="logbox" id="logbox"><?php echo htmlspecialchars(implode("\n", $LOG)); ?></div>
    </div>
    <script>
        const lb = document.getElementById('logbox');
        if (lb) lb.scrollTop = lb.scrollHeight;
    </script>
    <?php endif; ?>

    <form method="POST" style="margin-top:4px;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
        <button type="submit" name="lock" value="1" class="grey">🔒 Verrouiller l'accès</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
