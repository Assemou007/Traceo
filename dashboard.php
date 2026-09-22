<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_web_login(['censeur']);

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];

$annee = $pdo->prepare("SELECT id FROM annee_scolaire WHERE etablissement_id = ? AND statut = 'en_cours' LIMIT 1");
$annee->execute([$etab_id]);
$annee_id = $annee->fetchColumn();

// ---------- Période du tableau de bord ----------
$preset = $_GET['periode'] ?? '30j';
if (!in_array($preset, ['7j', '30j', '90j', 'perso'], true)) $preset = '30j';
$aujourdhui = date('Y-m-d');
if ($preset === 'perso') {
    $debut = $_GET['debut'] ?? date('Y-m-d', strtotime('-29 days'));
    $fin = $_GET['fin'] ?? $aujourdhui;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-m-d', strtotime('-29 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) $fin = $aujourdhui;
    if ($fin < $debut) $fin = $debut;
} else {
    $nJours = (int)substr($preset, 0, -1);
    $debut = date('Y-m-d', strtotime('-' . ($nJours - 1) . ' days'));
    $fin = $aujourdhui;
}

$spanJours = (int)floor((strtotime($fin) - strtotime($debut)) / 86400) + 1;
$moisMode = $spanJours > 120; // au-delà de 120 jours, on agrège par mois
$grpPresence = $moisMode ? "DATE_FORMAT(s.date, '%Y-%m')" : 's.date';
$grpSeance = $moisMode ? "DATE_FORMAT(s.date, '%Y-%m')" : 's.date';
$grpPunition = $moisMode ? "DATE_FORMAT(p.date_attribution, '%Y-%m')" : 'p.date_attribution';

$finPrec = date('Y-m-d', strtotime($debut . ' -1 day'));
$debutPrec = date('Y-m-d', strtotime($finPrec . ' -' . ($spanJours - 1) . ' days'));
$libellePeriode = $preset === 'perso' ? 'du ' . date('d/m/Y', strtotime($debut)) . ' au ' . date('d/m/Y', strtotime($fin)) : $nJours . ' derniers jours';

function remplir_serie(string $debut, string $fin, array $map, bool $mois): array {
    $labels = [];
    $values = [];
    $d = new DateTime($debut);
    $dernier = new DateTime($fin);
    if ($mois) $d->modify('first day of this month');
    while ($d <= $dernier) {
        $cle = $mois ? $d->format('Y-m') : $d->format('Y-m-d');
        $labels[] = $mois ? $d->format('m/Y') : $d->format('d/m');
        $values[] = (int)($map[$cle] ?? 0);
        $mois ? $d->modify('+1 month') : $d->modify('+1 day');
    }
    return [$labels, $values];
}

// ---------- Cartes de synthèse ----------
$stats = [];
$stats['eleves'] = $pdo->prepare("SELECT COUNT(*) FROM eleve WHERE etablissement_id = ? AND statut = 'actif'");
$stats['eleves']->execute([$etab_id]); $stats['eleves'] = (int)$stats['eleves']->fetchColumn();

$stats['profs'] = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE etablissement_id = ? AND role = 'prof' AND statut = 'actif'");
$stats['profs']->execute([$etab_id]); $stats['profs'] = (int)$stats['profs']->fetchColumn();

$stats['classes'] = $pdo->prepare("SELECT COUNT(*) FROM classe WHERE etablissement_id = ? AND annee_scolaire_id = ?");
$stats['classes']->execute([$etab_id, $annee_id]); $stats['classes'] = (int)$stats['classes']->fetchColumn();

