<?php
require_once 'config.php';
include 'includes/header.php';
if (!$is_manager) { header('Location: index.php'); exit(); }

$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt_emp = $conn->prepare("
        SELECT e.*, af.id_affectation, v.marque, v.modele, v.immatriculation
        FROM employes e
        LEFT JOIN affectations_fixes af ON e.id_employe=af.id_employe
        LEFT JOIN vehicules v ON af.id_vehicule=v.id_vehicule
        WHERE e.matricule LIKE ? OR e.nom LIKE ? OR e.prenom LIKE ?
            OR CONCAT(e.nom,' ',e.prenom) LIKE ? OR CONCAT(e.prenom,' ',e.nom) LIKE ?
        ORDER BY e.actif DESC, e.nom
    ");
    $stmt_emp->bind_param("sssss", $like, $like, $like, $like, $like);
    $stmt_emp->execute();
    $employes = $stmt_emp->get_result();
} else {
    $employes = $conn->query("
        SELECT e.*, af.id_affectation, v.marque, v.modele, v.immatriculation
        FROM employes e
        LEFT JOIN affectations_fixes af ON e.id_employe=af.id_employe
        LEFT JOIN vehicules v ON af.id_vehicule=v.id_vehicule
        ORDER BY e.actif DESC, e.nom
    ");
}
?>

<style>
/* ── Barre de recherche ── */
.emp-search-wrap {
    position: relative;
    max-width: 520px;
    margin-bottom: 18px;
}
.emp-search-wrap svg.ico-loupe {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    pointer-events: none;
}
#q {
    padding: 11px 38px 11px 42px !important;
    border: 1.5px solid #dee2e6 !important;
    border-radius: 8px !important;
    font-size: .97rem !important;
    width: 100% !important;
    box-sizing: border-box;
    background: #fff;
    margin: 0 !important;
}
#q:focus {
    border-color: var(--primary, #007bff) !important;
    box-shadow: 0 0 0 3px rgba(0,123,255,.12) !important;
    outline: none;
}
#btnClearEmp {
    position: absolute;
    right: 9px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: #9ca3af;
    cursor: pointer;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 0;
    margin: 0;
    font-size: .85rem;
    line-height: 1;
    transition: color .15s, background .15s;
}
#btnClearEmp:hover { color: #374151; background: #f3f4f6; box-shadow: none; transform: translateY(-50%); }

