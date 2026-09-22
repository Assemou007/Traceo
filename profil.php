<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_web_login();

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];
$user_id = $_SESSION['utilisateur_id'];
$erreur = null;
$succes = null;
require_csrf();

$user = $pdo->prepare("SELECT nom, prenom, identifiant, role FROM utilisateur WHERE id = ? AND etablissement_id = ?");
$user->execute([$user_id, $etab_id]);
$user = $user->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'changer_mdp') {
    $ancien = $_POST['ancien_mot_de_passe'] ?? '';
    $nouveau = $_POST['nouveau_mot_de_passe'] ?? '';
    $confirmation = $_POST['confirmation'] ?? '';

    if (!$ancien || !$nouveau || !$confirmation) {
        $erreur = 'Tous les champs sont requis.';
    } elseif ($nouveau !== $confirmation) {
        $erreur = 'Les nouveaux mots de passe ne correspondent pas.';
    } elseif (strlen($nouveau) < 6) {
        $erreur = 'Le mot de passe doit faire au moins 6 caractères.';
    } else {
        $st = $pdo->prepare("SELECT mot_de_passe_hash FROM utilisateur WHERE id = ? AND etablissement_id = ?");
        $st->execute([$user_id, $etab_id]);
        $hash = $st->fetchColumn();
        if (!password_verify($ancien, $hash)) {
            $erreur = 'Ancien mot de passe incorrect.';
        } else {
            $pdo->prepare("UPDATE utilisateur SET mot_de_passe_hash = ? WHERE id = ? AND etablissement_id = ?")
                ->execute([password_hash($nouveau, PASSWORD_DEFAULT), $user_id, $etab_id]);
            $succes = 'Mot de passe modifié avec succès.';
        }
    }
}

$titre = 'Mon profil';
require_once __DIR__ . '/inc/header.php';
?>
<div class="page-header">
    <div>
        <h1><i class="fas fa-user-circle" style="color:var(--accent);margin-right:8px;"></i> Mon profil</h1>
        <p>Informations personnelles et sécurité</p>
    </div>
</div>

<div class="dashboard-grid" style="grid-template-columns:1fr 1fr;">
    <div class="chart-card">
        <div class="card-header">
            <h3><i class="fas fa-info-circle" style="color:var(--statut-info);margin-right:6px;"></i> Informations</h3>
        </div>
        <div class="card-body">
            <div style="display:flex;flex-direction:column;gap:12px;">
                <div>
                    <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Nom complet</label>
                    <p style="font-size:1rem;font-weight:600;margin:4px 0 0;"><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></p>
                </div>
                <div>
                    <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Identifiant</label>
                    <p style="font-size:1rem;font-weight:600;margin:4px 0 0;"><?= htmlspecialchars($user['identifiant']) ?></p>
                </div>
                <div>
                    <label style="font-size:0.75rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Rôle</label>
                    <p style="margin:4px 0 0;">
                        <?php
                        $labels = ['censeur' => 'Censeur', 'prof' => 'Professeur', 'surveillant' => 'Surveillant', 'responsable_classe' => 'Responsable de classe'];
                        $class = ['censeur' => 'badge-accent', 'prof' => 'badge-success', 'surveillant' => 'badge-warning', 'responsable_classe' => 'badge-info'];
                        ?>
                        <span class="badge <?= $class[$user['role']] ?? 'badge-neutral' ?>"><?= $labels[$user['role']] ?? $user['role'] ?></span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="chart-card">
        <div class="card-header">
            <h3><i class="fas fa-lock" style="color:var(--statut-alerte);margin-right:6px;"></i> Changer le mot de passe</h3>
        </div>
        <div class="card-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="changer_mdp">
                <div class="form-group">
                    <label>Ancien mot de passe</label>
                    <input type="password" name="ancien_mot_de_passe" required autocomplete="current-password" placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label>Nouveau mot de passe</label>
                    <input type="password" name="nouveau_mot_de_passe" required minlength="6" autocomplete="new-password" placeholder="Min. 6 caractères">
                </div>
                <div class="form-group">
                    <label>Confirmer le nouveau mot de passe</label>
                    <input type="password" name="confirmation" required autocomplete="new-password" placeholder="Retaper le mot de passe">
                </div>
                <button type="submit" class="btn"><i class="fas fa-save"></i> Enregistrer</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
