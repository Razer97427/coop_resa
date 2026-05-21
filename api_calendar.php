<?php
require_once 'config.php'; 
ini_set('display_errors', 0); // Pas d'erreur HTML dans le JSON
header('Content-Type: application/json');

$events = [];

try {
    $id_vehicule = isset($_GET['id_vehicule']) && !empty($_GET['id_vehicule']) ? (int)$_GET['id_vehicule'] : 0;

    // Requête SQL complète
    $sql = "SELECT r.id_reservation, r.id_vehicule, r.date_debut_resa, r.date_fin_resa, r.statut_resa, r.motif, 
                   e.nom, e.prenom, v.marque, v.modele, v.immatriculation
            FROM reservations r 
            JOIN employes e ON r.id_employe = e.id_employe 
            JOIN vehicules v ON r.id_vehicule = v.id_vehicule
            WHERE r.statut_resa IN ('Validée', 'En cours', 'En attente')";

    // Filtre optionnel
    if ($id_vehicule > 0) {
        $sql .= " AND r.id_vehicule = " . $id_vehicule;
    }

    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            
            // Gestion des couleurs
            $color = '#6c757d'; // Gris
            if ($row['statut_resa'] == 'Validée') $color = '#28a745';    // Vert
            if ($row['statut_resa'] == 'En cours') $color = '#17a2b8';   // Bleu
            if ($row['statut_resa'] == 'En attente') $color = '#ffc107'; // Jaune

            $events[] = [
                'id'         => $row['id_reservation'],
                'resourceId' => $row['id_vehicule'], // INDISPENSABLE : Lie l'event à la ligne véhicule
                'title'      => $row['nom'] . ' ' . $row['prenom'],         // Texte dans la barre : Nom du conducteur
                'start'      => $row['date_debut_resa'],
                'end'        => $row['date_fin_resa'],
                'color'      => $color,
                // Données pour la popup au clic
                'extendedProps' => [
                    'vehicule'   => $row['marque'] . ' ' . $row['modele'] . ' (' . $row['immatriculation'] . ')',
                    'conducteur' => $row['nom'] . ' ' . $row['prenom'],
                    'motif'      => $row['motif'],
                    'statut'     => $row['statut_resa']
                ]
            ];
        }
    }
} catch (Exception $e) {}

echo json_encode($events);
?>