<?php
require_once 'config.php'; 
include 'includes/header.php';

if (($_SESSION['user_role'] ?? '') !== 'Manager') exit();

// --- 1. AJOUT D'UN VÉHICULE ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $immat = $_POST['immatriculation'];
    $marque = $_POST['marque'];
    $modele = $_POST['modele'];
    $carbu = $_POST['type_carburant'];
    $communal = isset($_POST['est_communal']) ? 1 : 0;

    $stmt = $conn->prepare("INSERT INTO vehicules (immatriculation, marque, modele, type_carburant, est_communal, actif) VALUES (?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("ssssi", $immat, $marque, $modele, $carbu, $communal);
    
    if($stmt->execute()) {
        // Redirection pour éviter la resoumission du formulaire + message succès
        header("Location: vehicules.php?msg=added");
        exit();
    }
}

// --- 2. ACTIONS ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $msg_code = 'updated'; // Message par défaut
    
    if ($_GET['action'] == 'desactiver') {
        $conn->query("UPDATE vehicules SET actif=0 WHERE id_vehicule=$id");
    }
    elseif ($_GET['action'] == 'reactiver') {
        $conn->query("UPDATE vehicules SET actif=1 WHERE id_vehicule=$id");
    }
    elseif ($_GET['action'] == 'toggle_type') {
        $conn->query("UPDATE vehicules SET est_communal = 1 - est_communal WHERE id_vehicule=$id");
    }
    elseif ($_GET['action'] == 'supprimer') {
        $conn->query("DELETE FROM reservations WHERE id_vehicule=$id");
        $conn->query("DELETE FROM affectations_fixes WHERE id_vehicule=$id");
        $conn->query("DELETE FROM vehicules WHERE id_vehicule=$id AND actif=0");
        $msg_code = 'deleted'; // Message spécifique suppression
    }
    
    // Redirection propre en gardant les filtres + Ajout du message de succès
    $redirect_params = $_GET;
    unset($redirect_params['action'], $redirect_params['id']);
    $redirect_params['msg'] = $msg_code; // On ajoute le paramètre msg
    
    $query_string = http_build_query($redirect_params);
    echo "<script>window.location.href='vehicules.php?$query_string';</script>";
    exit();
}

// --- 3. FILTRES & PAGINATION ---

$limit = 10; // Véhicules par page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Initialisation des filtres
$where_clauses = [];
$params = [];
$types = "";

// A. Filtre Immatriculation
$search_immat = $_GET['search_immat'] ?? '';
if (!empty($search_immat)) {
    $where_clauses[] = "immatriculation LIKE ?";
    $params[] = "%$search_immat%";
    $types .= "s";
}

// B. Filtre Marque / Modèle
$search_model = $_GET['search_model'] ?? '';
if (!empty($search_model)) {
    $where_clauses[] = "(marque LIKE ? OR modele LIKE ?)";
    $term_model = "%$search_model%";
    $params[] = $term_model;
    $params[] = $term_model;
    $types .= "ss";
}

// C. Filtre Carburant
$filter_carbu = $_GET['filter_carbu'] ?? '';
if (!empty($filter_carbu)) {
    $where_clauses[] = "type_carburant = ?";
    $params[] = $filter_carbu;
    $types .= "s";
}

// D. Filtre État
$filter_actif = $_GET['filter_actif'] ?? 'all';
if ($filter_actif !== 'all') {
    $where_clauses[] = "actif = ?";
    $params[] = (int)$filter_actif;
    $types .= "i";
}

// E. Filtre par type de véhicule
$type_voiture = $_GET['type_voiture'] ?? 'all';
if  ($type_voiture !== 'all') {
	$where_clauses[] = "est_communal = ?";
	$params[] = (int)$type_voiture;
	$types .= "i";
}

// Construction de la clause WHERE
$sql_where = "";
if (!empty($where_clauses)) {
    $sql_where = " WHERE " . implode(" AND ", $where_clauses);
}

