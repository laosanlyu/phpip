<?php
/**
 * CSV Import Script for phpIP
 *
 * Reads a single flat CSV file (semicolon-delimited) and separates the data
 * into the 5 entity arrays: actors, matters, matter_actor_lnk, events, classifiers.
 *
 * CSV format: Row 1 = technical column keys, Row 2+ = data rows.
 *
 * Modes:
 *   preview  - Show what would be inserted (default, safe to run)
 *   seed     - Write PHP array files to database/seeders/
 *   direct   - Insert directly into database (bootstraps Laravel)
 *             Generates a JSON manifest file for rollback
 *   rollback - Delete all data from a previous 'direct' import using its manifest file
 *
 * Usage (from project root, inside Docker container):
 *   php database/seeders/import-csv.php database/seeders/import-template.csv [preview|seed|direct]
 *   php database/seeders/import-csv.php <manifest-file.json> rollback
 */

// ============================================================
// Configuration
// ============================================================
$MODE = $argv[2] ?? 'preview'; // 'preview' | 'seed' | 'direct' | 'rollback'

if (!in_array($MODE, ['preview', 'seed', 'direct', 'rollback'])) {
    echo "Error: Invalid mode '$MODE'. Use: preview, seed, direct, or rollback\n";
    exit(1);
}

// Bootstrap Laravel for direct and rollback modes
if (in_array($MODE, ['direct', 'rollback'])) {
    require __DIR__ . '/../../vendor/autoload.php';
    $app = require_once __DIR__ . '/../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();
    echo "Laravel bootstrapped.\n\n";
}

// ============================================================
// Rollback mode — process manifest and exit early
// ============================================================
if ($MODE === 'rollback') {
    $manifestFile = $argv[1] ?? '';
    if (!$manifestFile || !file_exists($manifestFile)) {
        echo "Error: Manifest file not found: $manifestFile\n";
        echo "Usage: php database/seeders/import-csv.php <manifest-file.json> rollback\n";
        exit(1);
    }

    $manifest = json_decode(file_get_contents($manifestFile), true);
    if (!$manifest || !isset($manifest['matter_ids'])) {
        echo "Error: Invalid manifest file format.\n";
        exit(1);
    }

    echo "=== ROLLBACK MODE ===\n";
    echo "Manifest: $manifestFile\n";
    echo "Source CSV: {$manifest['source_csv']}\n";
    echo "Imported at: {$manifest['inserted_at']}\n\n";

    echo "Will delete:\n";
    echo "  " . count($manifest['task_ids']) . " tasks (auto-generated)\n";
    echo "  " . count($manifest['classifier_ids']) . " classifiers\n";
    echo "  " . count($manifest['event_ids']) . " events\n";
    echo "  " . count($manifest['link_ids']) . " matter-actor links\n";
    echo "  " . count($manifest['matter_ids']) . " matters\n";
    echo "  " . count($manifest['actor_ids']) . " actors\n\n";

    Illuminate\Support\Facades\DB::beginTransaction();

    try {
        // Disable FK checks — we're explicitly deleting all related rows by ID
        // (needed because matter has self-referencing FK on container_id/parent_id)
        Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();

        // Delete in reverse dependency order
        $taskCount = Illuminate\Support\Facades\DB::table('task')
            ->whereIn('id', $manifest['task_ids'])->delete();
        echo "Deleted $taskCount tasks.\n";

        $classifierCount = Illuminate\Support\Facades\DB::table('classifier')
            ->whereIn('id', $manifest['classifier_ids'])->delete();
        echo "Deleted $classifierCount classifiers.\n";

        $eventCount = Illuminate\Support\Facades\DB::table('event')
            ->whereIn('id', $manifest['event_ids'])->delete();
        echo "Deleted $eventCount events.\n";

        $linkCount = Illuminate\Support\Facades\DB::table('matter_actor_lnk')
            ->whereIn('id', $manifest['link_ids'])->delete();
        echo "Deleted $linkCount matter-actor links.\n";

        // Deleting matters cascades remaining events/links/classifiers via FK ON DELETE CASCADE
        $matterCount = Illuminate\Support\Facades\DB::table('matter')
            ->whereIn('id', $manifest['matter_ids'])->delete();
        echo "Deleted $matterCount matters (+ any remaining cascaded data).\n";

        $actorCount = Illuminate\Support\Facades\DB::table('actor')
            ->whereIn('id', $manifest['actor_ids'])->delete();
        echo "Deleted $actorCount actors.\n";

        Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        Illuminate\Support\Facades\DB::commit();

        echo "\nRollback complete. All imported data has been removed.\n";

    } catch (\Exception $e) {
        Illuminate\Support\Facades\DB::rollBack();
        echo "\n*** ROLLBACK FAILED — NO CHANGES MADE ***\n";
        echo "  " . $e->getMessage() . "\n";
        exit(1);
    }

    exit(0);
}

