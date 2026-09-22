<?php
session_start();
if (!empty($_SESSION['utilisateur_id'])) {
    $routes = [
        'censeur' => 'dashboard.php',
        'surveillant' => 'surveillant/dashboard.php',
        'prof' => 'prof/dashboard.php',
        'responsable_classe' => 'responsable/dashboard.php',
    ];
    header('Location: ' . ($routes[$_SESSION['role']] ?? 'login.php'));
    exit;
}
?><!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Traceo — Cahier de texte numérique</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='28' font-size='28'>📘</text></svg>">
    <style>
        .split-page { display:flex; min-height:100vh; }
        .split-left { flex:1; display:flex; flex-direction:column; justify-content:center; padding:48px; background:var(--sidebar-bg); position:relative; overflow:hidden; }
        .split-left::before { content:''; position:absolute; top:-40%; right:-20%; width:500px; height:500px; border-radius:50%; background:var(--accent); opacity:0.04; }
        .split-left::after { content:''; position:absolute; bottom:-30%; left:-10%; width:400px; height:400px; border-radius:50%; background:var(--accent); opacity:0.03; }
        .split-left .brand-icon { width:56px; height:56px; background:linear-gradient(135deg,var(--accent),var(--accent-hover)); color:#0B1324; border-radius:16px; display:flex; align-items:center; justify-content:center; font-size:1.6rem; font-weight:800; margin-bottom:24px; box-shadow:0 4px 20px var(--accent-glow); }
        .split-left h2 { color:var(--sidebar-text-active); font-size:1.8rem; font-weight:700; letter-spacing:-0.03em; margin-bottom:12px; line-height:1.25; }
        .split-left .sub { color:var(--sidebar-text); font-size:0.95rem; line-height:1.6; margin-bottom:32px; max-width:420px; }
        .split-left .features { display:flex; flex-direction:column; gap:14px; }
        .split-left .features .feat { display:flex; align-items:center; gap:12px; color:var(--sidebar-text); font-size:0.9rem; }
        .split-left .features .feat i { width:32px; height:32px; border-radius:8px; background:var(--sidebar-hover); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:0.9rem; flex-shrink:0; }
        .split-right { flex:1; display:flex; flex-direction:column; justify-content:center; padding:48px; background:var(--surface); }
        .split-right-inner { width:100%; max-width:520px; margin:0 auto; }
        .split-right-inner .logo { font-size:1.5rem; font-weight:800; color:var(--text); margin-bottom:36px; display:flex; align-items:center; gap:10px; }
        .split-right-inner .logo span { width:36px; height:36px; background:linear-gradient(135deg,var(--accent),var(--accent-hover)); color:#0B1324; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
        .role-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:28px; }
        .role-card { display:flex; flex-direction:column; align-items:center; gap:12px; padding:32px 20px; border:1.5px solid var(--border); border-radius:var(--radius-sm); background:var(--bg); cursor:pointer; transition:all 0.2s; text-decoration:none; color:var(--text); text-align:center; }
        .role-card:hover { border-color:var(--accent); background:var(--accent-light); transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,0.06); }
        .role-card .r-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
        .role-card h3 { margin:0; font-size:1rem; font-weight:700; }
        .role-card p { margin:0; font-size:0.78rem; color:var(--text-muted); line-height:1.4; }
        .split-right-inner .auth-footer { text-align:center; margin-top:24px; font-size:0.85rem; color:var(--text-muted); }
        .split-right-inner .auth-footer a { font-weight:600; }
        .theme-abs { position:fixed; top:16px; right:16px; z-index:10; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); color:var(--text-secondary); cursor:pointer; padding:8px; display:flex; }
        @media (max-width:768px) {
            .split-left { display:none; }
            .split-right { padding:24px 20px; }
            .role-grid { grid-template-columns:1fr; }
        }
        [data-theme="dark"] .split-right { background:var(--surface); }
        [data-theme="dark"] .split-right-inner .role-card { background:var(--bg); }
        [data-theme="dark"] .split-left { background:#060B14; }
    </style>
</head>
<body>
    <button class="theme-abs" id="themeToggle" aria-label="Changer le thème">
        <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>
    <div class="split-page">
        <div class="split-left">
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
        <div class="split-right">
            <div class="split-right-inner">
                <div class="logo"><span>T</span> Traceo</div>
                <h2 style="font-size:1.4rem;font-weight:700;margin-bottom:4px;letter-spacing:-0.03em;">Connectez-vous</h2>
                <p style="color:var(--text-muted);font-size:0.9rem;margin-bottom:24px;">Choisissez votre profil pour continuer</p>
                <div class="role-grid">
                    <a href="login.php?role=censeur" class="role-card">
                        <div class="r-icon" style="background:var(--accent-light);color:var(--accent);"><i class="fas fa-user-tie"></i></div>
                        <h3>Censeur</h3>
                        <p>Administration & gestion complète de l'établissement</p>
                    </a>
                    <a href="login.php?role=prof" class="role-card">
                        <div class="r-icon" style="background:#E8F5F0;color:#059669;"><i class="fas fa-chalkboard-user"></i></div>
                        <h3>Professeur</h3>
                        <p>Cahier de texte, appel & suivi des séances</p>
                    </a>
                    <a href="login.php?role=responsable_classe" class="role-card">
                        <div class="r-icon" style="background:#FFF3E0;color:#E65100;"><i class="fas fa-handshake"></i></div>
                        <h3>Responsable</h3>
                        <p>Validation des séances & suivi de la classe</p>
                    </a>
                    <a href="login.php?role=surveillant" class="role-card">
                        <div class="r-icon" style="background:#F3E8FF;color:#7C3AED;"><i class="fas fa-shield-halved"></i></div>
                        <h3>Surveillant</h3>
                        <p>Gestion des absences, retards & punitions</p>
                    </a>
                </div>
                <div class="auth-footer">
                    Nouvel établissement ? <a href="inscription.php">Créer un compte censeur</a>
                </div>
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