/* ── Barre d'outils (compteur + par-page) ── */
.emp-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 10px;
}
.emp-toolbar h3 { margin: 0; }
.per-page-group {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: .86em;
    color: #6c757d;
    white-space: nowrap;
}
.per-page-btn {
    background: #fff;
    border: 1.5px solid #dee2e6;
    border-radius: 5px;
    padding: 4px 11px;
    font-size: .86rem;
    cursor: pointer;
    color: #495057;
    font-weight: 500;
    transition: all .15s;
    width: auto;
    margin: 0;
    line-height: 1.5;
}
.per-page-btn:hover  { background: #e9ecef; border-color: #adb5bd; box-shadow: none; transform: none; }
.per-page-btn.active { background: #007bff; border-color: #007bff; color: #fff; box-shadow: none; transform: none; }

/* ── Pagination ── */
#empPagination {
    display: none;
    justify-content: center;
    align-items: center;
    gap: 4px;
    margin-top: 18px;
    flex-wrap: wrap;
}
.page-btn {
    background: #fff;
    border: 1.5px solid #dee2e6;
    border-radius: 6px;
    padding: 6px 13px;
    font-size: .9rem;
    cursor: pointer;
    color: #495057;
    font-weight: 500;
    transition: all .15s;
    min-width: 38px;
    width: auto;
    margin: 0;
    line-height: 1.4;
}
.page-btn:hover:not(:disabled) { background: #e9ecef; border-color: #adb5bd; box-shadow: none; transform: none; }
.page-btn.active  { background: #007bff; border-color: #007bff; color: #fff; box-shadow: none; transform: none; }
.page-btn:disabled { opacity: .38; cursor: default; }
.page-ellipsis { padding: 0 4px; color: #adb5bd; font-size: .95rem; line-height: 2.2; }
</style>

<h2>Gestion des Collaborateurs</h2>

<form action="employes.php" method="GET" id="formEmpSearch">
    <div class="emp-search-wrap">
        <svg class="ico-loupe" width="16" height="16" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input type="text" id="q" name="q"
               value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Matricule, nom ou prénom…"
               oninput="empLiveFilter(this.value)"
               autocomplete="off" spellcheck="false">
        <button type="button" id="btnClearEmp" title="Effacer la recherche" onclick="empClearSearch()">✕</button>
    </div>
</form>

<div class="emp-toolbar">
    <h3>Annuaire — <span id="empCount"></span><span id="empSearchLabel"></span></h3>
    <div class="per-page-group">
        Afficher :
        <button class="per-page-btn" data-n="10"  onclick="empSetPerPage(10)">10</button>
        <button class="per-page-btn" data-n="20"  onclick="empSetPerPage(20)">20</button>
        <button class="per-page-btn" data-n="50"  onclick="empSetPerPage(50)">50</button>
        <button class="per-page-btn" data-n="0"   onclick="empSetPerPage(0)">Tous</button>
    </div>
</div>
<table id="tblEmployes">
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
    <tr class="<?php echo $row['actif'] ? '' : 'archived'; ?>"
        data-s="<?php echo strtolower(htmlspecialchars($row['matricule'].' '.$row['nom'].' '.$row['prenom'].' '.($row['email']??''))); ?>">
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
            <?php if (!empty($row['marque'])): ?>
                <button class="action-btn" onclick="voirConges(<?php echo (int)$row['id_employe']; ?>, '<?php echo htmlspecialchars($row['prenom'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['nom'], ENT_QUOTES); ?>')" style="margin:0;">📅 Congés</button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
</table>

<div id="empPagination"></div>

<!-- Modal pour voir les congés -->
<div id="modalConges" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; max-width:500px; width:90%; max-height:70vh; overflow-y:auto; box-shadow:0 4px 20px rgba(0,0,0,.3);">
        <div style="padding:20px; border-bottom:1px solid #dee2e6; display:flex; justify-content:space-between; align-items:center;">
            <h3 style="margin:0;">📅 Absences de <span id="modalEmpName"></span></h3>
            <button onclick="fermerModalConges()" style="background:none; border:none; font-size:1.5em; cursor:pointer; color:#6c757d;">×</button>
        </div>
        <div id="congesContent" style="padding:20px;"></div>
    </div>
</div>

<script>
function voirConges(idEmp, prenom, nom) {
    const modal = document.getElementById('modalConges');
    const content = document.getElementById('congesContent');
    document.getElementById('modalEmpName').textContent = prenom + ' ' + nom;
    modal.style.display = 'flex';

    fetch('includes/api_conges.php?id_employe=' + idEmp)
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                content.innerHTML = '<p style="color:#dc3545;">Erreur : ' + escHtml(data.error) + '</p>';
            } else if (data.conges.length === 0) {
                content.innerHTML = '<p style="color:#6c757d;">Aucune absence enregistrée.</p>';
            } else {
                let html = '<table style="width:100%; border-collapse:collapse;">';
                data.conges.forEach(c => {
                    html += '<tr style="border-bottom:1px solid #dee2e6;">';
                    html += '<td style="padding:10px; color:#555;"><strong>' + escHtml(c.date_debut) + '</strong></td>';
                    html += '<td style="padding:10px; text-align:center; color:#999;">→</td>';
                    html += '<td style="padding:10px; color:#555;"><strong>' + escHtml(c.date_fin) + '</strong></td>';
                    html += '</tr>';
                });
                html += '</table>';
                content.innerHTML = html;
            }
        })
        .catch(err => {
            content.innerHTML = '<p style="color:#dc3545;">Erreur réseau : ' + escHtml(err.message) + '</p>';
        });
}

function fermerModalConges() {
    document.getElementById('modalConges').style.display = 'none';
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('modalConges').addEventListener('click', function(e) {
    if (e.target === this) fermerModalConges();
});
</script>
(function () {
    const inp        = document.getElementById('q');
    const btnClear   = document.getElementById('btnClearEmp');
    const countEl    = document.getElementById('empCount');
    const labelEl    = document.getElementById('empSearchLabel');
    const paginEl    = document.getElementById('empPagination');
    const allRows    = Array.from(document.querySelectorAll('#tblEmployes tbody tr'));

    let filterVal    = inp.value.trim().toLowerCase();
    let currentPage  = 1;
    let perPage      = parseInt(localStorage.getItem('emp_per_page') ?? '10');
    if (![10, 20, 50, 0].includes(perPage)) perPage = 10;

    /* ── Rows visibles selon le filtre ── */
    function filtered() {
        if (!filterVal) return allRows;
        return allRows.filter(tr => tr.dataset.s.includes(filterVal));
    }

    /* ── Rendu principal ── */
    function render() {
        const rows  = filtered();
        const total = rows.length;
        const pages = perPage === 0 ? 1 : Math.ceil(total / perPage);

        if (currentPage > pages) currentPage = pages || 1;

        const start = perPage === 0 ? 0       : (currentPage - 1) * perPage;
        const end   = perPage === 0 ? total   : Math.min(start + perPage, total);

        allRows.forEach(tr => (tr.style.display = 'none'));
        rows.forEach((tr, i) => { tr.style.display = (i >= start && i < end) ? '' : 'none'; });

        /* Compteur */
        if (total === 0) {
            countEl.textContent = 'Aucun collaborateur';
        } else if (perPage === 0 || total <= perPage) {
            countEl.textContent = total + ' collaborateur' + (total > 1 ? 's' : '');
        } else {
            countEl.textContent = (start + 1) + '–' + end + ' sur ' + total + ' collaborateur' + (total > 1 ? 's' : '');
        }

        /* Label recherche */
        labelEl.innerHTML = filterVal
            ? ' <small style="font-size:.72em; font-weight:normal; color:#6c757d;">pour « ' + escHtml(filterVal) + ' »</small>'
            : '';

        renderPagination(total, pages);
    }

    /* ── Contrôles de pagination ── */
    function renderPagination(total, pages) {
        if (perPage === 0 || pages <= 1) { paginEl.style.display = 'none'; return; }
        paginEl.style.display = 'flex';

        let html = btn('‹', currentPage - 1, currentPage === 1);
        pageRange(currentPage, pages).forEach(p => {
            html += p === '…'
                ? '<span class="page-ellipsis">…</span>'
                : btn(p, p, false, p === currentPage);
        });
        html += btn('›', currentPage + 1, currentPage === pages);
        paginEl.innerHTML = html;
    }

    function btn(label, page, disabled, active) {
        return '<button class="page-btn' + (active ? ' active' : '') + '"'
             + (disabled ? ' disabled' : ' onclick="empGoPage(' + page + ')"') + '>'
             + label + '</button>';
    }

    function pageRange(cur, total) {
        if (total <= 7) return Array.from({length: total}, (_, i) => i + 1);
        if (cur <= 4)        return [1, 2, 3, 4, 5, '…', total];
        if (cur >= total - 3) return [1, '…', total-4, total-3, total-2, total-1, total];
        return [1, '…', cur - 1, cur, cur + 1, '…', total];
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── API publique ── */
    window.empGoPage = function (page) {
        currentPage = page;
        render();
        document.getElementById('tblEmployes').scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    window.empSetPerPage = function (n) {
        perPage = n;
        currentPage = 1;
        localStorage.setItem('emp_per_page', n);
        document.querySelectorAll('.per-page-btn').forEach(b => {
            b.classList.toggle('active', parseInt(b.dataset.n) === n);
        });
        render();
    };

    window.empLiveFilter = function (val) {
        filterVal = val.trim().toLowerCase();
        btnClear.style.display = filterVal ? 'flex' : 'none';
        currentPage = 1;
        render();
    };

    window.empClearSearch = function () {
        inp.value = '';
        empLiveFilter('');
        inp.focus();
    };

    /* ── Init ── */
    if (inp.value.trim()) btnClear.style.display = 'flex';
    document.querySelectorAll('.per-page-btn').forEach(b => {
        b.classList.toggle('active', parseInt(b.dataset.n) === perPage);
    });
    inp.addEventListener('keydown', e => { if (e.key === 'Escape') empClearSearch(); });

    render();
})();
</script>
<?php $conn->close(); include 'includes/footer.php'; ?>
