<?php

declare(strict_types=1);

require_once __DIR__ . '/../db/exam.php';

// const CBT_ALLOWED_ORIGIN = 'https://exam.obi.apps.unej.ac.id';
const CBT_ALLOWED_ORIGIN = "http://localhost:5173";

function api_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_allow_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    if (!hash_equals(CBT_ALLOWED_ORIGIN, $origin)) {
        api_response(['success' => false, 'error' => 'Origin frontend tidak diizinkan.'], 403);
    }

    header('Access-Control-Allow-Origin: ' . CBT_ALLOWED_ORIGIN);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

function api_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $same_site = ucfirst(strtolower(getenv('CBT_COOKIE_SAMESITE') ?: 'Lax'));
    if (!in_array($same_site, ['Lax', 'Strict', 'None'], true)) {
        $same_site = 'Lax';
    }
    $secure_cookie = api_is_https();
    if ($same_site === 'None' && !$secure_cookie) {
        api_response(['success' => false, 'error' => 'Cookie SameSite=None hanya dapat digunakan melalui HTTPS.'], 500);
    }
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => $same_site,
        'secure' => $secure_cookie,
        'path' => '/',
    ]);
    session_start();
}

function api_input(): array
{
    $input = $_POST;
    $content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($content_type, 'application/json')) {
        $decoded = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($decoded)) {
            api_response(['success' => false, 'error' => 'Body JSON tidak valid.'], 400);
        }
        $input = $decoded;
    }
    return $input;
}

function api_exam_type(int $id): array
{
    if ($id < 1) {
        api_response(['success' => false, 'error' => 'exam_type_id wajib diisi.'], 422);
    }
    $stmt = db()->prepare('SELECT id, name, exam_kind, description, duration_minutes,
        question_count, is_default
        FROM exam_types
        WHERE id = ? AND is_active = 1
        LIMIT 1');
    $stmt->execute([$id]);
    $type = $stmt->fetch();
    if (!$type) {
        api_response(['success' => false, 'error' => 'Ujian tidak ditemukan atau belum aktif.'], 404);
    }
    return $type;
}

function api_resolve_exam_type_id(int $id, string $kind, string $action): int
{
    if ($kind !== '' && !in_array($kind, ['main', 'tryout'], true)) {
        api_response(['success' => false, 'error' => 'exam_kind harus bernilai main atau tryout.'], 422);
    }
    if ($id > 0) {
        return $id;
    }
    if ($action !== 'start' || $kind === '') {
        api_response(['success' => false, 'error' => 'exam_type_id wajib diisi.'], 422);
    }

    if ($kind === 'main') {
        $stmt = db()->query("SELECT id FROM exam_types
            WHERE exam_kind = 'main' AND is_active = 1
            ORDER BY is_default DESC, id ASC LIMIT 1");
        $resolved = (int)$stmt->fetchColumn();
        if ($resolved < 1) {
            api_response(['success' => false, 'error' => 'Ujian utama aktif tidak ditemukan.'], 404);
        }
        return $resolved;
    }

    $rows = db()->query("SELECT id FROM exam_types
        WHERE exam_kind = 'tryout' AND is_active = 1
        ORDER BY id ASC
        LIMIT 2")->fetchAll();
    if (count($rows) === 0) {
        api_response(['success' => false, 'error' => 'Tryout aktif tidak ditemukan.'], 404);
    }
    if (count($rows) > 1) {
        api_response(['success' => false, 'error' => 'Terdapat beberapa tryout aktif. Sertakan exam_type_id yang dipilih.'], 422);
    }
    return (int)$rows[0]['id'];
}

function api_type_payload(array $type): array
{
    return [
        'id' => (int)$type['id'],
        'name' => $type['name'],
        'kind' => $type['exam_kind'],
        'description' => $type['description'],
        'duration_minutes' => (int)$type['duration_minutes'],
        'question_count' => (int)$type['question_count'],
        'is_default' => (bool)$type['is_default'],
    ];
}

function api_is_https(): bool
{
    $direct_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $trusted_proxy_https = getenv('CBT_TRUST_PROXY_HTTPS') === '1'
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return $direct_https || $trusted_proxy_https;
}

function api_require_secure_transport(): void
{
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    $remote = strtolower($_SERVER['REMOTE_ADDR'] ?? '');
    $loopback_request = in_array($remote, ['127.0.0.1', '::1'], true);
    $local_host = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
        || str_ends_with($host, '.test');
    $local_request = $loopback_request && $local_host;
    if (!api_is_https() && !$local_request) {
        api_response(['success' => false, 'error' => 'Bearer token hanya boleh digunakan melalui HTTPS.'], 400);
    }
}

function api_bearer_token(): string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    return preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,})$/i', trim($header), $match) ? $match[1] : '';
}

