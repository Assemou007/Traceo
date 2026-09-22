<?php
/**
 * Générateur PDF minimal — aucune dépendance externe.
 *
 * Produit des rapports A4 mis en forme : bandeau d'en-tête coloré, cartes
 * de synthèse (KPI), sections numérotées, tableaux avec entête sur fond
 * bleu + zébrures + alignement des nombres à droite, pied de page.
 *
 * Polices standard PDF (Helvetica / Helvetica-Bold, encodage Windows-1252),
 * donc compatible accents français sans embarquer de police.
 *
 * Usage :
 *   $pdf = new MiniPdf();
 *   $pdf->setTitre("Rapport d'activité");
 *   $pdf->setSousTitre("Lycée Les Génies");
 *   $pdf->bandeau();
 *   $pdf->infos("Période : du 01/07/2026 au 04/08/2026");
 *   $pdf->cartes([['Séances', 338, '#1D4ED8'], ...]);
 *   $pdf->sousTitre("1. Absences par classe");
 *   $pdf->table(['Classe', 'Absences'], [['6e A', '12']], [300, 205]);
 *   $pdf->telecharger('rapport.pdf');  // ou ->sortie() pour le binaire
 */

class MiniPdf {
    private const W = 595.28;    // A4 largeur (points)
    private const H = 841.89;    // A4 hauteur
    private const M = 45;        // marge

    private array $pages = [];
    private array $cur = [];
    private float $y = self::M;
    private string $titre_doc = 'Traceo — Rapport';
    private string $sous_titre_doc = '';

    private bool $tableActive = false;
    private array $tableHeader = [];

    // ---------- Positionnement ----------

    private function nouvellePage(): void {
        if ($this->cur !== []) $this->pages[] = $this->cur;
        $this->cur = [];
        $this->y = self::M;
    }

    private function ensure(float $besoin): void {
        if ($this->y + $besoin <= self::H - self::M) return;
        $this->nouvellePage();
        if ($this->tableActive) $this->dessinerEnteteTable(); // re-prise du tableau
    }

    public function setTitre(string $t): void { $this->titre_doc = $t; }
    public function setSousTitre(string $s): void { $this->sous_titre_doc = $s; }

    // ---------- En-tête ----------

    /** Bandeau bleu pleine largeur : titre + établissement. */
    public function bandeau(): void {
        $this->ensure(80);
        $this->cur[] = ['r', 0, self::M - 20, self::W, 72, '#1D4ED8'];
        $this->cur[] = ['t', self::M, self::M + 7, 'F2', 20, '#FFFFFF', $this->titre_doc, 'g'];
        if ($this->sous_titre_doc !== '') {
            $this->cur[] = ['t', self::M, self::M + 32, 'F1', 11, '#BFDBFE', $this->sous_titre_doc, 'g'];
        }
        $this->y = self::M + 72 + 8;
    }

    /** Ligne d'informations sous le bandeau (période, génération…). */
    public function infos(string $ligne): void {
        $this->ensure(16);
        $this->cur[] = ['t', self::M, $this->y, 'F1', 9.5, '#64748B', $ligne, 'g'];
        $this->y += 15;
    }

    /** Cartes de synthèse : [label, valeur, couleur hex]. */
    public function cartes(array $kpis): void {
        $gap = 8;
        $n = max(1, count($kpis));
        $w = (self::W - 2 * self::M - $gap * ($n - 1)) / $n;
        $h = 48;
        $this->ensure($h + 12);
        foreach ($kpis as $i => [$label, $valeur, $hex]) {
            $x = self::M + $i * ($w + $gap);
            $this->cur[] = ['rr', $x, $this->y, $w, $h, 6, '#F1F5F9'];
            $this->cur[] = ['rr', $x, $this->y, $w, 4, 2, $hex];
            $this->cur[] = ['t', $x + $w / 2, $this->y + 11, 'F2', 15, $hex, (string)$valeur, 'c'];
            $this->cur[] = ['t', $x + $w / 2, $this->y + 33, 'F1', 8.5, '#475569', $label, 'c'];
        }
        $this->y += $h + 12;
    }

    // ---------- Contenu ----------

    public function sousTitre(string $t): void {
        $this->ensure(26);
        $this->cur[] = ['r', self::M, $this->y + 1, 3.5, 13, '#1D4ED8'];
        $this->cur[] = ['t', self::M + 11, $this->y, 'F2', 12.5, '#0F172A', $t, 'g'];
        $this->y += 21;
    }

    public function texte(string $t, bool $gras = false, float $taille = 10, string $couleur = '#0F172A'): void {
        $this->ensure($taille + 6);
        $this->cur[] = ['t', self::M, $this->y, $gras ? 'F2' : 'F1', $taille, $couleur, $t, 'g'];
        $this->y += $taille + 6;
    }

