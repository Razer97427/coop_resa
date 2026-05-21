<?php
require_once 'config.php'; 
include 'includes/header.php';

// On affiche les résas futures ou en cours
$now = date('Y-m-d H:i:s');
$sql = "
    SELECT r.*, e.nom, e.prenom, v.marque, v.modele, v.immatriculation
    FROM reservations r
    JOIN employes e ON r.id_employe = e.id_employe
    JOIN vehicules v ON r.id_vehicule = v.id_vehicule
    WHERE r.statut_resa IN ('Validée', 'En cours')
    AND r.date_fin_resa >= '$now'
    ORDER BY r.date_debut_resa ASC
";
$planning = $conn->query($sql);
?>

<h2>📅 Planning des sorties</h2>

<div class="message success" style="text-align:left; background:white; border:none; box-shadow:var(--shadow);">
    ℹ️ Ce tableau récapitule qui est sur la route actuellement ou qui va partir prochainement.
</div>

<!-- AJOUT DE LA CLASSE "planning-table" ICI -->
<table class="planning-table">
    <thead>
        <tr>
            <th>Conducteur</th>
            <th>Véhicule</th>
            <th>Départ</th>
            <th>Retour Prévu</th>
            <th>Statut</th>
            <!--<th>Motif</th>-->
			<th>Destination</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($planning && $planning->num_rows > 0): ?>
        <?php while ($row = $planning->fetch_assoc()): ?>
            <tr>
                <td data-label="Conducteur">
                    <strong><?php echo htmlspecialchars($row['prenom'] . ' ' . $row['nom']); ?></strong>
                </td>
                <td data-label="Véhicule">
                    <?php echo htmlspecialchars($row['marque'] . ' ' . $row['modele']); ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($row['immatriculation']); ?></small>
                </td>
                <td data-label="Départ">
                    <?php echo date('d/m H:i', strtotime($row['date_debut_resa'])); ?>
                </td>
                <td data-label="Retour">
                    <?php echo date('d/m H:i', strtotime($row['date_fin_resa'])); ?>
                </td>
                <td data-label="Statut">
                    <span class="status-tag <?php echo strtolower(str_replace(' ', '-', $row['statut_resa'])); ?>">
                        <?php echo htmlspecialchars($row['statut_resa']); ?>
                    </span>
                </td>
                <td data-label="Destination"><?php echo $row['destination']; ?></td>
            </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="6" style="text-align:center;">Aucun mouvement prévu.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<?php 
$conn->close();
include 'includes/footer.php'; 
?>