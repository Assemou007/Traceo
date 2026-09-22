<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
require_role($auth, ['responsable_classe']);

$pdo = get_db();

$stmt = $pdo->prepare(
    "SELECT s.id AS seance_id, s.date, m.nom AS matiere, s.contenu_texte,
            cr.heure_debut, cr.heure_fin
     FROM seance s
     JOIN creneau cr ON cr.id = s.creneau_id
     JOIN matiere m ON m.id = cr.matiere_id
     JOIN responsable_classe rc ON rc.classe_id = cr.classe_id AND rc.statut = 'actif'
     WHERE rc.utilisateur_id = ? AND cr.etablissement_id = ?
       AND s.statut_validation = 'en_attente'
     ORDER BY s.date DESC, cr.heure_debut ASC
     LIMIT 20"
);
$stmt->execute([$auth['utilisateur_id'], $auth['etablissement_id']]);

send_json(['seances' => $stmt->fetchAll()]);
