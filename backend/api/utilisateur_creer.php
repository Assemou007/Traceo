<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
require_role($auth, ['censeur']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Méthode non autorisée'], 405);
}

$input = json_input();
$role = $input['role'] ?? '';
$nom = trim($input['nom'] ?? '');
$prenom = trim($input['prenom'] ?? '');
$identifiant = trim($input['identifiant'] ?? '');
$mdp = $input['mot_de_passe'] ?? '';

$roles_creables = ['surveillant', 'prof', 'responsable_classe'];
if (!in_array($role, $roles_creables, true) || !$nom || !$identifiant || strlen($mdp) < 8) {
    send_json(['error' => 'Champs manquants ou invalides (mot de passe: 8 caractères min)'], 400);
}

$pdo = get_db();
$hash = password_hash($mdp, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare(
        "INSERT INTO utilisateur (etablissement_id, role, nom, prenom, identifiant, mot_de_passe_hash, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$auth['etablissement_id'], $role, $nom, $prenom, $identifiant, $hash, $auth['utilisateur_id']]);
} catch (Exception $e) {
    if (str_contains($e->getMessage(), 'Duplicate')) {
        send_json(['error' => 'Cet identifiant est déjà utilisé dans cet établissement'], 409);
    }
    send_json(['error' => 'Erreur lors de la création'], 500);
}

send_json(['ok' => true, 'utilisateur_id' => (int)$pdo->lastInsertId()], 201);
