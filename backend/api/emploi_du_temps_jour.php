<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
require_role($auth, ['prof']);

$pdo = get_db();

// jour_semaine envoyé en query (1=lundi..7=dimanche), sinon on prend le jour actuel
$jour = isset($_GET['jour']) ? (int)$_GET['jour'] : (int)date('N');

$stmt = $pdo->prepare(
    "SELECT c.id AS creneau_id, c.heure_debut, c.heure_fin, c.salle,
            cl.nom_affichage AS classe, m.nom AS matiere,
            s.id AS seance_id, s.statut_validation, s.contenu_texte
     FROM creneau c
     JOIN classe cl ON cl.id = c.classe_id
     JOIN matiere m ON m.id = c.matiere_id
     LEFT JOIN seance s ON s.creneau_id = c.id AND s.date = CURDATE()
     WHERE c.etablissement_id = ? AND c.prof_id = ? AND c.jour_semaine = ?
     ORDER BY c.heure_debut ASC"
);
$stmt->execute([$auth['etablissement_id'], $auth['utilisateur_id'], $jour]);

send_json(['creneaux' => $stmt->fetchAll()]);
