<?php
require_once __DIR__ . '/_session.php';
require_once __DIR__ . '/backend/config/database.php';
require_web_login(['censeur']);

$pdo = get_db();
$etab_id = $_SESSION['etablissement_id'];
$erreur = null; $succes = null;
require_csrf();

const IMPORT_MAX_OCTETS = 5 * 1024 * 1024; // 5 Mo max par fichier

$annee = $pdo->prepare("SELECT id FROM annee_scolaire WHERE etablissement_id = ? AND statut = 'en_cours' LIMIT 1");
$annee->execute([$etab_id]);
$annee_id = $annee->fetchColumn();

// --- Template download (CSV) ---
if (isset($_GET['template'])) {
    $type = $_GET['template'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="traceo_modele_' . $type . '.csv"');
    if ($type === 'eleves') {
        echo "\xEF\xBB\xBF"; // BOM UTF-8
        echo "Nom,Prenom,Contact_parent\n";
        echo "Dupont,Jean,22997000000\n";
        echo "Traoré,Aminata,\n";
        echo "Koffi,Yao,22997111111\n";
    } elseif ($type === 'emploi_du_temps') {
        echo "\xEF\xBB\xBF"; // BOM UTF-8
        echo "Matiere,Identifiant_prof,Jour_semaine,Heure_debut,Heure_fin,Salle\n";
        echo "Mathématiques,prof.math,1,08:00,09:00,Salle 101\n";
        echo "Anglais,prof.anglais,1,09:00,10:00,Salle 102\n";
        echo "Physique,prof.physique,2,10:00,11:00,Labo 1\n";
    }
    exit;
}

// --- XLSX reader ---
function lire_xlsx(string $chemin): ?array {
    $zip = new ZipArchive;
    if ($zip->open($chemin) !== true) return null;
    $ssContent = $zip->getFromName('xl/sharedStrings.xml');
    $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetContent === false) return null;
    $sharedStrings = [];
    if ($ssContent !== false) {
        $ssXml = simplexml_load_string($ssContent, 'SimpleXMLElement', LIBXML_NONET);
        if ($ssXml) foreach ($ssXml->si as $si) $sharedStrings[] = (string)$si->t;
    }
    $sheetXml = simplexml_load_string($sheetContent, 'SimpleXMLElement', LIBXML_NONET);
    if (!$sheetXml) return null;
    $rows = [];
    $maxCol = 0;
    foreach ($sheetXml->sheetData->row as $rowXml) {
        $cells = [];
        foreach ($rowXml->c as $cell) {
            $ref = (string)$cell['r'];
            $type = (string)$cell['t'];
            $value = (string)$cell->v;
            preg_match('/^([A-Z]+)/', $ref, $m);
            $ci = 0;
            foreach (str_split($m[1]) as $char) $ci = $ci * 26 + (ord($char) - 64);
            $ci--;
            if ($type === 's' && isset($sharedStrings[(int)$value])) $cells[$ci] = $sharedStrings[(int)$value];
            elseif ($type === 'inlineStr') $cells[$ci] = (string)$cell->is->t;
            else $cells[$ci] = $value;
            $maxCol = max($maxCol, $ci);
        }
        $rowData = [];
        for ($c = 0; $c <= $maxCol; $c++) $rowData[] = $cells[$c] ?? '';
        $rows[] = $rowData;
    }
    return $rows;
}

// --- Process rows ---
function traiter_lignes_eleves(array $rows, PDO $pdo, int $etab_id, int $classe_id): array {
    $importes = 0; $errs = [];
    foreach ($rows as $i => $row) {
        if ($i < 1) continue;
        $nom = trim($row[0] ?? ''); $prenom = trim($row[1] ?? '');
        $contact = trim($row[2] ?? '');
        if (!$nom && !$prenom) continue;
        if (!$nom || !$prenom) { $errs[] = "L." . ($i+1) . " : nom et prénom requis"; continue; }
        $pdo->prepare("INSERT IGNORE INTO eleve (etablissement_id, classe_id, nom, prenom, contact_parent) VALUES (?, ?, ?, ?, ?)")
            ->execute([$etab_id, $classe_id, $nom, $prenom, $contact ?: null]);
        if ($pdo->lastInsertId()) $importes++;
    }
    return [$importes, $errs];
}

