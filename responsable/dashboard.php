<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/../backend/config/database.php';

$pdo = get_db();
$user_id = $_SESSION['utilisateur_id'];
$etab_id = $_SESSION['etablissement_id'];
require_csrf('dashboard.php');

$classes = $pdo->prepare(
    "SELECT cl.id, cl.nom_affichage
     FROM responsable_classe rc
     JOIN classe cl ON cl.id = rc.classe_id
     WHERE rc.utilisateur_id = ? AND rc.statut = 'actif'"
);
$classes->execute([$user_id]);
$classes = $classes->fetchAll();
$classes_ids = array_column($classes, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['valider_seance'])) {
    $seance_id = (int)$_POST['valider_seance'];
    $stmt = $pdo->prepare(
        "SELECT rc.id AS responsable_id
         FROM seance s
         JOIN creneau c ON c.id = s.creneau_id
         JOIN responsable_classe rc ON rc.classe_id = c.classe_id AND rc.statut = 'actif'
         WHERE s.id = ? AND c.etablissement_id = ? AND rc.utilisateur_id = ?"
    );
    $stmt->execute([$seance_id, $etab_id, $user_id]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->prepare("UPDATE seance SET statut_validation = 'validee', valide_par = ?, valide_le = NOW() WHERE id = ?")
            ->execute([$row['responsable_id'], $seance_id]);
        $_SESSION['_flash_succes'] = 'Séance validée.';
    } else {
        $_SESSION['_flash_erreur'] = 'Vous n\'êtes pas habilité à valider cette séance.';
    }
    header('Location: dashboard.php');
    exit;
}

$en_attente = [];
if (!empty($classes_ids)) {
    $placeholders = implode(',', array_fill(0, count($classes_ids), '?'));
    $params = array_merge([$etab_id], $classes_ids);
    $stmt = $pdo->prepare(
        "SELECT s.id AS seance_id, s.date, m.nom AS matiere, s.contenu_texte,
                cr.heure_debut, cr.heure_fin, cl.nom_affichage AS classe_nom,
                u.prenom AS prof_prenom, u.nom AS prof_nom
         FROM seance s
         JOIN creneau cr ON cr.id = s.creneau_id
         JOIN matiere m ON m.id = cr.matiere_id
         JOIN classe cl ON cl.id = cr.classe_id
         JOIN utilisateur u ON u.id = cr.prof_id
         WHERE cr.etablissement_id = ? AND cr.classe_id IN ($placeholders)
           AND s.statut_validation = 'en_attente'
         ORDER BY s.date DESC, cr.heure_debut ASC
         LIMIT 30"
    );
    $stmt->execute($params);
    $en_attente = $stmt->fetchAll();
}

$validees_recentes = [];
if (!empty($classes_ids)) {
    $placeholders = implode(',', array_fill(0, count($classes_ids), '?'));
    $params = array_merge([$etab_id], $classes_ids);
    $stmt = $pdo->prepare(
        "SELECT s.date, m.nom AS matiere, cl.nom_affichage AS classe_nom,
                cr.heure_debut, cr.heure_fin, s.valide_le
         FROM seance s
         JOIN creneau cr ON cr.id = s.creneau_id
         JOIN matiere m ON m.id = cr.matiere_id
         JOIN classe cl ON cl.id = cr.classe_id
         WHERE cr.etablissement_id = ? AND cr.classe_id IN ($placeholders)
           AND s.statut_validation IN ('validee', 'forcee_censeur')
         ORDER BY s.valide_le DESC"
    );
    $stmt->execute($params);
    $validees_recentes = $stmt->fetchAll();
}

$alerte_jours = 3;
$en_retard = [];
if (!empty($classes_ids)) {
    $placeholders = implode(',', array_fill(0, count($classes_ids), '?'));
    $params = array_merge([$etab_id, $alerte_jours], $classes_ids);
    $stmt = $pdo->prepare(
        "SELECT s.id AS seance_id, s.date, m.nom AS matiere, cl.nom_affichage AS classe_nom,
                cr.heure_debut, u.prenom AS prof_prenom, u.nom AS prof_nom,
                DATEDIFF(CURDATE(), s.date) AS jours_attente
         FROM seance s
         JOIN creneau cr ON cr.id = s.creneau_id
         JOIN matiere m ON m.id = cr.matiere_id
         JOIN classe cl ON cl.id = cr.classe_id
         JOIN utilisateur u ON u.id = cr.prof_id
         WHERE cr.etablissement_id = ? AND cr.classe_id IN ($placeholders)
           AND s.statut_validation = 'en_attente'
           AND s.date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         ORDER BY s.date ASC"
    );
    $stmt->execute($params);
    $en_retard = $stmt->fetchAll();
}

$titre = 'Validations';
require_once __DIR__ . '/../inc/header.php';
?>
<div class="page-header">
    <div>
        <h1><i class="fas fa-clipboard-check" style="color:var(--accent);margin-right:8px;"></i> Validations</h1>
        <p>Bienvenue, <?= htmlspecialchars($_SESSION['nom_complet']) ?></p>
    </div>
</div>

