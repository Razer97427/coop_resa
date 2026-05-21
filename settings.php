<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$current_token = $_SESSION['session_token'] ?? '';

// ── Révocation d'une session ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_session_id'])) {
    $rid = (int)$_POST['revoke_session_id'];
    $del = $conn->prepare("DELETE FROM sessions_auto WHERE id = ? AND user_id = ? AND session_token != ?");
    $del->bind_param("iis", $rid, $user_id, $current_token);
    $del->execute();
    $del->close();
    header('Location: settings.php');
    exit;
}

// ── Révocation de toutes les autres sessions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_all_others'])) {
    $del = $conn->prepare("DELETE FROM sessions_auto WHERE user_id = ? AND session_token != ?");
    $del->bind_param("is", $user_id, $current_token);
    $del->execute();
    $del->close();
    header('Location: settings.php');
    exit;
}

$stmt = $conn->prepare("SELECT two_fa_secret, email FROM employes WHERE id_employe = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

$a2fa_actif = !empty($res['two_fa_secret']);
$email_ok   = !empty($res['email']);

// ── Toutes les sessions actives ──
$stmt_s = $conn->prepare("SELECT id, ip, user_agent, login_time, last_activity, session_token FROM sessions_auto WHERE user_id = ? ORDER BY last_activity DESC");
$stmt_s->bind_param("i", $user_id);
$stmt_s->execute();
$all_sessions = $stmt_s->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_s->close();

function parse_browser(string $ua): string {
    if (preg_match('/Edg\//i', $ua))     return 'Microsoft Edge';
    if (preg_match('/OPR\//i', $ua))     return 'Opera';
    if (preg_match('/Chrome\//i', $ua))  return 'Google Chrome';
    if (preg_match('/Firefox\//i', $ua)) return 'Mozilla Firefox';
    if (preg_match('/Safari\//i', $ua))  return 'Safari';
    return 'Navigateur inconnu';
}

function parse_os(string $ua): string {
    if (preg_match('/Android/i', $ua))     return 'Android';
    if (preg_match('/iPhone|iPad/i', $ua)) return 'iOS';
    if (preg_match('/Windows NT/i', $ua))  return 'Windows';
    if (preg_match('/Mac OS X/i', $ua))    return 'macOS';
    if (preg_match('/Linux/i', $ua))       return 'Linux';
    return 'Appareil inconnu';
}

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'À l\'instant';
    if ($diff < 3600)   return 'Il y a ' . floor($diff / 60) . ' min';
    if ($diff < 86400)  return 'Il y a ' . floor($diff / 3600) . ' h';
    return 'Il y a ' . floor($diff / 86400) . ' j';
}