function api_token_ttl(): int
{
    $configured = (int)(getenv('CBT_TOKEN_TTL') ?: 28800);
    return max(300, min(86400, $configured));
}

function api_issue_token(string $user_id): array
{
    api_require_secure_transport();
    $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $hash = hash('sha256', $token);
    $ttl = api_token_ttl();
    $expires_at = date('Y-m-d H:i:s', time() + $ttl);
    $stmt = db()->prepare('INSERT INTO exam_api_tokens
        (exam_user_id, token_hash, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $user_id,
        $hash,
        $expires_at,
        substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45),
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255) ?: null,
    ]);
    return [
        'access_token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $ttl,
        'expires_at' => $expires_at,
    ];
}

function api_user_from_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    api_require_secure_transport();
    $stmt = db()->prepare("SELECT t.id AS token_id, t.last_used_at, eu.id, eu.nama, eu.username, eu.email, eu.nik,
        eu.participant_id, p.nama_sekolah, p.foto_profil, p.status_bayar
        FROM exam_api_tokens t
        JOIN exam_users eu ON eu.id = t.exam_user_id
        JOIN participants p ON p.id = eu.participant_id
        WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()
            AND eu.status = 'active'
        LIMIT 1");
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row || ($row['status_bayar'] ?? '') !== 'lunas') {
        return null;
    }
    if (empty($row['last_used_at']) || strtotime($row['last_used_at']) < time() - 300) {
        $update = db()->prepare('UPDATE exam_api_tokens SET last_used_at = NOW() WHERE id = ?');
        $update->execute([$row['token_id']]);
    }
    return [
        'id' => $row['id'],
        'nama' => $row['nama'],
        'username' => $row['username'],
        'email' => $row['email'],
        'nik' => $row['nik'],
        'participant_id' => $row['participant_id'],
        'sekolah' => $row['nama_sekolah'],
        'foto_profil' => $row['foto_profil'],
    ];
}

function api_revoke_token(string $token): void
{
    if ($token === '') {
        return;
    }
    $stmt = db()->prepare('UPDATE exam_api_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL');
    $stmt->execute([hash('sha256', $token)]);
}

function api_login_identity(string $username): string
{
    return hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
}

