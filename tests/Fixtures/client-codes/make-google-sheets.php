<?php

/**
 * Генератор google-sheets.xlsx и google-sheets.csv — те же данные, что в source.fods, в виде
 * файлов «Файл → Скачать» из Google Sheets: xlsx с общими строками, числами вида 12345.0,
 * формулой t="str" с вычисленным значением, листами с state="visible", rId листов после
 * theme/styles/sharedStrings и пустыми строками со стилем в конце листа; CSV — UTF-8 без
 * BOM, разделитель «,», строки через CRLF, без перевода строки в конце.
 *
 * Запуск (в контейнере): php tests/Fixtures/client-codes/make-google-sheets.php
 */
$dir = __DIR__;
$main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
$rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
$pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';
$ct = 'application/vnd.openxmlformats-officedocument';
$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
$esc = fn ($s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

$strings = [];
$s = function (string $value) use (&$strings): int {
    $index = array_search($value, $strings, true);

    return $index === false ? array_push($strings, $value) - 1 : $index;
};

$cell = fn ($ref, $style, $body) => '<c r="'.$ref.'" s="'.$style.'"'.$body.'</c>';
$str = fn ($ref, $value, $style = 1) => $cell($ref, $style, ' t="s"><v>'.$s($value).'</v>');
$num = fn ($ref, $value) => $cell($ref, 2, '><v>'.$value.'</v>');
$row = fn ($r, $cells) => '<row r="'.$r.'">'.implode('', $cells).'</row>';

$sheet1 = $row(1, [$str('A1', 'Коды товаров ООО Сантехторг', 3)])
    .$row(3, [$str('A3', 'Наш артикул', 3), $str('B3', 'Код клиента', 3), $str('C3', 'Примечание', 3)])
    .$row(4, [$str('A4', 'GR-001'), $str('B4', '00777'), $str('C4', 'текст с ведущими нулями')])
    .$row(5, [$num('A5', '12345.0'), $num('B5', '5.0')])
    .$row(7, [$str('A7', 'HG-002'), $cell('B7', 1, ' t="str"><f>CONCATENATE("HG","-","B1")</f><v>HG-B1</v>'), $str('C7', 'формула')])
    .$row(8, [$str('A8', ' OR-003 '), $str('B8', 'Код-Ё')]);

// Google дописывает строки с оформлением, но без значений
for ($r = 9; $r <= 30; $r++) {
    $sheet1 .= $row($r, [$cell("A$r", 1, '/>'), $cell("B$r", 1, '/>')]);
}
$sheet1 = str_replace('/></c>', '/>', $sheet1);

$sheet2 = $row(1, [$str('A1', 'Наш артикул'), $str('B1', 'Код клиента')])
    .$row(2, [$str('A2', 'GR-001'), $str('B2', 'ВТОРОЙ-ЛИСТ')]);

$worksheet = fn ($data) => $xml.'<worksheet xmlns="'.$main.'" xmlns:r="'.$rel.'">'
    .'<sheetPr><outlinePr summaryBelow="0" summaryRight="0"/></sheetPr>'
    .'<sheetFormatPr customHeight="1" defaultColWidth="12.63" defaultRowHeight="15.75"/>'
    .'<sheetData>'.$data.'</sheetData><drawing r:id="rId1"/></worksheet>';

$files = [
    '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default ContentType="application/xml" Extension="xml"/>'
        .'<Default ContentType="application/vnd.openxmlformats-package.relationships+xml" Extension="rels"/>'
        .'<Override ContentType="'.$ct.'.spreadsheetml.worksheet+xml" PartName="/xl/worksheets/sheet1.xml"/>'
        .'<Override ContentType="'.$ct.'.spreadsheetml.worksheet+xml" PartName="/xl/worksheets/sheet2.xml"/>'
        .'<Override ContentType="'.$ct.'.spreadsheetml.sharedStrings+xml" PartName="/xl/sharedStrings.xml"/>'
        .'<Override ContentType="'.$ct.'.drawing+xml" PartName="/xl/drawings/drawing1.xml"/>'
        .'<Override ContentType="'.$ct.'.drawing+xml" PartName="/xl/drawings/drawing2.xml"/>'
        .'<Override ContentType="'.$ct.'.spreadsheetml.styles+xml" PartName="/xl/styles.xml"/>'
        .'<Override ContentType="'.$ct.'.spreadsheetml.sheet.main+xml" PartName="/xl/workbook.xml"/>'
        .'</Types>',
    '_rels/.rels' => $xml.'<Relationships xmlns="'.$pkg.'"><Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => $xml.'<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><workbookPr/><sheets>'
        .'<sheet state="visible" name="Коды" sheetId="1" r:id="rId4"/><sheet state="visible" name="Прочее" sheetId="2" r:id="rId5"/>'
        .'</sheets><definedNames/><calcPr/></workbook>',
    'xl/_rels/workbook.xml.rels' => $xml.'<Relationships xmlns="'.$pkg.'">'
        .'<Relationship Id="rId2" Type="'.$rel.'/styles" Target="styles.xml"/>'
        .'<Relationship Id="rId3" Type="'.$rel.'/sharedStrings" Target="sharedStrings.xml"/>'
        .'<Relationship Id="rId4" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId5" Type="'.$rel.'/worksheet" Target="worksheets/sheet2.xml"/>'
        .'</Relationships>',
    'xl/styles.xml' => $xml.'<styleSheet xmlns="'.$main.'"><fonts count="2"><font><sz val="10.0"/><color rgb="FF000000"/><name val="Arial"/><scheme val="minor"/></font>'
        .'<font><b/><color theme="1"/><name val="Arial"/><scheme val="minor"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="lightGray"/></fill></fills>'
        .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf borderId="0" fillId="0" fontId="0" numFmtId="0" applyAlignment="1" applyFont="1"/></cellStyleXfs>'
        .'<cellXfs count="4"><xf borderId="0" fillId="0" fontId="0" numFmtId="0" xfId="0" applyAlignment="1" applyFont="1"/>'
        .'<xf borderId="0" fillId="0" fontId="0" numFmtId="49" xfId="0" applyAlignment="1" applyFont="1" applyNumberFormat="1"/>'
        .'<xf borderId="0" fillId="0" fontId="0" numFmtId="0" xfId="0" applyAlignment="1" applyFont="1"/>'
        .'<xf borderId="0" fillId="0" fontId="1" numFmtId="0" xfId="0" applyAlignment="1" applyFont="1"/></cellXfs>'
        .'<cellStyles count="1"><cellStyle xfId="0" name="Normal" builtinId="0"/></cellStyles><dxfs count="0"/></styleSheet>',
    'xl/worksheets/sheet1.xml' => $worksheet($sheet1),
    'xl/worksheets/sheet2.xml' => $worksheet($sheet2),
    'xl/worksheets/_rels/sheet1.xml.rels' => $xml.'<Relationships xmlns="'.$pkg.'"><Relationship Id="rId1" Type="'.$rel.'/drawing" Target="../drawings/drawing1.xml"/></Relationships>',
    'xl/worksheets/_rels/sheet2.xml.rels' => $xml.'<Relationships xmlns="'.$pkg.'"><Relationship Id="rId1" Type="'.$rel.'/drawing" Target="../drawings/drawing2.xml"/></Relationships>',
    'xl/drawings/drawing1.xml' => $xml.'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"/>',
    'xl/drawings/drawing2.xml' => $xml.'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"/>',
];

// Общие строки — после листов: индексы заполняются при сборке листов
$files['xl/sharedStrings.xml'] = $xml.'<sst xmlns="'.$main.'" count="'.count($strings).'" uniqueCount="'.count($strings).'">'
    .implode('', array_map(fn ($v) => '<si><t xml:space="preserve">'.$esc($v).'</t></si>', $strings)).'</sst>';

$path = $dir.'/google-sheets.xlsx';
@unlink($path);
$zip = new ZipArchive;
$zip->open($path, ZipArchive::CREATE);
foreach ($files as $name => $content) {
    $zip->addFromString($name, $content);
    $zip->setMtimeName($name, mktime(0, 0, 0, 1, 1, 1980));
}
$zip->close();

file_put_contents($dir.'/google-sheets.csv', implode("\r\n", [
    'Коды товаров ООО Сантехторг,,',
    ',,',
    'Наш артикул,Код клиента,Примечание',
    'GR-001,00777,текст с ведущими нулями',
    '12345,5,',
    ',,',
    'HG-002,HG-B1,формула',
    ' OR-003 ,Код-Ё,',
]));

echo "google-sheets.xlsx, google-sheets.csv\n";
