<?php
// On active l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

$url = "https://terracoop.re/APIs/conges_periode.csv";

// --- PRÉPARATION DES REQUÊTES ---

// 1. Requête pour TRADUIRE le matricule en ID
// On cherche l'ID technique qui correspond au matricule du CSV
$sql_get_id = "SELECT id_employe FROM employes WHERE matricule = ?";
$stmt_get_id = $conn->prepare($sql_get_id);

// 2. Requête pour INSÉRER le congé
// On utilise l'ID trouvé juste avant
$sql_insert = "INSERT INTO conges (id_employe, date_debut, date_fin, motif, statut) 
               VALUES (?, ?, ?, ?, ?)";
$stmt_insert = $conn->prepare($sql_insert);


// 3. Requête pour SUPPRIMER avant Insertion
$sql_delete_conges = "DELETE FROM conges";
$stmt_delete_conges = $conn->prepare($sql_delete_conges);
$stmt_delete_conges->execute();
$stmt_delete_conges->close();

// Variables par défaut
$matricule_csv = "";
$id_trouve = 0;
$date_deb = "";
$date_fin = "";
$motif = "Import Auto";
$statut = "En attente";

// On lie les paramètres aux requêtes
$stmt_get_id->bind_param("s", $matricule_csv); // "s" car matricule est souvent du texte (varchar)
$stmt_insert->bind_param("issss", $id_trouve, $date_deb, $date_fin, $motif, $statut);

if (($handle = fopen($url, "r")) !== FALSE) {
    
    echo "<h3>Rapport d'importation :</h3><ul>";
    $succes = 0;
    $echecs = 0;

    while (($data = fgetcsv($handle, 1000, ";", '"')) !== FALSE) {
        
        if (count($data) >= 3) {
            
            // ÉTAPE 1 : Récupération des données du CSV
            $matricule_csv = trim($data[0]); // On nettoie les espaces éventuels
            $date_deb = $data[1];
            $date_fin = $data[2];

            // ÉTAPE 2 : On cherche l'ID de cet employé
            $stmt_get_id->execute();
            $result = $stmt_get_id->get_result();

            if ($row = $result->fetch_assoc()) {
                // TROUVÉ ! On récupère son véritable ID
                $id_trouve = $row['id_employe'];

                // ÉTAPE 3 : Insertion du congé
                try {
                    if ($stmt_insert->execute()) {
                        echo "<li style='color:green'>OK : Matricule $matricule_csv (ID $id_trouve) -> Congé ajouté.</li>";
                        $succes++;
                    } else {
                        echo "<li style='color:red'>Erreur SQL pour matricule $matricule_csv : " . $stmt_insert->error . "</li>";
                        $echecs++;
                    }
                } catch (Exception $e) {
                    echo "<li style='color:red'>Exception pour matricule $matricule_csv : " . $e->getMessage() . "</li>";
                    $echecs++;
                }

            } else {
                // PAS TROUVÉ
                echo "<li style='color:orange'><b>Introuvable :</b> Le matricule <b>$matricule_csv</b> n'existe pas dans votre table 'employes'. Congé ignoré.</li>";
                $echecs++;
            }
        }
    }
    
    echo "</ul>";
    echo "<hr>Importation terminée : $succes succès, $echecs échecs.";
    
    fclose($handle);
    $stmt_get_id->close();
    $stmt_insert->close();
    $conn->close();
    
} else {
    echo "Impossible d'ouvrir le fichier CSV.";
}
?>