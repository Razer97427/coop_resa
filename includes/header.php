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

// --- AJOUT : Récupération du véhicule affecté ---
$vehicule_affecte = "Aucun véhicule affecté";
$style_vehicule = "color: #999; font-style: italic; font-size: 0.9em;"; // Style par défaut (gris)


if (isset($_SESSION['user_id'])) {
    // On suppose que la table s'appelle 'affectations_fixes' et qu'elle lie id_employe à id_vehicule
    // On vérifie aussi que la date_fin est soit NULL soit future (si vous gérez l'historique)
    $sql_vehicule = "
        SELECT v.marque, v.modele, v.immatriculation 
        FROM affectations_fixes af
        JOIN vehicules v ON af.id_vehicule = v.id_vehicule
        WHERE af.id_employe = ? 
        LIMIT 1
    ";
    
    // Note: Si votre table d'affectation n'a pas de date de fin, la requête ci-dessus suffit.
    // Si vous gérez l'historique, ajoutez : AND (af.date_fin IS NULL OR af.date_fin > NOW())
    
    if (isset($conn)) { // On s'assure que la connexion $conn est bien là (via config.php)
        $stmt_v = $conn->prepare($sql_vehicule);
        if ($stmt_v) {
            $stmt_v->bind_param("i", $_SESSION['user_id']);
            $stmt_v->execute();
            $result_v = $stmt_v->get_result();
            
            if ($row_v = $result_v->fetch_assoc()) {
                $vehicule_affecte = "🚘 " . htmlspecialchars($row_v['marque'] . ' ' . $row_v['modele']) . " (" . htmlspecialchars($row_v['immatriculation']) . ")";
                $style_vehicule = "color: #2c3e50; font-weight: bold; font-size: 0.9em;"; // Style si trouvé (plus visible)
            }
            $stmt_v->close();
        }
    }
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
					<div class="user-vehicle" style="<?php echo $style_vehicule; ?>">
                        <?php echo $vehicule_affecte; ?>
                    </div>
                </div>
				<!--<a href="update_password.php" title="Paramètres" style="text-decoration:none; font-size: 1.2em;">⚙️</a>-->
				<a href="settings.php" title="Paramètres" style="text-decoration:none; font-size: 1.2em; margin-right: 15px;">⚙️</a>
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