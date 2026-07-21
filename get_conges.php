<?php
// On active l'affichage des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

$url = "https://terracoop.re/APIs/conges_periode.csv";

// --- PRÉPARATION DES REQUÊTES ---

// 1. Requête pour TRADUIRE le matricule en ID
// On cherche l'ID technique qui correspond au matricule du CSV
$sql_get_id = "SELECT id_employe FROM employes WHERE matricule = ?";
$stmt_get_id = $conn->prepare($sql_get_id);

// 2. Requête pour INSÉRER le congé
// On utilise l'ID trouvé juste avant
$sql_insert = "INSERT INTO conges (id_employe, date_debut, date_fin, motif, statut) 
               VALUES (?, ?, ?, ?, ?)";
$stmt_insert = $conn->prepare($sql_insert);


// 3. Requête pour SUPPRIMER avant Insertion
$sql_delete_conges = "DELETE FROM conges";
$stmt_delete_conges = $conn->prepare($sql_delete_conges);
$stmt_delete_conges->execute();
$stmt_delete_conges->close();

// Variables par défaut
$matricule_csv = "";
$id_trouve = 0;
$date_deb = "";
$date_fin = "";
$motif = "Import Auto";
$statut = "En attente";

// On lie les paramètres aux requêtes
$stmt_get_id->bind_param("s", $matricule_csv); // "s" car matricule est souvent du texte (varchar)
$stmt_insert->bind_param("issss", $id_trouve, $date_deb, $date_fin, $motif, $statut);

