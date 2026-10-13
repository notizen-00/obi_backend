<?php

declare(strict_types=1);

require_once __DIR__ . '/mysql.php';

function ensure_operators_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS operators (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        username VARCHAR(80) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        nama VARCHAR(150) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_operators_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seed_operators_from_json(): int
{
    $seedFile = __DIR__ . '/operators.json';
    if (!is_file($seedFile)) {
        throw new RuntimeException('File seed operator tidak ditemukan: ' . $seedFile);
    }

    $operators = json_decode((string) file_get_contents($seedFile), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($operators)) {
        throw new RuntimeException('Format seed operator harus berupa array JSON.');
    }

    $insert = db()->prepare(
        'INSERT IGNORE INTO operators (username, password_hash, nama, created_at)
         VALUES (?, ?, ?, ?)'
    );
    $seeded = 0;

    foreach ($operators as $operator) {
        if (!is_array($operator)) {
            throw new RuntimeException('Data operator pada file seed tidak valid.');
        }

        $username = trim((string) ($operator['username'] ?? ''));
        $passwordHash = (string) ($operator['password_hash'] ?? '');
        $name = trim((string) ($operator['nama'] ?? $username));
        $createdAt = (string) ($operator['created_at'] ?? date('Y-m-d H:i:s'));

        if ($username === '' || $passwordHash === '' || $name === '') {
            throw new RuntimeException('Seed operator harus memiliki username, password_hash, dan nama.');
        }

        $insert->execute([$username, $passwordHash, $name, $createdAt]);
        $seeded += $insert->rowCount();
    }

    return $seeded;
}

function migrate_operators(): int
{
    ensure_operators_table();
    return seed_operators_from_json();
}
