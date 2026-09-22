<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_web_login(['censeur']);

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];
$erreur = null; $succes = null;
require_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'creer') {
    $nom = trim($_POST['nom'] ?? '');
    if (!$nom) $erreur = 'Le nom est requis.';
    else { $pdo->prepare("INSERT INTO matiere (etablissement_id, nom) VALUES (?, ?)")->execute([$etab_id, $nom]); $succes = "Matière « $nom » créée."; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'supprimer') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM matiere WHERE id = ? AND etablissement_id = ?")->execute([$id, $etab_id]);
    $succes = 'Matière supprimée.';
}

$matieres = $pdo->prepare("SELECT id, nom FROM matiere WHERE etablissement_id = ? ORDER BY nom");
$matieres->execute([$etab_id]);
$matieres = $matieres->fetchAll();

$titre = 'Matières';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Matières enseignées</h1>
        <p><?= count($matieres) ?> matière(s)</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Ajouter une matière</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="creer">
            <div class="form-group" style="flex:1;"><label>Nom</label><input type="text" name="nom" required placeholder="ex: Mathématiques, Français..."></div>
            <button type="submit" class="btn">Ajouter</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Liste des matières</h2></div>
    <?php if ($matieres): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nom</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($matieres as $m): ?>
                <tr>
                    <td><?= htmlspecialchars($m['nom']) ?></td>
                    <td>
                        <form method="post" style="margin:0;" onsubmit="return confirm('Supprimer « <?= htmlspecialchars($m['nom']) ?> » ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucune matière pour l'instant.</p></div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
