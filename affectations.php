<?php
require_once 'config.php'; 
include 'includes/header.php';

if (($_SESSION['user_role'] ?? '') !== 'Manager') exit();

// Supprimer
if (isset($_GET['action']) && $_GET['action'] == 'supprimer') {
    $id = (int)$_GET['id'];
    $conn->query("DELETE FROM affectations_fixes WHERE id_affectation=$id");
}

// Ajouter
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $id_emp = (int)$_POST['id_employe'];
    $id_veh = (int)$_POST['id_vehicule'];
    
    // Vérification rapide doublon
    $check = $conn->query("SELECT * FROM affectations_fixes WHERE id_employe=$id_emp OR id_vehicule=$id_veh");
    
    if ($check->num_rows == 0) {
        $conn->query("INSERT INTO affectations_fixes (id_employe, id_vehicule) VALUES ($id_emp, $id_veh)");
        $message = "Affectation enregistrée.";
        $type = "success";
    } else {
        $message = "Erreur : Cet employé ou ce véhicule est déjà affecté.";
        $type = "error";
    }
}

// Données
// Employés sans affectation
$employes = $conn->query("SELECT id_employe, nom, prenom, matricule FROM employes WHERE actif=1 AND id_employe NOT IN (SELECT id_employe FROM affectations_fixes) ORDER BY nom");

// Véhicules privés sans affectation
$vehicules = $conn->query("SELECT id_vehicule, marque, modele, immatriculation, type_carburant FROM vehicules WHERE est_communal=0 AND actif=1 AND id_vehicule NOT IN (SELECT id_vehicule FROM affectations_fixes) ORDER BY marque");

$list = $conn->query("SELECT af.id_affectation, e.nom, e.prenom, e.matricule, v.marque, v.modele, v.immatriculation FROM affectations_fixes af JOIN employes e ON af.id_employe=e.id_employe JOIN vehicules v ON af.id_vehicule=v.id_vehicule");
?>

