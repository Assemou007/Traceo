<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
require_role($auth, ['surveillant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Méthode non autorisée'], 405);
}

$input = json_input();
$eleve_id = (int)($input['eleve_id'] ?? 0);
$motif = trim($input['motif'] ?? '');
$duree_jours = isset($input['duree_jours']) ? (int)$input['duree_jours'] : null;

if (!$eleve_id || !$motif) {
    send_json(['error' => 'eleve_id et motif requis'], 400);
}

$pdo = get_db();

assert_belongs_to_etablissement('eleve', $eleve_id, $auth['etablissement_id']);

// Récupère la durée par défaut de l'établissement si non précisée
$etab = $pdo->prepare(
    "SELECT duree_punition_custom_jours, seuil_recidive_punition, fenetre_recidive_jours,
            (SELECT duree_defaut_punition_jours FROM pays WHERE id = etablissement.pays_id) AS duree_pays
     FROM etablissement WHERE id = ?"
);
$etab->execute([$auth['etablissement_id']]);
$config = $etab->fetch();

if ($duree_jours === null) {
    $duree_jours = $config['duree_punition_custom_jours'] ?? $config['duree_pays'];
}

$stmt = $pdo->prepare(
    "INSERT INTO punition (eleve_id, gere_par, motif, date_attribution, duree_jours, statut)
     VALUES (?, ?, ?, CURDATE(), ?, 'a_faire')"
);
$stmt->execute([$eleve_id, $auth['utilisateur_id'], $motif, $duree_jours]);

// Détection récidive : compte les punitions de l'élève dans la fenêtre glissante
$fenetre = (int)$config['fenetre_recidive_jours'];
$seuil = (int)$config['seuil_recidive_punition'];
$count_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM punition
     WHERE eleve_id = ? AND date_attribution >= DATE_SUB(CURDATE(), INTERVAL ? DAY)"
);
$count_stmt->execute([$eleve_id, $fenetre]);
$nb_recentes = (int)$count_stmt->fetchColumn();

$recidive = $nb_recentes >= $seuil;
if ($recidive) {
    $eleve_info = $pdo->prepare("SELECT contact_parent, nom, prenom FROM eleve WHERE id = ?");
    $eleve_info->execute([$eleve_id]);
    $eleve = $eleve_info->fetch();
    if (!empty($eleve['contact_parent'])) {
        $notif = $pdo->prepare(
            "INSERT INTO notification (etablissement_id, type, destinataire_contact, canal, contenu)
             VALUES (?, 'recidive_punition', ?, 'sms', ?)"
        );
        $texte = "Votre enfant a reçu $nb_recentes punitions récemment, merci de contacter l'établissement.";
        $notif->execute([$auth['etablissement_id'], $eleve['contact_parent'], $texte]);
    }
}

send_json(['ok' => true, 'punition_id' => (int)$pdo->lastInsertId(), 'recidive_detectee' => $recidive], 201);