// Requetes de synthese consolidees
$q_pres = $pdo->prepare("SELECT 
    SUM(CASE WHEN s.date = CURDATE() AND p.statut = 'absent' THEN 1 ELSE 0 END) AS abs_ajd,
    SUM(CASE WHEN s.date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND p.statut = 'absent' THEN 1 ELSE 0 END) AS abs_hier,
    SUM(CASE WHEN s.date = CURDATE() AND p.statut = 'retard' THEN 1 ELSE 0 END) AS ret_ajd,
    SUM(CASE WHEN s.date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND p.statut = 'retard' THEN 1 ELSE 0 END) AS ret_hier
    FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id WHERE cr.etablissement_id = ? AND s.date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
$q_pres->execute([$etab_id]);
$res_pres = $q_pres->fetch(PDO::FETCH_ASSOC) ?: [];

$stats['absences_ajd'] = (int)($res_pres['abs_ajd'] ?? 0);
$stats['absences_hier'] = (int)($res_pres['abs_hier'] ?? 0);
$stats['retards_ajd'] = (int)($res_pres['ret_ajd'] ?? 0);
$stats['retards_hier'] = (int)($res_pres['ret_hier'] ?? 0);

$q_seances = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN s.statut_validation = 'en_attente' THEN 1 ELSE 0 END) AS attente FROM seance s JOIN creneau c ON c.id = s.creneau_id WHERE c.etablissement_id = ?");
$q_seances->execute([$etab_id]);
$res_seances = $q_seances->fetch(PDO::FETCH_ASSOC) ?: [];

$stats['seances_total'] = (int)($res_seances['total'] ?? 0);
$stats['attente'] = (int)($res_seances['attente'] ?? 0);

$stats['punitions'] = $pdo->prepare("SELECT COUNT(*) FROM punition p JOIN eleve e ON e.id = p.eleve_id WHERE e.etablissement_id = ? AND p.statut = 'a_faire'");
$stats['punitions']->execute([$etab_id]); $stats['punitions'] = (int)$stats['punitions']->fetchColumn();

// ---------- Séries temporelles (période) ----------
$abs_map = [];
$ret_map = [];
$q = $pdo->prepare("SELECT $grpPresence AS lbl, p.statut, COUNT(*) AS cnt FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ? AND p.statut IN ('absent', 'retard') GROUP BY lbl, p.statut");
$q->execute([$etab_id, $debut, $fin]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['statut'] === 'absent') $abs_map[$row['lbl']] = (int)$row['cnt'];
    else $ret_map[$row['lbl']] = (int)$row['cnt'];
}

$seances_map = [];
$validees_map = [];
$q = $pdo->prepare("SELECT $grpSeance AS lbl, COUNT(*) AS cnt, SUM(CASE WHEN s.statut_validation = 'validee' THEN 1 ELSE 0 END) AS validees FROM seance s JOIN creneau cr ON cr.id = s.creneau_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ? GROUP BY lbl");
$q->execute([$etab_id, $debut, $fin]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $seances_map[$row['lbl']] = (int)$row['cnt'];
    $validees_map[$row['lbl']] = (int)$row['validees'];
}

$pun_map = [];
$q = $pdo->prepare("SELECT $grpPunition AS lbl, COUNT(*) AS cnt FROM punition p JOIN eleve e ON e.id = p.eleve_id WHERE e.etablissement_id = ? AND p.date_attribution BETWEEN ? AND ? GROUP BY lbl");
$q->execute([$etab_id, $debut, $fin]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $pun_map[$row['lbl']] = (int)$row['cnt'];

[$abs_labels, $abs_values] = remplir_serie($debut, $fin, $abs_map, $moisMode);
[, $ret_values] = remplir_serie($debut, $fin, $ret_map, $moisMode);
[, $seances_values] = remplir_serie($debut, $fin, $seances_map, $moisMode);
[, $validees_values] = remplir_serie($debut, $fin, $validees_map, $moisMode);
[, $pun_values] = remplir_serie($debut, $fin, $pun_map, $moisMode);

// Totaux période + période précédente (tendances)
$abs_total = array_sum($abs_values);
$ret_total = array_sum($ret_values);
$pun_total = array_sum($pun_values);
$seances_total = array_sum($seances_values);
$validees_total = array_sum($validees_values);

function compter_presence(PDO $pdo, int $etab, string $d, string $f, string $statut): int {
    $q = $pdo->prepare("SELECT COUNT(*) FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ? AND p.statut = ?");
    $q->execute([$etab, $d, $f, $statut]);
    return (int)$q->fetchColumn();
}
function compter_punitions(PDO $pdo, int $etab, string $d, string $f): int {
    $q = $pdo->prepare("SELECT COUNT(*) FROM punition p JOIN eleve e ON e.id = p.eleve_id WHERE e.etablissement_id = ? AND p.date_attribution BETWEEN ? AND ?");
    $q->execute([$etab, $d, $f]);
    return (int)$q->fetchColumn();
}

$abs_prec = compter_presence($pdo, $etab_id, $debutPrec, $finPrec, 'absent');
$ret_prec = compter_presence($pdo, $etab_id, $debutPrec, $finPrec, 'retard');
$pun_prec = compter_punitions($pdo, $etab_id, $debutPrec, $finPrec);

// ---------- Répartition validation (période) ----------
$q = $pdo->prepare("SELECT statut_validation, COUNT(*) AS cnt FROM seance s JOIN creneau c ON c.id = s.creneau_id WHERE c.etablissement_id = ? AND s.date BETWEEN ? AND ? GROUP BY statut_validation");
$q->execute([$etab_id, $debut, $fin]);
$val_data = ['validee' => 0, 'en_attente' => 0, 'forcee_censeur' => 0];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) $val_data[$row['statut_validation']] = (int)$row['cnt'];

