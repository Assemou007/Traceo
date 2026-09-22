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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'creer_classe') {
    $niveau = trim($_POST['niveau'] ?? '');
    $nom_affichage = trim($_POST['nom_affichage'] ?? '');
    $filiere_id = !empty($_POST['filiere_id']) ? (int)$_POST['filiere_id'] : null;
    if (!$niveau || !$nom_affichage) {
        $erreur = 'Niveau et nom sont requis.';
    } elseif ($filiere_id && !verifier_ressource($pdo, 'filiere', $filiere_id, $etab_id)) {
        $erreur = 'Filière invalide pour cet établissement.';
    } else {
        $pdo->prepare("INSERT INTO classe (etablissement_id, annee_scolaire_id, niveau, nom_affichage, filiere_id) VALUES (?, ?, ?, ?, ?)")
            ->execute([$etab_id, $annee_id, $niveau, $nom_affichage, $filiere_id]);
        $succes = "Classe $nom_affichage créée.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'ajouter_eleve') {
    $classe_id = (int)($_POST['classe_id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $contact = trim($_POST['contact_parent'] ?? '');
    if (!$classe_id || !$nom) {
        $erreur = 'Classe et nom sont requis.';
    } elseif (!verifier_ressource($pdo, 'classe', $classe_id, $etab_id)) {
        $erreur = 'Classe invalide pour cet établissement.';
    } else {
        $pdo->prepare("INSERT INTO eleve (etablissement_id, classe_id, nom, prenom, contact_parent) VALUES (?, ?, ?, ?, ?)")
            ->execute([$etab_id, $classe_id, $nom, $prenom, $contact ?: null]);
        $succes = "Élève $prenom $nom ajouté.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'modifier_eleve') {
    $id = (int)($_POST['id'] ?? 0);
    $classe_id = (int)($_POST['classe_id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $contact = trim($_POST['contact_parent'] ?? '');
    if (!$id || !$classe_id || !$nom) {
        $erreur = 'Tous les champs obligatoires doivent être remplis.';
    } elseif (!verifier_ressource($pdo, 'classe', $classe_id, $etab_id)) {
        $erreur = 'Classe invalide pour cet établissement.';
    } else {
        $pdo->prepare("UPDATE eleve SET classe_id = ?, nom = ?, prenom = ?, contact_parent = ? WHERE id = ? AND etablissement_id = ?")
            ->execute([$classe_id, $nom, $prenom, $contact ?: null, $id, $etab_id]);
        $succes = "Élève $prenom $nom modifié.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'supprimer_eleve') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("DELETE FROM eleve WHERE id = ? AND etablissement_id = ?");
        $stmt->execute([$id, $etab_id]);
        if ($stmt->rowCount()) {
            $succes = 'Élève supprimé.';
        } else {
            $erreur = 'Élève introuvable.';
        }
    }
}

$classes = $pdo->prepare("SELECT c.id, c.niveau, c.nom_affichage, f.nom AS filiere FROM classe c LEFT JOIN filiere f ON f.id = c.filiere_id WHERE c.etablissement_id = ? AND c.annee_scolaire_id = ? ORDER BY c.niveau, c.nom_affichage");
$classes->execute([$etab_id, $annee_id]);
$classes = $classes->fetchAll();

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM eleve e JOIN classe c ON c.id = e.classe_id WHERE e.etablissement_id = ? AND e.statut = 'actif'");
$count_stmt->execute([$etab_id]);
$eleves_total = (int)$count_stmt->fetchColumn();

$par_page = 50;
$page_eleves = max(1, (int)($_GET['page_eleves'] ?? 1));
$nb_pages = max(1, (int)ceil($eleves_total / $par_page));
$page_eleves = min($page_eleves, $nb_pages);
$offset = ($page_eleves - 1) * $par_page;

$eleves = $pdo->prepare("SELECT e.id, e.nom, e.prenom, e.classe_id, e.contact_parent, c.nom_affichage FROM eleve e JOIN classe c ON c.id = e.classe_id WHERE e.etablissement_id = ? AND e.statut = 'actif' ORDER BY c.nom_affichage, e.nom LIMIT $par_page OFFSET $offset");
$eleves->execute([$etab_id]);
$eleves = $eleves->fetchAll();

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_eleve = null;
if ($edit_id) {
    $st = $pdo->prepare("SELECT id, classe_id, nom, prenom, contact_parent FROM eleve WHERE id = ? AND etablissement_id = ?");
    $st->execute([$edit_id, $etab_id]);
    $edit_eleve = $st->fetch();
}

$filieres = $pdo->prepare("SELECT id, nom FROM filiere WHERE etablissement_id = ? ORDER BY nom");
$filieres->execute([$etab_id]);
$filieres = $filieres->fetchAll();

$titre = 'Classes & élèves';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Classes & élèves</h1>
        <p><?= count($classes) ?> classe(s) · <?= $eleves_total ?> élève(s)</p>
    </div>
</div>

<?php if ($edit_eleve): ?>
<div class="card" style="border-left:3px solid var(--accent);">
    <div class="card-header"><h2>Modifier l'élève : <?= htmlspecialchars($edit_eleve['prenom'] . ' ' . $edit_eleve['nom']) ?></h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="modifier_eleve">
            <input type="hidden" name="id" value="<?= $edit_eleve['id'] ?>">
            <div class="form-group">
                <label>Classe</label>
                <select name="classe_id" required>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $edit_eleve['classe_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom_affichage']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;"><label>Prénom</label><input type="text" name="prenom" value="<?= htmlspecialchars($edit_eleve['prenom']) ?>" required></div>
            <div class="form-group" style="flex:1;"><label>Nom</label><input type="text" name="nom" value="<?= htmlspecialchars($edit_eleve['nom']) ?>" required></div>
            <div class="form-group" style="flex:1;"><label>Contact parent</label><input type="text" name="contact_parent" value="<?= htmlspecialchars($edit_eleve['contact_parent'] ?? '') ?>" placeholder="Téléphone (optionnel)"></div>
            <button type="submit" class="btn"><i class="fas fa-save"></i> Enregistrer</button>
            <a href="classes.php" class="btn btn-neutral" style="margin-bottom:0;"><i class="fas fa-times"></i> Annuler</a>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2>Créer une classe</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="creer_classe">
            <div class="form-group" style="flex:1;"><label>Niveau</label><input type="text" name="niveau" required placeholder="ex: 6e, 2nde"></div>
            <div class="form-group" style="flex:1;"><label>Nom affiché</label><input type="text" name="nom_affichage" required placeholder="ex: 6e A"></div>
            <div class="form-group" style="flex:1;">
                <label>Filière</label>
                <select name="filiere_id">
                    <option value="">— Aucune —</option>
                    <?php foreach ($filieres as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn">Créer</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Ajouter un élève</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ajouter_eleve">
            <div class="form-group">
                <label>Classe</label>
                <select name="classe_id" required>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nom_affichage']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1;"><label>Prénom</label><input type="text" name="prenom" required></div>
            <div class="form-group" style="flex:1;"><label>Nom</label><input type="text" name="nom" required></div>
            <div class="form-group" style="flex:1;"><label>Contact parent</label><input type="text" name="contact_parent" placeholder="Téléphone (optionnel)"></div>
            <button type="submit" class="btn">Ajouter</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Liste des élèves</h2>
        <span class="badge badge-info"><?= $eleves_total ?> inscrits</span>
    </div>
    <div class="card-body" style="padding:12px 20px;">
        <input type="search" id="searchEleves" class="list-search" placeholder="Rechercher un élève..." style="width:100%;max-width:340px;padding:8px 12px;border:1.5px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text);font-family:var(--font);">
    </div>
    <?php if ($eleves): ?>
    <div class="table-wrap">
        <table id="elevesTable">
            <thead><tr><th>Nom</th><th>Classe</th><th>Contact parent</th><th style="width:100px;">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($eleves as $e): ?>
                <tr class="eleve-row">
                    <td data-label="Nom"><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?></td>
                    <td data-label="Classe"><?= htmlspecialchars($e['nom_affichage']) ?></td>
                    <td data-label="Contact"><?= $e['contact_parent'] ? htmlspecialchars($e['contact_parent']) : '<span class="badge badge-neutral">Non renseigné</span>' ?></td>
                    <td data-label="Actions">
                        <a href="?edit=<?= $e['id'] ?><?= $page_eleves > 1 ? '&page_eleves=' . $page_eleves : '' ?>" class="btn btn-sm btn-accent" title="Modifier"><i class="fas fa-edit"></i></a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cet élève ?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="supprimer_eleve">
                            <input type="hidden" name="id" value="<?= $e['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" title="Supprimer"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($nb_pages > 1): ?>
    <div class="pagination">
        <?php if ($page_eleves > 1): ?><a href="?page_eleves=<?= $page_eleves - 1 ?>" class="btn btn-sm btn-outline"><i class="fas fa-chevron-left"></i> Précédent</a><?php endif; ?>
        <span class="badge badge-neutral">Page <?= $page_eleves ?> / <?= $nb_pages ?></span>
        <?php if ($page_eleves < $nb_pages): ?><a href="?page_eleves=<?= $page_eleves + 1 ?>" class="btn btn-sm btn-outline">Suivant <i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucun élève pour l'instant.</p></div></div>
    <?php endif; ?>
</div>

<script>
(function(){
    const input = document.getElementById('searchEleves');
    if (!input) return;
    input.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('#elevesTable .eleve-row').forEach(function(tr) {
            tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
