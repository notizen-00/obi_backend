<?php
session_start();
require_once __DIR__ . '/../db/exam.php';

ensure_exam_tables();

function promote_default_exam_type(int $excluded_id = 0): bool
{
    $stmt = db()->prepare("SELECT id FROM exam_types
        WHERE is_active = 1 AND exam_kind = 'main' AND id <> ?
        ORDER BY id ASC LIMIT 1");
    $stmt->execute([$excluded_id]);
    $replacement_id = (int)$stmt->fetchColumn();
    if ($replacement_id > 0) {
        $update = db()->prepare('UPDATE exam_types SET is_default = 1 WHERE id = ?');
        $update->execute([$replacement_id]);
        return true;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = db()->prepare('SELECT * FROM operators WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $stmt->execute([$username]);
        $op = $stmt->fetch();
        if ($op && password_verify($password, $op['password_hash'])) {
            $_SESSION['operator'] = ['username' => $op['username'], 'nama' => $op['nama'] ?? $op['username']];
            exam_json(['success' => true]);
        }
        exam_json(['success' => false, 'error' => 'Login panitia gagal.']);
    }

    if ($action === 'logout') {
        unset($_SESSION['operator']);
        exam_json(['success' => true]);
    }

    if (empty($_SESSION['operator'])) {
        exam_json(['success' => false, 'error' => 'Sesi panitia berakhir.']);
    }

    if ($action === 'save_exam_type') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $kind = $_POST['exam_kind'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $duration = (int)($_POST['duration_minutes'] ?? 0);
        $question_count = (int)($_POST['question_count'] ?? 0);
        $active = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
        $default = ($_POST['is_default'] ?? '0') === '1' ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 120 || !in_array($kind, ['main', 'tryout'], true) || $duration < 1 || $duration > 1440 || $question_count < 1 || $question_count > 100) {
            exam_json(['success' => false, 'error' => 'Pilih kategori, isi nama, durasi 1–1440 menit, dan jumlah soal 1–100.']);
        }
        if (!$active) $default = 0;
        if ($default) {
            $kind = 'main';
            $active = 1;
        }

        try {
            db()->beginTransaction();
            $was_default = false;
            if ($id > 0) {
                $current = db()->prepare('SELECT is_default FROM exam_types WHERE id = ? LIMIT 1');
                $current->execute([$id]);
                $current_row = $current->fetch();
                if (!$current_row) {
                    throw new RuntimeException('Jenis ujian tidak ditemukan.');
                }
                $was_default = (int)$current_row['is_default'] === 1;
            }
            if ($default) {
                db()->exec('UPDATE exam_types SET is_default = 0');
                $active = 1;
            }
            if ($id > 0) {
                $stmt = db()->prepare('UPDATE exam_types SET name = ?, exam_kind = ?, description = ?, duration_minutes = ?, question_count = ?, is_active = ?, is_default = ? WHERE id = ?');
                $stmt->execute([$name, $kind, $description ?: null, $duration, $question_count, $active, $default, $id]);
                if ($was_default && !$default) promote_default_exam_type($id);
            } else {
                $stmt = db()->prepare('INSERT INTO exam_types (name, exam_kind, description, duration_minutes, question_count, is_active, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $kind, $description ?: null, $duration, $question_count, $active, $default]);
                $id = (int)db()->lastInsertId();
            }
            if ($active && $kind === 'main' && !$default) {
                $has_default = (int)db()->query('SELECT COUNT(*) FROM exam_types WHERE is_default = 1 AND is_active = 1')->fetchColumn();
                if ($has_default === 0) {
                    $make_default = db()->prepare('UPDATE exam_types SET is_default = 1 WHERE id = ?');
                    $make_default->execute([$id]);
                }
            }
            db()->commit();
            exam_json(['success' => true, 'message' => 'Jenis ujian berhasil disimpan.', 'id' => $id]);
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            $message = $e instanceof PDOException && $e->getCode() === '23000'
                ? 'Nama jenis ujian sudah digunakan.'
                : $e->getMessage();
            exam_json(['success' => false, 'error' => $message]);
        }
    }

    if ($action === 'toggle_exam_type_status') {
        $id = (int)($_POST['id'] ?? 0);
        $active = ($_POST['is_active'] ?? '0') === '1' ? 1 : 0;
        $stmt = db()->prepare('SELECT name, exam_kind, is_default FROM exam_types WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $type = $stmt->fetch();
        if (!$type) {
            exam_json(['success' => false, 'error' => 'Jenis ujian tidak ditemukan.']);
        }
        try {
            db()->beginTransaction();
            $update = db()->prepare('UPDATE exam_types SET is_active = ?, is_default = CASE WHEN ? = 0 THEN 0 ELSE is_default END WHERE id = ?');
            $update->execute([$active, $active, $id]);
            if (!$active && (int)$type['is_default'] === 1) {
                promote_default_exam_type($id);
            } elseif ($active && $type['exam_kind'] === 'main') {
                $has_default = (int)db()->query('SELECT COUNT(*) FROM exam_types WHERE is_default = 1 AND is_active = 1')->fetchColumn();
                if ($has_default === 0) {
                    $make_default = db()->prepare('UPDATE exam_types SET is_default = 1 WHERE id = ?');
                    $make_default->execute([$id]);
                }
            }
            db()->commit();
            exam_json([
                'success' => true,
                'message' => $type['name'] . ($active ? ' berhasil diaktifkan.' : ' berhasil dinonaktifkan.'),
            ]);
        } catch (Throwable $error) {
            if (db()->inTransaction()) db()->rollBack();
            $message = $error instanceof RuntimeException ? $error->getMessage() : 'Status ujian belum dapat diperbarui.';
            exam_json(['success' => false, 'error' => $message]);
        }
    }

    if ($action === 'delete_exam_type') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT is_default FROM exam_types WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $type = $stmt->fetch();
        if (!$type) {
            exam_json(['success' => false, 'error' => 'Jenis ujian tidak ditemukan.']);
        }
        if ((int)$type['is_default'] === 1) {
            exam_json(['success' => false, 'error' => 'Jenis ujian default tidak dapat dihapus.']);
        }
        $stmt = db()->prepare('SELECT COUNT(*) FROM exam_questions WHERE exam_type_id = ?');
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            exam_json(['success' => false, 'error' => 'Jenis ujian masih memiliki soal. Hapus atau pindahkan soalnya terlebih dahulu.']);
        }
        $stmt = db()->prepare('DELETE FROM exam_types WHERE id = ?');
        $stmt->execute([$id]);
        exam_json(['success' => true, 'message' => 'Jenis ujian berhasil dihapus.']);
    }

    if ($action === 'save_question') {
        $exam_type_id = (int)($_POST['exam_type_id'] ?? 0);
        $no = (int)($_POST['question_no'] ?? 0);
        $text = trim($_POST['question_text'] ?? '');
        $a = trim($_POST['option_a'] ?? '');
        $b = trim($_POST['option_b'] ?? '');
        $c = trim($_POST['option_c'] ?? '');
        $d = trim($_POST['option_d'] ?? '');
        $e = trim($_POST['option_e'] ?? '');
        $correct = trim($_POST['correct_option'] ?? '');
        $active = isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0;

        $stmt = db()->prepare('SELECT question_count FROM exam_types WHERE id = ? LIMIT 1');
        $stmt->execute([$exam_type_id]);
        $max_questions = (int)$stmt->fetchColumn();
        if ($max_questions < 1 || $no < 1 || $no > $max_questions || $text === '' || $a === '' || $b === '' || $c === '' || $d === '' || $e === '' || !in_array($correct, ['A','B','C','D','E'], true)) {
            exam_json(['success' => false, 'error' => 'Lengkapi nomor, soal, opsi A-E, dan kunci jawaban.']);
        }

        $stmt = db()->prepare("INSERT INTO exam_questions (exam_type_id, question_no, question_text, option_a, option_b, option_c, option_d, option_e, correct_option, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE question_text = VALUES(question_text), option_a = VALUES(option_a), option_b = VALUES(option_b), option_c = VALUES(option_c), option_d = VALUES(option_d), option_e = VALUES(option_e), correct_option = VALUES(correct_option), is_active = VALUES(is_active)");
        $stmt->execute([$exam_type_id, $no, $text, $a, $b, $c, $d, $e, $correct, $active]);
        exam_json(['success' => true, 'message' => 'Soal nomor ' . $no . ' tersimpan.']);
    }

    exam_json(['success' => false, 'error' => 'Action tidak valid.']);
}

$is_logged_in = !empty($_SESSION['operator']);
$requested_admin_view = (string)($_GET['view'] ?? 'bank');
$admin_view = in_array($requested_admin_view, ['bank', 'results', 'surveys'], true) ? $requested_admin_view : 'bank';
$exam_types = [];
$selected_type = null;
$questions = [];
$results = [];
$result_summary = ['total' => 0, 'in_progress' => 0, 'submitted' => 0, 'expired' => 0];
$result_page = max(1, (int)($_GET['page'] ?? 1));
$result_per_page = 25;
$result_total_pages = 1;
$result_exam_filter = max(0, (int)($_GET['exam'] ?? 0));
$result_status_filter = (string)($_GET['status'] ?? '');
$result_search = trim((string)($_GET['q'] ?? ''));
$surveys = [];
$survey_page = max(1, (int)($_GET['page'] ?? 1));
$survey_per_page = 20;
$survey_total_pages = 1;
$survey_exam_filter = max(0, (int)($_GET['exam'] ?? 0));
$survey_rating_filter = max(0, min(5, (int)($_GET['rating'] ?? 0)));
$survey_search = trim((string)($_GET['q'] ?? ''));
$survey_summary = ['total' => 0, 'average_rating' => 0, 'five_star' => 0];
$survey_rating_distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
$survey_completed_attempts = 0;
if (!in_array($result_status_filter, ['', 'not_started', 'in_progress', 'submitted', 'expired'], true)) {
    $result_status_filter = '';
}
if ($is_logged_in) {
    $exam_types = db()->query("SELECT et.*, COUNT(eq.id) AS saved_questions,
        COALESCE(SUM(CASE WHEN eq.is_active = 1 THEN 1 ELSE 0 END), 0) AS active_questions
        FROM exam_types et
        LEFT JOIN exam_questions eq ON eq.exam_type_id = et.id
        GROUP BY et.id
        ORDER BY et.is_default DESC, FIELD(et.exam_kind, 'main', 'tryout'), et.name ASC")->fetchAll();
    $requested_type_id = (int)($_GET['type'] ?? 0);
    foreach ($exam_types as $type) {
        if ((int)$type['id'] === $requested_type_id) {
            $selected_type = $type;
            break;
        }
    }
    $selected_type = $selected_type ?: ($exam_types[0] ?? null);
    if ($selected_type) {
        $stmt = db()->prepare('SELECT * FROM exam_questions WHERE exam_type_id = ? ORDER BY question_no ASC');
        $stmt->execute([$selected_type['id']]);
        $questions = $stmt->fetchAll();
    }

    if ($admin_view === 'results') {
        $where = [];
        $params = [];
        if ($result_exam_filter > 0) {
            $where[] = 'ea.exam_type_id = ?';
            $params[] = $result_exam_filter;
        }
        if ($result_status_filter !== '') {
            $where[] = 'ea.status = ?';
            $params[] = $result_status_filter;
        }
        if ($result_search !== '') {
            $where[] = '(eu.nama LIKE ? OR eu.username LIKE ? OR eu.email LIKE ? OR p.nama_sekolah LIKE ?)';
            $search = '%' . $result_search . '%';
            array_push($params, $search, $search, $search, $search);
        }
        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $summary_stmt = db()->prepare("SELECT COUNT(*) AS total,
            COALESCE(SUM(ea.status = 'in_progress'), 0) AS in_progress,
            COALESCE(SUM(ea.status = 'submitted'), 0) AS submitted,
            COALESCE(SUM(ea.status = 'expired'), 0) AS expired
            FROM exam_attempts ea
            JOIN exam_users eu ON eu.id = ea.exam_user_id
            JOIN participants p ON p.id = eu.participant_id
            {$where_sql}");
        $summary_stmt->execute($params);
        $result_summary = $summary_stmt->fetch() ?: $result_summary;
        $result_total_pages = max(1, (int)ceil((int)$result_summary['total'] / $result_per_page));
        $result_page = min($result_page, $result_total_pages);
        $offset = ($result_page - 1) * $result_per_page;

        $result_stmt = db()->prepare("SELECT ea.*, eu.nama, eu.username, eu.email, eu.nik,
            p.nama_sekolah, et.name AS exam_name, et.exam_kind, et.question_count
            FROM exam_attempts ea
            JOIN exam_users eu ON eu.id = ea.exam_user_id
            JOIN participants p ON p.id = eu.participant_id
            JOIN exam_types et ON et.id = ea.exam_type_id
            {$where_sql}
            ORDER BY COALESCE(ea.submitted_at, ea.started_at, ea.created_at) DESC, ea.id DESC
            LIMIT {$result_per_page} OFFSET {$offset}");
        $result_stmt->execute($params);
        $results = $result_stmt->fetchAll();

        $answer_keys = [];
        $key_rows = db()->query("SELECT exam_type_id, question_no, correct_option
            FROM exam_questions WHERE correct_option IS NOT NULL AND is_active = 1")->fetchAll();
        foreach ($key_rows as $key_row) {
            $answer_keys[(int)$key_row['exam_type_id']][(string)(int)$key_row['question_no']] = $key_row['correct_option'];
        }
        foreach ($results as &$result) {
            $answers = json_decode($result['answers_json'] ?: '{}', true);
            $answers = is_array($answers) ? $answers : [];
            $keys = $answer_keys[(int)$result['exam_type_id']] ?? [];
            $answered = 0;
            $correct = 0;
            foreach ($answers as $number => $answer) {
                if (!in_array($answer, ['A', 'B', 'C', 'D', 'E'], true)) {
                    continue;
                }
                $answered++;
                if (isset($keys[(string)(int)$number]) && $keys[(string)(int)$number] === $answer) {
                    $correct++;
                }
            }
            $total_questions = max(1, (int)$result['question_count']);
            $result['answered_count'] = $answered;
            $result['correct_count'] = $correct;
            $result['incorrect_count'] = max(0, $answered - $correct);
            $result['blank_count'] = max(0, $total_questions - $answered);
            $result['score'] = round(($correct / $total_questions) * 100, 2);
        }
        unset($result);
    }

    if ($admin_view === 'surveys') {
        $where = [];
        $params = [];
        if ($survey_exam_filter > 0) {
            $where[] = 'es.exam_type_id = ?';
            $params[] = $survey_exam_filter;
        }
        if ($survey_rating_filter > 0) {
            $where[] = 'es.rating = ?';
            $params[] = $survey_rating_filter;
        }
        if ($survey_search !== '') {
            $where[] = '(COALESCE(eu.nama, es.nama, \'\') LIKE ? OR COALESCE(eu.username, \'\') LIKE ? OR COALESCE(p.nama_sekolah, \'\') LIKE ? OR es.kesan LIKE ? OR COALESCE(es.saran, \'\') LIKE ?)';
            $search = '%' . $survey_search . '%';
            array_push($params, $search, $search, $search, $search, $search);
        }
        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $summary_stmt = db()->prepare("SELECT COUNT(*) AS total,
            COALESCE(AVG(es.rating), 0) AS average_rating,
            COALESCE(SUM(es.rating = 5), 0) AS five_star
            FROM exam_surveys es
            LEFT JOIN exam_users eu ON eu.id = es.exam_user_id
            LEFT JOIN participants p ON p.id = eu.participant_id
            {$where_sql}");
        $summary_stmt->execute($params);
        $survey_summary = $summary_stmt->fetch() ?: $survey_summary;
        $survey_total_pages = max(1, (int)ceil((int)$survey_summary['total'] / $survey_per_page));
        $survey_page = min($survey_page, $survey_total_pages);
        $offset = ($survey_page - 1) * $survey_per_page;

        $distribution_stmt = db()->prepare("SELECT es.rating, COUNT(*) AS total
            FROM exam_surveys es
            LEFT JOIN exam_users eu ON eu.id = es.exam_user_id
            LEFT JOIN participants p ON p.id = eu.participant_id
            {$where_sql}
            GROUP BY es.rating");
        $distribution_stmt->execute($params);
        foreach ($distribution_stmt->fetchAll() as $distribution) {
            $survey_rating_distribution[(int)$distribution['rating']] = (int)$distribution['total'];
        }

        $completed_sql = "SELECT COUNT(*) FROM exam_attempts WHERE status IN ('submitted', 'expired')";
        $completed_params = [];
        if ($survey_exam_filter > 0) {
            $completed_sql .= ' AND exam_type_id = ?';
            $completed_params[] = $survey_exam_filter;
        }
        $completed_stmt = db()->prepare($completed_sql);
        $completed_stmt->execute($completed_params);
        $survey_completed_attempts = (int)$completed_stmt->fetchColumn();

        $survey_stmt = db()->prepare("SELECT es.*, COALESCE(eu.nama, es.nama, 'Peserta') AS participant_name,
            eu.username, eu.email, p.nama_sekolah, et.name AS exam_name, et.exam_kind,
            ea.status AS attempt_status, ea.submitted_at
            FROM exam_surveys es
            LEFT JOIN exam_users eu ON eu.id = es.exam_user_id
            LEFT JOIN participants p ON p.id = eu.participant_id
            LEFT JOIN exam_types et ON et.id = es.exam_type_id
            LEFT JOIN exam_attempts ea ON ea.id = es.exam_attempt_id
            {$where_sql}
            ORDER BY es.created_at DESC, es.id DESC
            LIMIT {$survey_per_page} OFFSET {$offset}");
        $survey_stmt->execute($params);
        $surveys = $survey_stmt->fetchAll();
    }
}
$map = [];
foreach ($questions as $q) {
    $map[(int)$q['question_no']] = $q;
}
$active_count = count(array_filter($questions, fn($q) => (int)$q['is_active'] === 1));
$question_count = (int)($selected_type['question_count'] ?? 0);
$main_types = array_values(array_filter($exam_types, fn($type) => $type['exam_kind'] === 'main'));
$tryout_types = array_values(array_filter($exam_types, fn($type) => $type['exam_kind'] === 'tryout'));
$total_saved_questions = array_sum(array_map(fn($type) => (int)$type['saved_questions'], $exam_types));
$result_status_labels = [
    'not_started' => 'Belum dimulai',
    'in_progress' => 'Berlangsung',
    'submitted' => 'Dikumpulkan',
    'expired' => 'Waktu habis',
];
$result_status_classes = [
    'not_started' => 'bg-gray-100 text-gray-700',
    'in_progress' => 'bg-blue-100 text-blue-700',
    'submitted' => 'bg-green-100 text-green-700',
    'expired' => 'bg-red-100 text-red-700',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Ujian - OBI EXAM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>tailwind.config={theme:{extend:{colors:{primary:'#133D56',secondary:'#025C26'}}}}</script>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
<?php if (!$is_logged_in): ?>
<main class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-primary to-secondary">
    <section class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-7">
        <h1 class="text-2xl font-black text-primary text-center mb-1">Backend Soal OBI EXAM</h1>
        <p class="text-sm text-gray-500 text-center mb-5">Login menggunakan akun operator panitia.</p>
        <div id="alert"></div>
        <form id="loginForm" class="space-y-4">
            <input id="username" class="w-full border rounded-xl px-4 py-3" placeholder="Username operator" required>
            <input id="password" type="password" class="w-full border rounded-xl px-4 py-3" placeholder="Password" required>
            <button class="w-full bg-primary text-white py-3 rounded-xl font-bold">Login</button>
        </form>
    </section>
</main>
<script>
loginForm.onsubmit=async e=>{e.preventDefault();const fd=new FormData();fd.append('action','login');fd.append('username',username.value);fd.append('password',password.value);const r=await fetch('admin.php',{method:'POST',body:fd});const j=await r.json();j.success?location.reload():alert.innerHTML=`<div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 rounded mb-4">${j.error}</div>`}
</script>
<?php else: ?>
<header class="bg-gradient-to-r from-primary to-secondary text-white shadow-lg">
    <div class="max-w-7xl mx-auto px-4 py-5 flex justify-between items-center gap-4">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl bg-white/15 flex items-center justify-center"><i class="fa-solid fa-graduation-cap text-xl"></i></div>
            <div><p class="text-xs uppercase tracking-[.2em] text-white/70 font-bold">OBI Exam</p><h1 class="text-xl md:text-2xl font-black"><?= $admin_view === 'results' ? 'Hasil Ujian Siswa' : ($admin_view === 'surveys' ? 'Hasil Survey Peserta' : 'Manajemen Ujian & Bank Soal') ?></h1></div>
        </div>
        <button onclick="logout()" class="bg-white/10 hover:bg-red-600 border border-white/20 px-4 py-2 rounded-xl font-bold text-sm"><i class="fa-solid fa-right-from-bracket mr-1"></i> Logout</button>
    </div>
</header>
<nav class="bg-white border-b shadow-sm sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 flex gap-1 overflow-x-auto">
        <a href="?view=bank" class="inline-flex items-center gap-2 px-4 py-3.5 border-b-2 whitespace-nowrap font-bold text-sm <?= $admin_view === 'bank' ? 'text-secondary border-secondary bg-green-50/60' : 'text-gray-500 border-transparent hover:text-primary' ?>">
            <i class="fa-solid fa-layer-group"></i>
            <span>Bank Soal & Jenis Ujian</span>
        </a>
        <a href="?view=results" class="inline-flex items-center gap-2 px-4 py-3.5 border-b-2 whitespace-nowrap font-bold text-sm <?= $admin_view === 'results' ? 'text-blue-700 border-blue-600 bg-blue-50/60' : 'text-gray-500 border-transparent hover:text-primary' ?>">
            <i class="fa-solid fa-chart-column"></i>
            <span>Hasil Ujian Siswa</span>
        </a>
        <a href="?view=surveys" class="inline-flex items-center gap-2 px-4 py-3.5 border-b-2 whitespace-nowrap font-bold text-sm <?= $admin_view === 'surveys' ? 'text-violet-700 border-violet-600 bg-violet-50/60' : 'text-gray-500 border-transparent hover:text-primary' ?>">
            <i class="fa-solid fa-comments"></i>
            <span>Hasil Survey</span>
        </a>
    </div>
</nav>
<main class="max-w-7xl mx-auto p-4 space-y-4">
<?php if ($admin_view === 'bank'): ?>
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl shadow-sm border p-4"><p class="text-xs font-bold uppercase text-gray-400">Ujian utama</p><p class="text-3xl font-black text-primary mt-1"><?= count($main_types) ?></p></div>
        <div class="bg-white rounded-2xl shadow-sm border p-4"><p class="text-xs font-bold uppercase text-gray-400">Tryout</p><p class="text-3xl font-black text-blue-600 mt-1"><?= count($tryout_types) ?></p></div>
        <div class="bg-white rounded-2xl shadow-sm border p-4"><p class="text-xs font-bold uppercase text-gray-400">Total bank soal</p><p class="text-3xl font-black text-secondary mt-1"><?= $total_saved_questions ?></p></div>
        <div class="bg-white rounded-2xl shadow-sm border p-4"><p class="text-xs font-bold uppercase text-gray-400">Sedang dikelola</p><p class="font-black text-primary mt-2 truncate"><?= htmlspecialchars($selected_type['name']) ?></p><p class="text-xs text-gray-500"><?= $active_count ?>/<?= $question_count ?> soal aktif</p></div>
    </section>
    <section class="bg-white rounded-2xl shadow p-5">
        <div class="flex flex-col lg:flex-row lg:items-start gap-5">
            <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <div>
                        <h2 class="text-xl font-black text-primary">Daftar Ujian</h2>
                        <p class="text-sm text-gray-500">Soal 1–50 yang sekarang tetap menjadi Ujian Utama. Tryout menggunakan bank soal terpisah.</p>
                    </div>
                    <button type="button" onclick="newType('tryout')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-xl font-bold text-sm whitespace-nowrap"><i class="fa-solid fa-plus mr-1"></i> Buat Tryout</button>
                </div>

                <?php foreach ([['title' => 'Ujian Utama', 'icon' => 'fa-trophy', 'color' => 'text-amber-600', 'items' => $main_types], ['title' => 'Tryout', 'icon' => 'fa-flask', 'color' => 'text-blue-600', 'items' => $tryout_types]] as $group): ?>
                    <div class="mt-5 first:mt-0">
                        <div class="flex items-center justify-between mb-2">
                            <h3 class="font-black text-gray-700"><i class="fa-solid <?= $group['icon'] ?> <?= $group['color'] ?> mr-2"></i><?= $group['title'] ?></h3>
                            <span class="text-xs bg-gray-100 text-gray-500 px-2 py-1 rounded-full font-bold"><?= count($group['items']) ?> ujian</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php foreach ($group['items'] as $type): ?>
                        <article class="border rounded-2xl p-4 transition <?= (int)$type['id'] === (int)$selected_type['id'] ? 'border-secondary ring-2 ring-green-100 bg-green-50/30' : 'border-gray-200 hover:border-gray-300' ?>">
                            <div class="flex justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-black text-primary truncate"><?= htmlspecialchars($type['name']) ?></h3>
                                        <?php if ((int)$type['is_default'] === 1): ?><span class="text-[10px] bg-yellow-100 text-yellow-800 px-2 py-1 rounded-full font-bold">AKTIF UNTUK PESERTA</span><?php endif; ?>
                                        <?php if ((int)$type['is_active'] === 1): ?><span class="text-[10px] bg-green-100 text-green-700 px-2 py-1 rounded-full font-bold">AKTIF</span><?php else: ?><span class="text-[10px] bg-gray-200 text-gray-600 px-2 py-1 rounded-full font-bold">NONAKTIF</span><?php endif; ?>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-1"><?= (int)$type['duration_minutes'] ?> menit &bull; target <?= (int)$type['question_count'] ?> soal</p>
                                    <?php if (!empty($type['description'])): ?><p class="text-sm text-gray-600 mt-2 line-clamp-2"><?= htmlspecialchars($type['description']) ?></p><?php endif; ?>
                                </div>
                                <div class="flex gap-1 shrink-0">
                                    <button type="button" onclick="toggleType(<?= (int)$type['id'] ?>, <?= (int)$type['is_active'] === 1 ? 'false' : 'true' ?>)" class="w-8 h-8 rounded-lg <?= (int)$type['is_active'] === 1 ? 'bg-orange-50 text-orange-700' : 'bg-green-50 text-green-700' ?>" title="<?= (int)$type['is_active'] === 1 ? 'Nonaktifkan ujian' : 'Aktifkan ujian' ?>"><i class="fa-solid fa-power-off"></i></button>
                                    <button type="button" onclick="editType(<?= (int)$type['id'] ?>)" class="w-8 h-8 rounded-lg bg-blue-50 text-blue-700" title="Edit"><i class="fa-solid fa-pen"></i></button>
                                    <?php if ((int)$type['is_default'] !== 1): ?><button type="button" onclick="deleteType(<?= (int)$type['id'] ?>)" class="w-8 h-8 rounded-lg bg-red-50 text-red-700" title="Hapus"><i class="fa-solid fa-trash"></i></button><?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-4">
                                <div class="flex justify-between text-xs font-bold text-gray-500 mb-1"><span>Soal tersimpan</span><span><?= (int)$type['saved_questions'] ?>/<?= (int)$type['question_count'] ?></span></div>
                                <div class="h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-full bg-secondary rounded-full" style="width: <?= (int)round(min(100, ((int)$type['saved_questions'] / max(1, (int)$type['question_count'])) * 100)) ?>%"></div></div>
                            </div>
                            <a href="?type=<?= (int)$type['id'] ?>#bank-soal" class="inline-flex items-center mt-4 text-sm font-black text-secondary hover:underline">Buka bank soal <i class="fa-solid fa-arrow-right ml-2"></i></a>
                        </article>
                        <?php endforeach; ?>
                        <?php if (!$group['items']): ?>
                            <button type="button" onclick="newType('tryout')" class="md:col-span-2 border-2 border-dashed border-blue-200 bg-blue-50/40 rounded-2xl p-6 text-blue-700 hover:bg-blue-50">
                                <i class="fa-solid fa-circle-plus text-2xl mb-2"></i><span class="block font-black">Belum ada tryout</span><span class="text-sm">Klik untuk membuat tryout dan bank soal baru.</span>
                            </button>
                        <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <form id="typeForm" class="lg:w-96 border rounded-xl p-4 bg-gray-50 space-y-3">
                <div><h3 id="typeFormTitle" class="font-black text-primary">Tambah Ujian</h3><p class="text-xs text-gray-500 mt-1">Atur kategori, durasi, dan kapasitas bank soal.</p></div>
                <input id="type_id" type="hidden">
                <div><label class="text-sm font-bold block mb-1">Kategori</label><select id="type_kind" class="w-full border rounded-lg px-3 py-2 bg-white" required><option value="main">Ujian Utama</option><option value="tryout">Tryout</option></select></div>
                <div><label class="text-sm font-bold block mb-1">Nama ujian</label><input id="type_name" maxlength="120" class="w-full border rounded-lg px-3 py-2" placeholder="Contoh: Tryout Gelombang 1" required></div>
                <div><label class="text-sm font-bold block mb-1">Deskripsi</label><textarea id="type_description" rows="2" class="w-full border rounded-lg px-3 py-2" placeholder="Keterangan singkat (opsional)"></textarea></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="text-sm font-bold block mb-1">Durasi (menit)</label><input id="type_duration" type="number" min="1" max="1440" value="120" class="w-full border rounded-lg px-3 py-2" required></div>
                    <div><label class="text-sm font-bold block mb-1">Jumlah soal</label><input id="type_question_count" type="number" min="1" max="100" value="50" class="w-full border rounded-lg px-3 py-2" required></div>
                </div>
                <div class="flex flex-wrap gap-4 text-sm">
                    <label class="inline-flex items-center gap-2"><input id="type_active" type="checkbox" checked> Aktif</label>
                    <label class="inline-flex items-center gap-2"><input id="type_default" type="checkbox"> Tampilkan untuk peserta</label>
                </div>
                <p class="text-xs text-gray-500">Ujian nonaktif tidak muncul di frontend CBT. Ujian yang ditampilkan untuk peserta otomatis harus aktif.</p>
                <div id="typeAlert"></div>
                <button class="w-full bg-secondary hover:bg-primary text-white py-2.5 rounded-lg font-black">Simpan Ujian</button>
            </form>
        </div>
    </section>

    <div id="bank-soal" class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-4 scroll-mt-4">
    <aside class="bg-white rounded-2xl shadow p-4 h-fit lg:sticky lg:top-4">
        <label for="exam_type_select" class="font-black text-primary block mb-2">Bank Soal</label>
        <select id="exam_type_select" onchange="location.href='?type='+this.value" class="w-full border rounded-lg px-3 py-2 mb-4 text-sm">
            <optgroup label="Ujian Utama"><?php foreach ($main_types as $type): ?><option value="<?= (int)$type['id'] ?>" <?= (int)$type['id'] === (int)$selected_type['id'] ? 'selected' : '' ?>><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></optgroup>
            <?php if ($tryout_types): ?><optgroup label="Tryout"><?php foreach ($tryout_types as $type): ?><option value="<?= (int)$type['id'] ?>" <?= (int)$type['id'] === (int)$selected_type['id'] ? 'selected' : '' ?>><?= htmlspecialchars($type['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        </select>
        <p class="font-black text-primary mb-3">Nomor Soal</p>
        <div class="grid grid-cols-5 gap-2">
            <?php for ($i=1; $i<=$question_count; $i++): ?>
                <button onclick="loadQ(<?= $i ?>)" id="nav<?= $i ?>" class="qnav rounded-lg py-2 font-bold border <?= isset($map[$i]) ? 'bg-green-50 text-secondary border-green-200' : 'bg-gray-50 text-gray-500' ?>"><?= $i ?></button>
            <?php endfor; ?>
        </div>
    </aside>
    <section class="bg-white rounded-2xl shadow p-5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-5 pb-4 border-b">
            <div><p class="text-xs font-bold uppercase tracking-wider <?= $selected_type['exam_kind'] === 'tryout' ? 'text-blue-600' : 'text-amber-600' ?>"><?= $selected_type['exam_kind'] === 'tryout' ? 'Tryout' : 'Ujian Utama' ?></p><h2 class="text-xl font-black text-primary">Editor Soal — <?= htmlspecialchars($selected_type['name']) ?></h2></div>
            <span class="text-sm font-bold bg-gray-100 text-gray-600 rounded-full px-3 py-1.5"><?= $active_count ?>/<?= $question_count ?> aktif</span>
        </div>
        <div id="saveAlert"></div>
        <form id="questionForm" class="space-y-4">
            <div class="flex flex-col md:flex-row md:items-center gap-3">
                <label class="font-bold text-primary">Nomor</label>
                <input id="question_no" name="question_no" type="number" min="1" max="<?= $question_count ?>" class="w-28 border rounded-lg px-3 py-2 font-bold" required>
                <label class="inline-flex items-center gap-2 text-sm"><input id="is_active" type="checkbox" checked> Aktif</label>
            </div>
            <textarea id="question_text" rows="7" class="w-full border rounded-xl px-4 py-3" placeholder="Tulis soal di sini..." required></textarea>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <textarea id="option_a" rows="2" class="border rounded-xl px-4 py-3" placeholder="Pilihan A" required></textarea>
                <textarea id="option_b" rows="2" class="border rounded-xl px-4 py-3" placeholder="Pilihan B" required></textarea>
                <textarea id="option_c" rows="2" class="border rounded-xl px-4 py-3" placeholder="Pilihan C" required></textarea>
                <textarea id="option_d" rows="2" class="border rounded-xl px-4 py-3" placeholder="Pilihan D" required></textarea>
                <textarea id="option_e" rows="2" class="border rounded-xl px-4 py-3 md:col-span-2" placeholder="Pilihan E" required></textarea>
            </div>
            <div>
                <label class="font-bold text-primary block mb-2">Kunci Jawaban</label>
                <select id="correct_option" class="border rounded-xl px-4 py-3" required>
                    <option value="">Pilih</option><option>A</option><option>B</option><option>C</option><option>D</option><option>E</option>
                </select>
            </div>
            <button class="bg-secondary hover:bg-primary text-white px-6 py-3 rounded-xl font-black">Simpan Soal</button>
        </form>
    </section>
    </div>
<?php elseif ($admin_view === 'results'): ?>
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl shadow-sm border p-4">
            <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase text-gray-400">Total attempt</p><i class="fa-solid fa-users text-primary/30"></i></div>
            <p class="text-3xl font-black text-primary mt-1"><?= (int)$result_summary['total'] ?></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border p-4">
            <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase text-gray-400">Dikumpulkan</p><i class="fa-solid fa-circle-check text-green-500/50"></i></div>
            <p class="text-3xl font-black text-green-700 mt-1"><?= (int)$result_summary['submitted'] ?></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border p-4">
            <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase text-gray-400">Berlangsung</p><i class="fa-solid fa-clock text-blue-500/50"></i></div>
            <p class="text-3xl font-black text-blue-700 mt-1"><?= (int)$result_summary['in_progress'] ?></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border p-4">
            <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase text-gray-400">Waktu habis</p><i class="fa-solid fa-hourglass-end text-red-500/50"></i></div>
            <p class="text-3xl font-black text-red-700 mt-1"><?= (int)$result_summary['expired'] ?></p>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border overflow-hidden">
        <div class="p-5 border-b">
            <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl font-black text-primary">Hasil Ujian Siswa</h2>
                    <p class="text-sm text-gray-500 mt-1">Nilai dihitung dari jawaban peserta dan kunci jawaban pada bank soal.</p>
                </div>
                <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[220px_170px_260px_auto] gap-2 w-full lg:w-auto">
                    <input type="hidden" name="view" value="results">
                    <select name="exam" class="border rounded-xl px-3 py-2.5 bg-white text-sm">
                        <option value="0">Semua ujian</option>
                        <?php foreach ($exam_types as $type): ?>
                            <option value="<?= (int)$type['id'] ?>" <?= $result_exam_filter === (int)$type['id'] ? 'selected' : '' ?>><?= htmlspecialchars($type['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="status" class="border rounded-xl px-3 py-2.5 bg-white text-sm">
                        <option value="">Semua status</option>
                        <?php foreach ($result_status_labels as $value => $label): ?>
                            <option value="<?= $value ?>" <?= $result_status_filter === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-3.5 text-gray-400 text-sm"></i>
                        <input name="q" value="<?= htmlspecialchars($result_search) ?>" class="w-full border rounded-xl pl-9 pr-3 py-2.5 text-sm" placeholder="Nama, username, sekolah">
                    </div>
                    <button class="bg-primary hover:bg-secondary text-white rounded-xl px-4 py-2.5 font-bold text-sm"><i class="fa-solid fa-filter mr-1"></i> Terapkan</button>
                </form>
            </div>
        </div>

        <?php if (!$results): ?>
            <div class="text-center px-5 py-16">
                <div class="w-16 h-16 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center mx-auto text-2xl"><i class="fa-solid fa-chart-simple"></i></div>
                <h3 class="font-black text-primary mt-4">Belum ada hasil ujian</h3>
                <p class="text-sm text-gray-500 mt-1">Belum ada attempt yang cocok dengan filter saat ini.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1120px] text-sm">
                    <thead class="bg-gray-50 text-gray-500 uppercase text-[11px] tracking-wider">
                        <tr>
                            <th class="text-left px-5 py-3">Peserta</th>
                            <th class="text-left px-4 py-3">Ujian</th>
                            <th class="text-left px-4 py-3">Status</th>
                            <th class="text-center px-4 py-3">Jawaban</th>
                            <th class="text-center px-4 py-3">Benar</th>
                            <th class="text-center px-4 py-3">Salah</th>
                            <th class="text-center px-4 py-3">Kosong</th>
                            <th class="text-center px-4 py-3">Nilai</th>
                            <th class="text-left px-4 py-3">Waktu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                    <?php foreach ($results as $result): ?>
                        <?php
                        $status = $result['status'];
                        $started = $result['started_at'] ? date('d/m/Y H:i', strtotime($result['started_at'])) : '-';
                        $finished_at = $result['submitted_at'] ?: (($status === 'expired') ? $result['ends_at'] : null);
                        $finished = $finished_at ? date('d/m/Y H:i', strtotime($finished_at)) : null;
                        ?>
                        <tr class="hover:bg-gray-50/80 align-top">
                            <td class="px-5 py-4">
                                <p class="font-black text-primary"><?= htmlspecialchars($result['nama']) ?></p>
                                <p class="text-xs text-gray-500 mt-0.5">@<?= htmlspecialchars($result['username']) ?> · <?= htmlspecialchars($result['email']) ?></p>
                                <p class="text-xs text-gray-400 mt-1 max-w-xs truncate" title="<?= htmlspecialchars($result['nama_sekolah']) ?>"><?= htmlspecialchars($result['nama_sekolah']) ?></p>
                            </td>
                            <td class="px-4 py-4">
                                <p class="font-bold text-gray-800"><?= htmlspecialchars($result['exam_name']) ?></p>
                                <span class="inline-flex mt-1 text-[10px] uppercase font-black px-2 py-1 rounded-full <?= $result['exam_kind'] === 'tryout' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' ?>"><?= $result['exam_kind'] === 'tryout' ? 'Tryout' : 'Ujian Utama' ?></span>
                            </td>
                            <td class="px-4 py-4">
                                <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-bold <?= $result_status_classes[$status] ?? 'bg-gray-100 text-gray-700' ?>"><?= $result_status_labels[$status] ?? $status ?></span>
                            </td>
                            <td class="px-4 py-4 text-center font-bold"><?= (int)$result['answered_count'] ?>/<?= (int)$result['question_count'] ?></td>
                            <td class="px-4 py-4 text-center font-black text-green-700"><?= (int)$result['correct_count'] ?></td>
                            <td class="px-4 py-4 text-center font-black text-red-600"><?= (int)$result['incorrect_count'] ?></td>
                            <td class="px-4 py-4 text-center font-bold text-gray-500"><?= (int)$result['blank_count'] ?></td>
                            <td class="px-4 py-4 text-center">
                                <?php if ($status === 'not_started'): ?>
                                    <span class="text-gray-400">-</span>
                                <?php else: ?>
                                    <span class="inline-flex min-w-16 justify-center rounded-xl px-3 py-2 font-black text-base <?= (float)$result['score'] >= 70 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700' ?>"><?= number_format((float)$result['score'], 2, ',', '.') ?></span>
                                    <?php if ($status === 'in_progress'): ?><p class="text-[10px] text-blue-500 mt-1">sementara</p><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <p class="text-xs"><span class="text-gray-400">Mulai</span><br><strong><?= $started ?></strong></p>
                                <?php if ($finished): ?><p class="text-xs mt-2"><span class="text-gray-400">Selesai</span><br><strong><?= $finished ?></strong></p><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-4 border-t flex flex-col sm:flex-row items-center justify-between gap-3">
                <p class="text-sm text-gray-500">Halaman <?= $result_page ?> dari <?= $result_total_pages ?> · <?= (int)$result_summary['total'] ?> data</p>
                <div class="flex gap-2">
                    <?php
                    $page_params = ['view' => 'results'];
                    if ($result_exam_filter > 0) $page_params['exam'] = $result_exam_filter;
                    if ($result_status_filter !== '') $page_params['status'] = $result_status_filter;
                    if ($result_search !== '') $page_params['q'] = $result_search;
                    ?>
                    <?php if ($result_page > 1): ?><a class="border rounded-xl px-4 py-2 text-sm font-bold hover:bg-gray-50" href="?<?= htmlspecialchars(http_build_query($page_params + ['page' => $result_page - 1])) ?>"><i class="fa-solid fa-chevron-left mr-1"></i> Sebelumnya</a><?php endif; ?>
                    <?php if ($result_page < $result_total_pages): ?><a class="border rounded-xl px-4 py-2 text-sm font-bold hover:bg-gray-50" href="?<?= htmlspecialchars(http_build_query($page_params + ['page' => $result_page + 1])) ?>">Berikutnya <i class="fa-solid fa-chevron-right ml-1"></i></a><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <?php
    $survey_total = (int)$survey_summary['total'];
    $survey_average = (float)$survey_summary['average_rating'];
    $survey_response_rate = $survey_completed_attempts > 0
        ? min(100, round(($survey_total / $survey_completed_attempts) * 100, 1))
        : 0;
    ?>
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl shadow-sm border p-4">
            <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase text-gray-400">Total respons</p><i class="fa-solid fa-comments text-violet-500/40"></i></div>
            <p class="text-3xl font-black text-violet-700 mt-1"><?= $survey_total ?></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border p-4">
            <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase text-gray-400">Rata-rata rating</p><i class="fa-solid fa-star text-amber-400"></i></div>
            <p class="text-3xl font-black text-primary mt-1"><?= number_format($survey_average, 2, ',', '.') ?><span class="text-sm text-gray-400">/5</span></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border p-4">
            <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase text-gray-400">Rating 5</p><i class="fa-solid fa-face-smile-beam text-green-500/50"></i></div>
            <p class="text-3xl font-black text-green-700 mt-1"><?= (int)$survey_summary['five_star'] ?></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border p-4">
            <div class="flex items-center justify-between"><p class="text-xs font-bold uppercase text-gray-400">Response rate</p><i class="fa-solid fa-chart-pie text-blue-500/40"></i></div>
            <p class="text-3xl font-black text-blue-700 mt-1"><?= number_format($survey_response_rate, 1, ',', '.') ?>%</p>
            <p class="text-[11px] text-gray-400 mt-1">dari <?= $survey_completed_attempts ?> attempt selesai</p>
        </div>
    </section>

    <section class="grid grid-cols-1 lg:grid-cols-[420px_1fr] gap-4">
        <div class="bg-white rounded-2xl shadow-sm border p-5">
            <div class="flex items-center justify-between mb-5">
                <div><h2 class="font-black text-primary">Distribusi Rating</h2><p class="text-xs text-gray-500 mt-1">Grafik berdasarkan filter aktif.</p></div>
                <i class="fa-solid fa-chart-simple text-violet-600 text-xl"></i>
            </div>
            <div class="space-y-4">
                <?php foreach ([5, 4, 3, 2, 1] as $rating): ?>
                    <?php
                    $rating_total = $survey_rating_distribution[$rating];
                    $rating_percentage = $survey_total > 0 ? round(($rating_total / $survey_total) * 100) : 0;
                    ?>
                    <div class="grid grid-cols-[52px_1fr_70px] items-center gap-3">
                        <span class="font-black text-sm text-gray-700"><?= $rating ?> <i class="fa-solid fa-star text-amber-400 text-xs"></i></span>
                        <div class="h-3 bg-gray-100 rounded-full overflow-hidden"><div class="h-full rounded-full bg-gradient-to-r from-violet-600 to-blue-500" style="width: <?= $rating_percentage ?>%"></div></div>
                        <span class="text-xs text-right text-gray-500 font-bold"><?= $rating_total ?> (<?= $rating_percentage ?>%)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bg-gradient-to-br from-primary to-secondary rounded-2xl shadow-sm p-5 text-white">
            <p class="text-xs uppercase tracking-[.2em] text-white/60 font-bold">Ringkasan pengalaman</p>
            <h2 class="text-2xl font-black mt-2"><?= $survey_total > 0 ? ($survey_average >= 4 ? 'Mayoritas peserta puas' : ($survey_average >= 3 ? 'Pengalaman peserta cukup baik' : 'Pelaksanaan perlu dievaluasi')) : 'Belum ada data survey' ?></h2>
            <p class="text-sm text-white/75 mt-3 max-w-2xl">Gunakan kesan dan saran peserta di bawah sebagai bahan evaluasi teknis, kualitas soal, serta pelaksanaan Ujian Utama dan Tryout berikutnya.</p>
            <div class="grid grid-cols-2 gap-3 mt-6 max-w-md">
                <div class="bg-white/10 border border-white/10 rounded-xl p-3"><p class="text-xs text-white/60">Respons positif</p><p class="text-xl font-black mt-1"><?= $survey_rating_distribution[5] + $survey_rating_distribution[4] ?></p></div>
                <div class="bg-white/10 border border-white/10 rounded-xl p-3"><p class="text-xs text-white/60">Perlu ditinjau (1–2)</p><p class="text-xl font-black mt-1"><?= $survey_rating_distribution[1] + $survey_rating_distribution[2] ?></p></div>
            </div>
        </div>
    </section>

    <section class="bg-white rounded-2xl shadow-sm border overflow-hidden">
        <div class="p-5 border-b">
            <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl font-black text-primary">Kesan & Saran Peserta</h2>
                    <p class="text-sm text-gray-500 mt-1">Tanggapan terbaru dari setiap attempt ujian.</p>
                </div>
                <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-[210px_140px_250px_auto] gap-2 w-full lg:w-auto">
                    <input type="hidden" name="view" value="surveys">
                    <select name="exam" class="border rounded-xl px-3 py-2.5 bg-white text-sm">
                        <option value="0">Semua ujian</option>
                        <?php foreach ($exam_types as $type): ?>
                            <option value="<?= (int)$type['id'] ?>" <?= $survey_exam_filter === (int)$type['id'] ? 'selected' : '' ?>><?= htmlspecialchars($type['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="rating" class="border rounded-xl px-3 py-2.5 bg-white text-sm">
                        <option value="0">Semua rating</option>
                        <?php foreach ([5, 4, 3, 2, 1] as $rating): ?><option value="<?= $rating ?>" <?= $survey_rating_filter === $rating ? 'selected' : '' ?>><?= $rating ?> bintang</option><?php endforeach; ?>
                    </select>
                    <div class="relative">
                        <i class="fa-solid fa-magnifying-glass absolute left-3 top-3.5 text-gray-400 text-sm"></i>
                        <input name="q" value="<?= htmlspecialchars($survey_search) ?>" class="w-full border rounded-xl pl-9 pr-3 py-2.5 text-sm" placeholder="Peserta, sekolah, isi survey">
                    </div>
                    <button class="bg-violet-700 hover:bg-primary text-white rounded-xl px-4 py-2.5 font-bold text-sm"><i class="fa-solid fa-filter mr-1"></i> Terapkan</button>
                </form>
            </div>
        </div>

        <?php if (!$surveys): ?>
            <div class="text-center px-5 py-16">
                <div class="w-16 h-16 rounded-full bg-violet-50 text-violet-400 flex items-center justify-center mx-auto text-2xl"><i class="fa-solid fa-comment-slash"></i></div>
                <h3 class="font-black text-primary mt-4">Belum ada hasil survey</h3>
                <p class="text-sm text-gray-500 mt-1">Survey peserta akan muncul di sini setelah ujian selesai.</p>
            </div>
        <?php else: ?>
            <div class="divide-y">
                <?php foreach ($surveys as $survey): ?>
                    <article class="p-5 hover:bg-gray-50/70">
                        <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-black text-primary"><?= htmlspecialchars($survey['participant_name']) ?></h3>
                                    <?php if ($survey['username']): ?><span class="text-xs text-gray-400">@<?= htmlspecialchars($survey['username']) ?></span><?php endif; ?>
                                    <span class="text-[10px] uppercase font-black px-2 py-1 rounded-full <?= $survey['exam_kind'] === 'tryout' ? 'bg-blue-50 text-blue-700' : 'bg-amber-50 text-amber-700' ?>"><?= $survey['exam_kind'] === 'tryout' ? 'Tryout' : 'Ujian Utama' ?></span>
                                </div>
                                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($survey['exam_name'] ?? 'Ujian lama') ?><?= $survey['nama_sekolah'] ? ' · ' . htmlspecialchars($survey['nama_sekolah']) : '' ?></p>
                                <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-3">
                                    <div class="bg-violet-50/60 border border-violet-100 rounded-xl p-4"><p class="text-[10px] uppercase tracking-wider font-black text-violet-600 mb-2">Kesan</p><p class="text-sm leading-relaxed whitespace-pre-wrap"><?= htmlspecialchars($survey['kesan']) ?></p></div>
                                    <div class="bg-blue-50/50 border border-blue-100 rounded-xl p-4"><p class="text-[10px] uppercase tracking-wider font-black text-blue-600 mb-2">Saran</p><p class="text-sm leading-relaxed whitespace-pre-wrap <?= !$survey['saran'] ? 'text-gray-400 italic' : '' ?>"><?= $survey['saran'] ? htmlspecialchars($survey['saran']) : 'Tidak ada saran.' ?></p></div>
                                </div>
                            </div>
                            <div class="lg:w-40 lg:text-right shrink-0">
                                <div class="inline-flex gap-1 bg-amber-50 rounded-xl px-3 py-2"><?php for ($star = 1; $star <= 5; $star++): ?><i class="fa-solid fa-star <?= $star <= (int)$survey['rating'] ? 'text-amber-400' : 'text-gray-200' ?>"></i><?php endfor; ?></div>
                                <p class="text-xs text-gray-400 mt-2"><?= date('d/m/Y H:i', strtotime($survey['created_at'])) ?></p>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="px-5 py-4 border-t flex flex-col sm:flex-row items-center justify-between gap-3">
                <p class="text-sm text-gray-500">Halaman <?= $survey_page ?> dari <?= $survey_total_pages ?> · <?= $survey_total ?> respons</p>
                <div class="flex gap-2">
                    <?php
                    $survey_page_params = ['view' => 'surveys'];
                    if ($survey_exam_filter > 0) $survey_page_params['exam'] = $survey_exam_filter;
                    if ($survey_rating_filter > 0) $survey_page_params['rating'] = $survey_rating_filter;
                    if ($survey_search !== '') $survey_page_params['q'] = $survey_search;
                    ?>
                    <?php if ($survey_page > 1): ?><a class="border rounded-xl px-4 py-2 text-sm font-bold hover:bg-gray-50" href="?<?= htmlspecialchars(http_build_query($survey_page_params + ['page' => $survey_page - 1])) ?>"><i class="fa-solid fa-chevron-left mr-1"></i> Sebelumnya</a><?php endif; ?>
                    <?php if ($survey_page < $survey_total_pages): ?><a class="border rounded-xl px-4 py-2 text-sm font-bold hover:bg-gray-50" href="?<?= htmlspecialchars(http_build_query($survey_page_params + ['page' => $survey_page + 1])) ?>">Berikutnya <i class="fa-solid fa-chevron-right ml-1"></i></a><?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
</main>
<script>
async function logout(){const fd=new FormData();fd.append('action','logout');await fetch('admin.php',{method:'POST',body:fd});location.reload()}
<?php if ($admin_view === 'bank'): ?>
const questions=<?= json_encode($map) ?>;
const examTypes=<?= json_encode(array_column($exam_types, null, 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const selectedTypeId=<?= (int)$selected_type['id'] ?>;
function loadQ(no){document.querySelectorAll('.qnav').forEach(b=>b.classList.remove('ring-4','ring-yellow-300'));document.getElementById('nav'+no).classList.add('ring-4','ring-yellow-300');const q=questions[no]||{};question_no.value=no;question_text.value=q.question_text||'';option_a.value=q.option_a||'';option_b.value=q.option_b||'';option_c.value=q.option_c||'';option_d.value=q.option_d||'';option_e.value=q.option_e||'';correct_option.value=q.correct_option||'';is_active.checked=q.is_active===undefined?true:parseInt(q.is_active)===1}
questionForm.onsubmit=async e=>{e.preventDefault();const fd=new FormData();fd.append('action','save_question');fd.append('exam_type_id',selectedTypeId);['question_no','question_text','option_a','option_b','option_c','option_d','option_e','correct_option'].forEach(id=>fd.append(id,document.getElementById(id).value));fd.append('is_active',is_active.checked?'1':'0');const r=await fetch('admin.php',{method:'POST',body:fd});const j=await r.json();saveAlert.innerHTML=`<div class="${j.success?'bg-green-50 text-secondary border-secondary':'bg-red-50 text-red-700 border-red-500'} border-l-4 p-3 rounded mb-4">${j.message||j.error}</div>`;if(j.success)setTimeout(()=>location.reload(),650)}
function newType(kind='main'){typeForm.reset();type_id.value='';type_kind.value=kind;type_duration.value=120;type_question_count.value=50;type_active.checked=true;type_default.checked=false;typeFormTitle.textContent=kind==='tryout'?'Buat Tryout Baru':'Tambah Ujian Utama';typeAlert.innerHTML='';typeForm.scrollIntoView({behavior:'smooth',block:'center'})}
function editType(id){const t=examTypes[id];if(!t)return;type_id.value=t.id;type_kind.value=t.exam_kind;type_name.value=t.name;type_description.value=t.description||'';type_duration.value=t.duration_minutes;type_question_count.value=t.question_count;type_active.checked=parseInt(t.is_active)===1;type_default.checked=parseInt(t.is_default)===1;typeFormTitle.textContent='Edit '+(t.exam_kind==='tryout'?'Tryout':'Ujian Utama');typeAlert.innerHTML='';typeForm.scrollIntoView({behavior:'smooth',block:'center'})}
type_active.onchange=()=>{if(!type_active.checked)type_default.checked=false};
type_default.onchange=()=>{if(type_default.checked){type_kind.value='main';type_active.checked=true}};
typeForm.onsubmit=async e=>{e.preventDefault();const fd=new FormData();fd.append('action','save_exam_type');fd.append('id',type_id.value);fd.append('exam_kind',type_kind.value);fd.append('name',type_name.value);fd.append('description',type_description.value);fd.append('duration_minutes',type_duration.value);fd.append('question_count',type_question_count.value);fd.append('is_active',type_active.checked?'1':'0');fd.append('is_default',type_default.checked?'1':'0');const r=await fetch('admin.php',{method:'POST',body:fd});const j=await r.json();typeAlert.innerHTML=`<div class="${j.success?'bg-green-50 text-secondary':'bg-red-50 text-red-700'} rounded p-2 text-sm">${escapeHtml(j.message||j.error)}</div>`;if(j.success)setTimeout(()=>location.href='?type='+j.id+'#bank-soal',650)}
async function toggleType(id,activate){const action=activate?'mengaktifkan':'menonaktifkan';if(!confirm(`Yakin ingin ${action} ujian ini?`))return;const fd=new FormData();fd.append('action','toggle_exam_type_status');fd.append('id',id);fd.append('is_active',activate?'1':'0');const r=await fetch('admin.php',{method:'POST',body:fd});const j=await r.json();if(j.success)location.reload();else alert(j.error||'Status ujian gagal diperbarui.')}
async function deleteType(id){if(!confirm('Hapus jenis ujian ini?'))return;const fd=new FormData();fd.append('action','delete_exam_type');fd.append('id',id);const r=await fetch('admin.php',{method:'POST',body:fd});const j=await r.json();if(j.success)location.reload();else alert(j.error)}
function escapeHtml(value){const div=document.createElement('div');div.textContent=value||'';return div.innerHTML}
loadQ(1);
<?php endif; ?>
</script>
<?php endif; ?>
</body>
</html>
