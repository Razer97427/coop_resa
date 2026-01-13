<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'config.php'; 

// Vérification de session
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

include 'includes/header.php';

// Script Sweetalert
echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';

// Script FullCalendar
/*echo '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>';*/
echo '<script src="https://cdn.jsdelivr.net/npm/fullcalendar-scheduler@6.1.10/index.global.min.js"></script>';

$current_user_id = $_SESSION['user_id'];
$message = isset($_GET['message']) ? urldecode($_GET['message']) : '';
$message_type = isset($_GET['type']) ? $_GET['type'] : '';
$restitution_id = isset($_GET['restitution_id']) ? (int)$_GET['restitution_id'] : 0;

if ($message) {
    echo '<div class="message ' . htmlspecialchars($message_type) . '">' . htmlspecialchars($message) . '</div>';
}

// ----------------------------------------------------
// LOGIQUE DE TRAITEMENT (POST)
// ----------------------------------------------------

// 1. Restitution
if (isset($_POST['restitution_submit'])) {
    $id_resa = (int)$_POST['id_reservation'];
    $km_fin = (int)$_POST['km_fin'];
    
    $date_retour_raw = $_POST['date_retour_reel'] ?? date('Y-m-d H:i:s');
    $date_retour = str_replace('T', ' ', $date_retour_raw); 
    
    $comment = $_POST['commentaire_retour'] ?? '';
    
    $stmt = $conn->prepare("UPDATE reservations SET km_fin=?, date_retour_reel=?, commentaire_retour=?, statut_resa='Terminée' WHERE id_reservation=?");
    $stmt->bind_param("issi", $km_fin, $date_retour, $comment, $id_resa);
    
    if ($stmt->execute()) {
        $stmt_v = $conn->prepare("UPDATE vehicules SET kilometrage = ? WHERE id_vehicule = (SELECT id_vehicule FROM reservations WHERE id_reservation = ?)");
        $stmt_v->bind_param("ii", $km_fin, $id_resa);
        $stmt_v->execute();

        echo "<script>window.location.href='index.php?message=" . urlencode("Véhicule restitué avec succès.") . "&type=success';</script>";
        exit();
    } else {
        echo '<div class="message error">Erreur lors de la restitution.</div>';
    }
}

// 2. Prise en charge
if (isset($_POST['prise_en_charge_submit'])) {
    $id_resa = (int)$_POST['id_reservation'];
    $km_debut = (int)$_POST['km_debut'];
	// On récupère le commentaire de départ
    $commentaire_depart = $_POST['commentaire_depart'] ?? '';
    $date_depart = date('Y-m-d H:i:s');
    
    $stmt = $conn->prepare("UPDATE reservations SET km_debut=?, date_depart_reel=?, commentaire_depart=?, statut_resa='En cours' WHERE id_reservation=?");
    $stmt->bind_param("issi", $km_debut, $date_depart, $commentaire_depart, $id_resa);
    $stmt->execute();
    
    echo "<script>window.location.href='index.php?message=" . urlencode("Bonne route ! Prise en charge OK.") . "&type=success';</script>";
    exit();
}

