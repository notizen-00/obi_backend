<?php
session_start();
require_once __DIR__ . '/../db/exam.php';

ensure_exam_tables();

$exam_user = $_SESSION['exam_user'] ?? null;
$exam_type = default_exam_type();
$exam_attempt = null;
if ($exam_user && $exam_type) {
    $stmt = db()->prepare('SELECT * FROM exam_attempts WHERE exam_user_id = ? AND exam_type_id = ? LIMIT 1');
    $stmt->execute([$exam_user['id'], $exam_type['id']]);
    $exam_attempt = $stmt->fetch() ?: null;
    if ($exam_attempt) {
        $exam_attempt = refresh_attempt_status($exam_attempt);
    }
}
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = (int)($_POST['rating'] ?? 0);
    $kesan = trim($_POST['kesan'] ?? '');
    $saran = trim($_POST['saran'] ?? '');

    if (!$exam_user || !$exam_attempt) {
        $error = 'Sesi atau data ujian tidak ditemukan. Silakan masuk kembali melalui halaman ujian.';
    } elseif ($rating < 1 || $rating > 5 || $kesan === '' || mb_strlen($kesan) > 2000 || mb_strlen($saran) > 2000) {
        $error = 'Rating dan kesan wajib diisi.';
    } elseif ($exam_attempt && !in_array($exam_attempt['status'], ['submitted', 'expired'], true)) {
        $error = 'Survey hanya dapat diisi setelah ujian selesai.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO exam_surveys (exam_user_id, exam_type_id, exam_attempt_id, nama, rating, kesan, saran, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $exam_user['id'] ?? null,
                $exam_attempt['exam_type_id'] ?? null,
                $exam_attempt['id'] ?? null,
                $exam_user['nama'] ?? null,
                $rating,
                $kesan,
                $saran ?: null,
            ]);
            unset($_SESSION['exam_user']);
            $success = true;
        } catch (PDOException $exception) {
            $error = $exception->getCode() === '23000'
                ? 'Survey untuk ujian ini sudah pernah dikirim.'
                : 'Survey belum dapat disimpan. Silakan coba kembali.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Survey ONBI 2026</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={theme:{extend:{colors:{primary:'#133D56',secondary:'#025C26'}}}}</script>
</head>
<body class="min-h-screen bg-gradient-to-br from-primary to-secondary text-gray-800 flex items-center justify-center p-4">
    <main class="bg-white rounded-2xl shadow-2xl max-w-xl w-full p-6 md:p-8">
        <?php if ($success): ?>
            <div class="text-center py-8">
                <div class="w-20 h-20 bg-secondary text-white rounded-full flex items-center justify-center mx-auto mb-4 text-4xl">✓</div>
                <h1 class="text-3xl font-black text-primary mb-2">Terima kasih!</h1>
                <p class="text-gray-600">Survey Anda sudah tersimpan. Sampai jumpa di pengumuman berikutnya.</p>
            </div>
        <?php else: ?>
            <h1 class="text-3xl font-black text-primary mb-2">Survey Setelah Ujian</h1>
            <p class="text-gray-600 mb-5">Mohon isi survey singkat ini untuk membantu panitia meningkatkan pelaksanaan ONBI.</p>
            <?php if ($error): ?><div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block font-bold text-primary mb-2">Rating pengalaman ujian</label>
                    <select name="rating" required class="w-full border rounded-xl px-4 py-3">
                        <option value="">Pilih rating</option>
                        <option value="5">5 - Sangat baik</option>
                        <option value="4">4 - Baik</option>
                        <option value="3">3 - Cukup</option>
                        <option value="2">2 - Kurang</option>
                        <option value="1">1 - Perlu banyak perbaikan</option>
                    </select>
                </div>
                <div>
                    <label class="block font-bold text-primary mb-2">Kesan setelah mengikuti ujian</label>
                    <textarea name="kesan" rows="4" required class="w-full border rounded-xl px-4 py-3" placeholder="Tuliskan kesan Anda..."></textarea>
                </div>
                <div>
                    <label class="block font-bold text-primary mb-2">Saran untuk panitia</label>
                    <textarea name="saran" rows="3" class="w-full border rounded-xl px-4 py-3" placeholder="Opsional"></textarea>
                </div>
                <button class="w-full bg-primary hover:bg-secondary text-white py-3 rounded-xl font-black">Kirim Survey</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
