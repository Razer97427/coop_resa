<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'Manager') {
    http_response_code(403);
    echo json_encode(['error' => 'Vous n\'avez pas accès à cette ressource']);
    exit;
}

$id_employe = isset($_GET['id_employe']) ? (int)$_GET['id_employe'] : 0;
if (!$id_employe) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre id_employe manquant']);
    exit;
}

$stmt = $conn->prepare("
    SELECT id_conge, date_debut, date_fin
    FROM conges
    WHERE id_employe = ?
    ORDER BY
        (date_debut < CURDATE()),
        CASE WHEN date_debut >= CURDATE() THEN date_debut ELSE NULL END ASC,
        date_debut DESC
");
$stmt->bind_param("i", $id_employe);
$stmt->execute();
$result = $stmt->get_result();
$conges = [];

while ($row = $result->fetch_assoc()) {
    $conges[] = [
        'date_debut' => date('d/m/Y', strtotime($row['date_debut'])),
        'date_fin' => date('d/m/Y', strtotime($row['date_fin']))
    ];
}

$stmt->close();

echo json_encode(['conges' => $conges]);
?>