// ============================================================
// Parse CSV
// ============================================================
$csvFile = $argv[1] ?? __DIR__ . '/import-template.csv';

if (!file_exists($csvFile)) {
    echo "Error: File not found: $csvFile\n";
    exit(1);
}

$handle = fopen($csvFile, 'r');
// Row 1: Technical column keys (used by the script)
$headers = fgetcsv($handle, 0, ';');
$headers = array_map('trim', $headers);

$rows = [];
while (($data = fgetcsv($handle, 0, ';')) !== false) {
    if (count($data) !== count($headers)) {
        echo "Warning: Skipping row with " . count($data) . " columns (expected " . count($headers) . ")\n";
        continue;
    }
    $row = [];
    foreach ($headers as $i => $header) {
        $row[$header] = trim($data[$i]) === '' ? null : trim($data[$i]);
    }
    $rows[] = $row;
}
fclose($handle);

echo "Parsed " . count($rows) . " rows from CSV.\n\n";

// ============================================================
// Pass 1: Extract unique actors
// ============================================================
$actors = [];
$actorId = 1000; // Starting ID, adjust based on your existing data

function addActor(string $name, string $defaultRole, bool $phyPerson, ?string $company = null, array &$actors, int &$actorId): void
{
    // Normalize name for deduplication
    $key = strtolower(trim($name));
    if ($key === '' || isset($actors[$key])) {
        return;
    }
    $actors[$key] = [
        'id' => $actorId++,
        'name' => $name,
        'first_name' => null,
        'display_name' => null,
        'login' => null,
        'password' => null,
        'default_role' => $defaultRole,
        'phy_person' => $phyPerson ? 1 : 0,
        'ren_discount' => 0,
        'company_name' => $company, // Resolved later
        'country' => null,
        'notes' => 'Imported from CSV',
    ];
}

foreach ($rows as $row) {
    if ($row['client_name']) {
        addActor($row['client_name'], 'CLI', false, null, $actors, $actorId);
    }
    if ($row['applicant1_name']) {
        addActor($row['applicant1_name'], 'APP', false, null, $actors, $actorId);
    }
    if ($row['applicant2_name']) {
        addActor($row['applicant2_name'], 'APP', false, null, $actors, $actorId);
    }
    // Inventors are physical persons
    foreach (['inventor1', 'inventor2', 'inventor3'] as $inv) {
        if ($row["{$inv}_name"]) {
            addActor($row["{$inv}_name"], 'INV', true, $row["{$inv}_company"] ?? null, $actors, $actorId);
        }
    }
    if ($row['agent_name']) {
        addActor($row['agent_name'], 'AGT', false, null, $actors, $actorId);
    }
}

// Resolve company_id for inventors
foreach ($actors as &$actor) {
    if ($actor['company_name']) {
        $companyKey = strtolower($actor['company_name']);
        if (isset($actors[$companyKey])) {
            $actor['company_id'] = $actors[$companyKey]['id'];
        }
    }
    unset($actor['company_name']);
}
unset($actor);

echo "=== ACTORS (" . count($actors) . " unique) ===\n";
foreach ($actors as $a) {
    $type = $a['phy_person'] ? 'Person' : 'Company';
    echo "  [{$a['id']}] {$a['name']} ({$a['default_role']}, $type)\n";
}
echo "\n";

// ============================================================
// Pass 2: Extract matters (two passes to resolve parent/container IDs)
// ============================================================
$matters = [];
$matterId = 100; // Starting ID, adjust based on your existing data
$matterLookup = []; // "caseref|country" => first matter id (for parent/container/priority resolution)
$matterUid = []; // "caseref|country|origin|type" => true (for deduplication)
$rowMatterIds = []; // row index => matter id (for per-row event/link association)

