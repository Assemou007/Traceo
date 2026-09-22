<?php
/**
 * Helper pour consigner les actions d'audit et de sécurité.
 */

function consigner_audit(PDO $pdo, int $etab_id, ?int $utilisateur_id, string $action, ?string $details = null): void {
    if ($etab_id <= 0) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    try {
        $stmt = $pdo->prepare("INSERT INTO journal_audit (etablissement_id, utilisateur_id, action, details, adresse_ip) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$etab_id, $utilisateur_id, $action, $details, $ip]);
    } catch (Throwable $e) {
        // En cas d'erreur de journalisation, on ne doit jamais bloquer le flux principal
        error_log("Traceo Audit Log Error: " . $e->getMessage());
    }
}
