<?php
session_start();
require_once 'admin_access.php';
requireAccess('dashboard');

/* =========================
   PHASE (LOCKED)
   ========================= */
$allowedPhases = ['Phase 1', 'Phase 2', 'Phase 3'];
$phase = in_array($myPhase, $allowedPhases, true) ? $myPhase : 'Phase 1';


/* ============================================================
   HOMEOWNER PROFILE HELPERS
   ============================================================ */

function dashboard_phase_prefix(string $phase): string
{
    $number =
        (int)filter_var(
            $phase,
            FILTER_SANITIZE_NUMBER_INT
        );

    return $number > 0
        ? 'P' . $number
        : 'P';
}


function dashboard_subdivision_block_lot(array $record): array
{
    $block =
        (int)(
            $record['block']
            ?? 0
        );

    $lot =
        (int)(
            $record['lot']
            ?? 0
        );

    /*
     * Legacy fallback:
     * "Block 2 Lot 8", "Blk 2 Lot 8", etc.
     */
    if (
        $block <= 0 ||
        $lot <= 0
    ) {
        $legacy =
            trim(
                (string)(
                    $record['house_lot_number']
                    ?? ''
                )
            );

        if (
            preg_match(
                '/(?:block|blk|b)\s*[-:]?\s*(\d+)\D+(?:lot|l)\s*[-:]?\s*(\d+)/i',
                $legacy,
                $match
            )
        ) {
            $block =
                (int)$match[1];

            $lot =
                (int)$match[2];
        }
    }

    return [
        $block,
        $lot
    ];
}


function dashboard_south_meridian_locations(): array
{
    static $locations = null;

    if ($locations !== null) {
        return $locations;
    }

    $locations = [];

    $mappingPath =
        __DIR__
        . DIRECTORY_SEPARATOR
        . 'southmeri_block_lot_mapping.json';

    if (!is_readable($mappingPath)) {
        return $locations;
    }

    $decoded =
        json_decode(
            (string)file_get_contents(
                $mappingPath
            ),
            true
        );

    if (!is_array($decoded)) {
        return $locations;
    }

    /*
     * Same coordinate conversion used by the current
     * Approved Homeowners page.
     *
     * Official map image size:
     * 2550 x 3300.
     */
    $pageWidthEmu =
        8.5 * 914400;

    $pageHeightEmu =
        11 * 914400;

    $pageMarginEmu =
        914400;

    $markerCenterOffsetEmu =
        90000;

    foreach ($decoded as $row) {
        $block =
            (int)(
                $row['block']
                ?? 0
            );

        $lot =
            (int)(
                $row['lot']
                ?? 0
            );

        $xEmu =
            (float)(
                $row['x_emu']
                ?? -1
            );

        $yEmu =
            (float)(
                $row['y_emu']
                ?? -1
            );

        if (
            $block < 1 ||
            $lot < 1 ||
            $xEmu < -$pageMarginEmu ||
            $yEmu < -$pageMarginEmu
        ) {
            continue;
        }

        $locations[
            $block . ':' . $lot
        ] = [
            'block' =>
                $block,

            'lot' =>
                $lot,

            'street' =>
                trim(
                    (string)(
                        $row['street']
                        ?? ''
                    )
                ),

            'x' =>
                round(
                    (
                        (
                            $pageMarginEmu +
                            $xEmu +
                            $markerCenterOffsetEmu
                        )
                        /
                        $pageWidthEmu
                    )
                    * 2550,
                    2
                ),

            'y' =>
                round(
                    (
                        (
                            $pageMarginEmu +
                            $yEmu +
                            $markerCenterOffsetEmu
                        )
                        /
                        $pageHeightEmu
                    )
                    * 3300,
                    2
                )
        ];
    }

    return $locations;
}


function dashboard_document_url(string $path): string
{
    $path =
        trim(
            str_replace(
                '\\',
                '/',
                $path
            )
        );

    if ($path === '') {
        return '';
    }

    if (
        preg_match(
            '~^https?://~i',
            $path
        )
    ) {
        return $path;
    }

    if (
        str_starts_with(
            $path,
            'uploads/'
        )
    ) {
        return
            '../'
            . $path;
    }

    return $path;
}


function dashboard_is_import_placeholder(string $path): bool
{
    $path =
        trim($path);

    return
        $path === '' ||
        str_starts_with(
            $path,
            'imports/'
        );
}


/* ============================================================
   AJAX: MODERN HOMEOWNER PROFILE
   ============================================================ */

