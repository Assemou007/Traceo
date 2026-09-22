<?php
/**
 * Seed de démonstration — remplit l'établissement n°1 avec des données
 * réalistes pour visualiser le dashboard admin, les rapports et les courbes.
 *
 * Usage (CLI, comme les tests) :
 *   php -d pdo_mysql.default_socket=/opt/lampp/var/mysql/mysql.sock backend/sql/seed_demo.php
 *
 * Détruit les données de démonstration de l'établissement 1 (sauf le compte
 * censeur) puis recrée : filières, classes, matières, profs, surveillants,
 * responsables, élèves, emploi du temps, séances (≈70 jours), présences
 * (absences/retards), punitions.
 *
 * Mot de passe des comptes créés : Demo2026!
 */

require_once __DIR__ . '/../config/database.php';

$pdo = get_db();
$etab = (int)$pdo->query("SELECT id FROM etablissement ORDER BY id LIMIT 1")->fetchColumn();
if (!$etab) { fwrite(STDERR, "Aucun établissement en base.\n"); exit(1); }
$annee = (int)$pdo->query("SELECT id FROM annee_scolaire WHERE etablissement_id = $etab AND statut = 'en_cours' ORDER BY id LIMIT 1")->fetchColumn();
if (!$annee) { fwrite(STDERR, "Aucune année scolaire en cours.\n"); exit(1); }

$mdp = password_hash('Demo2026!', PASSWORD_DEFAULT);
$debut = '2026-05-25';
$fin = '2026-08-03';

echo "Nettoyage des anciennes données (établissement $etab)…\n";
foreach ([
    'presence', 'punition', 'seance', 'code_reset', 'notification',
    'rapport_periodique', 'responsable_classe', 'creneau', 'prof_matiere_classe',
    'programme_chapitre', 'programme_importe', 'eleve', 'classe', 'matiere', 'filiere',
] as $t) {
    $pdo->exec("DELETE FROM $t");
}
$pdo->exec("DELETE FROM utilisateur WHERE etablissement_id = $etab AND role <> 'censeur'");

// ---------- Filières ----------
$filiere_id = [];
$pdo->exec("INSERT INTO filiere (etablissement_id, nom, niveau_debut, diplome_final) VALUES ($etab, 'Développement Web et Mobile', '2nde', 'Diplôme DWM')");
$filiere_id['dwm'] = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO filiere (etablissement_id, nom, niveau_debut, diplome_final) VALUES ($etab, 'Sciences Économiques et Gestion', '2nde', 'Bac SE')");
$filiere_id['se'] = (int)$pdo->lastInsertId();

// ---------- Matières ----------
$matiere_nom = ['Mathématiques', 'Français', 'Anglais', 'SVT', 'Physique-Chimie', 'Histoire-Géographie', 'Espagnol', 'EPS', 'Informatique'];
$matiere_id = [];
foreach ($matiere_nom as $m) {
    $pdo->exec("INSERT INTO matiere (etablissement_id, nom) VALUES ($etab, " . $pdo->quote($m) . ")");
    $matiere_id[$m] = (int)$pdo->lastInsertId();
}

