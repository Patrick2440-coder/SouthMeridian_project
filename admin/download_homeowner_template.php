<?php

declare(strict_types=1);

session_start();

require_once 'admin_access.php';
require_once '../config/database.php';
requireAccess('homeowner_management');

if (
    empty($_SESSION['admin_id']) ||
    empty($_SESSION['admin_role']) ||
    !in_array($_SESSION['admin_role'], ['admin', 'superadmin'], true)
) {
    http_response_code(401);
    exit('Unauthorized.');
}

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;


/*
|--------------------------------------------------------------------------
| Current administrator
|--------------------------------------------------------------------------
*/

$adminId = (int)$_SESSION['admin_id'];

$stmt = $conn->prepare(
    "SELECT phase, role
     FROM admins
     WHERE id=?
     LIMIT 1"
);

$stmt->bind_param('i', $adminId);
$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    http_response_code(403);
    exit('Admin account was not found.');
}

$adminRole  = (string)$admin['role'];
$adminPhase = (string)$admin['phase'];


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function normalizeAdminPhase(string $phase): string
{
    if (preg_match('/(\d+)/', $phase, $matches)) {
        return 'Phase ' . (int)$matches[1];
    }

    return '';
}


function loadAddressMapping(): array
{
    $path = __DIR__ . '/southmeri_block_lot_mapping.json';

    if (!is_readable($path)) {
        throw new RuntimeException(
            'southmeri_block_lot_mapping.json was not found beside this PHP file.'
        );
    }

    $decoded = json_decode(
        (string)file_get_contents($path),
        true
    );

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'southmeri_block_lot_mapping.json contains invalid JSON.'
        );
    }

    $mapping = [];

    foreach ($decoded as $item) {
        $block = (int)($item['block'] ?? 0);
        $lot   = (int)($item['lot'] ?? 0);

        if ($block <= 0 || $lot <= 0) {
            continue;
        }

        $mapping[$block . ':' . $lot] = [
            'block'  => $block,
            'lot'    => $lot,
            'street' => trim((string)($item['street'] ?? ''))
        ];
    }

    uasort(
        $mapping,
        static function (array $a, array $b): int {
            if ($a['block'] === $b['block']) {
                return $a['lot'] <=> $b['lot'];
            }

            return $a['block'] <=> $b['block'];
        }
    );

    if (!$mapping) {
        throw new RuntimeException(
            'No valid Block/Lot entries were loaded from the mapping JSON.'
        );
    }

    return $mapping;
}


/*
|--------------------------------------------------------------------------
| Existing XLSX base file
|--------------------------------------------------------------------------
*/

$templatePath =
    __DIR__ .
    '/South_Meridian_Resident_Import_Template_v5_Block_Lot_500_Rows.xlsx';

if (!is_readable($templatePath)) {
    http_response_code(500);
    exit('The base Excel template file was not found.');
}


