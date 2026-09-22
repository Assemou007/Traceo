<?php
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/_session.php';

$erreur = null;
$succes = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valide()) {
        $erreur = 'Session de sécurité expirée. Rechargez la page et réessayez.';
    } else {
    $pdo = get_db();
    $nom_etab = trim($_POST['nom_etablissement'] ?? '');
    $pays_code = trim($_POST['pays_code'] ?? '');
    $type = $_POST['type'] ?? '';
    $mode_cahier = $_POST['mode_cahier_texte'] ?? 'libre';
    $censeur_nom = trim($_POST['censeur_nom'] ?? '');
    $censeur_prenom = trim($_POST['censeur_prenom'] ?? '');
    $censeur_identifiant = trim($_POST['censeur_identifiant'] ?? '');
    $censeur_mdp = $_POST['censeur_mot_de_passe'] ?? '';

    if (!$nom_etab || !$pays_code || !in_array($type, ['college','lycee'], true)
        || !$censeur_identifiant || strlen($censeur_mdp) < 8) {
        $erreur = 'Merci de remplir tous les champs (mot de passe : 8 caractères minimum).';
    } else {
        $pays = $pdo->prepare("SELECT id FROM pays WHERE code = ?");
        $pays->execute([$pays_code]);
        $pays_id = $pays->fetchColumn();

        if (!$pays_id) {
            $erreur = 'Pays non reconnu.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO etablissement (pays_id, nom, type, mode_cahier_texte) VALUES (?, ?, ?, ?)");
                $stmt->execute([$pays_id, $nom_etab, $type, $mode_cahier]);
                $etablissement_id = $pdo->lastInsertId();

                $annee_debut = (int)date('Y');
                $stmt = $pdo->prepare("INSERT INTO annee_scolaire (etablissement_id, libelle, date_debut, date_fin, statut) VALUES (?, ?, ?, ?, 'en_cours')");
                $stmt->execute([$etablissement_id, "$annee_debut-" . ($annee_debut + 1), "$annee_debut-09-01", ($annee_debut + 1) . "-07-31"]);

                $hash = password_hash($censeur_mdp, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO utilisateur (etablissement_id, role, nom, prenom, identifiant, mot_de_passe_hash) VALUES (?, 'censeur', ?, ?, ?, ?)");
                $stmt->execute([$etablissement_id, $censeur_nom, $censeur_prenom, $censeur_identifiant, $hash]);

                $pdo->commit();
                $succes = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                $erreur = str_contains($e->getMessage(), 'Duplicate') ? 'Cet identifiant est déjà utilisé.' : 'Erreur lors de la création du compte.';
            }
        }
    }
    }
}