function traiter_lignes_edt(array $rows, PDO $pdo, int $etab_id, int $annee_id, int $classe_cible): array {
    $importes = 0; $errs = [];
    foreach ($rows as $i => $row) {
        if ($i < 1) continue;
        $matiere_nom = trim($row[0] ?? ''); $prof_idf = trim($row[1] ?? '');
        $jour = (int)trim($row[2] ?? '0'); $debut = trim($row[3] ?? '');
        $fin = trim($row[4] ?? ''); $salle = trim($row[5] ?? '');
        if (!$matiere_nom && !$prof_idf && !$jour && !$debut && !$fin) continue;
        if (!$matiere_nom || !$prof_idf || !$jour || !$debut || !$fin) { $errs[] = "L." . ($i+1) . " : colonnes obligatoires manquantes"; continue; }

        $mat = $pdo->prepare("SELECT id FROM matiere WHERE nom = ? AND etablissement_id = ? LIMIT 1");
        $mat->execute([$matiere_nom, $etab_id]); $matiere_id = $mat->fetchColumn();
        $prof = $pdo->prepare("SELECT id FROM utilisateur WHERE identifiant = ? AND etablissement_id = ? AND role = 'prof' LIMIT 1");
        $prof->execute([$prof_idf, $etab_id]); $prof_id = $prof->fetchColumn();

        if (!$matiere_id) { $errs[] = "L." . ($i+1) . " : matière « $matiere_nom » introuvable"; continue; }
        if (!$prof_id) { $errs[] = "L." . ($i+1) . " : prof « $prof_idf » introuvable"; continue; }
        if ($jour < 1 || $jour > 7 || !preg_match('/^\d{2}:\d{2}$/', $debut) || !preg_match('/^\d{2}:\d{2}$/', $fin)) {
            $errs[] = "L." . ($i+1) . " : jour (1-7) ou heures invalides"; continue;
        }
        if ($fin <= $debut) { $errs[] = "L." . ($i+1) . " : heure de fin doit être après l'heure de début"; continue; }

        $conflit = $pdo->prepare("SELECT COUNT(*) FROM creneau WHERE etablissement_id = ? AND annee_scolaire_id = ? AND jour_semaine = ? AND heure_fin > ? AND heure_debut < ? AND (prof_id = ? OR classe_id = ?)");
        $conflit->execute([$etab_id, $annee_id, $jour, $debut, $fin, $prof_id, $classe_cible]);
        if ((int)$conflit->fetchColumn() > 0) {
            $errs[] = "L." . ($i+1) . " : conflit horaire (prof ou classe déjà occupé ce jour à cette heure)";
            continue;
        }

        $pdo->prepare("INSERT INTO creneau (etablissement_id, annee_scolaire_id, classe_id, matiere_id, prof_id, jour_semaine, heure_debut, heure_fin, salle, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'importe')")
            ->execute([$etab_id, $annee_id, $classe_cible, $matiere_id, $prof_id, $jour, $debut, $fin, $salle ?: null]);
        $pdo->prepare("INSERT IGNORE INTO prof_matiere_classe (utilisateur_id, classe_id, matiere_id, annee_scolaire_id) VALUES (?, ?, ?, ?)")
            ->execute([$prof_id, $classe_cible, $matiere_id, $annee_id]);
        $importes++;
    }
    return [$importes, $errs];
}

// --- Handle upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type_import = $_POST['type_import'] ?? '';
    $fichier = $_FILES['fichier'] ?? null;
    $classe_cible = (int)($_POST['classe_cible'] ?? 0);

    if (!$fichier || $fichier['error'] !== UPLOAD_ERR_OK) {
        $erreur = 'Erreur lors de l\'upload.';
    } elseif (!in_array($type_import, ['eleves', 'emploi_du_temps'], true)) {
        $erreur = 'Type invalide.';
    } elseif (in_array($type_import, ['eleves', 'emploi_du_temps'], true) && !$classe_cible) {
        $erreur = 'Sélectionnez une classe de destination.';
    } elseif ($classe_cible && !verifier_ressource($pdo, 'classe', $classe_cible, $etab_id)) {
        $erreur = 'Classe de destination invalide pour cet établissement.';
    } elseif (!is_uploaded_file($fichier['tmp_name'])) {
        $erreur = 'Fichier invalide.';
    } elseif ($fichier['size'] > IMPORT_MAX_OCTETS) {
        $erreur = 'Fichier trop volumineux (maximum 5 Mo).';
    } else {
        $ext = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
        $rows = null;
        if (in_array($ext, ['csv', 'txt'])) {
            $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($fichier['tmp_name']) : '';
            if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream', ''], true)) {
                $erreur = 'Le fichier ne semble pas être un CSV valide.';
            } else {
                $handle = fopen($fichier['tmp_name'], 'r');
                if ($handle) { while (($row = fgetcsv($handle)) !== false) $rows[] = $row; fclose($handle); }
            }
        } elseif ($ext === 'xlsx') {
            $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($fichier['tmp_name']) : '';
            if (!in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream', ''], true)) {
                $erreur = 'Le fichier ne semble pas être un XLSX valide.';
            } else {
                $rows = lire_xlsx($fichier['tmp_name']);
            }
        } else {
            $erreur = 'Format non supporté. Utilisez .xlsx (recommandé) ou .csv.';
        }
        if ($rows !== null) {
            if ($type_import === 'eleves') {
                [$importes, $errs] = traiter_lignes_eleves($rows, $pdo, $etab_id, $classe_cible);
            } else {
                [$importes, $errs] = traiter_lignes_edt($rows, $pdo, $etab_id, $annee_id, $classe_cible);
            }
            $msg = "$importes ligne(s) importée(s).";
            if ($errs) $msg .= ' ' . count($errs) . ' erreur(s) : ' . implode('; ', array_slice($errs, 0, 5));
            $succes = $msg;
        } elseif (!$erreur) {
            $erreur = 'Fichier vide ou invalide.';
        }
    }
}

