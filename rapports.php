<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/helpers/pdf.php';
require_web_login(['censeur', 'surveillant']);

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];

// Validation stricte des dates de période (évite des valeurs aberrantes en requête)
$periode_debut = $_GET['debut'] ?? date('Y-m-01');
$periode_fin = $_GET['fin'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periode_debut)) $periode_debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periode_fin)) $periode_fin = date('Y-m-d');
if ($periode_fin < $periode_debut) $periode_fin = $periode_debut;

// --- Données du rapport ---
$present_par_classe = $pdo->prepare("SELECT c.nom_affichage, COUNT(p.id) AS total, SUM(CASE WHEN p.statut = 'absent' THEN 1 ELSE 0 END) AS absences, SUM(CASE WHEN p.statut = 'retard' THEN 1 ELSE 0 END) AS retards FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id JOIN classe c ON c.id = cr.classe_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ? GROUP BY c.id, c.nom_affichage ORDER BY c.nom_affichage");
$present_par_classe->execute([$etab_id, $periode_debut, $periode_fin]);
$present_par_classe = $present_par_classe->fetchAll();

$top_absents = $pdo->prepare("SELECT e.nom, e.prenom, c.nom_affichage, COUNT(p.id) AS nb FROM presence p JOIN eleve e ON e.id = p.eleve_id JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id JOIN classe c ON c.id = e.classe_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ? AND p.statut = 'absent' GROUP BY e.id HAVING nb >= 3 ORDER BY nb DESC LIMIT 20");
$top_absents->execute([$etab_id, $periode_debut, $periode_fin]);
$top_absents = $top_absents->fetchAll();

$cahier_stats = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN statut_validation = 'validee' THEN 1 ELSE 0 END) AS validees, SUM(CASE WHEN statut_validation = 'en_attente' THEN 1 ELSE 0 END) AS en_attente FROM seance s JOIN creneau cr ON cr.id = s.creneau_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ?");
$cahier_stats->execute([$etab_id, $periode_debut, $periode_fin]);
$cahier_stats = $cahier_stats->fetch();

$punitions_periode = $pdo->prepare("SELECT COUNT(*) FROM punition p JOIN eleve e ON e.id = p.eleve_id WHERE e.etablissement_id = ? AND p.date_attribution BETWEEN ? AND ?");
$punitions_periode->execute([$etab_id, $periode_debut, $periode_fin]);
$punitions_periode = $punitions_periode->fetchColumn();

$punitions_par_classe = $pdo->prepare("SELECT c.nom_affichage, COUNT(p.id) AS nb, SUM(CASE WHEN p.statut = 'a_faire' THEN 1 ELSE 0 END) AS a_faire FROM punition p JOIN eleve e ON e.id = p.eleve_id JOIN classe c ON c.id = e.classe_id WHERE e.etablissement_id = ? AND p.date_attribution BETWEEN ? AND ? GROUP BY c.id, c.nom_affichage ORDER BY nb DESC");
$punitions_par_classe->execute([$etab_id, $periode_debut, $periode_fin]);
$punitions_par_classe = $punitions_par_classe->fetchAll();

$cahier_par_classe = $pdo->prepare("SELECT c.nom_affichage, COUNT(s.id) AS total, SUM(CASE WHEN s.statut_validation = 'validee' THEN 1 ELSE 0 END) AS validees, SUM(CASE WHEN s.statut_validation = 'en_attente' THEN 1 ELSE 0 END) AS en_attente FROM seance s JOIN creneau cr ON cr.id = s.creneau_id JOIN classe c ON c.id = cr.classe_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ? GROUP BY c.id, c.nom_affichage ORDER BY c.nom_affichage");
$cahier_par_classe->execute([$etab_id, $periode_debut, $periode_fin]);
$cahier_par_classe = $cahier_par_classe->fetchAll();

