<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Vérification de sécurité basique
$is_manager = ($_SESSION['user_role'] ?? '') === 'Manager';
$current_page = basename($_SERVER['PHP_SELF']);

// Protection des pages managers
$manager_pages = ['employes.php', 'vehicules.php', 'conges.php', 'affectations.php', 'manager.php'];
if ($is_manager === false && in_array($current_page, $manager_pages)) {
    header('Location: index.php?message=' . urlencode('Accès réservé.') . '&type=error');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion de Flotte - COOP</title>
    <!-- Cache busting pour le CSS -->
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
</head>
<body>

<header class="main-header">
    <div class="header-content">
        <h1>🚘 Gestion Automobile TERRACOOP</h1>
        
        <?php if (isset($_SESSION['user_id'])): ?>
            <button class="menu-toggle" onclick="toggleMenu()">☰</button>
            
            <nav class="main-nav" id="mainNav">
                <a href="index.php">📅 Réservation</a>
                <a href="planning.php">📊 Planning</a>
                
                <?php if ($is_manager): ?>
                    <a href="manager.php">🛠️ Manager</a>
                    <!--<a href="employes.php">👥 Employés</a>-->
                    <a href="vehicules.php">🚗 Véhicules</a>
                    <!--<a href="conges.php">🏖️ Congés</a>-->
                    <a href="affectations.php">🔑 Affectations</a>
                <?php endif; ?>
                
                <div class="user-info">
                    👤 <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                </div>
                <a href="logout.php" class="cancel-btn">Déconnexion</a>
            </nav>
        <?php endif; ?>
    </div>
</header>

<div class="content">

<script>
function toggleMenu() {
    var nav = document.getElementById("mainNav");
    nav.classList.toggle("active");
}
</script>