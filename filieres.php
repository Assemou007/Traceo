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
    $niveau_debut = trim($_POST['niveau_debut'] ?? '');
    $diplome_inter = trim($_POST['diplome_intermediaire'] ?? '');
    $diplome_final = trim($_POST['diplome_final'] ?? '');
    if (!$nom || !$niveau_debut) $erreur = 'Nom et niveau de début sont requis.';
    else {
        $pdo->prepare("INSERT INTO filiere (etablissement_id, nom, niveau_debut, diplome_intermediaire, diplome_final) VALUES (?, ?, ?, ?, ?)")
            ->execute([$etab_id, $nom, $niveau_debut, $diplome_inter ?: null, $diplome_final ?: null]);
        $succes = "Filière « $nom » créée.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'supprimer') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM filiere WHERE id = ? AND etablissement_id = ?")->execute([$id, $etab_id]);
    $succes = 'Filière supprimée.';
}

$filieres = $pdo->prepare("SELECT * FROM filiere WHERE etablissement_id = ? ORDER BY nom");
$filieres->execute([$etab_id]);
$filieres = $filieres->fetchAll();

$titre = 'Filières';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Filières</h1>
        <p><?= count($filieres) ?> filière(s) · Propres à votre établissement</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Ajouter une filière</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="creer">
            <div class="form-group"><label>Nom</label><input type="text" name="nom" required placeholder="Génie Civil, DWM..."></div>
            <div class="form-group"><label>Niveau début</label><input type="text" name="niveau_debut" required placeholder="ex: 2nde"></div>
            <div class="form-group"><label>Diplôme interm.</label><input type="text" name="diplome_intermediaire" placeholder="CAP (opt.)"></div>
            <div class="form-group"><label>Diplôme final</label><input type="text" name="diplome_final" placeholder="DT / BAC Pro"></div>
            <button type="submit" class="btn">Ajouter</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Liste des filières</h2></div>
    <?php if ($filieres): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nom</th><th>Début</th><th>Diplôme interm.</th><th>Diplôme final</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($filieres as $f): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($f['nom']) ?></strong></td>
                    <td><?= htmlspecialchars($f['niveau_debut']) ?></td>
                    <td><?= htmlspecialchars($f['diplome_intermediaire'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($f['diplome_final'] ?? '—') ?></td>
                    <td>
                        <form method="post" style="margin:0;" onsubmit="return confirm('Supprimer ?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="id" value="<?= $f['id'] ?>">
                            <button class="btn btn-sm btn-danger">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucune filière définie.</p></div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