// --- Export PDF ---
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $nomEcole = $_SESSION['etablissement_nom'] ?? 'Traceo';
    $pdf = new MiniPdf();
    $pdf->setTitre('Rapport d\'activité');
    $pdf->setSousTitre($nomEcole);

    // Bandeau + infos
    $pdf->bandeau();
    $pdf->infos('Période : du ' . date('d/m/Y', strtotime($periode_debut)) . ' au ' . date('d/m/Y', strtotime($periode_fin))
        . '    ·    Généré le ' . date('d/m/Y H:i') . ' par ' . ($_SESSION['nom_complet'] ?? ''));

    // Cartes de synthèse
    $pdf->cartes([
        ['Séances', (int)$cahier_stats['total'], '#1D4ED8'],
        ['Cahiers validés', (int)$cahier_stats['validees'], '#15803D'],
        ['En attente', (int)$cahier_stats['en_attente'], '#B45309'],
        ['Punitions', (int)$punitions_periode, '#B91C1C'],
    ]);

    // 1. Absences & retards par classe
    $pdf->sousTitre('1. Absences & retards par classe');
    if ($present_par_classe) {
        $rows = [];
        foreach ($present_par_classe as $r) $rows[] = [$r['nom_affichage'], (int)$r['absences'], (int)$r['retards'], (int)$r['total']];
        $pdf->table(['Classe', 'Absences', 'Retards', 'Total'], $rows, [185, 107, 107, 106]);
    } else {
        $pdf->texte('Aucune donnée pour cette période.');
    }

    // 2. Élèves les plus absents
    $pdf->sousTitre('2. Élèves les plus absents (3 absences ou plus)');
    if ($top_absents) {
        $rows = [];
        foreach ($top_absents as $e) $rows[] = [$e['prenom'] . ' ' . $e['nom'], $e['nom_affichage'], (int)$e['nb']];
        $pdf->table(['Élève', 'Classe', 'Absences'], $rows, [215, 175, 115]);
    } else {
        $pdf->texte('Aucun élève avec 3 absences ou plus.');
    }

    // 3. Punitions par classe
    $pdf->sousTitre('3. Punitions par classe');
    if ($punitions_par_classe) {
        $rows = [];
        foreach ($punitions_par_classe as $r) $rows[] = [$r['nom_affichage'], (int)$r['nb'], (int)$r['a_faire']];
        $pdf->table(['Classe', 'Punitions', 'À faire'], $rows, [215, 145, 145]);
    } else {
        $pdf->texte('Aucune punition sur la période.');
    }

    // 4. Cahier de texte par classe
    $pdf->sousTitre('4. Cahier de texte par classe');
    if ($cahier_par_classe) {
        $rows = [];
        foreach ($cahier_par_classe as $r) $rows[] = [$r['nom_affichage'], (int)$r['total'], (int)$r['validees'], (int)$r['en_attente']];
        $pdf->table(['Classe', 'Séances', 'Validées', 'En attente'], $rows, [185, 107, 107, 106]);
    } else {
        $pdf->texte('Aucune séance sur la période.');
    }

    $pdf->telecharger('rapport_' . $periode_debut . '_' . $periode_fin . '.pdf');
}

$titre = 'Rapports';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-file-lines" style="color:var(--accent);margin-right:8px;"></i> Rapports d'activité</h1>
        <p><?= htmlspecialchars($_SESSION['etablissement_nom'] ?? '') ?> · Période du <?= date('d/m/Y', strtotime($periode_debut)) ?> au <?= date('d/m/Y', strtotime($periode_fin)) ?></p>
    </div>
    <form method="get" class="form-row">
        <div class="form-group"><label>Du</label><input type="date" name="debut" value="<?= htmlspecialchars($periode_debut) ?>"></div>
        <div class="form-group"><label>Au</label><input type="date" name="fin" value="<?= htmlspecialchars($periode_fin) ?>"></div>
        <button class="btn"><i class="fas fa-rotate"></i> Actualiser</button>
        <a href="?debut=<?= urlencode($periode_debut) ?>&fin=<?= urlencode($periode_fin) ?>&export=pdf" class="btn btn-accent" title="Télécharger le rapport PDF"><i class="fas fa-file-pdf"></i> Exporter PDF</a>
        <button type="button" class="btn btn-outline" onclick="window.print()" title="Imprimer le rapport"><i class="fas fa-print"></i> Imprimer</button>
    </form>
