<?php
/**
 * Script cron : traitement des notifications en attente et rappels.
 *
 * Usage (cron toutes les heures) :
 *   php backend/cron/traitement_notifications.php
 *
 * Actions :
 * 1. Envoie les notifications en attente (SMS/WhatsApp) via le provider configuré
 * 2. Relance les rappels de validation pour les responsables de classe
 * 3. Génère les rapports hebdomadaires (le lundi)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../sms/SmsGateway.php';

$pdo = get_db();
$sms = new SmsGateway();
$log = [];
$now = date('Y-m-d H:i:s');

// ========================
// 1. Notifications en attente
// ========================
$stmt = $pdo->query(
    "SELECT n.id, n.type, n.destinataire_contact, n.canal, n.contenu, n.etablissement_id
     FROM notification n
     WHERE n.statut_envoi = 'en_attente'
     ORDER BY n.created_at ASC
     LIMIT 50"
);

foreach ($stmt->fetchAll() as $n) {
    $ok = $sms->envoyer($n['destinataire_contact'], $n['contenu'], $n['canal']);

    $nouveau_statut = $ok ? 'envoye' : 'echec';
    $pdo->prepare("UPDATE notification SET statut_envoi = ? WHERE id = ?")->execute([$nouveau_statut, $n['id']]);
    $log[] = "Notification #{$n['id']} → $nouveau_statut ({$n['canal']} → {$n['destinataire_contact']})";
}

// ========================
// 2. Rappels aux responsables de classe
// ========================
$stmt = $pdo->query(
    "SELECT s.id AS seance_id, s.date, c.nom_affichage AS classe_nom,
            u.telephone AS contact_resp, cr.etablissement_id
     FROM seance s
     JOIN creneau cr ON cr.id = s.creneau_id
     JOIN classe c ON c.id = cr.classe_id
     JOIN responsable_classe rc ON rc.classe_id = cr.classe_id AND rc.annee_scolaire_id = cr.annee_scolaire_id AND rc.statut = 'actif'
     JOIN utilisateur u ON u.id = rc.utilisateur_id
     WHERE s.statut_validation = 'en_attente'
       AND (s.rappel_envoye_le IS NULL OR s.rappel_envoye_le < DATE_SUB(NOW(), INTERVAL 6 HOUR))
       AND cr.etablissement_id IN (SELECT id FROM etablissement WHERE statut = 'actif')
     LIMIT 30"
);

foreach ($stmt->fetchAll() as $s) {
    $message = "Traceo — Rappel : validez le cahier de texte du {$s['date']} pour la classe {$s['classe_nom']}.";
    $pdo->prepare(
        "INSERT INTO notification (etablissement_id, type, destinataire_contact, canal, contenu) VALUES (?, 'rappel_validation', ?, 'sms', ?)"
    )->execute([$s['etablissement_id'], $s['contact_resp'] ?? 'contact_non_disponible', $message]);

    $pdo->prepare("UPDATE seance SET rappel_envoye_le = NOW() WHERE id = ?")->execute([$s['seance_id']]);
    $log[] = "Rappel créé pour séance #{$s['seance_id']} ({$s['classe_nom']})";
}

// ========================
// 3. Rapport périodique (tous les lundis)
// ========================
if (date('N') === '1') {
    $debut = date('Y-m-d', strtotime('-7 days'));
    $fin = date('Y-m-d');

    $etabs = $pdo->query("SELECT DISTINCT etablissement_id FROM annee_scolaire WHERE statut = 'en_cours'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($etabs as $eid) {
        $censeurs = $pdo->prepare("SELECT id FROM utilisateur WHERE etablissement_id = ? AND role = 'censeur' AND statut = 'actif'");
        $censeurs->execute([$eid]);
        foreach ($censeurs->fetchAll() as $c) {
            $pdo->prepare("INSERT INTO rapport_periodique (etablissement_id, periode_debut, periode_fin, type, destinataire_id) VALUES (?, ?, ?, 'presence', ?)")
                ->execute([$eid, $debut, $fin, $c['id']]);
        }
        $log[] = "Rapport hebdo généré pour établissement #$eid";
    }
}

echo "$now — Traitement terminé.\n";
echo implode("\n", $log) . "\n";
