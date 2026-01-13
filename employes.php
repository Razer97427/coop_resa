<?php
require_once 'config.php'; 
include 'includes/header.php';

// Sécurité : Seul le Manager accède ici
if (($_SESSION['user_role'] ?? '') !== 'Manager') {
    echo "<script>window.location.href='index.php';</script>";
    exit();
}

$message = '';
$message_type = '';

// Ajout Employé
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $matricule = trim($_POST['matricule'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if (!empty($matricule) && !empty($nom) && !empty($prenom)) {
        // Vérif doublon
        $check = $conn->prepare("SELECT COUNT(*) FROM employes WHERE matricule = ? OR email = ?");
        $check->bind_param("ss", $matricule, $email);
        $check->execute();
        
        if ($check->get_result()->fetch_row()[0] > 0) {
            $message = "Erreur : Ce matricule ou cet email existe déjà.";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("INSERT INTO employes (matricule, nom, prenom, email, actif, mot_de_passe) VALUES (?, ?, ?, ?, 1, '123456')");
            // Note: mot de passe par défaut '123456' pour l'exemple
            $stmt->bind_param("ssss", $matricule, $nom, $prenom, $email);
            
            if ($stmt->execute()) {
                $message = "Collaborateur ajouté avec succès.";
                $message_type = "success";
            } else {
                $message = "Erreur SQL.";
                $message_type = "error";
            }
        }
    } else {
        $message = "Veuillez remplir les champs obligatoires.";
        $message_type = "error";
    }
}

$employes = $conn->query("SELECT * FROM employes ORDER BY actif DESC, nom ASC");
?>

<h2>👤 Gestion des Collaborateurs</h2>

<?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="form-container">
    <h3>Ajouter un employé</h3>
    <form action="employes.php" method="POST">
        <label>Matricule</label>
        <input type="text" name="matricule" required placeholder="Ex: M-2024" maxlength="20">

        <div class="time-group">
            <div style="flex:1">
                <label>Nom</label>
                <input type="text" name="nom" required maxlength="100">
            </div>
            <div style="flex:1">
                <label>Prénom</label>
                <input type="text" name="prenom" required maxlength="100">
            </div>
        </div>
        
        <label>Email (Optionnel)</label>
        <input type="email" name="email" placeholder="email@coop.re">

        <button type="submit">Enregistrer</button>
    </form>
</div>

<hr>

<h3>📚 Annuaire (<?php echo $employes->num_rows; ?>)</h3>
<table>
    <thead>
        <tr>
            <th>Matricule</th>
            <th>Identité</th>
            <th>Email</th>
            <th>Statut</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($row = $employes->fetch_assoc()): ?>
        <tr class="<?php echo $row['actif'] ? '' : 'archived'; ?>">
            <td data-label="Matricule"><strong><?php echo htmlspecialchars($row['matricule']); ?></strong></td>
            <td data-label="Identité"><?php echo htmlspecialchars($row['nom'] . ' ' . $row['prenom']); ?></td>
            <td data-label="Email"><?php echo htmlspecialchars($row['email'] ?? '-'); ?></td>
            <td data-label="Statut">
                <span class="status-tag <?php echo $row['actif'] ? 'validée' : 'annulée'; ?>">
                    <?php echo $row['actif'] ? 'Actif' : 'Inactif'; ?>
                </span>
            </td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<?php 
$conn->close();
include 'includes/footer.php'; 
?>