<?php
declare(strict_types=1);

class DevRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getGallery(string $uploadsDir): array
    {
        if (!is_dir($uploadsDir)) {
            return [];
        }

        $files = scandir($uploadsDir);
        if ($files === false) {
            return [];
        }

        $result = [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $filePath = $uploadsDir . '/' . $file;
            if (is_file($filePath)) {
                $mimeType = mime_content_type($filePath);
                $isImage = $mimeType && str_starts_with((string)$mimeType, 'image/');
                
                if ($isImage) {
                    $result[] = [
                        'filename' => $file,
                        'file_path' => '/uploads/' . $file,
                        'size_bytes' => filesize($filePath),
                        'created_at' => date(DATE_ATOM, filectime($filePath)),
                        'mime_type' => $mimeType,
                    ];
                }
            }
        }

        usort($result, static fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        
        return $result;
    }

    public function deleteImage(string $uploadsDir, string $filename): bool
    {
        $filename = basename($filename); 
        $filePath = $uploadsDir . '/' . $filename;
        if (is_file($filePath)) {
            return unlink($filePath);
        }
        return false;
    }

    public function exportDatabase(bool $noCreateInfo = false): string
    {
        $sql = "-- Database Export" . ($noCreateInfo ? " (Data Only)" : "") . "\n";
        $sql .= "-- Generated at: " . date(DATE_ATOM) . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $stmt = $this->pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            if (!$noCreateInfo) {
                $sql .= "-- Table structure for `$table`\n";
                $sql .= "DROP TABLE IF EXISTS `$table`;\n";

                $createStmt = $this->pdo->query("SHOW CREATE TABLE `$table`");
                $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
                $createTableSql = $createRow['Create Table'] ?? '';
                $sql .= $createTableSql . ";\n\n";
            }

            $sql .= "-- Data for table `$table`\n";
            $dataStmt = $this->pdo->query("SELECT * FROM `$table`");
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) > 0) {
                foreach ($rows as $row) {
                    $keys = array_keys($row);
                    $values = array_values($row);
                    $escapedValues = array_map(function ($val) {
                        if ($val === null) return 'NULL';
                        return $this->pdo->quote((string)$val);
                    }, $values);

                    $insertOrReplace = $noCreateInfo ? "REPLACE INTO" : "INSERT INTO";
                    $sql .= "$insertOrReplace `$table` (`" . implode("`, `", $keys) . "`) VALUES (" . implode(", ", $escapedValues) . ");\n";
                }
            }
            $sql .= "\n\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $sql;
    }

    public function importDatabase(string $sqlContent): void
    {
        $backupSql = $this->exportDatabase();
        $expectedTables = $this->listTables($this->pdo);
        $databaseName = $this->getDatabaseName();
        $databaseCharset = $this->getDatabaseCharset();
        $adminPdo = Database::createConnection();

        try {
            $this->recreateDatabase($adminPdo, $databaseName, $databaseCharset);
            $importPdo = Database::createConnection($databaseName);
            $this->executeSqlScript($importPdo, $sqlContent);
            $this->assertDatabaseHealthy($importPdo, $expectedTables);
        } catch (Throwable $e) {
            try {
                $this->recreateDatabase($adminPdo, $databaseName, $databaseCharset);
                $restorePdo = Database::createConnection($databaseName);
                $this->executeSqlScript($restorePdo, $backupSql);
                $this->assertDatabaseHealthy($restorePdo, $expectedTables);
            } catch (Throwable $eRestore) {
                throw new Exception("Import failed and rollback ALSO failed! Import error: " . $e->getMessage() . " | Rollback error: " . $eRestore->getMessage());
            }

            throw new Exception("Import failed. Database has been rolled back to its previous state. Original error: " . $e->getMessage());
        }
    }

    private function getDatabaseName(): string
    {
        $databaseName = env('DB_NAME', 'pet_hotel_site');
        if (!is_string($databaseName) || trim($databaseName) === '') {
            throw new Exception('DB_NAME is not configured.');
        }

        return trim($databaseName);
    }

    private function getDatabaseCharset(): string
    {
        $charset = env('DB_CHARSET', 'utf8mb4');
        return is_string($charset) && trim($charset) !== '' ? trim($charset) : 'utf8mb4';
    }

    private function listTables(PDO $pdo): array
    {
        $stmt = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map(
            static fn(mixed $table): string => is_string($table) ? $table : '',
            $tables
        )));
    }

    private function recreateDatabase(PDO $adminPdo, string $databaseName, string $charset): void
    {
        $quotedName = $this->quoteIdentifier($databaseName);
        $safeCharset = preg_replace('/[^a-zA-Z0-9_]/', '', $charset) ?: 'utf8mb4';

        $adminPdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $adminPdo->exec("DROP DATABASE IF EXISTS {$quotedName}");
        $adminPdo->exec("CREATE DATABASE {$quotedName} CHARACTER SET {$safeCharset}");
        $adminPdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function executeSqlScript(PDO $pdo, string $sqlContent): void
    {
        $statements = $this->splitSqlStatements($sqlContent);
        if ($statements === []) {
            throw new Exception('SQL import content does not contain any executable statements.');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function splitSqlStatements(string $sqlContent): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sqlContent);
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($index = 0; $index < $length; $index++) {
            $char = $sqlContent[$index];
            $next = $index + 1 < $length ? $sqlContent[$index + 1] : '';
            $prev = $index > 0 ? $sqlContent[$index - 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $buffer .= $char;
                }
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $index++;
                }
                continue;
            }

            if (!$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                if ($char === '#' || ($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sqlContent[$index + 2])))) {
                    $inLineComment = true;
                    if ($char === '-') {
                        $index++;
                    }
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $index++;
                    continue;
                }
            }

            if ($char === "'" && !$inDoubleQuote && !$inBacktick) {
                $escaped = $prev === '\\';
                $doubleQuotedQuote = $inSingleQuote && $next === "'";
                if (!$escaped && !$doubleQuotedQuote) {
                    $inSingleQuote = !$inSingleQuote;
                }
                $buffer .= $char;
                if ($doubleQuotedQuote) {
                    $buffer .= $next;
                    $index++;
                }
                continue;
            }

            if ($char === '"' && !$inSingleQuote && !$inBacktick) {
                $escaped = $prev === '\\';
                $doubleQuotedQuote = $inDoubleQuote && $next === '"';
                if (!$escaped && !$doubleQuotedQuote) {
                    $inDoubleQuote = !$inDoubleQuote;
                }
                $buffer .= $char;
                if ($doubleQuotedQuote) {
                    $buffer .= $next;
                    $index++;
                }
                continue;
            }

            if ($char === '`' && !$inSingleQuote && !$inDoubleQuote) {
                $inBacktick = !$inBacktick;
                $buffer .= $char;
                continue;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote && !$inBacktick) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    private function assertDatabaseHealthy(PDO $pdo, array $expectedTables): void
    {
        $tables = $this->listTables($pdo);
        if ($tables === []) {
            throw new Exception('Imported database is empty after import.');
        }

        if ($expectedTables !== []) {
            $missingTables = array_values(array_diff($expectedTables, $tables));
            if ($missingTables !== []) {
                throw new Exception('Imported database is missing expected tables: ' . implode(', ', $missingTables));
            }
        }

        $pdo->query('SELECT 1')->fetchColumn();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
