<?php

namespace App\Commands;

use Exception;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use TeamNifty\Plesk\Enums\DnsRecordType;
use TeamNiftyGmbH\ResellerInterface\Enums\ResellerID;
use function Laravel\Prompts\info;
use function Laravel\Prompts\warning;
use function Laravel\Prompts\error;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\search;
use function Laravel\Prompts\clear;

class SyncResellerInterfaceWithPlesk extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:reseller-to-plesk
                            {--domain= : Specific domain to sync (optional)}
                            {--dry-run : Show what would be changed without making changes}
                            {--stop-on-failure : Stop processing domains when a sync fails}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync DNS records from ResellerInterface to Plesk';

    private bool $dryRun = false;
    private bool $stopOnFailure = false;
    private array $lastSyncDetails = [];
    private array $lastSyncRecords = []; // Store actual records for detailed view

    public function handle(): int
    {
        $this->dryRun = $this->option('dry-run');
        $this->stopOnFailure = $this->option('stop-on-failure');

        if (!$this->testConnections()) {
            return self::FAILURE;
        }

        $specificDomain = $this->option('domain');

        if ($specificDomain) {
            info("Syncing specific domain: {$specificDomain}");
            return $this->syncDomain($specificDomain);
        }

        return $this->syncAllDomains();
    }

    private function testConnections(): bool
    {
        info('Testing connections...');

        try {
            spin(
                fn() => plesk()->server()->serverMetaInformation(),
                'Connecting to Plesk server...'
            );
            info('✓ Connected to Plesk server');
        } catch (Exception $e) {
            error('Failed to connect to Plesk: ' . $e->getMessage());
            error('Please check your PLESK_URL, PLESK_USERNAME/PASSWORD or PLESK_API_KEY in .env');
            return false;
        }

        try {
            spin(
                fn() => resellerInterface()->resellers()->resellerDetails(),
                'Connecting to ResellerInterface API...'
            );
            info('✓ Connected to ResellerInterface API');
        } catch (Exception $e) {
            error('Failed to connect to ResellerInterface: ' . $e->getMessage());
            error('Please check your RESELLERINTERFACE_USERNAME, RESELLERINTERFACE_PASSWORD and RESELLERINTERFACE_RESELLER_ID in .env');
            return false;
        }

        return true;
    }

    private function syncAllDomains(): int
    {
        try {
            $response = spin(
                fn() => resellerInterface()->domains()->domainList(limit: 1000),
                'Fetching domains from ResellerInterface...'
            );

            if ($response->failed()) {
                error("Failed to fetch domains from ResellerInterface: " . $response->body());
                return self::FAILURE;
            }

            $domains = $response->json()['list'] ?? [];

            if (empty($domains)) {
                warning('No domains found in ResellerInterface.');
                return self::SUCCESS;
            }

            info(sprintf('Found %d domain(s) in ResellerInterface', count($domains)));

            $pleskResponse = spin(
                fn() => plesk()->domains()->listAllDomains(),
                'Fetching domains from Plesk...'
            );

            if ($pleskResponse->failed()) {
                error("Failed to fetch domains from Plesk: " . $pleskResponse->body());
                return self::FAILURE;
            }

            $pleskDomains = $pleskResponse->json() ?? [];
            $pleskDomainNames = [];

            foreach ($pleskDomains as $domain) {
                $pleskDomainNames[] = strtolower($domain['name'] ?? '');
            }

            info(sprintf('Found %d domain(s) in Plesk', count($pleskDomains)));

            // Disable ResellerInterface DNS extension once for all domains
            if (!$this->dryRun) {
                info("Disabling ResellerInterface DNS extension to prevent sync loop...");
                try {
                    $disableResponse = plesk()->extensions()->disableExtension('resellerinterface-dns');
                    if ($disableResponse->failed()) {
                        error("Failed to disable ResellerInterface DNS extension: " . $disableResponse->body());
                        error("Cannot continue without disabling the extension - would cause sync loop!");
                        return self::FAILURE;
                    }
                } catch (Exception $e) {
                    error("Could not disable ResellerInterface DNS extension: " . $e->getMessage());
                    error("Cannot continue without disabling the extension - would cause sync loop!");
                    return self::FAILURE;
                }
            }

            $successCount = 0;
            $failedCount = 0;
            $skippedCount = 0;
            $results = [];
            $domainDetails = []; // Store detailed info for each domain

            foreach ($domains as $domain) {
                $domainName = $domain['domain'] ?? '';
                if (!$domainName) {
                    continue;
                }

                clear(); // Clear screen before processing each domain
                info("Processing domain: {$domainName}");

                if (!in_array(strtolower($domainName), $pleskDomainNames)) {
                    warning("  → Domain {$domainName} not found in Plesk, skipping...");
                    $skippedCount++;
                    $results[] = [$domainName, '⚠️ Skipped', 'Not found in Plesk'];
                    $domainDetails[$domainName] = [
                        'status' => 'skipped',
                        'reason' => 'Domain not found in Plesk',
                        'records_added' => 0,
                        'records_deleted' => 0
                    ];
                    continue;
                }

                // Store domain processing result with details
                $syncResult = $this->syncDomainRecords($domain);
                if ($syncResult === self::SUCCESS) {
                    $successCount++;
                    $results[] = [$domainName, '✅ Success', 'Synced'];
                    // Get the details from last sync
                    $domainDetails[$domainName] = array_merge(
                        ['status' => 'success', 'message' => 'Successfully synced DNS records'],
                        $this->lastSyncDetails,
                        ['records' => $this->lastSyncRecords]
                    );
                } else {
                    $failedCount++;
                    $results[] = [$domainName, '❌ Failed', 'Error during sync'];
                    $domainDetails[$domainName] = array_merge(
                        ['status' => 'failed', 'message' => 'Failed to sync DNS records'],
                        $this->lastSyncDetails,
                        ['records' => $this->lastSyncRecords]
                    );

                    // Stop processing if --stop-on-failure is set
                    if ($this->stopOnFailure) {
                        error("Stopping domain processing due to failure (--stop-on-failure is set)");
                        break;
                    }
                }
            }

            // Clear and show summary
            clear();
            info("🎯 Sync Summary");
            info("═══════════════");

            if (!empty($results)) {
                table(
                    ['Domain', 'Status', 'Details'],
                    $results
                );
            }

            info("Total: {$successCount} successful, {$failedCount} failed, {$skippedCount} skipped");

            // Interactive menu for details
            if (!empty($results)) {
                $domainList = [];
                foreach ($results as $result) {
                    $domainList[] = $result[0] . ' ' . $result[1];
                }

                while (true) {
                    $selection = search(
                        label: 'Search for a domain to view details (or type "exit" to quit):',
                        options: fn(string $value) => strlen($value) > 0
                            ? (strtolower($value) === 'exit'
                                ? ['exit' => '🚪 Exit']
                                : array_filter($domainList, fn($item) => str_contains(strtolower($item), strtolower($value))))
                            : $domainList,
                        placeholder: 'Type to search domains...'
                    );

                    if ($selection === 'exit') {
                        break;
                    }

                    // Extract domain name from selection (remove status emoji)
                    $domainName = explode(' ', $selection)[0];

                    // Show details for selected domain
                    clear();
                    info("📊 Details for: {$domainName}");
                    info("═══════════════════════════════════════");

                    if (isset($domainDetails[$domainName])) {
                        $details = $domainDetails[$domainName];
                        $records = $details['records'] ?? [];

                        // Show ResellerInterface records (source)
                        if (!empty($records['resellerinterface'])) {
                            info("");
                            info("📥 DNS Records from ResellerInterface:");
                            info(sprintf("Found %d DNS record(s)", count($records['resellerinterface'])));

                            $riTable = [];
                            foreach ($records['resellerinterface'] as $record) {
                                $riTable[] = [
                                    $record['name'] ?? '',
                                    $record['type'] ?? '',
                                    Str::limit($record['content'] ?? '', 50),
                                    (string)($record['ttl'] ?? 3600),
                                    isset($record['priority']) ? (string)$record['priority'] : '-'
                                ];
                            }
                            table(['Name', 'Type', 'Content', 'TTL', 'Priority'], $riTable);
                        }

                        // Show original Plesk records
                        if (!empty($records['plesk_before'])) {
                            info("");
                            info("📤 Original DNS Records in Plesk:");
                            info(sprintf("Had %d DNS record(s) before sync", count($records['plesk_before'])));

                            $pleskTable = [];
                            foreach ($records['plesk_before'] as $record) {
                                $pleskTable[] = [
                                    $record['host'] ?? '',
                                    $record['type'] ?? '',
                                    Str::limit($record['value'] ?? '', 50),
                                    (string)($record['ttl'] ?? ''),
                                    $record['opt'] ?? '-'
                                ];
                            }
                            table(['Host', 'Type', 'Value', 'TTL', 'Opt'], $pleskTable);
                        }

                        // Summary
                        info("");
                        info("📊 Sync Summary:");
                        info("═══════════════");
                        info("  Status: " . ucfirst($details['status']));
                        info("  Records added: " . ($details['records_added'] ?? 0));
                        info("  Records deleted: " . ($details['records_deleted'] ?? 0));
                        info("  Records failed: " . ($details['records_failed'] ?? 0));
                        info("  NS records skipped: " . ($details['records_skipped_ns'] ?? 0));

                        // Show deleted records
                        if (!empty($records['deleted'])) {
                            info("");
                            info("🗑️ Deleted Records from Plesk:");
                            $deletedTable = [];
                            foreach ($records['deleted'] as $record) {
                                $deletedTable[] = [
                                    $record['host'] ?? '',
                                    $record['type'] ?? '',
                                    Str::limit($record['value'] ?? '', 50),
                                    (string)($record['ttl'] ?? ''),
                                ];
                            }
                            table(['Host', 'Type', 'Value', 'TTL'], $deletedTable);
                        }

                        // Show added records
                        if (!empty($records['added'])) {
                            info("");
                            info("✅ Added Records to Plesk:");
                            $addedTable = [];
                            foreach ($records['added'] as $record) {
                                $addedTable[] = [
                                    $record['name'] ?? '',
                                    $record['type'] ?? '',
                                    Str::limit($record['content'] ?? '', 50),
                                    (string)($record['ttl'] ?? ''),
                                    isset($record['priority']) ? (string)$record['priority'] : '-'
                                ];
                            }
                            table(['Name', 'Type', 'Content', 'TTL', 'Priority'], $addedTable);
                        }

                        // Show failed records
                        if (!empty($records['failed'])) {
                            info("");
                            error("❌ Failed Records:");
                            $failedTable = [];
                            foreach ($records['failed'] as $record) {
                                $error = $record['error'] ?? 'Unknown error';
                                $statusCode = isset($record['status_code']) ? " (HTTP {$record['status_code']})" : '';
                                $exception = isset($record['exception']) ? " [{$record['exception']}]" : '';

                                $failedTable[] = [
                                    $record['name'] ?? '',
                                    $record['type'] ?? '',
                                    Str::limit($record['content'] ?? '', 30),
                                    Str::limit($error . $statusCode . $exception, 60),
                                ];
                            }
                            table(['Name', 'Type', 'Content', 'Error'], $failedTable);
                        }
                    }

                    info("");
                    info("Press enter to continue...");
                    fgets(STDIN);
                    clear(); // Clear after user presses enter
                }
            }

            // Re-enable ResellerInterface DNS extension after all domains are synced
            if (!$this->dryRun) {
                info("Re-enabling ResellerInterface DNS extension...");
                try {
                    $enableResponse = plesk()->extensions()->enableExtension('resellerinterface-dns');
                    if ($enableResponse->failed()) {
                        warning("Failed to re-enable ResellerInterface DNS extension: " . $enableResponse->body());
                    }
                } catch (Exception $e) {
                    warning("Could not re-enable ResellerInterface DNS extension: " . $e->getMessage());
                }
            }

            return $failedCount > 0 ? self::FAILURE : self::SUCCESS;

        } catch (Exception $e) {
            // Try to re-enable extension even if sync failed
            if (!$this->dryRun) {
                try {
                    plesk()->extensions()->enableExtension('resellerinterface-dns');
                } catch (Exception $enableException) {
                    // Ignore enable errors in error handler
                }
            }

            error('Failed to sync domains: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function syncDomain(string $domainName): int
    {
        try {
            info("Fetching domain {$domainName} from ResellerInterface...");

            $response = resellerInterface()->domains()->domainList(
                resellerId: ResellerID::ALL,
                search: [
                    'sld' => Str::beforeLast($domainName, '.'),
                    'tld' => Str::afterLast($domainName, '.')
                ],
            );

            if ($response->failed()) {
                error("Failed to fetch domain from ResellerInterface: " . $response->body());
                return self::FAILURE;
            }

            $domains = $response->json()['list'] ?? [];
            $targetDomain = null;

            foreach ($domains as $domain) {
                if (strtolower($domain['domain'] ?? '') === strtolower($domainName)) {
                    $targetDomain = $domain;
                    break;
                }
            }

            if (!$targetDomain) {
                warning("Domain {$domainName} not found in ResellerInterface, skipping...");
                return self::SUCCESS;
            }

            // For single domain sync, we need to handle the extension
            if (!$this->dryRun) {
                info("Disabling ResellerInterface DNS extension to prevent sync loop...");
                try {
                    $disableResponse = plesk()->extensions()->disableExtension('resellerinterface-dns');
                    if ($disableResponse->failed()) {
                        error("Failed to disable ResellerInterface DNS extension: " . $disableResponse->body());
                        error("Cannot continue without disabling the extension - would cause sync loop!");
                        return self::FAILURE;
                    }
                } catch (Exception $e) {
                    error("Could not disable ResellerInterface DNS extension: " . $e->getMessage());
                    error("Cannot continue without disabling the extension - would cause sync loop!");
                    return self::FAILURE;
                }
            }

            $result = $this->syncDomainRecords($targetDomain);

            // Re-enable extension for single domain sync
            if (!$this->dryRun) {
                info("Re-enabling ResellerInterface DNS extension...");
                try {
                    $enableResponse = plesk()->extensions()->enableExtension('resellerinterface-dns');
                    if ($enableResponse->failed()) {
                        warning("Failed to re-enable ResellerInterface DNS extension: " . $enableResponse->body());
                    }
                } catch (Exception $e) {
                    warning("Could not re-enable ResellerInterface DNS extension: " . $e->getMessage());
                }
            }

            return $result;

        } catch (Exception $e) {
            error("Failed to sync domain {$domainName}: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function syncDomainRecords(array $domain): int|array
    {
        try {
            // Use 'domainName' key as seen in response, fallback to 'domain'
            $domainName = $domain['domainName'] ?? $domain['domain'] ?? '';
            $domainId = $domain['domainID'] ?? null;

            if (!$domainId || !$domainName) {
                error("Invalid domain data from ResellerInterface");
                return self::FAILURE;
            }

            $response = spin(
                fn() => resellerInterface()->dns()->dnsListRecords($domainId),
                "Fetching DNS records from ResellerInterface..."
            );

            if ($response->failed()) {
                error("Failed to fetch DNS records from ResellerInterface: " . $response->body());
                return self::FAILURE;
            }

            $responseData = $response->json() ?? [];
            $riRecords = $responseData['records'] ?? [];

            if (empty($riRecords)) {
                warning("No DNS records found in ResellerInterface for {$domainName}");
                return self::SUCCESS;
            }

            info(sprintf("Found %d DNS record(s) in ResellerInterface", count($riRecords)));

            $recordsTable = [];
            foreach ($riRecords as $record) {
                $recordsTable[] = [
                    $record['name'] ?? '',
                    $record['type'] ?? '',
                    Str::limit($record['content'] ?? ''),
                    (string)($record['ttl'] ?? 3600),
                    isset($record['priority']) ? (string)$record['priority'] : '-'
                ];
            }

            table(
                ['Name', 'Type', 'Content', 'TTL', 'Priority'],
                $recordsTable
            );

            // Store records for detail view
            $this->lastSyncRecords['resellerinterface'] = $riRecords;

            $pleskDomainId = null;
            $pleskResponse = plesk()->domains()->listAllDomains($domainName);
            if ($pleskResponse->failed()) {
                error("Failed to fetch domains from Plesk: " . $pleskResponse->body());
                return self::FAILURE;
            }
            $pleskDomains = $pleskResponse->json() ?? [];

            foreach ($pleskDomains as $pleskDomain) {
                if (strtolower($pleskDomain['name'] ?? '') === strtolower($domainName)) {
                    $pleskDomainId = $pleskDomain['id'] ?? null;
                    break;
                }
            }

            if (!$pleskDomainId) {
                error("Could not find domain ID in Plesk for {$domainName}");
                return self::FAILURE;
            }


            $pleskDnsResponse = plesk()->dns()->getDomainOrDomainAliasDnsRecords(
                domain: $domainName
            );

            if ($pleskDnsResponse->failed()) {
                error("Failed to fetch DNS records from Plesk: " . $pleskDnsResponse->body());
                return self::FAILURE;
            }

            $pleskDnsRecords = $pleskDnsResponse->json() ?? [];

            if ($this->dryRun) {
                $nsCount = 0;
                $deleteCount = 0;
                foreach ($pleskDnsRecords as $record) {
                    if (strtoupper($record['type'] ?? '') === 'NS') {
                        $nsCount++;
                    } else {
                        $deleteCount++;
                    }
                }

                $addCount = 0;
                foreach ($riRecords as $record) {
                    if (strtoupper($record['type'] ?? '') !== 'NS') {
                        $addCount++;
                    }
                }

                info("DRY RUN: Would disable ResellerInterface DNS extension");
                info("DRY RUN: Would delete {$deleteCount} existing records in Plesk (skipping {$nsCount} NS records)");
                info("DRY RUN: Would add {$addCount} records from ResellerInterface (excluding NS records)");
                info("DRY RUN: Would re-enable ResellerInterface DNS extension");
                return self::SUCCESS;
            }

            // Note: Extension is disabled/enabled in syncAllDomains() when processing multiple domains
            // For single domain sync, we need to handle it here
            $needsExtensionHandling = true; // We'll check if we're in single domain mode

            // Store existing records for potential rollback
            $backupRecords = [];
            foreach ($pleskDnsRecords as $pleskRecord) {
                if (strtoupper($pleskRecord['type'] ?? '') !== 'NS') {
                    $backupRecords[] = $pleskRecord;
                }
            }

            // Store Plesk records for detail view
            $this->lastSyncRecords['plesk_before'] = $pleskDnsRecords;
            $this->lastSyncRecords['deleted'] = [];

            $deletedCount = spin(
                function() use ($pleskDnsRecords, &$backupRecords) {
                    $deleted = 0;
                    $deletedRecords = [];
                    foreach ($pleskDnsRecords as $pleskRecord) {
                        try {
                            $recordType = $pleskRecord['type'] ?? '';
                            $recordId = $pleskRecord['id'] ?? null;

                            // Skip NS records - they should not be deleted
                            if (strtoupper($recordType) === 'NS') {
                                continue;
                            }

                            if ($recordId) {
                                $deleteResponse = plesk()->dns()->deleteDnsRecord(id: $recordId);
                                if ($deleteResponse->failed()) {
                                    warning("Failed to delete record ID {$recordId}: " . $deleteResponse->body());
                                } else {
                                    $deleted++;
                                    $deletedRecords[] = $pleskRecord;
                                }
                            }
                        } catch (Exception $e) {
                            warning("Failed to delete record ID {$recordId}: " . $e->getMessage());
                        }
                    }
                    $this->lastSyncRecords['deleted'] = $deletedRecords;
                    return $deleted;
                },
                'Deleting existing DNS records in Plesk (excluding NS records)...'
            );

            info("Deleted {$deletedCount} existing DNS records");

            // Store details for later display
            $this->lastSyncDetails = [
                'records_deleted' => $deletedCount,
                'records_added' => 0,
                'records_failed' => 0,
                'records_skipped_ns' => 0
            ];

            $result = spin(
                function() use ($riRecords, $domainName) {
                    $addedCount = 0;
                    $skippedNsCount = 0;
                    $failedRecords = [];
                    $addedRecords = [];

                    foreach ($riRecords as $record) {
                        try {
                            $type = $record['type'] ?? '';
                            $host = $record['name'] ?? '';
                            $value = $record['content'] ?? '';

                            // Skip NS records - they should not be synced
                            if (strtoupper($type) === 'NS') {
                                $skippedNsCount++;
                                continue;
                            }

                            $pleskType = $this->mapRecordType($type);

                            if (!$pleskType) {
                                continue;
                            }

                            // Process host name for Plesk
                            // Plesk expects FQDN with trailing dots
                            // e.g., "mail.sabrina-gutmann.de." not "mail.sabrina-gutmann.de"

                            // Remove any trailing dots first for processing
                            $host = rtrim($host, '.');
                            $domainNameClean = rtrim($domainName, '.');

                            // Determine the host value for Plesk
                            if ($host === $domainNameClean || $host === '@') {
                                // Root domain record
                                $pleskHost = $domainNameClean . '.';
                            } elseif (str_ends_with($host, '.' . $domainNameClean)) {
                                // Already has full domain - add trailing dot
                                $pleskHost = $host . '.';
                            } else {
                                // Host is just subdomain part - build full FQDN with trailing dot
                                $pleskHost = $host . '.' . $domainNameClean . '.';
                            }

                            // Process the value - add trailing dots for domain references
                            if (in_array(strtoupper($type), ['CNAME', 'MX', 'NS'])) {
                                $value = rtrim($value, '.') . '.';
                            }

                            // Handle record-specific opt field
                            $opt = null;

                            // MX records - priority goes in opt
                            if ($type === 'MX') {
                                $opt = (string)($record['priority'] ?? '10');
                            }

                            // SRV records - special handling
                            // ResellerInterface format: "weight port target" in content, priority separate
                            // Plesk format: "priority weight port" in opt, "target." in value
                            if (strtoupper($type) === 'SRV') {
                                $parts = explode(' ', $value);
                                if (count($parts) >= 3) {
                                    // ResellerInterface has priority separately
                                    $priority = $record['priority'] ?? '0';
                                    // Format for Plesk: "priority weight port" in opt
                                    $opt = $priority . ' ' . $parts[0] . ' ' . $parts[1];
                                    // Target goes to value with trailing dot
                                    $value = rtrim($parts[2], '.') . '.';
                                }
                            }

                            $createResponse = plesk()->dns()->createDomainOrDomainAliasDnsRecord(
                                type: $pleskType,
                                host: $pleskHost,
                                value: $value,
                                opt: $opt,
                                ttl: $record['ttl'] ?? 3600,
                                domain: $domainName
                            );

                            if ($createResponse->failed()) {
                                $failedRecords[] = array_merge($record, [
                                    'error' => $createResponse->body(),
                                    'status_code' => $createResponse->status()
                                ]);
                            } else {
                                $addedCount++;
                                $addedRecords[] = $record;
                            }

                        } catch (Exception $e) {
                            $failedRecords[] = array_merge($record, [
                                'error' => $e->getMessage(),
                                'exception' => get_class($e)
                            ]);
                        }
                    }

                    return [
                        'added' => $addedCount,
                        'skippedNs' => $skippedNsCount,
                        'failed' => $failedRecords,
                        'addedRecords' => $addedRecords ?? []
                    ];
                },
                'Adding DNS records from ResellerInterface to Plesk...'
            );

            $addedCount = $result['added'];
            $skippedNsCount = $result['skippedNs'];
            $failedRecords = $result['failed'];

            // Update sync details with final counts
            $this->lastSyncDetails['records_added'] = $addedCount;
            $this->lastSyncDetails['records_failed'] = count($failedRecords);
            $this->lastSyncDetails['records_skipped_ns'] = $skippedNsCount;
            $this->lastSyncRecords['added'] = $result['addedRecords'] ?? [];
            $this->lastSyncRecords['failed'] = $failedRecords;

            info("Added {$addedCount} DNS records");

            if ($skippedNsCount > 0) {
                info("Skipped {$skippedNsCount} NS records");
            }

            $totalProcessed = count($riRecords) - $skippedNsCount;

            // Check if we need to rollback due to failures
            if (!empty($failedRecords)) {
                error(sprintf("Failed to add %d records. Rolling back changes...", count($failedRecords)));

                // Restore the backed up records
                $restoredCount = spin(
                    function() use ($backupRecords, $domainName) {
                        $restored = 0;
                        foreach ($backupRecords as $backup) {
                            try {
                                $restoreType = DnsRecordType::tryFrom($backup['type'] ?? '');
                                if ($restoreType) {
                                    $response = plesk()->dns()->createDomainOrDomainAliasDnsRecord(
                                        type: $restoreType,
                                        host: $backup['host'] ?? '',
                                        value: $backup['value'] ?? '',
                                        opt: $backup['opt'] ?? null,
                                        ttl: $backup['ttl'] ?? 3600,
                                        domain: $domainName
                                    );
                                    if ($response->ok()) {
                                        $restored++;
                                    }
                                }
                            } catch (Exception $e) {
                                // Silently continue
                            }
                        }
                        return $restored;
                    },
                    'Rolling back changes...'
                );

                info("Restored {$restoredCount} of " . count($backupRecords) . " original records");

                // Re-enable extension and exit with failure
                try {
                    $enableResponse = plesk()->extensions()->enableExtension('resellerinterface-dns');
                    if ($enableResponse->failed()) {
                        error("Failed to re-enable ResellerInterface DNS extension after rollback: " . $enableResponse->body());
                    }
                } catch (Exception $e) {
                    error("Could not re-enable ResellerInterface DNS extension after rollback: " . $e->getMessage());
                }

                return self::FAILURE;
            }

            info("Successfully synced {$addedCount}/{$totalProcessed} DNS records (excluded {$skippedNsCount} NS records)");

            // Re-enable ResellerInterface DNS extension
            info("Re-enabling ResellerInterface DNS extension...");
            try {
                $enableResponse = plesk()->extensions()->enableExtension('resellerinterface-dns');
                if ($enableResponse->failed()) {
                    warning("Failed to re-enable ResellerInterface DNS extension: " . $enableResponse->body());
                }
            } catch (Exception $e) {
                warning("Could not re-enable ResellerInterface DNS extension: " . $e->getMessage());
            }

            return $addedCount === $totalProcessed ? self::SUCCESS : self::FAILURE;

        } catch (Exception $e) {
            // Try to re-enable extension even if sync failed
            try {
                plesk()->extensions()->enableExtension('resellerinterface-dns');
            } catch (Exception $enableException) {
                // Ignore enable errors in error handler
            }

            error("Failed to sync domain records: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function mapRecordType(string $riType): ?DnsRecordType
    {
        return match(strtoupper($riType)) {
            'A' => DnsRecordType::A,
            'AAAA' => DnsRecordType::AAAA,
            'CNAME' => DnsRecordType::CNAME,
            'MX' => DnsRecordType::MX,
            'TXT' => DnsRecordType::TXT,
            'NS' => DnsRecordType::NS,
            'SRV' => DnsRecordType::SRV,
            'PTR' => DnsRecordType::PTR,
            default => null,
        };
    }
}
