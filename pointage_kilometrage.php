<?php
require_once 'config.php';
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

// Seul le manager Terracoop est redirigé vers le suivi global ; les managers des autres sociétés font le pointage individuel comme un employé.
if (!empty($IS_TERRACOOP_MANAGER)) {
    header('Location: manager_kilometrage.php');
    exit();
}

$uid = (int)$_SESSION['user_id'];

$mois_fr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
$mois_courant  = (int)date('n');
$annee_courante = (int)date('Y');
$jour_courant   = (int)date('j');

// Récupérer le véhicule attitré de l'employé
$stmt = $conn->prepare("
    SELECT v.id_vehicule, v.immatriculation, v.marque, v.modele, v.type_carburant, v.kilometrage
    FROM affectations_fixes af
    JOIN vehicules v ON v.id_vehicule = af.id_vehicule
    WHERE af.id_employe = ?
    LIMIT 1
");
$stmt->bind_param("i", $uid);
$stmt->execute();
$vehicule = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vehicule) {
    header('Location: index.php?message=' . urlencode("Vous n'avez pas de véhicule attitré.") . '&type=error');
    exit();
}

$id_veh = (int)$vehicule['id_vehicule'];

// Pointage du mois courant
$stmt2 = $conn->prepare("
    SELECT id_pointage, kilometrage_reel, date_pointage
    FROM pointages_kilometrage
    WHERE id_vehicule = ? AND mois = ? AND annee = ?
");
$stmt2->bind_param("iii", $id_veh, $mois_courant, $annee_courante);
$stmt2->execute();
$pointage_mois = $stmt2->get_result()->fetch_assoc();
$stmt2->close();

// Km plancher : le plus haut km enregistré dans les mois précédents
$stmt3 = $conn->prepare("
    SELECT MAX(kilometrage_reel) AS km_max
    FROM pointages_kilometrage
    WHERE id_vehicule = ?
      AND (annee < ? OR (annee = ? AND mois < ?))
");
$stmt3->bind_param("iiii", $id_veh, $annee_courante, $annee_courante, $mois_courant);
$stmt3->execute();
$row3 = $stmt3->get_result()->fetch_assoc();
$stmt3->close();
$km_plancher = ($row3['km_max'] !== null) ? (int)$row3['km_max'] : max(0, (int)$vehicule['kilometrage']);

// ── Traitement POST ──────────────────────────────────────────────────────────
$erreur  = '';
$message = isset($_GET['message']) ? htmlspecialchars(urldecode($_GET['message'])) : '';
$message_type = $_GET['type'] ?? 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_km'])) {
    csrf_verify();

    if ($pointage_mois) {
        $erreur = "Le pointage de ce mois est déjà enregistré et ne peut plus être modifié.";
    } else {
        $km_saisi = filter_input(INPUT_POST, 'kilometrage_reel', FILTER_VALIDATE_INT);

        if ($km_saisi === false || $km_saisi === null || $km_saisi <= 0) {
            $erreur = "Veuillez saisir un kilométrage valide (nombre entier positif).";
        } elseif ($km_saisi < $km_plancher) {
            $erreur = "Le kilométrage saisi (" . number_format($km_saisi, 0, ',', ' ') . " km) est inférieur au dernier pointage enregistré (" . number_format($km_plancher, 0, ',', ' ') . " km).";
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO pointages_kilometrage (id_vehicule, id_employe, kilometrage_reel, date_pointage, mois, annee) VALUES (?, ?, ?, CURDATE(), ?, ?)");
            $stmt_ins->bind_param("iiiii", $id_veh, $uid, $km_saisi, $mois_courant, $annee_courante);
            $stmt_ins->execute();
            $stmt_ins->close();

            $stmt_veh = $conn->prepare("UPDATE vehicules SET kilometrage = ? WHERE id_vehicule = ? AND kilometrage <= ?");
            $stmt_veh->bind_param("iii", $km_saisi, $id_veh, $km_saisi);
            $stmt_veh->execute();
            $stmt_veh->close();

            header('Location: pointage_kilometrage.php?message=' . urlencode("Kilométrage enregistré avec succès.") . '&type=success');
            exit();
        }
    }
}

// Historique (24 derniers mois)
$stmt_h = $conn->prepare("
    SELECT pk.kilometrage_reel, pk.date_pointage, pk.mois, pk.annee,
           e.nom, e.prenom
    FROM pointages_kilometrage pk
    JOIN employes e ON e.id_employe = pk.id_employe
    WHERE pk.id_vehicule = ?
    ORDER BY pk.annee DESC, pk.mois DESC
    LIMIT 24
");
$stmt_h->bind_param("i", $id_veh);
$stmt_h->execute();
$historique = $stmt_h->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_h->close();

include 'includes/header.php';
?>

<div class="page-narrow">

    <?php if ($message): ?>
        <div class="message <?php echo htmlspecialchars($message_type); ?>"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($erreur): ?>
        <div class="message error"><?php echo htmlspecialchars($erreur); ?></div>
    <?php endif; ?>

    <?php if ($jour_courant <= 7 && !$pointage_mois): ?>
        <div class="message" style="background:#fff3cd;color:#856404;border:1px solid #ffeeba;">
            Rappel : le pointage kilométrique de <strong><?php echo $mois_fr[$mois_courant] . ' ' . $annee_courante; ?></strong> n'a pas encore été renseigné. Merci de le saisir avant le 7 du mois.
        </div>
    <?php endif; ?>

    <!-- Carte véhicule -->
    <div class="form-section" style="margin-bottom:24px;">
        <div style="display:flex;align-items:flex-start;gap:18px;flex-wrap:wrap;">
            <div style="width:52px;height:52px;background:var(--primary);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg viewBox="0 0 24 24" fill="#fff" width="28" height="28">
                    <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                </svg>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;">
                    <strong style="font-size:1.2rem;"><?php echo htmlspecialchars($vehicule['marque'] . ' ' . $vehicule['modele']); ?></strong>
                    <span class="badge badge-attitre">Véhicule attitré</span>
                </div>
                <div style="color:#555;font-size:0.93rem;display:flex;gap:20px;flex-wrap:wrap;">
                    <span>Immatriculation : <strong><?php echo htmlspecialchars($vehicule['immatriculation']); ?></strong></span>
                    <span>Carburant : <strong><?php echo htmlspecialchars($vehicule['type_carburant']); ?></strong></span>
                    <span>Km enregistré : <strong><?php echo number_format((int)$vehicule['kilometrage'], 0, ',', ' '); ?> km</strong></span>
                </div>
            </div>
            <div style="text-align:right;flex-shrink:0;">
                <?php if ($pointage_mois): ?>
                    <span class="badge badge-active" style="font-size:0.82rem;padding:6px 12px;">
                        ✓ <?php echo $mois_fr[$mois_courant]; ?> renseigné
                    </span>
                    <div style="color:#555;font-size:0.8rem;margin-top:4px;">
                        le <?php echo date('d/m/Y', strtotime($pointage_mois['date_pointage'])); ?>
                    </div>
                <?php else: ?>
                    <span class="badge badge-inactive" style="font-size:0.82rem;padding:6px 12px;">
                        <?php echo $mois_fr[$mois_courant]; ?> non renseigné
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Formulaire / Confirmation -->
    <div class="form-section">
        <h3 style="margin-top:0;margin-bottom:20px;">
            Pointage kilométrique — <?php echo $mois_fr[$mois_courant] . ' ' . $annee_courante; ?>
        </h3>

        <?php if ($pointage_mois): ?>
            <div style="display:flex;align-items:center;gap:14px;padding:18px 20px;background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;">
                <svg viewBox="0 0 24 24" fill="#155724" width="28" height="28" style="flex-shrink:0;">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
                <div>
                    <strong style="color:#155724;font-size:1rem;">Pointage enregistré</strong>
                    <div style="color:#155724;font-size:0.9rem;margin-top:2px;">
                        <?php echo number_format((int)$pointage_mois['kilometrage_reel'], 0, ',', ' '); ?> km
                        — saisi le <?php echo date('d/m/Y', strtotime($pointage_mois['date_pointage'])); ?>
                    </div>
                    <div style="color:#28693a;font-size:0.8rem;margin-top:4px;">
                        Ce pointage est verrouillé et ne peut plus être modifié.
                    </div>
                </div>
            </div>
        <?php else: ?>
            <form method="POST" action="pointage_kilometrage.php">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="submit_km" value="1">

                <label for="kilometrage_reel">Kilométrage réel actuel du véhicule (km)</label>
                <input
                    type="number"
                    id="kilometrage_reel"
                    name="kilometrage_reel"
                    min="<?php echo $km_plancher; ?>"
                    step="1"
                    value="<?php echo $km_plancher > 0 ? $km_plancher : ''; ?>"
                    required
                    style="max-width:280px;"
                    placeholder="Ex. : <?php echo number_format($km_plancher, 0, ',', ' '); ?> km"
                >
                <?php if ($km_plancher > 0): ?>
                    <p style="margin:6px 0 0;font-size:0.85rem;color:#6c757d;">
                        Valeur minimale acceptée : <?php echo number_format($km_plancher, 0, ',', ' '); ?> km
                    </p>
                <?php endif; ?>
                <p style="margin:10px 0 0;font-size:0.85rem;color:#856404;background:#fff3cd;padding:8px 12px;border-radius:6px;border:1px solid #ffeeba;">
                    Attention : une fois validé, le pointage ne pourra plus être modifié.
                </p>

                <button type="submit" style="max-width:280px;margin-top:20px;">
                    Enregistrer le pointage
                </button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Historique -->
    <?php if ($historique): ?>
        <h3 style="margin-top:10px;">Historique des pointages</h3>
        <table>
            <thead>
                <tr>
                    <th>Mois</th>
                    <th>Année</th>
                    <th>Kilométrage (km)</th>
                    <th>Saisi le</th>
                    <th>Par</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historique as $h):
                    $is_current = ((int)$h['mois'] === $mois_courant && (int)$h['annee'] === $annee_courante);
                ?>
                <tr <?php if ($is_current) echo 'style="background:#e8f4fd;"'; ?>>
                    <td>
                        <?php echo $mois_fr[(int)$h['mois']]; ?>
                        <?php if ($is_current): ?><span class="badge badge-active" style="margin-left:6px;font-size:0.72rem;">en cours</span><?php endif; ?>
                    </td>
                    <td><?php echo (int)$h['annee']; ?></td>
                    <td><strong><?php echo number_format((int)$h['kilometrage_reel'], 0, ',', ' '); ?> km</strong></td>
                    <td><?php echo date('d/m/Y', strtotime($h['date_pointage'])); ?></td>
                    <td><?php echo htmlspecialchars($h['prenom'] . ' ' . $h['nom']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p style="color:#6c757d;text-align:center;margin-top:20px;">Aucun pointage enregistré pour ce véhicule.</p>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>