// --- COMPTAGE TOTAL (Pour la pagination) ---
$sql_count = "SELECT COUNT(*) as total FROM vehicules" . $sql_where;
$stmt_count = $conn->prepare($sql_count);
if (!empty($params)) {
    $bind_names[] = $types;
    for ($i=0; $i<count($params); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $params[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array(array($stmt_count, 'bind_param'), $bind_names);
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_records / $limit);

// --- REQUÊTE PRINCIPALE (Avec Limit/Offset) ---
$sql = "SELECT * FROM vehicules" . $sql_where . " ORDER BY actif DESC, immatriculation ASC LIMIT ? OFFSET ?";

// On ajoute limit et offset aux paramètres pour la requête finale
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt_list = $conn->prepare($sql);
if (!empty($params)) {
    $bind_names_list = [];
    $bind_names_list[] = $types;
    for ($i=0; $i<count($params); $i++) {
        $bind_name = 'bind_list' . $i;
        $$bind_name = $params[$i];
        $bind_names_list[] = &$$bind_name;
    }
    call_user_func_array(array($stmt_list, 'bind_param'), $bind_names_list);
}
$stmt_list->execute();
$list = $stmt_list->get_result();
?>

<h2>🚗 Gestion du Parc (<?php echo $total_records; ?> véhicules)</h2>

<?php if (isset($_GET['msg'])): ?>
    <div style="background-color: #d4edda; color: #155724; padding: 15px; border-radius: 5px; border: 1px solid #c3e6cb; margin-bottom: 20px; text-align: center; font-weight: bold;">
        <?php 
            if ($_GET['msg'] == 'added') echo "✅ Nouveau véhicule ajouté avec succès !";
            elseif ($_GET['msg'] == 'updated') echo "✅ Véhicule mis à jour avec succès !";
            elseif ($_GET['msg'] == 'deleted') echo "🗑️ Véhicule supprimé définitivement.";
            else echo "✅ Action effectuée avec succès.";
        ?>
    </div>
<?php endif; ?>

<div class="form-container">
    <h3>Ajouter un véhicule</h3>
    <form action="vehicules.php" method="POST">
        <label>Immatriculation</label>
        <input type="text" name="immatriculation" required placeholder="Ex: AA-123-BB">
        
        <label>Marque & Modèle</label>
        <div class="time-group">
            <input type="text" name="marque" placeholder="Marque" required>
            <input type="text" name="modele" placeholder="Modèle" required>
        </div>

        <label>Carburant</label>
        <select name="type_carburant">
            <option value="Essence">Essence</option>
            <option value="Diesel">Diesel</option>
            <option value="Electrique">Electrique</option>
            <option value="Hybride">Hybride</option>
        </select>

        <label style="margin-top:15px; display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="checkbox" name="est_communal" checked style="width:auto; margin:0;"> 
            <span>Véhicule partagé (Communal)</span>
        </label>
        
        <button type="submit">Ajouter</button>
    </form>
</div>

<div class="form-container" style="background-color: #e9ecef; border: 1px solid #dee2e6;">
    <h3 style="margin-top:0; font-size:1.1rem;">🔍 Filtrer la liste</h3>
    <form action="vehicules.php" method="GET">
        <div class="time-group" style="flex-wrap: wrap;">
            
            <div style="flex: 1; min-width: 180px;">
                <label style="margin-top:0;">Immatriculation</label>
                <input type="text" name="search_immat" value="<?php echo htmlspecialchars($search_immat); ?>" placeholder="Ex: AA-123">
            </div>
			
			<div style="flex: 1; min-width: 180px;">
				<label style="margin-top:0;">Type</label>
					<select name="type_voiture">
					<option value="all">-- Tous --</option>
					<option value="1" <?php if($type_voiture === '1') echo 'selected'; ?>>Communal</option>
					<option value="0" <?php if($type_voiture === '0') echo 'selected'; ?>>Attitré</option>
				</select>
			</div>
			
            <div style="flex: 1; min-width: 180px;">
                <label style="margin-top:0;">Marque / Modèle</label>
                <input type="text" name="search_model" value="<?php echo $search_model; ?>" placeholder="Ex: Peugeot, Clio...">
            </div>

            <div style="flex: 1; min-width: 150px;">
                <label style="margin-top:0;">Carburant</label>
                <select name="filter_carbu">
                    <option value="">-- Tous --</option>
                    <option value="Essence" <?php if($filter_carbu == 'Essence') echo 'selected'; ?>>Essence</option>
                    <option value="Gasoil" <?php if($filter_carbu == 'Gasoil') echo 'selected'; ?>>Gasoil</option>
                    <option value="Electrique" <?php if($filter_carbu == 'Electrique') echo 'selected'; ?>>Electrique</option>
                    <option value="Hybride" <?php if($filter_carbu == 'Hybride') echo 'selected'; ?>>Hybride</option>
                </select>
            </div>

            <div style="flex: 1; min-width: 150px;">
                <label style="margin-top:0;">État</label>
                <select name="filter_actif">
                    <option value="all">-- Tous --</option>
                    <option value="1" <?php if($filter_actif === '1') echo 'selected'; ?>>Actifs Uniquement</option>
                    <option value="0" <?php if($filter_actif === '0') echo 'selected'; ?>>Hors Service</option>
                </select>
            </div>
        </div>

        <div style="margin-top: 15px; display: flex; gap: 10px;">
            <button type="submit" class="action-btn charge-btn" style="width: auto; margin-top:0;">Filtrer</button>
            <a href="vehicules.php" class="action-btn cancel-btn" style="text-decoration:none; padding:12px 20px;">Réinitialiser</a>
        </div>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>Immat</th>
            <th>Véhicule</th>
            <th>Type actuel</th>
            <th>Statut</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if ($list->num_rows > 0): ?>
        <?php while ($row = $list->fetch_assoc()): ?>
        <tr class="<?php echo $row['actif'] ? '' : 'archived'; ?>">
            <td data-label="Immat">
                <strong><?php echo htmlspecialchars($row['immatriculation']); ?></strong>
            </td>
            <td data-label="Véhicule">
                <?php echo htmlspecialchars($row['marque'] . ' ' . $row['modele']); ?>
                <br><small style="color:#666;"><?php echo htmlspecialchars($row['type_carburant']); ?></small>
            </td>
            <td data-label="Type">
                <?php if($row['est_communal']): ?>
                    <span style="color:#155724; font-weight:bold;">🏢 Communal</span>
                <?php else: ?>
                    <span style="color:#004085; font-weight:bold;">👤 Attitré</span>
                <?php endif; ?>
            </td>
            <td data-label="Statut">
                <span class="status-tag <?php echo $row['actif'] ? 'actif' : 'hors-service'; ?>">
                    <?php echo $row['actif'] ? 'Actif' : 'Hors Service'; ?>
                </span>
            </td>
            <td data-label="Actions">
                <?php 
                $url_params = $_GET;
                $url_params['id'] = $row['id_vehicule'];
                
                // Préparation des paramètres pour le lien
                // Note : On retire le 'msg' s'il est présent pour éviter de le traîner
                if(isset($url_params['msg'])) unset($url_params['msg']);
                ?>
                
                <?php if ($row['actif']): ?>
                    <a href="vehicules.php?<?php echo http_build_query(array_merge($url_params, ['action' => 'toggle_type'])); ?>" 
                       class="action-btn return-btn"
                       title="Changer le type"
                       onclick="return confirm('Voulez-vous changer le type de ce véhicule (Communal <-> Attitré) ?');">
                       🔁 Type
                    </a>
                    
                    <a href="vehicules.php?<?php echo http_build_query(array_merge($url_params, ['action' => 'desactiver'])); ?>" 
                       class="action-btn cancel-btn"
                       onclick="return confirm('Êtes-vous sûr de vouloir désactiver ce véhicule ? Il ne sera plus réservable.');">
                       Désactiver
                    </a>
                <?php else: ?>
                    <a href="vehicules.php?<?php echo http_build_query(array_merge($url_params, ['action' => 'reactiver'])); ?>" 
                       class="action-btn charge-btn"
                       onclick="return confirm('Réactiver ce véhicule ?');">
                       Réactiver
                    </a>
                    <a href="vehicules.php?<?php echo http_build_query(array_merge($url_params, ['action' => 'supprimer'])); ?>" 
                       class="action-btn cancel-btn" 
                       onclick="return confirm('ATTENTION : Supprimer définitivement ce véhicule et tout son historique ? Cette action est irréversible.');">
                       Supprimer
                    </a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
    <?php else: ?>
        <tr>
            <td colspan="5" style="text-align:center;">Aucun véhicule trouvé.</td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>

<?php if ($total_pages > 1): ?>
<div style="text-align: center; margin-top: 20px; display: flex; justify-content: center; gap: 5px; flex-wrap: wrap;">
    <?php 
    // Fonction pour générer l'URL de la page
    function getPageUrl($p) {
        $params = $_GET;
        $params['page'] = $p;
        // On ne propage pas le message de succès lors du changement de page
        if(isset($params['msg'])) unset($params['msg']);
        return 'vehicules.php?' . http_build_query($params);
    }
    ?>

    <?php if ($page > 1): ?>
        <a href="<?php echo getPageUrl($page - 1); ?>" class="action-btn" style="background:#6c757d;">&laquo;</a>
    <?php endif; ?>

    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="<?php echo getPageUrl($i); ?>" 
           class="action-btn" 
           style="<?php echo ($i == $page) ? 'background:var(--primary); font-weight:bold;' : 'background:#e9ecef; color:#333 !important; border:1px solid #ccc;'; ?>">
           <?php echo $i; ?>
        </a>
    <?php endfor; ?>

    <?php if ($page < $total_pages): ?>
        <a href="<?php echo getPageUrl($page + 1); ?>" class="action-btn" style="background:#6c757d;">&raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php 
$conn->close();
include 'includes/footer.php'; 
?>