// 3. Nouvelle Réservation
if (isset($_POST['reservation_submit'])) {
    $id_vehicule = (int)$_POST['id_vehicule'];
    $motif = $_POST['motif'];
    $jour = $_POST['date_resa'];         
    $heure_debut = $_POST['heure_debut']; 
    $heure_fin = $_POST['heure_fin'];     
    $date_debut = $jour . ' ' . $heure_debut . ':00';
    $date_fin = $jour . ' ' . $heure_fin . ':00';
	
	// $type_motif = $_POST['type_motif'] ?? '';
    $destination = $_POST['destination'] ?? '';
    $motif_complet = $motif . " (" . $destination . ")";

    if (strtotime($date_debut) >= strtotime($date_fin)) {
        echo '<div class="message error">Erreur : L\'heure de fin doit être après l\'heure de début.</div>';
    } else {
        
        // --- VÉRIFICATION PÉRIODE CONGÉ (SI ATTITRÉ) ---
        $verif_ok = true;
        
        // 1. On regarde si le véhicule est attitré
        $stmt_attitre = $conn->prepare("SELECT id_employe FROM affectations_fixes WHERE id_vehicule = ?");
        $stmt_attitre->bind_param("i", $id_vehicule);
        $stmt_attitre->execute();
        $res_attitre = $stmt_attitre->get_result();
        
        if ($res_attitre->num_rows > 0) {
            $row_attitre = $res_attitre->fetch_assoc();
            $id_proprietaire = $row_attitre['id_employe'];
            
            // 2. On vérifie s'il existe un congé (PEU IMPORTE LE STATUT) qui couvre la période
            $sql_conge_check = "
                SELECT COUNT(*) 
                FROM conges 
                WHERE id_employe = ? 
                AND date_debut <= DATE(?) 
                AND date_fin >= DATE(?)
            ";
            $stmt_cc = $conn->prepare($sql_conge_check);
            $stmt_cc->bind_param("iss", $id_proprietaire, $date_debut, $date_fin);
            $stmt_cc->execute();
            $count_conge = $stmt_cc->get_result()->fetch_row()[0];
            
            if ($count_conge == 0) {
                echo '<div class="message error">⛔ Erreur : Ce véhicule est attitré. Il n\'est pas déclaré en congé sur ces dates.</div>';
                $verif_ok = false;
            }
        }

        // --- SUITE : VÉRIFICATION CHEVAUCHEMENT ---
        if ($verif_ok) {
            $check_sql = "SELECT COUNT(*) FROM reservations 
                          WHERE id_vehicule = ? 
                          AND statut_resa NOT IN ('Annulée', 'Refusée', 'Terminée')
                          AND (date_debut_resa < ? AND date_fin_resa > ?)";
            
            $stmt_check = $conn->prepare($check_sql);
            $stmt_check->bind_param("iss", $id_vehicule, $date_fin, $date_debut);
            $stmt_check->execute();
            $stmt_check->bind_result($count_overlap);
            $stmt_check->fetch();
            $stmt_check->close();

            if ($count_overlap > 0) {
                echo '<div class="message error">❌ Impossible : Ce véhicule est déjà réservé sur ce créneau !</div>';
            } else {
                $stmt = $conn->prepare("INSERT INTO reservations (id_employe, id_vehicule, date_debut_resa, date_fin_resa, motif, destination, statut_resa, km_debut, date_demande) VALUES (?, ?, ?, ?, ?, ?, 'Validée', 0, NOW())");
                $stmt->bind_param("iissss", $current_user_id, $id_vehicule, $date_debut, $date_fin, $motif, $destination);
                
                if ($stmt->execute()) {
                     echo "<script>window.location.href='index.php?message=" . urlencode("Réservation confirmée.") . "&type=success';</script>";
                     exit();
                } else {
                     echo '<div class="message error">Erreur technique lors de la réservation.</div>';
                }
            }
        }
    }
}

// ----------------------------------------------------
// CHARGEMENT DONNÉES
// ----------------------------------------------------

// ----------------------------------------------------
// ANCIENNE REQUETE
// ----------------------------------------------------
/*$sql_vehicules = "
    SELECT 
        v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal, 
        e.nom AS prop_nom, e.prenom AS prop_prenom,
        (SELECT CONCAT(date_debut, '|', date_fin) 
         FROM conges c 
         WHERE c.id_employe = e.id_employe 
         AND c.date_fin >= CURDATE() 
         ORDER BY c.date_debut ASC LIMIT 1
        ) as info_conge
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON v.id_vehicule = af.id_vehicule
    LEFT JOIN employes e ON af.id_employe = e.id_employe
    WHERE v.actif = 1
	AND v.est_communal = 1
    ORDER BY v.est_communal DESC, v.marque";*/

// ----------------------------------------------------
// NOUVELLE REQUETE
// ----------------------------------------------------	
$sql_vehicules = "
    SELECT 
        v.id_vehicule, v.marque, v.modele, v.immatriculation, v.est_communal, 
        e.nom AS prop_nom, e.prenom AS prop_prenom,
        (SELECT CONCAT(date_debut, '|', date_fin) 
         FROM conges c 
         WHERE c.id_employe = e.id_employe 
         AND c.date_fin >= CURDATE() 
         ORDER BY c.date_debut ASC LIMIT 1
        ) as info_conge
    FROM vehicules v
    LEFT JOIN affectations_fixes af ON v.id_vehicule = af.id_vehicule
    LEFT JOIN employes e ON af.id_employe = e.id_employe
    WHERE v.actif = 1
    -- J'AI SUPPRIMÉ 'AND v.est_communal = 1' ICI AUSSI
    ORDER BY v.est_communal DESC, v.marque";

$vehicules_list = $conn->query($sql_vehicules);

