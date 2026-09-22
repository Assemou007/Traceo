<?php
/**
 * Mot de passe oublié — réinitialisation via code à 6 chiffres envoyé par SMS
 * (ou fichier de log en mode développement, voir backend/config/notifications.php).
 */

require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/sms/SmsGateway.php';

$erreur = null;
$succes = null;
$etape = 1; // 1 = demande de code, 2 = saisie du code + nouveau mot de passe

$identifiant = trim($_GET['identifiant'] ?? ($_POST['identifiant'] ?? ''));

// Limite de demandes : 3 par heure par session
$limite = $_SESSION['reset_limite'] ?? ['demandes' => 0, 'heure' => time()];
if (time() - $limite['heure'] > 3600) $limite = ['demandes' => 0, 'heure' => time()];

$pdo = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valide()) {
    $erreur = 'Session de sécurité expirée. Rechargez la page et réessayez.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'demander') {
    if ($limite['demandes'] >= 3) {
        $erreur = 'Trop de demandes. Réessayez dans une heure.';
    } else {
        $limite['demandes']++;
        $_SESSION['reset_limite'] = $limite;

        $stmt = $pdo->prepare("SELECT id, telephone, prenom, nom FROM utilisateur WHERE identifiant = ? AND statut = 'actif'");
        $stmt->execute([$identifiant]);
        $users = $stmt->fetchAll();
        // S'il y a un seul utilisateur ou si plusieurs ont des numéros différents, on prend le premier qui a un téléphone
        foreach ($users as $candidate) {
            if (!empty($candidate['telephone'])) {
                $user = $candidate;
                break;
            }
        }

        if ($user && !empty($user['telephone'])) {
            // Invalide les anciens codes non utilisés
            $pdo->prepare("UPDATE code_reset SET utilise = 1 WHERE utilisateur_id = ?")->execute([$user['id']]);

            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO code_reset (utilisateur_id, code_hash, expire_le) VALUES (?, ?, ?)")
                ->execute([$user['id'], password_hash($code, PASSWORD_DEFAULT), date('Y-m-d H:i:s', time() + 15 * 60)]);

            $sms = new SmsGateway();
            $sms->envoyer($user['telephone'], "Traceo — Votre code de réinitialisation : $code (valable 15 min).", 'sms');

            $succes = 'Si un compte actif avec numéro de téléphone correspond, un code à 6 chiffres a été envoyé par SMS.';
            $etape = 2;
        } else {
            // Message générique : ne jamais révéler si l'identifiant existe
            $succes = 'Si un compte actif avec numéro de téléphone correspond, un code à 6 chiffres a été envoyé par SMS.';
            $etape = 2;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reinitialiser') {
    $code = trim($_POST['code'] ?? '');
    $nouveau = $_POST['nouveau_mot_de_passe'] ?? '';
    $confirmation = $_POST['confirmation'] ?? '';

    // Anti brute-force sur la saisie du code OTP
    $otp_bruteforce = $_SESSION['otp_echecs'] ?? 0;

    if ($otp_bruteforce >= 5) {
        $erreur = 'Nombre maximal d\'essais dépassé. Veuillez faire une nouvelle demande de code.';
        $etape = 1;
    } elseif (!preg_match('/^\d{6}$/', $code)) {
        $erreur = 'Le code doit contenir exactement 6 chiffres.';
        $etape = 2;
    } elseif (strlen($nouveau) < 8) {
        $erreur = 'Le mot de passe doit faire au moins 8 caractères.';
        $etape = 2;
    } elseif ($nouveau !== $confirmation) {
        $erreur = 'Les deux mots de passe ne correspondent pas.';
        $etape = 2;
    } else {
        $stmt = $pdo->prepare("SELECT id FROM utilisateur WHERE identifiant = ? AND statut = 'actif'");
        $stmt->execute([$identifiant]);
        $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $valide = false;
        $matched_user_id = null;
        $matched_code_id = null;

        if (!empty($users)) {
            $inClause = implode(',', array_fill(0, count($users), '?'));
            $stmtCode = $pdo->prepare("SELECT id, utilisateur_id, code_hash FROM code_reset WHERE utilisateur_id IN ($inClause) AND utilise = 0 AND expire_le > NOW() ORDER BY id DESC");
            $stmtCode->execute($users);
            $codes = $stmtCode->fetchAll();

            foreach ($codes as $row) {
                if (password_verify($code, $row['code_hash'])) {
                    $valide = true;
                    $matched_user_id = (int)$row['utilisateur_id'];
                    $matched_code_id = (int)$row['id'];
                    break;
                }
            }
        }

        if (!$valide) {
            $_SESSION['otp_echecs'] = $otp_bruteforce + 1;
            $restants = 5 - $_SESSION['otp_echecs'];
            $erreur = 'Code invalide ou expiré.' . ($restants > 0 ? " ($restants tentative(s) restante(s))" : '');
            $etape = 2;
        } else {
            unset($_SESSION['otp_echecs']);
            $pdo->prepare("UPDATE code_reset SET utilise = 1 WHERE id = ?")->execute([$matched_code_id]);
            $pdo->prepare("UPDATE utilisateur SET mot_de_passe_hash = ? WHERE id = ?")
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $matched_user_id]);
            unset($_SESSION['reset_limite']);
            header('Location: login.php?reset=1');
            exit;
        }
    }
}

