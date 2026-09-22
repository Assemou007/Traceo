<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
require_role($auth, ['responsable_classe']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Méthode non autorisée'], 405);
}

$input = json_input();
$seance_id = (int)($input['seance_id'] ?? 0);

$pdo = get_db();

// Le responsable actif de la classe concernée par la séance
$stmt = $pdo->prepare(
    "SELECT rc.id AS responsable_id, c.classe_id
     FROM seance s
     JOIN creneau c ON c.id = s.creneau_id
     JOIN responsable_classe rc ON rc.classe_id = c.classe_id AND rc.statut = 'actif'
     WHERE s.id = ? AND c.etablissement_id = ? AND rc.utilisateur_id = ?"
);
$stmt->execute([$seance_id, $auth['etablissement_id'], $auth['utilisateur_id']]);
$row = $stmt->fetch();

if (!$row) {
    send_json(['error' => 'Vous n\'êtes pas habilité à valider cette séance'], 403);
}

$update = $pdo->prepare(
    "UPDATE seance SET statut_validation = 'validee', valide_par = ?, valide_le = NOW()
     WHERE id = ?"
);
$update->execute([$row['responsable_id'], $seance_id]);

send_json(['ok' => true]);
