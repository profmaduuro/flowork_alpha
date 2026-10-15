<?php
// Minimal pure-PHP PDF generator for simple tabular reports (no libraries).
// pdf_table_send($filename, $title, $metaLines, $sections)
//   $sections = [ ['heading'=>'', 'cols'=>[ ['Label',x,align], ... ], 'rows'=>[ [c1,c2,..], ... ], 'totals'=>[..] ], ... ]
// align: 'L' (default) or 'R' (x is the right edge for R).

function pdf_t_esc(string $s): string {
    return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s);
}

function pdf_table_build(string $title, array $metaLines, array $sections): string {
    $PW = 595; $PH = 842; $L = 40; $top = $PH - 50; $bottom = 45; $lh = 14;
    $pages = []; $stream = ''; $y = 0;

    $newPage = function() use (&$pages, &$stream, &$y, $top) {
        if ($stream !== '') $pages[] = $stream;
        $stream = ''; $y = $top;
    };
    // text helper
    $T = function($x, $yy, $txt, $size=9, $bold=false) {
        $f = $bold ? 'F1' : 'F2';
        return "BT /$f $size Tf " . round($x,2) . " " . round($yy,2) . " Td (" . pdf_t_esc((string)$txt) . ") Tj ET\n";
    };
    $line = fn($x1,$yy,$x2) => "$x1 $yy m $x2 $yy l S\n";

    $newPage();
    // Title + meta
    $stream .= $T($L, $y, $title, 14, true); $y -= 20;
    foreach ($metaLines as $ml) { $stream .= $T($L, $y, $ml, 9); $y -= $lh; }
    $y -= 4;

    foreach ($sections as $sec) {
        if ($y < $bottom + 60) $newPage();
        if (!empty($sec['heading'])) { $stream .= $T($L, $y, $sec['heading'], 10, true); $y -= 16; }
        $cols = $sec['cols'];
        // header row
        $stream .= "0.6 w\n";
        foreach ($cols as $c) {
            $x = $c[1]; $al = $c[2] ?? 'L';
            $stream .= $al==='R'
                ? $T($x - strlen($c[0])*5.0, $y, $c[0], 9, true)
                : $T($x, $y, $c[0], 9, true);
        }
        $y -= 4; $stream .= $line($L, $y, $PW - $L); $y -= 12;
        // data rows
        $render = function($cells, $bold=false) use (&$stream, &$y, $cols, $T) {
            foreach ($cols as $i => $c) {
                $val = (string)($cells[$i] ?? '');
                $x = $c[1]; $al = $c[2] ?? 'L';
                $stream .= $al==='R'
                    ? $T($x - strlen($val)*5.0, $y, $val, 9, $bold)
                    : $T($x, $y, $val, 9, $bold);
            }
            $y -= 13;
        };
        foreach ($sec['rows'] as $row) {
            if ($y < $bottom) { $newPage(); }
            $render($row);
        }
        if (!empty($sec['totals'])) {
            $y -= 2; $stream .= $line($L, $y+8, $PW-$L);
            $render($sec['totals'], true);
        }
        $y -= 14;
    }
    $pages[] = $stream;

    // assemble PDF
    $objs = []; $n = 0;
    $alloc = function() use (&$objs,&$n){ $n++; $objs[$n]=''; return $n; };
    $cat=$alloc(); $pagesId=$alloc(); $pageIds=[];
    foreach ($pages as $ps) {
        $cs=$alloc();
        $objs[$cs]="<< /Length ".strlen($ps)." >>\nstream\n".$ps."endstream";
        $pg=$alloc();
        $objs[$pg]="<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 $PW $PH] /Contents $cs 0 R "
            ."/Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >> "
            ."/F2 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>";
        $pageIds[]=$pg;
    }
    $kids=implode(' 0 R ',$pageIds).' 0 R';
    $objs[$pagesId]="<< /Type /Pages /Kids [$kids] /Count ".count($pageIds)." >>";
    $objs[$cat]="<< /Type /Catalog /Pages $pagesId 0 R >>";
    $buf="%PDF-1.4\n"; $xref=[];
    for($i=1;$i<=$n;$i++){ $xref[$i]=strlen($buf); $buf.="$i 0 obj\n".$objs[$i]."\nendobj\n"; }
    $xp=strlen($buf);
    $buf.="xref\n0 ".($n+1)."\n0000000000 65535 f \n";
    for($i=1;$i<=$n;$i++) $buf.=str_pad($xref[$i],10,'0',STR_PAD_LEFT)." 00000 n \n";
    $buf.="trailer\n<< /Size ".($n+1)." /Root $cat 0 R >>\nstartxref\n$xp\n%%EOF\n";
    return $buf;
}

function pdf_table_send(string $filename, string $title, array $metaLines, array $sections): void {
    $bytes = pdf_table_build($title, $metaLines, $sections);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($bytes));
    echo $bytes;
    exit;
}
