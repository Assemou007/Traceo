<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_web_login(['censeur', 'surveillant']);

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];
$erreur = null; $succes = null;
require_csrf();

$classe_filtre = (int)($_GET['classe_id'] ?? 0);
if ($classe_filtre && !verifier_ressource($pdo, 'classe', $classe_filtre, $etab_id)) {
    $classe_filtre = 0;
}
$mode_tous = isset($_GET['tous']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'creer') {
    $eleve_id = (int)($_POST['eleve_id'] ?? 0);
    $motif = trim($_POST['motif'] ?? '');
    if (!$eleve_id || !$motif) $erreur = 'Élève et motif requis.';
    elseif (!verifier_ressource($pdo, 'eleve', $eleve_id, $etab_id)) {
        $erreur = 'Élève invalide pour cet établissement.';
    }
    else {
        $config = $pdo->prepare("SELECT duree_punition_custom_jours, seuil_recidive_punition, fenetre_recidive_jours, (SELECT duree_defaut_punition_jours FROM pays WHERE id = etablissement.pays_id) AS duree_pays FROM etablissement WHERE id = ?");
        $config->execute([$etab_id]);
        $config = $config->fetch();
        $duree = $config['duree_punition_custom_jours'] ?? $config['duree_pays'];
        $pdo->prepare("INSERT INTO punition (eleve_id, gere_par, motif, date_attribution, duree_jours, statut) VALUES (?, ?, ?, CURDATE(), ?, 'a_faire')")
            ->execute([$eleve_id, $_SESSION['utilisateur_id'], $motif, $duree]);

        $count = $pdo->prepare("SELECT COUNT(*) FROM punition WHERE eleve_id = ? AND date_attribution >= DATE_SUB(CURDATE(), INTERVAL ? DAY)");
        $count->execute([$eleve_id, (int)$config['fenetre_recidive_jours']]);
        $nb = (int)$count->fetchColumn();
        $succes = 'Punition enregistrée.' . ($nb >= (int)$config['seuil_recidive_punition'] ? ' Seuil de récidive atteint — notification créée.' : '');

        if ($nb >= (int)$config['seuil_recidive_punition']) {
            $contact = $pdo->prepare("SELECT contact_parent FROM eleve WHERE id = ?");
            $contact->execute([$eleve_id]);
            $c = $contact->fetchColumn();
            if ($c) $pdo->prepare("INSERT INTO notification (etablissement_id, type, destinataire_contact, canal, contenu) VALUES (?, 'recidive_punition', ?, 'sms', ?)")
                ->execute([$etab_id, $c, "Alert: votre enfant a reçu $nb punitions récemment."]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'marquer_faite') {
    $pdo->prepare("UPDATE punition SET statut = 'faite', date_faite = CURDATE() WHERE id = ? AND eleve_id IN (SELECT id FROM eleve WHERE etablissement_id = ?)")
        ->execute([(int)($_POST['punition_id'] ?? 0), $etab_id]);
    $succes = 'Punition marquée faite.';
}

$classes = $pdo->prepare("SELECT id, nom_affichage FROM classe WHERE etablissement_id = ? ORDER BY nom_affichage");
$classes->execute([$etab_id]);
$classes = $classes->fetchAll();

$clause_classe = '';
$params = [$etab_id];
if ($classe_filtre) {
    $clause_classe = 'AND e.classe_id = ?';
    $params[] = $classe_filtre;
}

if ($mode_tous) {
    $eleves = $pdo->prepare("SELECT e.id, e.nom, e.prenom, c.nom_affichage AS classe_nom FROM eleve e JOIN classe c ON c.id = e.classe_id WHERE e.etablissement_id = ? AND e.statut = 'actif' $clause_classe ORDER BY c.nom_affichage, e.nom");
    $eleves->execute($params);
} else {
    $eleves = $pdo->prepare("SELECT DISTINCT e.id, e.nom, e.prenom, c.nom_affichage AS classe_nom FROM eleve e JOIN classe c ON c.id = e.classe_id WHERE e.etablissement_id = ? AND e.statut = 'actif' $clause_classe AND (e.id IN (SELECT p.eleve_id FROM presence p JOIN seance s ON s.id = p.seance_id WHERE s.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) OR e.id IN (SELECT pu.eleve_id FROM punition pu)) ORDER BY c.nom_affichage, e.nom");
    $eleves->execute($params);
}
$eleves = $eleves->fetchAll();

$histo = null;
if (!empty($_GET['histo'])) {
    $hid = (int)$_GET['histo'];
    $h_eleve = $pdo->prepare("SELECT prenom, nom FROM eleve WHERE id = ? AND etablissement_id = ?");
    $h_eleve->execute([$hid, $etab_id]);
    $h_eleve = $h_eleve->fetch();
    if ($h_eleve) {
        $histo = $pdo->prepare("SELECT 'absence' AS type, p.statut, s.date, cr.heure_debut FROM presence p JOIN seance s ON s.id = p.seance_id JOIN creneau cr ON cr.id = s.creneau_id WHERE p.eleve_id = ? AND s.date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) UNION ALL SELECT 'punition' AS type, p.statut, p.date_attribution AS date, NULL AS heure_debut FROM punition p WHERE p.eleve_id = ? ORDER BY date DESC LIMIT 15");
        $histo->execute([$hid, $hid]);
        $histo = $histo->fetchAll();
    }
}

$count_punitions = $pdo->prepare("SELECT COUNT(*) FROM punition p JOIN eleve e ON e.id = p.eleve_id WHERE e.etablissement_id = ?");
$count_punitions->execute([$etab_id]);
$total_punitions = (int)$count_punitions->fetchColumn();

$par_page = 50;
$page_punitions = max(1, (int)($_GET['page_punitions'] ?? 1));
$nb_pages_punitions = max(1, (int)ceil($total_punitions / $par_page));
$page_punitions = min($page_punitions, $nb_pages_punitions);
$offset_punitions = ($page_punitions - 1) * $par_page;

$punitions = $pdo->prepare("SELECT p.id, p.motif, p.date_attribution, p.duree_jours, p.statut, e.nom, e.prenom, c.nom_affichage AS classe_nom FROM punition p JOIN eleve e ON e.id = p.eleve_id JOIN classe c ON c.id = e.classe_id WHERE e.etablissement_id = ? ORDER BY p.date_attribution DESC LIMIT $par_page OFFSET $offset_punitions");
$punitions->execute([$etab_id]);
$punitions = $punitions->fetchAll();

$titre = 'Punitions';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Punitions</h1>
        <p><?= count($eleves) ?> élève(s) concerné(s) · <?= $total_punitions ?> punition(s)</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Attribuer une punition</h2></div>
    <div class="card-body">
        <form method="get" class="form-row" style="margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--border);">
            <div class="form-group"><label>Filtrer par classe</label>
                <select name="classe_id" onchange="this.form.submit()">
                    <option value="">— Toutes les classes —</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $classe_filtre === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom_affichage']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="align-self:flex-end;">
                <label>&nbsp;</label>
                <label style="display:flex;align-items:center;gap:6px;font-size:0.85rem;cursor:pointer;">
                    <input type="checkbox" name="tous" value="1" <?= $mode_tous ? 'checked' : '' ?> onchange="this.form.submit()">
                    Voir tous les élèves
                </label>
            </div>
            <?php if ($classe_filtre): ?>
            <input type="hidden" name="classe_id" value="<?= $classe_filtre ?>">
            <?php endif; ?>
        </form>
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="creer">
            <div class="form-group"><label><?= $mode_tous ? 'Élève' : 'Élève en défaut' ?></label>
                <select name="eleve_id" required>
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($eleves as $e): ?>
                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom'] . ' (' . $e['classe_nom'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;"><label>Motif</label><input type="text" name="motif" required></div>
            <button type="submit" class="btn">Attribuer</button>
        </form>
        <?php if (!$mode_tous): ?>
        <p style="font-size:0.78rem;color:var(--text-muted);margin-top:8px;">
            <i class="fas fa-info-circle"></i> Seuls les élèves avec absences/retards (30 jours) ou punitions existantes sont listés.
            <a href="?<?= $classe_filtre ? 'classe_id='.$classe_filtre.'&' : '' ?>tous=1" style="font-weight:600;">Voir tous les élèves</a>
        </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($histo !== null && !empty($h_eleve)): ?>
<div class="card">
    <div class="card-header">
        <h2>Historique — <?= htmlspecialchars($h_eleve['prenom'] . ' ' . $h_eleve['nom']) ?></h2>
        <a href="punitions.php?classe_id=<?= $classe_filtre ?><?= $mode_tous ? '&tous=1' : '' ?>" class="btn btn-sm btn-outline"><i class="fas fa-times"></i> Fermer</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Date</th><th>Type</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($histo as $h): ?>
                <tr>
                    <td><?= htmlspecialchars($h['date']) ?></td>
                    <td><span class="badge <?= $h['type'] === 'absence' ? 'badge-danger' : 'badge-warning' ?>"><?= $h['type'] === 'absence' ? 'Absence/Retard' : 'Punition' ?></span></td>
                    <td><?= htmlspecialchars($h['statut']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2>Suivi des punitions</h2></div>
    <?php if ($punitions): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Élève</th><th>Classe</th><th>Motif</th><th>Date</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($punitions as $p): ?>
                <tr>
                    <td><a href="?histo=<?= $p['id'] ?>&classe_id=<?= $classe_filtre ?><?= $mode_tous ? '&tous=1' : '' ?>" style="font-weight:600;"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></a></td>
                    <td><?= htmlspecialchars($p['classe_nom']) ?></td>
                    <td><?= htmlspecialchars($p['motif']) ?></td>
                    <td><?= htmlspecialchars($p['date_attribution']) ?></td>
                    <td><span class="badge <?= $p['statut'] === 'faite' ? 'badge-success' : 'badge-warning' ?>"><?= $p['statut'] === 'faite' ? 'Faite' : 'À faire' ?></span></td>
                    <td><?php if ($p['statut'] === 'a_faire'): ?>
                        <form method="post" style="margin:0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="marquer_faite">
                            <input type="hidden" name="punition_id" value="<?= $p['id'] ?>">
                            <button class="btn btn-sm btn-success">Marquer faite</button>
                        </form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucune punition enregistrée.</p></div></div>
    <?php endif; ?>
    <?php if ($nb_pages_punitions > 1): ?>
    <div class="pagination">
        <?php $base_q = '?' . ($classe_filtre ? 'classe_id=' . $classe_filtre . '&' : '') . ($mode_tous ? 'tous=1&' : ''); ?>
        <?php if ($page_punitions > 1): ?><a href="<?= $base_q ?>page_punitions=<?= $page_punitions - 1 ?>" class="btn btn-sm btn-outline"><i class="fas fa-chevron-left"></i> Précédent</a><?php endif; ?>
        <span class="badge badge-neutral">Page <?= $page_punitions ?> / <?= $nb_pages_punitions ?></span>
        <?php if ($page_punitions < $nb_pages_punitions): ?><a href="<?= $base_q ?>page_punitions=<?= $page_punitions + 1 ?>" class="btn btn-sm btn-outline">Suivant <i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
