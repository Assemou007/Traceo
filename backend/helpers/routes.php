<?php
/**
 * Routage par rôle — unique source de vérité pour les redirections post-login.
 */

function dashboard_url_pour_role(string $role): string {
    $routes = [
        'censeur'            => 'dashboard.php',
        'surveillant'        => 'surveillant/dashboard.php',
        'prof'               => 'prof/dashboard.php',
        'responsable_classe' => 'responsable/dashboard.php',
    ];
    return $routes[$role] ?? 'login.php';
}
