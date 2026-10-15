<?php
// Minimal real .xlsx writer (no external libraries — uses the ZIP extension).
// Usage:
//   xlsx_send('report.xlsx', [
//     'Sheet1' => [ ['Header A','Header B'], ['text', 123.45], ... ],
//   ]);
// Numeric cells (int/float) are written as numbers; everything else as text.

function xlsx_col_letter(int $n): string {            // 0 -> A, 26 -> AA
    $s = '';
    for ($n = $n + 1; $n > 0; $n = intdiv($n - 1, 26)) {
        $s = chr(65 + ($n - 1) % 26) . $s;
    }
    return $s;
}

function xlsx_esc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsx_sheet_xml(array $rows): string {
    $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $x .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $r => $cells) {
        $x .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($cells) as $c => $val) {
            $ref = xlsx_col_letter($c) . ($r + 1);
            if (is_int($val) || is_float($val)) {
                $x .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } elseif ($val === null || $val === '') {
                $x .= '<c r="' . $ref . '"/>';
            } else {
                $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . xlsx_esc($val) . '</t></is></c>';
            }
        }
        $x .= '</row>';
    }
    $x .= '</sheetData></worksheet>';
    return $x;
}

function xlsx_build(array $sheets): string {
    $names = array_keys($sheets);
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . implode('', array_map(fn($i)=>'<Override PartName="/xl/worksheets/sheet'.($i+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($names)))
      . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>');

    $sheetsXml = ''; $relsXml = '';
    foreach ($names as $i => $name) {
        $sid = $i + 1;
        $sheetsXml .= '<sheet name="' . xlsx_esc(substr($name,0,31)) . '" sheetId="' . $sid . '" r:id="rId' . $sid . '"/>';
        $relsXml   .= '<Relationship Id="rId' . $sid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sid . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $sid . '.xml', xlsx_sheet_xml($sheets[$name]));
    }

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheets>' . $sheetsXml . '</sheets></workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . $relsXml . '</Relationships>');

    $zip->close();
    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    return $bytes;
}

// Stream an xlsx download. $sheets = ['Name' => rows[][] ]
function xlsx_send(string $filename, array $sheets): void {
    $bytes = xlsx_build($sheets);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
