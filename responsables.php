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
    $utilisateur_id = (int)($_POST['utilisateur_id'] ?? 0);
    $classe_id = (int)($_POST['classe_id'] ?? 0);
    if (!$utilisateur_id || !$classe_id) $erreur = 'Compte et classe requis.';
    elseif (!verifier_ressource($pdo, 'classe', $classe_id, $etab_id)) {
        $erreur = 'Classe invalide pour cet établissement.';
    } else {
        $check_user = $pdo->prepare("SELECT id FROM utilisateur WHERE id = ? AND etablissement_id = ? AND role = 'responsable_classe' AND statut = 'actif'");
        $check_user->execute([$utilisateur_id, $etab_id]);
        if (!$check_user->fetch()) {
            $erreur = 'Compte responsable invalide pour cet établissement.';
        } else {
            $nb = $pdo->prepare("SELECT COUNT(*) FROM responsable_classe WHERE classe_id = ? AND annee_scolaire_id = ? AND statut = 'actif'");
            $nb->execute([$classe_id, $annee_id]);
            if ((int)$nb->fetchColumn() >= 2) $erreur = 'Cette classe a déjà 2 responsables actifs.';
            else {
                $pdo->prepare("INSERT INTO responsable_classe (utilisateur_id, classe_id, annee_scolaire_id, date_election, statut) VALUES (?, ?, ?, CURDATE(), 'actif')")
                    ->execute([$utilisateur_id, $classe_id, $annee_id]);
                $succes = 'Responsable affecté.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'creer_affecter') {
    $eleve_id = (int)($_POST['eleve_id'] ?? 0);
    $identifiant = trim($_POST['identifiant'] ?? '');
    $mdp = $_POST['mot_de_passe'] ?? '';

    if (!$eleve_id || !$identifiant || strlen($mdp) < 8) {
        $erreur = 'Élève, identifiant et mot de passe requis (8 car. min).';
    } else {
        $eleve = $pdo->prepare("SELECT nom, prenom, classe_id FROM eleve WHERE id = ? AND etablissement_id = ?");
        $eleve->execute([$eleve_id, $etab_id]);
        $eleve = $eleve->fetch(PDO::FETCH_ASSOC);
        if (!$eleve) { $erreur = 'Élève introuvable.'; }
        else {
            $classe_id = (int)$eleve['classe_id'];
            $nb = $pdo->prepare("SELECT COUNT(*) FROM responsable_classe WHERE classe_id = ? AND annee_scolaire_id = ? AND statut = 'actif'");
            $nb->execute([$classe_id, $annee_id]);
            if ((int)$nb->fetchColumn() >= 2) {
                $erreur = 'Cette classe a déjà 2 responsables actifs.';
            } else {
                try {
                    $hash = password_hash($mdp, PASSWORD_DEFAULT);
                    $pdo->prepare("INSERT INTO utilisateur (etablissement_id, role, nom, prenom, identifiant, mot_de_passe_hash, created_by) VALUES (?, 'responsable_classe', ?, ?, ?, ?, ?)")
                        ->execute([$etab_id, $eleve['nom'], $eleve['prenom'], $identifiant, $hash, $_SESSION['utilisateur_id']]);
                    $utilisateur_id = $pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO responsable_classe (utilisateur_id, classe_id, annee_scolaire_id, date_election, statut) VALUES (?, ?, ?, CURDATE(), 'actif')")
                        ->execute([$utilisateur_id, $classe_id, $annee_id]);
                    $succes = "Compte $identifiant créé pour {$eleve['prenom']} {$eleve['nom']} et affecté.";
                } catch (Exception $e) {
                    $erreur = str_contains($e->getMessage(), 'Duplicate') ? 'Identifiant déjà utilisé.' : 'Erreur lors de la création.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'remplacer') {
    $id = (int)($_POST['id'] ?? 0);
    $ok = $pdo->prepare("SELECT rc.id FROM responsable_classe rc JOIN classe c ON c.id = rc.classe_id WHERE rc.id = ? AND c.etablissement_id = ?");
    $ok->execute([$id, $etab_id]);
    if ($ok->fetch()) {
        $pdo->prepare("UPDATE responsable_classe SET statut = 'remplace' WHERE id = ?")->execute([$id]);
        $succes = 'Responsable remplacé.';
    } else {
        $erreur = 'Responsable introuvable pour cet établissement.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'supprimer') {
    $id = (int)($_POST['id'] ?? 0);
    $ok = $pdo->prepare("SELECT rc.utilisateur_id FROM responsable_classe rc JOIN classe c ON c.id = rc.classe_id WHERE rc.id = ? AND c.etablissement_id = ?");
    $ok->execute([$id, $etab_id]);
    $utilisateur_id = $ok->fetchColumn();
    if (!$utilisateur_id) {
        $erreur = 'Responsable introuvable pour cet établissement.';
    } else {
        $pdo->prepare("UPDATE seance SET valide_par = NULL WHERE valide_par = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM responsable_classe WHERE id = ?")->execute([$id]);
        if ($utilisateur_id) {

            $pdo->prepare("DELETE FROM code_reset WHERE utilisateur_id = ?")->execute([$utilisateur_id]);
            $pdo->prepare("DELETE FROM rapport_periodique WHERE destinataire_id = ?")->execute([$utilisateur_id]);
            $pdo->prepare("DELETE FROM punition WHERE gere_par = ?")->execute([$utilisateur_id]);
            $pdo->prepare("UPDATE utilisateur SET created_by = NULL WHERE created_by = ?")->execute([$utilisateur_id]);
            $pdo->prepare("DELETE FROM utilisateur WHERE id = ?")->execute([$utilisateur_id]);
        }
        $succes = 'Responsable et son compte supprimés.';
    }
}

$responsables = $pdo->prepare("SELECT rc.id, rc.statut, rc.date_election, u.prenom, u.nom, u.identifiant, c.nom_affichage FROM responsable_classe rc JOIN utilisateur u ON u.id = rc.utilisateur_id JOIN classe c ON c.id = rc.classe_id WHERE c.etablissement_id = ? AND rc.annee_scolaire_id = ? ORDER BY c.nom_affichage");
$responsables->execute([$etab_id, $annee_id]);
$responsables = $responsables->fetchAll();

$classes = $pdo->prepare("SELECT id, nom_affichage FROM classe WHERE etablissement_id = ? AND annee_scolaire_id = ? ORDER BY nom_affichage");
$classes->execute([$etab_id, $annee_id]);
$classes = $classes->fetchAll();

$comptes_resp = $pdo->prepare("SELECT id, nom, prenom, identifiant FROM utilisateur WHERE etablissement_id = ? AND role = 'responsable_classe' AND statut = 'actif' ORDER BY nom");
$comptes_resp->execute([$etab_id]);
$comptes_resp = $comptes_resp->fetchAll();

$eleves_disponibles = $pdo->prepare("SELECT e.id, e.nom, e.prenom, c.nom_affichage AS classe_nom FROM eleve e JOIN classe c ON c.id = e.classe_id WHERE e.etablissement_id = ? AND e.statut = 'actif' ORDER BY c.nom_affichage, e.nom");
$eleves_disponibles->execute([$etab_id]);
$eleves_disponibles = $eleves_disponibles->fetchAll();

$titre = 'Responsables de classe';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Responsables de classe</h1>
        <p>Chaque classe peut avoir jusqu'à 2 délégués</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Nouveau responsable depuis un élève</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="creer_affecter">
            <div class="form-group"><label>Élève</label>
                <select name="eleve_id" required>
                    <option value="">— Sélectionner un élève —</option>
                    <?php foreach ($eleves_disponibles as $e): ?>
                    <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom'] . ' (' . $e['classe_nom'] . ')') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Identifiant</label><input type="text" name="identifiant" required></div>
            <div class="form-group"><label>Mot de passe (8 car.)</label><input type="password" name="mot_de_passe" minlength="8" required></div>
            <button type="submit" class="btn">Créer le compte &amp; affecter</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Affecter un compte existant</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="affecter">
            <div class="form-group"><label>Responsable</label>
                <select name="utilisateur_id" required>
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($comptes_resp as $r): ?><option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['prenom'] . ' ' . $r['nom'] . ' (' . $r['identifiant'] . ')') ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Classe</label>
                <select name="classe_id" required>
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nom_affichage']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn">Affecter</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Responsables en fonction</h2></div>
    <?php if ($responsables): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Classe</th><th>Responsable</th><th>Identifiant</th><th>Élection</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($responsables as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['nom_affichage']) ?></td>
                    <td><?= htmlspecialchars($r['prenom'] . ' ' . $r['nom']) ?></td>
                    <td><?= htmlspecialchars($r['identifiant']) ?></td>
                    <td><?= htmlspecialchars($r['date_election']) ?></td>
                    <td><span class="badge <?= $r['statut'] === 'actif' ? 'badge-success' : 'badge-warning' ?>"><?= $r['statut'] ?></span></td>
                    <td>
                        <div style="display:flex; gap:6px;">
                        <?php if ($r['statut'] === 'actif'): ?>
                            <form method="post" style="margin:0;" onsubmit="return confirm('Marquer comme remplacé ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remplacer">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button class="btn btn-sm btn-danger">Remplacer</button>
                            </form>
                        <?php endif; ?>
                            <form method="post" style="margin:0;" onsubmit="return confirm('Supprimer définitivement ce responsable ?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button class="btn btn-sm btn-outline">Supprimer</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucun responsable affecté.</p></div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
