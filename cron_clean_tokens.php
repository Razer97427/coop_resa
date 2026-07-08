<?php
//if (php_sapi_name() !== 'cli') {
//    die("Accès refusé, vous n'avez pas la possibilité d'exécuter ce script depuis un navigateur..");
//}

date_default_timezone_set('Indian/Reunion');
require_once __DIR__ . '/../config.php';

$now = date('Y-m-d H:i:s');
echo "[" . $now . "] Début du nettoyage des tokens expirés...\n";

$res = $conn->query("SELECT i.user_id, e.matricule FROM init_pass_auto i LEFT JOIN employes e ON i.user_id = e.id_employe WHERE i.token_expiry < '$now'");
if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        echo "  - Suppression token expiré : user_id=" . $row['user_id'] . " matricule=" . ($row['matricule'] ?? 'inconnu') . "\n";
    }
}
$conn->query("DELETE FROM init_pass_auto WHERE token_expiry < '$now'");
echo "- init_pass_auto : " . $conn->affected_rows . " ligne(s) supprimée(s).\n";

$conn->query("DELETE FROM email_verif_auto WHERE token_expiry < '$now'");
echo "- email_verif_auto : " . $conn->affected_rows . " ligne(s) supprimée(s).\n";

// Sessions inactives depuis plus de 7 jours (last_activity rafraîchie à chaque page)
$conn->query("DELETE FROM sessions_auto WHERE last_activity < (NOW() - INTERVAL 7 DAY)");
echo "- sessions_auto : " . $conn->affected_rows . " session(s) expirée(s) supprimée(s).\n";

$conn->close();
echo "[" . date('Y-m-d H:i:s') . "] Fin du nettoyage.\n";
