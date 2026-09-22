<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/../backend/config/database.php';

$pdo = get_db();
$user_id = $_SESSION['utilisateur_id'];
$etab_id = $_SESSION['etablissement_id'];

$annee = $pdo->prepare("SELECT id FROM annee_scolaire WHERE etablissement_id = ? AND statut = 'en_cours' LIMIT 1");
$annee->execute([$etab_id]);
$annee_id = $annee->fetchColumn();

$date_aff = $_GET['date'] ?? date('Y-m-d');
$jour_semaine = (int)date('N', strtotime($date_aff));

$classes_prof = $pdo->prepare(
    "SELECT DISTINCT cl.id, cl.nom_affichage
     FROM prof_matiere_classe pmc
     JOIN classe cl ON cl.id = pmc.classe_id
     WHERE pmc.utilisateur_id = ? AND pmc.annee_scolaire_id = ?"
);
$classes_prof->execute([$user_id, $annee_id]);
$classes_prof = $classes_prof->fetchAll();

$stats_classes = [];
foreach ($classes_prof as $cl) {
    $total = $pdo->prepare("SELECT COUNT(*) FROM creneau WHERE prof_id = ? AND classe_id = ? AND annee_scolaire_id = ?");
    $total->execute([$user_id, $cl['id'], $annee_id]);
    $fait = $pdo->prepare("SELECT COUNT(*) FROM seance s JOIN creneau cr ON cr.id = s.creneau_id WHERE cr.prof_id = ? AND cr.classe_id = ? AND cr.annee_scolaire_id = ? AND s.date <= ?");
    $fait->execute([$user_id, $cl['id'], $annee_id, date('Y-m-d')]);
    $valide = $pdo->prepare("SELECT COUNT(*) FROM seance s JOIN creneau cr ON cr.id = s.creneau_id WHERE cr.prof_id = ? AND cr.classe_id = ? AND cr.annee_scolaire_id = ? AND s.date <= ? AND s.statut_validation IN ('validee','forcee_censeur')");
    $valide->execute([$user_id, $cl['id'], $annee_id, date('Y-m-d')]);
    $stats_classes[] = [
        'id' => $cl['id'],
        'nom' => $cl['nom_affichage'],
        'total' => (int)$total->fetchColumn(),
        'fait' => (int)$fait->fetchColumn(),
        'valide' => (int)$valide->fetchColumn(),
    ];
}

$creneaux = $pdo->prepare(
    "SELECT c.id AS creneau_id, c.heure_debut, c.heure_fin, c.salle,
            cl.nom_affichage AS classe, m.nom AS matiere,
            s.id AS seance_id, s.statut_validation, s.contenu_texte
     FROM creneau c
     JOIN classe cl ON cl.id = c.classe_id
     JOIN matiere m ON m.id = c.matiere_id
     LEFT JOIN seance s ON s.creneau_id = c.id AND s.date = ?
     WHERE c.etablissement_id = ? AND c.prof_id = ? AND c.jour_semaine = ?
     ORDER BY c.heure_debut ASC"
);
$creneaux->execute([$date_aff, $etab_id, $user_id, $jour_semaine]);
$creneaux = $creneaux->fetchAll();

$nb_seances = count($creneaux);
$nb_faites = 0;
$nb_validees = 0;
foreach ($creneaux as $c) {
    if ($c['seance_id']) $nb_faites++;
    if ($c['statut_validation'] === 'validee' || $c['statut_validation'] === 'forcee_censeur') $nb_validees++;
}

$aujourdhui = date('Y-m-d');
$hier = date('Y-m-d', strtotime('-1 day'));
$demain = date('Y-m-d', strtotime('+1 day'));
$est_ajd = $date_aff === $aujourdhui;

$historique = $pdo->prepare(
    "SELECT s.date, s.contenu_texte, s.statut_validation, cr.heure_debut, cr.heure_fin, c.nom_affichage AS classe, m.nom AS matiere
     FROM seance s
     JOIN creneau cr ON cr.id = s.creneau_id
     JOIN classe c ON c.id = cr.classe_id
     JOIN matiere m ON m.id = cr.matiere_id
     WHERE cr.etablissement_id = ? AND cr.prof_id = ? AND s.date < ?
     ORDER BY s.date DESC, cr.heure_debut DESC
     LIMIT 15"
);
$historique->execute([$etab_id, $user_id, $aujourdhui]);
$historique = $historique->fetchAll();