// Historique : Trié par ID décroissant
$stmt_h = $conn->prepare("
    SELECT r.*, v.marque, v.modele, v.kilometrage as km_actuel 
    FROM reservations r 
    JOIN vehicules v ON r.id_vehicule = v.id_vehicule 
    WHERE r.id_employe = ? 
    ORDER BY r.id_reservation DESC 
    LIMIT 20
");
$stmt_h->bind_param("i", $current_user_id);
$stmt_h->execute();
$historique = $stmt_h->get_result();

?>

<!-- MODE RESTITUTION -->
<?php if ($restitution_id > 0): 
    $stmt_r = $conn->prepare("SELECT r.*, v.marque, v.modele, v.kilometrage as km_vehicule FROM reservations r JOIN vehicules v ON r.id_vehicule = v.id_vehicule WHERE r.id_reservation = ?");
    $stmt_r->bind_param("i", $restitution_id);
    $stmt_r->execute();
    $resa_data = $stmt_r->get_result()->fetch_assoc();
    $km_ref = ($resa_data['km_debut'] > 0) ? $resa_data['km_debut'] : $resa_data['km_vehicule'];
?>
    <div class="restitution-container">
        <h3>📝 Restitution : <?php echo htmlspecialchars($resa_data['marque'] . ' ' . $resa_data['modele']); ?></h3>
        <p>KM Départ : <strong><?php echo $resa_data['km_debut']; ?> km</strong></p>
        
        <form action="index.php" method="POST">
            <input type="hidden" name="restitution_submit" value="1">
            <input type="hidden" name="id_reservation" value="<?php echo $restitution_id; ?>">
            
            <label>Date Retour Réelle :</label>
            <input type="datetime-local" name="date_retour_reel" value="<?php echo date('Y-m-d\TH:i'); ?>" required>

            <label>KM Fin (Compteur actuel) :</label>
            <input type="number" name="km_fin" required min="<?php echo $km_ref; ?>" value="">

            <label>Commentaire :</label>
            <textarea name="commentaire_retour" rows="3" placeholder="État du véhicule, problèmes..."></textarea>

            <button type="submit" class="action-btn return-btn" style="width:100%">Valider la Restitution</button>
            <div style="text-align:center; margin-top:10px;"><a href="index.php">Annuler</a></div>
        </form>
    </div>

<?php else: ?>

<!-- MODE RÉSERVATION -->
<div class="booking-container">
    <div class="booking-sidebar">
        <h3>📝 Réserver</h3>
        <form action="index.php" method="POST" id="formResa">
            <input type="hidden" name="reservation_submit" value="1">
            
            <label>Véhicule :</label>
            <select name="id_vehicule" id="id_vehicule" required>
                <option value="">-- Choisir --</option>
                <?php while ($v = $vehicules_list->fetch_assoc()): 
                    $est_communal = $v['est_communal'];
                    $est_attitre = !empty($v['prop_nom']);
                    $a_un_conge = !empty($v['info_conge']);
                    
                    $label = $v['marque'] . ' ' . $v['modele'] . ' (' . $v['immatriculation'] . ')';
                    $disabled = "";
                    $class = "communal";

                    if ($est_attitre) {
                        $label .= " [" . $v['prop_nom'] . "]";
                        
                        if ($a_un_conge) {
                            $dates = explode('|', $v['info_conge']);
                            $d_debut = date('d/m', strtotime($dates[0]));
                            $d_fin = date('d/m', strtotime($dates[1]));
                            $label .= " 🟢 (Libéré: $d_debut au $d_fin)";
                            $class = "libere";
                        } else {
                            $label .= " 🔴 (Privé)";
                            $disabled = "disabled";
                            $class = "perso";
                        }
                    }
                ?>
                    <option value="<?php echo $v['id_vehicule']; ?>" class="<?php echo $class; ?>" <?php echo $disabled; ?>>
                        <?php echo htmlspecialchars($label); ?>
                    </option>
                <?php endwhile; ?>
            </select>

            <label>Date :</label>
            <input type="date" name="date_resa" id="date_resa" required value="<?php echo date('Y-m-d'); ?>">
            
            <div class="time-group">
                <div>
                    <label>De :</label>
                    <input type="time" name="heure_debut" id="heure_debut" required value="08:00">
                </div>
                <div>
                    <label>À :</label>
                    <input type="time" name="heure_fin" id="heure_fin" required value="17:00">
                </div>
            </div>

            <label>Motif :</label>
            <input type="text" name="motif" placeholder="Ex: Visite chantier">

			<label>Destination :</label>
            <input type="text" name="destination" required placeholder="Ex: Visite chantier">
            
            <button type="submit">Valider</button>
        </form>
    </div>
    
    <div class="booking-calendar-wrapper">
        <div id="calendar"></div>
    </div>
</div>
<?php endif; ?>

<hr>

<h3>📂 Mes Réservations</h3>
<table>
    <thead>
        <tr>
            <th>Véhicule</th>
            <th>Dates</th>
            <th>Statut</th>
            <th style="width: 180px;">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php while ($row = $historique->fetch_assoc()): ?>
        <tr>
            <td data-label="Véhicule">
                <?php echo htmlspecialchars($row['marque'] . ' ' . $row['modele']); ?>
            </td>
            <td data-label="Dates">
                <?php echo date('d/m H:i', strtotime($row['date_debut_resa'])); ?> -> 
                <?php echo date('d/m H:i', strtotime($row['date_fin_resa'])); ?>
            </td>
            <td data-label="Statut">
                <span class="status-tag <?php echo strtolower(str_replace(' ', '-', $row['statut_resa'])); ?>">
                    <?php echo htmlspecialchars($row['statut_resa']); ?>
                </span>
            </td>
            <td data-label="Actions">
                <?php if ($row['statut_resa'] == 'Validée'): ?>
                    
                    <!-- FORMULAIRE DE DÉPART (MODIFIÉ) -->
                    <form action="index.php" method="POST" style="margin-bottom:10px; width:100%;">
                        <input type="hidden" name="prise_en_charge_submit" value="1">
                        <input type="hidden" name="id_reservation" value="<?php echo $row['id_reservation']; ?>">
                        
                        <!-- Champ KM Départ Manuel & Obligatoire -->
                        <div style="margin-bottom: 5px; text-align:left;">
                            <label style="font-size:0.8em; margin:0; color:#555;">Km Départ :</label>
                            <input type="number" 
                                   name="km_debut" 
                                   value="<?php echo $row['km_actuel']; ?>" 
                                   min="<?php echo $row['km_actuel']; ?>" 
                                   required 
                                   style="width: 100%; padding: 6px; border:1px solid #ccc; border-radius:4px;">
                        </div>
						
						<div style="margin-bottom: 5px; text-align:left;">
						<label style="font-size:0.8em; margin:0; color:#555;">Note état (facultatif) :</label>
						<input type="text" 
						name="commentaire_depart" 
						placeholder="Ex: Rayure porte gauche..." 
						style="width: 100%; padding: 6px; border:1px solid #ccc; border-radius:4px;">
						</div>

                        <button type="submit" class="action-btn charge-btn" style="width:100%; display:block;">
                            Valider Départ
                        </button>
                    </form>
                    
                    <a href="actions.php?action=annuler&id=<?php echo $row['id_reservation']; ?>" 
                       class="action-btn cancel-btn" 
                       style="width:100%; display:block; box-sizing:border-box;" 
                       onclick="return confirm('Annuler ?')">Annuler</a>

                <?php elseif ($row['statut_resa'] == 'En cours'): ?>
                    <a href="index.php?restitution_id=<?php echo $row['id_reservation']; ?>" 
                       class="action-btn return-btn"
                       style="width:100%; display:block; box-sizing:border-box;">Restituer</a>

                <?php elseif ($row['statut_resa'] == 'En attente'): ?>
                     <a href="actions.php?action=annuler&id=<?php echo $row['id_reservation']; ?>" 
                        class="action-btn cancel-btn"
                        style="width:100%; display:block; box-sizing:border-box;">Annuler</a>
                <?php endif; ?>
            </td>
        </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================================
    // 1. GESTION DU MENU DÉROULANT (AJAX)
    // ============================================================
    const dateInput = document.getElementById('date_resa');
    const selectVehicule = document.getElementById('id_vehicule');

    // Cette fonction recharge la liste des véhicules selon la date choisie
    function updateVehiculesList() {
        const selectedDate = dateInput.value;
        if (!selectedDate) return;

        // On sauvegarde la sélection actuelle pour essayer de la remettre après
        const currentSelection = selectVehicule.value;

        // Indicateur visuel de chargement
        selectVehicule.style.opacity = '0.5';
        selectVehicule.innerHTML = '<option value="">-- Chargement... --</option>';

        fetch(`api_vehicules.php?date=${selectedDate}`)
            .then(response => response.json())
            .then(data => {
                selectVehicule.innerHTML = '<option value="">-- Choisir --</option>';
                
                if(data.length === 0) {
                    selectVehicule.innerHTML += '<option disabled>Aucun véhicule disponible</option>';
                }

                data.forEach(vehicule => {
                    const option = document.createElement('option');
                    option.value = vehicule.id;
                    option.textContent = vehicule.label;
                    option.className = vehicule.class; 
                    selectVehicule.appendChild(option);
                });

                // Si le véhicule qu'on avait sélectionné est toujours dispo, on le resélectionne
                if (currentSelection && selectVehicule.querySelector(`option[value="${currentSelection}"]`)) {
                    selectVehicule.value = currentSelection;
                }
                
                selectVehicule.style.opacity = '1';
            })
            .catch(error => {
                console.error('Erreur:', error);
                selectVehicule.innerHTML = '<option value="">Erreur de chargement</option>';
            });
    }

    // --- MODIFICATION ICI : Écouteur complet ---
    if(dateInput) {
        dateInput.addEventListener('change', function() {
            // 1. On met à jour la liste déroulante
            updateVehiculesList();

            // 2. On déplace le calendrier vers la date choisie
            // On vérifie que le calendrier est bien initialisé avant d'appeler la fonction
            if (this.value && typeof calendar !== 'undefined' && calendar) {
                calendar.gotoDate(this.value);
            }
        });

        // On lance une fois au chargement de la page pour filtrer "Aujourd'hui"
        updateVehiculesList(); 
    }
    // -------------------------------------------

    // ============================================================
    // 2. CONFIGURATION FULLCALENDAR
    // ============================================================
    var calendarEl = document.getElementById('calendar');

    // On déclare la variable calendar ici pour qu'elle soit accessible (scope) par l'écouteur plus haut
    var calendar; 

    if (calendarEl) {
        calendar = new FullCalendar.Calendar(calendarEl, {
            schedulerLicenseKey: 'CC-Attribution-NonCommercial-NoDerivatives',
            initialView: 'resourceTimelineDay', 
            locale: 'fr',
            height: '578px', 
            stickyHeaderDates: true, 
            
            // IMPORTANT : Recharge les ressources (véhicules) quand la date change
            refetchResourcesOnNavigate: true,

            headerToolbar: { 
                left: 'prev,next today', 
                center: 'title', 
                right: 'resourceTimelineDay' 
            },

            resourceAreaWidth: window.innerWidth < 768 ? '100px' : '20%',
            resourceAreaHeaderContent: 'Véhicules',
            slotMinWidth: 70,
            
            resources: 'api_resources.php', 
            events: 'api_calendar.php',    

            slotMinTime: '06:00:00', 
            slotMaxTime: '20:00:00', 
            
            selectable: true,
            selectOverlap: false,

            // --- C'EST ICI QUE LES DEUX SYSTEMES SE PARLENT ---
            select: function(info) {
                // 1. On remplit les champs dates/heures du formulaire
                document.getElementById('date_resa').value = info.start.toLocaleDateString('fr-CA');
                document.getElementById('heure_debut').value = info.start.toTimeString().substring(0,5);
                document.getElementById('heure_fin').value = info.end.toTimeString().substring(0,5);
                
                // 2. IMPORTANT : On met à jour la liste déroulante car la date a changé via le clic
                updateVehiculesList(); 

                // 3. On sélectionne le véhicule cliqué dans la liste
                if (info.resource) {
                    setTimeout(() => {
                        let option = selectVehicule.querySelector('option[value="' + info.resource.id + '"]');
                        if(option) {
                            selectVehicule.value = info.resource.id;
                        } else {
                            console.log("Ce véhicule n'est pas réservable pour cette date.");
                        }
                    }, 300); 
                }

                // Scroll sur mobile
                if(window.innerWidth < 768) {
                    document.querySelector('.booking-sidebar').scrollIntoView({ behavior: 'smooth' });
                }
            },

            eventClick: function(info) {
                var props = info.event.extendedProps;
                var options = { hour: '2-digit', minute: '2-digit' };
                var debut = info.event.start.toLocaleTimeString('fr-FR', options);
                var fin = info.event.end ? info.event.end.toLocaleTimeString('fr-FR', options) : '?';

                Swal.fire({
                    title: props.vehicule,
                    html: `
                        <div style="text-align:left; font-size:0.9rem;">
                            <p><strong>👤 :</strong> ${props.conducteur}</p>
                            <p><strong>🕒 :</strong> ${debut} - ${fin}</p>
                            <p><strong>📝 :</strong> ${props.motif}</p>
                            <p><strong>Statut :</strong> ${props.statut}</p>
                        </div>
                    `,
                    icon: 'info',
                    confirmButtonText: 'Fermer'
                });
            }
        });
        
        calendar.render();
    }
});
</script>

<?php 
$conn->close();
include 'includes/footer.php'; 
?>