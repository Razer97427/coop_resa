<?php
require_once 'config.php';
require_once 'includes/admin_code.php';
require_once 'GoogleAuthenticator.php';

// ── Accès réservé via login.php (code e-mail déjà vérifié) ─────────────────
if (empty($_SESSION['admin_2fa_access'])) {
    header('Location: login.php?admin=1');
    exit();
}

// ── Fermeture de la session admin ──────────────────────────────────────────
if (isset($_GET['logout_admin'])) {
    unset($_SESSION['admin_2fa_access']);
    admin_code_deverrouiller();
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
}

$ga           = new PHPGangsta_GoogleAuthenticator();
$msg          = '';
$msg_type     = '';
$user_data    = null;
$show_setup   = false;

$matricule = isset($_POST['matricule']) ? trim($_POST['matricule']) : null;

// ── Recherche employé ──────────────────────────────────────────────────────
if ($matricule) {
    $stmt = $conn->prepare("SELECT matricule, nom, prenom, two_fa_secret FROM employes WHERE matricule = ?");
    $stmt->bind_param("s", $matricule);
    $stmt->execute();
    $user_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user_data) {
        $msg      = "Aucun employé trouvé avec ce matricule.";
        $msg_type = 'error';
        $matricule = null;
    }
}

// ── Déverrouillage ──────────────────────────────────────────────────────────
// L'accès admin (code e-mail) est déjà vérifié à l'entrée sur la page (voir
// login.php?admin=1) : plus besoin de ressaisir un code ici.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['unlock_mod'])) {
    $show_setup = true;
}

// ── Vérification OTP et enregistrement ────────────────────────────────────
if (isset($_POST['verify_and_update'])) {
    $new_secret = $_POST['nouveau_secret'] ?? '';
    $otp_code   = $_POST['otp_to_check']   ?? '';
    if ($ga->verifyCode($new_secret, $otp_code, 2)) {
        $stmt = $conn->prepare("UPDATE employes SET two_fa_secret = ? WHERE matricule = ?");
        $stmt->bind_param("ss", $new_secret, $matricule);
        if ($stmt->execute()) {
            $msg      = "2FA mis à jour avec succès pour <strong>" . htmlspecialchars($matricule) . "</strong>.";
            $msg_type = 'success';
            $user_data['two_fa_secret'] = $new_secret;
        }
        $stmt->close();
    } else {
        $msg      = "Code TOTP invalide. Vérifiez l'heure de votre téléphone et réessayez.";
        $msg_type = 'error';
        $show_setup = true;
    }
}

// ── Révocation 2FA ──────────────────────────────────────────────────────────
// Accès admin déjà vérifié à l'entrée sur la page ; une confirmation JS
// (confirm()) protège juste contre un clic accidentel (voir bouton ci-dessous).
if (isset($_POST['revoke_2fa']) && $user_data) {
    $stmt = $conn->prepare("UPDATE employes SET two_fa_secret = NULL WHERE matricule = ?");
    $stmt->bind_param("s", $matricule);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $msg      = "2FA révoqué pour <strong>" . htmlspecialchars($user_data['prenom'] . ' ' . $user_data['nom']) . " (" . htmlspecialchars($matricule) . ")</strong>. L'employé ne pourra plus se connecter jusqu'à reconfiguration.";
        $msg_type = 'success';
        $user_data['two_fa_secret'] = null;
    }
    $stmt->close();
}

// ── Génération QR code (côté serveur → data URI, évite CSP/COEP) ──────────
$secret_temp = isset($_POST['nouveau_secret']) ? $_POST['nouveau_secret'] : $ga->createSecret();
$issuer      = 'Gestion Automobile TERRACOOP';
$titre_app   = $issuer . ':' . ($matricule ?? 'Admin');
$otpauth_uri = 'otpauth://totp/' . rawurlencode($titre_app)
             . '?secret=' . rawurlencode($secret_temp)
             . '&issuer=' . rawurlencode($issuer);