$classes_list = $pdo->prepare("SELECT id, nom_affichage FROM classe WHERE etablissement_id = ? AND annee_scolaire_id = ? ORDER BY nom_affichage");
$classes_list->execute([$etab_id, $annee_id]);
$classes_list = $classes_list->fetchAll();

$titre = 'Import';
require_once __DIR__ . '/inc/header.php';
?>
<div class="page-header">
    <div>
        <h1><i class="fas fa-file-import" style="color:var(--accent);margin-right:8px;"></i> Import de données</h1>
        <p>Utilisez les modèles fournis pour importer vos données</p>
    </div>
</div>

<div class="alert" style="border-left:4px solid var(--accent);background:var(--accent-light);color:var(--accent);">
    <i class="fas fa-exclamation-circle" style="font-size:1.2rem;"></i>
    <strong>Important :</strong> Téléchargez le modèle, remplissez-le avec vos données, puis importez le fichier. Ne modifiez pas l'en-tête.
</div>

<div class="dashboard-grid">
    <div class="chart-card">
        <div class="card-header">
            <h3><i class="fas fa-users" style="color:var(--statut-info);margin-right:6px;"></i> Importer des élèves</h3>
        </div>
        <div class="card-body">
            <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:12px;">
                <strong>1.</strong> Choisissez la classe de destination ci-dessous<br>
                <strong>2.</strong> Téléchargez le modèle<br>
                <strong>3.</strong> Remplissez les colonnes Nom et Prénom<br>
                <strong>4.</strong> Importez le fichier
            </p>
            <div style="background:var(--bg);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:16px;font-size:0.8rem;color:var(--text-secondary);overflow-x:auto;white-space:nowrap;">
                <code style="font-family:var(--font-mono);">Nom | Prénom | Contact_parent</code>
            </div>
            <form method="post" enctype="multipart/form-data" class="js-loading">
                <?= csrf_field() ?>
                <input type="hidden" name="type_import" value="eleves">
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label>Classe de destination</label>
                        <select name="classe_cible" required>
                            <option value="">— Sélectionner —</option>
                            <?php foreach ($classes_list as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nom_affichage']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Fichier (.xlsx ou .csv)</label>
                        <input type="file" name="fichier" accept=".csv,.xlsx,.txt" required>
                    </div>
                    <button type="submit" class="btn" style="margin-bottom:0;"><i class="fas fa-upload"></i> Importer</button>
                </div>
                <div style="margin-top:12px;">
                    <a href="?template=eleves" class="btn btn-sm btn-accent"><i class="fas fa-download"></i> Télécharger le modèle élèves</a>
                </div>
            </form>
        </div>
    </div>

    <div class="chart-card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-alt" style="color:var(--statut-alerte);margin-right:6px;"></i> Importer l'emploi du temps</h3>
        </div>
        <div class="card-body">
            <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:12px;">
                <strong>1.</strong> Choisissez la classe ci-dessous<br>
                <strong>2.</strong> Téléchargez le modèle<br>
                <strong>3.</strong> Remplissez : Matière, Identifiant prof, Jour (1-7), Début, Fin, Salle<br>
                <strong>4.</strong> Importez le fichier
            </p>
            <div style="background:var(--bg);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:16px;font-size:0.8rem;color:var(--text-secondary);overflow-x:auto;white-space:nowrap;">
                <code style="font-family:var(--font-mono);">Matière | Identifiant_prof | Jour | Heure_debut | Heure_fin | Salle</code>
            </div>
            <form method="post" enctype="multipart/form-data" class="js-loading">
                <?= csrf_field() ?>
                <input type="hidden" name="type_import" value="emploi_du_temps">
                <div class="form-row">
                    <div class="form-group" style="flex:1;">
                        <label>Classe</label>
                        <select name="classe_cible" required>
                            <option value="">— Sélectionner —</option>
                            <?php foreach ($classes_list as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nom_affichage']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Fichier (.csv ou .xlsx)</label>
                        <input type="file" name="fichier" accept=".csv,.xlsx,.txt" required>
                    </div>
                    <button type="submit" class="btn" style="margin-bottom:0;"><i class="fas fa-upload"></i> Importer</button>
                </div>
                <div style="margin-top:12px;">
                    <a href="?template=emploi_du_temps" class="btn btn-sm btn-accent"><i class="fas fa-download"></i> Télécharger le modèle emploi du temps</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