// ---------- Absences par classe (période) ----------
$q = $pdo->prepare("SELECT c.nom_affichage, COUNT(p.id) AS cnt FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id JOIN classe c ON c.id = cr.classe_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ? AND p.statut = 'absent' GROUP BY c.id, c.nom_affichage ORDER BY cnt DESC LIMIT 8");
$q->execute([$etab_id, $debut, $fin]);
$abs_classes_labels = [];
$abs_classes_values = [];
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $abs_classes_labels[] = $row['nom_affichage'];
    $abs_classes_values[] = (int)$row['cnt'];
}

// ---------- Élèves les plus absents (période) ----------
$q = $pdo->prepare("SELECT e.nom, e.prenom, c.nom_affichage AS classe_nom, COUNT(p.id) AS cnt FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id JOIN eleve e ON e.id = p.eleve_id JOIN classe c ON c.id = e.classe_id WHERE cr.etablissement_id = ? AND s.date BETWEEN ? AND ? AND p.statut = 'absent' GROUP BY e.id ORDER BY cnt DESC LIMIT 5");
$q->execute([$etab_id, $debut, $fin]);
$top_abs_students = $q->fetchAll(PDO::FETCH_ASSOC);

// ---------- Dernières séances ----------
$recent = $pdo->prepare("SELECT s.date, s.statut_validation, u.nom, u.prenom, cr.heure_debut, cr.heure_fin, c.nom_affichage AS classe_nom, m.nom AS matiere_nom FROM seance s JOIN creneau cr ON cr.id = s.creneau_id JOIN utilisateur u ON u.id = cr.prof_id JOIN classe c ON c.id = cr.classe_id JOIN matiere m ON m.id = cr.matiere_id WHERE cr.etablissement_id = ? ORDER BY s.created_at DESC LIMIT 10");
$recent->execute([$etab_id]);
$recent_sessions = $recent->fetchAll(PDO::FETCH_ASSOC);

$titre = 'Tableau de bord';
require_once __DIR__ . '/inc/header.php';
?>
<style>
.stat-card { display: flex; flex-direction: column; }
.stat-row { display: flex; align-items: center; gap: 16px; }
.stat-row .stat-value { font-size: 1.8rem; }
.stat-row .stat-icon { margin-bottom: 0; }
.periode-form { display: flex; align-items: flex-end; gap: 8px; flex-wrap: wrap; }
.periode-form select, .periode-form input[type="date"] { padding: 8px 10px; }
</style>

<div class="page-header">
    <div>
        <h1>Tableau de bord</h1>
        <p>Vue d'ensemble de l'établissement · période : <?= htmlspecialchars($libellePeriode) ?></p>
    </div>
    <div class="page-actions">
        <form method="get" class="periode-form">
            <select name="periode" id="periodeSel" aria-label="Période">
                <option value="7j" <?= $preset === '7j' ? 'selected' : '' ?>>7 derniers jours</option>
                <option value="30j" <?= $preset === '30j' ? 'selected' : '' ?>>30 derniers jours</option>
                <option value="90j" <?= $preset === '90j' ? 'selected' : '' ?>>90 derniers jours</option>
                <option value="perso" <?= $preset === 'perso' ? 'selected' : '' ?>>Personnalisée</option>
            </select>
            <input type="date" name="debut" id="inDebut" value="<?= $preset === 'perso' ? htmlspecialchars($debut) : '' ?>" aria-label="Date de début">
            <input type="date" name="fin" id="inFin" value="<?= $preset === 'perso' ? htmlspecialchars($fin) : '' ?>" aria-label="Date de fin">
            <button class="btn">Appliquer</button>
            <a class="btn btn-sm btn-outline" href="rapports.php?debut=<?= urlencode($debut) ?>&fin=<?= urlencode($fin) ?>">Rapports détaillés</a>
        </form>
    </div>
