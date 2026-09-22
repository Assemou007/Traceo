<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Méthode non autorisée'], 405);
}

$input = json_input();
$identifiant = trim($input['identifiant'] ?? '');
$mot_de_passe = $input['mot_de_passe'] ?? '';

if ($identifiant === '' || $mot_de_passe === '') {
    send_json(['error' => 'Identifiant et mot de passe requis'], 400);
}

$pdo = get_db();
// L'identifiant est unique PAR établissement (pas globalement), donc on cherche
// sur tous les comptes actifs qui matchent - en pratique l'app mobile/web
// pourra aussi demander le code établissement pour restreindre la recherche.
$stmt = $pdo->prepare(
    "SELECT u.*, e.nom AS etablissement_nom, e.statut AS etablissement_statut
     FROM utilisateur u
     JOIN etablissement e ON e.id = u.etablissement_id
     WHERE u.identifiant = ? AND u.statut = 'actif'"
);
$stmt->execute([$identifiant]);
$candidats = $stmt->fetchAll();

$user = null;
foreach ($candidats as $candidat) {
    if (password_verify($mot_de_passe, $candidat['mot_de_passe_hash'])) {
        $user = $candidat;
        break;
    }
}

if (!$user) {
    send_json(['error' => 'Identifiants incorrects'], 401);
}

if ($user['etablissement_statut'] !== 'actif') {
    send_json(['error' => 'Établissement suspendu, contactez le support'], 403);
}

$token = generate_token([
    'utilisateur_id' => (int)$user['id'],
    'etablissement_id' => (int)$user['etablissement_id'],
    'role' => $user['role'],
]);

send_json([
    'token' => $token,
    'utilisateur' => [
        'id' => (int)$user['id'],
        'nom' => $user['nom'],
        'prenom' => $user['prenom'],
        'role' => $user['role'],
        'etablissement' => $user['etablissement_nom'],
    ],
]);
