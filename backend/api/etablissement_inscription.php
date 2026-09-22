<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Méthode non autorisée'], 405);
}

$input = json_input();
$nom_etab = trim($input['nom_etablissement'] ?? '');
$pays_code = trim($input['pays_code'] ?? '');
$type = $input['type'] ?? '';
$mode_cahier = $input['mode_cahier_texte'] ?? 'libre';
$censeur_nom = trim($input['censeur_nom'] ?? '');
$censeur_prenom = trim($input['censeur_prenom'] ?? '');
$censeur_identifiant = trim($input['censeur_identifiant'] ?? '');
$censeur_mdp = $input['censeur_mot_de_passe'] ?? '';

if (!$nom_etab || !$pays_code || !in_array($type, ['college','lycee'], true)
    || !$censeur_identifiant || strlen($censeur_mdp) < 8) {
    send_json(['error' => 'Champs manquants ou invalides (mot de passe: 8 caractères min)'], 400);
}
if (!in_array($mode_cahier, ['libre', 'programme_importe'], true)) {
    send_json(['error' => 'mode_cahier_texte invalide'], 400);
}

$pdo = get_db();

$pays = $pdo->prepare("SELECT id FROM pays WHERE code = ?");
$pays->execute([$pays_code]);
$pays_id = $pays->fetchColumn();
if (!$pays_id) {
    send_json(['error' => 'Pays non reconnu'], 400);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "INSERT INTO etablissement (pays_id, nom, type, mode_cahier_texte)
         VALUES (?, ?, ?, ?)"
    );
    $stmt->execute([$pays_id, $nom_etab, $type, $mode_cahier]);
    $etablissement_id = $pdo->lastInsertId();

    // Année scolaire par défaut (l'admin pourra la modifier ensuite)
    $annee_debut = (int)date('Y');
    $stmt = $pdo->prepare(
        "INSERT INTO annee_scolaire (etablissement_id, libelle, date_debut, date_fin, statut)
         VALUES (?, ?, ?, ?, 'en_cours')"
    );
    $stmt->execute([
        $etablissement_id,
        "$annee_debut-" . ($annee_debut + 1),
        "$annee_debut-09-01",
        ($annee_debut + 1) . "-07-31",
    ]);

    $hash = password_hash($censeur_mdp, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO utilisateur (etablissement_id, role, nom, prenom, identifiant, mot_de_passe_hash)
         VALUES (?, 'censeur', ?, ?, ?, ?)"
    );
    $stmt->execute([$etablissement_id, $censeur_nom, $censeur_prenom, $censeur_identifiant, $hash]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    if (str_contains($e->getMessage(), 'Duplicate')) {
        send_json(['error' => 'Cet identifiant est déjà utilisé'], 409);
    }
    send_json(['error' => 'Erreur lors de la création'], 500);
}

send_json(['ok' => true, 'etablissement_id' => (int)$etablissement_id], 201);