// First pass: create all matters without parent/container
foreach ($rows as $ri => $row) {
    // Use compound key for deduplication (same caseref+country can have different origin/type)
    $uid = $row['caseref'] . '|' . ($row['country'] ?? '') . '|' . ($row['origin'] ?? '') . '|' . ($row['type'] ?? '');
    if (isset($matterUid[$uid])) {
        echo "Warning: Duplicate matter $uid, skipping.\n";
        continue;
    }
    $matterUid[$uid] = true;

    // Simple key for reference resolution (parent/container/priority lookups)
    // Only stores the FIRST matter with this caseref+country (typically the earliest filing)
    $refKey = $row['caseref'] . '|' . ($row['country'] ?? '');
    if (!isset($matterLookup[$refKey])) {
        $matterLookup[$refKey] = $matterId;
    }

    $rowMatterIds[$ri] = $matterId;
    $matters[] = [
        'id' => $matterId,
        'category_code' => $row['category'] ?? 'PAT',
        'type_code' => $row['type'],
        'caseref' => $row['caseref'],
        'country' => $row['country'],
        'origin' => $row['origin'],
        'parent_id' => null, // Resolved in pass 2
        'container_id' => null, // Resolved in pass 2
        'responsible' => $row['responsible'] ?? 'phpipuser',
        'dead' => (int)($row['dead'] ?? 0),
        'expire_date' => $row['expire_date'],
        'alt_ref' => $row['alt_ref'],
        'notes' => $row['notes'],
        '_parent_key' => $row['parent_caseref'] && $row['parent_country']
            ? $row['parent_caseref'] . '|' . $row['parent_country']
            : null,
        '_container_key' => $row['container_caseref'] && $row['container_country']
            ? $row['container_caseref'] . '|' . $row['container_country']
            : null,
    ];
    $matterId++;
}

// Second pass: resolve parent/container references
foreach ($matters as &$matter) {
    if ($matter['_parent_key'] && isset($matterLookup[$matter['_parent_key']])) {
        $matter['parent_id'] = $matterLookup[$matter['_parent_key']];
    } elseif ($matter['_parent_key']) {
        echo "Warning: Parent not found for {$matter['caseref']}|{$matter['country']}: {$matter['_parent_key']}\n";
    }
    if ($matter['_container_key'] && isset($matterLookup[$matter['_container_key']])) {
        $matter['container_id'] = $matterLookup[$matter['_container_key']];
    } elseif ($matter['_container_key']) {
        echo "Warning: Container not found for {$matter['caseref']}|{$matter['country']}: {$matter['_container_key']}\n";
    }
    unset($matter['_parent_key'], $matter['_container_key']);
}
unset($matter);

echo "=== MATTERS (" . count($matters) . ") ===\n";
foreach ($matters as $m) {
    $suffix = implode('/', array_filter([$m['country'], $m['origin']]));
    if ($m['type_code']) $suffix .= "-{$m['type_code']}";
    echo "  [{$m['id']}] {$m['caseref']} $suffix (parent={$m['parent_id']}, container={$m['container_id']})\n";
}
echo "\n";

// ============================================================
// Pass 3: Extract matter-actor links
// ============================================================
$links = [];
$linkId = 100;

function addLink(int $matterId, ?string $actorName, string $role, bool $shared, ?string $actorRef, array $actors, array &$links, int &$linkId): void
{
    if (!$actorName) return;
    $actorKey = strtolower(trim($actorName));
    if (!isset($actors[$actorKey])) {
        echo "Warning: Actor '$actorName' not found for link.\n";
        return;
    }
    $links[] = [
        'id' => $linkId++,
        'matter_id' => $matterId,
        'actor_id' => $actors[$actorKey]['id'],
        'display_order' => 1,
        'role' => $role,
        'shared' => $shared ? 1 : 0,
        'actor_ref' => $actorRef,
        'company_id' => $actors[$actorKey]['company_id'] ?? null,
        'rate' => '100.00',
        'date' => date('Y-m-d'),
    ];
}