$other_count = count(array_filter($all_sessions, fn($s) => $s['session_token'] !== $current_token));
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

    .card-icon.green { background: linear-gradient(135deg, #1a7f4b, #28a745); }

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

    .badge-dot { width: 6px; height: 6px; border-radius: 50%; }
    .badge.on  .badge-dot { background: #28a745; }
    .badge.off .badge-dot { background: #dc3545; }

    .card-arrow { color: #ccc; }

    /* ── Carte sessions ── */
    .sessions-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.07);
        overflow: hidden;
    }

    .sessions-card-header {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 16px 22px;
        border-bottom: 1px solid #f0f0f0;
    }

    .sessions-card-header h2 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1a1a2e;
        margin: 0;
        flex: 1;
    }

    .session-count {
        font-size: 0.73rem;
        font-weight: 600;
        background: #e8f0fe;
        color: #0054b3;
        padding: 3px 10px;
        border-radius: 20px;
    }

    .session-row {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 22px;
        border-bottom: 1px solid #f5f5f5;
        transition: background 0.15s;
    }

    .session-row:last-child { border-bottom: none; }
    .session-row.current-session { background: #f8fbff; }

    .session-device-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        background: #f0f4ff;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        color: #007bff;
    }

    .session-row.current-session .session-device-icon {
        background: linear-gradient(135deg, #0054b3, #007bff);
        color: #fff;
    }

    .session-info { flex: 1; min-width: 0; }

    .session-browser {
        font-size: 0.88rem;
        font-weight: 700;
        color: #1a1a2e;
        margin-bottom: 2px;
        display: flex;
        align-items: center;
        gap: 7px;
        flex-wrap: wrap;
    }

    .badge-current {
        font-size: 0.68rem;
        font-weight: 600;
        background: #d4edda;
        color: #155724;
        padding: 2px 8px;
        border-radius: 20px;
        white-space: nowrap;
    }

    .badge-current::before {
        content: '';
        display: inline-block;
        width: 5px;
        height: 5px;
        border-radius: 50%;
        background: #28a745;
        margin-right: 4px;
        animation: pulse-dot 1.8s ease-in-out infinite;
        vertical-align: middle;
    }

    @keyframes pulse-dot {
        0%, 100% { opacity: 1; }
        50%       { opacity: 0.3; }
    }

    .session-meta {
        font-size: 0.77rem;
        color: #999;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .session-meta span { display: flex; align-items: center; gap: 3px; }

    .session-actions { flex-shrink: 0; }

    .btn-revoke {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 7px 14px;
        background: #fff;
        color: #dc3545;
        border: 1.5px solid #f5c6cb;
        border-radius: 7px;
        font-size: 0.78rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s;
        white-space: nowrap;
    }

    .btn-revoke:hover { background: #f8d7da; border-color: #dc3545; }

    .sessions-footer {
        padding: 14px 22px;
        border-top: 1px solid #f0f0f0;
        display: flex;
        justify-content: flex-end;
    }

    .btn-revoke-all {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        background: #fff;
        color: #dc3545;
        border: 1.5px solid #f5c6cb;
        border-radius: 8px;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s;
    }

    .btn-revoke-all:hover { background: #f8d7da; border-color: #dc3545; }

    .no-other-sessions {
        padding: 18px 22px;
        font-size: 0.83rem;
        color: #aaa;
        text-align: center;
    }

    @media (max-width: 480px) {
        .card-right .badge { display: none; }
        .session-meta { gap: 6px; }
        .btn-revoke span { display: none; }
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

    <!-- ── Sessions actives ── -->
    <div class="sessions-card">
        <div class="sessions-card-header">
            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18" style="color:#007bff;flex-shrink:0">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
            </svg>
            <h2>Sessions actives</h2>
            <?php if (count($all_sessions) > 0): ?>
            <span class="session-count"><?php echo count($all_sessions); ?> session<?php echo count($all_sessions) > 1 ? 's' : ''; ?></span>
            <?php endif; ?>
        </div>

        <?php if (empty($all_sessions)): ?>
            <div class="no-other-sessions">Aucune session enregistrée.</div>
        <?php else: ?>
            <?php foreach ($all_sessions as $s):
                $is_current = $s['session_token'] === $current_token;
                $ua         = $s['user_agent'] ?? '';
                $browser    = parse_browser($ua);
                $os         = parse_os($ua);
                $is_mobile  = preg_match('/Android|iPhone|iPad/i', $ua);
            ?>
            <div class="session-row <?php echo $is_current ? 'current-session' : ''; ?>">

                <div class="session-device-icon">
                    <?php if ($is_mobile): ?>
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M17 1H7c-1.1 0-2 .9-2 2v18c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2zm-5 20c-.83 0-1.5-.67-1.5-1.5S11.17 18 12 18s1.5.67 1.5 1.5S12.83 21 12 21zm5-4H7V4h10v13z"/>
                    </svg>
                    <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4v-2h16v2zm0-4H4V6h16v8z"/>
                    </svg>
                    <?php endif; ?>
                </div>

                <div class="session-info">
                    <div class="session-browser">
                        <?php echo htmlspecialchars($browser); ?> — <?php echo htmlspecialchars($os); ?>
                        <?php if ($is_current): ?>
                            <span class="badge-current">Session actuelle</span>
                        <?php endif; ?>
                    </div>
                    <div class="session-meta">
                        <span>
                            <svg viewBox="0 0 24 24" fill="currentColor" width="11" height="11"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                            <?php echo htmlspecialchars($s['ip'] ?: '—'); ?>
                        </span>
                        <span>
                            <svg viewBox="0 0 24 24" fill="currentColor" width="11" height="11"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>
                            Connexion : <?php echo date('d/m/Y H:i', strtotime($s['login_time'])); ?>
                        </span>
                        <span>Activité : <?php echo time_ago($s['last_activity']); ?></span>
                    </div>
                </div>

                <div class="session-actions">
                    <?php if (!$is_current): ?>
                    <form method="POST" onsubmit="return confirm('Révoquer cette session ?\nL\'appareil sera déconnecté lors de sa prochaine action.');">
                        <input type="hidden" name="revoke_session_id" value="<?php echo $s['id']; ?>">
                        <button type="submit" class="btn-revoke">
                            <svg viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                            <span>Révoquer</span>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

            </div>
            <?php endforeach; ?>

            <?php if ($other_count > 0): ?>
            <div class="sessions-footer">
                <form method="POST" onsubmit="return confirm('Déconnecter toutes les autres sessions ?\n<?php echo $other_count; ?> appareil(s) seront déconnectés.');">
                    <input type="hidden" name="revoke_all_others" value="1">
                    <button type="submit" class="btn-revoke-all">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
                        Déconnecter les <?php echo $other_count; ?> autre<?php echo $other_count > 1 ? 's' : ''; ?> session<?php echo $other_count > 1 ? 's' : ''; ?>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

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