function api_recovery_identity(string $action, string $nik): string
{
    return hash('sha256', $action . '|' . trim($nik) . '|' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
}

function api_enforce_login_limit(string $identity): void
{
    $stmt = db()->prepare('SELECT 1 FROM exam_api_login_attempts
        WHERE identity_hash = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        LIMIT 5');
    $stmt->execute([$identity]);
    if (count($stmt->fetchAll()) >= 5) {
        header('Retry-After: 900');
        api_response(['success' => false, 'error' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.'], 429);
    }
}

function api_enforce_recovery_limit(string $identity): void
{
    $stmt = db()->prepare('SELECT 1 FROM exam_api_login_attempts
        WHERE identity_hash = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        LIMIT 5');
    $stmt->execute([$identity]);
    if (count($stmt->fetchAll()) >= 5) {
        header('Retry-After: 900');
        api_response(['success' => false, 'error' => 'Terlalu banyak percobaan. Coba lagi dalam 15 menit.'], 429);
    }
}

function api_record_login_failure(string $identity): void
{
    $stmt = db()->prepare('INSERT INTO exam_api_login_attempts (identity_hash) VALUES (?)');
    $stmt->execute([$identity]);
}

function api_clear_login_failures(string $identity): void
{
    $stmt = db()->prepare('DELETE FROM exam_api_login_attempts WHERE identity_hash = ?');
    $stmt->execute([$identity]);
}

api_allow_cors();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input = api_input();
$action = trim((string)($_GET['action'] ?? $input['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (getenv('CBT_AUTO_MIGRATE') === '1') {
    ensure_exam_tables();
}

try {
    if ($action === 'exams' && $method === 'GET') {
        $rows = db()->query("SELECT et.id, et.name, et.exam_kind, et.description,
            et.duration_minutes, et.question_count, et.is_default,
            COUNT(eq.id) AS ready_questions
            FROM exam_types et
            LEFT JOIN exam_questions eq
                ON eq.exam_type_id = et.id AND eq.is_active = 1
            WHERE et.is_active = 1
            GROUP BY et.id
            ORDER BY et.is_default DESC, FIELD(et.exam_kind, 'main', 'tryout'), et.name ASC")->fetchAll();
        $exams = array_map(function (array $row): array {
            $exam = api_type_payload($row);
            $exam['ready_questions'] = (int)$row['ready_questions'];
            $exam['is_ready'] = (int)$row['ready_questions'] >= (int)$row['question_count'];
            return $exam;
        }, $rows);
        api_response(['success' => true, 'data' => $exams]);
    }

    if ($action === 'check_username' && $method === 'POST') {
        api_require_secure_transport();
        $nik = trim((string)($input['nik'] ?? ''));
        if (!preg_match('/^\d{16}$/', $nik)) {
            api_response(['success' => false, 'error' => 'NIK harus terdiri dari 16 digit angka.'], 422);
        }

        $identity = api_recovery_identity('check_username', $nik);
        api_enforce_recovery_limit($identity);
        $stmt = db()->prepare("SELECT username FROM exam_users
            WHERE nik = ? AND status = 'active'
            LIMIT 1");
        $stmt->execute([$nik]);
        $username = $stmt->fetchColumn();
        if ($username === false) {
            api_record_login_failure($identity);
            api_response(['success' => false, 'error' => 'Akun ujian dengan NIK tersebut tidak ditemukan.'], 404);
        }

        api_clear_login_failures($identity);
        api_response(['success' => true, 'data' => ['username' => $username]]);
    }

    if ($action === 'forgot_password' && $method === 'POST') {
        api_require_secure_transport();
        $nik = trim((string)($input['nik'] ?? ''));
        $username = trim((string)($input['username'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $confirm = (string)($input['confirm_password'] ?? '');
        if (!preg_match('/^\d{16}$/', $nik)) {
            api_response(['success' => false, 'error' => 'NIK harus terdiri dari 16 digit angka.'], 422);
        }
        if ($username === '') {
            api_response(['success' => false, 'error' => 'Username wajib diisi.'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            api_response(['success' => false, 'error' => 'Email tidak valid.'], 422);
        }
        if (strlen($password) < 6) {
            api_response(['success' => false, 'error' => 'Password baru minimal 6 karakter.'], 422);
        }
        if ($password !== $confirm) {
            api_response(['success' => false, 'error' => 'Konfirmasi password tidak cocok.'], 422);
        }

        $identity = api_recovery_identity('forgot_password', $nik);
        api_enforce_recovery_limit($identity);
        $stmt = db()->prepare("SELECT id FROM exam_users
            WHERE nik = ? AND LOWER(username) = LOWER(?) AND LOWER(email) = LOWER(?)
                AND status = 'active'
            LIMIT 1");
        $stmt->execute([$nik, $username, $email]);
        $user_id = $stmt->fetchColumn();
        if ($user_id === false) {
            api_record_login_failure($identity);
            api_response(['success' => false, 'error' => 'Data akun tidak sesuai.'], 404);
        }

        db()->beginTransaction();
        try {
            $update = db()->prepare('UPDATE exam_users SET password_hash = ? WHERE id = ?');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $user_id]);
            $revoke = db()->prepare('UPDATE exam_api_tokens SET revoked_at = NOW()
                WHERE exam_user_id = ? AND revoked_at IS NULL');
            $revoke->execute([$user_id]);
            db()->commit();
        } catch (Throwable $error) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $error;
        }

        api_clear_login_failures($identity);
        exam_log((string)$user_id, 'api_password_reset');
        api_response(['success' => true, 'message' => 'Password berhasil diperbarui. Silakan login kembali.']);
    }

    if (in_array($action, ['login', 'token_login'], true) && $method === 'POST') {
        api_require_secure_transport();
        $username = trim((string)($input['username'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $identity = api_login_identity($username);
        api_enforce_login_limit($identity);
        $stmt = db()->prepare("SELECT eu.id, eu.participant_id, eu.nama, eu.email, eu.nik,
            eu.username, eu.password_hash, p.nama_sekolah, p.status_bayar, p.foto_profil
            FROM exam_users eu
            JOIN participants p ON p.id = eu.participant_id
            WHERE eu.username = ? AND eu.status = 'active'
            LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        $dummy_hash = '$2y$12$Uy9b1ymLP/.Ph9GCRhX1veZ3/ZjDz4Mdpd639MwX1TOH415iQV/8e';
        if (!password_verify($password, $user['password_hash'] ?? $dummy_hash) || !$user) {
            api_record_login_failure($identity);
            api_response(['success' => false, 'error' => 'Username atau password salah.'], 401);
        }
        if (($user['status_bayar'] ?? '') !== 'lunas') {
            api_response(['success' => false, 'error' => 'Pembayaran peserta belum lunas.'], 403);
        }
        api_clear_login_failures($identity);
        $profile = [
            'id' => $user['id'],
            'nama' => $user['nama'],
            'username' => $user['username'],
            'email' => $user['email'],
            'nik' => $user['nik'],
            'participant_id' => $user['participant_id'],
            'sekolah' => $user['nama_sekolah'],
            'foto_profil' => $user['foto_profil'],
        ];
        if ($action === 'token_login') {
            $token = api_issue_token($user['id']);
            exam_log($user['id'], 'api_token_login');
            api_response(['success' => true, 'data' => ['user' => $profile] + $token]);
        }
        api_start_session();
        session_regenerate_id(true);
        $_SESSION['exam_user'] = $profile;
        session_write_close();
        exam_log($user['id'], 'api_login');
        api_response(['success' => true, 'data' => ['user' => $profile]]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $logout_token = api_bearer_token();
        if ($logout_token !== '') {
            api_require_secure_transport();
            api_revoke_token($logout_token);
        }
        api_start_session();
        $_SESSION = [];
        session_destroy();
        api_response(['success' => true, 'message' => 'Logout berhasil.']);
    }

    $bearer_token = api_bearer_token();
    if ($bearer_token !== '') {
        $user = api_user_from_token($bearer_token);
    } else {
        api_start_session();
        $user = current_exam_user();
        session_write_close();
    }
    if (!$user) {
        api_response(['success' => false, 'error' => 'Sesi peserta berakhir. Silakan login ulang.'], 401);
    }

    if ($action === 'me' && $method === 'GET') {
        $stmt = db()->prepare("SELECT ea.id, ea.exam_type_id, ea.status, ea.started_at, ea.ends_at,
            ea.submitted_at,
            CASE
                WHEN ea.status = 'in_progress' AND ea.ends_at IS NOT NULL AND ea.ends_at <= NOW()
                    THEN 'expired'
                ELSE ea.status
            END AS effective_status,
            et.name AS exam_name, et.exam_kind, et.duration_minutes, et.question_count,
            es.id AS survey_id
            FROM exam_attempts ea
            JOIN exam_types et ON et.id = ea.exam_type_id
            LEFT JOIN exam_surveys es ON es.exam_attempt_id = ea.id
            WHERE ea.exam_user_id = ? AND et.is_active = 1
            ORDER BY et.is_default DESC, ea.id DESC");
        $stmt->execute([$user['id']]);
        $attempts = array_map(function (array $attempt): array {
            return [
                'exam_type_id' => (int)$attempt['exam_type_id'],
                'exam_name' => $attempt['exam_name'],
                'kind' => $attempt['exam_kind'],
                'status' => $attempt['effective_status'],
                'started_at' => $attempt['started_at'],
                'ends_at' => $attempt['ends_at'],
                'submitted_at' => $attempt['submitted_at'],
                'duration_minutes' => (int)$attempt['duration_minutes'],
                'question_count' => (int)$attempt['question_count'],
                'survey_submitted' => !empty($attempt['survey_id']),
            ];
        }, $stmt->fetchAll());
        api_response(['success' => true, 'data' => [
            'user' => $user,
            'attempts' => $attempts,
            'server_time' => date('Y-m-d H:i:s'),
        ]]);
    }

    $exam_type_id = (int)($input['exam_type_id'] ?? $_GET['exam_type_id'] ?? 0);
    $exam_kind = strtolower(trim((string)($input['exam_kind'] ?? $input['exam_type'] ?? $_GET['exam_kind'] ?? $_GET['exam_type'] ?? '')));
    $exam_type_id = api_resolve_exam_type_id($exam_type_id, $exam_kind, $action);
    $type = api_exam_type($exam_type_id);
    if ($exam_kind !== '' && $type['exam_kind'] !== $exam_kind) {
        api_response(['success' => false, 'error' => 'exam_type_id tidak sesuai dengan exam_kind yang dipilih.'], 422);
    }

    if ($action === 'start' && $method === 'POST') {
        $questions = active_questions($exam_type_id, (int)$type['question_count']);
        if (count($questions) < (int)$type['question_count']) {
            api_response(['success' => false, 'error' => 'Soal aktif belum lengkap.'], 409);
        }
        $attempt = refresh_attempt_status(get_or_create_attempt($user['id'], $exam_type_id));
        if (in_array($attempt['status'], ['submitted', 'expired'], true)) {
            api_response(['success' => false, 'error' => 'Ujian ini sudah selesai.', 'finished' => true], 409);
        }
        if ($attempt['status'] === 'not_started') {
            $order = array_map(fn(array $q): int => (int)$q['question_no'], $questions);
            $duration = max(1, min(1440, (int)$type['duration_minutes']));
            $started_at = date('Y-m-d H:i:s');
            $ends_at = date('Y-m-d H:i:s', time() + ($duration * 60));
            $stmt = db()->prepare("UPDATE exam_attempts
                SET started_at = ?, ends_at = ?,
                    status = 'in_progress', question_order_json = ?
                WHERE id = ? AND status = 'not_started'");
            $stmt->execute([$started_at, $ends_at, json_encode($order), $attempt['id']]);
            if ($stmt->rowCount() === 1) {
                $attempt['status'] = 'in_progress';
                $attempt['started_at'] = $started_at;
                $attempt['ends_at'] = $ends_at;
                $attempt['question_order_json'] = json_encode($order);
                exam_log($user['id'], 'api_start', 'exam_type_id=' . $exam_type_id);
            } else {
                $attempt = get_or_create_attempt($user['id'], $exam_type_id);
            }
        }
        api_response(['success' => true, 'data' => [
            'exam' => api_type_payload($type),
            'status' => $attempt['status'],
            'started_at' => $attempt['started_at'],
            'ends_at' => $attempt['ends_at'],
            'server_time' => date('Y-m-d H:i:s'),
        ]]);
    }

    if ($action === 'exam' && $method === 'GET') {
        $attempt = refresh_attempt_status(get_or_create_attempt($user['id'], $exam_type_id));
        if ($attempt['status'] !== 'in_progress') {
            api_response([
                'success' => false,
                'error' => 'Ujian belum dimulai atau sudah selesai.',
                'status' => $attempt['status'],
                'finished' => in_array($attempt['status'], ['submitted', 'expired'], true),
            ], 409);
        }
        api_response(['success' => true, 'data' => [
            'exam' => api_type_payload($type),
            'questions' => active_questions($exam_type_id, (int)$type['question_count']),
            'answers' => json_decode($attempt['answers_json'] ?: '{}', true) ?: new stdClass(),
            'started_at' => $attempt['started_at'],
            'ends_at' => $attempt['ends_at'],
            'server_time' => date('Y-m-d H:i:s'),
        ]]);
    }

    if ($action === 'save' && $method === 'POST') {
        $attempt = refresh_attempt_status(get_or_create_attempt($user['id'], $exam_type_id));
        if ($attempt['status'] !== 'in_progress') {
            api_response(['success' => false, 'error' => 'Ujian tidak sedang berlangsung.', 'finished' => true], 409);
        }
        $answers = $input['answers'] ?? [];
        if (is_string($answers)) {
            $answers = json_decode($answers, true);
        }
        if (!is_array($answers)) {
            api_response(['success' => false, 'error' => 'Format answers harus berupa object JSON.'], 422);
        }
        $clean = [];
        foreach ($answers as $number => $answer) {
            $number = (int)$number;
            $answer = strtoupper((string)$answer);
            if ($number >= 1 && $number <= (int)$type['question_count'] && in_array($answer, ['A', 'B', 'C', 'D', 'E'], true)) {
                $clean[(string)$number] = $answer;
            }
        }
        $stmt = db()->prepare('UPDATE exam_attempts SET answers_json = ?, last_saved_at = NOW() WHERE id = ?');
        $stmt->execute([json_encode($clean), $attempt['id']]);
        api_response(['success' => true, 'data' => [
            'saved_at' => date('Y-m-d H:i:s'),
            'answered' => count($clean),
            'question_count' => (int)$type['question_count'],
        ]]);
    }

    if ($action === 'survey' && $method === 'GET') {
        $stmt = db()->prepare('SELECT ea.*,
            es.id AS survey_id, es.rating AS survey_rating, es.kesan AS survey_kesan,
            es.saran AS survey_saran, es.created_at AS survey_created_at
            FROM exam_attempts ea
            LEFT JOIN exam_surveys es ON es.exam_attempt_id = ea.id
            WHERE ea.exam_user_id = ? AND ea.exam_type_id = ?
            LIMIT 1');
        $stmt->execute([$user['id'], $exam_type_id]);
        $attempt = $stmt->fetch();
        if (!$attempt) {
            api_response(['success' => false, 'error' => 'Attempt ujian tidak ditemukan.'], 404);
        }
        $attempt = refresh_attempt_status($attempt);
        $survey_exists = !empty($attempt['survey_id']);
        api_response(['success' => true, 'data' => [
            'exam' => api_type_payload($type),
            'attempt_status' => $attempt['status'],
            'eligible' => in_array($attempt['status'], ['submitted', 'expired'], true),
            'submitted' => $survey_exists,
            'survey' => $survey_exists ? [
                'id' => (int)$attempt['survey_id'],
                'rating' => (int)$attempt['survey_rating'],
                'kesan' => $attempt['survey_kesan'],
                'saran' => $attempt['survey_saran'],
                'created_at' => $attempt['survey_created_at'],
            ] : null,
            'fields' => [
                'rating' => [
                    'required' => true,
                    'min' => 1,
                    'max' => 5,
                    'options' => [
                        ['value' => 5, 'label' => 'Sangat baik'],
                        ['value' => 4, 'label' => 'Baik'],
                        ['value' => 3, 'label' => 'Cukup'],
                        ['value' => 2, 'label' => 'Kurang'],
                        ['value' => 1, 'label' => 'Perlu banyak perbaikan'],
                    ],
                ],
                'kesan' => ['required' => true, 'max_length' => 2000],
                'saran' => ['required' => false, 'max_length' => 2000],
            ],
        ]]);
    }

    if ($action === 'survey' && $method === 'POST') {
        $stmt = db()->prepare('SELECT * FROM exam_attempts WHERE exam_user_id = ? AND exam_type_id = ? LIMIT 1');
        $stmt->execute([$user['id'], $exam_type_id]);
        $attempt = $stmt->fetch();
        if (!$attempt) {
            api_response(['success' => false, 'error' => 'Attempt ujian tidak ditemukan.'], 404);
        }
        $attempt = refresh_attempt_status($attempt);
        if (!in_array($attempt['status'], ['submitted', 'expired'], true)) {
            api_response(['success' => false, 'error' => 'Survey hanya dapat diisi setelah ujian selesai.'], 409);
        }

        $rating = (int)($input['rating'] ?? 0);
        $kesan = trim((string)($input['kesan'] ?? ''));
        $saran = trim((string)($input['saran'] ?? ''));
        if ($rating < 1 || $rating > 5) {
            api_response(['success' => false, 'error' => 'Rating harus bernilai 1 sampai 5.'], 422);
        }
        if ($kesan === '' || mb_strlen($kesan) > 2000) {
            api_response(['success' => false, 'error' => 'Kesan wajib diisi dan maksimal 2000 karakter.'], 422);
        }
        if (mb_strlen($saran) > 2000) {
            api_response(['success' => false, 'error' => 'Saran maksimal 2000 karakter.'], 422);
        }

        try {
            $insert = db()->prepare('INSERT INTO exam_surveys
                (exam_user_id, exam_type_id, exam_attempt_id, nama, rating, kesan, saran, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $insert->execute([
                $user['id'],
                $exam_type_id,
                $attempt['id'],
                $user['nama'],
                $rating,
                $kesan,
                $saran ?: null,
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                api_response(['success' => false, 'error' => 'Survey untuk ujian ini sudah pernah dikirim.', 'submitted' => true], 409);
            }
            throw $error;
        }
        exam_log($user['id'], 'api_survey_submit', 'exam_type_id=' . $exam_type_id);
        api_response(['success' => true, 'message' => 'Terima kasih, survey berhasil disimpan.', 'data' => [
            'survey_id' => (int)db()->lastInsertId(),
            'exam_type_id' => $exam_type_id,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]], 201);
    }

    if ($action === 'submit' && $method === 'POST') {
        $attempt = refresh_attempt_status(get_or_create_attempt($user['id'], $exam_type_id));
        if ($attempt['status'] === 'in_progress') {
            $stmt = db()->prepare("UPDATE exam_attempts SET status = 'submitted', submitted_at = NOW(), last_saved_at = NOW() WHERE id = ?");
            $stmt->execute([$attempt['id']]);
            exam_log($user['id'], 'api_submit', 'exam_type_id=' . $exam_type_id);
        }
        if (!in_array($attempt['status'], ['in_progress', 'submitted'], true)) {
            api_response(['success' => false, 'error' => 'Ujian belum dimulai atau waktu sudah habis.'], 409);
        }
        api_response(['success' => true, 'message' => 'Ujian berhasil dikumpulkan.', 'data' => [
            'exam_type_id' => $exam_type_id,
            'submitted_at' => date('Y-m-d H:i:s'),
            'survey_required' => true,
            'survey_action' => 'survey',
        ]]);
    }

    api_response(['success' => false, 'error' => 'Endpoint atau method tidak ditemukan.'], 404);
} catch (Throwable $error) {
    error_log('CBT API: ' . $error->getMessage());
    api_response(['success' => false, 'error' => 'Terjadi kesalahan pada server.'], 500);
}
