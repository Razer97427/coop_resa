<?php
require_once 'config.php'; 
// Pas besoin d'include header ici si c'est juste un script de redirection
// Mais vérifions la session
if (!isset($_SESSION['user_id'])) exit();

$current_user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if ($action == 'annuler' && $id > 0) {
    $stmt = $conn->prepare("UPDATE reservations SET statut_resa = 'Annulée' WHERE id_reservation = ? AND id_employe = ? AND statut_resa IN ('En attente', 'Validée')");
    $stmt->bind_param("ii", $id, $current_user_id);
    $stmt->execute();
}

header("Location: index.php");
exit();
?>