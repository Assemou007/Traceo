<?php
function generer_xlsx_modele(string $titre, array $entetes, array $exemples, string $instruction): never {
    $allStrings = [''];
    foreach ([$instruction] as $s) if ($s !== '') $allStrings[] = $s;
    foreach ($entetes as $s) if ($s !== '') $allStrings[] = $s;
    foreach ($exemples as $row) foreach ($row as $v) if ((string)$v !== '') $allStrings[] = (string)$v;
    $allStrings = array_values(array_unique($allStrings));

    $siXml = '';
    foreach ($allStrings as $i => $s) {
        if ($i === 0) continue;
        $siXml .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }

    $strMap = array_flip($allStrings);

    $nbCols = count($entetes);
    $letters = [];
    for ($i = 0; $i < 26; $i++) $letters[] = chr(65 + $i);

    function xlCell(string $col, int $row, int $style, ?int $strIdx): string {
        if ($strIdx === null) return '<c r="' . $col . $row . '" s="' . $style . '"/>';
        return '<c r="' . $col . $row . '" s="' . $style . '" t="s"><v>' . $strIdx . '</v></c>';
    }

    $rowsXml = '';
    $rowsXml .= '<row r="1" ht="36" customHeight="1">';
    $rowsXml .= xlCell('A', 1, 2, $strMap[$instruction] ?? null);
    $rowsXml .= '</row>';
    $rowsXml .= '<row r="2"/>';
    $rowsXml .= '<row r="3" ht="30" customHeight="1">';
    foreach ($entetes as $i => $h) {
        $rowsXml .= xlCell($letters[$i], 3, 1, $strMap[$h] ?? null);
    }
    $rowsXml .= '</row>';
    foreach ($exemples as $ri => $row) {
        $r = 4 + $ri;
        $rowsXml .= '<row r="' . $r . '">';
        foreach ($row as $ci => $val) {
            $s = (string)$val;
            $rowsXml .= xlCell($letters[$ci], $r, 0, $s !== '' ? ($strMap[$s] ?? null) : null);
        }
        $rowsXml .= '</row>';
    }

    $colsXml = '';
    for ($i = 0; $i < $nbCols; $i++) {
        $colsXml .= '<col min="' . ($i+1) . '" max="' . ($i+1) . '" width="26" customWidth="1"/>';
    }

    $zip = new ZipArchive;
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        http_response_code(500);
        exit;
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '</Relationships>');

    $zip->addFromString('docProps/core.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties">'
        . '<dc:creator>Traceo</dc:creator>'
        . '<dc:title>' . htmlspecialchars($titre, ENT_XML1) . '</dc:title>'
        . '</cp:coreProperties>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheets><sheet name="' . htmlspecialchars($titre, ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/sharedStrings.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . (count($allStrings)-1) . '" uniqueCount="' . (count($allStrings)-1) . '">'
        . $siXml . '</sst>');

    $zip->addFromString('xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        . '<font><i/><sz val="10"/><color rgb="FF8899AA"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0B1324"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border>'
        . '<left style="thin"><color rgb="FFDDE3EA"/></left>'
        . '<right style="thin"><color rgb="FFDDE3EA"/></right>'
        . '<top style="thin"><color rgb="FFDDE3EA"/></top>'
        . '<bottom style="thin"><color rgb="FFDDE3EA"/></bottom>'
        . '<diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="3">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '</styleSheet>');

    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols>' . $colsXml . '</cols>'
        . '<sheetData>' . $rowsXml . '</sheetData>'
        . '</worksheet>');

    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="traceo_modele_' . $titre . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}
