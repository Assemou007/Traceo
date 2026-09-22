<?php
/**
 * Authentification par token signé (mini-JWT maison, aucune dépendance Composer,
 * pour rester simple à déployer chez n'importe quel hébergeur PHP mutualisé).
 */

require_once __DIR__ . '/../config/database.php';

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function generate_token(array $payload): string {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + (60 * 60 * 24 * 30); // 30 jours (usage mobile terrain)
    $payload_encoded = base64url_encode(json_encode($payload));
    $signature = base64url_encode(hash_hmac('sha256', "$header.$payload_encoded", APP_SECRET, true));
    return "$header.$payload_encoded.$signature";
}

function verify_token(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$header, $payload_encoded, $signature] = $parts;
    $expected_sig = base64url_encode(hash_hmac('sha256', "$header.$payload_encoded", APP_SECRET, true));
    if (!hash_equals($expected_sig, $signature)) return null;
    $payload = json_decode(base64url_decode($payload_encoded), true);
    if (!$payload || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

/**
 * Récupère et vérifie le token du header Authorization: Bearer xxx
 * Retourne le payload (utilisateur_id, etablissement_id, role) ou tue la requête en 401.
 */
function require_auth(): array {
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'Token manquant']);
        exit;
    }
    $payload = verify_token($matches[1]);
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Token invalide ou expiré']);
        exit;
    }
    return $payload; // ['utilisateur_id' => .., 'etablissement_id' => .., 'role' => ..]
}

/**
 * Vérifie que le rôle de l'utilisateur connecté fait partie des rôles autorisés.
 */
function require_role(array $auth, array $roles_autorises): void {
    if (!in_array($auth['role'], $roles_autorises, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Accès refusé pour ce rôle']);
        exit;
    }
}
