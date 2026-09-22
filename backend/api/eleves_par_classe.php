<?php
/**
 * GET /api/eleves_par_classe.php?classe_id=X
 *
 * Retourne la liste des élèves d'une classe.
 * Accessible aux profs (pour l'appel) et aux responsables de classe (pour la validation).
 *
 * Requiert un token valide.
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
$pdo = get_db();

$classe_id = (int)($_GET['classe_id'] ?? 0);
if (!$classe_id) {
    send_json(['error' => 'Paramètre classe_id requis'], 400);
}

// Vérifier que l'utilisateur a accès à cette classe
if ($auth['role'] === 'prof') {
    $annee = $pdo->prepare("SELECT id FROM annee_scolaire WHERE etablissement_id = ? AND statut = 'en_cours' LIMIT 1");
    $annee->execute([$auth['etablissement_id']]);
    $annee_id = $annee->fetchColumn();

    $check = $pdo->prepare(
        "SELECT id FROM prof_matiere_classe
         WHERE utilisateur_id = ? AND classe_id = ? AND annee_scolaire_id = ?
         LIMIT 1"
    );
    $check->execute([$auth['utilisateur_id'], $classe_id, $annee_id]);
    if (!$check->fetch()) {
        send_json(['error' => 'Vous n\'avez pas accès à cette classe'], 403);
    }
} else {
    assert_belongs_to_etablissement('classe', $classe_id, $auth['etablissement_id']);
}

$stmt = $pdo->prepare(
    "SELECT id, nom, prenom, contact_parent
     FROM eleve
     WHERE etablissement_id = ? AND classe_id = ? AND statut = 'actif'
     ORDER BY nom, prenom"
);
$stmt->execute([$auth['etablissement_id'], $classe_id]);
$eleves = $stmt->fetchAll();

send_json(['eleves' => $eleves]);