<div class="stats" style="grid-template-columns: repeat(2, 1fr);">
    <div class="stat-card">
        <div class="stat-icon amber"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-value"><?= count($en_attente) ?></div>
        <div class="stat-label">Séances à valider</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-check-double"></i></div>
        <div class="stat-value"><?= count($validees_recentes) ?></div>
        <div class="stat-label">Validations</div>
    </div>
</div>

<?php if (!empty($en_retard)): ?>
<div class="alert alert-error" style="margin-bottom:16px;">
    <i class="fas fa-exclamation-triangle"></i>
    <strong><?= count($en_retard) ?> séance(s) en attente depuis plus de <?= $alerte_jours ?> jours !</strong>
    Pensez à les valider ou à contacter le censeur.
</div>
<?php endif; ?>

<?php if (!empty($classes)): ?>
<div class="card" style="margin-bottom:8px;">
    <div class="card-body" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <span style="font-size:0.82rem;color:var(--text-muted);">Ma classe :</span>
        <?php foreach ($classes as $cl): ?>
            <span class="badge badge-info"><i class="fas fa-school"></i> <?= htmlspecialchars($cl['nom_affichage']) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($en_retard)): ?>
<div class="card" style="border-left:4px solid var(--statut-danger);">
    <div class="card-header">
        <h2><i class="fas fa-clock" style="color:var(--statut-danger);margin-right:8px;"></i> Séances en retard</h2>
        <span class="badge badge-danger"><?= count($en_retard) ?> en retard</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Classe</th><th>matière</th><th>Professeur</th><th>Jours d'attente</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($en_retard as $s): ?>
                <tr>
                    <td><span class="badge badge-neutral"><?= date('d/m', strtotime($s['date'])) ?></span></td>
                    <td><strong><?= htmlspecialchars($s['classe_nom']) ?></strong></td>
                    <td><?= htmlspecialchars($s['matiere']) ?></td>
                    <td><?= htmlspecialchars($s['prof_prenom'] . ' ' . $s['prof_nom']) ?></td>
                    <td><span class="badge badge-danger"><?= $s['jours_attente'] ?> jours</span></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Valider cette séance ?')">
                            <?= csrf_field() ?>
                            <button type="submit" name="valider_seance" value="<?= $s['seance_id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Valider</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-clock" style="color:var(--statut-alerte);margin-right:8px;"></i> Séances à valider</h2>
        <?php if (count($en_attente) > 0): ?>
        <span class="badge badge-warning"><?= count($en_attente) ?> en attente</span>
        <?php endif; ?>
    </div>
    <?php if (empty($en_attente)): ?>
    <div class="card-body">
        <div class="empty"><p><i class="fas fa-check-circle" style="font-size:2rem;display:block;margin-bottom:8px;color:var(--statut-present);"></i>Tout est à jour ! Aucune séance en attente de validation.</p></div>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Classe</th>
                    <th>matière</th>
                    <th>Professeur</th>
                    <th>Horaire</th>
                    <th>Contenu</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($en_attente as $s): ?>
                <tr>
                    <td><span class="badge badge-neutral"><?= date('d/m', strtotime($s['date'])) ?></span></td>
                    <td><strong><?= htmlspecialchars($s['classe_nom']) ?></strong></td>
                    <td><?= htmlspecialchars($s['matiere']) ?></td>
                    <td><?= htmlspecialchars($s['prof_prenom'] . ' ' . $s['prof_nom']) ?></td>
                    <td><?= substr($s['heure_debut'], 0, 5) ?>-<?= substr($s['heure_fin'], 0, 5) ?></td>
                    <td style="max-width:200px;">
                        <span style="font-size:0.82rem;color:var(--text-secondary);">
                            <?= htmlspecialchars(mb_substr($s['contenu_texte'] ?? '', 0, 80)) ?>
                            <?= mb_strlen($s['contenu_texte'] ?? '') > 80 ? '...' : '' ?>
                        </span>
                    </td>
                    <td>
                        <form method="post" onsubmit="return confirm('Valider cette séance ?')">
                            <?= csrf_field() ?>
                            <button type="submit" name="valider_seance" value="<?= $s['seance_id'] ?>" class="btn btn-sm btn-success">
                                <i class="fas fa-check"></i> Valider
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header">
        <h2><i class="fas fa-history" style="color:var(--statut-info);margin-right:8px;"></i> Historique des validations</h2>
        <?php $total_val = count($validees_recentes); if ($total_val > 0): ?><span class="badge badge-success"><?= $total_val ?> validation(s)</span><?php endif; ?>
    </div>
    <?php if (empty($validees_recentes)): ?>
    <div class="card-body">
        <div class="empty"><p>Aucune validation pour l'instant.</p></div>
    </div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Classe</th>
                    <th>matière</th>
                    <th>Horaire</th>
                    <th>Validée le</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($validees_recentes as $s): ?>
                <tr>
                    <td><span class="badge badge-neutral"><?= date('d/m', strtotime($s['date'])) ?></span></td>
                    <td><strong><?= htmlspecialchars($s['classe_nom']) ?></strong></td>
                    <td><?= htmlspecialchars($s['matiere']) ?></td>
                    <td><?= substr($s['heure_debut'], 0, 5) ?>-<?= substr($s['heure_fin'], 0, 5) ?></td>
                    <td><span class="badge badge-success"><i class="fas fa-check-circle"></i> <?= date('d/m H:i', strtotime($s['valide_le'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