$qr_src = '';
if ($show_setup) {
    $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($otpauth_uri) . '&size=220x220&ecc=M';
    $img_data = false;

    if (ini_get('allow_url_fopen')) {
        $ctx      = stream_context_create(['http' => ['timeout' => 6]]);
        $img_data = @file_get_contents($qr_url, false, $ctx);
    }
    if ($img_data === false && function_exists('curl_init')) {
        $ch = curl_init($qr_url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => true]);
        $resp     = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) $img_data = $resp;
    }
    if ($img_data !== false) {
        $qr_src = 'data:image/png;base64,' . base64_encode($img_data);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration 2FA — TERRACOOP</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #0d1117 0%, #1a1a2e 55%, #16213e 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 40px 20px;
        }

        /* ── Carte principale ── */
        .admin-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 16px 48px rgba(0,0,0,0.45);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
        }

        .admin-card-header {
            background: linear-gradient(135deg, #1a1a2e, #2d2d54);
            padding: 24px 32px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .admin-logo {
            width: 46px;
            height: 46px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .admin-logo svg { width: 24px; height: 24px; fill: #fff; }

        .header-text-wrap { flex: 1; }

        .header-brand { color: #fff; font-size: 1.1rem; font-weight: 800; letter-spacing: 0.06em; }
        .header-sub   { color: rgba(255,255,255,0.55); font-size: 0.74rem; margin-top: 3px; }

        .header-session-btn {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.6);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            text-decoration: none;
            white-space: nowrap;
            transition: background 0.2s, color 0.2s;
        }
        .header-session-btn:hover { background: rgba(255,255,255,0.15); color: #fff; }

        /* ── Corps ── */
        .admin-card-body { padding: 24px 28px 28px; }

        /* ── Horloge ── */
        .clock-box {
            background: #f0f4ff;
            border: 1px solid #d6def5;
            border-radius: 8px;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            font-size: 0.82rem;
            color: #4a5568;
        }
        .clock-left { display: flex; align-items: center; gap: 8px; }
        .clock-dot  { width: 8px; height: 8px; background: #28a745; border-radius: 50%; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:0.3; } }
        .clock-time { font-size: 1.05rem; font-weight: 700; color: #1a1a2e; font-variant-numeric: tabular-nums; letter-spacing: 0.04em; }
        .clock-tz   { font-size: 0.73rem; color: #718096; }

        /* ── Messages ── */
        .msg {
            border-radius: 8px;
            padding: 11px 14px;
            font-size: 0.875rem;
            margin-bottom: 18px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            line-height: 1.5;
        }
        .msg-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .msg svg     { flex-shrink: 0; margin-top: 1px; }

        /* ── Section label ── */
        .section-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #a0aec0;
            margin-bottom: 10px;
        }

        /* ── Champs ── */
        .field-group       { margin-bottom: 14px; }
        .field-group label { display: block; font-size: 0.84rem; font-weight: 600; color: #495057; margin-bottom: 6px; }
        .field-group input[type="text"],
        .field-group input[type="password"],
        .field-group input[type="number"] {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #dee2e6;
            border-radius: 7px;
            font-size: 0.95rem;
            font-family: inherit;
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .field-group input:focus {
            border-color: #1a1a2e;
            box-shadow: 0 0 0 3px rgba(26,26,46,0.08);
            outline: none;
        }

        /* ── Boutons ── */
        .btn {
            width: 100%;
            padding: 10px 14px;
            border: none;
            border-radius: 7px;
            font-size: 0.93rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
        }
        .btn:active { transform: scale(0.99); }
        .btn-search { background: #1a1a2e; color: #fff; margin-top: 4px; }
        .btn-search:hover { background: #2d2d54; }
        .btn-unlock { background: #4a5568; color: #fff; margin-top: 4px; }
        .btn-unlock:hover { background: #2d3748; }
        .btn-save   { background: #28a745; color: #fff; margin-top: 4px; }
        .btn-save:hover { background: #218838; }
        .btn-revoke { background: #dc3545; color: #fff; margin-top: 4px; }
        .btn-revoke:hover { background: #b02a37; }

        /* ── Info utilisateur ── */
        .user-box {
            background: #ebf8ff;
            border: 1px solid #bee3f8;
            border-left: 4px solid #3182ce;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .user-avatar {
            width: 38px; height: 38px;
            background: linear-gradient(135deg, #1a1a2e, #3182ce);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 700; font-size: 1rem;
            flex-shrink: 0;
        }
        .user-name   { font-weight: 700; color: #1a365d; font-size: 0.95rem; }
        .user-status { font-size: 0.8rem; color: #4a5568; margin-top: 3px; }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 99px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .badge-ok   { background: #c6f6d5; color: #276749; }
        .badge-warn { background: #feebc8; color: #7b341e; }

        .divider { border: none; border-top: 1px solid #edf2f7; margin: 18px 0; }

        /* ── Zone de danger ── */
        .danger-zone {
            border: 1.5px solid #f5c6cb;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 6px;
        }
        .danger-zone-header {
            background: #fff5f5;
            border-bottom: 1px solid #f5c6cb;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }
        .danger-zone-header svg { flex-shrink: 0; }
        .danger-zone-title { font-size: 0.82rem; font-weight: 700; color: #9b2c2c; flex: 1; letter-spacing: 0.03em; }
        .danger-zone-chevron { color: #fc8181; font-size: 0.8rem; transition: transform 0.2s; }
        .danger-zone-header.open .danger-zone-chevron { transform: rotate(180deg); }
        .danger-zone-body {
            display: none;
            padding: 16px;
            background: #fffafa;
        }
        .danger-zone-body.open { display: block; }
        .danger-notice {
            font-size: 0.82rem;
            color: #742a2a;
            background: #fff5f5;
            border: 1px solid #feb2b2;
            border-radius: 6px;
            padding: 10px 12px;
            margin-bottom: 14px;
            line-height: 1.5;
        }

        /* ── QR Code ── */
        .qr-wrap {
            background: #f7fafc;
            border: 1px dashed #cbd5e0;
            border-radius: 10px;
            padding: 20px 16px;
            text-align: center;
            margin-bottom: 16px;
        }
        .qr-wrap h3 { font-size: 0.88rem; font-weight: 700; color: #2d3748; margin-bottom: 14px; }
        .qr-wrap img {
            border-radius: 8px;
            border: 4px solid #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,0.12);
        }
        .qr-secret {
            margin-top: 12px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            font-family: 'Courier New', monospace;
            font-size: 0.82rem;
            color: #2d3748;
            letter-spacing: 0.08em;
            word-break: break-all;
        }
        .qr-secret small { display: block; font-family: inherit; font-size: 0.7rem; color: #a0aec0; letter-spacing: 0; margin-bottom: 3px; }

        /* ── Pied ── */
        .page-footer {
            margin-top: 18px;
            font-size: 0.75rem;
            color: rgba(255,255,255,0.3);
            text-align: center;
        }
    </style>
</head>
<body>

<div class="admin-card">

    <!-- En-tête -->
    <div class="admin-card-header">
        <div class="admin-logo">
            <svg viewBox="0 0 24 24">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4.18l6 2.67V11c0 3.82-2.56 7.4-6 8.54-3.44-1.14-6-4.72-6-8.54V7.85l6-2.67z"/>
            </svg>
        </div>
        <div class="header-text-wrap">
            <div class="header-brand">TERRACOOP</div>
            <div class="header-sub">Gestionnaire 2FA — Accès administrateur</div>
        </div>
        <a href="admin_2fa.php?logout_admin=1" class="header-session-btn" title="Fermer la session admin">
            Quitter
        </a>
    </div>

    <!-- Corps -->
    <div class="admin-card-body">

        <!-- Horloge serveur -->
        <div class="clock-box">
            <div class="clock-left">
                <div class="clock-dot"></div>
                <span>Heure serveur</span>
            </div>
            <div>
                <div class="clock-time" id="srv-clock"><?php echo date('H:i:s'); ?></div>
                <div class="clock-tz"><?php echo date_default_timezone_get(); ?></div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($msg): ?>
            <div class="msg msg-<?php echo $msg_type; ?>">
                <?php if ($msg_type === 'success'): ?>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/></svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                <?php endif; ?>
                <span><?php echo $msg; ?></span>
            </div>
        <?php endif; ?>

        <!-- Recherche employé -->
        <div class="section-label">Rechercher un employé</div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <div class="field-group">
                <label for="matricule">Matricule</label>
                <input type="text" id="matricule" name="matricule"
                       value="<?php echo htmlspecialchars($matricule ?? ''); ?>"
                       required placeholder="Ex : TC001" autofocus>
            </div>
            <button type="submit" name="search_user" class="btn btn-search">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16a6.47 6.47 0 0 0 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                Rechercher
            </button>
        </form>

        <?php if ($user_data): ?>
            <div class="divider"></div>

            <!-- Info utilisateur -->
            <div class="user-box">
                <div class="user-avatar"><?php echo strtoupper(substr($user_data['prenom'], 0, 1)); ?></div>
                <div>
                    <div class="user-name"><?php echo htmlspecialchars($user_data['prenom'] . ' ' . $user_data['nom']); ?></div>
                    <div class="user-status">
                        Matricule : <?php echo htmlspecialchars($user_data['matricule']); ?> &nbsp;·&nbsp;
                        2FA :
                        <?php if ($user_data['two_fa_secret']): ?>
                            <span class="badge badge-ok">Activé</span>
                        <?php else: ?>
                            <span class="badge badge-warn">Non configuré</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (!$show_setup): ?>
                <!-- Formulaire déverrouillage -->
                <div class="section-label">Autoriser la modification</div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="matricule" value="<?php echo htmlspecialchars($user_data['matricule']); ?>">
                    <button type="submit" name="unlock_mod" class="btn btn-unlock">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                        Déverrouiller
                    </button>
                </form>

            <?php else: ?>
                <!-- QR Code + Vérification -->
                <div class="section-label">Configurer le nouveau 2FA</div>

                <div class="qr-wrap">
                    <h3>Scanner avec votre application d'authentification</h3>
                    <?php if ($qr_src): ?>
                        <img src="<?php echo $qr_src; ?>" width="220" height="220" alt="QR Code 2FA">
                    <?php else: ?>
                        <p style="color:#721c24; font-size:0.85rem; background:#f8d7da; padding:10px; border-radius:6px;">
                            Impossible de générer le QR code. Utilisez le code secret ci-dessous pour l'ajouter manuellement.
                        </p>
                    <?php endif; ?>
                    <div class="qr-secret">
                        <small>Code secret (saisie manuelle)</small>
                        <?php echo htmlspecialchars($secret_temp); ?>
                    </div>
                </div>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="matricule" value="<?php echo htmlspecialchars($user_data['matricule']); ?>">
                    <input type="hidden" name="nouveau_secret" value="<?php echo htmlspecialchars($secret_temp); ?>">
                    <div class="field-group">
                        <label for="otp_to_check">Code de vérification (6 chiffres)</label>
                        <input type="number" id="otp_to_check" name="otp_to_check"
                               required placeholder="000000" maxlength="6"
                               style="letter-spacing:0.3em; font-size:1.1rem; text-align:center;"
                               autofocus>
                    </div>
                    <button type="submit" name="verify_and_update" class="btn btn-save">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
                        Valider et enregistrer
                    </button>
                </form>
            <?php endif; ?>

        <?php if (!$show_setup && !empty($user_data['two_fa_secret'])): ?>
            <div class="divider"></div>

            <!-- Zone de révocation -->
            <div class="danger-zone">
                <div class="danger-zone-header" id="dangerToggle" onclick="toggleDanger()">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="#c53030"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                    <span class="danger-zone-title">Révoquer le 2FA</span>
                    <span class="danger-zone-chevron">▼</span>
                </div>
                <div class="danger-zone-body" id="dangerBody">
                    <div class="danger-notice">
                        ⚠ Cette action supprime le 2FA de <strong><?php echo htmlspecialchars($user_data['prenom'] . ' ' . $user_data['nom']); ?></strong>.<br>
                        L'employé ne pourra plus se connecter jusqu'à ce qu'un nouveau 2FA soit configuré.
                    </div>
                    <form method="post" onsubmit="return confirm('Révoquer définitivement le 2FA de <?php echo htmlspecialchars(addslashes($user_data['prenom'].' '.$user_data['nom']), ENT_QUOTES); ?> ?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="matricule" value="<?php echo htmlspecialchars($user_data['matricule']); ?>">
                        <button type="submit" name="revoke_2fa" class="btn btn-revoke">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                            Révoquer définitivement
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php endif; ?>

    </div><!-- /card-body -->
</div><!-- /card -->

<p class="page-footer">Accès restreint — TERRACOOP &copy; <?php echo date('Y'); ?></p>

<script>
function toggleDanger() {
    const header = document.getElementById('dangerToggle');
    const body   = document.getElementById('dangerBody');
    if (!header || !body) return;
    const isOpen = body.classList.toggle('open');
    header.classList.toggle('open', isOpen);
}

(function () {
    const el = document.getElementById('srv-clock');
    if (!el) return;
    const parts = el.textContent.split(':');
    let [h, m, s] = parts.map(Number);
    setInterval(() => {
        if (++s >= 60) { s = 0; if (++m >= 60) { m = 0; if (++h >= 24) h = 0; } }
        el.textContent = [h, m, s].map(n => String(n).padStart(2, '0')).join(':');
    }, 1000);
})();
</script>

</body>
</html>