</div>

<div class="stats">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
        <div class="stat-value"><?= $stats['eleves'] ?></div>
        <div class="stat-label">Élèves actifs</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon gold"><i class="fas fa-chalkboard-user"></i></div>
        <div class="stat-value"><?= $stats['profs'] ?></div>
        <div class="stat-label">Professeurs</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-book"></i></div>
        <div class="stat-value"><?= $stats['classes'] ?></div>
        <div class="stat-label">Classes</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-clipboard-list"></i></div>
        <div class="stat-value"><?= $stats['attente'] ?></div>
        <div class="stat-label">Cahiers en attente</div>
        <div class="stat-footer">sur <?= $stats['seances_total'] ?> séances</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-user-slash"></i></div>
        <div class="stat-value"><?= $stats['absences_ajd'] ?></div>
        <div class="stat-label">Absences aujourd'hui</div>
        <?php if ($stats['absences_hier'] > 0 || $stats['absences_ajd'] > 0): ?>
        <div class="stat-footer">
            <?php if ($stats['absences_ajd'] > $stats['absences_hier']): ?>
            <span class="trend-up">↑ <?= $stats['absences_ajd'] - $stats['absences_hier'] ?></span>
            <?php elseif ($stats['absences_ajd'] < $stats['absences_hier']): ?>
            <span class="trend-down">↓ <?= $stats['absences_hier'] - $stats['absences_ajd'] ?></span>
            <?php else: ?>—<?php endif; ?>
            vs hier (<?= $stats['absences_hier'] ?>)
        </div>
        <?php endif; ?>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-clock"></i></div>
        <div class="stat-value"><?= $stats['retards_ajd'] ?></div>
        <div class="stat-label">Retards aujourd'hui</div>
        <?php if ($stats['retards_hier'] > 0 || $stats['retards_ajd'] > 0): ?>
        <div class="stat-footer">
            <?php if ($stats['retards_ajd'] > $stats['retards_hier']): ?>
            <span class="trend-up">↑ <?= $stats['retards_ajd'] - $stats['retards_hier'] ?></span>
            <?php elseif ($stats['retards_ajd'] < $stats['retards_hier']): ?>
            <span class="trend-down">↓ <?= $stats['retards_hier'] - $stats['retards_ajd'] ?></span>
            <?php else: ?>—<?php endif; ?>
            vs hier (<?= $stats['retards_hier'] ?>)
        </div>
        <?php endif; ?>
        <div class="stat-footer"><?= $ret_total ?> sur la période (<?= htmlspecialchars($libellePeriode) ?>)</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="stat-value"><?= $stats['punitions'] ?></div>
        <div class="stat-label">Punitions à faire</div>
        <div class="stat-footer"><?= $pun_total ?> sur la période</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-chart-line"></i></div>
        <div class="stat-value"><?= $abs_total ?></div>
        <div class="stat-label">Absences (période)</div>
        <div class="stat-footer">
            <?php if ($abs_total > $abs_prec): ?>
            <span class="trend-up">↑ +<?= $abs_total - $abs_prec ?></span>
            <?php elseif ($abs_total < $abs_prec): ?>
            <span class="trend-down">↓ -<?= $abs_prec - $abs_total ?></span>
            <?php else: ?>—<?php endif; ?>
            vs période précédente (<?= $abs_prec ?>)
        </div>
    </div>
</div>

