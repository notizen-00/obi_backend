<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/exam.php';
require_once __DIR__ . '/operators.php';

ensure_exam_tables();
$seededOperators = migrate_operators();
fwrite(STDOUT, "Migrasi schema ujian selesai. Seed operator baru: $seededOperators.\n");
