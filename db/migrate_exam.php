<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/exam.php';

ensure_exam_tables();
fwrite(STDOUT, "Migrasi schema ujian selesai.\n");