if (
    ($_GET['ajax'] ?? '')
    ===
    'homeowner_profile'
) {
    $homeownerId =
        (int)(
            $_GET['id']
            ?? 0
        );

    if ($homeownerId <= 0) {
        http_response_code(400);

        echo '
          <div class="hp-empty-state is-error">
            <div class="hp-empty-icon">!</div>
            <strong>Invalid homeowner ID.</strong>
          </div>
        ';

        exit;
    }

    /*
     * Dashboard is phase locked, so even a manually changed ID
     * cannot expose a homeowner from another phase.
     */
    $profileStmt =
        $conn->prepare("
          SELECT *
          FROM homeowners
          WHERE id = ?
            AND phase = ?
            AND status IN ('approved','pending')
          LIMIT 1
        ");

    $profileStmt->bind_param(
        'is',
        $homeownerId,
        $phase
    );

    $profileStmt->execute();

    $homeowner =
        $profileStmt
            ->get_result()
            ->fetch_assoc();

    $profileStmt->close();

    if (!$homeowner) {
        http_response_code(404);

        echo '
          <div class="hp-empty-state is-error">
            <div class="hp-empty-icon">!</div>
            <strong>Homeowner not found or outside your assigned phase.</strong>
          </div>
        ';

        exit;
    }


    /* -------------------------
       Household members
       ------------------------- */

    $memberStmt =
        $conn->prepare("
          SELECT
            id,
            first_name,
            middle_name,
            last_name,
            relation
          FROM household_members
          WHERE homeowner_id = ?
          ORDER BY id ASC
        ");

    $memberStmt->bind_param(
        'i',
        $homeownerId
    );

    $memberStmt->execute();

    $householdMembers =
        $memberStmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $memberStmt->close();


    /* -------------------------
       Tenants
       ------------------------- */

    $tenantStmt =
        $conn->prepare("
          SELECT
            id,
            first_name,
            middle_name,
            last_name,
            email,
            contact_number,
            house_lot_number,
            lease_start,
            lease_end,
            status,
            registered_at
          FROM tenants
          WHERE homeowner_id = ?
          ORDER BY registered_at DESC, id DESC
        ");

    $tenantStmt->bind_param(
        'i',
        $homeownerId
    );

    $tenantStmt->execute();

    $tenants =
        $tenantStmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $tenantStmt->close();


    /* -------------------------
       Homeowner / HOA position
       ------------------------- */

    $positionStmt =
        $conn->prepare("
          SELECT position
          FROM homeowner_positions
          WHERE homeowner_id = ?
            AND phase = ?
          LIMIT 1
        ");

    $positionStmt->bind_param(
        'is',
        $homeownerId,
        $phase
    );

    $positionStmt->execute();

    $positionRow =
        $positionStmt
            ->get_result()
            ->fetch_assoc();

    $positionStmt->close();

    $homeownerPosition =
        trim(
            (string)(
                $positionRow['position']
                ?? 'Homeowner'
            )
        );

    if ($homeownerPosition === '') {
        $homeownerPosition =
            'Homeowner';
    }


    /* -------------------------
       Display values
       ------------------------- */

    $displayValue =
        static function (
            $value,
            string $fallback = 'Not provided'
        ): string {
            $value =
                trim(
                    (string)(
                        $value
                        ?? ''
                    )
                );

            return
                $value !== ''
                    ? $value
                    : $fallback;
        };


    $fullName =
        trim(
            (string)(
                $homeowner['first_name']
                ?? ''
            )
            . ' ' .
            (string)(
                $homeowner['middle_name']
                ?? ''
            )
            . ' ' .
            (string)(
                $homeowner['last_name']
                ?? ''
            )
        );


    $initials = '';

    foreach (
        [
            $homeowner['first_name']
                ?? '',
            $homeowner['last_name']
                ?? ''
        ] as $namePart
    ) {
        $namePart =
            trim(
                (string)$namePart
            );

        if ($namePart !== '') {
            $initials .=
                strtoupper(
                    substr(
                        $namePart,
                        0,
                        1
                    )
                );
        }
    }

    if ($initials === '') {
        $initials = 'HO';
    }


    $displayId =
        trim(
            (string)(
                $homeowner['public_id']
                ?? ''
            )
        );

    if ($displayId === '') {
        $displayId =
            dashboard_phase_prefix(
                (string)(
                    $homeowner['phase']
                    ?? ''
                )
            )
            .
            (int)$homeowner['id'];
    }


    [
        $homeownerBlock,
        $homeownerLot
    ] =
        dashboard_subdivision_block_lot(
            $homeowner
        );


    $locations =
        dashboard_south_meridian_locations();

    $mapLocation = null;

    if (
        $homeownerBlock > 0 &&
        $homeownerLot > 0
    ) {
        $mapLocation =
            $locations[
                $homeownerBlock
                . ':'
                . $homeownerLot
            ]
            ?? null;
    }


    $homeownerStreet =
        trim(
            (string)(
                $homeowner['street']
                ?? ''
            )
        );

    if (
        $homeownerStreet === '' &&
        !empty(
            $mapLocation['street']
        )
    ) {
        $homeownerStreet =
            trim(
                (string)
                    $mapLocation['street']
            );
    }


    /*
     * Official JSON mapping is preferred.
     * Existing DB coordinates remain only as a legacy fallback.
     */
    $mapX =
        $mapLocation['x']
        ??
        $homeowner['map_x']
        ??
        null;

    $mapY =
        $mapLocation['y']
        ??
        $homeowner['map_y']
        ??
        null;


    $hasSubdivisionMap =
        $homeownerBlock > 0 &&
        $homeownerLot > 0 &&
        $mapX !== null &&
        $mapY !== null &&
        is_numeric($mapX) &&
        is_numeric($mapY);


    $markerLeft =
        $hasSubdivisionMap
            ? (
                ((float)$mapX / 2550)
                * 100
            )
            : 0;

    $markerTop =
        $hasSubdivisionMap
            ? (
                ((float)$mapY / 3300)
                * 100
            )
            : 0;


    /*
     * Address defaults requested for South Meridian.
     */
    $homeownerRegion =
        trim(
            (string)(
                $homeowner['region']
                ?? ''
            )
        );

    if ($homeownerRegion === '') {
        $homeownerRegion =
            'CALABARZON';
    }


    $homeownerZipCode =
        trim(
            (string)(
                $homeowner['zip_code']
                ?? ''
            )
        );

    if ($homeownerZipCode === '') {
        $homeownerZipCode =
            '4114';
    }


    $homeownerCountry =
        trim(
            (string)(
                $homeowner['country']
                ?? ''
            )
        );

    if ($homeownerCountry === '') {
        $homeownerCountry =
            'Philippines';
    }


    $propertyAddress =
        (
            $homeownerBlock > 0 &&
            $homeownerLot > 0
        )
            ? "Block {$homeownerBlock}, Lot {$homeownerLot}"
            : trim(
                (string)(
                    $homeowner['house_lot_number']
                    ?? ''
                )
            );


    $fullAddress =
        trim(
            implode(
                ', ',
                array_filter(
                    [
                        $propertyAddress,
                        $homeownerStreet,
                        $homeowner['other_location_info']
                            ?? '',
                        $homeowner['barangay']
                            ?? '',
                        $homeowner['city_municipality']
                            ?? '',
                        $homeowner['province']
                            ?? '',
                        $homeownerRegion,
                        $homeownerZipCode,
                        $homeownerCountry
                    ],
                    static fn($value) =>
                        trim(
                            (string)$value
                        )
                        !==
                        ''
                )
            )
        );


    $createdAt =
        !empty(
            $homeowner['created_at']
        )
            ? date(
                'M d, Y · h:i A',
                strtotime(
                    (string)
                        $homeowner['created_at']
                )
            )
            : 'Not provided';


    $status =
        strtolower(
            trim(
                (string)(
                    $homeowner['status']
                    ?? 'pending'
                )
            )
        );

    $statusClass =
        $status === 'approved'
            ? 'is-approved'
            : 'is-pending';


    $profilePicture =
        trim(
            (string)(
                $homeowner['profile_picture_path']
                ?? ''
            )
        );

    $profilePictureUrl =
        $profilePicture !== ''
            ? dashboard_document_url(
                $profilePicture
            )
            : '';


    $validIdPath =
        trim(
            (string)(
                $homeowner['valid_id_path']
                ?? ''
            )
        );

    $proofPath =
        trim(
            (string)(
                $homeowner['proof_of_billing_path']
                ?? ''
            )
        );

    $validIdUrl =
        !dashboard_is_import_placeholder(
            $validIdPath
        )
            ? dashboard_document_url(
                $validIdPath
            )
            : '';

    $proofUrl =
        !dashboard_is_import_placeholder(
            $proofPath
        )
            ? dashboard_document_url(
                $proofPath
            )
            : '';


    $mapImageFile =
        dirname(__DIR__)
        . DIRECTORY_SEPARATOR
        . 'assets'
        . DIRECTORY_SEPARATOR
        . 'img'
        . DIRECTORY_SEPARATOR
        . 'south_meridian_block_lot_map.png';

    $mapImageVersion =
        is_file(
            $mapImageFile
        )
            ? (int)filemtime(
                $mapImageFile
            )
            : time();
    ?>

    <div class="hp-profile">

      <!-- HERO -->
      <section class="hp-hero">
        <div class="hp-identity">
          <div class="hp-avatar">
            <?php if ($profilePictureUrl !== ''): ?>
              <img
                src="<?= esc($profilePictureUrl) ?>"
                alt="<?= esc($fullName) ?>"
              >
            <?php else: ?>
              <span><?= esc($initials) ?></span>
            <?php endif; ?>
          </div>

          <div class="hp-identity-copy">
            <div class="hp-kicker">
              South Meridian Homeowner
            </div>

            <h2>
              <?= esc(
                  $fullName !== ''
                      ? $fullName
                      : 'Unnamed Homeowner'
              ) ?>
            </h2>

            <div class="hp-hero-badges">
              <span class="hp-status <?= esc($statusClass) ?>">
                <?= esc(ucfirst($status)) ?>
              </span>

              <span class="hp-soft-badge">
                <?= esc($displayId) ?>
              </span>

              <span class="hp-soft-badge">
                <?= esc($homeownerPosition) ?>
              </span>
            </div>
          </div>
        </div>

        <div class="hp-quick-grid">
          <div>
            <span>Phase</span>
            <strong>
              <?= esc(
                  $displayValue(
                      $homeowner['phase']
                      ?? null
                  )
              ) ?>
            </strong>
          </div>

          <div>
            <span>Property</span>
            <strong>
              <?= esc(
                  $propertyAddress !== ''
                      ? $propertyAddress
                      : 'Not provided'
              ) ?>
            </strong>
          </div>

          <div>
            <span>Registered</span>
            <strong>
              <?= esc($createdAt) ?>
            </strong>
          </div>
        </div>
      </section>


      <div class="hp-main-grid">

        <!-- LEFT: MAP -->
        <aside class="hp-side-column">
          <section class="hp-card hp-map-card">
            <div class="hp-card-head">
              <div>
                <span class="hp-section-kicker">
                  Property
                </span>

                <h3>
                  South Meridian Map
                </h3>
              </div>

              <span class="hp-map-chip">
                Block <?= (int)$homeownerBlock ?>
                ·
                Lot <?= (int)$homeownerLot ?>
              </span>
            </div>

            <?php if ($hasSubdivisionMap): ?>

              <div class="hp-property-map">
                <img
                  src="../assets/img/south_meridian_block_lot_map.png?v=<?= (int)$mapImageVersion ?>"
                  alt="South Meridian Block and Lot Map"
                >

                <div
                  class="hp-property-pin"
                  style="
                    left:<?= esc(number_format($markerLeft, 4, '.', '')) ?>%;
                    top:<?= esc(number_format($markerTop, 4, '.', '')) ?>%;
                  "
                  title="Block <?= (int)$homeownerBlock ?>, Lot <?= (int)$homeownerLot ?>"
                  aria-hidden="true"
                >
                  <span class="hp-pin-dot"></span>
                </div>
              </div>

              <div class="hp-map-caption">
                <strong>
                  Block <?= (int)$homeownerBlock ?>,
                  Lot <?= (int)$homeownerLot ?>
                </strong>

                <?php if ($homeownerStreet !== ''): ?>
                  <span>
                    <?= esc($homeownerStreet) ?>
                  </span>
                <?php endif; ?>
              </div>

            <?php else: ?>

              <div class="hp-empty-state">
                <div class="hp-empty-icon">
                  <i class="dw dw-placeholder"></i>
                </div>

                <strong>
                  Map location unavailable
                </strong>

                <span>
                  The Block/Lot has no matching location in the current official map data.
                </span>
              </div>

            <?php endif; ?>

            <div class="hp-address-box">
              <span>
                Complete Address
              </span>

              <strong>
                <?= esc(
                    $fullAddress !== ''
                        ? $fullAddress
                        : 'Not provided'
                ) ?>
              </strong>
            </div>
          </section>


          <section class="hp-card">
            <div class="hp-card-head">
              <div>
                <span class="hp-section-kicker">
                  Account
                </span>

                <h3>
                  Contact
                </h3>
              </div>
            </div>

            <div class="hp-stack-list">
              <div>
                <span>Email</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['email']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Contact Number</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['contact_number']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>HOA Position</span>
                <strong>
                  <?= esc($homeownerPosition) ?>
                </strong>
              </div>
            </div>
          </section>
        </aside>


        <!-- RIGHT: DETAILS -->
        <div class="hp-content-column">

          <section class="hp-card">
            <div class="hp-card-head">
              <div>
                <span class="hp-section-kicker">
                  Homeowner
                </span>

                <h3>
                  Personal Information
                </h3>
              </div>
            </div>

            <div class="hp-detail-grid">
              <div>
                <span>First Name</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['first_name']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Middle Name</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['middle_name']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Last Name</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['last_name']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Residential Type</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['residential_type']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Length of Residency</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['length_of_residency']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Status</span>
                <strong>
                  <?= esc(ucfirst($status)) ?>
                </strong>
              </div>
            </div>
          </section>


          <section class="hp-card">
            <div class="hp-card-head">
              <div>
                <span class="hp-section-kicker">
                  Residence
                </span>

                <h3>
                  Address Details
                </h3>
              </div>
            </div>

            <div class="hp-detail-grid">
              <div>
                <span>Block</span>
                <strong>
                  <?= esc(
                      $homeownerBlock > 0
                          ? $homeownerBlock
                          : 'Not provided'
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Lot</span>
                <strong>
                  <?= esc(
                      $homeownerLot > 0
                          ? $homeownerLot
                          : 'Not provided'
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Street</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeownerStreet
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Barangay</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['barangay']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>City / Municipality</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['city_municipality']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Province</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['province']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Region</span>
                <strong>
                  <?= esc($homeownerRegion) ?>
                </strong>
              </div>

              <div>
                <span>ZIP Code</span>
                <strong>
                  <?= esc($homeownerZipCode) ?>
                </strong>
              </div>

              <div>
                <span>Country</span>
                <strong>
                  <?= esc($homeownerCountry) ?>
                </strong>
              </div>

              <div class="hp-detail-wide">
                <span>Other Location Information</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['other_location_info']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>
            </div>
          </section>


          <section class="hp-card">
            <div class="hp-card-head">
              <div>
                <span class="hp-section-kicker">
                  Safety
                </span>

                <h3>
                  Emergency Contact
                </h3>
              </div>
            </div>

            <div class="hp-detail-grid hp-detail-grid-two">
              <div>
                <span>Contact Person</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['emergency_contact_person']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>

              <div>
                <span>Contact Number</span>
                <strong>
                  <?= esc(
                      $displayValue(
                          $homeowner['emergency_contact_number']
                          ?? null
                      )
                  ) ?>
                </strong>
              </div>
            </div>
          </section>

        </div>
      </div>


      <!-- HOUSEHOLD -->
      <section class="hp-card">
        <div class="hp-card-head">
          <div>
            <span class="hp-section-kicker">
              Household
            </span>

            <h3>
              Household Members
            </h3>
          </div>

          <span class="hp-count-chip">
            <?= count($householdMembers) ?>
            member<?= count($householdMembers) === 1 ? '' : 's' ?>
          </span>
        </div>

        <?php if (!empty($householdMembers)): ?>

          <div class="hp-table-wrap">
            <table class="hp-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>Relation</th>
                </tr>
              </thead>

              <tbody>
                <?php foreach ($householdMembers as $index => $member): ?>
                  <?php
                    $memberName =
                        trim(
                            (string)(
                                $member['first_name']
                                ?? ''
                            )
                            . ' ' .
                            (string)(
                                $member['middle_name']
                                ?? ''
                            )
                            . ' ' .
                            (string)(
                                $member['last_name']
                                ?? ''
                            )
                        );
                  ?>

                  <tr>
                    <td>
                      <?= $index + 1 ?>
                    </td>

                    <td>
                      <strong>
                        <?= esc(
                            $memberName !== ''
                                ? $memberName
                                : 'Unnamed Member'
                        ) ?>
                      </strong>
                    </td>

                    <td>
                      <?= esc(
                          $displayValue(
                              $member['relation']
                              ?? null
                          )
                      ) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        <?php else: ?>

          <div class="hp-empty-state hp-empty-compact">
            <strong>
              No household members found.
            </strong>
          </div>

        <?php endif; ?>
      </section>


      <!-- TENANTS -->
      <section class="hp-card">
        <div class="hp-card-head">
          <div>
            <span class="hp-section-kicker">
              Tenant Records
            </span>

            <h3>
              Registered Tenants
            </h3>
          </div>

          <span class="hp-count-chip">
            <?= count($tenants) ?>
            tenant<?= count($tenants) === 1 ? '' : 's' ?>
          </span>
        </div>

        <?php if (!empty($tenants)): ?>

          <div class="hp-table-wrap">
            <table class="hp-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Contact</th>
                  <th>Lease Period</th>
                  <th>Status</th>
                </tr>
              </thead>

              <tbody>
                <?php foreach ($tenants as $tenant): ?>
                  <?php
                    $tenantName =
                        trim(
                            (string)(
                                $tenant['first_name']
                                ?? ''
                            )
                            . ' ' .
                            (string)(
                                $tenant['middle_name']
                                ?? ''
                            )
                            . ' ' .
                            (string)(
                                $tenant['last_name']
                                ?? ''
                            )
                        );

                    $leaseStart =
                        !empty(
                            $tenant['lease_start']
                        )
                            ? date(
                                'M d, Y',
                                strtotime(
                                    (string)
                                        $tenant['lease_start']
                                )
                            )
                            : '—';

                    $leaseEnd =
                        !empty(
                            $tenant['lease_end']
                        )
                            ? date(
                                'M d, Y',
                                strtotime(
                                    (string)
                                        $tenant['lease_end']
                                )
                            )
                            : '—';

                    $tenantStatus =
                        strtolower(
                            trim(
                                (string)(
                                    $tenant['status']
                                    ?? 'inactive'
                                )
                            )
                        );
                  ?>

                  <tr>
                    <td>
                      <strong>
                        <?= esc(
                            $tenantName !== ''
                                ? $tenantName
                                : 'Unnamed Tenant'
                        ) ?>
                      </strong>
                    </td>

                    <td>
                      <div>
                        <?= esc(
                            $displayValue(
                                $tenant['contact_number']
                                ?? null
                            )
                        ) ?>
                      </div>

                      <small>
                        <?= esc(
                            $displayValue(
                                $tenant['email']
                                ?? null
                            )
                        ) ?>
                      </small>
                    </td>

                    <td>
                      <?= esc(
                          $leaseStart
                          . ' → '
                          . $leaseEnd
                      ) ?>
                    </td>

                    <td>
                      <span
                        class="hp-status <?= $tenantStatus === 'active' ? 'is-approved' : 'is-neutral' ?>"
                      >
                        <?= esc(
                            ucfirst(
                                $tenantStatus
                            )
                        ) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

        <?php else: ?>

          <div class="hp-empty-state hp-empty-compact">
            <strong>
              No registered tenants found.
            </strong>
          </div>

        <?php endif; ?>
      </section>


      <!-- DOCUMENTS -->
      <section class="hp-card">
        <div class="hp-card-head">
          <div>
            <span class="hp-section-kicker">
              Verification
            </span>

            <h3>
              Uploaded Documents
            </h3>
          </div>
        </div>

        <div class="hp-document-grid">
          <div class="hp-document-card">
            <div class="hp-document-icon">
              <i class="dw dw-id-card"></i>
            </div>

            <div>
              <strong>
                Valid ID
              </strong>

              <span>
                <?= $validIdUrl !== '' ? 'Document available' : 'Not provided' ?>
              </span>
            </div>

            <?php if ($validIdUrl !== ''): ?>
              <a
                href="<?= esc($validIdUrl) ?>"
                target="_blank"
                rel="noopener"
                class="hp-document-action"
              >
                Open
              </a>
            <?php endif; ?>
          </div>


          <div class="hp-document-card">
            <div class="hp-document-icon">
              <i class="dw dw-file"></i>
            </div>

            <div>
              <strong>
                Proof of Billing
              </strong>

              <span>
                <?= $proofUrl !== '' ? 'Document available' : 'Not provided' ?>
              </span>
            </div>

            <?php if ($proofUrl !== ''): ?>
              <a
                href="<?= esc($proofUrl) ?>"
                target="_blank"
                rel="noopener"
                class="hp-document-action"
              >
                Open
              </a>
            <?php endif; ?>
          </div>
        </div>
      </section>

    </div>

    <?php
    exit;
}


/* =========================
   6) KPI QUERIES
   ========================= */

/* Approved homeowners */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM homeowners WHERE phase=? AND status='approved'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$approvedCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* Pending homeowners */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM homeowners WHERE phase=? AND status='pending'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* Pending finance report requests */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM finance_report_requests WHERE phase=? AND status='pending'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingReportCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* This month collections */
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) s
  FROM finance_payments
  WHERE phase=? AND status='paid'
    AND YEAR(paid_at)=YEAR(CURDATE())
    AND MONTH(paid_at)=MONTH(CURDATE())
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$thisMonthCollections = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
$stmt->close();

/* This month expenses */
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(amount),0) s
  FROM finance_expenses
  WHERE phase=?
    AND YEAR(expense_date)=YEAR(CURDATE())
    AND MONTH(expense_date)=MONTH(CURDATE())
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$thisMonthExpenses = (float)($stmt->get_result()->fetch_assoc()['s'] ?? 0);
$stmt->close();

/* =========================
   6B) COMPLAINT KPI QUERIES
   ========================= */
$stmt = $conn->prepare("SELECT COUNT(*) c FROM complaints WHERE phase=?");
$stmt->bind_param("s", $phase);
$stmt->execute();
$complaintTotal = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM complaints WHERE phase=? AND status='open'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$complaintOpen = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM complaints WHERE phase=? AND status='in_progress'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$complaintInProgress = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM complaints WHERE phase=? AND status='resolved'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$complaintResolved = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) c FROM complaints WHERE phase=? AND status='closed'");
$stmt->bind_param("s", $phase);
$stmt->execute();
$complaintClosed = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
$stmt->close();

/* =========================
   7) CHART DATA (LAST 6 MONTHS)
   ========================= */
$labels = [];
$keys   = [];

for ($i = 5; $i >= 0; $i--) {
  $ts = strtotime(date('Y-m-01') . " -$i months");
  $labels[] = date('M Y', $ts);
  $keys[]   = date('Y-m', $ts);
}

$fromDate = date('Y-m-01', strtotime(date('Y-m-01') . " -5 months"));
$toDate   = date('Y-m-t');

/* Monthly collections */
$collectionsByKey = array_fill_keys($keys, 0.0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(paid_at,'%Y-%m') ym, COALESCE(SUM(amount),0) total
  FROM finance_payments
  WHERE phase=? AND status='paid'
    AND paid_at >= ?
    AND paid_at < DATE_ADD(?, INTERVAL 1 DAY)
  GROUP BY ym
");
$stmt->bind_param("sss", $phase, $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $ym = (string)$r['ym'];
  if (isset($collectionsByKey[$ym])) $collectionsByKey[$ym] = (float)$r['total'];
}
$stmt->close();

/* Monthly expenses */
$expensesByKey = array_fill_keys($keys, 0.0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(expense_date,'%Y-%m') ym, COALESCE(SUM(amount),0) total
  FROM finance_expenses
  WHERE phase=?
    AND expense_date >= ?
    AND expense_date <= ?
  GROUP BY ym
");
$stmt->bind_param("sss", $phase, $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $ym = (string)$r['ym'];
  if (isset($expensesByKey[$ym])) $expensesByKey[$ym] = (float)$r['total'];
}
$stmt->close();

/* Monthly new homeowners */
$newHOByKey = array_fill_keys($keys, 0);
$stmt = $conn->prepare("
  SELECT DATE_FORMAT(created_at,'%Y-%m') ym, COUNT(*) c
  FROM homeowners
  WHERE phase=?
    AND created_at >= ?
    AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
  GROUP BY ym
");
$stmt->bind_param("sss", $phase, $fromDate, $toDate);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $ym = (string)$r['ym'];
  if (isset($newHOByKey[$ym])) $newHOByKey[$ym] = (int)$r['c'];
}
$stmt->close();

$chartCollections = array_values($collectionsByKey);
$chartExpenses    = array_values($expensesByKey);
$chartNewHO       = array_values($newHOByKey);

/* =========================
   8) TABLE: HOMEOWNERS (APPROVED + PENDING)
   ========================= */
$stmt = $conn->prepare("
  SELECT id, first_name, middle_name, last_name, house_lot_number, status, created_at
  FROM homeowners
  WHERE phase=? AND status IN ('approved','pending')
  ORDER BY FIELD(status,'pending','approved'), created_at DESC
  LIMIT 200
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$homeownersRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =========================
   9) TABLE: PENDING FINANCE REPORT REQUESTS
   ========================= */
$stmt = $conn->prepare("
  SELECT r.*, a.email AS requested_by_email, a.full_name AS requested_by_name
  FROM finance_report_requests r
  LEFT JOIN admins a ON a.id=r.requested_by_admin_id
  WHERE r.phase=? AND r.status='pending'
  ORDER BY r.requested_at DESC
  LIMIT 50
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$pendingReports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* =========================
   9B) TABLE: RECENT COMPLAINTS
   ========================= */
$stmt = $conn->prepare("
  SELECT c.id, c.subject, c.category, c.status, c.priority, c.created_at, c.updated_at,
         h.first_name, h.middle_name, h.last_name, h.house_lot_number
  FROM complaints c
  LEFT JOIN homeowners h ON h.id = c.homeowner_id
  WHERE c.phase=?
  ORDER BY c.updated_at DESC, c.id DESC
  LIMIT 20
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$recentComplaints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ============================================================
   10) ANNOUNCEMENTS
   ============================================================ */

/* ACTIVE */
$annActive = [];
$stmt = $conn->prepare("
  SELECT a.id, a.phase, a.audience, a.title, a.category, a.message, a.start_date, a.end_date, a.priority, a.created_at,
         ad.full_name AS posted_by_name, ad.email AS posted_by_email, ad.role AS posted_by_role
  FROM announcements a
  LEFT JOIN admins ad ON ad.id = a.admin_id
  WHERE (
      (a.phase='Superadmin' AND (ad.role='superadmin' OR ad.role IS NULL))
      OR
      (a.phase=? AND a.audience='all_officers')
  )
    AND a.start_date <= CURDATE()
    AND (a.end_date IS NULL OR a.end_date >= CURDATE())
  ORDER BY FIELD(a.priority,'urgent','important','normal'), a.created_at DESC
  LIMIT 10
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $annActive[] = $r;
$stmt->close();

/* ENDED */
$annEnded = [];
$stmt = $conn->prepare("
  SELECT a.id, a.phase, a.audience, a.title, a.category, a.message, a.start_date, a.end_date, a.priority, a.created_at,
         ad.full_name AS posted_by_name, ad.email AS posted_by_email, ad.role AS posted_by_role
  FROM announcements a
  LEFT JOIN admins ad ON ad.id = a.admin_id
  WHERE (
      (a.phase='Superadmin' AND (ad.role='superadmin' OR ad.role IS NULL))
      OR
      (a.phase=? AND a.audience='all_officers')
  )
    AND a.end_date IS NOT NULL
    AND a.end_date < CURDATE()
  ORDER BY a.end_date DESC, a.created_at DESC
  LIMIT 10
");
$stmt->bind_param("s", $phase);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $annEnded[] = $r;
$stmt->close();

/* Calendar range */
$calFrom = date('Y-m-d', strtotime('-60 days'));
$calTo   = date('Y-m-d', strtotime('+30 days'));

$stmt = $conn->prepare("
  SELECT a.id, a.phase, a.audience, a.title, a.category, a.message, a.start_date, a.end_date, a.priority, a.created_at,
         ad.full_name AS posted_by_name, ad.email AS posted_by_email, ad.role AS posted_by_role
  FROM announcements a
  LEFT JOIN admins ad ON ad.id = a.admin_id
  WHERE (
      (a.phase='Superadmin' AND (ad.role='superadmin' OR ad.role IS NULL))
      OR
      (a.phase=? AND a.audience='all_officers')
  )
    AND a.start_date <= ?
    AND (a.end_date IS NULL OR a.end_date >= ?)
  ORDER BY a.start_date DESC
  LIMIT 200
");
$stmt->bind_param("sss", $phase, $calTo, $calFrom);
$stmt->execute();
$annForCalendar = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Build FullCalendar events */
$calEvents = [];

foreach ($annForCalendar as $a) {
  $start = (string)$a['start_date'];
  $endInclusive = $a['end_date'] ?? null;

  $endExclusive = !empty($endInclusive)
    ? date('Y-m-d', strtotime($endInclusive . ' +1 day'))
    : date('Y-m-d', strtotime($start . ' +1 day'));

  $by = trim((string)($a['posted_by_name'] ?? ''));
  if ($by === '') $by = (string)($a['posted_by_email'] ?? 'Admin');

  $prio = (string)($a['priority'] ?? 'normal');
  $cat  = (string)($a['category'] ?? 'general');

  $endedFlag = (!empty($endInclusive) && strtotime($endInclusive) < strtotime(date('Y-m-d'))) ? 1 : 0;

  $classNames = ['cal-ann', 'prio-' . $prio, 'cat-' . $cat];
  if ($endedFlag === 1) $classNames[] = 'cal-ended';

  $calEvents[] = [
    'id'     => (string)$a['id'],
    'title'  => (string)$a['title'],
    'start'  => $start,
    'end'    => $endExclusive,
    'allDay' => true,
    'classNames' => $classNames,
    'extendedProps' => [
      'message'  => (string)($a['message'] ?? ''),
      'priority' => $prio,
      'category' => $cat,
      'postedBy' => $by,
      'phase'    => (string)($a['phase'] ?? ''),
      'audience' => (string)($a['audience'] ?? ''),
      'range'    => $start . (!empty($endInclusive) ? ' to ' . (string)$endInclusive : ''),
      'ended'    => $endedFlag
    ]
  ];
}

/* complaint helpers */
function complaintStatusBadge($s){
  $s = (string)$s;
  if ($s === 'open') return 'badge-soft-warning';
  if ($s === 'in_progress') return 'badge-soft-info';
  if ($s === 'resolved') return 'badge-soft-success';
  if ($s === 'closed') return 'badge-soft-secondary';
  return 'badge-soft-warning';
}

function complaintPriorityBadge($p){
  $p = (string)$p;
  if ($p === 'urgent') return 'ann-badge urgent';
  if ($p === 'high') return 'ann-badge important';
  if ($p === 'normal') return 'ann-badge normal';
  return 'ann-badge';
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>HOA-ADMIN</title>

  <link rel="apple-touch-icon" sizes="180x180" href="vendors/images/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="vendors/images/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="vendors/images/favicon-16x16.png">

  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

  <link rel="stylesheet" type="text/css" href="vendors/styles/core.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/icon-font.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/dataTables.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="src/plugins/datatables/css/responsive.bootstrap4.min.css">
  <link rel="stylesheet" type="text/css" href="vendors/styles/style.css">
<script>
(function () {
    try {
        const savedTheme = localStorage.getItem('hoa-theme');

        const dark =
            savedTheme === 'dark' ||
            (
                !savedTheme &&
                window.matchMedia &&
                window.matchMedia('(prefers-color-scheme: dark)').matches
            );

        document.documentElement.classList.toggle('dark', dark);
    } catch (e) {}
})();
</script>

  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
  <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

  <style>
    /* =========================================================
       OFFICER DASHBOARD - UI
       ========================================================= */

    :root {
      --dash-green: #077f46;
      --dash-green-dark: #056338;
      --dash-blue: #2563eb;
      --dash-amber: #d97706;
      --dash-red: #dc2626;
      --dash-cyan: #0891b2;

      --dash-bg-soft: #f7f9fc;
      --dash-surface: #ffffff;
      --dash-surface-soft: #f8fafc;
      --dash-border: #e5e7eb;
      --dash-text: #0f172a;
      --dash-muted: #64748b;
      --dash-shadow: 0 8px 30px rgba(15, 23, 42, .06);
      --dash-shadow-hover: 0 14px 38px rgba(15, 23, 42, .10);
    }


    /* ---------------------------------------------------------
       Page heading
       --------------------------------------------------------- */

    .dashboard-page-header {
      position: relative;
      overflow: hidden;
      padding: 22px 24px;
      border: 1px solid var(--dash-border);
      border-radius: 18px;
      background:
        radial-gradient(circle at top right, rgba(7, 127, 70, .12), transparent 38%),
        linear-gradient(135deg, #ffffff 0%, #f8fbf9 100%);
      box-shadow: var(--dash-shadow);
    }

    .dashboard-page-header::after {
      content: "";
      position: absolute;
      width: 180px;
      height: 180px;
      right: -70px;
      bottom: -105px;
      border-radius: 50%;
      background: rgba(7, 127, 70, .07);
      pointer-events: none;
    }

    .dashboard-heading-row {
      position: relative;
      z-index: 1;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      flex-wrap: wrap;
    }

    .dashboard-eyebrow {
      margin-bottom: 5px;
      color: var(--dash-green);
      font-size: 11px;
      line-height: 1.2;
      font-weight: 800;
      letter-spacing: .12em;
      text-transform: uppercase;
    }

    .dashboard-page-title {
      margin: 0;
      color: var(--dash-text);
      font-size: 27px;
      line-height: 1.15;
      font-weight: 800;
      letter-spacing: -.02em;
    }

    .dashboard-page-subtitle {
      margin-top: 7px;
      color: var(--dash-muted);
      font-size: 13px;
      line-height: 1.6;
    }

    .dashboard-phase-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-height: 38px;
      padding: 8px 13px;
      border: 1px solid #bbf7d0;
      border-radius: 999px;
      background: #ecfdf5;
      color: #166534;
      font-size: 12px;
      font-weight: 800;
      white-space: nowrap;
    }


    /* ---------------------------------------------------------
       Cards
       --------------------------------------------------------- */

    .card-box {
      border: 1px solid var(--dash-border);
      box-shadow: var(--dash-shadow);
    }

    .welcome-card,
    .announcement-card,
    .dashboard-section-card,
    .kpi-card {
      border-radius: 18px !important;
    }

    .welcome-card {
      position: relative;
      overflow: hidden;
      min-height: 210px;
      background:
        radial-gradient(circle at 10% 15%, rgba(7, 127, 70, .10), transparent 30%),
        var(--dash-surface);
    }

    .welcome-card::after {
      content: "";
      position: absolute;
      right: -55px;
      top: -70px;
      width: 190px;
      height: 190px;
      border-radius: 50%;
      background: rgba(37, 99, 235, .055);
      pointer-events: none;
    }

    .welcome-card .row {
      position: relative;
      z-index: 1;
    }

    .welcome-card .welcome-image {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 165px;
    }

    .welcome-card .welcome-image img {
      width: min(100%, 225px);
      max-height: 175px;
      object-fit: contain;
      filter: drop-shadow(0 12px 20px rgba(15, 23, 42, .10));
    }

    .welcome-kicker {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 7px;
      color: var(--dash-green);
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .06em;
    }

    .welcome-title {
      margin: 0 0 9px;
      color: var(--dash-text);
      font-size: 27px;
      line-height: 1.22;
      font-weight: 800;
      letter-spacing: -.02em;
    }

    .welcome-copy {
      max-width: 680px;
      margin: 0;
      color: var(--dash-muted);
      font-size: 14px;
      line-height: 1.7;
    }

    .welcome-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-top: 14px;
    }

    .welcome-meta span {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 10px;
      border: 1px solid var(--dash-border);
      border-radius: 999px;
      background: var(--dash-surface-soft);
      color: #475569;
      font-size: 11px;
      font-weight: 700;
    }

    .top-row-no-stretch {
      align-items: flex-start !important;
    }

    .announcement-card {
      background: var(--dash-surface);
    }

    .panel-heading {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 14px;
    }

    .panel-title-wrap {
      min-width: 0;
    }

    .panel-title {
      margin: 0;
      color: var(--dash-text);
      font-size: 17px;
      line-height: 1.25;
      font-weight: 800;
    }

    .panel-subtitle {
      margin-top: 4px;
      color: var(--dash-muted);
      font-size: 12px;
      line-height: 1.5;
    }

    .panel-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      flex: 0 0 38px;
      border-radius: 11px;
      background: #ecfdf5;
      color: var(--dash-green);
      font-size: 19px;
    }


    /* ---------------------------------------------------------
       KPI cards
       --------------------------------------------------------- */

    .kpi-card {
      position: relative;
      min-height: 138px;
      overflow: hidden;
      background: var(--dash-surface);
      transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
    }

    .kpi-card:hover {
      transform: translateY(-2px);
      border-color: #d7dde6;
      box-shadow: var(--dash-shadow-hover);
    }

    .kpi-card::after {
      content: "";
      position: absolute;
      width: 82px;
      height: 82px;
      right: -28px;
      bottom: -32px;
      border-radius: 50%;
      background: rgba(148, 163, 184, .07);
      pointer-events: none;
    }

    .kpi-card .icon {
      position: relative;
      z-index: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 48px;
      height: 48px;
      flex: 0 0 48px;
      border-radius: 14px;
      font-size: 24px;
      opacity: 1;
      background: #f8fafc;
      border: 1px solid var(--dash-border);
    }

    .kpi-card .icon.text-success { background:#ecfdf5; border-color:#bbf7d0; }
    .kpi-card .icon.text-warning { background:#fff7ed; border-color:#fed7aa; }
    .kpi-card .icon.text-danger  { background:#fef2f2; border-color:#fecaca; }
    .kpi-card .icon.text-info    { background:#ecfeff; border-color:#a5f3fc; }
    .kpi-card .icon.text-primary { background:#eff6ff; border-color:#bfdbfe; }

    .kpi-value {
      margin-top: 2px;
      color: var(--dash-text);
      font-size: 29px;
      line-height: 1.1;
      font-weight: 800;
      letter-spacing: -.03em;
    }

    .kpi-label {
      color: var(--dash-muted);
      font-size: 12px;
      line-height: 1.35;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .kpi-card .mt-2 {
      position: relative;
      z-index: 1;
      margin-top: 12px !important;
      color: var(--dash-muted);
      font-size: 12px;
      line-height: 1.5;
    }


    /* ---------------------------------------------------------
       Soft badges
       --------------------------------------------------------- */

    .badge-soft {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 25px;
      padding: .3rem .58rem;
      border-radius: 999px;
      font-size: 11px;
      line-height: 1;
      font-weight: 800;
      letter-spacing: .02em;
      white-space: nowrap;
    }

    .badge-soft-warning { background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; }
    .badge-soft-success { background:#ecfdf5; border:1px solid #bbf7d0; color:#166534; }
    .badge-soft-info { background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; }
    .badge-soft-secondary { background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; }


    /* ---------------------------------------------------------
       Announcement widget
       --------------------------------------------------------- */

    .ann-wrap {
      display: flex;
      flex-direction: column;
      gap: 11px;
    }

    .ann-tabs {
      display: flex;
      gap: 7px;
      flex-wrap: wrap;
      padding: 4px;
      border: 1px solid var(--dash-border);
      border-radius: 13px;
      background: var(--dash-surface-soft);
    }

    .ann-tab {
      min-height: 34px;
      border: 0;
      background: transparent;
      padding: 7px 12px;
      border-radius: 10px;
      color: #475569;
      font-size: 11px;
      font-weight: 800;
      cursor: pointer;
      transition: background .18s ease, color .18s ease, box-shadow .18s ease;
    }

    .ann-tab:hover {
      background: #ffffff;
      color: var(--dash-green);
    }

    .ann-tab.active {
      background: var(--dash-green);
      color: #fff;
      box-shadow: 0 5px 14px rgba(7, 127, 70, .20);
    }

    .ann-list {
      display: none;
      max-height: 410px;
      overflow-y: auto;
      padding-right: 3px;
    }

    .ann-list.show {
      display: block;
    }

    .ann-list::-webkit-scrollbar {
      width: 6px;
    }

    .ann-list::-webkit-scrollbar-thumb {
      border-radius: 99px;
      background: #cbd5e1;
    }

    .ann-item {
      border: 1px solid var(--dash-border);
      border-radius: 14px;
      padding: 13px;
      background: var(--dash-surface);
      transition: border-color .18s ease, box-shadow .18s ease;
    }

    .ann-item + .ann-item {
      margin-top: 9px;
    }

    .ann-item:hover {
      border-color: #cbd5e1;
      box-shadow: 0 6px 18px rgba(15,23,42,.055);
    }

    .ann-item .top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
    }

    .ann-title {
      margin: 0;
      color: var(--dash-text);
      font-size: 14px;
      line-height: 1.35;
      font-weight: 800;
    }

    .ann-meta {
      margin-top: 3px;
      color: var(--dash-muted);
      font-size: 11px;
      line-height: 1.45;
    }

    .ann-msg {
      margin-top: 9px;
      color: #334155;
      font-size: 12px;
      line-height: 1.6;
      white-space: pre-wrap;
    }

    .ann-badges {
      display: flex;
      gap: 5px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .ann-badge {
      display: inline-flex;
      align-items: center;
      min-height: 23px;
      padding: 3px 7px;
      border: 1px solid #e5e7eb;
      border-radius: 999px;
      background: #f8fafc;
      color: #334155;
      font-size: 10px;
      line-height: 1;
      font-weight: 800;
    }

    .ann-badge.urgent { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
    .ann-badge.important { background:#fffbeb; border-color:#fed7aa; color:#9a3412; }
    .ann-badge.normal { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
    .ann-badge.ended { background:#f1f5f9; border-color:#e2e8f0; color:#475569; }
    .ann-badge.phase { background:#ecfeff; border-color:#a5f3fc; color:#155e75; }

    .ann-ended {
      opacity: .78;
    }

    .ann-ended .ann-title {
      text-decoration: line-through;
    }


    /* ---------------------------------------------------------
       FullCalendar
       --------------------------------------------------------- */

    #annCalendar {
      overflow: hidden;
      border: 1px solid var(--dash-border);
      border-radius: 14px;
      padding: 11px;
      background: var(--dash-surface);
    }

    .fc .fc-toolbar-title {
      color: var(--dash-text);
      font-size: 16px !important;
      font-weight: 800;
    }

    .fc .fc-button {
      border-radius: 8px !important;
      box-shadow: none !important;
      font-size: 11px !important;
      font-weight: 700 !important;
    }

    .fc .fc-daygrid-event {
      border-radius: 999px;
      padding: 2px 7px;
      font-weight: 800;
      border-width: 1px;
    }

    .fc .prio-urgent { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
    .fc .prio-important { background:#fffbeb; border-color:#fed7aa; color:#9a3412; }
    .fc .prio-normal { background:#eff6ff; border-color:#bfdbfe; color:#1d4ed8; }
    .fc .cal-ended { opacity: .72; }


    /* ---------------------------------------------------------
       Main section cards / chart / tables
       --------------------------------------------------------- */

    .dashboard-section-card {
      border-radius: 18px !important;
      background: var(--dash-surface);
    }

    .section-heading {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }

    .section-heading-main {
      min-width: 0;
    }

    .section-heading h2,
    .section-heading h5 {
      margin: 0;
      color: var(--dash-text);
      font-weight: 800;
      letter-spacing: -.01em;
    }

    .section-heading h2 {
      font-size: 18px;
    }

    .section-heading h5 {
      font-size: 16px;
    }

    .section-note {
      margin-top: 4px;
      color: var(--dash-muted);
      font-size: 11px;
      line-height: 1.5;
    }

    .chart-shell {
      position: relative;
      min-height: 320px;
    }

    #activityChart {
      max-height: 330px;
    }

    .table-responsive {
      border: 1px solid var(--dash-border);
      border-radius: 13px;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .table {
      margin-bottom: 0 !important;
    }

    .table thead th {
      border-top: 0 !important;
      border-bottom: 1px solid var(--dash-border) !important;
      background: var(--dash-surface-soft);
      color: #475569;
      font-size: 10px;
      line-height: 1.3;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
      white-space: nowrap;
      vertical-align: middle !important;
    }

    .table tbody td {
      color: #334155;
      font-size: 12px;
      line-height: 1.45;
      vertical-align: middle !important;
      border-color: #eef2f7 !important;
    }

    .table tbody tr:last-child td {
      border-bottom: 0;
    }

    .table-hover tbody tr:hover {
      background: #f8fafc;
    }

    .table .btn-sm {
      min-width: 34px;
      min-height: 32px;
      border-radius: 9px;
    }

    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
      color: var(--dash-muted) !important;
      font-size: 11px;
    }

    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
      min-height: 34px;
      border: 1px solid var(--dash-border);
      border-radius: 8px;
      background: #fff;
    }


    /* ---------------------------------------------------------
       Custom modals
       --------------------------------------------------------- */

    body.modalx-open {
      overflow: hidden;
    }

    .modalx {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 10050;
      align-items: center;
      justify-content: center;
      padding: 22px;
      background: rgba(15, 23, 42, .58);
      backdrop-filter: blur(5px);
      -webkit-backdrop-filter: blur(5px);
    }

    .modalx .box {
      width: min(1180px, 96vw);
      max-height: min(90vh, 860px);
      overflow: auto;
      border: 1px solid rgba(255,255,255,.25);
      border-radius: 20px;
      background: #fff;
      box-shadow: 0 24px 70px rgba(2, 6, 23, .28);
      animation: dashModalIn .18s ease-out;
    }

    .modalx .box.announcement-modal-box {
      width: min(760px, 96vw);
    }

    @keyframes dashModalIn {
      from {
        opacity: 0;
        transform: translateY(10px) scale(.985);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .modalx .boxhead {
      position: sticky;
      top: 0;
      z-index: 5;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      min-height: 62px;
      padding: 14px 18px;
      border-bottom: 1px solid var(--dash-border);
      background: rgba(255,255,255,.96);
      backdrop-filter: blur(8px);
    }

    .modal-title-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }

    .modal-title-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      flex: 0 0 36px;
      border-radius: 10px;
      background: #ecfdf5;
      color: var(--dash-green);
      font-size: 18px;
    }

    .modal-title-text {
      color: var(--dash-text);
      font-size: 15px;
      line-height: 1.25;
      font-weight: 800;
    }

    .modal-subtitle-text {
      margin-top: 2px;
      color: var(--dash-muted);
      font-size: 10px;
      line-height: 1.35;
    }

    .modalx .closebtn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 38px;
      height: 38px;
      flex: 0 0 38px;
      border: 1px solid var(--dash-border);
      border-radius: 10px;
      background: #fff;
      color: #64748b;
      font-size: 21px;
      line-height: 1;
      cursor: pointer;
      transition: background .18s ease, color .18s ease, border-color .18s ease;
    }

    .modalx .closebtn:hover {
      border-color: #fecaca;
      background: #fef2f2;
      color: #dc2626;
    }

    .modal-body-shell {
      min-height: 130px;
      padding: 18px;
      background: #f8fafc;
    }

    .modalx .kv {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .modalx .kv > div {
      min-width: 0;
      padding: 11px 12px;
      border: 1px solid var(--dash-border);
      border-radius: 11px;
      background: #fff;
    }

    .mini-muted {
      color: var(--dash-muted);
      font-size: 11px;
      font-weight: 600;
    }


    /* =========================================================
       HOMEOWNER PROFILE MODAL
       ========================================================= */

    #viewModal .modal-body-shell {
      padding: 18px;
    }

    .hp-profile {
      display: flex;
      flex-direction: column;
      gap: 16px;
      color: var(--dash-text);
    }

    .hp-hero {
      position: relative;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
      padding: 22px;
      border: 1px solid #dbe7df;
      border-radius: 18px;
      background:
        radial-gradient(circle at 90% 20%, rgba(16,185,129,.15), transparent 30%),
        linear-gradient(135deg, #ffffff 0%, #f4fbf7 100%);
    }

    .hp-hero::after {
      content: "";
      position: absolute;
      right: -70px;
      bottom: -95px;
      width: 220px;
      height: 220px;
      border-radius: 999px;
      background: rgba(7,127,70,.055);
      pointer-events: none;
    }

    .hp-identity,
    .hp-quick-grid {
      position: relative;
      z-index: 1;
    }

    .hp-identity {
      display: flex;
      align-items: center;
      gap: 15px;
      min-width: 0;
    }

    .hp-avatar {
      width: 76px;
      height: 76px;
      min-width: 76px;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 4px solid #ffffff;
      border-radius: 22px;
      background: linear-gradient(135deg, #047857, #0f9f63);
      color: #fff;
      box-shadow: 0 8px 22px rgba(7,127,70,.22);
      font-size: 23px;
      font-weight: 900;
      letter-spacing: .04em;
    }

    .hp-avatar img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .hp-identity-copy {
      min-width: 0;
    }

    .hp-kicker,
    .hp-section-kicker {
      display: block;
      margin-bottom: 4px;
      color: #078048;
      font-size: 10px;
      line-height: 1.2;
      font-weight: 900;
      letter-spacing: .11em;
      text-transform: uppercase;
    }

    .hp-identity-copy h2 {
      margin: 0;
      color: #0f172a;
      font-size: 24px;
      line-height: 1.2;
      font-weight: 850;
      letter-spacing: -.025em;
      word-break: break-word;
    }

    .hp-hero-badges {
      display: flex;
      align-items: center;
      gap: 7px;
      flex-wrap: wrap;
      margin-top: 9px;
    }

    .hp-status,
    .hp-soft-badge,
    .hp-map-chip,
    .hp-count-chip {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 26px;
      padding: 5px 9px;
      border-radius: 999px;
      font-size: 10px;
      line-height: 1;
      font-weight: 850;
      letter-spacing: .02em;
    }

    .hp-status.is-approved {
      border: 1px solid #bbf7d0;
      background: #ecfdf5;
      color: #166534;
    }

    .hp-status.is-pending {
      border: 1px solid #fed7aa;
      background: #fff7ed;
      color: #9a3412;
    }

    .hp-status.is-neutral {
      border: 1px solid #cbd5e1;
      background: #f1f5f9;
      color: #475569;
    }

    .hp-soft-badge,
    .hp-count-chip {
      border: 1px solid #dbe3ec;
      background: rgba(255,255,255,.78);
      color: #475569;
    }

    .hp-quick-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(105px, 1fr));
      gap: 8px;
      width: min(100%, 460px);
    }

    .hp-quick-grid > div {
      min-width: 0;
      padding: 10px 11px;
      border: 1px solid rgba(203,213,225,.85);
      border-radius: 12px;
      background: rgba(255,255,255,.78);
      backdrop-filter: blur(8px);
    }

    .hp-quick-grid span,
    .hp-stack-list span,
    .hp-detail-grid span,
    .hp-address-box span,
    .hp-document-card span {
      display: block;
      color: #64748b;
      font-size: 10px;
      line-height: 1.35;
      font-weight: 700;
    }

    .hp-quick-grid strong {
      display: block;
      margin-top: 3px;
      color: #0f172a;
      font-size: 11px;
      line-height: 1.4;
      font-weight: 850;
      word-break: break-word;
    }

    .hp-main-grid {
      display: grid;
      grid-template-columns: minmax(285px, .88fr) minmax(0, 1.62fr);
      gap: 16px;
      align-items: start;
    }

    .hp-side-column,
    .hp-content-column {
      display: flex;
      flex-direction: column;
      gap: 16px;
      min-width: 0;
    }

    .hp-card {
      overflow: hidden;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 5px 18px rgba(15,23,42,.045);
    }

    .hp-card-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
      padding: 14px 15px 12px;
      border-bottom: 1px solid #eef2f7;
      background: #fff;
    }

    .hp-card-head h3 {
      margin: 0;
      color: #0f172a;
      font-size: 14px;
      line-height: 1.3;
      font-weight: 850;
    }

    .hp-map-chip {
      flex: 0 0 auto;
      border: 1px solid #bbf7d0;
      background: #ecfdf5;
      color: #166534;
    }

    .hp-map-card {
      padding-bottom: 14px;
    }

    /*
     * Current official static image map.
     * NO Leaflet is used in this modal.
     *
     * Source image: 2550 x 3300 = 17:22.
     * Keeping the same aspect ratio preserves exact pin alignment.
     */
    .hp-property-map {
      position: relative;
      width: calc(100% - 28px);
      max-width: 370px;
      aspect-ratio: 17 / 22;
      margin: 14px auto 0;
      overflow: hidden;
      border: 1px solid #cbd5e1;
      border-radius: 14px;
      background: #e8edf4;
      box-shadow: 0 7px 18px rgba(15,23,42,.10);
    }

    .hp-property-map img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: fill;
      user-select: none;
      -webkit-user-drag: none;
    }

    .hp-property-pin {
      position: absolute;
      width: 22px;
      height: 22px;
      transform: translate(-50%, -50%);
      display: flex;
      align-items: center;
      justify-content: center;
      border: 3px solid #fff;
      border-radius: 999px;
      background: #dc2626;
      box-shadow:
        0 3px 8px rgba(0,0,0,.30),
        0 0 0 5px rgba(220,38,38,.18);
      z-index: 4;
      pointer-events: none;
    }

    .hp-pin-dot {
      width: 6px;
      height: 6px;
      border-radius: 999px;
      background: #fff;
    }

    .hp-map-caption {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      padding: 10px 14px 0;
      color: #475569;
      text-align: center;
    }

    .hp-map-caption strong {
      color: #0f172a;
      font-size: 12px;
    }

    .hp-map-caption span {
      font-size: 11px;
    }

    .hp-address-box {
      margin: 12px 14px 0;
      padding: 11px 12px;
      border: 1px solid #dbe3ec;
      border-radius: 12px;
      background: #f8fafc;
    }

    .hp-address-box strong {
      display: block;
      margin-top: 3px;
      color: #334155;
      font-size: 11px;
      line-height: 1.55;
      font-weight: 750;
      word-break: break-word;
    }

    .hp-stack-list {
      padding: 4px 15px 13px;
    }

    .hp-stack-list > div {
      padding: 10px 0;
      border-bottom: 1px solid #eef2f7;
    }

    .hp-stack-list > div:last-child {
      border-bottom: 0;
    }

    .hp-stack-list strong {
      display: block;
      margin-top: 3px;
      color: #334155;
      font-size: 11px;
      line-height: 1.5;
      font-weight: 800;
      word-break: break-word;
    }

    .hp-detail-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      padding: 14px 15px 15px;
    }

    .hp-detail-grid-two {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .hp-detail-grid > div {
      min-width: 0;
      padding: 11px 12px;
      border: 1px solid #e2e8f0;
      border-radius: 11px;
      background: #f8fafc;
    }

    .hp-detail-grid > div.hp-detail-wide {
      grid-column: 1 / -1;
    }

    .hp-detail-grid strong {
      display: block;
      margin-top: 4px;
      color: #1e293b;
      font-size: 11px;
      line-height: 1.45;
      font-weight: 800;
      word-break: break-word;
    }

    .hp-table-wrap {
      width: 100%;
      overflow-x: auto;
    }

    .hp-table {
      width: 100%;
      border-collapse: collapse;
      margin: 0;
    }

    .hp-table th,
    .hp-table td {
      padding: 11px 14px;
      border-bottom: 1px solid #eef2f7;
      color: #334155;
      font-size: 11px;
      line-height: 1.45;
      text-align: left;
      vertical-align: middle;
    }

    .hp-table th {
      background: #f8fafc;
      color: #64748b;
      font-size: 9px;
      font-weight: 850;
      letter-spacing: .07em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .hp-table tr:last-child td {
      border-bottom: 0;
    }

    .hp-table small {
      display: block;
      margin-top: 2px;
      color: #94a3b8;
      font-size: 9px;
    }

    .hp-document-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap: 10px;
      padding: 14px 15px 15px;
    }

    .hp-document-card {
      display: grid;
      grid-template-columns: 40px minmax(0,1fr) auto;
      gap: 10px;
      align-items: center;
      min-width: 0;
      padding: 11px;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      background: #f8fafc;
    }

    .hp-document-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      border-radius: 11px;
      background: #ecfdf5;
      color: #047857;
      font-size: 18px;
    }

    .hp-document-card strong {
      display: block;
      color: #1e293b;
      font-size: 11px;
      line-height: 1.35;
      font-weight: 850;
    }

    .hp-document-action {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 30px;
      padding: 6px 10px;
      border: 1px solid #a7f3d0;
      border-radius: 9px;
      background: #ecfdf5;
      color: #047857 !important;
      font-size: 10px;
      font-weight: 850;
      text-decoration: none !important;
    }

    .hp-document-action:hover {
      border-color: #6ee7b7;
      background: #d1fae5;
    }

    .hp-empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 5px;
      min-height: 160px;
      padding: 22px;
      color: #64748b;
      text-align: center;
    }

    .hp-empty-state.hp-empty-compact {
      min-height: auto;
      padding: 20px 15px;
    }

    .hp-empty-state.is-error {
      min-height: 220px;
      color: #991b1b;
    }

    .hp-empty-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 42px;
      height: 42px;
      margin-bottom: 3px;
      border-radius: 12px;
      background: #f1f5f9;
      color: #64748b;
      font-size: 18px;
      font-weight: 900;
    }


    /* -------------------------
       Homeowner profile dark
       ------------------------- */

    html.dark .hp-profile {
      color: var(--admin-text) !important;
    }

    html.dark .hp-hero {
      border-color: var(--admin-border) !important;
      background:
        radial-gradient(circle at 90% 20%, rgba(16,185,129,.12), transparent 30%),
        linear-gradient(135deg, var(--admin-surface) 0%, var(--admin-surface-2) 100%) !important;
    }

    html.dark .hp-avatar {
      border-color: #1e293b;
      box-shadow: 0 9px 24px rgba(0,0,0,.34);
    }

    html.dark .hp-identity-copy h2,
    html.dark .hp-card-head h3,
    html.dark .hp-quick-grid strong,
    html.dark .hp-map-caption strong,
    html.dark .hp-detail-grid strong,
    html.dark .hp-stack-list strong,
    html.dark .hp-document-card strong {
      color: var(--admin-text) !important;
    }

    html.dark .hp-kicker,
    html.dark .hp-section-kicker {
      color: #6ee7b7 !important;
    }

    html.dark .hp-soft-badge,
    html.dark .hp-count-chip,
    html.dark .hp-quick-grid > div {
      border-color: var(--admin-border) !important;
      background: rgba(30,41,59,.78) !important;
      color: #cbd5e1 !important;
    }

    html.dark .hp-card,
    html.dark .hp-card-head {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface) !important;
      box-shadow: none !important;
    }

    html.dark .hp-card-head {
      border-bottom-color: var(--admin-border) !important;
    }

    html.dark .hp-property-map {
      border-color: var(--admin-border) !important;
      background: #0f172a !important;
      box-shadow: 0 8px 22px rgba(0,0,0,.30);
    }

    html.dark .hp-address-box,
    html.dark .hp-detail-grid > div,
    html.dark .hp-document-card {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface-2) !important;
    }

    html.dark .hp-address-box strong,
    html.dark .hp-map-caption,
    html.dark .hp-table td {
      color: #cbd5e1 !important;
    }

    html.dark .hp-quick-grid span,
    html.dark .hp-stack-list span,
    html.dark .hp-detail-grid span,
    html.dark .hp-address-box span,
    html.dark .hp-document-card span,
    html.dark .hp-table small {
      color: var(--admin-muted) !important;
    }

    html.dark .hp-stack-list > div,
    html.dark .hp-table th,
    html.dark .hp-table td {
      border-color: var(--admin-border) !important;
    }

    html.dark .hp-table th {
      background: var(--admin-surface-2) !important;
      color: #cbd5e1 !important;
    }

    html.dark .hp-document-icon,
    html.dark .hp-document-action {
      border-color: rgba(52,211,153,.22) !important;
      background: rgba(16,185,129,.12) !important;
      color: #6ee7b7 !important;
    }

    html.dark .hp-empty-icon {
      background: var(--admin-surface-2) !important;
      color: var(--admin-muted) !important;
    }


    @media (max-width: 991.98px) {
      .hp-hero {
        align-items: flex-start;
        flex-direction: column;
      }

      .hp-quick-grid {
        width: 100%;
      }

      .hp-main-grid {
        grid-template-columns: 1fr;
      }

      .hp-property-map {
        max-width: 420px;
      }
    }


    @media (max-width: 767.98px) {
      #viewModal .modal-body-shell {
        padding: 11px;
      }

      .hp-profile {
        gap: 11px;
      }

      .hp-hero {
        padding: 16px;
        border-radius: 14px;
      }

      .hp-identity {
        align-items: flex-start;
      }

      .hp-avatar {
        width: 60px;
        height: 60px;
        min-width: 60px;
        border-radius: 17px;
        font-size: 18px;
      }

      .hp-identity-copy h2 {
        font-size: 19px;
      }

      .hp-quick-grid {
        grid-template-columns: 1fr;
      }

      .hp-detail-grid,
      .hp-detail-grid-two,
      .hp-document-grid {
        grid-template-columns: 1fr;
      }

      .hp-document-card {
        grid-template-columns: 40px minmax(0,1fr);
      }

      .hp-document-action {
        grid-column: 1 / -1;
      }

      .hp-card {
        border-radius: 14px;
      }

      .hp-property-map {
        width: calc(100% - 22px);
      }
    }


    /* ---------------------------------------------------------
       Toast
       --------------------------------------------------------- */

    .access-toast {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 99999;

      padding: 12px 18px;
      border-radius: 10px;
      font-weight: 700;

      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transform: translateY(-10px);

      transition:
        opacity .3s ease,
        transform .3s ease,
        visibility .3s ease;
    }

    .access-toast.show {
      opacity: 1;
      visibility: visible;
      transform: translateY(0);
    }


    /* =========================================================
       RESPONSIVE
       ========================================================= */

    @media (max-width: 991.98px) {
      .dashboard-page-header {
        padding: 18px;
      }

      .welcome-card {
        min-height: auto;
      }

      .welcome-card .welcome-image {
        min-height: 130px;
        margin-bottom: 8px;
      }

      .welcome-title {
        font-size: 23px;
      }

      .ann-list {
        max-height: 360px;
      }
    }

    @media (max-width: 767.98px) {
      .dashboard-page-title {
        font-size: 22px;
      }

      .dashboard-phase-chip {
        width: 100%;
        justify-content: center;
      }

      .welcome-card .welcome-image {
        display: none;
      }

      .kpi-card {
        min-height: 125px;
      }

      .section-heading {
        align-items: flex-start;
      }

      .section-heading .btn {
        width: 100%;
      }

      .ann-item .top {
        flex-direction: column;
      }

      .ann-badges {
        justify-content: flex-start;
      }

      .fc .fc-toolbar {
        gap: 8px;
        flex-direction: column;
      }

      .modalx {
        padding: 10px;
        align-items: flex-end;
      }

      .modalx .box,
      .modalx .box.announcement-modal-box {
        width: 100%;
        max-height: 92vh;
        border-radius: 18px 18px 0 0;
      }

      .modal-body-shell {
        padding: 13px;
      }

      .modalx .kv {
        grid-template-columns: 1fr;
      }

      .table tbody td,
      .table thead th {
        white-space: nowrap;
      }
    }
  </style>

  <!-- SHARED ADMIN LIGHT / DARK THEME -->
  <link rel="stylesheet" type="text/css" href="vendors/styles/admin_theme.css">

  <style>
    /* =========================================================
       OFFICER DASHBOARD - DARK MODE EXTENSIONS
       ========================================================= */

    html.dark .dashboard-page-header {
      border-color: var(--admin-border) !important;
      background:
        radial-gradient(circle at top right, rgba(16, 185, 129, .10), transparent 38%),
        linear-gradient(135deg, var(--admin-surface) 0%, var(--admin-surface-2) 100%) !important;
      box-shadow: none;
    }

    html.dark .dashboard-page-title,
    html.dark .welcome-title,
    html.dark .panel-title,
    html.dark .section-heading h2,
    html.dark .section-heading h5,
    html.dark .ann-title,
    html.dark .modal-title-text,
    html.dark .kpi-value {
      color: var(--admin-text) !important;
    }

    html.dark .dashboard-page-subtitle,
    html.dark .panel-subtitle,
    html.dark .welcome-copy,
    html.dark .welcome-meta span,
    html.dark .kpi-label,
    html.dark .ann-meta,
    html.dark .mini-muted,
    html.dark .section-note {
      color: var(--admin-muted) !important;
    }

    html.dark .dashboard-phase-chip {
      border-color: rgba(34, 197, 94, .30) !important;
      background: rgba(22, 163, 74, .14) !important;
      color: #86efac !important;
    }

    html.dark .welcome-card,
    html.dark .announcement-card,
    html.dark .dashboard-section-card,
    html.dark .kpi-card {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface) !important;
      box-shadow: none !important;
    }

    html.dark .welcome-meta span,
    html.dark .kpi-card .icon {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface-2) !important;
    }

    html.dark .ann-tabs {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface-2) !important;
    }

    html.dark .ann-tab {
      color: var(--admin-muted) !important;
    }

    html.dark .ann-tab:hover {
      background: var(--admin-hover) !important;
      color: #d1fae5 !important;
    }

    html.dark .ann-tab.active {
      background: #047857 !important;
      color: #fff !important;
    }

    html.dark .ann-item {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface-2) !important;
    }

    html.dark .ann-msg {
      color: #cbd5e1 !important;
    }

    html.dark .ann-badge {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface-3) !important;
      color: #cbd5e1 !important;
    }

    html.dark .ann-badge.urgent {
      background: rgba(220,38,38,.16) !important;
      border-color: rgba(239,68,68,.35) !important;
      color: #fca5a5 !important;
    }

    html.dark .ann-badge.important,
    html.dark .badge-soft-warning {
      background: rgba(217,119,6,.16) !important;
      border-color: rgba(245,158,11,.35) !important;
      color: #fcd34d !important;
    }

    html.dark .ann-badge.normal,
    html.dark .badge-soft-info {
      background: rgba(37,99,235,.16) !important;
      border-color: rgba(59,130,246,.35) !important;
      color: #93c5fd !important;
    }

    html.dark .ann-badge.phase {
      background: rgba(8,145,178,.16) !important;
      border-color: rgba(34,211,238,.30) !important;
      color: #67e8f9 !important;
    }

    html.dark .badge-soft-success {
      background: rgba(22,163,74,.16) !important;
      border-color: rgba(34,197,94,.35) !important;
      color: #86efac !important;
    }

    html.dark .badge-soft-secondary,
    html.dark .ann-badge.ended {
      background: var(--admin-surface-3) !important;
      border-color: var(--admin-border) !important;
      color: #cbd5e1 !important;
    }

    html.dark #annCalendar {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface-2) !important;
    }

    html.dark .fc,
    html.dark .fc .fc-toolbar-title,
    html.dark .fc .fc-col-header-cell-cushion,
    html.dark .fc .fc-daygrid-day-number,
    html.dark .fc .fc-list-day-text,
    html.dark .fc .fc-list-day-side-text {
      color: var(--admin-text) !important;
    }

    html.dark .fc-theme-standard td,
    html.dark .fc-theme-standard th,
    html.dark .fc-theme-standard .fc-scrollgrid {
      border-color: var(--admin-border) !important;
    }

    html.dark .fc .fc-day-today {
      background: rgba(59,130,246,.09) !important;
    }

    html.dark .table-responsive {
      border-color: var(--admin-border) !important;
    }

    html.dark .table,
    html.dark .table tbody,
    html.dark .table tbody tr,
    html.dark .table tbody td {
      color: var(--admin-text) !important;
      background: var(--admin-surface) !important;
      border-color: var(--admin-border) !important;
    }

    html.dark .table thead th,
    html.dark .table-light th {
      background: var(--admin-surface-2) !important;
      color: #f8fafc !important;
      border-color: var(--admin-border) !important;
    }

    html.dark .table-striped tbody tr:nth-of-type(odd),
    html.dark .table-striped tbody tr:nth-of-type(odd) > * {
      background: var(--admin-surface-2) !important;
      color: var(--admin-text) !important;
    }

    html.dark .table-hover tbody tr:hover,
    html.dark .table-hover tbody tr:hover > * {
      background: var(--admin-hover) !important;
      color: #fff !important;
    }

    html.dark .dataTables_wrapper .dataTables_filter input,
    html.dark .dataTables_wrapper .dataTables_length select {
      border-color: var(--admin-border) !important;
      background: var(--admin-input) !important;
      color: var(--admin-text) !important;
    }

    html.dark .modalx {
      background: rgba(2, 6, 23, .72) !important;
    }

    html.dark .modalx .box {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface) !important;
      box-shadow: 0 24px 70px rgba(0,0,0,.50) !important;
    }

    html.dark .modalx .boxhead {
      border-color: var(--admin-border) !important;
      background: rgba(17, 24, 39, .96) !important;
    }

    html.dark .modal-title-icon {
      background: rgba(22, 163, 74, .15) !important;
      color: #86efac !important;
    }

    html.dark .modalx .closebtn {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface-2) !important;
      color: #cbd5e1 !important;
    }

    html.dark .modalx .closebtn:hover {
      border-color: rgba(239,68,68,.35) !important;
      background: rgba(220,38,38,.15) !important;
      color: #fca5a5 !important;
    }

    html.dark .modal-body-shell {
      background: var(--admin-bg) !important;
      color: var(--admin-text) !important;
    }

    html.dark .modalx .kv > div {
      border-color: var(--admin-border) !important;
      background: var(--admin-surface-2) !important;
      color: var(--admin-text) !important;
    }
  </style>
</head>

<body>

 <div class="header">
    <div class="header-left">
        <div class="menu-icon dw dw-menu"></div>
    </div>

    <div class="header-right">

        <!-- DARK MODE TOGGLE -->
        <div class="admin-theme-switch">
            <button type="button"
                    id="themeToggle"
                    class="admin-theme-toggle"
                    aria-label="Switch theme"
                    title="Switch theme">
                <span id="themeIcon">☾</span>
            </button>
        </div>

        <div class="user-info-dropdown">
            <div class="dropdown">
                <a class="dropdown-toggle"
                   href="#"
                   role="button"
                   data-toggle="dropdown">

                    <span class="user-icon">
                        <img src="vendors/images/photo1.jpg" alt="">
                    </span>
                </a>

                <div class="dropdown-menu dropdown-menu-right dropdown-menu-icon-list">
                    <a class="dropdown-item" href="logout.php">
                        <i class="dw dw-logout"></i> Log Out
                    </a>
                </div>
            </div>
        </div>

    </div>
</div>

  <!-- SIDEBAR -->
<?php include 'sidebar.php'; ?>

  <div class="mobile-menu-overlay"></div>

  <div class="main-container">
    <div class="pd-ltr-20">

      <div class="dashboard-page-header mb-20">
        <div class="dashboard-heading-row">
          <div>
            <div class="dashboard-eyebrow">South Meridian Homes</div>
            <h1 class="dashboard-page-title">Officer Dashboard</h1>
            <div class="dashboard-page-subtitle">
              Welcome back,
              <strong><?= esc($adminName !== '' ? $adminName : $adminEmail) ?></strong>
              <?php if ($adminPosition !== ''): ?>
                · <?= esc($adminPosition) ?>
              <?php endif; ?>
              · Monitor your phase operations from one workspace.
            </div>
          </div>

          <div class="dashboard-phase-chip">
            <i class="dw dw-home"></i>
            <?= esc($phase) ?>
          </div>
        </div>
      </div>

      <!-- TOP ROW -->
      <div class="row top-row-no-stretch">
        <!-- LEFT -->
        <div class="col-lg-7 col-md-12 mb-30">
          <div class="card-box pd-20 welcome-card mb-20">
            <div class="row align-items-center">
              <div class="col-md-4 welcome-image">
                <img src="vendors/images/banner-img.png" alt="South Meridian dashboard">
              </div>

              <div class="col-md-8">
                <div class="welcome-kicker">
                  <i class="dw dw-dashboard"></i>
                  Officer workspace
                </div>

                <h2 class="welcome-title">
                  Welcome, <?= esc($adminName !== '' ? $adminName : 'Officer') ?>!
                </h2>

                <p class="welcome-copy">
                  Monitor registrations, finance activity, approval queues, announcements,
                  and homeowner concerns for <strong><?= esc($phase) ?></strong>.
                </p>

                <div class="welcome-meta">
                  <span><i class="dw dw-home"></i><?= esc($phase) ?></span>

                  <?php if ($adminPosition !== ''): ?>
                    <span><i class="dw dw-user-1"></i><?= esc($adminPosition) ?></span>
                  <?php endif; ?>

                  <span><i class="dw dw-calendar-1"></i><?= esc(date('M d, Y')) ?></span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- RIGHT (Announcements) -->
        <div class="col-lg-5 col-md-12 mb-30">
          <div class="card-box pd-20 announcement-card">
            <div class="panel-heading">
              <div class="panel-title-wrap">
                <h4 class="panel-title">Announcements</h4>
                <div class="panel-subtitle">
                  Updates for Superadmin and <?= esc($phase) ?> HOA officers.
                </div>
              </div>

              <div class="panel-icon">
                <i class="dw dw-notification"></i>
              </div>
            </div>

            <div class="ann-wrap">
              <div class="ann-tabs">
                <button type="button" class="ann-tab active" id="tabActive">
                  Active (<?= count($annActive) ?>)
                </button>
                <button type="button" class="ann-tab" id="tabEnded">
                  Ended (<?= count($annEnded) ?>)
                </button>
                <button type="button" class="ann-tab" id="tabCalendar">
                  Calendar
                </button>
              </div>

              <div class="mini-muted">
                Select a tab to switch between current, ended, and calendar views.
              </div>

              <!-- ACTIVE -->
              <div class="ann-list show" id="listActive">
                <?php if (empty($annActive)): ?>
                  <div class="text-secondary">No active announcements.</div>
                <?php else: ?>
                  <?php foreach ($annActive as $a): ?>
                    <?php
                      $prio = (string)($a['priority'] ?? 'normal');
                      $prioClass = ($prio === 'urgent') ? 'urgent' : (($prio === 'important') ? 'important' : 'normal');

                      $range = date("M d, Y", strtotime((string)$a['start_date']));
                      if (!empty($a['end_date'])) $range .= " - " . date("M d, Y", strtotime((string)$a['end_date']));

                      $by = trim((string)($a['posted_by_name'] ?? ''));
                      if ($by === '') $by = (string)($a['posted_by_email'] ?? 'Admin');

                      $srcPhase = (string)($a['phase'] ?? '');
                      $isOfficerPost = ($srcPhase === $phase && (string)($a['audience'] ?? '') === 'all_officers');
                      $sourceLabel = $isOfficerPost ? 'HOA OFFICERS' : 'SUPERADMIN';
                    ?>
                    <div class="ann-item">
                      <div class="top">
                        <div>
                          <p class="ann-title"><?= esc($a['title']) ?></p>
                          <div class="ann-meta">
                            <?= esc($range) ?> • Posted by <?= esc($by) ?>
                          </div>
                        </div>
                        <div class="ann-badges">
                          <span class="ann-badge phase"><?= esc($sourceLabel) ?></span>
                          <span class="ann-badge <?= esc($prioClass) ?>"><?= esc(strtoupper($prio)) ?></span>
                          <span class="ann-badge"><?= esc(strtoupper((string)($a['category'] ?? 'general'))) ?></span>
                        </div>
                      </div>
                      <div class="ann-msg"><?= esc((string)($a['message'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <!-- ENDED -->
              <div class="ann-list" id="listEnded">
                <?php if (empty($annEnded)): ?>
                  <div class="text-secondary">No ended announcements.</div>
                <?php else: ?>
                  <?php foreach ($annEnded as $a): ?>
                    <?php
                      $prio = (string)($a['priority'] ?? 'normal');
                      $prioClass = ($prio === 'urgent') ? 'urgent' : (($prio === 'important') ? 'important' : 'normal');

                      $range = date("M d, Y", strtotime((string)$a['start_date']));
                      if (!empty($a['end_date'])) $range .= " - " . date("M d, Y", strtotime((string)$a['end_date']));

                      $by = trim((string)($a['posted_by_name'] ?? ''));
                      if ($by === '') $by = (string)($a['posted_by_email'] ?? 'Admin');

                      $endedOn = !empty($a['end_date']) ? date("M d, Y", strtotime((string)$a['end_date'])) : '';

                      $srcPhase = (string)($a['phase'] ?? '');
                      $isOfficerPost = ($srcPhase === $phase && (string)($a['audience'] ?? '') === 'all_officers');
                      $sourceLabel = $isOfficerPost ? 'HOA OFFICERS' : 'SUPERADMIN';
                    ?>
                    <div class="ann-item ann-ended">
                      <div class="top">
                        <div>
                          <p class="ann-title"><?= esc($a['title']) ?></p>
                          <div class="ann-meta">
                            <?= esc($range) ?> • Ended: <b><?= esc($endedOn) ?></b> • Posted by <?= esc($by) ?>
                          </div>
                        </div>
                        <div class="ann-badges">
                          <span class="ann-badge ended">ENDED</span>
                          <span class="ann-badge phase"><?= esc($sourceLabel) ?></span>
                          <span class="ann-badge <?= esc($prioClass) ?>"><?= esc(strtoupper($prio)) ?></span>
                          <span class="ann-badge"><?= esc(strtoupper((string)($a['category'] ?? 'general'))) ?></span>
                        </div>
                      </div>
                      <div class="ann-msg"><?= esc((string)($a['message'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>

              <!-- CALENDAR -->
              <div class="ann-list" id="listCalendar">
                <div id="annCalendar"></div>
              </div>

            </div>
          </div>
        </div>
      </div>

      <!-- KPI CARDS -->
      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Approved Homeowners</div>
                <div class="kpi-value"><?= nfmt($approvedCount) ?></div>
              </div>
              <div class="icon text-success"><i class="dw dw-user"></i></div>
            </div>
            <div class="mt-2 text-secondary">Active members this phase</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Pending Homeowners</div>
                <div class="kpi-value"><?= nfmt($pendingCount) ?></div>
              </div>
              <div class="icon text-warning"><i class="dw dw-clock"></i></div>
            </div>
            <div class="mt-2 text-secondary">Waiting for approval</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Report Approvals</div>
                <div class="kpi-value"><?= nfmt($pendingReportCount) ?></div>
              </div>
              <div class="icon text-danger"><i class="dw dw-file-3"></i></div>
            </div>
            <div class="mt-2">
              <a href="finance_reports.php" class="text-primary font-weight-bold">Review pending reports →</a>
            </div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <?php $net = $thisMonthCollections - $thisMonthExpenses; ?>
                <div class="kpi-label">This Month Net</div>
                <div class="kpi-value"><?= money($net) ?></div>
              </div>
              <div class="icon text-info"><i class="dw dw-money"></i></div>
            </div>
            <div class="mt-2 text-secondary">
              Collections: <?= money($thisMonthCollections) ?> • Expenses: <?= money($thisMonthExpenses) ?>
            </div>
          </div>
        </div>
      </div>

      <!-- COMPLAINT KPI CARDS -->
      <div class="row">
        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Total Complaints</div>
                <div class="kpi-value"><?= nfmt($complaintTotal) ?></div>
              </div>
              <div class="icon text-primary"><i class="dw dw-chat3"></i></div>
            </div>
            <div class="mt-2 text-secondary">All complaint records</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Open Complaints</div>
                <div class="kpi-value"><?= nfmt($complaintOpen) ?></div>
              </div>
              <div class="icon text-warning"><i class="dw dw-warning"></i></div>
            </div>
            <div class="mt-2 text-secondary">New concerns to review</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">In Progress</div>
                <div class="kpi-value"><?= nfmt($complaintInProgress) ?></div>
              </div>
              <div class="icon text-info"><i class="dw dw-checked"></i></div>
            </div>
            <div class="mt-2 text-secondary">Being handled by admin</div>
          </div>
        </div>

        <div class="col-xl-3 col-lg-6 col-md-6 mb-30">
          <div class="card-box pd-20 kpi-card">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="kpi-label">Resolved / Closed</div>
                <div class="kpi-value"><?= nfmt($complaintResolved + $complaintClosed) ?></div>
              </div>
              <div class="icon text-success"><i class="dw dw-check"></i></div>
            </div>
            <div class="mt-2 text-secondary">Finished complaint cases</div>
          </div>
        </div>
      </div>

      <!-- CHART -->
      <div class="row">
        <div class="col-xl-12 mb-30">
          <div class="card-box pd-20 dashboard-section-card">
            <div class="section-heading">
              <div class="section-heading-main">
                <h2>Operations Overview</h2>
                <div class="section-note">Collections, expenses, and new homeowner registrations for the last 6 months.</div>
              </div>

              <span class="badge-soft badge-soft-info">Last 6 months</span>
            </div>

            <div class="chart-shell">
              <canvas id="activityChart" height="95"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- PENDING REPORTS TABLE -->
      <div class="card-box mb-30 p-3 dashboard-section-card">
        <div class="section-heading">
          <div class="section-heading-main">
            <h5>Pending Finance Reports</h5>
            <div class="section-note">Reports waiting for President review and approval.</div>
          </div>

          <a class="btn btn-sm btn-outline-primary" href="finance_reports.php">
            Open Finance Reports
          </a>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover mb-0">
            <thead>
              <tr>
                <th>Requested At</th>
                <th>Period</th>
                <th>Requested By</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($pendingReports)): ?>
                <?php foreach ($pendingReports as $r): ?>
                  <?php
                    $who = trim((string)($r['requested_by_name'] ?? ''));
                    if ($who === '') $who = (string)($r['requested_by_email'] ?? '');
                    $period = (string)($r['report_year'] ?? '') . '-' . str_pad((string)($r['report_month'] ?? ''), 2, '0', STR_PAD_LEFT);
                  ?>
                  <tr>
                    <td><?= esc((string)($r['requested_at'] ?? '')) ?></td>
                    <td><?= esc($period) ?></td>
                    <td><?= esc($who) ?></td>
                    <td><span class="badge-soft badge-soft-warning">pending</span></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="4" class="text-center text-secondary">No pending report approvals.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- RECENT COMPLAINTS TABLE -->
      <div class="card-box mb-30 p-3 dashboard-section-card">
        <div class="section-heading">
          <div class="section-heading-main">
            <h5>Recent Complaints</h5>
            <div class="section-note">Latest homeowner concerns and their current handling status.</div>
          </div>

          <a class="btn btn-sm btn-outline-success" href="admin_complaints.php">
            Open Complaints Module
          </a>
        </div>

        <div class="table-responsive">
          <table class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Homeowner</th>
                <th>Blk/Lot</th>
                <th>Subject</th>
                <th>Category</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Updated</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($recentComplaints)): ?>
                <?php foreach ($recentComplaints as $c): ?>
                  <?php
                    $cName = trim(
                      (string)($c['first_name'] ?? '') . ' ' .
                      (string)($c['middle_name'] ?? '') . ' ' .
                      (string)($c['last_name'] ?? '')
                    );
                  ?>
                  <tr>
                    <td><?= (int)$c['id'] ?></td>
                    <td><?= esc($cName !== '' ? $cName : 'Unknown') ?></td>
                    <td><?= esc((string)($c['house_lot_number'] ?? '')) ?></td>
                    <td><?= esc((string)($c['subject'] ?? '')) ?></td>
                    <td><?= esc(ucwords(str_replace('_', ' ', (string)($c['category'] ?? 'general')))) ?></td>
                    <td>
                      <span class="badge-soft <?= esc(complaintStatusBadge((string)$c['status'])) ?>">
                        <?= esc(strtoupper(str_replace('_', ' ', (string)$c['status']))) ?>
                      </span>
                    </td>
                    <td>
                      <span class="<?= esc(complaintPriorityBadge((string)$c['priority'])) ?>">
                        <?= esc(strtoupper((string)$c['priority'])) ?>
                      </span>
                    </td>
                    <td><?= esc((string)($c['updated_at'] ?? '')) ?></td>
                    <td class="text-center">
                      <a href="admin_complaints.php?complaint_id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary" title="View Complaint">
                        <i class="dw dw-eye"></i>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center text-secondary">No complaints found for this phase.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- HOMEOWNERS TABLE -->
      <div class="card-box mb-30 p-3 dashboard-section-card">
        <div class="section-heading">
          <div class="section-heading-main">
            <h5>Homeowners</h5>
            <div class="section-note">Approved and pending homeowner accounts for <?= esc($phase) ?>.</div>
          </div>

          <span class="badge-soft badge-soft-secondary">
            <?= esc($phase) ?>
          </span>
        </div>

        <div class="table-responsive">
          <table id="homeownersTable" class="table table-striped table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Blk/Lot</th>
                <th>Status</th>
                <th>Registered</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($homeownersRows)): ?>
                <?php foreach ($homeownersRows as $h): ?>
                  <?php
                    $full = trim((string)($h['first_name'] ?? '') . ' ' . (string)($h['middle_name'] ?? '') . ' ' . (string)($h['last_name'] ?? ''));
                    $st = (string)($h['status'] ?? 'pending');
                    $badge = $st === 'approved' ? 'badge-soft-success' : 'badge-soft-warning';
                  ?>
                  <tr>
                    <td><?= (int)$h['id'] ?></td>
                    <td><?= esc($full) ?></td>
                    <td><?= esc((string)($h['house_lot_number'] ?? '')) ?></td>
                    <td><span class="badge-soft <?= esc($badge) ?>"><?= esc($st) ?></span></td>
                    <td><?= esc((string)($h['created_at'] ?? '')) ?></td>
                    <td class="text-center">
                      <button type="button" class="btn btn-sm btn-outline-primary viewHomeowner" data-id="<?= (int)$h['id'] ?>" title="View">
                        <i class="dw dw-eye"></i>
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="6" class="text-center text-secondary">No homeowners found for this phase.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="footer-wrap pd-20 mb-20 card-box">
        © Copyright South Meridian Homes All Rights Reserved
      </div>
    </div>
  </div>

  <!-- VIEW HOMEOWNER MODAL -->
  <div
    class="modalx"
    id="viewModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="viewModalTitle"
  >
    <div class="box homeowner-profile-modal-box">
      <div class="boxhead">
        <div class="modal-title-wrap">
          <div class="modal-title-icon">
            <i class="dw dw-user"></i>
          </div>

          <div>
            <div class="modal-title-text" id="viewModalTitle">Homeowner Profile</div>
            <div class="modal-subtitle-text">Profile, property map, household, tenants and documents</div>
          </div>
        </div>

        <button
          class="closebtn"
          type="button"
          id="closeViewModal"
          aria-label="Close homeowner profile"
        >
          &times;
        </button>
      </div>

      <div id="viewModalBody" class="modal-body-shell">
        <div class="text-secondary">Loading...</div>
      </div>
    </div>
  </div>

  <!-- ANNOUNCEMENT MODAL -->
  <div
    class="modalx"
    id="annModal"
    role="dialog"
    aria-modal="true"
    aria-labelledby="annModalTitle"
  >
    <div class="box announcement-modal-box">
      <div class="boxhead">
        <div class="modal-title-wrap">
          <div class="modal-title-icon">
            <i class="dw dw-notification"></i>
          </div>

          <div>
            <div class="modal-title-text" id="annModalTitle">Announcement</div>
            <div class="modal-subtitle-text">Announcement details and message</div>
          </div>
        </div>

        <button
          class="closebtn"
          type="button"
          id="closeAnnModal"
          aria-label="Close announcement"
        >
          &times;
        </button>
      </div>

      <div id="annModalBody" class="modal-body-shell">
        <div class="text-secondary">Loading...</div>
      </div>
    </div>
  </div>
 
<script src="vendors/scripts/core.js"></script>
<script src="vendors/scripts/script.min.js"></script>
<script src="vendors/scripts/process.js"></script>
<script src="vendors/scripts/layout-settings.js"></script>

<script src="src/plugins/datatables/js/jquery.dataTables.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.bootstrap4.min.js"></script>
<script src="src/plugins/datatables/js/dataTables.responsive.min.js"></script>
<script src="src/plugins/datatables/js/responsive.bootstrap4.min.js"></script>

<!-- ADMIN DARK MODE -->
<script src="vendors/scripts/admin_theme.js"></script>

  <script>
    // DataTables init
    $(document).ready(function () {
      $('#homeownersTable').DataTable({
        responsive: true,
        pageLength: 10,
        order: [],
        columnDefs: [{ orderable: false, targets: 5 }]
      });
    });

    // View modal
    const viewModal = document.getElementById('viewModal');
    const viewBody  = document.getElementById('viewModalBody');

    function openViewModal() {
      viewModal.style.display = 'flex';
      document.body.classList.add('modalx-open');
    }

    function closeViewModal() {
      viewModal.style.display = 'none';
      document.body.classList.remove('modalx-open');
      viewBody.innerHTML = '<div class="text-secondary">Loading...</div>';
    }

    document.getElementById('closeViewModal').addEventListener('click', closeViewModal);
    viewModal.addEventListener('click', (e) => { if (e.target === viewModal) closeViewModal(); });

    $(document).on('click', '.viewHomeowner', function () {
      const id = Number(
        $(this).data('id')
        || 0
      );

      if (!id) {
        return;
      }

      openViewModal();

      viewBody.innerHTML = `
        <div class="hp-empty-state">
          <div class="hp-empty-icon">
            <i class="dw dw-hourglass"></i>
          </div>
          <strong>Loading homeowner profile...</strong>
        </div>
      `;

      $.ajax({
        url: 'dashboard.php',
        method: 'GET',
        data: {
          ajax: 'homeowner_profile',
          id: id,
          _: Date.now()
        },
        cache: false
      })
      .done(function (html) {
        viewBody.innerHTML = html;
      })
      .fail(function (xhr) {
        let message =
          'Failed to load homeowner profile.';

        if (xhr.status === 404) {
          message =
            'Homeowner not found or outside your assigned phase.';
        }

        viewBody.innerHTML = `
          <div class="hp-empty-state is-error">
            <div class="hp-empty-icon">!</div>
            <strong>${escapeHtml(message)}</strong>
          </div>
        `;
      });
    });

    // Announcements tabs + calendar init
    (function () {
      const tabA = document.getElementById('tabActive');
      const tabE = document.getElementById('tabEnded');
      const tabC = document.getElementById('tabCalendar');

      const listA = document.getElementById('listActive');
      const listE = document.getElementById('listEnded');
      const listC = document.getElementById('listCalendar');

      let calInited = false;
      let calendar = null;

      function setActive(btn) {
        [tabA, tabE, tabC].forEach(t => t && t.classList.remove('active'));
        if (btn) btn.classList.add('active');
      }

      function showOnly(which) {
        [listA, listE, listC].forEach(l => l && l.classList.remove('show'));
        if (which) which.classList.add('show');
      }

      function showActive() {
        setActive(tabA);
        showOnly(listA);
      }

      function showEnded() {
        setActive(tabE);
        showOnly(listE);
      }

      function showCalendar() {
        setActive(tabC);
        showOnly(listC);

        if (!calInited) {
          const el = document.getElementById('annCalendar');
          if (!el) return;

          const events = <?= json_encode($calEvents) ?>;

          calendar = new FullCalendar.Calendar(el, {
            initialView: 'dayGridMonth',
            height: 'auto',
            headerToolbar: {
              left: 'prev,next today',
              center: 'title',
              right: 'dayGridMonth,listWeek'
            },
            events: events,
            eventDidMount: function (info) {
              const ep = info.event.extendedProps || {};

              const source = (ep.audience === 'all_officers' && ep.phase && ep.phase !== 'Superadmin')
                ? ('HOA OFFICERS • ' + ep.phase)
                : 'SUPERADMIN';

              const tip = [
                info.event.title,
                (ep.range ? ('Dates: ' + ep.range) : ''),
                ('Source: ' + source),
                ('Priority: ' + (ep.priority || 'normal')),
                ('Category: ' + (ep.category || 'general'))
              ].filter(Boolean).join('\n');

              info.el.setAttribute('title', tip);
            },
            eventClick: function (info) {
              info.jsEvent.preventDefault();
              openAnnModal(info.event);
            }
          });

          calendar.render();
          calInited = true;
          setTimeout(() => calendar.updateSize(), 80);
        } else {
          setTimeout(() => calendar && calendar.updateSize(), 50);
        }
      }

      if (tabA) tabA.addEventListener('click', showActive);
      if (tabE) tabE.addEventListener('click', showEnded);
      if (tabC) tabC.addEventListener('click', showCalendar);
    })();

    // Announcement modal
    const annModal = document.getElementById('annModal');
    const annBody  = document.getElementById('annModalBody');

    function openAnnModal(event) {
      const ep = event.extendedProps || {};

      const title = escapeHtml(event.title || '');
      const msg   = escapeHtml(ep.message || '');
      const pri   = escapeHtml(ep.priority || 'normal');
      const cat   = escapeHtml(ep.category || 'general');
      const by    = escapeHtml(ep.postedBy || 'Admin');
      const range = escapeHtml(ep.range || '');

      const source = (ep.audience === 'all_officers' && ep.phase && ep.phase !== 'Superadmin')
        ? ('HOA OFFICERS • ' + ep.phase)
        : 'SUPERADMIN';

      annBody.innerHTML = `
        <div style="font-weight:800;font-size:18px;line-height:1.35;margin-bottom:12px;">${title}</div>

        <div class="kv mb-3">
          <div><span class="mini-muted">Dates</span><br><b>${range || '—'}</b></div>
          <div><span class="mini-muted">Source</span><br><b>${escapeHtml(source)}</b></div>
          <div><span class="mini-muted">Priority</span><br><b>${pri.toUpperCase()}</b></div>
          <div><span class="mini-muted">Category</span><br><b>${cat.toUpperCase()}</b></div>
          <div><span class="mini-muted">Posted By</span><br><b>${by}</b></div>
        </div>

        <div class="mini-muted mb-1">Message</div>
        <div style="
          white-space:pre-wrap;
          line-height:1.65;
          padding:12px 13px;
          border:1px solid var(--dash-border);
          border-radius:11px;
          background:var(--dash-surface);
        ">${msg || '—'}</div>
      `;

      annModal.style.display = 'flex';
      document.body.classList.add('modalx-open');
    }

    function closeAnnModal() {
      annModal.style.display = 'none';
      document.body.classList.remove('modalx-open');
      annBody.innerHTML = '<div class="text-secondary">Loading...</div>';
    }

    document.getElementById('closeAnnModal').addEventListener('click', closeAnnModal);
    annModal.addEventListener('click', (e) => { if (e.target === annModal) closeAnnModal(); });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;

      if (viewModal && viewModal.style.display === 'flex') {
        closeViewModal();
      }

      if (annModal && annModal.style.display === 'flex') {
        closeAnnModal();
      }
    });

    function escapeHtml(str) {
      return String(str ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    // Chart.js
    const labels = <?= json_encode($labels) ?>;
    const collections = <?= json_encode($chartCollections) ?>;
    const expenses = <?= json_encode($chartExpenses) ?>;
    const newHO = <?= json_encode($chartNewHO) ?>;

    const ctx = document.getElementById('activityChart').getContext('2d');

    const activityChart = new Chart(ctx, {
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: 'Collections (Paid)',
            data: collections,
            borderWidth: 1,
            borderRadius: 6,
            maxBarThickness: 34,
            backgroundColor: 'rgba(37, 99, 235, .70)',
            borderColor: '#2563eb'
          },
          {
            type: 'bar',
            label: 'Expenses',
            data: expenses,
            borderWidth: 1,
            borderRadius: 6,
            maxBarThickness: 34,
            backgroundColor: 'rgba(239, 68, 68, .62)',
            borderColor: '#ef4444'
          },
          {
            type: 'line',
            label: 'New Homeowners',
            data: newHO,
            borderWidth: 2,
            tension: 0.28,
            yAxisID: 'y2',
            pointRadius: 3,
            pointHoverRadius: 5,
            borderColor: '#16a34a',
            backgroundColor: '#16a34a'
          }
        ]
      },

      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },

        plugins: {
          legend: {
            display: true,
            position: 'bottom',
            labels: {
              usePointStyle: true,
              boxWidth: 8,
              boxHeight: 8,
              padding: 18,
              font: {
                family: 'Inter',
                size: 11,
                weight: '600'
              }
            }
          },

          tooltip: {
            padding: 11,
            cornerRadius: 8
          }
        },

        scales: {
          x: {
            grid: {
              display: false
            },
            ticks: {
              font: {
                family: 'Inter',
                size: 10
              }
            }
          },

          y: {
            beginAtZero: true,
            title: {
              display: true,
              text: 'Amount (₱)',
              font: {
                family: 'Inter',
                size: 10,
                weight: '600'
              }
            },
            ticks: {
              font: {
                family: 'Inter',
                size: 10
              }
            }
          },

          y2: {
            beginAtZero: true,
            position: 'right',
            grid: {
              drawOnChartArea: false
            },
            title: {
              display: true,
              text: 'Count',
              font: {
                family: 'Inter',
                size: 10,
                weight: '600'
              }
            },
            ticks: {
              precision: 0,
              font: {
                family: 'Inter',
                size: 10
              }
            }
          }
        }
      }
    });


    function applyDashboardChartTheme() {
      const isDark =
        document.documentElement.classList.contains('dark');

      const textColor =
        isDark ? '#cbd5e1' : '#64748b';

      const gridColor =
        isDark
          ? 'rgba(148, 163, 184, .14)'
          : 'rgba(148, 163, 184, .16)';

      activityChart.options.plugins.legend.labels.color =
        textColor;

      activityChart.options.scales.x.ticks.color =
        textColor;

      activityChart.options.scales.y.ticks.color =
        textColor;

      activityChart.options.scales.y.title.color =
        textColor;

      activityChart.options.scales.y.grid.color =
        gridColor;

      activityChart.options.scales.y2.ticks.color =
        textColor;

      activityChart.options.scales.y2.title.color =
        textColor;

      activityChart.update('none');
    }


    applyDashboardChartTheme();


    const dashboardThemeObserver =
      new MutationObserver(function () {
        applyDashboardChartTheme();
      });


    dashboardThemeObserver.observe(
      document.documentElement,
      {
        attributes: true,
        attributeFilter: ['class']
      }
    );
  </script>
   <div id="accessToast" class="access-toast">
  🚫 You do not have access to that part.
</div>

<script>
window.userPermissions = <?= json_encode($permissions) ?>;

document.addEventListener('DOMContentLoaded', function () {

  const toast = document.getElementById('accessToast');

  function showAccessToast() {
    toast.classList.add('show');

    setTimeout(() => {
      toast.classList.remove('show');
    }, 2500);
  }

  document.querySelectorAll('.menu-access-link').forEach(function(link){

    link.addEventListener('click', function(e){

      const moduleKey = this.dataset.module || '';
      const allowed = !!window.userPermissions[moduleKey];

      if(!allowed){
        e.preventDefault();
        showAccessToast();
      }

    });

  });

});
</script>
</body>
</html>