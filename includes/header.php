<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$is_manager   = ($_SESSION['user_role'] ?? '') === 'Manager';
$current_page = basename($_SERVER['PHP_SELF']);

$nb_attente = 0;
if ($is_manager && isset($conn)) {
    $r = $conn->query("SELECT COUNT(*) FROM reservations WHERE statut_resa='En attente'");
    if ($r) $nb_attente = (int)$r->fetch_row()[0];
}

$manager_pages = ['manager.php', 'parc.php', 'employes.php'];
if (!$is_manager && in_array($current_page, $manager_pages)) {
    header('Location: index.php?message=' . urlencode('Accès réservé.') . '&type=error');
    exit();
}

$page_titles = [
    'index.php'                  => 'Mes demandes',
    'manager.php'                => 'Gestion des demandes',
    'parc.php'                   => 'Parc automobile',
    'employes.php'               => 'Collaborateurs',
    'planning.php'               => 'Planning',
    'settings.php'               => 'Paramètres du compte',
    'conges.php'                 => 'Absences',
    'pointage_kilometrage.php'   => 'Pointage kilométrage',
];

$has_vehicule_affecte = false;
$nb_km_manquant = 0;
if (isset($conn, $_SESSION['user_id'])) {
    $uid_af = (int)$_SESSION['user_id'];
    $chk_af = $conn->prepare("
        SELECT af.id_vehicule
        FROM affectations_fixes af
        WHERE af.id_employe = ?
        LIMIT 1
    ");
    if ($chk_af) {
        $chk_af->bind_param("i", $uid_af);
        $chk_af->execute();
        $row_af = $chk_af->get_result()->fetch_assoc();
        $chk_af->close();
        if ($row_af) {
            $has_vehicule_affecte = true;
            $mois_hdr  = (int)date('n');
            $annee_hdr = (int)date('Y');
            $id_veh_hdr = (int)$row_af['id_vehicule'];
            $chk_km = $conn->prepare("
                SELECT 1 FROM pointages_kilometrage
                WHERE id_vehicule = ? AND mois = ? AND annee = ?
                LIMIT 1
            ");
            if ($chk_km) {
                $chk_km->bind_param("iii", $id_veh_hdr, $mois_hdr, $annee_hdr);
                $chk_km->execute();
                $chk_km->store_result();
                $nb_km_manquant = ($chk_km->num_rows === 0) ? 1 : 0;
                $chk_km->close();
            }
        }
    }
}
$doc_title = ($page_titles[$current_page] ?? 'Tableau de bord') . ' — TERRACOOP';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($doc_title); ?></title>
    <link rel="stylesheet" href="styles.css?v=<?php echo filemtime('styles.css'); ?>">
</head>
<body>

<header class="main-header">
    <div class="header-content">

        <!-- ── Logo + Marque ── -->
        <a href="index.php" class="header-brand">
            <div class="brand-icon-wrap">
                <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22">
                    <path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/>
                </svg>
            </div>
            <div class="brand-text">
                <span class="brand-name">TERRACOOP</span>
                <span class="brand-sub">Flotte Automobile</span>
            </div>
        </a>

        <?php if (isset($_SESSION['user_id'])): ?>

            <!-- ── Hamburger mobile ── -->
            <button class="menu-toggle" id="menuToggle" aria-label="Menu" onclick="toggleMenu()">
                <span></span><span></span><span></span>
            </button>

            <!-- ── Navigation ── -->
            <nav class="main-nav" id="mainNav">

                <a href="index.php" class="nav-link <?php echo $current_page==='index.php' ? 'active' : ''; ?>">
                    Accueil
                </a>

                <?php if ($has_vehicule_affecte): ?>
                    <a href="pointage_kilometrage.php" class="nav-link <?php echo $current_page==='pointage_kilometrage.php' ? 'active' : ''; ?>">
                        Pointage Véhicule
                        <?php if ($nb_km_manquant > 0): ?>
                            <span class="nav-badge"><?php echo $nb_km_manquant; ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>

                <?php if ($is_manager): ?>
                    <a href="manager.php" class="nav-link <?php echo $current_page==='manager.php' ? 'active' : ''; ?>">
                        Demandes
                        <?php if ($nb_attente > 0): ?>
                            <span class="nav-badge"><?php echo $nb_attente; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="parc.php" class="nav-link <?php echo $current_page==='parc.php' ? 'active' : ''; ?>">
                        Parc
                    </a>
                    <a href="employes.php" class="nav-link <?php echo $current_page==='employes.php' ? 'active' : ''; ?>">
                        Equipes
                    </a>
                <?php endif; ?>

                <!-- ── Partie droite ── -->
                <div class="nav-right">

                    <div class="nav-user">
                        <div class="nav-avatar"><?php echo strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1)); ?></div>
                        <div class="nav-user-info">
                            <span class="nav-user-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                            <span class="nav-user-role"><?php echo $is_manager ? 'Manager' : 'Employé'; ?></span>
                        </div>
                    </div>

                    <a href="settings.php" class="nav-icon-btn <?php echo $current_page==='settings.php' ? 'active' : ''; ?>" title="Paramètres">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                            <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
                        </svg>
                    </a>

                    <a href="logout.php" class="nav-logout">Déconnexion</a>

                </div>
            </nav>

        <?php endif; ?>
    </div>
</header>

<script>
function toggleMenu() {
    const nav = document.getElementById('mainNav');
    const btn = document.getElementById('menuToggle');
    if (!nav) return;
    const open = nav.classList.toggle('active');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
document.addEventListener('click', function(e) {
    const nav = document.getElementById('mainNav');
    const btn = document.getElementById('menuToggle');
    if (nav && btn && !nav.contains(e.target) && !btn.contains(e.target)) {
        nav.classList.remove('active');
    }
});
</script>

<div class="content">
