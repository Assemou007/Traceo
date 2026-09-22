<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_web_login(['censeur']);

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];
$erreur = null; $succes = null;
require_csrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'creer') {
    $role = $_POST['role'] ?? '';
    $nom = trim($_POST['nom'] ?? '');
    $prenom = trim($_POST['prenom'] ?? '');
    $identifiant = trim($_POST['identifiant'] ?? '');
    $mdp = $_POST['mot_de_passe'] ?? '';

    if (!in_array($role, ['surveillant','prof','responsable_classe'], true) || !$nom || !$identifiant || strlen($mdp) < 8) {
        $erreur = 'Champs invalides (mot de passe : 8 caractères minimum).';
    } else {
        try {
            $hash = password_hash($mdp, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO utilisateur (etablissement_id, role, nom, prenom, identifiant, mot_de_passe_hash, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$etab_id, $role, $nom, $prenom, $identifiant, $hash, $_SESSION['utilisateur_id']]);
            $succes = "Compte $identifiant créé.";
        } catch (Exception $e) {
            $erreur = str_contains($e->getMessage(), 'Duplicate') ? 'Identifiant déjà utilisé.' : 'Erreur de création.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'supprimer') {
    $utilisateur_id = (int)($_POST['id'] ?? 0);
    if ($utilisateur_id === (int)$_SESSION['utilisateur_id']) {
        $erreur = 'Vous ne pouvez pas désactiver votre propre compte.';
    } elseif (!verifier_ressource($pdo, 'utilisateur', $utilisateur_id, $etab_id)) {
        $erreur = 'Compte introuvable pour cet établissement.';
    } else {
        $pdo->prepare("UPDATE utilisateur SET statut = 'inactif' WHERE id = ? AND etablissement_id = ?")->execute([$utilisateur_id, $etab_id]);
        $succes = 'Compte désactivé.';
    }
}

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM utilisateur WHERE etablissement_id = ? AND role != 'censeur'");
$count_stmt->execute([$etab_id]);
$total_utilisateurs = (int)$count_stmt->fetchColumn();

$par_page = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$nb_pages = max(1, (int)ceil($total_utilisateurs / $par_page));
$page = min($page, $nb_pages);
$offset = ($page - 1) * $par_page;

$stmt = $pdo->prepare("SELECT id, nom, prenom, role, identifiant, statut FROM utilisateur WHERE etablissement_id = ? AND role != 'censeur' ORDER BY role, nom LIMIT $par_page OFFSET $offset");
$stmt->execute([$etab_id]);
$utilisateurs = $stmt->fetchAll();

$labels_role = ['surveillant' => 'Surveillant', 'prof' => 'Professeur', 'responsable_classe' => 'Responsable de classe'];

$titre = 'Gestion des comptes';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Gestion des comptes</h1>
        <p><?= $total_utilisateurs ?> compte(s) créé(s)</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Créer un compte</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="creer">
            <div class="form-group"><label>Rôle</label>
                <select name="role" required>
                    <option value="prof">Professeur</option>
                    <option value="surveillant">Surveillant</option>
                    <option value="responsable_classe">Responsable de classe</option>
                </select>
            </div>
            <div class="form-group" style="flex:1;"><label>Prénom</label><input type="text" name="prenom" required></div>
            <div class="form-group" style="flex:1;"><label>Nom</label><input type="text" name="nom" required></div>
            <div class="form-group"><label>Identifiant</label><input type="text" name="identifiant" required></div>
            <div class="form-group"><label>Mot de passe (8 car.)</label><input type="password" name="mot_de_passe" minlength="8" required></div>
            <button type="submit" class="btn">Créer</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Comptes existants</h2>
        <span class="badge badge-info"><?= $total_utilisateurs ?></span>
    </div>
    <div class="card-body" style="padding:12px 20px;">
        <input type="search" id="searchComptes" class="list-search" placeholder="Rechercher un compte..." style="width:100%;max-width:340px;padding:8px 12px;border:1.5px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text);font-family:var(--font);">
    </div>
    <?php if ($utilisateurs): ?>
    <div class="table-wrap">
        <table id="comptesTable">
            <thead><tr><th>Nom</th><th>Rôle</th><th>Identifiant</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($utilisateurs as $u): ?>
                <tr class="compte-row">
                    <td data-label="Nom"><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom']) ?></td>
                    <td data-label="Rôle"><?= htmlspecialchars($labels_role[$u['role']] ?? $u['role']) ?></td>
                    <td data-label="Identifiant"><?= htmlspecialchars($u['identifiant']) ?></td>
                    <td data-label="Statut"><span class="badge <?= $u['statut'] === 'actif' ? 'badge-success' : 'badge-danger' ?>"><?= htmlspecialchars($u['statut']) ?></span></td>
                    <td data-label="">
                        <form method="post" style="margin:0;" onsubmit="return confirm('Supprimer le compte <?= htmlspecialchars($u['identifiant']) ?> ? Cette action est irréversible.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm btn-outline">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($nb_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>" class="btn btn-sm btn-outline"><i class="fas fa-chevron-left"></i> Précédent</a><?php endif; ?>
        <span class="badge badge-neutral">Page <?= $page ?> / <?= $nb_pages ?></span>
        <?php if ($page < $nb_pages): ?><a href="?page=<?= $page + 1 ?>" class="btn btn-sm btn-outline">Suivant <i class="fas fa-chevron-right"></i></a><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucun compte créé pour l'instant.</p></div></div>
    <?php endif; ?>
</div>

<script>
(function(){
    const input = document.getElementById('searchComptes');
    if (!input) return;
    input.addEventListener('input', function() {
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('#comptesTable .compte-row').forEach(function(tr) {
            tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
    });
})();
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
