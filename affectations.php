<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_web_login(['censeur']);

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];
$erreur = null; $succes = null;
require_csrf();

$annee = $pdo->prepare("SELECT id FROM annee_scolaire WHERE etablissement_id = ? AND statut = 'en_cours' LIMIT 1");
$annee->execute([$etab_id]);
$annee_id = $annee->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'affecter') {
    $prof_id = (int)($_POST['prof_id'] ?? 0);
    $classe_id = (int)($_POST['classe_id'] ?? 0);
    $matiere_id = (int)($_POST['matiere_id'] ?? 0);
    if (!$prof_id || !$classe_id || !$matiere_id) $erreur = 'Tous les champs sont requis.';
    elseif (!verifier_ressource($pdo, 'classe', $classe_id, $etab_id) || !verifier_ressource($pdo, 'matiere', $matiere_id, $etab_id)) {
        $erreur = 'Classe ou matière invalide pour cet établissement.';
    } else {
        $check_prof = $pdo->prepare("SELECT id FROM utilisateur WHERE id = ? AND etablissement_id = ? AND role = 'prof' AND statut = 'actif'");
        $check_prof->execute([$prof_id, $etab_id]);
        if (!$check_prof->fetch()) {
            $erreur = 'Professeur invalide pour cet établissement.';
        } else {
            $pdo->prepare("INSERT IGNORE INTO prof_matiere_classe (utilisateur_id, classe_id, matiere_id, annee_scolaire_id) VALUES (?, ?, ?, ?)")
                ->execute([$prof_id, $classe_id, $matiere_id, $annee_id]);
            $succes = 'Affectation enregistrée.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'retirer') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM prof_matiere_classe WHERE id = ? AND utilisateur_id IN (SELECT id FROM utilisateur WHERE etablissement_id = ?)")
        ->execute([$id, $etab_id]);
    $succes = 'Affectation retirée.';
}

$profs = $pdo->prepare("SELECT id, nom, prenom FROM utilisateur WHERE etablissement_id = ? AND role = 'prof' AND statut = 'actif' ORDER BY nom");
$profs->execute([$etab_id]); $profs = $profs->fetchAll();
$classes = $pdo->prepare("SELECT id, nom_affichage FROM classe WHERE etablissement_id = ? AND annee_scolaire_id = ? ORDER BY nom_affichage");
$classes->execute([$etab_id, $annee_id]); $classes = $classes->fetchAll();
$matieres = $pdo->prepare("SELECT id, nom FROM matiere WHERE etablissement_id = ? ORDER BY nom");
$matieres->execute([$etab_id]); $matieres = $matieres->fetchAll();

$affectations = $pdo->prepare("SELECT pmc.id, u.prenom AS p_prenom, u.nom AS p_nom, c.nom_affichage, m.nom AS matiere FROM prof_matiere_classe pmc JOIN utilisateur u ON u.id = pmc.utilisateur_id JOIN classe c ON c.id = pmc.classe_id JOIN matiere m ON m.id = pmc.matiere_id WHERE u.etablissement_id = ? AND pmc.annee_scolaire_id = ? ORDER BY u.nom, c.nom_affichage");
$affectations->execute([$etab_id, $annee_id]);
$affectations = $affectations->fetchAll();

$titre = 'Affectations';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Affectations profs → classes</h1>
        <p><?= count($affectations) ?> affectation(s) · Aussi créées automatiquement via l'emploi du temps</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Affecter un professeur</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="affecter">
            <div class="form-group"><label>Professeur</label>
                <select name="prof_id" required>
                    <?php foreach ($profs as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Classe</label>
                <select name="classe_id" required>
                    <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nom_affichage']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Matière</label>
                <select name="matiere_id" required>
                    <?php foreach ($matieres as $m): ?><option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['nom']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn">Affecter</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Affectations en cours</h2></div>
    <?php if ($affectations): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Professeur</th><th>Classe</th><th>Matière</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($affectations as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['p_prenom'] . ' ' . $a['p_nom']) ?></td>
                    <td><?= htmlspecialchars($a['nom_affichage']) ?></td>
                    <td><?= htmlspecialchars($a['matiere']) ?></td>
                    <td>
                        <form method="post" style="margin:0;" onsubmit="return confirm('Retirer cette affectation ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="retirer">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button class="btn btn-sm btn-danger">Retirer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucune affectation pour l'instant.</p></div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
