<?php
/**
 * Layout : en-tête HTML + topbar + sidebar.
 * À inclure en début de chaque page protégée.
 *
 * Usage :
 *   $titre = "Titre de la page";
 *   require_once __DIR__ . '/inc/header.php';
 */
$titre = $titre ?? 'Traceo';
$current_page = basename($_SERVER['SCRIPT_NAME']);
$root_rel = dirname($_SERVER['SCRIPT_NAME']) === '/' || dirname($_SERVER['SCRIPT_NAME']) === '' || dirname($_SERVER['SCRIPT_NAME']) === '/Traceo' ? '' : '../';
?>
<!DOCTYPE html>
<html lang="fr" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        (function() {
            const saved = localStorage.getItem('traceo-theme');
            const theme = saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <title><?= htmlspecialchars($titre) ?> — Traceo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $root_rel ?>assets/css/app.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='28' font-size='28'>📘</text></svg>">
</head>
<body>
<div class="app">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="sidebar-logo">T</span>
            <span class="sidebar-name">Traceo</span>
        </div>
        <nav class="sidebar-nav">
            <?php if ($_SESSION['role'] === 'censeur' || $_SESSION['role'] === 'surveillant'): ?>
            <a href="<?= $root_rel ?>dashboard.php" class="sidebar-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                Tableau de bord
            </a>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'prof'): ?>
            <a href="<?= $root_rel ?>prof/dashboard.php" class="sidebar-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                Tableau de bord
            </a>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'responsable_classe'): ?>
            <a href="<?= $root_rel ?>responsable/dashboard.php" class="sidebar-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                Tableau de bord
            </a>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'censeur'): ?>
            <div class="sidebar-label">Gestion</div>
            <a href="<?= $root_rel ?>classes.php" class="sidebar-link <?= $current_page === 'classes.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                Classes & élèves
            </a>
            <a href="<?= $root_rel ?>matieres.php" class="sidebar-link <?= $current_page === 'matieres.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                Matières
            </a>
            <a href="<?= $root_rel ?>filieres.php" class="sidebar-link <?= $current_page === 'filieres.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                Filières
            </a>
            <a href="<?= $root_rel ?>affectations.php" class="sidebar-link <?= $current_page === 'affectations.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                Affectations profs
            </a>
            <a href="<?= $root_rel ?>responsables.php" class="sidebar-link <?= $current_page === 'responsables.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Responsables
            </a>
            <a href="<?= $root_rel ?>utilisateurs.php" class="sidebar-link <?= $current_page === 'utilisateurs.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                Comptes
            </a>
            <a href="<?= $root_rel ?>emploi_du_temps.php" class="sidebar-link <?= $current_page === 'emploi_du_temps.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Emploi du temps
            </a>
            <a href="<?= $root_rel ?>import.php" class="sidebar-link <?= $current_page === 'import.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Import
            </a>
            <a href="<?= $root_rel ?>transition.php" class="sidebar-link <?= $current_page === 'transition.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Transition
            </a>
            <div class="sidebar-label">Vie scolaire</div>
            <a href="<?= $root_rel ?>punitions.php" class="sidebar-link <?= $current_page === 'punitions.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Punitions
            </a>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'surveillant'): ?>
            <div class="sidebar-label">Vie scolaire</div>
            <a href="<?= $root_rel ?>surveillant/dashboard.php" class="sidebar-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Punitions
            </a>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'prof'): ?>
            <div class="sidebar-label">Mes séances</div>
            <a href="<?= $root_rel ?>prof/dashboard.php" class="sidebar-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Aujourd'hui
            </a>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'responsable_classe'): ?>
            <div class="sidebar-label">Validations</div>
            <a href="<?= $root_rel ?>responsable/dashboard.php" class="sidebar-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 11 19 13 23 9"/><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                À valider
            </a>
            <?php endif; ?>
            <div class="sidebar-label">Général</div>
            <a href="<?= $root_rel ?>profil.php" class="sidebar-link <?= $current_page === 'profil.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Mon profil
            </a>
            <a href="<?= $root_rel ?>rapports.php" class="sidebar-link <?= $current_page === 'rapports.php' ? 'active' : '' ?>">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Rapports
            </a>
        </nav>
    </aside>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main -->
    <div class="main">
        <!-- Topbar -->
        <header class="topbar">
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="topbar-title"><?= htmlspecialchars($titre) ?></div>
            <div class="topbar-right">
                <span class="topbar-etab"><?= htmlspecialchars($_SESSION['etablissement_nom'] ?? '') ?></span>
                <span class="topbar-user"><?= htmlspecialchars($_SESSION['nom_complet'] ?? '') ?></span>
                <button class="theme-toggle" id="themeToggle" aria-label="Changer le thème">
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                </button>
                <a href="<?= $root_rel ?>logout.php" class="btn-logout" aria-label="Déconnexion">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </a>
            </div>
        </header>

        <!-- Content -->
        <main class="content">
            <?php
            $aff_erreur = $erreur ?? $_SESSION['_flash_erreur'] ?? null;
            $aff_succes = $succes ?? $_SESSION['_flash_succes'] ?? null;
            unset($_SESSION['_flash_erreur'], $_SESSION['_flash_succes']);
            ?>
            <?php if (!empty($aff_erreur)): ?>
                <div class="alert alert-error"><?= htmlspecialchars($aff_erreur) ?></div>
            <?php endif; ?>
            <?php if (!empty($aff_succes)): ?>
                <div class="alert alert-success"><?= htmlspecialchars($aff_succes) ?></div>
            <?php endif; ?>