$titre = 'Mot de passe oublié';
?><!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mot de passe oublié — Traceo</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='28' font-size='28'>📘</text></svg>">
    <style>
        .reset-page { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px 20px; background:var(--bg); }
        .reset-card { width:100%; max-width:420px; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:32px; box-shadow:0 4px 24px rgba(0,0,0,0.06); }
        .reset-card .logo { font-size:1.3rem; font-weight:800; color:var(--text); margin-bottom:24px; display:flex; align-items:center; gap:10px; }
        .reset-card .logo span { width:34px; height:34px; background:linear-gradient(135deg,var(--accent),var(--accent-hover)); color:#0B1324; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1rem; }
        .reset-card h1 { font-size:1.35rem; font-weight:800; margin-bottom:4px; }
        .reset-card > p { color:var(--text-muted); font-size:0.88rem; margin-bottom:22px; line-height:1.5; }
        .reset-card .form-group { margin-bottom:16px; }
        .reset-card .form-group label { font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:6px; display:block; }
        .reset-card .form-group input { width:100%; padding:11px 14px; border:1.5px solid var(--border); border-radius:var(--radius-sm); background:var(--bg); color:var(--text); font-family:var(--font); font-size:0.95rem; }
        .reset-card .form-group input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px var(--accent-light); }
        .reset-card .btn { width:100%; justify-content:center; padding:12px; }
        .reset-card .back-link { text-align:center; margin-top:18px; font-size:0.85rem; color:var(--text-muted); }
        .reset-card .back-link a { font-weight:600; }
    </style>
</head>
<body>
    <div class="reset-page">
        <div class="reset-card">
            <div class="logo"><span>T</span> Traceo</div>
            <?php if ($etape === 2): ?>
                <h1>Réinitialiser le mot de passe</h1>
                <p>Entrez le code à 6 chiffres reçu par SMS, puis votre nouveau mot de passe.</p>
            <?php else: ?>
                <h1>Mot de passe oublié</h1>
                <p>Entrez votre identifiant. Un code de réinitialisation vous sera envoyé par SMS sur le numéro enregistré.</p>
            <?php endif; ?>

            <?php if ($erreur): ?>
                <div class="alert alert-error"><?= htmlspecialchars($erreur) ?></div>
            <?php endif; ?>
            <?php if ($succes): ?>
                <div class="alert alert-success"><?= htmlspecialchars($succes) ?></div>
            <?php endif; ?>

            <?php if ($etape === 1): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="demander">
                <div class="form-group">
                    <label for="identifiant">Identifiant</label>
                    <input type="text" id="identifiant" name="identifiant" value="<?= htmlspecialchars($identifiant) ?>" required autofocus autocomplete="username">
                </div>
                <button type="submit" class="btn btn-accent"><i class="fas fa-paper-plane"></i> Envoyer le code</button>
            </form>
            <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reinitialiser">
                <input type="hidden" name="identifiant" value="<?= htmlspecialchars($identifiant) ?>">
                <div class="form-group">
                    <label for="code">Code à 6 chiffres</label>
                    <input type="text" id="code" name="code" pattern="[0-9]{6}" maxlength="6" inputmode="numeric" required autofocus placeholder="000000">
                </div>
                <div class="form-group">
                    <label for="nouveau">Nouveau mot de passe</label>
                    <input type="password" id="nouveau" name="nouveau_mot_de_passe" required minlength="8" autocomplete="new-password" placeholder="Min. 8 caractères">
                </div>
                <div class="form-group">
                    <label for="confirmation">Confirmer le mot de passe</label>
                    <input type="password" id="confirmation" name="confirmation" required minlength="8" autocomplete="new-password" placeholder="Retaper le mot de passe">
                </div>
                <button type="submit" class="btn btn-accent"><i class="fas fa-key"></i> Réinitialiser</button>
            </form>
            <?php endif; ?>

            <div class="back-link">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Retour à la connexion</a>
            </div>
        </div>
    </div>
</body>
</html>
