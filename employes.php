<?php
require_once 'config.php';
include 'includes/header.php';
if (!$is_manager) { header('Location: index.php'); exit(); }

$message      = '';
$message_type = '';

// Ajout
if (isset($_POST['ajout_employe'])) {
    csrf_verify();
    $matricule = trim($_POST['matricule'] ?? '');
    $nom       = trim($_POST['nom']       ?? '');
    $prenom    = trim($_POST['prenom']    ?? '');
    $email     = trim($_POST['email']     ?? '');
    $role      = $_POST['role']  ?? 'Employé';

    if (empty($matricule) || empty($nom) || empty($prenom)) {
        $message = '❌ Matricule, nom et prénom sont obligatoires.';
        $message_type = 'error';
    } else {
        $check = $conn->prepare("SELECT COUNT(*) FROM employes WHERE matricule=?");
        $check->bind_param("s", $matricule);
        $check->execute();
        if ($check->get_result()->fetch_row()[0] > 0) {
            $message = '❌ Ce matricule existe déjà.';
            $message_type = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO employes (matricule, nom, prenom, email, role, actif, mot_de_passe) VALUES (?,?,?,?,?,1,'123456')");
            $stmt->bind_param("sssss", $matricule, $nom, $prenom, $email, $role);
            if ($stmt->execute()) {
                $message = '✅ Collaborateur ajouté. Mot de passe par défaut : 123456';
                $message_type = 'success';
            } else {
                $message = '❌ Erreur SQL.';
                $message_type = 'error';
            }
        }
    }
}

// Toggle actif
if (isset($_GET['emp_action']) && isset($_GET['id'])) {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        http_response_code(403); exit;
    }
    $id  = (int)$_GET['id'];
    $act = $_GET['emp_action'];
    if ($act === 'desactiver' || $act === 'reactiver') {
        $actif_val = ($act === 'reactiver') ? 1 : 0;
        $stmt_tog = $conn->prepare("UPDATE employes SET actif=? WHERE id_employe=?");
        $stmt_tog->bind_param("ii", $actif_val, $id);
        $stmt_tog->execute();
        $stmt_tog->close();
    }
    header('Location: employes.php?message=' . urlencode('✅ Collaborateur mis à jour.') . '&type=success');
    exit();
}

if (!$message && isset($_GET['message'])) {
    $message      = urldecode($_GET['message']);
    $message_type = $_GET['type'] ?? 'success';
}

$employes = $conn->query("
    SELECT e.*, af.id_affectation, v.marque, v.modele, v.immatriculation
    FROM employes e
    LEFT JOIN affectations_fixes af ON e.id_employe=af.id_employe
    LEFT JOIN vehicules v ON af.id_vehicule=v.id_vehicule
    ORDER BY e.actif DESC, e.nom
");
?>

<h2>Gestion des Collaborateurs</h2>

<?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="form-container" style="max-width:700px;">
    <h3 style="margin-top:0;">➕ Ajouter un collaborateur</h3>
    <form action="employes.php" method="POST">
        <input type="hidden" name="ajout_employe" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="time-group">
            <div>
                <label>Matricule <span style="color:red">*</span></label>
                <input type="text" name="matricule" required placeholder="Ex: EMP001" maxlength="20">
            </div>
            <div>
                <label>Rôle</label>
                <select name="role">
                    <option value="Employé">Employé</option>
                    <option value="Manager">Manager</option>
                </select>
            </div>
        </div>
        <div class="time-group">
            <div>
                <label>Nom <span style="color:red">*</span></label>
                <input type="text" name="nom" required maxlength="100">
            </div>
            <div>
                <label>Prénom <span style="color:red">*</span></label>
                <input type="text" name="prenom" required maxlength="100">
            </div>
        </div>
        <label>Email (optionnel)</label>
        <input type="email" name="email" placeholder="prenom.nom@terracoop.re">
        <p class="text-muted" style="margin-top:10px; font-size:.85em;">ℹ️ Le mot de passe par défaut sera <strong>123456</strong>. L'employé devra configurer sa 2FA avant de se connecter.</p>
        <button type="submit" style="margin-top:8px;">Enregistrer</button>
    </form>
</div>

<h3>Annuaire — <?php echo $employes->num_rows; ?> collaborateur<?php echo $employes->num_rows > 1 ? 's' : ''; ?></h3>
<table>
    <thead>
        <tr>
            <th>Matricule</th>
            <th>Identité</th>
            <th>Rôle</th>
            <th>Email</th>
            <th>Véhicule attitré</th>
            <th>Statut</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($row = $employes->fetch_assoc()): ?>
    <tr class="<?php echo $row['actif'] ? '' : 'archived'; ?>">
        <td data-label="Matricule"><strong><?php echo htmlspecialchars($row['matricule']); ?></strong></td>
        <td data-label="Identité"><?php echo htmlspecialchars($row['nom'].' '.$row['prenom']); ?></td>
        <td data-label="Rôle">
            <span class="badge <?php echo $row['role']==='Manager' ? 'badge-manager' : 'badge-employe'; ?>">
                <?php echo htmlspecialchars($row['role']); ?>
            </span>
        </td>
        <td data-label="Email"><small><?php echo htmlspecialchars($row['email'] ?: '—'); ?></small></td>
        <td data-label="Véhicule">
            <?php if (!empty($row['marque'])): ?>
                🔑 <?php echo htmlspecialchars($row['marque'].' '.$row['modele']); ?><br>
                <small class="text-muted"><?php echo htmlspecialchars($row['immatriculation']); ?></small>
            <?php else: ?>
                <span class="text-muted">Aucun</span>
            <?php endif; ?>
        </td>
        <td data-label="Statut">
            <span class="badge <?php echo $row['actif'] ? 'badge-active' : 'badge-inactive'; ?>">
                <?php echo $row['actif'] ? 'Actif' : 'Inactif'; ?>
            </span>
        </td>
        <td data-label="Actions">
            <?php if ($row['actif']): ?>
                <a href="employes.php?emp_action=desactiver&id=<?php echo $row['id_employe']; ?>&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>" class="action-btn cancel-btn" onclick="return confirm('Désactiver ce collaborateur ?')">Désactiver</a>
            <?php else: ?>
                <a href="employes.php?emp_action=reactiver&id=<?php echo $row['id_employe']; ?>&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>" class="action-btn charge-btn">Réactiver</a>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<?php $conn->close(); include 'includes/footer.php'; ?>
