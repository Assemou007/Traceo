<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/../backend/config/database.php';

$pdo = get_db();
$user_id = $_SESSION['utilisateur_id'];
$etab_id = $_SESSION['etablissement_id'];
require_csrf('dashboard.php');

$creneau_id = (int)($_POST['creneau_id'] ?? 0);
$date = $_POST['date'] ?? date('Y-m-d');
$contenu_texte = trim($_POST['contenu_texte'] ?? '');

if (!$creneau_id) {
    header('Location: dashboard.php');
    exit;
}

$check = $pdo->prepare("SELECT id FROM creneau WHERE id = ? AND etablissement_id = ? AND prof_id = ?");
$check->execute([$creneau_id, $etab_id, $user_id]);
if (!$check->fetch()) {
    header('Location: dashboard.php');
    exit;
}

if ($contenu_texte !== '') {
    $pdo->prepare(
        "INSERT INTO seance (creneau_id, date, contenu_texte, statut_validation)
         VALUES (?, ?, ?, 'en_attente')
         ON DUPLICATE KEY UPDATE contenu_texte = VALUES(contenu_texte)"
    )->execute([$creneau_id, $date, $contenu_texte]);
    $_SESSION['_flash_succes'] = 'Cahier de texte enregistré.';
} else {
    $_SESSION['_flash_erreur'] = 'Le contenu ne peut pas être vide.';
}

header('Location: dashboard.php');
exit;