foreach ($rows as $ri => $row) {
    if (!isset($rowMatterIds[$ri])) continue;
    $mid = $rowMatterIds[$ri];

    addLink($mid, $row['client_name'], 'CLI', true, $row['client_ref'] ?? null, $actors, $links, $linkId);

    $appOrder = 1;
    foreach (['applicant1_name', 'applicant2_name'] as $appCol) {
        if ($row[$appCol]) {
            $actorKey = strtolower(trim($row[$appCol]));
            if (isset($actors[$actorKey])) {
                $links[] = [
                    'id' => $linkId++,
                    'matter_id' => $mid,
                    'actor_id' => $actors[$actorKey]['id'],
                    'display_order' => $appOrder++,
                    'role' => 'APP',
                    'shared' => 1,
                    'actor_ref' => null,
                    'company_id' => null,
                    'rate' => '100.00',
                    'date' => date('Y-m-d'),
                ];
            }
        }
    }

    $invOrder = 1;
    foreach (['inventor1', 'inventor2', 'inventor3'] as $inv) {
        if ($row["{$inv}_name"]) {
            $actorKey = strtolower(trim($row["{$inv}_name"]));
            if (isset($actors[$actorKey])) {
                $links[] = [
                    'id' => $linkId++,
                    'matter_id' => $mid,
                    'actor_id' => $actors[$actorKey]['id'],
                    'display_order' => $invOrder++,
                    'role' => 'INV',
                    'shared' => 1,
                    'actor_ref' => null,
                    'company_id' => $actors[$actorKey]['company_id'] ?? null,
                    'rate' => '100.00',
                    'date' => date('Y-m-d'),
                ];
            }
        }
    }

    addLink($mid, $row['agent_name'], 'AGT', false, $row['agent_ref'] ?? null, $actors, $links, $linkId);
}

echo "=== MATTER-ACTOR LINKS (" . count($links) . ") ===\n";
foreach ($links as $l) {
    echo "  [{$l['id']}] matter={$l['matter_id']} actor={$l['actor_id']} role={$l['role']} shared={$l['shared']}\n";
}
echo "\n";

// ============================================================
// Pass 4: Extract events
// ============================================================
$events = [];
$eventId = 1000;

// Map of CSV column prefixes to event codes
$eventMap = [
    'filing' => ['code' => 'FIL', 'date_col' => 'filing_date', 'detail_col' => 'filing_number'],
    'priority' => ['code' => 'PRI', 'date_col' => 'priority_date', 'detail_col' => 'priority_number'],
    'publication' => ['code' => 'PUB', 'date_col' => 'publication_date', 'detail_col' => 'publication_number'],
    'grant' => ['code' => 'GRT', 'date_col' => 'grant_date', 'detail_col' => 'grant_number'],
    'entry' => ['code' => 'ENT', 'date_col' => 'entry_date', 'detail_col' => null],
    'examination' => ['code' => 'EXA', 'date_col' => 'examination_date', 'detail_col' => null],
];

foreach ($rows as $ri => $row) {
    if (!isset($rowMatterIds[$ri])) continue;
    $mid = $rowMatterIds[$ri];

    foreach ($eventMap as $prefix => $config) {
        $dateCol = $config['date_col'];
        $detailCol = $config['detail_col'];

        if (!$row[$dateCol]) continue;

        $event = [
            'id' => $eventId++,
            'code' => $config['code'],
            'matter_id' => $mid,
            'event_date' => $row[$dateCol],
            'alt_matter_id' => null,
            'detail' => $detailCol ? $row[$detailCol] : null,
        ];

        // For priority events, resolve the alt_matter_id
        if ($config['code'] === 'PRI' && $row['priority_country']) {
            // Try to find the priority matter in our dataset
            // Priority often points to a matter we're importing (e.g., the US provisional)
            // Search by filing number if available, or by country
            $priDetail = $row['priority_country'] . ($row['priority_number'] ?? '');
            $event['detail'] = $priDetail;

            // Try to find alt_matter by caseref + priority_country
            $priKey = $row['caseref'] . '|' . $row['priority_country'];
            if (isset($matterLookup[$priKey])) {
                $event['alt_matter_id'] = $matterLookup[$priKey];
                $event['detail'] = null; // When alt_matter_id is set, detail is not needed
            }
        }

        $events[] = $event;
    }
}