$pays_list = get_db()->query("SELECT code, nom FROM pays ORDER BY nom")->fetchAll();
?><!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inscription — Traceo</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='28' font-size='28'>📘</text></svg>">
    <style>
        .split-page { display:flex; min-height:100vh; }
        .split-left { flex:1; display:flex; align-items:center; justify-content:center; padding:32px; background:var(--surface); }
        .split-left-inner { width:100%; max-width:480px; }
        .split-left-inner .logo { font-size:1.5rem; font-weight:800; color:var(--text); margin-bottom:28px; display:flex; align-items:center; gap:10px; }
        .split-left-inner .logo span { width:36px; height:36px; background:linear-gradient(135deg,var(--accent),var(--accent-hover)); color:#0B1324; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:1.1rem; }
        .split-left-inner h1 { font-size:1.6rem; font-weight:800; letter-spacing:-0.03em; margin-bottom:4px; }
        .split-left-inner > p { color:var(--text-muted); font-size:0.9rem; margin-bottom:24px; line-height:1.5; }
        .split-left-inner .form-group { margin-bottom:14px; }
        .split-left-inner .form-group label { font-size:0.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin-bottom:5px; display:block; }
        .split-left-inner .form-group input,
        .split-left-inner .form-group select { width:100%; padding:11px 14px; border:1.5px solid var(--border); border-radius:var(--radius-sm); background:var(--bg); color:var(--text); font-family:var(--font); font-size:0.9rem; transition:all 0.18s; }
        .split-left-inner .form-group input:focus,
        .split-left-inner .form-group select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 4px var(--accent-light); background:var(--surface); }
        .split-left-inner hr { border:none; border-top:1px solid var(--border); margin:22px 0; }
        .split-left-inner .section-title { font-weight:700; font-size:0.85rem; color:var(--text); margin-bottom:14px; display:flex; align-items:center; gap:8px; }
        .split-left-inner .section-title i { color:var(--accent); }
        .split-left-inner .btn { width:100%; justify-content:center; padding:12px; margin-top:4px; }
        .split-left-inner .auth-footer { text-align:center; margin-top:20px; font-size:0.85rem; color:var(--text-muted); }
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
        [data-theme="dark"] .split-left-inner .form-group input,
        [data-theme="dark"] .split-left-inner .form-group select { background:var(--bg); }
        [data-theme="dark"] .split-left-inner .form-group input:focus,
        [data-theme="dark"] .split-left-inner .form-group select:focus { background:var(--surface); }
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
                <?php if ($succes): ?>
                    <div class="logo"><span>T</span> Traceo</div>
                    <h1>Établissement créé</h1>
                    <p>Votre établissement et votre compte censeur ont été créés avec succès.</p>
                    <a class="btn btn-accent" href="login.php" style="width:auto;display:inline-flex;"><i class="fas fa-arrow-right-to-bracket"></i> Se connecter</a>
                <?php else: ?>
                    <div class="logo"><span>T</span> Traceo</div>
                    <h1>Créer un établissement</h1>
                    <p>Inscrivez votre établissement et créez votre compte administrateur (censeur).</p>
                    <?php if ($erreur): ?>
                        <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($erreur) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <div class="section-title"><i class="fas fa-school"></i> Établissement</div>
                        <div class="form-group">
                            <label>Nom de l'établissement</label>
                            <input type="text" name="nom_etablissement" required placeholder="Ex: Lycée Moderne ...">
                        </div>
                        <div class="form-row" style="display:flex;gap:12px;">
                            <div class="form-group" style="flex:1;">
                                <label>Pays</label>
                                <select name="pays_code" required>
                                    <option value="">— Choisir —</option>
                                    <?php foreach ($pays_list as $p): ?>
                                        <option value="<?= htmlspecialchars($p['code']) ?>"><?= htmlspecialchars($p['nom']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Type</label>
                                <select name="type" required>
                                    <option value="college">Collège</option>
                                    <option value="lycee">Lycée</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Mode cahier de texte</label>
                            <select name="mode_cahier_texte" required>
                                <option value="libre">Texte libre</option>
                                <option value="programme_importe">Programme importé</option>
                            </select>
                        </div>
                        <hr>
                        <div class="section-title"><i class="fas fa-user-shield"></i> Compte censeur (administrateur)</div>
                        <div class="form-row" style="display:flex;gap:12px;">
                            <div class="form-group" style="flex:1;"><label>Prénom</label><input type="text" name="censeur_prenom" required placeholder="Jean"></div>
                            <div class="form-group" style="flex:1;"><label>Nom</label><input type="text" name="censeur_nom" required placeholder="Dupont"></div>
                        </div>
                        <div class="form-group">
                            <label>Identifiant</label>
                            <input type="text" name="censeur_identifiant" required placeholder="censeur.lycee">
                        </div>
                        <div class="form-group">
                            <label>Mot de passe (8 caractères minimum)</label>
                            <input type="password" name="censeur_mot_de_passe" minlength="8" required placeholder="••••••••">
                        </div>
                        <button type="submit" class="btn btn-accent"><i class="fas fa-check-circle"></i> Créer l'établissement</button>
                    </form>
                    <div class="auth-footer">
                        Déjà un compte ? <a href="login.php">Se connecter</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="split-right">
            <div class="brand-icon">T</div>
            <h2>Traceo</h2>
            <p class="sub">Rejoignez les établissements qui modernisent leur gestion scolaire.</p>
            <div class="features">
                <div class="feat"><i class="fas fa-cloud-upload-alt"></i> Configuration en quelques minutes</div>
                <div class="feat"><i class="fas fa-users"></i> Multi-utilisateurs : censeur, surveillant, profs</div>
                <div class="feat"><i class="fas fa-file-import"></i> Import de données par fichier Excel</div>
                <div class="feat"><i class="fas fa-message"></i> Notifications SMS parents</div>
                <div class="feat"><i class="fas fa-lock"></i> Données sécurisées, hébergement local</div>
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