</div>

<div class="stats">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-book-open"></i></div>
        <div class="stat-value"><?= (int)$cahier_stats['total'] ?></div>
        <div class="stat-label">Séances</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-double"></i></div>
        <div class="stat-value"><?= (int)$cahier_stats['validees'] ?></div>
        <div class="stat-label">Cahiers validés</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-value"><?= (int)$cahier_stats['en_attente'] ?></div>
        <div class="stat-label">En attente</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-gavel"></i></div>
        <div class="stat-value"><?= (int)$punitions_periode ?></div>
        <div class="stat-label">Punitions</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-user-slash" style="color:var(--statut-danger);margin-right:8px;"></i> Absences & retards par classe</h2></div>
    <?php if ($present_par_classe): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Classe</th><th>Absences</th><th>Retards</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($present_par_classe as $r): ?>
                <tr>
                    <td data-label="Classe"><?= htmlspecialchars($r['nom_affichage']) ?></td>
                    <td data-label="Absences"><span style="font-weight:600;color:var(--statut-danger);"><?= (int)$r['absences'] ?></span></td>
                    <td data-label="Retards"><span style="font-weight:600;color:var(--statut-alerte);"><?= (int)$r['retards'] ?></span></td>
                    <td data-label="Total"><strong><?= (int)$r['total'] ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucune donnée pour cette période.</p></div></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-exclamation-triangle" style="color:var(--statut-danger);margin-right:8px;"></i> Élèves les plus absents (≥ 3 absences)</h2></div>
    <?php if ($top_absents): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Élève</th><th>Classe</th><th>Absences</th></tr></thead>
            <tbody>
            <?php foreach ($top_absents as $e): ?>
                <tr>
                    <td data-label="Élève"><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?></td>
                    <td data-label="Classe"><?= htmlspecialchars($e['nom_affichage']) ?></td>
                    <td data-label="Absences"><span class="badge badge-danger"><?= (int)$e['nb'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucun élève avec 3 absences ou plus.</p></div></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-gavel" style="color:var(--statut-alerte);margin-right:8px;"></i> Punitions par classe</h2></div>
    <?php if ($punitions_par_classe): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Classe</th><th>Punitions</th><th>À faire</th></tr></thead>
            <tbody>
            <?php foreach ($punitions_par_classe as $r): ?>
                <tr>
                    <td data-label="Classe"><?= htmlspecialchars($r['nom_affichage']) ?></td>
                    <td data-label="Punitions"><strong><?= (int)$r['nb'] ?></strong></td>
                    <td data-label="À faire"><span class="badge badge-warning"><?= (int)$r['a_faire'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucune punition sur la période.</p></div></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header"><h2><i class="fas fa-book-open" style="color:var(--statut-info);margin-right:8px;"></i> Cahier de texte par classe</h2></div>
    <?php if ($cahier_par_classe): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Classe</th><th>Séances</th><th>Validées</th><th>En attente</th></tr></thead>
            <tbody>
            <?php foreach ($cahier_par_classe as $r): ?>
                <tr>
                    <td data-label="Classe"><?= htmlspecialchars($r['nom_affichage']) ?></td>
                    <td data-label="Séances"><strong><?= (int)$r['total'] ?></strong></td>
                    <td data-label="Validées"><span class="badge badge-success"><?= (int)$r['validees'] ?></span></td>
                    <td data-label="En attente"><span class="badge badge-warning"><?= (int)$r['en_attente'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucune séance sur la période.</p></div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