$a_venir = $pdo->prepare(
    "SELECT cr.heure_debut, cr.heure_fin, c.nom_affichage AS classe, m.nom AS matiere, cr.salle,
            cr.jour_semaine
     FROM creneau cr
     JOIN classe c ON c.id = cr.classe_id
     JOIN matiere m ON m.id = cr.matiere_id
     WHERE cr.etablissement_id = ? AND cr.prof_id = ? AND cr.jour_semaine > ?
     ORDER BY cr.jour_semaine, cr.heure_debut
     LIMIT 10"
);
$a_venir->execute([$etab_id, $user_id, $jour_semaine]);
$a_venir = $a_venir->fetchAll();

$jours_nom = ['', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

$titre = 'Mon tableau de bord';
require_once __DIR__ . '/../inc/header.php';
?>
<div class="page-header">
    <div>
        <h1>Bonjour, <?= htmlspecialchars($_SESSION['nom_complet']) ?></h1>
        <p><?= date('l d F Y', strtotime($date_aff)) ?></p>
    </div>
</div>

<div class="stats" style="grid-template-columns: repeat(3, 1fr);">
    <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-calendar-day"></i></div>
        <div class="stat-value"><?= $nb_seances ?></div>
        <div class="stat-label">Séances <?= $est_ajd ? "aujourd'hui" : 'ce jour' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-value"><?= $nb_faites ?></div>
        <div class="stat-label">Cahiers remplis</div>
        <div class="stat-footer"><?= $nb_validees ?> validé<?= $nb_validees > 1 ? 'es' : 'e' ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-value"><?= $nb_seances - $nb_faites ?></div>
        <div class="stat-label">En attente</div>
    </div>
</div>

<?php if (!empty($classes_prof)): ?>
<div class="card" style="margin-bottom:16px;">
    <div class="card-body" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <span style="font-size:0.82rem;color:var(--text-muted);">Mes classes :</span>
        <?php foreach ($stats_classes as $sc): ?>
        <span class="badge badge-info" style="display:flex;align-items:center;gap:6px;">
            <?= htmlspecialchars($sc['nom']) ?>
            <span style="font-weight:400;opacity:0.7;"><?= $sc['fait'] ?>/<?= $sc['total'] ?></span>
            <?php if ($sc['fait'] > 0): ?>
            <span style="color:var(--statut-present);font-weight:600;"><?= round(($sc['valide']/$sc['fait'])*100) ?>%</span>
            <?php endif; ?>
        </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-clock" style="color:var(--accent);margin-right:8px;"></i> Emploi du temps — <?= $jours_nom[$jour_semaine] ?></h2>
        <div style="display:flex;gap:6px;">
            <a href="?date=<?= date('Y-m-d', strtotime($date_aff . ' -1 day')) ?>" class="btn btn-sm btn-outline"><i class="fas fa-chevron-left"></i></a>
            <?php if (!$est_ajd): ?>
            <a href="?date=<?= $aujourdhui ?>" class="btn btn-sm btn-accent"><i class="fas fa-calendar-day"></i> Aujourd'hui</a>
            <?php endif; ?>
            <a href="?date=<?= date('Y-m-d', strtotime($date_aff . ' +1 day')) ?>" class="btn btn-sm btn-outline"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
    <?php if (empty($creneaux)): ?>
    <div class="card-body">
        <div class="empty"><p>Aucune séance prévue ce jour.</p></div>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Horaire</th>
                    <th>Classe</th>
                    <th>matière</th>
                    <th>Salle</th>
                    <th>Cahier de texte</th>
                    <th>Appel</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($creneaux as $c): ?>
                <tr>
                    <td><span class="badge badge-neutral"><?= substr($c['heure_debut'], 0, 5) ?>-<?= substr($c['heure_fin'], 0, 5) ?></span></td>
                    <td><strong><?= htmlspecialchars($c['classe']) ?></strong></td>
                    <td><?= htmlspecialchars($c['matiere']) ?></td>
                    <td><?= htmlspecialchars($c['salle'] ?? '—') ?></td>
                    <td>
                        <?php if ($c['seance_id'] && $c['contenu_texte']): ?>
                            <span class="badge badge-success"><i class="fas fa-check" style="margin-right:3px;"></i> Rempli</span>
                        <?php else: ?>
                            <button class="btn btn-sm btn-accent" onclick="var r=document.getElementById('form-<?= $c['creneau_id'] ?>');r.style.display=r.style.display==='none'?'table-row':'none';">
                                <i class="fas fa-pen"></i> Remplir
                            </button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['seance_id']): ?>
                            <a href="appel.php?seance_id=<?= $c['seance_id'] ?>" class="btn btn-sm btn-outline">
                                <i class="fas fa-clipboard-user"></i> Appel
                            </a>
                        <?php else: ?>
                            <span class="badge badge-neutral">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['statut_validation'] === 'validee'): ?>
                            <span class="badge badge-success"><i class="fas fa-check-circle"></i> Validé</span>
                        <?php elseif ($c['statut_validation'] === 'forcee_censeur'): ?>
                            <span class="badge badge-info"><i class="fas fa-lock"></i> Forcé</span>
                        <?php elseif ($c['seance_id']): ?>
                            <span class="badge badge-warning"><i class="fas fa-clock"></i> En attente</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">À faire</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr id="form-<?= $c['creneau_id'] ?>" style="display:none;">
                    <td colspan="7" style="background:var(--surface-hover); padding:16px 24px;">
                        <form method="post" action="save_seance.php" style="display:flex; gap:10px; align-items:end;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="creneau_id" value="<?= $c['creneau_id'] ?>">
                            <input type="hidden" name="date" value="<?= $date_aff ?>">
                            <div class="form-group" style="flex:1;">
                                <label>Contenu du cahier de texte</label>
                                <textarea name="contenu_texte" rows="2" style="padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:var(--font);font-size:0.9rem;background:var(--surface);color:var(--text);width:100%;resize:vertical;" placeholder="Décrivez le déroulé de la séance..."><?= htmlspecialchars($c['contenu_texte'] ?? '') ?></textarea>
                            </div>
                            <button type="submit" class="btn btn-accent btn-sm"><i class="fas fa-save"></i> Enregistrer</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($a_venir)): ?>
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-calendar-week" style="color:var(--statut-info);margin-right:8px;"></i> Prochaines séances</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Jour</th><th>Horaire</th><th>Classe</th><th>matière</th><th>Salle</th></tr></thead>
            <tbody>
                <?php foreach ($a_venir as $v): ?>
                <tr>
                    <td><strong><?= $jours_nom[$v['jour_semaine']] ?></strong></td>
                    <td><span class="badge badge-neutral"><?= substr($v['heure_debut'], 0, 5) ?>-<?= substr($v['heure_fin'], 0, 5) ?></span></td>
                    <td><?= htmlspecialchars($v['classe']) ?></td>
                    <td><?= htmlspecialchars($v['matiere']) ?></td>
                    <td><?= htmlspecialchars($v['salle'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($historique)): ?>
<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-history" style="color:var(--text-muted);margin-right:8px;"></i> Séances passées</h2>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Classe</th><th>matière</th><th>Horaire</th><th>Contenu</th><th>Statut</th></tr></thead>
            <tbody>
                <?php foreach ($historique as $h): ?>
                <tr>
                    <td><span class="badge badge-neutral"><?= date('d/m', strtotime($h['date'])) ?></span></td>
                    <td><?= htmlspecialchars($h['classe']) ?></td>
                    <td><?= htmlspecialchars($h['matiere']) ?></td>
                    <td><?= substr($h['heure_debut'], 0, 5) ?>-<?= substr($h['heure_fin'], 0, 5) ?></td>
                    <td style="max-width:200px;font-size:0.82rem;color:var(--text-secondary);"><?= htmlspecialchars(mb_substr($h['contenu_texte'] ?? '', 0, 60)) ?><?= mb_strlen($h['contenu_texte'] ?? '') > 60 ? '...' : '' ?></td>
                    <td>
                        <?php if ($h['statut_validation'] === 'validee'): ?>
                            <span class="badge badge-success">Validé</span>
                        <?php elseif ($h['statut_validation'] === 'forcee_censeur'): ?>
                            <span class="badge badge-info">Forcé</span>
                        <?php else: ?>
                            <span class="badge badge-warning">En attente</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