    public function saut(float $h = 12): void {
        $this->ensure($h);
        $this->y += $h;
    }

    /**
     * Tableau : entête sur fond bleu (répété en haut de page), zébrures,
     * filets fins, nombres alignés à droite.
     * $largeurs : largeurs de colonnes en points (total = largeur utile).
     * $aligns : 'g' | 'c' | 'd' par colonne (défaut : auto, nombre → droite).
     */
    public function table(array $entetes, array $rows, array $largeurs = [], array $aligns = []): void {
        $nbCols = max(1, count($entetes));
        $largeurUtile = self::W - 2 * self::M;
        if (count($largeurs) !== $nbCols || array_sum($largeurs) <= 0) {
            $largeurs = array_fill(0, $nbCols, $largeurUtile / $nbCols);
        }
        $aligns = array_pad($aligns, $nbCols, 'g');

        $this->tableActive = true;
        $this->tableHeader = [$entetes, $largeurs, $aligns];
        $this->dessinerEnteteTable();

        foreach ($rows as $ri => $r) {
            $this->ensure(15);
            if ($ri % 2 === 1) {
                $this->cur[] = ['r', self::M, $this->y, $largeurUtile, 15, '#F8FAFC'];
            }
            $x = self::M;
            for ($i = 0; $i < $nbCols; $i++) {
                $v = (string)($r[$i] ?? '');
                $align = $aligns[$i];
                if ($align === 'g' && $v !== '' && is_numeric($v)) $align = 'd';
                $texte = self::tronquer($v, $largeurs[$i]);
                $this->cur[] = ['t', $x + ($align === 'd' ? $largeurs[$i] - 7 : 7), $this->y + 4.5, 'F1', 9.5, '#0F172A', $texte, $align === 'd' ? 'd' : 'g'];
                $x += $largeurs[$i];
            }
            $this->y += 15;
            $this->cur[] = ['l', self::M, $this->y - 0.6, self::W - self::M, $this->y - 0.6, '#E2E8F0', 0.6];
        }
        $this->tableActive = false;
        $this->saut(6);
    }

    private function dessinerEnteteTable(): void {
        [$entetes, $largeurs, $aligns] = $this->tableHeader;
        $largeurUtile = self::W - 2 * self::M;
        $this->cur[] = ['r', self::M, $this->y, $largeurUtile, 18, '#1D4ED8'];
        $x = self::M;
        foreach ($entetes as $i => $e) {
            $align = $aligns[$i] ?? 'g';
            if ($align === 'g' && $e !== '' && is_numeric($e)) $align = 'd';
            $this->cur[] = ['t', $x + ($align === 'd' ? $largeurs[$i] - 7 : 7), $this->y + 5, 'F2', 9.5, '#FFFFFF', self::tronquer((string)$e, $largeurs[$i]), $align === 'd' ? 'd' : 'g'];
            $x += $largeurs[$i];
        }
        $this->y += 18;
    }

    // ---------- Utilitaires ----------

    private static function tronquer(string $s, float $largeur): string {
        $maxChars = max(4, (int)floor($largeur / 5.2));
        if (mb_strlen($s) <= $maxChars) return $s;
        return mb_substr($s, 0, $maxChars - 1) . '…';
    }

    private static function largeurTexte(string $s, string $font, float $taille): float {
        return mb_strlen($s) * $taille * ($font === 'F2' ? 0.57 : 0.51);
    }

    private static function echapperTexte(string $s): string {
        // UTF-8 → Windows-1252 (couverture complète : accents + , –, …, €, etc.)
        $s = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        $out = '';
        for ($i = 0; $i < strlen($s); $i++) {
            $o = ord($s[$i]);
            if ($o === 0x5C || $o === 0x28 || $o === 0x29) {
                $out .= '\\' . chr($o);
            } elseif ($o >= 0x80) {
                $out .= sprintf('\\%03o', $o);
            } else {
                $out .= chr($o);
            }
        }
        return $out;
    }

    private static function rgb(string $hex): string {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        return self::num($r) . ' ' . self::num($g) . ' ' . self::num($b);
    }

