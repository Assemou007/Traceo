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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $classe_id = (int)($_POST['classe_id'] ?? 0);
    $matiere_id = (int)($_POST['matiere_id'] ?? 0);
    $prof_id = (int)($_POST['prof_id'] ?? 0);
    $jour = (int)($_POST['jour_semaine'] ?? 0);
    $debut = $_POST['heure_debut'] ?? '';
    $fin = $_POST['heure_fin'] ?? '';
    $salle = trim($_POST['salle'] ?? '');
    if (!$classe_id || !$matiere_id || !$prof_id || !$jour || !$debut || !$fin) $erreur = 'Tous les champs (sauf salle) sont requis.';
    elseif (!verifier_ressource($pdo, 'classe', $classe_id, $etab_id) || !verifier_ressource($pdo, 'matiere', $matiere_id, $etab_id)) {
        $erreur = 'Classe ou matière invalide pour cet établissement.';
    } elseif ($fin <= $debut) {
        $erreur = 'L\'heure de fin doit être après l\'heure de début.';
    } else {
        $check_prof = $pdo->prepare("SELECT id FROM utilisateur WHERE id = ? AND etablissement_id = ? AND role = 'prof' AND statut = 'actif'");
        $check_prof->execute([$prof_id, $etab_id]);
        if (!$check_prof->fetch()) {
            $erreur = 'Professeur invalide pour cet établissement.';
        } else {
            $conflit = $pdo->prepare("SELECT cr.classe_id, cl.nom_affichage, u.prenom, u.nom FROM creneau cr JOIN classe cl ON cl.id = cr.classe_id JOIN utilisateur u ON u.id = cr.prof_id WHERE cr.etablissement_id = ? AND cr.annee_scolaire_id = ? AND cr.jour_semaine = ? AND cr.heure_fin > ? AND cr.heure_debut < ? AND (cr.prof_id = ? OR cr.classe_id = ?) LIMIT 1");
            $conflit->execute([$etab_id, $annee_id, $jour, $debut, $fin, $prof_id, $classe_id]);
            $conf = $conflit->fetch();
            if ($conf) {
                $erreur = "Conflit horaire : la classe {$conf['nom_affichage']} ou le professeur {$conf['prenom']} {$conf['nom']} est déjà occupé ce jour sur ce créneau.";
            } else {
                $pdo->prepare("INSERT INTO creneau (etablissement_id, annee_scolaire_id, classe_id, matiere_id, prof_id, jour_semaine, heure_debut, heure_fin, salle, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'cree_manuellement')")
                    ->execute([$etab_id, $annee_id, $classe_id, $matiere_id, $prof_id, $jour, $debut, $fin, $salle ?: null]);
                $pdo->prepare("INSERT IGNORE INTO prof_matiere_classe (utilisateur_id, classe_id, matiere_id, annee_scolaire_id) VALUES (?, ?, ?, ?)")
                    ->execute([$prof_id, $classe_id, $matiere_id, $annee_id]);
                $succes = 'Créneau ajouté.';
            }
        }
    }
}

$classes = $pdo->prepare("SELECT id, nom_affichage FROM classe WHERE etablissement_id = ? AND annee_scolaire_id = ? ORDER BY nom_affichage");
$classes->execute([$etab_id, $annee_id]); $classes = $classes->fetchAll();
$matieres = $pdo->prepare("SELECT id, nom FROM matiere WHERE etablissement_id = ? ORDER BY nom");
$matieres->execute([$etab_id]); $matieres = $matieres->fetchAll();
$profs = $pdo->prepare("SELECT id, nom, prenom FROM utilisateur WHERE etablissement_id = ? AND role = 'prof' AND statut = 'actif' ORDER BY nom");
$profs->execute([$etab_id]); $profs = $profs->fetchAll();

$creneaux = $pdo->prepare("SELECT cr.jour_semaine, cr.heure_debut, cr.heure_fin, cl.nom_affichage AS classe, m.nom AS matiere, u.prenom, u.nom FROM creneau cr JOIN classe cl ON cl.id = cr.classe_id JOIN matiere m ON m.id = cr.matiere_id JOIN utilisateur u ON u.id = cr.prof_id WHERE cr.etablissement_id = ? AND cr.annee_scolaire_id = ? ORDER BY cr.jour_semaine, cr.heure_debut");
$creneaux->execute([$etab_id, $annee_id]);
$creneaux = $creneaux->fetchAll();
$jours = [1=>'Lundi',2=>'Mardi',3=>'Mercredi',4=>'Jeudi',5=>'Vendredi',6=>'Samedi',7=>'Dimanche'];

$titre = 'Emploi du temps';
require_once __DIR__ . '/inc/header.php';
?>

<div class="page-header">
    <div>
        <h1>Emploi du temps</h1>
        <p><?= count($creneaux) ?> créneau(x) · <a href="import.php">Import Excel/CSV</a> pour un import en masse</p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Ajouter un créneau</h2></div>
    <div class="card-body">
        <form method="post" class="form-row">
            <?= csrf_field() ?>
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
            <div class="form-group"><label>Professeur</label>
                <select name="prof_id" required>
                    <?php foreach ($profs as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Jour</label>
                <select name="jour_semaine" required>
                    <?php foreach ($jours as $n => $l): ?><option value="<?= $n ?>"><?= $l ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Début</label><input type="time" name="heure_debut" required></div>
            <div class="form-group"><label>Fin</label><input type="time" name="heure_fin" required></div>
            <div class="form-group"><label>Salle</label><input type="text" name="salle" placeholder="Optionnelle"></div>
            <button type="submit" class="btn">Ajouter</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Creneaux existants</h2></div>
    <?php if ($creneaux): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Jour</th><th>Horaire</th><th>Classe</th><th>Matière</th><th>Professeur</th></tr></thead>
            <tbody>
            <?php foreach ($creneaux as $c): ?>
                <tr>
                    <td><?= $jours[$c['jour_semaine']] ?></td>
                    <td><?= substr($c['heure_debut'],0,5) ?> – <?= substr($c['heure_fin'],0,5) ?></td>
                    <td><?= htmlspecialchars($c['classe']) ?></td>
                    <td><?= htmlspecialchars($c['matiere']) ?></td>
                    <td><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body"><div class="empty"><p>Aucun créneau pour l'instant.</p></div></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
