<?php
// logout.php - Gestion de la déconnexion
require_once 'config.php'; // démarre la session avec les bons paramètres sécurisés

// Supprimer le token de session en base
if (isset($_SESSION['session_token'])) {
    $del_s = $conn->prepare("DELETE FROM sessions_auto WHERE session_token = ?");
    $del_s->bind_param("s", $_SESSION['session_token']);
    $del_s->execute();
    $del_s->close();
}

$_SESSION = array();

// Si vous voulez détruire complètement la session, effacez également le cookie de session.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Détruire la session
session_destroy();

// Rediriger vers la page de connexion
header("Location: login.php?logout=1");
exit();
?>