echo "=== EVENTS (" . count($events) . ") ===\n";
foreach ($events as $e) {
    $alt = $e['alt_matter_id'] ? " -> matter {$e['alt_matter_id']}" : '';
    $det = $e['detail'] ? " [{$e['detail']}]" : '';
    echo "  [{$e['id']}] {$e['code']} matter={$e['matter_id']} {$e['event_date']}{$det}{$alt}\n";
}
echo "\n";

// ============================================================
// Pass 5: Extract classifiers (titles)
// ============================================================
$classifiers = [];
$classifierId = 100;

foreach ($rows as $ri => $row) {
    if (!isset($rowMatterIds[$ri])) continue;
    $mid = $rowMatterIds[$ri];

    if ($row['title']) {
        $classifiers[] = [
            'id' => $classifierId++,
            'matter_id' => $mid,
            'type_code' => 'TIT',
            'value' => $row['title'],
        ];
    }
    if ($row['title_official']) {
        $classifiers[] = [
            'id' => $classifierId++,
            'matter_id' => $mid,
            'type_code' => 'TITOF',
            'value' => $row['title_official'],
        ];
    }
}

echo "=== CLASSIFIERS (" . count($classifiers) . ") ===\n";
foreach ($classifiers as $c) {
    echo "  [{$c['id']}] {$c['type_code']} matter={$c['matter_id']}: " . substr($c['value'], 0, 50) . "\n";
}
echo "\n";

