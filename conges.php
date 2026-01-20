<?php
require_once 'config.php'; 
include 'includes/header.php';

// if (($_SESSION['user_role'] ?? '') !== 'Manager') exit();
// 1. Vérification de connexion et de rôle (Côté Serveur)
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'Manager') {
    // Redirection propre et sécurisée
    header("Location: index.php");
    exit();
}


$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';
$message_type = isset($_GET['type']) ? $_GET['type'] : '';

// Suppression
if (isset($_GET['action']) && $_GET['action'] == 'supprimer' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $conn->query("DELETE FROM conges WHERE id_conge = $id");
    echo "<script>window.location.href='conges.php?message=Congé supprimé&type=success';</script>";
    exit();
}

// Ajout
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	check_csrf();
    $id_emp = (int)$_POST['id_employe'];
    $debut = $_POST['date_debut'];
    $fin = $_POST['date_fin'];
    $motif = $_POST['motif'];

    if ($id_emp && $debut && $fin) {
        if ($debut > $fin) {
            $message = "La date de fin doit être après le début.";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("INSERT INTO conges (id_employe, date_debut, date_fin, motif, statut) VALUES (?, ?, ?, ?, 'Validé')");
            $stmt->bind_param("isss", $id_emp, $debut, $fin, $motif);
            $stmt->execute();
            echo "<script>window.location.href='conges.php?message=Congé ajouté&type=success';</script>";
            exit();
        }
    }
}

// Données
$employes = $conn->query("SELECT id_employe, nom, prenom FROM employes WHERE actif=1 ORDER BY nom");
$conges = $conn->query("SELECT c.*, e.nom, e.prenom FROM conges c JOIN employes e ON c.id_employe=e.id_employe ORDER BY c.date_debut DESC");
?>

<h2>🏖️ Gestion des Congés</h2>

<?php if ($message): ?>
    <div class="message <?php echo $message_type; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="form-container">
    <h3>Déclarer une absence (Libère le véhicule attitré)</h3>
    <form action="conges.php" method="POST">
	<?php csrf_field(); ?>
        <label>Employé</label>
        <select name="id_employe" required>
            <option value="">-- Sélectionner --</option>
            <?php while ($e = $employes->fetch_assoc()): ?>
                <option value="<?php echo $e['id_employe']; ?>"><?php echo $e['nom'] . ' ' . $e['prenom']; ?></option>
            <?php endwhile; ?>
        </select>

        <div class="time-group">
            <div>
                <label>Du</label>
                <input type="date" name="date_debut" required>
            </div>
            <div>
                <label>Au</label>
                <input type="date" name="date_fin" required>
            </div>
        </div>

        <label>Motif</label>
        <input type="text" name="motif" placeholder="CP, Maladie, RTT...">

        <button type="submit">Valider</button>
    </form>
</div>

<table>
    <thead>
        <tr>
            <th>Employé</th>
            <th>Période</th>
            <th>Motif</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($row = $conges->fetch_assoc()): ?>
        <tr>
            <td data-label="Employé"><?php echo htmlspecialchars($row['nom'] . ' ' . $row['prenom']); ?></td>
            <td data-label="Période">
                Du <?php echo date('d/m/Y', strtotime($row['date_debut'])); ?> 
                au <?php echo date('d/m/Y', strtotime($row['date_fin'])); ?>
            </td>
            <td data-label="Motif"><?php echo htmlspecialchars($row['motif']); ?></td>
            <td data-label="Actions">
                <a href="conges.php?action=supprimer&id=<?php echo $row['id_conge']; ?>" class="action-btn cancel-btn" onclick="return confirm('Supprimer ?')">Supprimer</a>
            </td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<?php 
$conn->close();
include 'includes/footer.php'; 
?>