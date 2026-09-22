<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_web_login(['censeur']);

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];
$erreur = null; $succes = null;
require_csrf();

$annee = $pdo->prepare("SELECT id, libelle FROM annee_scolaire WHERE etablissement_id = ? AND statut = 'en_cours' LIMIT 1");
$annee->execute([$etab_id]);
$annee = $annee->fetch();
$annee_id = $annee['id'];
$annee_libelle = $annee['libelle'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'passer') {
        $eleve_id = (int)($_POST['eleve_id'] ?? 0);
        $nouvelle_classe = (int)($_POST['nouvelle_classe'] ?? 0);
        if (!verifier_ressource($pdo, 'classe', $nouvelle_classe, $etab_id)) {
            $erreur = 'Classe de destination invalide pour cet établissement.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO eleve (etablissement_id, classe_id, nom, prenom, contact_parent, statut, eleve_precedent_id) SELECT etablissement_id, ?, nom, prenom, contact_parent, 'actif', id FROM eleve WHERE id = ? AND etablissement_id = ?")
                    ->execute([$nouvelle_classe, $eleve_id, $etab_id]);
                $nouvel_id = $pdo->lastInsertId();
                $pdo->prepare("UPDATE eleve SET statut = 'passe' WHERE id = ? AND etablissement_id = ?")->execute([$eleve_id, $etab_id]);
                $pdo->commit();
                $succes = 'Élève passé en classe supérieure.';
            } catch (Exception $e) { $pdo->rollBack(); $erreur = 'Erreur lors du passage.'; }
        }
    }
    if ($action === 'redoubler') {
        $pdo->prepare("UPDATE eleve SET statut = 'redouble' WHERE id = ? AND etablissement_id = ?")->execute([(int)($_POST['eleve_id'] ?? 0), $etab_id]);
        $succes = 'Élève marqué comme redoublant.';
    }
    if ($action === 'archiver_annee') {
        $pdo->prepare("UPDATE annee_scolaire SET statut = 'archivee' WHERE id = ? AND etablissement_id = ?")->execute([$annee_id, $etab_id]);
        $succes = 'Année archivée.';
    }
    if ($action === 'nouvelle_annee') {
        $d = (int)date('Y');
        $pdo->prepare("INSERT INTO annee_scolaire (etablissement_id, libelle, date_debut, date_fin, statut) VALUES (?, ?, ?, ?, 'en_cours')")
            ->execute([$etab_id, $d . '-' . ($d+1), "$d-09-01", ($d+1)."-07-31"]);
        $succes = 'Nouvelle année créée.';
    }
}

$classes = $pdo->prepare("SELECT id, nom_affichage, niveau FROM classe WHERE etablissement_id = ? AND annee_scolaire_id = ? ORDER BY niveau, nom_affichage");
$classes->execute([$etab_id, $annee_id]);
$classes = $classes->fetchAll();

$eleves = $pdo->prepare("SELECT e.id, e.nom, e.prenom, e.statut, c.nom_affichage, c.niveau FROM eleve e JOIN classe c ON c.id = e.classe_id WHERE e.etablissement_id = ? AND e.statut IN ('actif', 'redouble') ORDER BY c.nom_affichage, e.nom");
$eleves->execute([$etab_id]);
$eleves = $eleves->fetchAll();

$niveaux_ordre = ['6e','5e','4e','3e','2nde','1ere','Tle'];
function niveau_suivant($n) { global $niveaux_ordre; $i = array_search($n, $niveaux_ordre); return ($i !== false && isset($niveaux_ordre[$i+1])) ? $niveaux_ordre[$i+1] : null; }

$titre = 'Transition annuelle';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Transition annuelle</h1>
        <p>Année : <strong><?= htmlspecialchars($annee_libelle) ?></strong></p>
    </div>
    <div class="page-actions">
        <form method="post" style="margin:0;" onsubmit="return confirm('Archiver <?= htmlspecialchars($annee_libelle) ?> ?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="archiver_annee">
            <button class="btn btn-outline">Archiver l'année</button>
        </form>
        <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="nouvelle_annee">
            <button class="btn btn-accent">Nouvelle année</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Élèves à traiter</h2><span class="badge badge-info"><?= count($eleves) ?> élève(s)</span></div>
    <?php if ($eleves): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Élève</th><th>Classe</th><th>Statut</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($eleves as $e): ?>
                <tr>
                    <td><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?></td>
                    <td><?= htmlspecialchars($e['nom_affichage']) ?></td>
                    <td><span class="badge <?= $e['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>"><?= $e['statut'] === 'actif' ? 'Actif' : 'Redoublant' ?></span></td>
                    <td>
                        <?php if ($e['statut'] === 'actif'): $ns = niveau_suivant($e['niveau']); ?>
                            <form method="post" style="display:flex;gap:4px;flex-wrap:wrap;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="passer">
                                <input type="hidden" name="eleve_id" value="<?= $e['id'] ?>">
                                <select name="nouvelle_classe" style="padding:5px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:0.85rem;">
                                    <option value="">→ Classe</option>
                                    <?php foreach ($classes as $c): if (!$ns || $c['niveau'] === $ns): ?>
                                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nom_affichage']) ?></option>
                                    <?php endif; endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-success">Passer</button>
                            </form>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Redoubler ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="redoubler">
                                <input type="hidden" name="eleve_id" value="<?= $e['id'] ?>">
                                <button class="btn btn-sm btn-danger">Redoubler</button>
                            </form>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:0.85rem;">Redouble déjà</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucun élève à traiter.</p></div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
