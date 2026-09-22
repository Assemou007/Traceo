<?php
/**
 * Garde-fous multi-tenant.
 * RÈGLE D'OR : toute requête SQL sur une table qui a etablissement_id DOIT
 * filtrer dessus avec la valeur venant du token (jamais depuis l'input utilisateur).
 * Ces fonctions centralisent le pattern pour éviter les oublis.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Vérifie qu'une ressource (classe, élève, matière, etc.) appartient bien
 * à l'établissement de l'utilisateur connecté. Tue la requête en 404 sinon.
 */
function assert_belongs_to_etablissement(string $table, int $id, int $etablissement_id): void {
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE id = ? AND etablissement_id = ?");
    $stmt->execute([$id, $etablissement_id]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Ressource introuvable']);
        exit;
    }
}

/**
 * Vérifie qu'un prof a bien accès à cette classe+matière pour l'année en cours
 * (règle métier : le prof ne voit que ses classes assignées).
 */
function assert_prof_a_acces(int $prof_id, int $classe_id, int $matiere_id, int $annee_scolaire_id): void {
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT id FROM prof_matiere_classe
         WHERE utilisateur_id = ? AND classe_id = ? AND matiere_id = ? AND annee_scolaire_id = ?"
    );
    $stmt->execute([$prof_id, $classe_id, $matiere_id, $annee_scolaire_id]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Vous n\'avez pas accès à cette classe/matière']);
        exit;
    }
}

function json_input(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return $data ?: [];
}

function send_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
