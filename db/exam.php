<?php
declare(strict_types=1);

require_once __DIR__ . '/mysql.php';

const EXAM_DURATION_SECONDS = 120 * 60;
const SURVEY_URL = '../survey/';

function ensure_exam_tables(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    db()->exec("CREATE TABLE IF NOT EXISTS exam_types (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        exam_kind ENUM('main','tryout') NOT NULL DEFAULT 'main',
        description TEXT DEFAULT NULL,
        duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 120,
        question_count TINYINT UNSIGNED NOT NULL DEFAULT 50,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_exam_types_name (name),
        KEY idx_exam_types_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrate_exam_types_kind();

    db()->exec("INSERT INTO exam_types (name, exam_kind, description, duration_minutes, question_count, is_active, is_default)
        SELECT 'OBI EXAM 2026', 'main', 'Ujian utama untuk 50 soal yang sudah ada.', 120, 50, 1, 1
        WHERE NOT EXISTS (SELECT 1 FROM exam_types)");
    db()->exec('UPDATE exam_types SET is_default = 0 WHERE is_active = 0 AND is_default = 1');

    db()->exec("CREATE TABLE IF NOT EXISTS exam_questions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        exam_type_id INT UNSIGNED NOT NULL,
        question_no TINYINT UNSIGNED NOT NULL,
        question_text TEXT NOT NULL,
        option_a TEXT NOT NULL,
        option_b TEXT NOT NULL,
        option_c TEXT NOT NULL,
        option_d TEXT NOT NULL,
        option_e TEXT NOT NULL,
        correct_option ENUM('A','B','C','D','E') DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_exam_questions_type_no (exam_type_id, question_no),
        KEY idx_exam_questions_active_no (exam_type_id, is_active, question_no),
        CONSTRAINT fk_exam_questions_type FOREIGN KEY (exam_type_id) REFERENCES exam_types (id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrate_exam_questions_type();

    db()->exec("CREATE TABLE IF NOT EXISTS exam_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        exam_user_id VARCHAR(32) NOT NULL,
        exam_type_id INT UNSIGNED NOT NULL,
        started_at DATETIME DEFAULT NULL,
        ends_at DATETIME DEFAULT NULL,
        submitted_at DATETIME DEFAULT NULL,
        status ENUM('not_started','in_progress','submitted','expired') NOT NULL DEFAULT 'not_started',
        answers_json LONGTEXT NOT NULL,
        question_order_json LONGTEXT NOT NULL,
        last_saved_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_exam_attempt_user_type (exam_user_id, exam_type_id),
        KEY idx_exam_attempt_user (exam_user_id),
        KEY idx_exam_attempt_status (status),
        KEY idx_exam_attempt_type (exam_type_id),
        CONSTRAINT fk_exam_attempt_user FOREIGN KEY (exam_user_id) REFERENCES exam_users (id) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT fk_exam_attempt_type FOREIGN KEY (exam_type_id) REFERENCES exam_types (id) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrate_exam_attempts_type();

    db()->exec("CREATE TABLE IF NOT EXISTS exam_surveys (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        exam_user_id VARCHAR(32) DEFAULT NULL,
        exam_type_id INT UNSIGNED DEFAULT NULL,
        exam_attempt_id BIGINT UNSIGNED DEFAULT NULL,
        nama VARCHAR(150) DEFAULT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        kesan TEXT NOT NULL,
        saran TEXT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_exam_surveys_attempt (exam_attempt_id),
        KEY idx_exam_surveys_user (exam_user_id),
        KEY idx_exam_surveys_type (exam_type_id),
        CONSTRAINT fk_exam_surveys_type FOREIGN KEY (exam_type_id) REFERENCES exam_types (id) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT fk_exam_surveys_attempt FOREIGN KEY (exam_attempt_id) REFERENCES exam_attempts (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrate_exam_surveys();

    db()->exec("CREATE TABLE IF NOT EXISTS exam_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        exam_user_id VARCHAR(32) NOT NULL,
        event_type VARCHAR(40) NOT NULL,
        event_data VARCHAR(255) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_exam_events_user_created (exam_user_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS exam_api_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        exam_user_id VARCHAR(32) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        last_used_at DATETIME DEFAULT NULL,
        revoked_at DATETIME DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_exam_api_token_hash (token_hash),
        KEY idx_exam_api_token_user (exam_user_id),
        KEY idx_exam_api_token_expiry (expires_at, revoked_at),
        CONSTRAINT fk_exam_api_token_user FOREIGN KEY (exam_user_id) REFERENCES exam_users (id) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS exam_api_login_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        identity_hash CHAR(64) NOT NULL,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_exam_api_login_identity_time (identity_hash, attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    migrate_exam_performance_indexes();
}

function schema_column_exists(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function schema_column_is_nullable(string $table, string $column): bool
{
    $stmt = db()->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->execute([$table, $column]);
    return $stmt->fetchColumn() === 'YES';
}

function schema_index_exists(string $table, string $index): bool
{
    $stmt = db()->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function schema_index_columns(string $table, string $index): array
{
    $stmt = db()->prepare('SELECT COLUMN_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
        ORDER BY SEQ_IN_INDEX');
    $stmt->execute([$table, $index]);
    return array_column($stmt->fetchAll(), 'COLUMN_NAME');
}

function schema_foreign_key_exists(string $table, string $constraint): bool
{
    $stmt = db()->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1');
    $stmt->execute([$table, $constraint, 'FOREIGN KEY']);
    return (bool)$stmt->fetchColumn();
}

function default_exam_type_id(): int
{
    $id = db()->query("SELECT id FROM exam_types WHERE is_active = 1 AND exam_kind = 'main' ORDER BY is_default DESC, id ASC LIMIT 1")->fetchColumn();
    return (int)$id;
}

function default_exam_type(): array
{
    $type = db()->query("SELECT * FROM exam_types WHERE is_active = 1 AND exam_kind = 'main' ORDER BY is_default DESC, id ASC LIMIT 1")->fetch();
    return $type ?: [];
}

function any_exam_type_id(): int
{
    return (int)db()->query('SELECT id FROM exam_types ORDER BY id ASC LIMIT 1')->fetchColumn();
}

function migrate_exam_types_kind(): void
{
    if (schema_column_exists('exam_types', 'exam_kind')) {
        return;
    }

    db()->exec("ALTER TABLE exam_types ADD COLUMN exam_kind ENUM('main','tryout') NOT NULL DEFAULT 'tryout' AFTER name");
    db()->exec("UPDATE exam_types SET exam_kind = CASE WHEN is_default = 1 THEN 'main' ELSE 'tryout' END");
}

function migrate_exam_questions_type(): void
{
    if (!schema_column_exists('exam_questions', 'exam_type_id')) {
        db()->exec('ALTER TABLE exam_questions ADD COLUMN exam_type_id INT UNSIGNED DEFAULT NULL AFTER id');
    }

    $default_id = default_exam_type_id() ?: any_exam_type_id();
    $stmt = db()->prepare('UPDATE exam_questions SET exam_type_id = ? WHERE exam_type_id IS NULL');
    $stmt->execute([$default_id]);

    if (schema_index_exists('exam_questions', 'uniq_exam_questions_no')) {
        db()->exec('ALTER TABLE exam_questions DROP INDEX uniq_exam_questions_no');
    }
    if (!schema_index_exists('exam_questions', 'uniq_exam_questions_type_no')) {
        db()->exec('ALTER TABLE exam_questions ADD UNIQUE KEY uniq_exam_questions_type_no (exam_type_id, question_no)');
    }
    if (!schema_foreign_key_exists('exam_questions', 'fk_exam_questions_type')) {
        db()->exec('ALTER TABLE exam_questions ADD CONSTRAINT fk_exam_questions_type FOREIGN KEY (exam_type_id) REFERENCES exam_types (id) ON DELETE RESTRICT ON UPDATE CASCADE');
    }

    if (schema_column_is_nullable('exam_questions', 'exam_type_id')) {
        db()->exec('ALTER TABLE exam_questions MODIFY exam_type_id INT UNSIGNED NOT NULL');
    }
}

function migrate_exam_attempts_type(): void
{
    if (!schema_column_exists('exam_attempts', 'exam_type_id')) {
        db()->exec('ALTER TABLE exam_attempts ADD COLUMN exam_type_id INT UNSIGNED DEFAULT NULL AFTER exam_user_id');
    }

    $default_id = default_exam_type_id() ?: any_exam_type_id();
    $stmt = db()->prepare('UPDATE exam_attempts SET exam_type_id = ? WHERE exam_type_id IS NULL');
    $stmt->execute([$default_id]);

    if (!schema_index_exists('exam_attempts', 'idx_exam_attempt_user')) {
        db()->exec('ALTER TABLE exam_attempts ADD KEY idx_exam_attempt_user (exam_user_id)');
    }
    if (schema_index_exists('exam_attempts', 'uniq_exam_attempt_user')) {
        db()->exec('ALTER TABLE exam_attempts DROP INDEX uniq_exam_attempt_user');
    }
    if (!schema_index_exists('exam_attempts', 'uniq_exam_attempt_user_type')) {
        db()->exec('ALTER TABLE exam_attempts ADD UNIQUE KEY uniq_exam_attempt_user_type (exam_user_id, exam_type_id)');
    }
    if (!schema_index_exists('exam_attempts', 'idx_exam_attempt_type')) {
        db()->exec('ALTER TABLE exam_attempts ADD KEY idx_exam_attempt_type (exam_type_id)');
    }
    if (!schema_foreign_key_exists('exam_attempts', 'fk_exam_attempt_type')) {
        db()->exec('ALTER TABLE exam_attempts ADD CONSTRAINT fk_exam_attempt_type FOREIGN KEY (exam_type_id) REFERENCES exam_types (id) ON DELETE RESTRICT ON UPDATE CASCADE');
    }
    if (schema_column_is_nullable('exam_attempts', 'exam_type_id')) {
        db()->exec('ALTER TABLE exam_attempts MODIFY exam_type_id INT UNSIGNED NOT NULL');
    }
}

function migrate_exam_surveys(): void
{
    if (!schema_column_exists('exam_surveys', 'exam_type_id')) {
        db()->exec('ALTER TABLE exam_surveys ADD COLUMN exam_type_id INT UNSIGNED DEFAULT NULL AFTER exam_user_id');
    }
    if (!schema_column_exists('exam_surveys', 'exam_attempt_id')) {
        db()->exec('ALTER TABLE exam_surveys ADD COLUMN exam_attempt_id BIGINT UNSIGNED DEFAULT NULL AFTER exam_type_id');
    }
    if (!schema_index_exists('exam_surveys', 'uniq_exam_surveys_attempt')) {
        db()->exec('ALTER TABLE exam_surveys ADD UNIQUE KEY uniq_exam_surveys_attempt (exam_attempt_id)');
    }
    if (!schema_index_exists('exam_surveys', 'idx_exam_surveys_type')) {
        db()->exec('ALTER TABLE exam_surveys ADD KEY idx_exam_surveys_type (exam_type_id)');
    }
    if (!schema_foreign_key_exists('exam_surveys', 'fk_exam_surveys_type')) {
        db()->exec('ALTER TABLE exam_surveys ADD CONSTRAINT fk_exam_surveys_type FOREIGN KEY (exam_type_id) REFERENCES exam_types (id) ON DELETE RESTRICT ON UPDATE CASCADE');
    }
    if (!schema_foreign_key_exists('exam_surveys', 'fk_exam_surveys_attempt')) {
        db()->exec('ALTER TABLE exam_surveys ADD CONSTRAINT fk_exam_surveys_attempt FOREIGN KEY (exam_attempt_id) REFERENCES exam_attempts (id) ON DELETE CASCADE ON UPDATE CASCADE');
    }
}

function ensure_exam_index(string $table, string $index, array $columns): void
{
    if (schema_index_columns($table, $index) === $columns) {
        return;
    }
    if (schema_index_exists($table, $index)) {
        db()->exec("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }
    $column_sql = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
    db()->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$column_sql})");
}

function migrate_exam_performance_indexes(): void
{
    ensure_exam_index('exam_types', 'idx_exam_types_api_list', ['is_active', 'exam_kind', 'is_default', 'id']);
    ensure_exam_index('exam_questions', 'idx_exam_questions_active_no', ['exam_type_id', 'is_active', 'question_no']);
    if (schema_index_exists('exam_attempts', 'idx_exam_attempt_user_status_end')) {
        db()->exec('ALTER TABLE exam_attempts DROP INDEX idx_exam_attempt_user_status_end');
    }
    ensure_exam_index('exam_attempts', 'idx_exam_attempt_type_status', ['exam_type_id', 'status', 'id']);
    ensure_exam_index('exam_api_tokens', 'idx_exam_api_token_revoked', ['revoked_at']);
    ensure_exam_index('exam_api_login_attempts', 'idx_exam_api_login_attempted_at', ['attempted_at']);
}

function exam_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function exam_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function exam_log(string $user_id, string $event, string $data = ''): void
{
    try {
        $stmt = db()->prepare('INSERT INTO exam_events (exam_user_id, event_type, event_data, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$user_id, $event, substr($data, 0, 255), exam_ip()]);
    } catch (Throwable $e) {
    }
}

function current_exam_user(): ?array
{
    return $_SESSION['exam_user'] ?? null;
}

function require_exam_user(): array
{
    $user = current_exam_user();
    if (!$user) {
        exam_json(['success' => false, 'error' => 'Sesi ujian berakhir. Silakan login ulang.', 'auth' => false]);
    }
    return $user;
}

function get_or_create_attempt(string $exam_user_id, ?int $exam_type_id = null): array
{
    $exam_type_id = $exam_type_id ?: default_exam_type_id();
    if ($exam_type_id < 1) {
        throw new RuntimeException('Belum ada ujian utama yang aktif.');
    }
    $stmt = db()->prepare('SELECT * FROM exam_attempts WHERE exam_user_id = ? AND exam_type_id = ? LIMIT 1');
    $stmt->execute([$exam_user_id, $exam_type_id]);
    $attempt = $stmt->fetch();
    if ($attempt) {
        return $attempt;
    }

    try {
        $stmt = db()->prepare("INSERT INTO exam_attempts
            (exam_user_id, exam_type_id, status, answers_json, question_order_json)
            VALUES (?, ?, 'not_started', '{}', '[]')");
        $stmt->execute([$exam_user_id, $exam_type_id]);
        $now = date('Y-m-d H:i:s');
        return [
            'id' => (int)db()->lastInsertId(),
            'exam_user_id' => $exam_user_id,
            'exam_type_id' => $exam_type_id,
            'started_at' => null,
            'ends_at' => null,
            'submitted_at' => null,
            'status' => 'not_started',
            'answers_json' => '{}',
            'question_order_json' => '[]',
            'last_saved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    } catch (PDOException $error) {
        if ($error->getCode() !== '23000') {
            throw $error;
        }
        $stmt = db()->prepare('SELECT * FROM exam_attempts WHERE exam_user_id = ? AND exam_type_id = ? LIMIT 1');
        $stmt->execute([$exam_user_id, $exam_type_id]);
        $attempt = $stmt->fetch();
        if (!$attempt) {
            throw $error;
        }
        return $attempt;
    }
}

function refresh_attempt_status(array $attempt): array
{
    if ($attempt['status'] === 'in_progress' && !empty($attempt['ends_at']) && strtotime($attempt['ends_at']) <= time()) {
        $stmt = db()->prepare("UPDATE exam_attempts SET status = 'expired', submitted_at = COALESCE(submitted_at, NOW()) WHERE id = ?");
        $stmt->execute([$attempt['id']]);
        $attempt['status'] = 'expired';
        $attempt['submitted_at'] = date('Y-m-d H:i:s');
    }
    return $attempt;
}

function active_questions(?int $exam_type_id = null, ?int $question_count = null): array
{
    $type = $exam_type_id
        ? ($question_count === null ? (function () use ($exam_type_id) {
            $stmt = db()->prepare('SELECT * FROM exam_types WHERE id = ? LIMIT 1');
            $stmt->execute([$exam_type_id]);
            return $stmt->fetch() ?: [];
        })() : ['id' => $exam_type_id, 'question_count' => $question_count])
        : default_exam_type();
    if (!$type) {
        return [];
    }
    $limit = max(1, min(100, (int)($type['question_count'] ?? 50)));
    $stmt = db()->prepare('SELECT question_no, question_text, option_a, option_b, option_c, option_d, option_e FROM exam_questions WHERE exam_type_id = ? AND is_active = 1 ORDER BY question_no ASC LIMIT ' . $limit);
    $stmt->execute([(int)$type['id']]);
    return $stmt->fetchAll();
}
