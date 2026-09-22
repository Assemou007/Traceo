<?php
session_start();

function require_web_login(array $roles_autorises = ['censeur', 'surveillant', 'prof', 'responsable_classe']): void {
    if (empty($_SESSION['utilisateur_id']) || !in_array($_SESSION['role'], $roles_autorises, true)) {
        header('Location: login.php');
        exit;
    }
}

// ============ Protection CSRF ============

/**
 * Jeton CSRF lié à la session (régénéré à chaque session).
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Champ caché à insérer dans chaque formulaire POST.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/**
 * Vérifie le jeton CSRF du POST en cours.
 */
function csrf_valide(): bool {
    $envoye = $_POST['csrf_token'] ?? '';
    $attendu = $_SESSION['csrf_token'] ?? '';
    return $envoye !== '' && $attendu !== '' && hash_equals($attendu, $envoye);
}

/**
 * Bloque tout POST sans jeton CSRF valide (flash erreur + redirection).
 * À appeler en tête de page, avant le traitement des actions POST.
 */
function require_csrf(?string $retour = null): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || csrf_valide()) return;
    $_SESSION['_flash_erreur'] = 'Jeton de sécurité invalide ou expiré. Veuillez réessayer.';
    header('Location: ' . ($retour ?? strtok($_SERVER['REQUEST_URI'], '?')));
    exit;
}

// ============ Garde-fous multi-tenant (pages web) ============

/**
 * Vérifie qu'une ressource (élève, classe, matière...) appartient bien à
 * l'établissement. Renvoie false sinon (message d'erreur côté page web).
 */
function verifier_ressource(PDO $pdo, string $table, int $id, int $etab_id): bool {
    if ($id <= 0) return false;
    $stmt = $pdo->prepare("SELECT id FROM `$table` WHERE id = ? AND etablissement_id = ?");
    $stmt->execute([$id, $etab_id]);
    return (bool)$stmt->fetch();
}

/**
 * Vérifie qu'un créneau appartient bien à l'établissement.
 */
function verifier_creneau(PDO $pdo, int $id, int $etab_id): bool {
    if ($id <= 0) return false;
    $stmt = $pdo->prepare("SELECT id FROM creneau WHERE id = ? AND etablissement_id = ?");
    $stmt->execute([$id, $etab_id]);
    return (bool)$stmt->fetch();
}
