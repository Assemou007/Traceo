<?php
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/helpers/pdf.php';

$pdo = get_db();
$etab_id = 1;

$pdf = new MiniPdf();
$pdf->setTitre('Identifiants d\'accès — Établissement');
$pdf->setSousTitre('Lycée Excellence Traceo');

$pdf->bandeau();
$pdf->infos('Document confidentiel · Généré le ' . date('d/m/Y H:i') . ' · Tous les comptes de l\'établissement');

// 1. Administration & Vie Scolaire
$pdf->sousTitre('1. Administration & Vie Scolaire');
$adminRows = [
    ['Censeur', 'Censeur ADMINISTRATEUR', 'censeur', 'Traceo2026!'],
    ['Surveillant', 'Sami KPOVIESSI', 'surv1', 'Demo2026!'],
    ['Surveillant', 'Doris TOSSOU', 'surv2', 'Demo2026!'],
];
$pdf->table(['Rôle', 'Nom & Prénom', 'Identifiant', 'Mot de passe'], $adminRows, [110, 165, 115, 115]);

// 2. Enseignants (Professeurs)
$pdf->sousTitre('2. Professeurs par Matière');
$profRows = [
    ['Mathématiques', 'Sylvain NDOUR', 'prof1', 'Demo2026!'],
    ['Français', 'Mireille ASSOGBA', 'prof2', 'Demo2026!'],
    ['Anglais', 'Grâce ALASSANE', 'prof3', 'Demo2026!'],
    ['SVT', 'Fiacre DOSSOU', 'prof4', 'Demo2026!'],
    ['Physique-Chimie', 'Judith HOUNKPATIN', 'prof5', 'Demo2026!'],
    ['Histoire-Géographie', 'Modeste SOGLO', 'prof6', 'Demo2026!'],
    ['EPS', 'Aymar BELLO', 'prof7', 'Demo2026!'],
    ['Informatique', 'Ulrich GBAGUIDI', 'prof8', 'Demo2026!'],
    ['Espagnol', 'Candide ODJO', 'prof9', 'Demo2026!'],
];
$pdf->table(['Matière', 'Nom & Prénom', 'Identifiant', 'Mot de passe'], $profRows, [130, 145, 115, 115]);

// 3. Délégués de Classe (Responsables)
$pdf->sousTitre('3. Responsables de Classe (Délégués)');
$respRows = [
    ['2nde DWM1', 'resp_dwm1_a / Demo2026!', 'resp_dwm1_b / Demo2026!'],
    ['2nde DWM2', 'resp_dwm2_a / Demo2026!', 'resp_dwm2_b / Demo2026!'],
    ['1ère DWM', 'resp_dwm1ere_a / Demo2026!', 'resp_dwm1ere_b / Demo2026!'],
    ['Tle DWM', 'resp_dwmtle_a / Demo2026!', 'resp_dwmtle_b / Demo2026!'],
    ['2nde SE1', 'resp_se1_a / Demo2026!', 'resp_se1_b / Demo2026!'],
    ['1ère SE', 'resp_se1ere_a / Demo2026!', 'resp_se1ere_b / Demo2026!'],
    ['Tle SE', 'resp_setle_a / Demo2026!', 'resp_setle_b / Demo2026!'],
];
$pdf->table(['Classe', 'Responsable A (Id / Mdp)', 'Responsable B (Id / Mdp)'], $respRows, [125, 190, 190]);

$pdfBinary = $pdf->sortie();
$outputPath = __DIR__ . '/traceo_identifiants_etablissement.pdf';
file_put_contents($outputPath, $pdfBinary);

echo "PDF généré avec succès : $outputPath (" . strlen($pdfBinary) . " octets)\n";