try {

    /*
    |--------------------------------------------------------------------------
    | Load workbook
    |--------------------------------------------------------------------------
    */

    $spreadsheet = IOFactory::load($templatePath);

    $residentSheet =
        $spreadsheet->getSheetByName('Resident Import');

    if (!$residentSheet) {
        throw new RuntimeException(
            'Sheet "Resident Import" was not found.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Final 20-column layout
    |--------------------------------------------------------------------------
    */

    $headers = [
        'A1' => 'First Name',
        'B1' => 'Middle Name',
        'C1' => 'Last Name',
        'D1' => 'Contact Number',
        'E1' => 'Email',
        'F1' => 'Phase',
        'G1' => 'Block',
        'H1' => 'Lot',
        'I1' => 'Street',
        'J1' => 'Barangay',
        'K1' => 'City/Municipality',
        'L1' => 'Province',
        'M1' => 'Region',
        'N1' => 'ZIP Code',
        'O1' => 'Country',
        'P1' => 'Other Location Info',
        'Q1' => 'Length of Residency',
        'R1' => 'Residential Type',
        'S1' => 'Emergency Contact Person',
        'T1' => 'Emergency Contact Number'
    ];

    foreach ($headers as $cell => $value) {
        $residentSheet->setCellValue($cell, $value);
    }


    /*
    |--------------------------------------------------------------------------
    | AddressLists
    |--------------------------------------------------------------------------
    */

    $addressSheet =
        $spreadsheet->getSheetByName('AddressLists');

    if (!$addressSheet) {
        $addressSheet = $spreadsheet->createSheet();
        $addressSheet->setTitle('AddressLists');
    }

    /*
     * Clear existing helper data.
     */
    $addressSheet->removeRow(
        1,
        max(1000, $addressSheet->getHighestRow())
    );


    /*
    |--------------------------------------------------------------------------
    | Canonical JSON mapping
    |--------------------------------------------------------------------------
    */

    $mapping = loadAddressMapping();

    $addressSheet->setCellValue('A1', 'Block');
    $addressSheet->setCellValue('B1', 'Lot');
    $addressSheet->setCellValue('C1', 'Street');
    $addressSheet->setCellValue('D1', 'Key');

    $lotsByBlock = [];
    $lookupRow = 2;

    foreach ($mapping as $item) {

        $block  = (int)$item['block'];
        $lot    = (int)$item['lot'];
        $street = (string)$item['street'];

        $addressSheet->setCellValue(
            "A{$lookupRow}",
            $block
        );

        $addressSheet->setCellValue(
            "B{$lookupRow}",
            $lot
        );

        $addressSheet->setCellValue(
            "C{$lookupRow}",
            $street
        );

        $addressSheet->setCellValue(
            "D{$lookupRow}",
            "{$block}:{$lot}"
        );

        $lotsByBlock[$block][] = $lot;

        $lookupRow++;
    }

    $lookupEndRow = $lookupRow - 1;


    /*
    |--------------------------------------------------------------------------
    | Unique Block list
    |--------------------------------------------------------------------------
    */

    $blocks = array_keys($lotsByBlock);

    sort($blocks, SORT_NUMERIC);

    $addressSheet->setCellValue(
        'F1',
        'Valid Blocks'
    );

    foreach ($blocks as $index => $block) {
        $addressSheet->setCellValue(
            'F' . ($index + 2),
            $block
        );
    }

    $blockEndRow = count($blocks) + 1;


    /*
    |--------------------------------------------------------------------------
    | Remove old generated named ranges
    |--------------------------------------------------------------------------
    */

    foreach ($spreadsheet->getNamedRanges() as $namedRange) {

        $name = $namedRange->getName();

        if (
            $name === 'BlockList' ||
            str_starts_with($name, 'Lots_B')
        ) {
            $spreadsheet->removeNamedRange($name);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | BlockList named range
    |--------------------------------------------------------------------------
    */

    $spreadsheet->addNamedRange(
        new NamedRange(
            'BlockList',
            $addressSheet,
            "\$F\$2:\$F\${$blockEndRow}"
        )
    );


    /*
    |--------------------------------------------------------------------------
    | Exact Lot lists per Block
    |--------------------------------------------------------------------------
    */

    $lotColumnIndex = 7; // G on AddressLists

    foreach ($blocks as $block) {

        $columnLetter =
            Coordinate::stringFromColumnIndex(
                $lotColumnIndex
            );

        $lots = array_values(
            array_unique(
                $lotsByBlock[$block]
            )
        );

        sort($lots, SORT_NUMERIC);

        $addressSheet->setCellValue(
            "{$columnLetter}1",
            "Block {$block}"
        );

        foreach ($lots as $index => $lot) {
            $addressSheet->setCellValue(
                $columnLetter . ($index + 2),
                $lot
            );
        }

        $lastLotRow = count($lots) + 1;

        $spreadsheet->addNamedRange(
            new NamedRange(
                'Lots_B' . $block,
                $addressSheet,
                "\${$columnLetter}\$2:\${$columnLetter}\${$lastLotRow}"
            )
        );

        $lotColumnIndex++;
    }


    /*
    |--------------------------------------------------------------------------
    | Admin phase
    |--------------------------------------------------------------------------
    */

    $normalizedAdminPhase =
        normalizeAdminPhase($adminPhase);


    /*
    |--------------------------------------------------------------------------
    | Build template rows
    |--------------------------------------------------------------------------
    */

    for ($row = 2; $row <= 500; $row++) {

        /*
        |--------------------------------------------------------------------------
        | Default South Meridian address
        |--------------------------------------------------------------------------
        */

        $residentSheet->setCellValue(
            "J{$row}",
            'Salitran IV'
        );

        $residentSheet->setCellValue(
            "K{$row}",
            'Dasmarinas City'
        );

        $residentSheet->setCellValue(
            "L{$row}",
            'Cavite'
        );

        $residentSheet->setCellValue(
            "M{$row}",
            'CALABARZON'
        );

        $residentSheet->setCellValueExplicit(
            "N{$row}",
            '4114',
            DataType::TYPE_STRING
        );

        $residentSheet->setCellValue(
            "O{$row}",
            'Philippines'
        );


        /*
        |--------------------------------------------------------------------------
        | Phase
        |--------------------------------------------------------------------------
        */

        if ($adminRole === 'superadmin') {

            $validation = new DataValidation();

            $validation->setType(
                DataValidation::TYPE_LIST
            );

            $validation->setErrorStyle(
                DataValidation::STYLE_STOP
            );

            $validation->setAllowBlank(false);
            $validation->setShowDropDown(true);
            $validation->setShowErrorMessage(true);
            $validation->setShowInputMessage(true);

            $validation->setErrorTitle(
                'Invalid Phase'
            );

            $validation->setError(
                'Select Phase 1, Phase 2, or Phase 3.'
            );

            $validation->setPromptTitle(
                'Phase'
            );

            $validation->setPrompt(
                'Select the resident phase.'
            );

            $validation->setFormula1(
                '"Phase 1,Phase 2,Phase 3"'
            );

            $residentSheet
                ->getCell("F{$row}")
                ->setDataValidation(
                    clone $validation
                );

        } else {

            $residentSheet->setCellValue(
                "F{$row}",
                $normalizedAdminPhase
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Block dropdown
        |--------------------------------------------------------------------------
        */

        $blockValidation =
            new DataValidation();

        $blockValidation->setType(
            DataValidation::TYPE_LIST
        );

        $blockValidation->setErrorStyle(
            DataValidation::STYLE_STOP
        );

        $blockValidation->setAllowBlank(true);
        $blockValidation->setShowDropDown(true);
        $blockValidation->setShowErrorMessage(true);
        $blockValidation->setShowInputMessage(true);

        $blockValidation->setErrorTitle(
            'Invalid Block'
        );

        $blockValidation->setError(
            'Select a valid South Meridian Block.'
        );

        $blockValidation->setPromptTitle(
            'Block'
        );

        $blockValidation->setPrompt(
            'Select the resident Block.'
        );

        $blockValidation->setFormula1(
            '=BlockList'
        );

        $residentSheet
            ->getCell("G{$row}")
            ->setDataValidation(
                clone $blockValidation
            );


        /*
        |--------------------------------------------------------------------------
        | Dependent Lot dropdown
        |--------------------------------------------------------------------------
        */

        $lotValidation =
            new DataValidation();

        $lotValidation->setType(
            DataValidation::TYPE_LIST
        );

        $lotValidation->setErrorStyle(
            DataValidation::STYLE_STOP
        );

        $lotValidation->setAllowBlank(true);
        $lotValidation->setShowDropDown(true);
        $lotValidation->setShowErrorMessage(true);
        $lotValidation->setShowInputMessage(true);

        $lotValidation->setErrorTitle(
            'Invalid Lot'
        );

        $lotValidation->setError(
            'Select a valid Lot for the selected Block.'
        );

        $lotValidation->setPromptTitle(
            'Lot'
        );

        $lotValidation->setPrompt(
            'Choose Block first, then choose Lot.'
        );

        $lotValidation->setFormula1(
            '=INDIRECT("Lots_B"&G' . $row . ')'
        );

        $residentSheet
            ->getCell("H{$row}")
            ->setDataValidation(
                clone $lotValidation
            );


        /*
        |--------------------------------------------------------------------------
        | Automatic Street
        |--------------------------------------------------------------------------
        */

        $residentSheet->setCellValue(
            "I{$row}",
            '=IF(OR(G' . $row . '="",H' . $row . '=""),"",'
            . 'IFERROR('
            . 'INDEX(AddressLists!$C$2:$C$' . $lookupEndRow . ','
            . 'MATCH(G' . $row . '&":"&H' . $row . ','
            . 'AddressLists!$D$2:$D$' . $lookupEndRow . ',0)'
            . '),""))'
        );


        /*
        |--------------------------------------------------------------------------
        | Residential Type - Column R
        |--------------------------------------------------------------------------
        */

        $typeValidation =
            new DataValidation();

        $typeValidation->setType(
            DataValidation::TYPE_LIST
        );

        $typeValidation->setErrorStyle(
            DataValidation::STYLE_STOP
        );

        $typeValidation->setAllowBlank(false);
        $typeValidation->setShowDropDown(true);
        $typeValidation->setShowErrorMessage(true);
        $typeValidation->setShowInputMessage(true);

        $typeValidation->setErrorTitle(
            'Invalid Residential Type'
        );

        $typeValidation->setError(
            'Select Owner or Renter/Tenant.'
        );

        $typeValidation->setPromptTitle(
            'Residential Type'
        );

        $typeValidation->setPrompt(
            'Select Owner or Renter/Tenant.'
        );

        $typeValidation->setFormula1(
            '"Owner,Renter/Tenant"'
        );

        $residentSheet
            ->getCell("R{$row}")
            ->setDataValidation(
                clone $typeValidation
            );
    }


    /*
    |--------------------------------------------------------------------------
    | South Meridian workbook UI
    |--------------------------------------------------------------------------
    */

    $residentSheet
        ->getTabColor()
        ->setRGB('077F46');


    /*
    |--------------------------------------------------------------------------
    | Header
    |--------------------------------------------------------------------------
    */

    $residentSheet
        ->getStyle('A1:T1')
        ->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->getStartColor()
        ->setRGB('077F46');

    $residentSheet
        ->getStyle('A1:T1')
        ->getFont()
        ->setBold(true)
        ->setSize(11)
        ->setColor(
            new Color('FFFFFFFF')
        );

    $residentSheet
        ->getStyle('A1:T1')
        ->getAlignment()
        ->setHorizontal(
            Alignment::HORIZONTAL_CENTER
        )
        ->setVertical(
            Alignment::VERTICAL_CENTER
        )
        ->setWrapText(true);

    $residentSheet
        ->getRowDimension(1)
        ->setRowHeight(40);


    /*
    |--------------------------------------------------------------------------
    | Body borders
    |--------------------------------------------------------------------------
    */

    $residentSheet
        ->getStyle('A2:T500')
        ->getAlignment()
        ->setVertical(
            Alignment::VERTICAL_CENTER
        );

    $residentSheet
        ->getStyle('A2:T500')
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(
            Border::BORDER_THIN
        )
        ->getColor()
        ->setRGB('E1E5EA');


    /*
    |--------------------------------------------------------------------------
    | User-entered fields
    |--------------------------------------------------------------------------
    */

    foreach (
        [
            'A2:E500',
            'P2:Q500',
            'S2:T500'
        ] as $range
    ) {

        $residentSheet
            ->getStyle($range)
            ->getFill()
            ->setFillType(
                Fill::FILL_SOLID
            )
            ->getStartColor()
            ->setRGB('F3FAF6');
    }


    /*
    |--------------------------------------------------------------------------
    | Dropdown fields
    |--------------------------------------------------------------------------
    */

    $residentSheet
        ->getStyle('F2:H500')
        ->getFill()
        ->setFillType(
            Fill::FILL_SOLID
        )
        ->getStartColor()
        ->setRGB('FFF8E1');

    $residentSheet
        ->getStyle('R2:R500')
        ->getFill()
        ->setFillType(
            Fill::FILL_SOLID
        )
        ->getStartColor()
        ->setRGB('FFF8E1');


    /*
    |--------------------------------------------------------------------------
    | Automatic Street
    |--------------------------------------------------------------------------
    */

    $residentSheet
        ->getStyle('I2:I500')
        ->getFill()
        ->setFillType(
            Fill::FILL_SOLID
        )
        ->getStartColor()
        ->setRGB('E9ECEF');

    $residentSheet
        ->getStyle('I2:I500')
        ->getFont()
        ->setItalic(true)
        ->setColor(
            new Color('FF495057')
        );


    /*
    |--------------------------------------------------------------------------
    | Default address fields
    |--------------------------------------------------------------------------
    */

    $residentSheet
        ->getStyle('J2:O500')
        ->getFill()
        ->setFillType(
            Fill::FILL_SOLID
        )
        ->getStartColor()
        ->setRGB('E8F5E9');

    $residentSheet
        ->getStyle('J2:O500')
        ->getFont()
        ->setColor(
            new Color('FF077F46')
        );


    /*
    |--------------------------------------------------------------------------
    | Column widths
    |--------------------------------------------------------------------------
    */

    $widths = [
        'A' => 18,
        'B' => 18,
        'C' => 18,
        'D' => 18,
        'E' => 28,
        'F' => 14,
        'G' => 10,
        'H' => 10,
        'I' => 25,
        'J' => 18,
        'K' => 20,
        'L' => 16,
        'M' => 18,
        'N' => 12,
        'O' => 16,
        'P' => 24,
        'Q' => 20,
        'R' => 20,
        'S' => 26,
        'T' => 22
    ];

    foreach ($widths as $column => $width) {
        $residentSheet
            ->getColumnDimension($column)
            ->setWidth($width);
    }

    for ($row = 2; $row <= 500; $row++) {
        $residentSheet
            ->getRowDimension($row)
            ->setRowHeight(21);
    }


    /*
    |--------------------------------------------------------------------------
    | Excel navigation
    |--------------------------------------------------------------------------
    */

    $residentSheet->freezePane('A2');

    $residentSheet->setAutoFilter(
        'A1:T500'
    );


    /*
    |--------------------------------------------------------------------------
    | Print settings
    |--------------------------------------------------------------------------
    */

    $residentSheet
        ->getPageSetup()
        ->setOrientation(
            PageSetup::ORIENTATION_LANDSCAPE
        );

    $residentSheet
        ->getPageSetup()
        ->setFitToWidth(1);

    $residentSheet
        ->getPageSetup()
        ->setFitToHeight(0);


    /*
    |--------------------------------------------------------------------------
    | Hide helper sheet
    |--------------------------------------------------------------------------
    */

    $addressSheet->setSheetState(
        Worksheet::SHEETSTATE_HIDDEN
    );

    $spreadsheet->setActiveSheetIndex(
        $spreadsheet->getIndex(
            $residentSheet
        )
    );


    /*
    |--------------------------------------------------------------------------
    | Temporary download file
    |--------------------------------------------------------------------------
    */

    $tempBase = tempnam(
        sys_get_temp_dir(),
        'south_meridian_'
    );

    if ($tempBase === false) {
        throw new RuntimeException(
            'Unable to create temporary Excel file.'
        );
    }

    @unlink($tempBase);

    $tempPath =
        $tempBase . '.xlsx';

    $writer = IOFactory::createWriter(
        $spreadsheet,
        'Xlsx'
    );

    $writer->save($tempPath);


    /*
    |--------------------------------------------------------------------------
    | Send download
    |--------------------------------------------------------------------------
    */

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header(
        'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    header(
        'Content-Disposition: attachment; filename="South_Meridian_Resident_Template.xlsx"'
    );

    header(
        'Cache-Control: max-age=0'
    );

    header(
        'Content-Length: ' .
        filesize($tempPath)
    );

    readfile($tempPath);

    @unlink($tempPath);

    exit;


} catch (Throwable $e) {

    http_response_code(500);

    echo '<h3>Unable to generate Excel template.</h3>';
    echo '<pre>';
    echo htmlspecialchars(
        $e->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    );
    echo '</pre>';

    exit;
}