<style>
    /* Style pour le menu de recherche personnalisé */
    .searchable-select {
        position: relative;
        margin-bottom: 15px;
    }
    .search-input {
        width: 100%;
        padding: 12px;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        font-size: 1rem;
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="%23ccc" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001c.03.04.062.078.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1.007 1.007 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0z"/></svg>');
        background-repeat: no-repeat;
        background-position: right 10px center;
    }
    .options-list {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        max-height: 200px;
        overflow-y: auto;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 0 0 4px 4px;
        z-index: 1000;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .options-list.show {
        display: block;
    }
    .option-item {
        padding: 10px 15px;
        cursor: pointer;
        border-bottom: 1px solid #f1f1f1;
    }
    .option-item:hover {
        background-color: #f8f9fa;
        color: var(--primary);
        font-weight: 500;
    }
    .no-result {
        padding: 10px;
        color: #999;
        font-style: italic;
        text-align: center;
    }
</style>

<h2>🔑 Affectations Véhicules Attitrés</h2>

<?php if (isset($message)): ?>
    <div class="message <?php echo $type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="form-container">
    <h3>Nouvelle Affectation</h3>
    <form action="affectations.php" method="POST">
        
        <!-- EMPLOYÉS -->
        <label>Employé (sans véhicule)</label>
        <!-- On garde le select caché pour la soumission du formulaire -->
        <select name="id_employe" id="select_employe" required style="display:none;">
            <option value="">-- Choisir --</option>
            <?php while ($e = $employes->fetch_assoc()): ?>
                <option value="<?php echo $e['id_employe']; ?>" data-search="<?php echo strtolower($e['nom'] . ' ' . $e['prenom'] . ' ' . $e['matricule']); ?>">
                    <?php echo $e['nom'] . ' ' . $e['prenom'] . ' (' . $e['matricule'] . ')'; ?>
                </option>
            <?php endwhile; ?>
        </select>
        <!-- Champ de recherche visible -->
        <div class="searchable-select" id="container_employe">
            <input type="text" class="search-input" placeholder="Rechercher nom, prénom ou matricule..." autocomplete="off">
            <div class="options-list"></div>
        </div>

        <!-- VÉHICULES -->
        <label>Véhicule (privé non assigné)</label>
        <select name="id_vehicule" id="select_vehicule" required style="display:none;">
            <option value="">-- Choisir --</option>
            <?php while ($v = $vehicules->fetch_assoc()): ?>
                <option value="<?php echo $v['id_vehicule']; ?>" data-search="<?php echo strtolower($v['marque'] . ' ' . $v['modele'] . ' ' . $v['immatriculation']); ?>">
                    <?php echo $v['marque'] . ' ' . $v['modele'] . ' - ' . $v['immatriculation'] . ' (' . $v['type_carburant'] . ')'; ?>
                </option>
            <?php endwhile; ?>
        </select>
        <!-- Champ de recherche visible -->
        <div class="searchable-select" id="container_vehicule">
            <input type="text" class="search-input" placeholder="Rechercher marque, modèle ou plaque..." autocomplete="off">
            <div class="options-list"></div>
        </div>
        
        <button type="submit" style="margin-top:20px;">Enregistrer</button>
    </form>
</div>

<h3>Affectations en cours</h3>
<table>
    <thead>
        <tr>
            <th>Employé</th>
            <th>Véhicule</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($row = $list->fetch_assoc()): ?>
        <tr>
            <td data-label="Employé">
                <?php echo htmlspecialchars($row['nom'] . ' ' . $row['prenom']); ?>
                <br><small class="text-muted"><?php echo htmlspecialchars($row['matricule']); ?></small>
            </td>
            <td data-label="Véhicule">
                <?php echo htmlspecialchars($row['marque'] . ' ' . $row['modele']); ?>
                <br><small class="text-muted"><?php echo htmlspecialchars($row['immatriculation']); ?></small>
            </td>
            <td data-label="Actions">
                <a href="affectations.php?action=supprimer&id=<?php echo $row['id_affectation']; ?>" class="action-btn cancel-btn" onclick="return confirm('Libérer ce véhicule ?')">Libérer</a>
            </td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<script>
// Script simple pour transformer les selects en champs de recherche
function setupSearchableSelect(selectId, containerId) {
    const select = document.getElementById(selectId);
    const container = document.getElementById(containerId);
    const input = container.querySelector('.search-input');
    const list = container.querySelector('.options-list');
    
    // Récupérer toutes les options du select original
    const options = Array.from(select.options).slice(1); // On saute le premier "Choisir"
    
    // Fonction pour afficher la liste filtrée
    function renderOptions(filterText = '') {
        list.innerHTML = '';
        const lowerFilter = filterText.toLowerCase();
        let found = false;

        options.forEach(opt => {
            const text = opt.innerText;
            const searchData = opt.getAttribute('data-search') || text.toLowerCase();
            
            if (searchData.includes(lowerFilter)) {
                found = true;
                const div = document.createElement('div');
                div.className = 'option-item';
                div.innerText = text;
                div.onclick = () => {
                    input.value = text;     // Met à jour le champ texte visible
                    select.value = opt.value; // Met à jour le select caché (pour le POST)
                    list.classList.remove('show');
                };
                list.appendChild(div);
            }
        });

        if (!found) {
            list.innerHTML = '<div class="no-result">Aucun résultat</div>';
        }
        list.classList.add('show');
    }

    // Événements
    input.addEventListener('input', (e) => renderOptions(e.target.value));
    input.addEventListener('focus', () => renderOptions(input.value));
    
    // Fermer si on clique ailleurs
    document.addEventListener('click', (e) => {
        if (!container.contains(e.target)) {
            list.classList.remove('show');
        }
    });
}

// Initialisation
document.addEventListener('DOMContentLoaded', () => {
    setupSearchableSelect('select_employe', 'container_employe');
    setupSearchableSelect('select_vehicule', 'container_vehicule');
});
</script>

<?php 
$conn->close();
include 'includes/footer.php'; 
?>