    private static function num(float $n): string {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    // ---------- Sérialisation ----------

    private function serialiser(): string {
        $this->pages[] = $this->cur; // dernière page
        $nbPages = count($this->pages);
        $objets = [];

        // 1 : Catalog, 2 : Pages, 3 : F1, 4 : F2
        $objets[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objets[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objets[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        $pageIds = [];
        $nextId = 5;
        foreach ($this->pages as $pi => $contenu) {
            $contenuId = $nextId++;
            $pageId = $nextId++;
            $pageIds[] = "$pageId 0 R";
            $objets[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::W . " " . self::H . "] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $contenuId 0 R >>";

            // Pied de page : filet + titre à gauche, page à droite
            // (coordonnées PDF mesurées depuis le BAS de la page)
            $stream = '';
            $stream .= self::rgb('#CBD5E1') . " RG 0.6 w " . self::num(self::M) . " 44 m " . self::num(self::W - self::M) . " 44 l S\n";
            $stream .= "BT /F1 8 Tf " . self::num(self::M) . " 38 Td (" . self::echapperTexte($this->titre_doc) . ") Tj ET\n";
            $stream .= "BT /F1 8 Tf " . self::num(self::W - self::M - 60) . " 38 Td (Page " . ($pi + 1) . " / $nbPages) Tj ET\n";

            foreach ($contenu as $item) {
                if ($item[0] === 't') {
                    [, $x, $yTop, $font, $taille, $hex, $texte, $align] = $item;
                    $xPdf = $x;
                    if ($align === 'c') $xPdf -= self::largeurTexte($texte, $font, $taille) / 2;
                    elseif ($align === 'd') $xPdf -= self::largeurTexte($texte, $font, $taille);
                    $stream .= self::rgb($hex) . " rg BT /$font " . self::num($taille) . " Tf " . self::num($xPdf) . " " . self::num(self::H - $yTop) . " Td (" . self::echapperTexte($texte) . ") Tj ET\n";
                } elseif ($item[0] === 'l') {
                    [, $x1, $y1, $x2, $y2, $hex, $ep] = $item;
                    $stream .= self::rgb($hex) . " RG " . self::num($ep) . " w " . self::num($x1) . " " . self::num(self::H - $y1) . " m " . self::num($x2) . " " . self::num(self::H - $y2) . " l S\n";
                } elseif ($item[0] === 'r') {
                    [, $x, $yTop, $w, $h, $hex] = $item;
                    $stream .= self::rgb($hex) . " rg " . self::num($x) . " " . self::num(self::H - $yTop - $h) . " " . self::num($w) . " " . self::num($h) . " re f\n";
                } elseif ($item[0] === 'rr') {
                    [, $x, $yTop, $w, $h, $r, $hex] = $item;
                    $stream .= self::rgb($hex) . " rg " . self::cheminRond($x, self::H - $yTop - $h, $w, $h, $r) . " f\n";
                }
            }

            $objets[$contenuId] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $objets[2] = "<< /Type /Pages /Kids [" . implode(' ', $pageIds) . "] /Count $nbPages >>";

        ksort($objets);
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objets as $id => $corps) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$corps\nendobj\n";
        }
        $xrefPos = strlen($out);
        $out .= "xref\n0 " . (max(array_keys($objets)) + 1) . "\n";
        $out .= "0000000000 65535 f \n";
        foreach ($objets as $id => $corps) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $out .= "trailer\n<< /Size " . (max(array_keys($objets)) + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";
        return $out;
    }

    /** Chemin (sans remplissage) d'un rectangle aux coins arrondis, système y montant. */
    private static function cheminRond(float $x, float $y, float $w, float $h, float $r): string {
        $k = 0.55228;
        $r = min($r, $w / 2, $h / 2);
        $a = $r * $k;
        return
            self::num($x + $r) . " " . self::num($y) . " m " .
            self::num($x + $w - $r) . " " . self::num($y) . " l " .
            self::num($x + $w - $r + $a) . " " . self::num($y) . " " . self::num($x + $w) . " " . self::num($y + $a) . " " . self::num($x + $w) . " " . self::num($y + $r) . " c " .
            self::num($x + $w) . " " . self::num($y + $h - $r) . " l " .
            self::num($x + $w) . " " . self::num($y + $h - $r + $a) . " " . self::num($x + $w - $a) . " " . self::num($y + $h) . " " . self::num($x + $w - $r) . " " . self::num($y + $h) . " c " .
            self::num($x + $r) . " " . self::num($y + $h) . " l " .
            self::num($x + $r - $a) . " " . self::num($y + $h) . " " . self::num($x) . " " . self::num($y + $h - $a) . " " . self::num($x) . " " . self::num($y + $h - $r) . " c " .
            self::num($x) . " " . self::num($y + $r) . " l " .
            self::num($x) . " " . self::num($y + $r - $a) . " " . self::num($x + $a) . " " . self::num($y) . " " . self::num($x + $r) . " " . self::num($y) . " c h";
    }

    public function sortie(): string {
        return $this->serialiser();
    }

    public function telecharger(string $nomFichier): never {
        $bin = $this->serialiser();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
        header('Content-Length: ' . strlen($bin));
        echo $bin;
        exit;
    }
}