<div class="dashboard-grid">
    <div class="chart-card" style="grid-column:1/-1;">
        <div class="card-header">
            <h3>Évolution des absences & retards par jour</h3>
            <div class="chart-legend">
                <span><span class="dot" style="background:var(--chart-absences)"></span> Absences</span>
                <span><span class="dot" style="background:var(--chart-retards)"></span> Retards</span>
            </div>
        </div>
        <div class="chart-body">
            <canvas id="chartTendancePresence" height="240"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <div class="card-header">
            <h3>Séances enregistrées vs validées</h3>
            <div class="chart-legend">
                <span><span class="dot" style="background:var(--chart-seances)"></span> Enregistrées</span>
                <span><span class="dot" style="background:var(--chart-validees)"></span> Validées</span>
            </div>
        </div>
        <div class="chart-body">
            <canvas id="chartTendanceSeances" height="200"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <div class="card-header">
            <h3>Punitions par jour</h3>
            <div class="chart-legend">
                <span><span class="dot" style="background:var(--chart-punitions)"></span> Punitions</span>
            </div>
        </div>
        <div class="chart-body">
            <canvas id="chartTendancePunitions" height="200"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <div class="card-header">
            <h3>Validation des séances</h3>
            <div class="chart-legend">
                <span><span class="dot" style="background:var(--chart-validees)"></span> Validées</span>
                <span><span class="dot" style="background:var(--chart-classes)"></span> En attente</span>
                <span><span class="dot" style="background:var(--chart-seances)"></span> Forcées</span>
            </div>
        </div>
        <div class="chart-body" style="display:flex; align-items:center; justify-content:center;">
            <canvas id="chartValidation" height="200" style="max-width:260px;"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <div class="card-header">
            <h3>Absences par classe</h3>
            <div class="chart-legend">
                <span><span class="dot" style="background:var(--chart-absences)"></span> Absences</span>
            </div>
        </div>
        <div class="chart-body">
            <canvas id="chartAbsByClass" height="200"></canvas>
        </div>
    </div>
    <div class="chart-card">
        <div class="card-header">
            <h3>Élèves les plus absents</h3>
        </div>
        <div class="card-body" style="padding:12px 20px;">
            <?php if (empty($top_abs_students)): ?>
                <div class="empty"><p>Aucune absence sur la période</p></div>
            <?php else: ?>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Élève</th>
                        <th>Classe</th>
                        <th>Absences</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_abs_students as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?></td>
                        <td><?= htmlspecialchars($s['classe_nom']) ?></td>
                        <td><span class="badge badge-danger"><?= (int)$s['cnt'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Dernières séances enregistrées</h2>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($recent_sessions)): ?>
            <div class="empty"><p>Aucune séance enregistrée</p></div>
        <?php else: ?>
        <div class="table-wrap">
        <table class="recent-activity">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Professeur</th>
                    <th>Classe</th>
                    <th>Matière</th>
                    <th>Horaire</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_sessions as $s): ?>
                <tr>
                    <td><span class="time"><?= date('d/m', strtotime($s['date'])) ?></span></td>
                    <td><?= htmlspecialchars($s['prenom'] . ' ' . $s['nom']) ?></td>
                    <td><?= htmlspecialchars($s['classe_nom']) ?></td>
                    <td><?= htmlspecialchars($s['matiere_nom']) ?></td>
                    <td><span class="time"><?= substr($s['heure_debut'], 0, 5) ?>-<?= substr($s['heure_fin'], 0, 5) ?></span></td>
                    <td>
                        <?php if ($s['statut_validation'] === 'validee'): ?>
                        <span class="badge badge-success">Validée</span>
                        <?php elseif ($s['statut_validation'] === 'forcee_censeur'): ?>
                        <span class="badge badge-info">Forcée</span>
                        <?php else: ?>
                        <span class="badge badge-warning">En attente</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    const danger = getComputedStyle(document.documentElement).getPropertyValue('--statut-danger').trim() || '#DC2626';
    const amber  = getComputedStyle(document.documentElement).getPropertyValue('--statut-alerte').trim() || '#D97706';
    const green  = getComputedStyle(document.documentElement).getPropertyValue('--statut-present').trim() || '#059669';
    const info   = getComputedStyle(document.documentElement).getPropertyValue('--statut-info').trim() || '#2563EB';
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#C7920E';
    const text   = getComputedStyle(document.documentElement).getPropertyValue('--text').trim() || '#0B1324';
    const chartAbsences = getComputedStyle(document.documentElement).getPropertyValue('--chart-absences').trim() || '#E11D48';
    const chartRetards  = getComputedStyle(document.documentElement).getPropertyValue('--chart-retards').trim() || '#F97316';
    const chartSeances  = getComputedStyle(document.documentElement).getPropertyValue('--chart-seances').trim() || '#3B82F6';
    const chartValidees = getComputedStyle(document.documentElement).getPropertyValue('--chart-validees').trim() || '#10B981';
    const chartPunitions = getComputedStyle(document.documentElement).getPropertyValue('--chart-punitions').trim() || '#A855F7';
    const chartClasses  = getComputedStyle(document.documentElement).getPropertyValue('--chart-classes').trim() || '#F59E0B';

    // Count-up animation for KPI cards
    document.querySelectorAll('.stat-value').forEach(el => {
        const target = parseInt(el.textContent.trim(), 10);
        if (isNaN(target) || target === 0) return;
        const duration = 1000;
        const startTime = performance.now();
        function update(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const ease = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.floor(ease * target);
            if (progress < 1) requestAnimationFrame(update);
            else el.textContent = target;
        }
        requestAnimationFrame(update);
    });

    function resizeCanvas(canvas) {
        const rect = canvas.parentElement.getBoundingClientRect();
        const w = canvas.clientWidth || rect.width - 8;
        const dpr = window.devicePixelRatio || 1;
        canvas.width = w * dpr;
        canvas.height = canvas.clientHeight * dpr;
        const ctx = canvas.getContext('2d');
        ctx.scale(dpr, dpr);
        return { ctx, w, h: canvas.clientHeight };
    }

    function drawLineChart(canvasId, labels, series) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const startTime = performance.now();
        const duration = 1100;

        function render(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const ease = 1 - Math.pow(1 - progress, 3);

            const { ctx, w, h } = resizeCanvas(canvas);
            ctx.clearRect(0, 0, w, h);
            const maxVal = Math.max(1, ...series.flatMap(s => s.values));
            const max = Math.ceil(maxVal / 5) * 5;
            const pad = { top: 14, bottom: 26, left: 34, right: 12 };
            const cw = w - pad.left - pad.right;
            const ch = h - pad.top - pad.bottom;
            const n = labels.length;
            const xAt = i => pad.left + (n > 1 ? cw * i / (n - 1) : cw / 2);
            const yAt = v => pad.top + ch - ch * (v / max);

            // Grid lines
            ctx.font = '10px sans-serif';
            ctx.strokeStyle = 'rgba(226, 232, 240, 0.6)';
            ctx.lineWidth = 1;
            for (let g = 0; g <= 4; g++) {
                const y = pad.top + ch * g / 4;
                ctx.beginPath(); ctx.moveTo(pad.left, y); ctx.lineTo(pad.left + cw, y); ctx.stroke();
                ctx.fillStyle = '#8899AA';
                ctx.textAlign = 'right';
                ctx.fillText(String(Math.round(max - max * g / 4)), pad.left - 6, y + 3);
            }

            // X Labels
            ctx.textAlign = 'center';
            const pasLabel = Math.max(1, Math.ceil(n / 8));
            for (let i = 0; i < n; i++) {
                if (i % pasLabel === 0 || i === n - 1) {
                    ctx.fillStyle = '#8899AA';
                    ctx.fillText(labels[i], xAt(i), pad.top + ch + 14);
                }
            }

            // Series drawing
            series.forEach(function(s) {
                const animatedValues = s.values.map(v => v * ease);

                // Area Gradient
                const grad = ctx.createLinearGradient(0, pad.top, 0, pad.top + ch);
                grad.addColorStop(0, s.color + '33');
                grad.addColorStop(1, s.color + '00');
                ctx.fillStyle = grad;
                ctx.beginPath();
                ctx.moveTo(xAt(0), pad.top + ch);
                for (let i = 0; i < n; i++) {
                    ctx.lineTo(xAt(i), yAt(animatedValues[i]));
                }
                ctx.lineTo(xAt(n - 1), pad.top + ch);
                ctx.closePath();
                ctx.fill();

                // Stroke line
                ctx.strokeStyle = s.color;
                ctx.lineWidth = 2.5;
                ctx.setLineDash(s.dash ? [7, 5] : []);
                ctx.beginPath();
                for (let i = 0; i < n; i++) {
                    const x = xAt(i), y = yAt(animatedValues[i]);
                    if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
                }
                ctx.stroke();
                ctx.setLineDash([]);

                // Points
                for (let i = 0; i < n; i++) {
                    const x = xAt(i), y = yAt(animatedValues[i]);
                    ctx.beginPath(); ctx.arc(x, y, 3.5 * ease, 0, Math.PI * 2);
                    ctx.fillStyle = s.color; ctx.fill();
                    ctx.strokeStyle = '#FFFFFF'; ctx.lineWidth = 1.5; ctx.stroke();
                }

                // Numbers above points
                if (n <= 31 && progress > 0.6) {
                    const alpha = (progress - 0.6) / 0.4;
                    ctx.fillStyle = s.color;
                    ctx.font = 'bold 9px sans-serif';
                    ctx.textAlign = 'center';
                    for (let i = 0; i < n; i++) {
                        if (s.values[i] === 0) continue;
                        ctx.globalAlpha = alpha;
                        ctx.fillText(String(s.values[i]), xAt(i), yAt(animatedValues[i]) - 6);
                        ctx.globalAlpha = 1.0;
                    }
                }
            });

            if (progress < 1) {
                requestAnimationFrame(render);
            }
        }
        requestAnimationFrame(render);
    }

    const labelsPresence = <?= json_encode($abs_labels) ?>;
    drawLineChart('chartTendancePresence', labelsPresence, [
        { values: <?= json_encode($abs_values) ?>, color: chartAbsences },
        { values: <?= json_encode($ret_values) ?>, color: chartRetards, dash: true },
    ]);
    drawLineChart('chartTendanceSeances', labelsPresence, [
        { values: <?= json_encode($seances_values) ?>, color: chartSeances },
        { values: <?= json_encode($validees_values) ?>, color: chartValidees, dash: true },
    ]);
    drawLineChart('chartTendancePunitions', labelsPresence, [
        { values: <?= json_encode($pun_values) ?>, color: chartPunitions },
    ]);

    // --- Donut Chart (validation) ---
    (function() {
        const canvas = document.getElementById('chartValidation');
        if (!canvas) return;
        const data = [
            { value: <?= $val_data['validee'] ?>, color: chartValidees, label: 'Validées' },
            { value: <?= $val_data['en_attente'] ?>, color: chartClasses, label: 'En attente' },
            { value: <?= $val_data['forcee_censeur'] ?>, color: chartSeances, label: 'Forcées' },
        ];
        const total = data.reduce((s, d) => s + d.value, 0);
        const startTime = performance.now();
        const duration = 1000;

        function render(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const ease = 1 - Math.pow(1 - progress, 3);

            const { ctx, w, h } = resizeCanvas(canvas);
            ctx.clearRect(0, 0, w, h);
            if (total === 0) {
                ctx.fillStyle = '#8899AA'; ctx.font = '13px sans-serif';
                ctx.textAlign = 'center'; ctx.fillText('Aucune séance', w/2, h/2);
                return;
            }
            const cx = w / 2, cy = h / 2;
            const radius = Math.min(cx, cy) * 0.82;
            const inner = radius * 0.6;

            let start = -Math.PI / 2;
            const totalSweep = Math.PI * 2 * ease;
            let currentSweep = 0;

            data.forEach(function(d) {
                const sliceAngle = (d.value / total) * Math.PI * 2;
                const drawAngle = Math.min(sliceAngle, Math.max(0, totalSweep - currentSweep));

                if (drawAngle > 0) {
                    ctx.beginPath();
                    ctx.arc(cx, cy, radius, start, start + drawAngle);
                    ctx.arc(cx, cy, inner, start + drawAngle, start, true);
                    ctx.closePath();
                    ctx.fillStyle = d.color;
                    ctx.fill();
                }
                start += sliceAngle;
                currentSweep += sliceAngle;
            });

            ctx.globalAlpha = ease;
            ctx.fillStyle = text;
            ctx.font = 'bold 24px sans-serif';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText(Math.floor(total * ease), cx, cy - 6);
            ctx.fillStyle = '#8899AA';
            ctx.font = '10px sans-serif';
            ctx.fillText('séances', cx, cy + 14);
            ctx.globalAlpha = 1.0;

            if (progress < 1) {
                requestAnimationFrame(render);
            }
        }
        requestAnimationFrame(render);
    })();

    // --- Horizontal Bar Chart (absences by class) ---
    (function() {
        const canvas = document.getElementById('chartAbsByClass');
        if (!canvas) return;
        const labels = <?= json_encode($abs_classes_labels) ?>;
        const values = <?= json_encode($abs_classes_values) ?>;
        const startTime = performance.now();
        const duration = 900;

        function render(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const ease = 1 - Math.pow(1 - progress, 3);

            const { ctx, w, h } = resizeCanvas(canvas);
            ctx.clearRect(0, 0, w, h);
            if (labels.length === 0) {
                ctx.fillStyle = '#8899AA'; ctx.font = '13px sans-serif';
                ctx.textAlign = 'center'; ctx.fillText('Aucune absence sur la période', w/2, h/2);
                return;
            }
            const max = Math.max(...values, 1);
            const pad = { top: 8, bottom: 8, left: 12, right: 50 };
            const chartW = w - pad.left - pad.right;
            const chartH = h - pad.top - pad.bottom;
            const barH = Math.min(24, chartH / labels.length * 0.65);
            const gap = chartH / labels.length;

            ctx.font = '11px sans-serif';
            for (let i = 0; i < labels.length; i++) {
                const y = pad.top + gap * i + gap/2 + barH/2 - 4;
                const targetBarW = chartW * Math.min(values[i] / max, 1);
                const barW = targetBarW * ease;
                const x = pad.left;

                ctx.fillStyle = chartAbsences + 'CC';
                const rx = 3;
                if (barW > 2) {
                    ctx.beginPath();
                    ctx.moveTo(x + rx, y);
                    ctx.lineTo(x + Math.max(rx, barW - rx), y);
                    ctx.arcTo(x + barW, y, x + barW, y + barH, rx);
                    ctx.lineTo(x + barW, y + barH);
                    ctx.arcTo(x + barW, y + barH, x + Math.max(0, barW - rx), y + barH, rx);
                    ctx.lineTo(x + rx, y + barH);
                    ctx.arcTo(x, y + barH, x, y + barH - rx, rx);
                    ctx.lineTo(x, y + rx);
                    ctx.arcTo(x, y, x + rx, y, rx);
                    ctx.closePath();
                    ctx.fill();
                }

                ctx.fillStyle = text;
                ctx.textAlign = 'right';
                ctx.fillText(labels[i], x - 6, y + barH/2 + 4);

                ctx.fillStyle = '#8899AA';
                ctx.textAlign = 'left';
                ctx.fillText(Math.floor(values[i] * ease), x + barW + 6, y + barH/2 + 4);
            }

            if (progress < 1) {
                requestAnimationFrame(render);
            }
        }
        requestAnimationFrame(render);
    })();

    // --- Sélecteur de période ---
    (function() {
        const sel = document.getElementById('periodeSel');
        const inDebut = document.getElementById('inDebut');
        const inFin = document.getElementById('inFin');
        if (!sel) return;
        const iso = dt => dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
        sel.addEventListener('change', function() {
            const v = sel.value;
            if (v === 'perso') return;
            const today = new Date();
            const d = new Date(today);
            d.setDate(d.getDate() - (parseInt(v, 10) - 1));
            inDebut.value = iso(d);
            inFin.value = iso(today);
            sel.form.submit();
        });
    })();

    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            drawLineChart('chartTendancePresence', labelsPresence, [
                { values: <?= json_encode($abs_values) ?>, color: chartAbsences },
                { values: <?= json_encode($ret_values) ?>, color: chartRetards, dash: true },
            ]);
            drawLineChart('chartTendanceSeances', labelsPresence, [
                { values: <?= json_encode($seances_values) ?>, color: chartSeances },
                { values: <?= json_encode($validees_values) ?>, color: chartValidees, dash: true },
            ]);
            drawLineChart('chartTendancePunitions', labelsPresence, [
                { values: <?= json_encode($pun_values) ?>, color: chartPunitions },
            ]);
        }, 200);
    });
})();
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
