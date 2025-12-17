<?php
require_once 'config.php';

// On récupère la date demandée (ou aujourd'hui par défaut)
$date_demandee = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

$sql = "
    SELECT 
        v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal, 
        e.nom AS prop_nom, e.prenom AS prop_prenom,
        -- Point d'interrogation N°1 : Pour récupérer les dates du congé spécifique à cette date
        (SELECT CONCAT(date_debut, '|', date_fin) 
         FROM conges c 
         WHERE c.id_employe = e.id_employe 
         AND ? BETWEEN c.date_debut AND c.date_fin
         LIMIT 1
        ) as info_conge
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON v.id_vehicule = af.id_vehicule
    LEFT JOIN employes e ON af.id_employe = e.id_employe
    WHERE v.actif = 1
    AND (
        -- 1. Véhicules Communaux
        v.est_communal = 1
        OR 
        -- 2. Véhicules Attitrés (Seulement si congé ce jour-là)
        (
            v.est_communal = 0 
            AND EXISTS (
                -- Point d'interrogation N°2 : Pour vérifier si le véhicule doit apparaître
                SELECT 1 FROM conges c 
                WHERE c.id_employe = e.id_employe 
                AND ? BETWEEN c.date_debut AND c.date_fin
            )
        )
    )
    ORDER BY v.est_communal DESC, v.marque";

$stmt = $conn->prepare($sql);

// CORRECTION ICI : Il n'y a que 2 points d'interrogation, donc on met 'ss' et 2 fois la variable
$stmt->bind_param("ss", $date_demandee, $date_demandee);

$stmt->execute();
$result = $stmt->get_result();

$vehicules = [];

while ($row = $result->fetch_assoc()) {
    $label = $row['marque'] . ' ' . $row['modele'] . ' (' . $row['immatriculation'] . ')';
    $class = "communal";
    
    // Si c'est un véhicule privé
    if ($row['est_communal'] == 0) {
        $label .= " [" . $row['prop_nom'] . "]";
        $class = "libere";
        
        // Formatage des dates pour l'affichage
        if (!empty($row['info_conge'])) {
            // info_conge ressemble à "2023-12-01|2023-12-15"
            $dates = explode('|', $row['info_conge']);
            
            if (count($dates) == 2) {
                $debut = date('d/m', strtotime($dates[0]));
                $fin = date('d/m', strtotime($dates[1]));
                $label .= " 🟢 (Dispo du $debut au $fin)";
            } else {
                $label .= " 🟢 (Dispo)";
            }
        }
    }
    
    $vehicules[] = [
        'id' => $row['id_vehicule'],
        'label' => $label,
        'class' => $class
    ];
}

header('Content-Type: application/json');
echo json_encode($vehicules);
?>