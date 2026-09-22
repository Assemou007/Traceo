<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
require_role($auth, ['prof']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Méthode non autorisée'], 405);
}

$input = json_input();
$creneau_id = (int)($input['creneau_id'] ?? 0);
$contenu_texte = trim($input['contenu_texte'] ?? '');
$chapitre_id = isset($input['chapitre_id']) ? (int)$input['chapitre_id'] : null;
$date = $input['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    send_json(['error' => 'Format de date invalide (attendu AAAA-MM-JJ)'], 400);
}

$pdo = get_db();

// Vérifie que le créneau appartient bien à ce prof dans cet établissement
$stmt = $pdo->prepare(
    "SELECT id FROM creneau WHERE id = ? AND etablissement_id = ? AND prof_id = ?"
);
$stmt->execute([$creneau_id, $auth['etablissement_id'], $auth['utilisateur_id']]);
if (!$stmt->fetch()) {
    send_json(['error' => 'Créneau introuvable ou non autorisé'], 404);
}

if ($contenu_texte === '' && $chapitre_id === null) {
    send_json(['error' => 'Le contenu du cahier de texte est vide'], 400);
}

// Upsert : une seule séance par créneau/date (contrainte unique en base)
$stmt = $pdo->prepare(
    "INSERT INTO seance (creneau_id, date, contenu_texte, chapitre_id, statut_validation)
     VALUES (?, ?, ?, ?, 'en_attente')
     ON DUPLICATE KEY UPDATE contenu_texte = VALUES(contenu_texte), chapitre_id = VALUES(chapitre_id)"
);
$stmt->execute([$creneau_id, $date, $contenu_texte ?: null, $chapitre_id]);

$seance_id = $pdo->lastInsertId();
if (!$seance_id) {
    $stmt = $pdo->prepare("SELECT id FROM seance WHERE creneau_id = ? AND date = ?");
    $stmt->execute([$creneau_id, $date]);
    $seance_id = $stmt->fetchColumn();
}

send_json(['seance_id' => (int)$seance_id, 'statut_validation' => 'en_attente']);
