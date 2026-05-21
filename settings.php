<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$stmt = $conn->prepare("SELECT two_fa_secret, email FROM employes WHERE id_employe = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

$a2fa_actif   = !empty($res['two_fa_secret']);
$email_ok     = !empty($res['email']);
?>
<?php include 'includes/header.php'; ?>

<style>
    .settings-wrap {
        max-width: 720px;
        margin: 36px auto;
        padding: 0 16px 48px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .settings-page-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 4px;
    }

    .settings-page-sub {
        font-size: 0.85rem;
        color: #888;
        margin-bottom: 8px;
    }

    .settings-menu-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.07);
        overflow: hidden;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 20px 22px;
        transition: box-shadow 0.18s, transform 0.18s;
        border: 1.5px solid transparent;
        color: inherit;
    }

    .settings-menu-card:hover {
        box-shadow: 0 6px 24px rgba(0,123,255,0.13);
        border-color: #cce0ff;
        transform: translateY(-2px);
    }

    .card-icon {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: linear-gradient(135deg, #0054b3, #007bff);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #fff;
    }

    .card-icon.green {
        background: linear-gradient(135deg, #1a7f4b, #28a745);
    }

    .card-text { flex: 1; }

    .card-title {
        font-size: 0.97rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 3px;
    }

    .card-desc {
        font-size: 0.81rem;
        color: #888;
        line-height: 1.4;
    }

    .card-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 6px;
        flex-shrink: 0;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        white-space: nowrap;
    }

    .badge.on  { background: #d4edda; color: #155724; }
    .badge.off { background: #f8d7da; color: #721c24; }

    .badge-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
    }

    .badge.on  .badge-dot { background: #28a745; }
    .badge.off .badge-dot { background: #dc3545; }

    .card-arrow { color: #ccc; }

    @media (max-width: 480px) {
        .card-right .badge { display: none; }
    }
</style>

<div class="settings-wrap">

    <div>
        <div class="settings-page-title">Paramètres du compte</div>
        <div class="settings-page-sub">Sécurité et préférences</div>
    </div>

    <a href="update_password.php" class="settings-menu-card">
        <div class="card-icon">
            <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22">
                <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
            </svg>
        </div>
        <div class="card-text">
            <div class="card-title">Mot de passe</div>
            <div class="card-desc">Modifier votre mot de passe de connexion</div>
        </div>
        <div class="card-right">
            <svg class="card-arrow" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
        </div>
    </a>

    <a href="setup_2fa.php" class="settings-menu-card">
        <div class="card-icon <?php echo $a2fa_actif ? 'green' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" width="22" height="22">
                <path d="M17 1H7c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2zm-5 20c-.83 0-1.5-.67-1.5-1.5S11.17 18 12 18s1.5.67 1.5 1.5S12.83 21 12 21zm5-4H7V4h10v13z"/>
            </svg>
        </div>
        <div class="card-text">
            <div class="card-title">Double authentification (2FA)</div>
            <div class="card-desc">Configurer ou réinitialiser votre application TOTP</div>
        </div>
        <div class="card-right">
            <span class="badge <?php echo $a2fa_actif ? 'on' : 'off'; ?>">
                <span class="badge-dot"></span>
                <?php echo $a2fa_actif ? 'Activée' : 'Désactivée'; ?>
            </span>
            <svg class="card-arrow" viewBox="0 0 24 24" fill="currentColor" width="20" height="20"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
        </div>
    </a>

    <?php if (!$a2fa_actif || !$email_ok): ?>
    <div style="display:flex;align-items:flex-start;gap:9px;padding:12px 14px;background:#fff8e1;border:1px solid #ffe082;border-radius:10px;font-size:0.81rem;color:#795548;">
        <svg viewBox="0 0 24 24" fill="currentColor" width="16" height="16" style="flex-shrink:0;margin-top:1px;color:#f59e0b"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
        <span>
            <?php if (!$a2fa_actif && !$email_ok): ?>
                La 2FA n'est pas activée et aucun e-mail de récupération n'est renseigné. Contactez le <strong>service informatique</strong>.
            <?php elseif (!$a2fa_actif): ?>
                La double authentification n'est pas encore activée sur votre compte.
            <?php else: ?>
                Aucune adresse e-mail de récupération renseignée — la réinitialisation de mot de passe ne sera pas possible.
            <?php endif; ?>
        </span>
    </div>
    <?php endif; ?>

</div>

<?php include 'includes/footer.php'; ?>
