<?php

declare(strict_types=1);

/**
 * Minimal migration runner: no framework, just "run any .sql file in
 * migrations/ that isn't already recorded in the migrations table, in
 * filename order." Good enough for a project with a handful of tables that
 * never need to change shape mid-project; a real rollback/down-migration
 * system would be more machinery than this project's schema needs.
 *
 * Usage: php database/migrate.php
 */

require __DIR__ . '/../src/Config/Env.php';
require __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;
use App\Config\Env;

Env::load(__DIR__ . '/../.env');
$pdo = Database::connection();

$pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS migrations (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration VARCHAR(255) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_migrations_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);

$applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "Applying {$name}... ";
    $sql = file_get_contents($file);
    $pdo->exec($sql);

    $stmt = $pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
    $stmt->execute(['migration' => $name]);

    echo "done\n";
    $ran++;
}

echo $ran === 0 ? "Nothing to migrate.\n" : "{$ran} migration(s) applied.\n";