// ============================================================
// Output
// ============================================================
if ($MODE === 'preview') {
    echo "=== PREVIEW MODE ===\n";
    echo "To generate PHP seed files, run with 'seed' mode:\n";
    echo "  php database/seeders/import-csv.php <csv-file> seed\n\n";
    echo "To insert directly into database, run with 'direct' mode:\n";
    echo "  php database/seeders/import-csv.php <csv-file> direct\n";

} elseif ($MODE === 'seed') {
    // Write PHP array files named after the source CSV
    $outputDir = __DIR__;
    $csvBaseName = pathinfo(basename($csvFile), PATHINFO_FILENAME); // e.g. "import-test-seeder"

    // Actors
    $actorArray = array_values(array_map(function($a) {
        unset($a['company_id']); // Not a direct column in actor table for seeder
        return $a;
    }, $actors));
    file_put_contents("$outputDir/actor-{$csvBaseName}.php", "<?php\n\n\$actor = " . var_export(array_values($actors), true) . ";\n");

    // Matters
    file_put_contents("$outputDir/matter-{$csvBaseName}.php", "<?php\n\n\$matter = " . var_export($matters, true) . ";\n");

    // Matter-actor links
    file_put_contents("$outputDir/matter_actor_lnk-{$csvBaseName}.php", "<?php\n\n\$matter_actor_lnk = " . var_export($links, true) . ";\n");

    // Events
    file_put_contents("$outputDir/event-{$csvBaseName}.php", "<?php\n\n\$event = " . var_export($events, true) . ";\n");

    // Classifiers
    file_put_contents("$outputDir/classifier-{$csvBaseName}.php", "<?php\n\n\$classifier = " . var_export($classifiers, true) . ";\n");

    echo "=== SEED FILES WRITTEN ===\n";
    echo "  $outputDir/actor-{$csvBaseName}.php\n";
    echo "  $outputDir/matter-{$csvBaseName}.php\n";
    echo "  $outputDir/matter_actor_lnk-{$csvBaseName}.php\n";
    echo "  $outputDir/event-{$csvBaseName}.php\n";
    echo "  $outputDir/classifier-{$csvBaseName}.php\n";
    echo "\nIMPORTANT: When loading events, use Event::create() instead of insertOrIgnore()\n";
    echo "to ensure DB triggers fire and automatically create tasks/renewals.\n";
    echo "\nCreate a seeder class to load these files, similar to the existing sample seeders.\n";

} elseif ($MODE === 'direct') {
    echo "=== DIRECT INSERT MODE ===\n\n";

    // Check for existing data conflicts before inserting
    $matterIds = array_column($matters, 'id');
    $existingMatters = Illuminate\Support\Facades\DB::table('matter')
        ->whereIn('id', $matterIds)->count();
    if ($existingMatters > 0) {
        echo "*** ERROR: $existingMatters matter(s) with IDs " . implode(',', $matterIds) . " already exist in the database.\n";
        echo "Run rollback first to remove previous import data, or use different starting IDs.\n";
        exit(1);
    }

    // Use DB::transaction() closure — automatically commits on success, rolls back on exception
    try {
        Illuminate\Support\Facades\DB::transaction(function () use ($actors, $matters, $links, $events, $classifiers) {
            // 1. Insert actors
            echo "Inserting " . count($actors) . " actors...\n";
            foreach (array_values($actors) as $actorData) {
                App\Models\Actor::insertOrIgnore([$actorData]);
            }

            // 2. Insert matters
            echo "Inserting " . count($matters) . " matters...\n";
            foreach ($matters as $matterData) {
                App\Models\Matter::insertOrIgnore([$matterData]);
            }

            // 3. Insert matter-actor links
            echo "Inserting " . count($links) . " matter-actor links...\n";
            foreach ($links as $linkData) {
                App\Models\ActorPivot::insertOrIgnore([$linkData]);
            }

            // 4. Insert events ONE BY ONE via Eloquent create()
            //    This fires MySQL triggers that auto-generate tasks and renewals
            echo "Inserting " . count($events) . " events (with trigger support)...\n";
            $createdEventIds = [];
            foreach ($events as $eventData) {
                $evt = App\Models\Event::create($eventData);
                $createdEventIds[] = $evt->id;
            }

            // 5. Insert classifiers
            echo "Inserting " . count($classifiers) . " classifiers...\n";
            foreach ($classifiers as $classData) {
                App\Models\Classifier::insertOrIgnore([$classData]);
            }
        });

        echo "\nDone! Inserted " . count($actors) . " actors, " . count($matters) . " matters, " . count($links) . " links, " . count($events) . " events, " . count($classifiers) . " classifiers.\n";
        echo "Tasks and renewals were auto-generated by database triggers from event insertions.\n";

        // ---- Generate rollback manifest ----
        // Query actual DB-assigned IDs (events get auto-increment IDs, triggers create extra events/links)
        $matterIds = array_column($matters, 'id');

        // All events for imported matters (includes our events + trigger-created CRE events)
        $allEventIds = Illuminate\Support\Facades\DB::table('event')
            ->whereIn('matter_id', $matterIds)
            ->pluck('id')->toArray();

        // All tasks linked to events in imported matters
        $taskIds = Illuminate\Support\Facades\DB::table('task')
            ->join('event', 'task.trigger_id', '=', 'event.id')
            ->whereIn('event.matter_id', $matterIds)
            ->pluck('task.id')->toArray();

        // All matter-actor links for imported matters (includes trigger-created ones)
        $allLinkIds = Illuminate\Support\Facades\DB::table('matter_actor_lnk')
            ->whereIn('matter_id', $matterIds)
            ->pluck('id')->toArray();

        // All classifiers for imported matters
        $allClassifierIds = Illuminate\Support\Facades\DB::table('classifier')
            ->whereIn('matter_id', $matterIds)
            ->pluck('id')->toArray();

        $manifest = [
            'source_csv'     => basename($csvFile),
            'inserted_at'    => date('Y-m-d H:i:s'),
            'actor_ids'      => array_column(array_values($actors), 'id'),
            'matter_ids'     => $matterIds,
            'link_ids'       => $allLinkIds,
            'event_ids'      => $allEventIds,
            'classifier_ids' => $allClassifierIds,
            'task_ids'       => $taskIds,
        ];

        $timestamp = date('Ymd_His');
        $manifestPath = __DIR__ . "/import-manifest-{$timestamp}.json";
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT) . "\n");

        echo "\nManifest saved: $manifestPath\n";
        echo "To undo this import, run:\n";
        echo "  php database/seeders/import-csv.php database/seeders/import-manifest-{$timestamp}.json rollback\n";

    } catch (\Exception $e) {
        echo "\n*** ERROR — ALL CHANGES ROLLED BACK ***\n";
        echo "  " . $e->getMessage() . "\n";
        echo "  in " . $e->getFile() . " line " . $e->getLine() . "\n";
        exit(1);
    }
}
