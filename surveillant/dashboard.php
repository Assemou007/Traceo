<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/../backend/config/database.php';

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];
$classe_filtre = (int)($_GET['classe_id'] ?? 0);

$stats = [];
$stats['absences_ajd'] = $pdo->prepare("SELECT COUNT(*) FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id WHERE s.date = CURDATE() AND p.statut = 'absent' AND cr.etablissement_id = ?");
$stats['absences_ajd']->execute([$etab_id]); $stats['absences_ajd'] = (int)$stats['absences_ajd']->fetchColumn();

$stats['retards_ajd'] = $pdo->prepare("SELECT COUNT(*) FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id WHERE s.date = CURDATE() AND p.statut = 'retard' AND cr.etablissement_id = ?");
$stats['retards_ajd']->execute([$etab_id]); $stats['retards_ajd'] = (int)$stats['retards_ajd']->fetchColumn();

$stats['punitions'] = $pdo->prepare("SELECT COUNT(*) FROM punition p JOIN eleve e ON e.id = p.eleve_id WHERE e.etablissement_id = ? AND p.statut = 'a_faire'");
$stats['punitions']->execute([$etab_id]); $stats['punitions'] = (int)$stats['punitions']->fetchColumn();

$stats['abs_semaine'] = $pdo->prepare("SELECT COUNT(*) FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id WHERE cr.etablissement_id = ? AND s.date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND p.statut = 'absent'");
$stats['abs_semaine']->execute([$etab_id]); $stats['abs_semaine'] = (int)$stats['abs_semaine']->fetchColumn();

$classes = $pdo->prepare("SELECT id, nom_affichage FROM classe WHERE etablissement_id = ? ORDER BY nom_affichage");
$classes->execute([$etab_id]);
$classes = $classes->fetchAll();

$clause_classe = '';
$params = [$etab_id];
if ($classe_filtre) {
    $clause_classe = 'AND cl.id = ?';
    $params[] = $classe_filtre;
}

// Dernières absences
$recentes = $pdo->prepare(
    "SELECT s.date, e.id AS eleve_id, e.nom, e.prenom, cl.nom_affichage AS classe, p.statut, cr.heure_debut
     FROM presence p
     JOIN seance s ON s.id = p.seance_id
     JOIN creneau cr ON cr.id = s.creneau_id
     JOIN eleve e ON e.id = p.eleve_id
     JOIN classe cl ON cl.id = e.classe_id
     WHERE cr.etablissement_id = ? $clause_classe
     ORDER BY s.date DESC, cr.heure_debut DESC
     LIMIT 30"
);
$recentes->execute($params);
$recentes = $recentes->fetchAll();

// Top absents
$top_abs = $pdo->prepare(
    "SELECT e.id, e.nom, e.prenom, cl.nom_affichage AS classe, COUNT(p.id) AS cnt
     FROM presence p
     JOIN seance s ON s.id = p.seance_id
     JOIN creneau cr ON cr.id = s.creneau_id
     JOIN eleve e ON e.id = p.eleve_id
     JOIN classe cl ON cl.id = e.classe_id
     WHERE cr.etablissement_id = ? AND s.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND p.statut = 'absent' $clause_classe
     GROUP BY e.id ORDER BY cnt DESC LIMIT 10"
);
$top_abs->execute($params);
$top_abs = $top_abs->fetchAll();

$titre = 'Vie scolaire';
require_once __DIR__ . '/../inc/header.php';
?>
<div class="page-header">
    <div>
        <h1>Vie scolaire</h1>
        <p>Bienvenue, <?= htmlspecialchars($_SESSION['nom_complet']) ?></p>
    </div>
    <div class="page-actions">
        <a href="../punitions.php?classe_id=<?= $classe_filtre ?>" class="btn btn-sm btn-outline"><i class="fas fa-gavel"></i> Gérer les punitions</a>
    </div>
</div>

<div class="stats" style="grid-template-columns: repeat(4, 1fr);">
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-user-slash"></i></div>
        <div class="stat-value"><?= $stats['absences_ajd'] ?></div>
        <div class="stat-label">Absences aujourd'hui</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-clock"></i></div>
        <div class="stat-value"><?= $stats['retards_ajd'] ?></div>
        <div class="stat-label">Retards aujourd'hui</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="stat-value"><?= $stats['punitions'] ?></div>
        <div class="stat-label">Punitions à faire</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-chart-bar"></i></div>
        <div class="stat-value"><?= $stats['abs_semaine'] ?></div>
        <div class="stat-label">Absences cette semaine</div>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:12px 20px;">
        <form method="get" class="form-row" style="margin:0;gap:12px;">
            <div class="form-group" style="margin:0;"><label style="font-size:0.75rem;">Filtrer par classe</label>
                <select name="classe_id" onchange="this.form.submit()" style="padding:6px 10px;">
                    <option value="">— Toutes les classes —</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $classe_filtre === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom_affichage']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($classe_filtre): ?>
            <div class="form-group" style="margin:0;align-self:flex-end;">
                <a href="dashboard.php" class="btn btn-sm btn-outline">Réinitialiser</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-history"></i> Dernières absences et retards</h2>
        <a href="../punitions.php?classe_id=<?= $classe_filtre ?>" class="btn btn-sm btn-outline"><i class="fas fa-gavel"></i> Punitions</a>
    </div>
    <?php if (empty($recentes)): ?>
    <div class="card-body">
        <div class="empty"><p>Aucune absence ou retard enregistré.</p></div>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>Élève</th><th>Classe</th><th>Statut</th><th>Horaire</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($recentes as $r): ?>
                <tr>
                    <td><span class="badge badge-neutral"><?= date('d/m', strtotime($r['date'])) ?></span></td>
                    <td><strong><?= htmlspecialchars($r['prenom'] . ' ' . $r['nom']) ?></strong></td>
                    <td><?= htmlspecialchars($r['classe']) ?></td>
                    <td>
                        <?php if ($r['statut'] === 'absent'): ?>
                            <span class="badge badge-danger">Absent</span>
                        <?php else: ?>
                            <span class="badge badge-warning">Retard</span>
                        <?php endif; ?>
                    </td>
                    <td><?= substr($r['heure_debut'], 0, 5) ?></td>
                    <td>
                        <a href="../punitions.php?histo=<?= $r['eleve_id'] ?>&classe_id=<?= $classe_filtre ?>" class="btn btn-sm btn-outline" title="Voir historique"><i class="fas fa-eye"></i></a>
                        <a href="../punitions.php?classe_id=<?= $classe_filtre ?>&tous=1" class="btn btn-sm btn-danger" title="Punir"><i class="fas fa-gavel"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($top_abs)): ?>
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-exclamation-triangle" style="color:var(--statut-danger);"></i> Élèves les plus absents (30 jours)</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Élève</th><th>Classe</th><th>Absences</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($top_abs as $a): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($a['prenom'] . ' ' . $a['nom']) ?></strong></td>
                    <td><?= htmlspecialchars($a['classe']) ?></td>
                    <td><span class="badge badge-danger"><?= (int)$a['cnt'] ?></span></td>
                    <td>
                        <a href="../punitions.php?histo=<?= $a['id'] ?>&classe_id=<?= $classe_filtre ?>" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i> Voir</a>
                        <a href="../punitions.php?classe_id=<?= $classe_filtre ?>&tous=1" class="btn btn-sm btn-danger"><i class="fas fa-gavel"></i> Punir</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
