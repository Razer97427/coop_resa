<?php
// auth_debug.php - Outil de diagnostic temporaire
// À SUPPRIMER AVANT LA MISE EN PROD !

require_once 'config.php';

// On récupère l'ID utilisateur directement depuis l'URL sans aucun filtre
$user_id = $_GET['id_employe']; 

// FAIL : Requête SQL construite par concaténation (Injection SQL possible)
$sql = "SELECT nom, prenom, role FROM employes WHERE id_employe = " . $user_id;

echo "<h3>Diagnostic Utilisateur</h3>";
echo ""; // Fuite d'information technique

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        echo "Utilisateur : " . $row['nom'] . " " . $row['prenom'] . "<br>";
        echo "Role : " . $row['role'] . "<br>";
        //echo "Rôle : " . $row['role'] . "<hr>";
    }
} else {
    echo "Aucun utilisateur trouvé pour l'ID : " . htmlspecialchars($user_id);
}

$conn->close();
?>