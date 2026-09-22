<?php
/**
 * GET /api/classes_liste.php
 *
 * Retourne la liste des classes de l'établissement.
 * Accessible aux profs (filtré par leurs affectations) et aux censeurs/surveillants.
 */

require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
$pdo = get_db();

$annee = $pdo->prepare("SELECT id FROM annee_scolaire WHERE etablissement_id = ? AND statut = 'en_cours' LIMIT 1");
$annee->execute([$auth['etablissement_id']]);
$annee_id = $annee->fetchColumn();

if ($auth['role'] === 'prof') {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT c.id, c.niveau, c.nom_affichage
         FROM classe c
         JOIN prof_matiere_classe pmc ON pmc.classe_id = c.id
         WHERE c.etablissement_id = ? AND c.annee_scolaire_id = ? AND pmc.utilisateur_id = ?
         ORDER BY c.niveau, c.nom_affichage"
    );
    $stmt->execute([$auth['etablissement_id'], $annee_id, $auth['utilisateur_id']]);
} else {
    $stmt = $pdo->prepare(
        "SELECT id, niveau, nom_affichage
         FROM classe
         WHERE etablissement_id = ? AND annee_scolaire_id = ?
         ORDER BY niveau, nom_affichage"
    );
    $stmt->execute([$auth['etablissement_id'], $annee_id]);
}

send_json(['classes' => $stmt->fetchAll()]);
