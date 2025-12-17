<?php
require_once 'config.php'; 
header('Content-Type: application/json');

// 1. Récupérer la date affichée par le calendrier
// FullCalendar envoie "start" (ex: 2023-12-10T00:00:00). On prend juste la date Y-m-d.
$date_ref = date('Y-m-d'); 
if (isset($_GET['start'])) {
    $date_ref = substr($_GET['start'], 0, 10);
}

$resources = [];

// 2. Requête avec le MÊME FILTRE que la liste déroulante
$sql = "SELECT v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal
        FROM vehicules v
        LEFT JOIN affectations_fixes af ON v.id_vehicule = af.id_vehicule
        LEFT JOIN employes e ON af.id_employe = e.id_employe
        WHERE v.actif = 1
        AND (
            -- A. Véhicule Communal : On affiche toujours
            v.est_communal = 1
            OR 
            -- B. Véhicule Privé : On affiche SEULEMENT si congé ce jour-là
            (
                v.est_communal = 0 
                AND EXISTS (
                    SELECT 1 FROM conges c 
                    WHERE c.id_employe = e.id_employe 
                    AND ? BETWEEN c.date_debut AND c.date_fin
                )
            )
        )
        ORDER BY v.est_communal DESC, v.marque";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $date_ref); // On passe la date du calendrier
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $icon = ($row['est_communal'] == 1) ? '🏢' : '🚗';
    $titre = $icon . ' ' . $row['marque'] . ' ' . $row['modele'] . ' (' . $row['immatriculation'] . ')';

    $resources[] = [
        'id'    => $row['id_vehicule'],
        'title' => $titre
    ];
}

echo json_encode($resources);
?>