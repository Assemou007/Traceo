<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/tenant.php';

$auth = require_auth();
require_role($auth, ['prof']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'Méthode non autorisée'], 405);
}

/**
 * Payload attendu:
 * {
 *   "seance_id": 42,
 *   "absences": [
 *     {"eleve_id": 1, "statut": "absent", "motif": null},
 *     {"eleve_id": 7, "statut": "retard", "motif": "bus en retard"}
 *   ]
 * }
 * Seuls les élèves absents/en retard sont envoyés (présent = pas de ligne).
 */

$input = json_input();
$seance_id = (int)($input['seance_id'] ?? 0);
$absences = $input['absences'] ?? [];

$pdo = get_db();

// Vérifie que la séance appartient bien à un créneau de ce prof dans son établissement
$stmt = $pdo->prepare(
    "SELECT s.id, s.creneau_id, c.etablissement_id, c.classe_id
     FROM seance s JOIN creneau c ON c.id = s.creneau_id
     WHERE s.id = ? AND c.prof_id = ? AND c.etablissement_id = ?"
);
$stmt->execute([$seance_id, $auth['utilisateur_id'], $auth['etablissement_id']]);
$seance = $stmt->fetch();
if (!$seance) {
    send_json(['error' => 'Séance introuvable ou non autorisée'], 404);
}

$pdo->beginTransaction();
try {
    // Repart de zéro pour cette séance à chaque sauvegarde (simple et prévisible pour le prof)
    $del = $pdo->prepare("DELETE FROM presence WHERE seance_id = ?");
    $del->execute([$seance_id]);

    $ins = $pdo->prepare(
        "INSERT INTO presence (seance_id, eleve_id, statut, motif, saisi_par)
         VALUES (?, ?, ?, ?, ?)"
    );
    $notifs = [];
    foreach ($absences as $a) {
        $eleve_id = (int)($a['eleve_id'] ?? 0);
        $statut = $a['statut'] ?? '';
        $motif = trim($a['motif'] ?? '') ?: null; // vide => sans motif
        if (!in_array($statut, ['absent', 'retard'], true) || $eleve_id <= 0) continue;

        // Vérifie que l'élève appartient bien à la classe du créneau
        $check = $pdo->prepare("SELECT contact_parent FROM eleve WHERE id = ? AND classe_id = ?");
        $check->execute([$eleve_id, $seance['classe_id']]);
        $eleve = $check->fetch();
        if (!$eleve) continue;

        $ins->execute([$seance_id, $eleve_id, $statut, $motif, $auth['utilisateur_id']]);

        if (!empty($eleve['contact_parent'])) {
            $notifs[] = [$eleve['contact_parent'], $statut, $eleve_id];
        }
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    send_json(['error' => 'Erreur lors de la sauvegarde'], 500);
}

// File d'attente de notifications aux parents (envoi réel géré par un job/cron
// externe qui traite la table `notification` - voir README pour brancher un
// fournisseur SMS/WhatsApp local, ex: agrégateur béninois)
$notif_stmt = $pdo->prepare(
    "INSERT INTO notification (etablissement_id, type, destinataire_contact, canal, contenu)
     VALUES (?, ?, ?, 'sms', ?)"
);
foreach ($notifs as [$contact, $statut, $eleve_id]) {
    $texte = $statut === 'absent'
        ? "Votre enfant a été marqué absent aujourd'hui."
        : "Votre enfant a été marqué en retard aujourd'hui.";
    $notif_stmt->execute([$auth['etablissement_id'], $statut, $contact, $texte]);
}

send_json(['ok' => true, 'nb_absences_retards' => count($notifs)]);