// ---------- Classes ----------
// [slug, nom_affichage, niveau, filière]
$classes = [
    ['dwm1', '2nde DWM1', '2nde', 'dwm'],
    ['dwm2', '2nde DWM2', '2nde', 'dwm'],
    ['dwm1ere', '1ère DWM', '1ère', 'dwm'],
    ['dwmtle', 'Tle DWM', 'Tle', 'dwm'],
    ['se1', '2nde SE1', '2nde', 'se'],
    ['se1ere', '1ère SE', '1ère', 'se'],
    ['setle', 'Tle SE', 'Tle', 'se'],
];
$classe_id = [];
foreach ($classes as [$slug, $nom, $niveau, $filiere]) {
    $pdo->exec("INSERT INTO classe (etablissement_id, annee_scolaire_id, filiere_id, niveau, nom_affichage)
                VALUES ($etab, $annee, {$filiere_id[$filiere]}, " . $pdo->quote($niveau) . ", " . $pdo->quote($nom) . ")");
    $classe_id[$slug] = (int)$pdo->lastInsertId();
}

// ---------- Utilisateurs : profs, surveillants, responsables ----------
$prof_id = [];
$profs = [
    ['Mathématiques', 'N' . 'dour', 'Sylvain'],
    ['Français', 'Assogba', 'Mireille'],
    ['Anglais', 'Alassane', 'Grâce'],
    ['SVT', 'Dossou', 'Fiacre'],
    ['Physique-Chimie', 'Hounkpatin', 'Judith'],
    ['Histoire-Géographie', 'Soglo', 'Modeste'],
    ['EPS', 'Bello', 'Aymar'],
    ['Informatique', 'Gbaguidi', 'Ulrich'],
];
foreach ($profs as $i => [$matiere, $nom, $prenom]) {
    $id = insert_user($pdo, $etab, 'prof', $nom, $prenom, 'prof' . ($i + 1), $mdp);
    $prof_id[$matiere] = $id;
}
// Un prof suppl. pour l'Espagnol
$prof_id['Espagnol'] = insert_user($pdo, $etab, 'prof', 'Odjo', 'Candide', 'prof9', $mdp);

$surv_id = [];
$surv_id[] = insert_user($pdo, $etab, 'surveillant', 'Kpoviessi', 'Sami', 'surv1', $mdp);
$surv_id[] = insert_user($pdo, $etab, 'surveillant', 'Tossou', 'Doris', 'surv2', $mdp);

$resp_id = [];
foreach ($classes as [$slug, $nom]) {
    $prefixe = 'resp_' . $slug;
    $r1 = insert_user($pdo, $etab, 'responsable_classe', 'Ahouansou', 'Christelle', $prefixe . '_a', $mdp);
    $r2 = insert_user($pdo, $etab, 'responsable_classe', 'Tchibozo', 'Wilfried', $prefixe . '_b', $mdp);
    foreach ([$r1, $r2] as $ru) {
        $pdo->exec("INSERT INTO responsable_classe (utilisateur_id, classe_id, annee_scolaire_id, date_election, statut)
                    VALUES ($ru, {$classe_id[$slug]}, $annee, '2026-09-10', 'actif')");
        $resp_id[$slug][] = (int)$pdo->lastInsertId();
    }
}

// ---------- Affectations prof / matière / classe ----------
foreach ($classe_id as $slug => $cid) {
    $estDwm = in_array($slug, ['dwm1', 'dwm2', 'dwm1ere', 'dwmtle'], true);
    $matieres = ['Mathématiques', 'Français', 'Anglais', 'SVT', 'Physique-Chimie', 'Histoire-Géographie', 'Espagnol', 'EPS'];
    if ($estDwm) $matieres[] = 'Informatique';
    foreach ($matieres as $m) {
        $pdo->exec("INSERT INTO prof_matiere_classe (utilisateur_id, classe_id, matiere_id, annee_scolaire_id)
                    VALUES ({$prof_id[$m]}, $cid, {$matiere_id[$m]}, $annee)");
    }
}

// ---------- Emploi du temps ----------
// [jour, heure_debut, heure_fin, matière] — 9 créneaux/semaine, communs à toutes les classes
$edt = [
    [1, '08:00', '09:00', 'Mathématiques'],
    [1, '09:00', '10:00', 'Français'],
    [2, '08:00', '09:00', 'Anglais'],
    [2, '10:00', '11:00', 'SVT'],
    [3, '08:00', '09:00', 'Physique-Chimie'],
    [3, '09:00', '10:00', 'Histoire-Géographie'],
    [4, '15:00', '16:00', 'Espagnol'],
    [4, '16:00', '17:00', 'EPS'],
    [5, '10:00', '11:00', 'Mathématiques'],
    [5, '15:00', '16:00', 'Français'],
    [6, '08:00', '09:00', 'Anglais'],
];
$creneau_id = [];
foreach ($classe_id as $slug => $cid) {
    $estDwm = in_array($slug, ['dwm1', 'dwm2', 'dwm1ere', 'dwmtle'], true);
    foreach ($edt as [$jour, $hd, $hf, $matiere]) {
        if ($matiere === 'Informatique') continue; // réservé aux classes DWM
        $pdo->exec("INSERT INTO creneau (etablissement_id, annee_scolaire_id, classe_id, matiere_id, prof_id, jour_semaine, heure_debut, heure_fin, salle, source)
                    VALUES ($etab, $annee, $cid, {$matiere_id[$matiere]}, {$prof_id[$matiere]}, $jour, '$hd', '$hf', " . $pdo->quote('S' . rand(1, 6)) . ", 'cree_manuellement')");
        $creneau_id[$slug][] = (int)$pdo->lastInsertId();
    }
    if ($estDwm) {
        $pdo->exec("INSERT INTO creneau (etablissement_id, annee_scolaire_id, classe_id, matiere_id, prof_id, jour_semaine, heure_debut, heure_fin, salle, source)
                    VALUES ($etab, $annee, $cid, {$matiere_id['Informatique']}, {$prof_id['Informatique']}, 6, '09:00', '10:00', 'Salle Info', 'cree_manuellement')");
        $creneau_id[$slug][] = (int)$pdo->lastInsertId();
    }
}

// ---------- Élèves ----------
$noms = ['Adjanon','Agbodjan','Ahouansou','Akpovi','Alassane','Allada','Assogba','Atindehou','Ayaovi','Azonhihon','Bello','Bio','Codjo','Dagbé','Dossou','Gbaguidi','Gnonlonfoun','Hounkpatin','Hountondji','Hounsou','Houngbo','Kpoviessi','Kpossa','Ligan','Lokossou','Medegan','Mensah','Nouakpo','Odjo','Ogou','Osseni','Paraiso','Soglo','Tchibozo','Tossou','Zannou','Zinsou','Zohoun','Houessou','Quenum'];
$prenoms = ['Abibou','Adélaïde','Aïcha','Alice','Amadou','Amina','Ange','Aristide','Aurèle','Aymar','Basile','Bénédicte','Candide','Carmelle','Charbel','Christian','Christelle','Corine','David','Déborah','Delphine','Dieudonné','Doris','Eudes','Fabrice','Fiacre','Flore','Franck','Grâce','Grégoire','Habib','Hermine','Ismaël','Jessica','Jocelyne','Jonas','Judith','Kévin','Laeticia','Lise','Loïc','Marcelin','Mariette','Mathieu','Mireille','Modeste','Nadège','Narcisse','Nathanaël','Nestor','Odile','Olivier','Orphée','Pascaline','Paul','Peggy','Prisca','Rachid','Rachel','Raïssa','Rodolphe','Romain','Romuald','Sandra','Sèna','Serge','Stella','Suleyman','Sylvie','Thierry','Trésor','Ulrich','Vanessa','Victor','Victoire','Wilfried','Yann','Yasmine','Yves','Zaki','Zoulkifou'];
$eleve_id = [];
mt_srand(42);
foreach ($classes as [$slug]) {
    $cid = $classe_id[$slug];
    $nb = ($slug === 'dwm1' || $slug === 'se1') ? 24 : 20;
    $eleve_id[$slug] = [];
    for ($i = 0; $i < $nb; $i++) {
        $nom = $noms[mt_rand(0, count($noms) - 1)];
        $prenom = $prenoms[mt_rand(0, count($prenoms) - 1)];
        $tel = '01 ' . mt_rand(90, 99) . ' ' . str_pad((string)mt_rand(0, 99), 2, '0', STR_PAD_LEFT) . ' ' . str_pad((string)mt_rand(0, 99), 2, '0', STR_PAD_LEFT) . ' ' . str_pad((string)mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
        $pdo->exec("INSERT INTO eleve (etablissement_id, classe_id, nom, prenom, contact_parent, statut)
                    VALUES ($etab, $cid, " . $pdo->quote($nom) . ", " . $pdo->quote($prenom) . ", " . $pdo->quote($tel) . ", 'actif')");
        $eleve_id[$slug][] = (int)$pdo->lastInsertId();
    }
}

// ---------- Séances + présences (≈70 jours) ----------
$motifs = ['Maladie', 'Rendez-vous médical', 'Raison familiale', 'Transport en panne', 'Gréve de transport', 'Cérémonie religieuse', null, null, null, null];
$contenus = ['Cours + exercices d\'application', 'Correction des devoirs et évaluation', 'Cours magistral suivi d\'une mise en pratique', 'Travaux dirigés sur le chapitre en cours', 'Rappel du cours précédent puis nouveau chapitre', 'Évaluation orale + résumé du cours'];
$stats = ['seances' => 0, 'validees' => 0, 'en_attente' => 0, 'forcees' => 0, 'absents' => 0, 'retards' => 0, 'punitions' => 0];
mt_srand(2026);

$d = new DateTime($debut);
$dernier = new DateTime($fin);
while ($d <= $dernier) {
    $date = $d->format('Y-m-d');
    $jour = (int)$d->format('N'); // 1=lundi … 7=dimanche
    if ($jour === 7) { $d->modify('+1 day'); continue; }

    foreach ($classe_id as $slug => $cid) {
        foreach ($creneau_id[$slug] as $cid_cren) {
            $info = $pdo->query("SELECT cr.jour_semaine, cr.prof_id, m.nom AS matiere, c.nom_affichage
                                 FROM creneau cr JOIN matiere m ON m.id = cr.matiere_id JOIN classe c ON c.id = cr.classe_id
                                 WHERE cr.id = $cid_cren")->fetch();
            if ((int)$info['jour_semaine'] !== $jour) continue;

            $hash = crc32($cid_cren . $date) % 100;
            if ($hash < 62) {
                $statut = 'validee';
                $valide = "{$resp_id[$slug][0]}";
                $valideLe = "'" . $date . ' 09:00:00' . "'";
            } elseif ($hash < 88) {
                $statut = 'en_attente';
                $valide = 'NULL';
                $valideLe = 'NULL';
            } else {
                $statut = 'forcee_censeur';
                $valide = 'NULL';
                $valideLe = 'NULL';
            }
            $contenu = $pdo->quote($contenus[$hash % count($contenus)]);
            $pdo->exec("INSERT INTO seance (creneau_id, date, contenu_texte, statut_validation, valide_par, valide_le)
                        VALUES ($cid_cren, '$date', $contenu, '$statut', $valide, $valideLe)");
            $seance_id = (int)$pdo->lastInsertId();
            $stats['seances']++;
            $stats[$statut === 'validee' ? 'validees' : ($statut === 'en_attente' ? 'en_attente' : 'forcees')]++;

            // Présences (absents/retards uniquement)
            foreach ($eleve_id[$slug] as $eid) {
                $chronique = $eid % 11 === 0; // ~9 % d'élèves chroniquement absents
                $pAbs = $chronique ? 240 : 55;
                $pRet = $chronique ? 80 : 28;
                mt_srand($eid * 1000 + $seance_id);
                $r = mt_rand(1, 1000);
                if ($r > $pAbs + $pRet) continue;
                $statutP = $r <= $pAbs ? 'absent' : 'retard';
                $motif = $statutP === 'absent' ? $pdo->quote($motifs[mt_rand(0, count($motifs) - 1)]) : 'NULL';
                $pdo->exec("INSERT INTO presence (seance_id, eleve_id, statut, motif, saisi_par)
                            VALUES ($seance_id, $eid, '$statutP', $motif, {$info['prof_id']})");
                $stats[$statutP === 'absent' ? 'absents' : 'retards']++;
            }
        }
    }
    $d->modify('+1 day');
}

// ---------- Punitions ----------
$motifsPun = ['Retard répété', 'Turbulence en classe', 'Devoirs non faits', 'Absence injustifiée', 'Chahut pendant le cours', 'Propos injurieux', 'Bavardage excessif'];
mt_srand(7);
foreach ($eleve_id as $slug => $ids) {
    $week = 0;
    $wd = new DateTime($debut);
    $we = new DateTime($fin);
    while ($wd <= $we) {
        if ((int)$wd->format('N') === 1) {
            foreach ($ids as $eid) {
                mt_srand($eid * 31 + $week);
                if (mt_rand(1, 40) !== 1) continue;
                $dateP = $wd->format('Y-m-d');
                $statutP = $dateP < '2026-07-20' && mt_rand(1, 10) <= 6 ? 'faite' : 'a_faire';
                $dateFaite = $statutP === 'faite' ? $pdo->quote(date('Y-m-d', strtotime($dateP . ' +2 days'))) : 'NULL';
                $pdo->exec("INSERT INTO punition (eleve_id, gere_par, motif, date_attribution, duree_jours, statut, date_faite)
                            VALUES ($eid, {$surv_id[mt_rand(0, count($surv_id) - 1)]}, " . $pdo->quote($motifsPun[mt_rand(0, count($motifsPun) - 1)]) . ", '$dateP', 1, '$statutP', $dateFaite)");
                $stats['punitions']++;
            }
            $week++;
        }
        $wd->modify('+1 day');
    }
}

// ---------- Récapitulatif ----------
function insert_user(PDO $pdo, int $etab, string $role, string $nom, string $prenom, string $identifiant, string $mdp): int {
    $pdo->exec("INSERT INTO utilisateur (etablissement_id, role, nom, prenom, identifiant, mot_de_passe_hash, statut, created_by)
                VALUES ($etab, '$role', " . $pdo->quote($nom) . ", " . $pdo->quote($prenom) . ", " . $pdo->quote($identifiant) . ", '$mdp', 'actif', 1)");
    return (int)$pdo->lastInsertId();
}

$r = $pdo->query("SELECT c.nom_affichage, COUNT(e.id) AS nb FROM classe c LEFT JOIN eleve e ON e.classe_id = c.id AND e.etablissement_id = $etab GROUP BY c.id ORDER BY c.id")->fetchAll();
echo "\n=== SEED TERMINÉ ===\n";
echo "Classes / effectifs :\n";
foreach ($r as $row) echo "  - {$row['nom_affichage']} : {$row['nb']} élèves\n";
printf("Séances (%s → %s) : %d (validées %d, en attente %d, forcées %d)\n", $debut, $fin, $stats['seances'], $stats['validees'], $stats['en_attente'], $stats['forcees']);
printf("Présences : %d absences, %d retards\n", $stats['absents'], $stats['retards']);
printf("Punitions : %d\n", $stats['punitions']);
echo "Comptes créés (mot de passe : Demo2026!) : prof1..prof9, surv1, surv2, resp_<classe>_a/_b\n";
