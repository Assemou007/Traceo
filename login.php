<?php
session_start();
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/helpers/routes.php';

if (!empty($_SESSION['utilisateur_id'])) {
    header('Location: ' . dashboard_url_pour_role($_SESSION['role'] ?? ''));
    exit;
}

$erreur = null;
$succes_reset = isset($_GET['reset']) ? 'Mot de passe réinitialisé. Connectez-vous.' : null;
$role_hint = $_GET['role'] ?? '';
$allowed_roles = ['censeur', 'surveillant', 'prof', 'responsable_classe'];
$role_hint = in_array($role_hint, $allowed_roles, true) ? $role_hint : '';

$role_labels = [
    'censeur' => ['Censeur', 'Administration & gestion complète', true],
    'prof' => ['Professeur', 'Cahier de texte & appel', false],
    'responsable_classe' => ['Responsable', 'Validation & suivi', false],
    'surveillant' => ['Surveillant', 'Absences & punitions', false],
];
$role_info = $role_labels[$role_hint] ?? ['', '', false];
$can_register = $role_info[2];

// --- Anti brute-force : 5 échecs max, verrouillage 15 min ---
$bruteforce = $_SESSION['login_bruteforce'] ?? ['echecs' => 0, 'verrou_jusque' => 0];
$est_verrouille = $bruteforce['verrou_jusque'] > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valide()) {
        $erreur = 'Session de sécurité expirée. Rechargez la page et réessayez.';
    } elseif ($est_verrouille) {
        $attente = ceil(($bruteforce['verrou_jusque'] - time()) / 60);
        $erreur = "Trop de tentatives échouées. Réessayez dans $attente minute(s).";
    } else {
        $identifiant = trim($_POST['identifiant'] ?? '');
        $mdp = $_POST['mot_de_passe'] ?? '';

        $pdo = get_db();
        $stmt = $pdo->prepare(
            "SELECT u.*, e.nom AS etablissement_nom FROM utilisateur u
             JOIN etablissement e ON e.id = u.etablissement_id
             WHERE u.identifiant = ?"
        );
        $stmt->execute([$identifiant]);
        $candidats = $stmt->fetchAll();

        $user_trouve = null;
        $compte_inactif = false;

        foreach ($candidats as $candidat) {
            if (password_verify($mdp, $candidat['mot_de_passe_hash'])) {
                if ($candidat['statut'] === 'actif') {
                    $user_trouve = $candidat;
                    break;
                } else {
                    $compte_inactif = true;
                }
            }
        }

        if ($user_trouve) {
            unset($_SESSION['login_bruteforce']);
            session_regenerate_id(true); // anti-fixation de session
            $_SESSION['utilisateur_id'] = (int)$user_trouve['id'];
            $_SESSION['etablissement_id'] = (int)$user_trouve['etablissement_id'];
            $_SESSION['role'] = $user_trouve['role'];
            $_SESSION['nom_complet'] = $user_trouve['prenom'] . ' ' . $user_trouve['nom'];
            $_SESSION['etablissement_nom'] = $user_trouve['etablissement_nom'];

            require_once __DIR__ . '/backend/helpers/audit.php';
            consigner_audit($pdo, (int)$user_trouve['etablissement_id'], (int)$user_trouve['id'], 'connexion_reussie', 'Connexion Web réussie');

            header('Location: ' . dashboard_url_pour_role($user_trouve['role']));
            exit;
        } elseif ($compte_inactif) {
            $erreur = 'Compte inactif — faute de destitution vous n\'êtes plus le responsable. Contactez l\'administration.';
        } else {
            $erreur = 'Identifiant ou mot de passe incorrect';
        }

        if ($erreur) {
            $bruteforce['echecs']++;
            if ($bruteforce['echecs'] >= 5) {
                $bruteforce['verrou_jusque'] = time() + 15 * 60;
                $bruteforce['echecs'] = 0;
                $erreur = 'Trop de tentatives échouées. Compte verrouillé 15 minutes.';
            }
            $_SESSION['login_bruteforce'] = $bruteforce;
            usleep(500000); // ralentit le brute-force par force brute
        }
    }
}
?><!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — Traceo</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='28' font-size='28'>📘</text></svg>">
    <style>
        .split-page { display:flex; min-height:100vh; }
        .split-left { flex:1; display:flex; align-items:center; justify-content:center; padding:32px; background:var(--surface); }
        .split-left-inner { width:100%; max-width:400px; }
        .split-left-inner .logo { font-size:1.5rem; font-weight:800; color:var(--text); margin-bottom:32px; display:flex; align-items:center; gap:10px; }
        .split-left-inner .logo span { width:36px; height:36px; background:linear-gradient(135deg,var(--accent),var(--accent-hover)); color:#0B1324; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
        .split-left-inner h1 { font-size:1.6rem; font-weight:800; letter-spacing:-0.03em; margin-bottom:4px; }
        .split-left-inner > p { color:var(--text-muted); font-size:0.9rem; margin-bottom:28px; }
        .split-left-inner .form-group { margin-bottom:18px; }
        .split-left-inner .form-group label { font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:6px; display:block; }
        .split-left-inner .form-group input { width:100%; padding:12px 14px; border:1.5px solid var(--border); border-radius:var(--radius-sm); background:var(--bg); color:var(--text); font-family:var(--font); font-size:0.95rem; transition:all 0.18s; }
        .split-left-inner .form-group input:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px var(--accent-light); background:var(--surface); }
        .split-left-inner .btn { width:100%; justify-content:center; padding:12px; }
        .split-left-inner .auth-footer { text-align:center; margin-top:24px; font-size:0.85rem; color:var(--text-muted); }
        .split-left-inner .auth-footer a { font-weight:600; }

        .split-right { flex:1; display:flex; flex-direction:column; justify-content:center; padding:48px; background:var(--sidebar-bg); position:relative; overflow:hidden; }
        .split-right::before { content:''; position:absolute; top:-40%; right:-20%; width:500px; height:500px; border-radius:50%; background:var(--accent); opacity:0.04; }
        .split-right::after { content:''; position:absolute; bottom:-30%; left:-10%; width:400px; height:400px; border-radius:50%; background:var(--accent); opacity:0.03; }
        .split-right .brand-icon { width:56px; height:56px; background:linear-gradient(135deg,var(--accent),var(--accent-hover)); color:#0B1324; border-radius:16px; display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:800; margin-bottom:24px; box-shadow:0 4px 20px var(--accent-glow); }
        .split-right h2 { color:var(--sidebar-text-active); font-size:1.8rem; font-weight:700; letter-spacing:-0.03em; margin-bottom:12px; line-height:1.25; }
        .split-right .sub { color:var(--sidebar-text); font-size:0.95rem; line-height:1.6; margin-bottom:32px; max-width:420px; }
        .split-right .features { display:flex; flex-direction:column; gap:14px; }
        .split-right .features .feat { display:flex; align-items:center; gap:12px; color:var(--sidebar-text); font-size:0.9rem; }
        .split-right .features .feat i { width:32px; height:32px; border-radius:8px; background:var(--sidebar-hover); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:0.9rem; flex-shrink:0; }

        .theme-abs { position:fixed; top:16px; right:16px; z-index:10; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); color:var(--text-secondary); cursor:pointer; padding:8px; display:flex; }

        @media (max-width:768px) {
            .split-right { display:none; }
            .split-left { padding:24px 20px; }
        }

        [data-theme="dark"] .split-left { background:var(--surface); }
        [data-theme="dark"] .split-left-inner .form-group input { background:var(--bg); }
        [data-theme="dark"] .split-left-inner .form-group input:focus { background:var(--surface); }
        [data-theme="dark"] .split-right { background:#060B14; }
    </style>
</head>
<body>
    <button class="theme-abs" id="themeToggle" aria-label="Changer le thème">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>
    <div class="split-page">
        <div class="split-left">
            <div class="split-left-inner">
                <div class="logo"><span>T</span> Traceo</div>
                <h1>Connexion<?= $role_info[0] ? ' — ' . $role_info[0] : '' ?></h1>
                <p><?= $role_info[1] ?: 'Cahier de texte numérique & gestion de la vie scolaire' ?></p>
                <?php if ($erreur): ?>
                    <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($erreur) ?></div>
                <?php endif; ?>
                <?php if ($succes_reset): ?>
                    <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($succes_reset) ?></div>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="identifiant">Identifiant</label>
                        <input type="text" id="identifiant" name="identifiant" required autofocus autocomplete="username" placeholder="Votre identifiant">
                    </div>
                    <div class="form-group">
                        <label for="mot_de_passe">Mot de passe</label>
                        <input type="password" id="mot_de_passe" name="mot_de_passe" required autocomplete="current-password" placeholder="••••••••">
                    </div>
                    <button type="submit" class="btn btn-accent"><i class="fas fa-arrow-right-to-bracket"></i> Se connecter</button>
                </form>
                <div class="auth-footer">
                    <a href="oubli.php" style="font-size:0.8rem;"><i class="fas fa-key"></i> Mot de passe oublié ?</a>
                </div>
                <div class="auth-footer">
                    <?php if ($can_register): ?>
                        Nouvel établissement ? <a href="inscription.php">Créer un compte</a>
                    <?php else: ?>
                        Connectez-vous avec les identifiants fournis par votre établissement.
                    <?php endif; ?>
                </div>
                <div style="text-align:center;margin-top:12px;font-size:0.8rem;">
                    <a href="index.php" style="color:var(--text-muted);"><i class="fas fa-arrow-left"></i> Choisir un autre profil</a>
                </div>
            </div>
        </div>
        <div class="split-right">
            <div class="brand-icon">T</div>
            <h2>Traceo</h2>
            <p class="sub">La plateforme qui simplifie la gestion quotidienne de votre établissement scolaire.</p>
            <div class="features">
                <div class="feat"><i class="fas fa-book-open"></i> Cahier de texte numérique</div>
                <div class="feat"><i class="fas fa-clipboard-user"></i> Appel & suivi des absences</div>
                <div class="feat"><i class="fas fa-check-double"></i> Validation par les délégués</div>
                <div class="feat"><i class="fas fa-gavel"></i> Gestion des punitions</div>
                <div class="feat"><i class="fas fa-chart-line"></i> Rapports & statistiques</div>
            </div>
        </div>
    </div>
<script>
(function(){
    const root = document.documentElement;
    const saved = localStorage.getItem('traceo-theme');
    const prefers = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const theme = saved || (prefers ? 'dark' : 'light');
    root.setAttribute('data-theme', theme);
    document.getElementById('themeToggle')?.addEventListener('click', function(){
        const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        localStorage.setItem('traceo-theme', next);
    });
})();
</script>
</body>
</html>
