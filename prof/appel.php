<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/../backend/config/database.php';

$pdo = get_db();
$user_id = $_SESSION['utilisateur_id'];
$etab_id = $_SESSION['etablissement_id'];
require_csrf('dashboard.php');

$seance_id = (int)($_GET['seance_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT s.id, s.creneau_id, s.date, s.contenu_texte, s.statut_validation,
            cr.classe_id, cl.nom_affichage AS classe_nom, m.nom AS matiere_nom,
            cr.heure_debut, cr.heure_fin
     FROM seance s
     JOIN creneau cr ON cr.id = s.creneau_id
     JOIN classe cl ON cl.id = cr.classe_id
     JOIN matiere m ON m.id = cr.matiere_id
     WHERE s.id = ? AND cr.prof_id = ? AND cr.etablissement_id = ?"
);
$stmt->execute([$seance_id, $user_id, $etab_id]);
$seance = $stmt->fetch();

if (!$seance) {
    header('Location: dashboard.php');
    exit;
}
$classe_id = $seance['classe_id'];

$eleves = $pdo->prepare(
    "SELECT id, nom, prenom FROM eleve WHERE etablissement_id = ? AND classe_id = ? AND statut = 'actif' ORDER BY nom, prenom"
);
$eleves->execute([$etab_id, $classe_id]);
$eleves = $eleves->fetchAll();

$presences = $pdo->prepare("SELECT eleve_id, statut, motif FROM presence WHERE seance_id = ?");
$presences->execute([$seance_id]);
$presences_map = [];
foreach ($presences as $p) {
    $presences_map[$p['eleve_id']] = $p;
}

$erreur = null;
$succes = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $absences = $_POST['absence'] ?? [];

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM presence WHERE seance_id = ?");
        $del->execute([$seance_id]);

        if (!empty($absences)) {
            $ins = $pdo->prepare(
                "INSERT INTO presence (seance_id, eleve_id, statut, motif, saisi_par) VALUES (?, ?, ?, ?, ?)"
            );
            $check_eleve = $pdo->prepare("SELECT id FROM eleve WHERE id = ? AND etablissement_id = ? AND classe_id = ? AND statut = 'actif'");
            foreach ($absences as $eleve_id => $data) {
                $statut = '';
                if (!empty($data['absent'])) $statut = 'absent';
                elseif (!empty($data['retard'])) $statut = 'retard';
                if (!$statut) continue;
                $check_eleve->execute([(int)$eleve_id, $etab_id, $classe_id]);
                if (!$check_eleve->fetch()) continue; // élève hors classe/établissement : ignoré
                $motif = trim($data['motif'] ?? '') ?: null;
                $ins->execute([$seance_id, (int)$eleve_id, $statut, $motif, $user_id]);
            }
        }
        $pdo->commit();
        $succes = 'Appel enregistré.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $erreur = 'Erreur lors de la sauvegarde.';
    }

    $presences = $pdo->prepare("SELECT eleve_id, statut, motif FROM presence WHERE seance_id = ?");
    $presences->execute([$seance_id]);
    $presences_map = [];
    foreach ($presences as $p) {
        $presences_map[$p['eleve_id']] = $p;
    }
}

$titre = 'Appel — ' . $seance['matiere_nom'];
require_once __DIR__ . '/../inc/header.php';
?>
<div class="page-header">
    <div>
        <h1><i class="fas fa-clipboard-user" style="color:var(--accent);margin-right:8px;"></i> Appel</h1>
        <p><?= htmlspecialchars($seance['classe_nom']) ?> · <?= htmlspecialchars($seance['matiere_nom']) ?> · <?= date('d/m/Y', strtotime($seance['date'])) ?> · <?= substr($seance['heure_debut'], 0, 5) ?>-<?= substr($seance['heure_fin'], 0, 5) ?></p>
    </div>
    <div class="page-actions">
        <a href="dashboard.php" class="btn btn-sm btn-outline"><i class="fas fa-arrow-left"></i> Retour</a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2>Liste des élèves</h2>
        <span class="badge badge-info"><?= count($eleves) ?> élève<?= count($eleves) > 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body" style="padding:0;">
        <form method="post">
            <?= csrf_field() ?>
        <?php if (empty($eleves)): ?>
        <div style="padding:32px 24px;text-align:center;color:var(--text-muted);">
            <i class="fas fa-users-slash" style="font-size:2rem;margin-bottom:8px;display:block;"></i>
            <p>Aucun élève inscrit dans cette classe.</p>
            <a href="../classes.php" class="btn btn-sm btn-accent" style="margin-top:8px;"><i class="fas fa-user-plus"></i> Ajouter des élèves</a>
        </div>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Élève</th>
                        <th style="width:80px;">Absent</th>
                        <th style="width:80px;">Retard</th>
                        <th>Motif</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eleves as $i => $e): ?>
                    <?php
                        $statut = $presences_map[$e['id']]['statut'] ?? '';
                        $motif = $presences_map[$e['id']]['motif'] ?? '';
                        $is_absent = $statut === 'absent';
                        $is_retard = $statut === 'retard';
                        $show_motif = $is_absent || $is_retard;
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?></strong></td>
                        <td>
                            <input type="hidden" name="absence[<?= $e['id'] ?>][absent]" value="0">
                            <input type="checkbox" name="absence[<?= $e['id'] ?>][absent]" value="1" class="statut-cb absent-cb" data-eleve="<?= $e['id'] ?>" <?= $is_absent ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <input type="hidden" name="absence[<?= $e['id'] ?>][retard]" value="0">
                            <input type="checkbox" name="absence[<?= $e['id'] ?>][retard]" value="1" class="statut-cb retard-cb" data-eleve="<?= $e['id'] ?>" <?= $is_retard ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <input type="text" name="absence[<?= $e['id'] ?>][motif]" value="<?= htmlspecialchars($motif) ?>" placeholder="Motif (optionnel)" class="motif-input" data-eleve="<?= $e['id'] ?>" style="padding:6px 10px;border:1.5px solid var(--border);border-radius:var(--radius-sm);background:var(--surface);color:var(--text);font-family:var(--font);width:100%;<?= $show_motif ? '' : 'display:none;' ?>">
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        document.querySelectorAll('.statut-cb').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var eleveId = this.dataset.eleve;
                var absentCb = document.querySelector('.absent-cb[data-eleve="' + eleveId + '"]');
                var retardCb = document.querySelector('.retard-cb[data-eleve="' + eleveId + '"]');
                var motifInput = document.querySelector('.motif-input[data-eleve="' + eleveId + '"]');
                if (absentCb.checked || retardCb.checked) {
                    motifInput.style.display = '';
                } else {
                    motifInput.style.display = 'none';
                }
                if (absentCb.checked && retardCb.checked) {
                    retardCb.checked = false;
                }
            });
        });
        </script>
        <?php endif; ?>
        <div style="padding:16px 24px; border-top:1px solid var(--border); display:flex; gap:8px;">
            <button type="submit" class="btn btn-accent" <?= empty($eleves) ? 'disabled' : '' ?>><i class="fas fa-save"></i> Enregistrer l'appel</button>
            <a href="dashboard.php" class="btn btn-outline">Annuler</a>
        </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
