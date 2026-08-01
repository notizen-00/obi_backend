<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/mysql.php';

$login_attempts = db()->exec(
    'DELETE FROM exam_api_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
);
$expired_tokens = db()->exec(
    'DELETE FROM exam_api_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
);
$revoked_tokens = db()->exec(
    'DELETE FROM exam_api_tokens WHERE revoked_at IS NOT NULL
        AND revoked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
);

fwrite(STDOUT, sprintf(
    "Cleanup selesai: %d login attempts, %d token kedaluwarsa, %d token dicabut.\n",
    $login_attempts,
    $expired_tokens,
    $revoked_tokens
));
