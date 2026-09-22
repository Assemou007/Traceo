<?php
/**
 * Runner de tests minimal — zéro dépendance, compatible CLI :
 *   php backend/tests/run.php
 *
 * Couvre : helpers/auth.php (JWT maison), garde-fous multi-tenant,
 * protection CSRF, fonctions de routage.
 * Les tests qui nécessitent la base sont ignorés proprement si MySQL est injoignable.
 */

$tests = 0;
$echecs = 0;

require_once __DIR__ . '/../../_session.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/routes.php';
$_SESSION = []; // isole les tests CSRF

function t(string $nom, bool $condition): void {
    global $tests, $echecs;
    $tests++;
    if ($condition) {
        echo "  OK  $nom\n";
    } else {
        $echecs++;
        echo "  FAIL $nom\n";
    }
}

echo "== Traceo — Tests\n\n";

// ---------- helpers/auth.php ----------
echo "— JWT maison (helpers/auth.php)\n";

t('base64url roundtrip', base64url_decode(base64url_encode('héllo+wörld')) === 'héllo+wörld');

$token = generate_token(['utilisateur_id' => 1, 'etablissement_id' => 2, 'role' => 'censeur']);
t('generate_token -> verify_token', ($payload = verify_token($token)) && $payload['role'] === 'censeur' && $payload['etablissement_id'] === 2);

$corrompu = substr_replace($token, 'x', -3, 1);
t('token corrompu rejeté', verify_token($corrompu) === null);

$p = json_decode(base64url_decode(explode('.', $token)[1]), true);
t('payload contient exp', isset($p['exp']) && $p['exp'] > time());

// ---------- routage ----------
echo "\n— Routage par rôle (helpers/routes.php)\n";
t('censeur -> dashboard.php', dashboard_url_pour_role('censeur') === 'dashboard.php');
t('prof -> prof/dashboard.php', dashboard_url_pour_role('prof') === 'prof/dashboard.php');
t('rôle inconnu -> login.php', dashboard_url_pour_role('inconnu') === 'login.php');

// ---------- CSRF ----------
echo "\n— Protection CSRF (_session.php)\n";
$tok = csrf_token();
t('jeton généré (32 octets hex)', preg_match('/^[0-9a-f]{64}$/', $tok) === 1);
$_POST = ['csrf_token' => $tok];
t('jeton valide accepté', csrf_valide() === true);
$_POST = ['csrf_token' => '0000000000000000000000000000000000000000000000000000000000000000'];
t('jeton invalide rejeté', csrf_valide() === false);
$_POST = [];
t('absence de jeton rejetée', csrf_valide() === false);

// Mock PDO pour verifier_ressource sans BDD
class DummyPdo extends PDO { function __construct() {} }
$dummyPdo = new DummyPdo();
t('table non autorisée rejetée dans verifier_ressource', verifier_ressource($dummyPdo, 'invalid_table; DROP TABLE users;', 1, 1) === false);

// ---------- Base de données (optionnel) ----------
echo "\n— Multi-tenant (base requise, sinon ignoré)\n";
try {
    require_once __DIR__ . '/../config/database.php';
    $pdo = get_db();
    $pdo->query("SELECT 1")->fetch();
    $db_ok = true;
} catch (Throwable $e) {
    $db_ok = false;
    echo "  (ignoré : MySQL injoignable — " . $e->getMessage() . ")\n";
}

if ($db_ok) {
    $etabs = $pdo->query("SELECT id FROM etablissement ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    if (count($etabs) >= 2) {
        [$a, $b] = $etabs;
        $eleve_a = $pdo->prepare("SELECT id FROM eleve WHERE etablissement_id = ? LIMIT 1");
        $eleve_a->execute([$a]);
        $eleve_a = $eleve_a->fetchColumn();
        if ($eleve_a) {
            t('élève OK dans son établissement', verifier_ressource($pdo, 'eleve', (int)$eleve_a, (int)$a));
            t('élève refusé pour un autre établissement', verifier_ressource($pdo, 'eleve', (int)$eleve_a, (int)$b) === false);
        } else {
            echo "  (ignoré : aucun élève dans l'établissement $a)\n";
        }
    } else {
        echo "  (ignoré : besoin de 2 établissements pour tester l'isolation)\n";
    }
}

echo "\n== Résultat : $tests test(s), $echecs échec(s)\n";
exit($echecs > 0 ? 1 : 0);
