<?php
session_start();

$guard_ttl = 6 * 60 * 60;
$next = $_GET['next'] ?? ($_POST['next'] ?? 'reg');
$allowed_next = [
    'reg' => '../reg/',
    'akun' => '../reg/?akun=1',
];
$target = $allowed_next[$next] ?? $allowed_next['reg'];

if (!empty($_SESSION['reg_guard_passed_at']) && (time() - (int)$_SESSION['reg_guard_passed_at']) < $guard_ttl) {
    header('Location: ' . $target, true, 302);
    exit;
}

if (empty($_SESSION['guard_num1']) || empty($_SESSION['guard_num2']) || empty($_SESSION['guard_token'])) {
    $_SESSION['guard_num1'] = random_int(2, 9);
    $_SESSION['guard_num2'] = random_int(2, 9);
    $_SESSION['guard_token'] = bin2hex(random_bytes(24));
    $_SESSION['guard_loaded_at'] = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['guard_token'] ?? '';
    $answer = isset($_POST['guard_answer']) ? (int)$_POST['guard_answer'] : -999;
    $expected = (int)$_SESSION['guard_num1'] + (int)$_SESSION['guard_num2'];
    $loaded_at = (int)($_SESSION['guard_loaded_at'] ?? time());

    if (!is_string($token) || !hash_equals($_SESSION['guard_token'] ?? '', $token) || (time() - $loaded_at) < 2) {
        header('Location: https://unej.ac.id', true, 302);
        exit;
    }

    if ($answer === $expected) {
        $_SESSION['reg_guard_passed_at'] = time();
        unset($_SESSION['guard_num1'], $_SESSION['guard_num2'], $_SESSION['guard_token'], $_SESSION['guard_loaded_at']);
        header('Location: ' . $target, true, 302);
        exit;
    }

    header('Location: https://unej.ac.id', true, 302);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Check - OBI 2026</title>
    <link rel="icon" type="image/png" href="/../unejmask.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = { theme: { extend: { colors: { primary: '#133D56', secondary: '#025C26' } } } }
    </script>
    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(255,255,255,0.16), transparent 22rem),
                radial-gradient(circle at bottom right, rgba(2,92,38,0.42), transparent 24rem),
                linear-gradient(135deg, #102f43, #1a2e3e 54%, #025c26);
        }
        .guard-card {
            background: rgba(255,255,255,0.94);
            border: 1px solid rgba(255,255,255,0.65);
            box-shadow: 0 24px 80px rgba(0,0,0,0.24);
            backdrop-filter: blur(16px);
        }
        .guard-pattern::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0.06;
            background-image: url('/../obi-logo.png'), url('/../unejmask.png');
            background-size: 92px 92px, 66px 66px;
            background-position: 18px 20px, 74px 62px;
            background-repeat: repeat;
            transform: rotate(-8deg) scale(1.14);
            pointer-events: none;
        }
    </style>
</head>
<body class="min-h-screen font-sans antialiased text-gray-800 guard-pattern relative overflow-hidden">
    <main class="relative z-10 min-h-screen flex items-center justify-center p-4">
        <section class="guard-card w-full max-w-md rounded-3xl p-6 md:p-8 text-center">
            <div class="flex justify-center gap-2 mb-5">
                <img src="/../obi-logo.png" alt="Logo OBI" class="w-16 h-16 object-contain bg-white rounded-full p-1 shadow">
                <img src="/../unejmask.png" alt="Logo UNEJ" class="w-16 h-16 object-contain bg-white rounded-full p-1 shadow">
            </div>

            <div class="w-16 h-16 rounded-full bg-primary text-white flex items-center justify-center mx-auto mb-4 shadow-lg">
                <i class="fas fa-shield-halved text-2xl"></i>
            </div>

            <p class="text-xs font-black tracking-[0.25em] text-secondary uppercase mb-2">Security Gateway</p>
            <h1 class="text-2xl md:text-3xl font-black text-primary mb-2">Verifikasi Pengunjung</h1>
            <p class="text-sm text-gray-600 mb-6">Jawab pertanyaan singkat ini sebelum masuk ke halaman pendaftaran.</p>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="guard_token" value="<?= htmlspecialchars($_SESSION['guard_token']) ?>">
                <input type="hidden" name="next" value="<?= htmlspecialchars(array_key_exists($next, $allowed_next) ? $next : 'reg') ?>">
                <div class="bg-gray-50 border border-gray-200 rounded-2xl p-5">
                    <p class="text-sm text-gray-500 mb-2">Berapa hasil dari</p>
                    <p class="text-4xl font-black text-primary select-none">
                        <?= (int)$_SESSION['guard_num1'] ?> + <?= (int)$_SESSION['guard_num2'] ?>
                    </p>
                </div>

                <input type="number" name="guard_answer" required autofocus inputmode="numeric"
                       class="w-full text-center text-xl font-bold px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-primary focus:border-primary outline-none"
                       placeholder="Jawaban">

                <button type="submit" class="w-full bg-primary hover:bg-secondary text-white font-black py-3 rounded-xl transition shadow-lg">
                    <i class="fas fa-arrow-right-to-bracket mr-2"></i>Lanjutkan
                </button>
            </form>

            <a href="../" class="inline-block text-xs text-gray-400 hover:text-primary mt-5">Kembali ke halaman utama</a>
        </section>
    </main>
</body>
</html>
