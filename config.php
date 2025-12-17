<?php
// config.php - Paramètres de connexion et démarrage de session
//require '../config.php';
date_default_timezone_set('Indian/Reunion');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', 'root'); // À ajuster
define('DB_NAME', 'db_coop'); // À ajuster*/

// Connexion à la base de données MySQL
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Vérifier la connexion
if ($conn->connect_error) {
    die("Erreur de connexion à la base de données: " . $conn->connect_error);
}

// Définir l'encodage
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+04:00'");
// NOTE: Pour les tests, nous allons initialiser un utilisateur "Manager" ou "Employé" 
// si aucune session n'est active et qu'on est sur une page critique (mais login.php gère ça)
// L'inclusion de 'includes/header.php' s'occupe de la redirection si l'utilisateur n'est pas logué.
?>