if (($handle = fopen($url, "r")) !== FALSE) {
    
    echo "<h3>Rapport d'importation :</h3><ul>";
    $succes = 0;
    $echecs = 0;

    while (($data = fgetcsv($handle, 1000, ";", '"')) !== FALSE) {
        
        if (count($data) >= 3) {
            
            // ÉTAPE 1 : Récupération des données du CSV
            $matricule_csv = trim($data[0]); // On nettoie les espaces éventuels
            $date_deb = $data[1];
            $date_fin = $data[2];

            // ÉTAPE 2 : On cherche l'ID de cet employé
            $stmt_get_id->execute();
            $result = $stmt_get_id->get_result();

            if ($row = $result->fetch_assoc()) {
                // TROUVÉ ! On récupère son véritable ID
                $id_trouve = $row['id_employe'];

                // ÉTAPE 3 : Insertion du congé
                try {
                    if ($stmt_insert->execute()) {
                        echo "<li style='color:green'>OK : Matricule $matricule_csv (ID $id_trouve) -> Congé ajouté.</li>";
                        $succes++;
                    } else {
                        echo "<li style='color:red'>Erreur SQL pour matricule $matricule_csv : " . $stmt_insert->error . "</li>";
                        $echecs++;
                    }
                } catch (Exception $e) {
                    echo "<li style='color:red'>Exception pour matricule $matricule_csv : " . $e->getMessage() . "</li>";
                    $echecs++;
                }

            } else {
                // PAS TROUVÉ
                echo "<li style='color:orange'><b>Introuvable :</b> Le matricule <b>$matricule_csv</b> n'existe pas dans votre table 'employes'. Congé ignoré.</li>";
                $echecs++;
            }
        }
    }
    
    echo "</ul>";
    echo "<hr>Importation terminée : $succes succès, $echecs échecs.";
    
    fclose($handle);
    $stmt_get_id->close();
    $stmt_insert->close();

    // ================================================================
    // ALERTE « congé long → véhicule à récupérer » (managers Terracoop)
    // ================================================================
    // Table de suivi persistante (jamais effacée par l'import) → 1 seul mail par congé
    $conn->query("CREATE TABLE IF NOT EXISTS conges_alertes (
        id_alerte  INT AUTO_INCREMENT PRIMARY KEY,
        id_employe INT NOT NULL,
        date_debut DATE NOT NULL,
        date_fin   DATE NOT NULL,
        date_envoi DATETIME NOT NULL,
        UNIQUE KEY uniq_conge (id_employe, date_debut, date_fin)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Congés >= 7 jours, employé avec véhicule attitré, congé en cours/à venir, PAS déjà notifié
    $res_cl = $conn->query("
        SELECT c.id_employe, c.date_debut, c.date_fin,
               e.nom, e.prenom, e.matricule,
               v.marque, v.modele, v.immatriculation,
               (DATEDIFF(c.date_fin, c.date_debut) + 1) AS nb_jours
        FROM conges c
        JOIN affectations_fixes af ON af.id_employe = c.id_employe
        JOIN vehicules v           ON v.id_vehicule = af.id_vehicule
        JOIN employes e            ON e.id_employe  = c.id_employe
        WHERE DATEDIFF(c.date_fin, c.date_debut) >= 7
          AND YEAR(c.date_debut)  = YEAR(CURDATE())
          AND MONTH(c.date_debut) = MONTH(CURDATE())
          AND NOT EXISTS (
              SELECT 1 FROM conges_alertes a
              WHERE a.id_employe = c.id_employe AND a.date_debut = c.date_debut AND a.date_fin = c.date_fin
          )
        ORDER BY c.date_debut
    ");
    $nouveaux = $res_cl ? $res_cl->fetch_all(MYSQLI_ASSOC) : [];

    if ($nouveaux) {
        // Marque comme notifiés (avant l'envoi : évite tout doublon même si le mail échoue)
        $ins_cl = $conn->prepare("INSERT IGNORE INTO conges_alertes (id_employe, date_debut, date_fin, date_envoi) VALUES (?, ?, ?, NOW())");
        foreach ($nouveaux as $n) {
            $ins_cl->bind_param("iss", $n['id_employe'], $n['date_debut'], $n['date_fin']);
            $ins_cl->execute();
        }
        $ins_cl->close();

        // Destinataires : managers Terracoop (établissement 1)
        $mgrs = $conn->query("SELECT email, nom, prenom FROM employes WHERE role='Manager' AND actif=1 AND email IS NOT NULL AND email <> '' AND id_etablissement = 1");

        if ($mgrs && $mgrs->num_rows > 0 && function_exists('email_actif') && email_actif('conge_recup')) {
            require_once __DIR__ . '/vendor/autoload.php';

            // Corps du tableau récap
            $lignes = '';
            foreach ($nouveaux as $n) {
                $lignes .= '<tr>'
                    . '<td style="padding:7px 10px;border-bottom:1px solid #eee;"><strong>' . htmlspecialchars($n['prenom'] . ' ' . $n['nom']) . '</strong><br><small style="color:#888;">' . htmlspecialchars($n['matricule']) . '</small></td>'
                    . '<td style="padding:7px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($n['marque'] . ' ' . $n['modele']) . '<br><small style="color:#888;">' . htmlspecialchars($n['immatriculation']) . '</small></td>'
                    . '<td style="padding:7px 10px;border-bottom:1px solid #eee;white-space:nowrap;">' . date('d/m/Y', strtotime($n['date_debut'])) . ' → ' . date('d/m/Y', strtotime($n['date_fin'])) . '<br><small style="color:#888;">' . (int)$n['nb_jours'] . ' jours</small></td>'
                    . '</tr>';
            }

            foreach ($mgrs as $mgr) {
                try {
                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = smtp_host;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = smtp_username;
                    $mail->Password   = smtp_password;
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = smtp_port;
                    $mail->CharSet    = 'UTF-8';
                    $mail->setFrom(smtp_from, 'Gestion Flotte TERRACOOP');
                    $mail->addAddress($mgr['email'], $mgr['prenom'] . ' ' . $mgr['nom']);
                    $mail->addCustomHeader('X-MJ-TrackClicks', '0');
                    $mail->addCustomHeader('X-MJ-TrackOpens',  '0');
                    $mail->isHTML(true);
                    $mail->Subject = '[TERRACOOP] 🏖️ ' . count($nouveaux) . ' véhicule(s) à récupérer — congés de ' . date('m/Y');
                    $mail->Body = '
<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
  <div style="background:#856404;padding:20px 24px;">
    <h2 style="color:#fff;margin:0;font-size:1.12em;">🏖️ Congés à venir — véhicules à récupérer</h2>
  </div>
  <div style="padding:24px;">
    <p style="margin:0 0 16px;">Bonjour <strong>' . htmlspecialchars($mgr['prenom']) . '</strong>,</p>
    <p style="margin:0 0 18px;color:#555;">Les collaborateurs suivants <strong>débutent un congé ce mois-ci</strong> (≥ 7 jours). Pensez à <strong>récupérer leur véhicule attitré</strong> :</p>
    <table style="width:100%;border-collapse:collapse;font-size:.92em;">
      <thead><tr style="background:#f0f4ff;">
        <th style="padding:8px 10px;text-align:left;">Collaborateur</th>
        <th style="padding:8px 10px;text-align:left;">Véhicule</th>
        <th style="padding:8px 10px;text-align:left;">Congé</th>
      </tr></thead>
      <tbody>' . $lignes . '</tbody>
    </table>
  </div>
  <div style="background:#f8f9fa;padding:12px 24px;text-align:center;color:#999;font-size:.8em;border-top:1px solid #dee2e6;">
    Gestion de Flotte TERRACOOP — message automatique
  </div>
</div>';
                    $mail->AltBody = count($nouveaux) . " véhicule(s) à récupérer (congés à venir >= 7 jours). Connectez-vous à l'application pour le détail.";
                    $mail->send();
                } catch (\PHPMailer\PHPMailer\Exception $e) {
                    error_log('[get_conges] Erreur email congé pour ' . $mgr['email'] . ' : ' . $mail->ErrorInfo);
                }
            }
        }
        echo "<hr>🏖️ Alerte congés : " . count($nouveaux) . " nouveau(x) congé(s) long(s) détecté(s) (email managers Terracoop).";
    }

    $conn->close();
    
} else {
    echo "Impossible d'ouvrir le fichier CSV.";
}
?>