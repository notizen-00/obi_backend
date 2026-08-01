<?php
session_start();

$guard_ttl = 6 * 60 * 60;
if (empty($_SESSION['reg_guard_passed_at']) || (time() - (int)$_SESSION['reg_guard_passed_at']) >= $guard_ttl) {
    $next = isset($_GET['akun']) ? 'akun' : 'reg';
    header('Location: ../gate/?next=' . $next, true, 302);
    exit;
}

require_once __DIR__ . '/../db/mysql.php';

const REG_CONTACT_SILVI = '6281244120600';
const REG_CONTACT_NATA = '6281234771917';
const REG_WHATSAPP_GROUP = 'https://chat.whatsapp.com/DsngNpsMAKwLoEcGmgoJl2';

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return substr($ip, 0, 45);
}

function ensure_registration_security_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS registration_attempts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip_address VARCHAR(45) NOT NULL,
        status VARCHAR(20) NOT NULL,
        reason VARCHAR(120) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_registration_attempts_ip_created (ip_address, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function registration_rate_limit_message(string $ip): ?string
{
    ensure_registration_security_table();
    db()->exec("DELETE FROM registration_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)");

    $stmt = db()->prepare("SELECT
        SUM(created_at >= (NOW() - INTERVAL 10 MINUTE)) AS last_10m,
        SUM(created_at >= (NOW() - INTERVAL 1 HOUR)) AS last_1h
        FROM registration_attempts
        WHERE ip_address = ?");
    $stmt->execute([$ip]);
    $row = $stmt->fetch() ?: ['last_10m' => 0, 'last_1h' => 0];

    if ((int)$row['last_10m'] >= 5) {
        return 'Terlalu banyak percobaan dari jaringan Anda. Silakan coba lagi sekitar 10 menit lagi.';
    }
    if ((int)$row['last_1h'] >= 20) {
        return 'Terlalu banyak percobaan registrasi dalam 1 jam. Silakan coba lagi nanti.';
    }
    return null;
}

function record_registration_attempt(string $ip, string $status, string $reason = ''): void
{
    try {
        ensure_registration_security_table();
        $stmt = db()->prepare('INSERT INTO registration_attempts (ip_address, status, reason, created_at) VALUES (?, ?, ?, NOW())');
        $stmt->execute([$ip, $status, substr($reason, 0, 120)]);
    } catch (Throwable $e) {
        // Jangan gagalkan registrasi hanya karena log keamanan tidak bisa ditulis.
    }
}

function reset_form_security(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['form_loaded_at'] = time();
}

function secure_upload(string $field, array $extensions, array $mimes, string $prefix, string $upload_dir, string &$error): ?string
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        $error = 'File wajib diupload.';
        return null;
    }

    $file = $_FILES[$field];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true) || $file['size'] <= 0 || $file['size'] > 2 * 1024 * 1024) {
        $error = 'Format file tidak sesuai atau ukuran melebihi 2MB.';
        return null;
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!in_array($mime, $mimes, true)) {
            $error = 'Isi file tidak sesuai dengan format yang diizinkan.';
            return null;
        }
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
        $error = 'Gagal mengupload file.';
        return null;
    }

    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    try {
        $action = $_POST['action'];

        if ($action === 'verify_account') {
            $email = trim(strtolower($_POST['email'] ?? ''));
            $nik = trim($_POST['nik'] ?? '');

            if ($email === '' || $nik === '') {
                echo json_encode(['success' => false, 'error' => 'Email dan NIK wajib diisi.']);
                exit;
            }

            $found = participant_by_email_nik($email, $nik);
            if (!$found) {
                echo json_encode(['success' => false, 'error' => 'Email atau NIK tidak ditemukan. Pastikan data sama dengan saat pendaftaran.']);
                exit;
            }

            if (exam_account_exists_for_email($email)) {
                echo json_encode(['success' => false, 'error' => 'Akun ujian untuk email ini sudah pernah dibuat.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'nama' => $found['nama'],
                'reg_id' => $found['id'],
                'sekolah' => $found['nama_sekolah'],
            ]);
            exit;
        }

        if ($action === 'create_account') {
            $email = trim(strtolower($_POST['email'] ?? ''));
            $nik = trim($_POST['nik'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($email === '' || $nik === '') {
                echo json_encode(['success' => false, 'error' => 'Email dan NIK wajib diisi.']);
                exit;
            }

            $found = participant_by_email_nik($email, $nik);
            if (!$found) {
                echo json_encode(['success' => false, 'error' => 'Verifikasi gagal. Silakan ulangi dari awal.']);
                exit;
            }
            if (exam_account_exists_for_email($email)) {
                echo json_encode(['success' => false, 'error' => 'Akun untuk email ini sudah ada.']);
                exit;
            }
            $account_error = exam_account_validation_error($username, $password, $confirm);
            if ($account_error !== null) {
                echo json_encode(['success' => false, 'error' => $account_error]);
                exit;
            }

            create_exam_account($found, $username, $password);

            echo json_encode([
                'success' => true,
                'message' => 'Akun ujian berhasil dibuat!',
                'username' => $username,
                'nama' => $found['nama'],
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Action tidak valid.']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Terjadi kesalahan server. Silakan coba lagi.']);
        exit;
    }
}

// --- 1. ANTI-BOT: Math CAPTCHA ---
if (!isset($_SESSION['captcha_num1']) || !isset($_SESSION['captcha_num2'])) {
    $_SESSION['captcha_num1'] = rand(1, 9);
    $_SESSION['captcha_num2'] = rand(1, 9);
}

$error = '';
$success = '';
$show_modal = false;
$modal_data = [];
$show_account_panel = isset($_GET['akun']);
if (empty($_SESSION['csrf_token']) || empty($_SESSION['form_loaded_at'])) {
    reset_form_security();
}

// --- 2. PROSES FORM SAAT SUBMIT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_ip = client_ip();
    $security_error = '';

    try {
        $security_error = registration_rate_limit_message($client_ip) ?? '';
    } catch (Throwable $e) {
        $security_error = 'Proteksi registrasi belum siap. Pastikan tabel keamanan sudah tersedia di database.';
    }

    if ($security_error === '') {
        $posted_token = $_POST['csrf_token'] ?? '';
        if (!is_string($posted_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted_token)) {
            $security_error = 'Sesi form tidak valid. Silakan refresh halaman lalu coba lagi.';
        }
    }

    $honeypot = $_POST['website'] ?? '';
    if ($security_error === '' && (!is_scalar($honeypot) || trim((string)$honeypot) !== '')) {
        $security_error = 'Permintaan ditolak oleh sistem keamanan.';
    }

    if ($security_error === '' && (time() - (int)($_SESSION['form_loaded_at'] ?? time())) < 4) {
        $security_error = 'Form dikirim terlalu cepat. Silakan isi kembali dengan normal.';
    }

    // Validasi Captcha
    $captcha_answer = isset($_POST['captcha_answer']) ? (int)$_POST['captcha_answer'] : -1;
    if ($security_error !== '') {
        $error = $security_error;
        record_registration_attempt($client_ip, 'blocked', $security_error);
        $_SESSION['captcha_num1'] = rand(1, 9);
        $_SESSION['captcha_num2'] = rand(1, 9);
        reset_form_security();
    } elseif ($captcha_answer !== ($_SESSION['captcha_num1'] + $_SESSION['captcha_num2'])) {
        $error = "Jawaban Captcha salah. Silakan coba lagi.";
        record_registration_attempt($client_ip, 'failed', 'captcha');
        $_SESSION['captcha_num1'] = rand(1, 9);
        $_SESSION['captcha_num2'] = rand(1, 9);
        reset_form_security();
    } else {
        // Sanitasi Input
        $nama = trim($_POST['nama'] ?? '');
        $nik = trim($_POST['nik'] ?? '');
        $tempat_lahir = trim($_POST['tempat_lahir'] ?? '');
        $tgl_lahir = trim($_POST['tgl_lahir'] ?? '');
        $nama_sekolah = trim($_POST['nama_sekolah'] ?? '');
        $alamat_sekolah = trim($_POST['alamat_sekolah'] ?? '');
        $alamat_rumah = trim($_POST['alamat_rumah'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $no_hp = trim($_POST['no_hp'] ?? '');
        $jenis_pembayaran = trim($_POST['jenis_pembayaran'] ?? '');
        $exam_username = trim($_POST['exam_username'] ?? '');
        $exam_password = $_POST['exam_password'] ?? '';
        $exam_confirm = $_POST['exam_confirm'] ?? '';
        $pernyataan = isset($_POST['pernyataan']) ? 1 : 0;

        // Validasi Jenis Pembayaran
        $jenis_pembayaran_valid = ['Individu', 'Kolektif 1 (3-5 orang)', 'Kolektif 2 (lebih dari 6 orang)'];
        if (!in_array($jenis_pembayaran, $jenis_pembayaran_valid)) {
            $error = "Jenis pembayaran tidak valid.";
        }

        // Validasi Field Wajib
        if ($error === '' && (empty($nama) || empty($nik) || empty($tempat_lahir) || empty($tgl_lahir) || empty($nama_sekolah) || empty($alamat_sekolah) || empty($alamat_rumah) || empty($email) || empty($no_hp) || !$pernyataan)) {
            $error = "Semua field wajib diisi dengan benar.";
        } elseif ($error === '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Format email tidak valid.";
        } elseif ($error === '' && !preg_match('/^[0-9]{10,15}$/', str_replace([' ', '-', '+'], '', $no_hp))) {
            $error = "Nomor HP tidak valid (hanya angka, 10-15 digit).";
        } elseif ($error === '' && ($account_error = exam_account_validation_error($exam_username, $exam_password, $exam_confirm)) !== null) {
            $error = $account_error;
        } elseif ($error === '') {
            // --- 3. HANDLE FILE UPLOADS ---
            $upload_dir = __DIR__ . '/../uploads/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $files_ok = true;
            $uploaded_files = [];

            // A. Foto Profil
            $upload_error = '';
            $foto = secure_upload('foto_profil', ['jpg', 'jpeg', 'png'], ['image/jpeg', 'image/png'], 'foto', $upload_dir, $upload_error);
            if ($foto) {
                $uploaded_files['foto_profil'] = $foto;
            } else {
                $files_ok = false;
                $error = "Foto Profil: " . $upload_error;
            }

            // B. Bukti Pembayaran
            if ($files_ok) {
                $upload_error = '';
                $bukti = secure_upload('bukti_bayar', ['pdf'], ['application/pdf', 'application/x-pdf'], 'bayar', $upload_dir, $upload_error);
                if ($bukti) {
                    $uploaded_files['bukti_bayar'] = $bukti;
                } else {
                    $files_ok = false;
                    $error = "Bukti Pembayaran: " . $upload_error;
                }
            }

            // C. Identitas Sekolah (Kartu Pelajar ATAU Surat Keterangan Aktif)
            $identitas_uploaded = false;
            $identitas_type = '';
            $identitas_filename = '';
            
            // Cek Kartu Pelajar
            if ($files_ok && isset($_FILES['kartu_pelajar']) && $_FILES['kartu_pelajar']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload_error = '';
                $kartu = secure_upload('kartu_pelajar', ['jpg', 'jpeg', 'png', 'pdf'], ['image/jpeg', 'image/png', 'application/pdf', 'application/x-pdf'], 'kartu', $upload_dir, $upload_error);
                if ($kartu) {
                    $identitas_uploaded = true;
                    $identitas_type = 'kartu_pelajar';
                    $identitas_filename = $kartu;
                } else {
                    $files_ok = false;
                    $error = "Kartu Pelajar: " . $upload_error;
                }
            }
            
            // Cek Surat Keterangan Aktif (jika kartu pelajar belum diupload)
            if ($files_ok && !$identitas_uploaded && isset($_FILES['surat_aktif']) && $_FILES['surat_aktif']['error'] !== UPLOAD_ERR_NO_FILE) {
                $upload_error = '';
                $surat = secure_upload('surat_aktif', ['jpg', 'jpeg', 'png', 'pdf'], ['image/jpeg', 'image/png', 'application/pdf', 'application/x-pdf'], 'surat_aktif', $upload_dir, $upload_error);
                if ($surat) {
                    $identitas_uploaded = true;
                    $identitas_type = 'surat_aktif';
                    $identitas_filename = $surat;
                } else {
                    $files_ok = false;
                    $error = "Surat Keterangan Aktif: " . $upload_error;
                }
            }
            
            if ($files_ok && !$identitas_uploaded) {
                $files_ok = false;
                $error = "Anda harus mengupload salah satu: Kartu Pelajar atau Surat Keterangan Aktif Sekolah.";
            }

            // Peserta dan akun ujian harus tersimpan sebagai satu kesatuan.
            if ($files_ok) {
                $database = null;
                try {
                    $database = db();
                    $database->beginTransaction();
                    $reg_id = make_public_id('REG');
                    $normalized_email = strtolower($email);
                    $stmt = $database->prepare('INSERT INTO participants (id, registered_at, nama, nik, tempat_lahir, tgl_lahir, nama_sekolah, alamat_sekolah, alamat_rumah, email, no_hp, jenis_pembayaran, foto_profil, bukti_bayar, identitas_type, identitas_file, status_bayar) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([
                        $reg_id,
                        $nama,
                        $nik,
                        $tempat_lahir,
                        $tgl_lahir,
                        $nama_sekolah,
                        $alamat_sekolah,
                        $alamat_rumah,
                        $normalized_email,
                        $no_hp,
                        $jenis_pembayaran,
                        $uploaded_files['foto_profil'],
                        $uploaded_files['bukti_bayar'],
                        $identitas_type,
                        $identitas_filename,
                        'pending',
                    ]);

                    create_exam_account([
                        'id' => $reg_id,
                        'nama' => $nama,
                        'email' => $normalized_email,
                        'nik' => $nik,
                    ], $exam_username, $exam_password);
                    $database->commit();

                    $success = "Pendaftaran dan akun ujian berhasil dibuat.";
                    $show_modal = true;
                    $modal_data = [
                        'reg_id' => $reg_id,
                        'nama' => $nama,
                        'username' => $exam_username,
                        'sekolah' => $nama_sekolah,
                        'jenis_pembayaran' => $jenis_pembayaran,
                        'wa_url' => 'https://wa.me/' . REG_CONTACT_SILVI . '?text=' . urlencode($jenis_pembayaran . '_' . $nama . '_' . $nama_sekolah),
                        'wa_nata_url' => 'https://wa.me/' . REG_CONTACT_NATA . '?text=' . urlencode($jenis_pembayaran . '_' . $nama . '_' . $nama_sekolah),
                        'grup_url' => REG_WHATSAPP_GROUP,
                    ];

                    record_registration_attempt($client_ip, 'success', 'registered_with_exam_account');
                    $_SESSION['captcha_num1'] = rand(1, 9);
                    $_SESSION['captcha_num2'] = rand(1, 9);
                    reset_form_security();
                } catch (Throwable $e) {
                    if ($database instanceof PDO && $database->inTransaction()) {
                        $database->rollBack();
                    }
                    $error = $e instanceof PDOException && $e->getCode() === '23000'
                        ? "NIK, email, atau username sudah pernah terdaftar."
                        : "Gagal menyimpan pendaftaran. Silakan coba lagi.";
                    record_registration_attempt($client_ip, 'failed', 'database');
                    reset_form_security();
                }
            } else {
                record_registration_attempt($client_ip, 'failed', 'upload');
                reset_form_security();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Olimpiade Nasional Bahasa Indonesia 2026</title>
    
	<link rel="icon" type="image/png" href="/../unejmask.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#133D56',
                        secondary: '#025C26',
                    }
                }
            }
        }
    </script>
    
    <style>
        html {
            scroll-behavior: smooth;
        }
        .progress-bar {
            transition: width 0.4s ease-in-out, background-color 0.4s ease-in-out;
        }
        .floating-progress {
            position: fixed;
            left: 1rem;
            bottom: 1rem;
            z-index: 40;
            width: min(360px, calc(100vw - 2rem));
            border: 1px solid rgba(255, 255, 255, 0.72);
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 18px 45px rgba(19, 61, 86, 0.18);
            backdrop-filter: blur(16px);
        }
        .floating-progress::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(19, 61, 86, 0.08), rgba(2, 92, 38, 0.08));
        }
        .scroll-top-btn {
            position: fixed;
            right: 1rem;
            bottom: 1rem;
            z-index: 40;
            width: 3rem;
            height: 3rem;
            transform: translateY(16px) scale(0.95);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease, transform 0.25s ease, background-color 0.25s ease;
        }
        .scroll-top-btn.is-visible {
            transform: translateY(0) scale(1);
            opacity: 1;
            pointer-events: auto;
        }
        .ornament-header {
            position: relative;
            overflow: hidden;
            background:
                linear-gradient(135deg, rgba(26, 46, 62, 0.98), rgba(19, 61, 86, 0.94) 48%, rgba(2, 92, 38, 0.9)),
                #1a2e3e;
        }
        .ornament-header::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0.08;
            background-image:
                url('/../overlay.png'),
                url('/../overlay.png');
            background-size: 86px 86px, 64px 64px;
            background-position: 18px 18px, 68px 56px;
            background-repeat: repeat;
            /*transform: rotate(-7deg) scale(1.16); */
            transform-origin: center;
            pointer-events: none;
        }
        .ornament-header::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 12% 20%, rgba(255, 255, 255, 0.18), transparent 14rem),
                radial-gradient(circle at 84% 12%, rgba(247, 201, 72, 0.18), transparent 12rem),
                linear-gradient(90deg, rgba(26, 46, 62, 0.3), rgba(26, 46, 62, 0.08));
            pointer-events: none;
        }
        .ornament-header > * {
            position: relative;
            z-index: 1;
        }
        @media (max-width: 640px) {
            .floating-progress {
                left: 0.75rem;
                right: 4.5rem;
                bottom: 0.75rem;
                width: auto;
            }
            .scroll-top-btn {
                right: 0.75rem;
                bottom: 0.75rem;
            }
            .ornament-header::before {
                opacity: 0.06;
                background-size: 64px 64px, 48px 48px;
            }
        }
        .modal-backdrop { background: rgba(19, 61, 86, 0.7); backdrop-filter: blur(4px); }
        @keyframes slideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .animate-slide-in { animation: slideIn 0.3s ease-out; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased">

    <!-- Header -->
    <header class="ornament-header text-white shadow-lg">
        <div class="max-w-5xl mx-auto px-4 py-4 md:py-6">
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3 sm:gap-4">
                <div class="flex items-center justify-center gap-3 sm:gap-4 w-full sm:w-auto">
                    <img src="/../obi-logo.png"
                         alt="Logo OBI"
                         class="w-14 h-14 sm:w-16 sm:h-16 md:w-20 md:h-20 object-contain bg-white p-1 rounded-full shadow flex-shrink-0">

                    <div class="text-center sm:text-left min-w-0">
                        <h1 class="text-base sm:text-xl md:text-2xl font-bold tracking-wide leading-tight">
                            Olimpiade Nasional Bahasa Indonesia
                        </h1>
                        <p class="text-xs sm:text-sm text-gray-200 leading-snug max-w-[18rem] sm:max-w-md md:max-w-xl">
                            Fakultas Keguruan dan Ilmu Pendidikan - Universitas Jember Tahun 2026
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-center gap-2 sm:gap-3 flex-shrink-0">
                    <img src="/../unejmask.png"
                         alt="Logo UNEJ"
                         class="w-10 h-10 sm:w-12 sm:h-12 md:w-16 md:h-16 object-contain bg-white/10 p-1 rounded-full shadow">

                    <img src="/../fkipmask.png"
                         alt="Logo FKIP UNEJ"
                         class="w-10 h-10 sm:w-12 sm:h-12 md:w-16 md:h-16 object-contain bg-white/10 p-1 rounded-full shadow">

                    <img src="/../imabinamask.png"
                         alt="Logo IMABINA"
                         class="w-10 h-10 sm:w-12 sm:h-12 md:w-16 md:h-16 object-contain bg-white/10 p-1 rounded-full shadow">
                </div>
            </div>
        </div>
        <div class="h-2 bg-primary"></div>
    </header>

    <div id="floatingProgress" class="floating-progress rounded-full px-4 py-3">
        <div class="relative flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-primary text-white flex items-center justify-center shadow-md shrink-0">
                <i class="fas fa-tasks"></i>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3 mb-1">
                    <span class="text-xs font-bold text-primary uppercase tracking-wide">Kelengkapan Data</span>
                    <span id="progress-text" class="text-xs font-black text-secondary whitespace-nowrap">0% Lengkap</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                    <div id="progress-bar" class="progress-bar h-2 rounded-full bg-red-500" style="width: 0%"></div>
                </div>
                <p id="progress-caption" class="text-[11px] text-gray-500 mt-1 truncate">Lengkapi data untuk mengaktifkan tombol daftar.</p>
            </div>
        </div>
    </div>

    <main class="max-w-4xl mx-auto px-4 py-8">

        <!-- Alert Messages -->
        <?php if ($error): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-sm" role="alert">
                <p class="font-bold"><i class="fas fa-exclamation-triangle mr-2"></i>Error</p>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <?php if ($show_account_panel): ?>
        <div id="existingAccountPanel" class="bg-white rounded-xl shadow-lg p-6 md:p-8 mb-6 border-t-4 border-primary">
            <h2 class="text-xl font-bold text-primary mb-1"><i class="fas fa-user-shield mr-2"></i>Buat Akun Ujian</h2>
            <p class="text-sm text-gray-500 mb-2">Khusus peserta lama yang sudah mendaftar tetapi belum memiliki akun ujian.</p>
            <p class="text-xs text-gray-400 mb-5">Pendaftaran baru wajib membuat akun ujian.</p>
            <div id="existing-alert"></div>
            <form id="existingVerifyForm" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">Email</label>
                    <input type="email" id="ex_email" required class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">NIK</label>
                    <input type="text" id="ex_nik" required maxlength="16" pattern="\d{16}" class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary outline-none">
                </div>
                <button type="submit" class="bg-primary hover:bg-secondary text-white font-bold py-2.5 px-5 rounded-lg transition">Verifikasi</button>
            </form>
            <form id="existingCreateForm" class="hidden mt-6 space-y-4">
                <div class="bg-green-50 border-l-4 border-secondary p-3 rounded">
                    <p class="font-bold text-secondary" id="ex_info_nama"></p>
                    <p class="text-sm text-gray-700" id="ex_info_sekolah"></p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <input type="text" id="ex_username" required minlength="4" class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary outline-none" placeholder="Username">
                    <input type="password" id="ex_password" required minlength="6" class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary outline-none" placeholder="Password">
                    <input type="password" id="ex_confirm" required minlength="6" class="px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary outline-none" placeholder="Konfirmasi Password">
                </div>
                <button type="submit" class="bg-secondary hover:bg-primary text-white font-bold py-2.5 px-5 rounded-lg transition">Buat Akun Ujian</button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Form Pendaftaran -->
        <form id="regForm" action="" method="POST" enctype="multipart/form-data" class="bg-white rounded-xl shadow-lg p-6 md:p-8">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <div class="absolute left-[-9999px] top-auto w-px h-px overflow-hidden" aria-hidden="true">
                <label for="website">Website</label>
                <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Nama Lengkap -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-user text-primary mr-2"></i>Nama Lengkap</label>
                    <input type="text" id="nama" name="nama" required class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white" placeholder="Masukkan nama lengkap sesuai KTP">
                </div>

                <!-- NIK -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-id-card text-primary mr-2"></i>NIK</label>
                    <input type="text" id="nik" name="nik" required maxlength="16" pattern="\d{16}" title="NIK harus 16 digit angka" class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white" placeholder="16 digit NIK">
                </div>

                <!-- Tempat Lahir -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-map-marker-alt text-primary mr-2"></i>Tempat Lahir</label>
                    <input type="text" id="tempat_lahir" name="tempat_lahir" required class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white" placeholder="Kota/Kabupaten">
                </div>

                <!-- Tanggal Lahir -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-calendar-alt text-primary mr-2"></i>Tanggal Lahir</label>
                    <input type="date" id="tgl_lahir" name="tgl_lahir" required class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white">
                </div>

                <!-- Nama Sekolah -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-school text-primary mr-2"></i>Nama Sekolah</label>
                    <input type="text" id="nama_sekolah" name="nama_sekolah" required class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white" placeholder="Nama Sekolah Asal">
                </div>

                <!-- Alamat Sekolah -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-address-book text-primary mr-2"></i>Alamat Sekolah</label>
                    <textarea id="alamat_sekolah" name="alamat_sekolah" required rows="3" class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white" placeholder="Alamat lengkap sekolah (Jalan, Kota, Kode Pos)"></textarea>
                </div>

                <!-- Alamat Rumah -->
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-home text-primary mr-2"></i>Alamat Rumah Peserta</label>
                    <textarea id="alamat_rumah" name="alamat_rumah" required rows="3" class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white" placeholder="Alamat lengkap tempat tinggal saat ini"></textarea>
                </div>

                <!-- Email -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-envelope text-primary mr-2"></i>Email Peserta</label>
                    <input type="email" id="email" name="email" required class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white" placeholder="email@contoh.com">
                </div>

                <!-- No HP -->
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-phone text-primary mr-2"></i>No. HP Peserta</label>
                    <input type="tel" id="no_hp" name="no_hp" required pattern="[0-9]{10,15}" title="Hanya angka, 10-15 digit" class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition bg-gray-50 focus:bg-white" placeholder="08xxxxxxxxxx">
                </div>
            </div>

            <!-- Upload Section -->
            <div class="border-t border-gray-200 my-8 pt-6">
                <h3 class="text-lg font-bold text-primary mb-4"><i class="fas fa-upload mr-2"></i>Dokumen Pendukung</h3>
                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-image text-primary mr-2"></i>Foto Profil (JPG/JPEG/PNG, Maks 2MB)</label>
                        <input type="file" id="foto_profil" name="foto_profil" accept=".jpg,.jpeg,.png" required class="track-progress block w-full text-sm text-gray-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-secondary cursor-pointer border border-gray-300 rounded-lg bg-gray-50">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-receipt text-primary mr-2"></i>Bukti Pembayaran (PDF, Maks 2MB)</label>
                        <input type="file" id="bukti_bayar" name="bukti_bayar" accept=".pdf" required class="track-progress block w-full text-sm text-gray-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-secondary cursor-pointer border border-gray-300 rounded-lg bg-gray-50">
                    </div>
                </div>
            </div>

            <!-- Identitas Sekolah Section -->
            <div class="border-t border-gray-200 my-8 pt-6">
                <h3 class="text-lg font-bold text-primary mb-4"><i class="fas fa-id-badge mr-2"></i>Identitas Sekolah <span class="text-sm font-normal text-gray-600">(Pilih salah satu)</span></h3>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4 rounded">
                    <p class="text-sm text-yellow-800"><i class="fas fa-info-circle mr-2"></i><strong>Wajib:</strong> Upload salah satu dari dua dokumen berikut untuk memverifikasi status pelajar Anda.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 hover:border-primary transition">
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><i class="fas fa-id-card text-primary mr-2"></i>Kartu Pelajar</label>
                        <input type="file" id="kartu_pelajar" name="kartu_pelajar" accept=".jpg,.jpeg,.png,.pdf" class="track-progress block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-white hover:file:bg-secondary cursor-pointer border border-gray-300 rounded-lg bg-gray-50">
                        <p class="text-xs text-gray-500 mt-2">Format: JPG/PNG/PDF, Maks 2MB</p>
                    </div>
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-4 hover:border-primary transition">
                        <label class="block text-sm font-semibold text-gray-700 mb-2"><i class="fas fa-file-alt text-primary mr-2"></i>Surat Keterangan Aktif</label>
                        <input type="file" id="surat_aktif" name="surat_aktif" accept=".jpg,.jpeg,.png,.pdf" class="track-progress block w-full text-sm text-gray-600 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-white hover:file:bg-secondary cursor-pointer border border-gray-300 rounded-lg bg-gray-50">
                        <p class="text-xs text-gray-500 mt-2">Format: JPG/PNG/PDF, Maks 2MB</p>
                    </div>
                </div>
            </div>

            <!-- Jenis Pembayaran Section -->
            <div class="border-t border-gray-200 my-8 pt-6">
                <h3 class="text-lg font-bold text-primary mb-4"><i class="fas fa-money-bill-wave mr-2"></i>Jenis Pembayaran</h3>
                <div class="space-y-3">
                    <label class="flex items-center p-4 border-2 border-gray-200 rounded-lg hover:border-primary hover:bg-gray-50 cursor-pointer transition">
                        <input type="radio" name="jenis_pembayaran" value="Individu" required class="track-progress w-5 h-5 text-primary border-gray-300 focus:ring-primary cursor-pointer">
                        <div class="ml-3">
                            <p class="font-semibold text-gray-800">Individu</p>
                            <p class="text-xs text-gray-500">Pendaftaran perorangan</p>
                        </div>
                    </label>
                    <label class="flex items-center p-4 border-2 border-gray-200 rounded-lg hover:border-primary hover:bg-gray-50 cursor-pointer transition">
                        <input type="radio" name="jenis_pembayaran" value="Kolektif 1 (3-5 orang)" required class="track-progress w-5 h-5 text-primary border-gray-300 focus:ring-primary cursor-pointer">
                        <div class="ml-3">
                            <p class="font-semibold text-gray-800">Kolektif 1</p>
                            <p class="text-xs text-gray-500">Pendaftaran rombongan 3-5 orang</p>
                        </div>
                    </label>
                    <label class="flex items-center p-4 border-2 border-gray-200 rounded-lg hover:border-primary hover:bg-gray-50 cursor-pointer transition">
                        <input type="radio" name="jenis_pembayaran" value="Kolektif 2 (lebih dari 6 orang)" required class="track-progress w-5 h-5 text-primary border-gray-300 focus:ring-primary cursor-pointer">
                        <div class="ml-3">
                            <p class="font-semibold text-gray-800">Kolektif 2</p>
                            <p class="text-xs text-gray-500">Pendaftaran rombongan lebih dari 6 orang</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Akun Ujian Section -->
            <div class="border-t border-gray-200 my-8 pt-6">
                <div class="bg-blue-50 border-l-4 border-primary p-4 mb-5 rounded">
                    <h3 class="text-lg font-bold text-primary"><i class="fas fa-user-shield mr-2"></i>Akun Ujian</h3>
                    <p class="text-sm text-gray-600 mt-1">Anda wajib membuat akun ujian. Jaga kerahasiaan akun ujian Anda!</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Username</label>
                        <input type="text" id="exam_username" name="exam_username" required minlength="4" pattern="[a-zA-Z0-9_]+" autocomplete="username"
                               class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary outline-none"
                               placeholder="Minimal 4 karakter: huruf, angka, atau underscore">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Password</label>
                        <input type="password" id="exam_password" name="exam_password" required minlength="6" autocomplete="new-password"
                               class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary outline-none"
                               placeholder="Minimal 6 karakter">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">Konfirmasi Password</label>
                        <input type="password" id="exam_confirm" name="exam_confirm" required minlength="6" autocomplete="new-password"
                               class="track-progress w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary outline-none"
                               placeholder="Ulangi password">
                    </div>
                </div>
            </div>

            <!-- Captcha & Pernyataan -->
            <div class="border-t border-gray-200 my-8 pt-6 space-y-6">
                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 flex flex-col md:flex-row md:items-center gap-4">
<label class="block text-sm font-mono font-semibold text-gray-700 mb-1"><i class="fas fa-robot text-primary mr-2"></i>Bot Verification</label>
                    <div class="flex-shrink-0">
                        <span class="text-lg font-bold text-primary bg-white px-4 py-2 rounded border border-gray-300 select-none shadow-sm">
                            <?= $_SESSION['captcha_num1'] ?> + <?= $_SESSION['captcha_num2'] ?> = ?
                        </span>
                    </div>
                    <div class="flex-grow">
                        
                        <input type="number" id="captcha_answer" name="captcha_answer" required class="track-progress w-full md:w-32 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition" placeholder="Jawab">
                    </div>
                </div>

                <div class="flex items-start">
                    <div class="flex items-center h-5">
                        <input id="pernyataan" name="pernyataan" type="checkbox" required class="track-progress w-5 h-5 text-secondary border-gray-300 rounded focus:ring-secondary cursor-pointer">
                    </div>
                    <div class="ml-3 text-sm">
                        <label for="pernyataan" class="font-medium text-gray-700 cursor-pointer">
                            Saya menyatakan bahwa data yang saya kirimkan adalah <strong>benar dan dapat dipertanggungjawabkan</strong>. Jika di kemudian hari ditemukan ketidaksesuaian, saya bersedia didiskualifikasi.
                        </label>
                    </div>
                </div>
            </div>

            <!-- Submit Button -->
            <div class="mt-8">
                <button type="submit" id="submitBtn" disabled class="w-full bg-gray-400 text-white font-bold py-3.5 px-6 rounded-lg shadow-md transition-all duration-300 flex items-center justify-center space-x-2 cursor-not-allowed">
                    <i class="fas fa-paper-plane"></i>
                    <span>Daftar Sekarang</span>
                </button>
                <p id="btn-hint" class="text-center text-xs text-gray-500 mt-2">Lengkapi semua field (100%) untuk mengaktifkan tombol.</p>
            </div>

        </form>
        
        <footer class="text-center text-gray-500 text-xs mt-8 pb-8">
            &copy; 2026 Panitia Olimpiade Nasional Bahasa Indonesia - HMP IMABINA. <br>Fakultas Keguruan dan Ilmu Pendidikan - Universitas Jember. <br>Hak cipta dilindungi undang-undang.
        </footer>
    </main>

    <!-- Modal Konfirmasi Setelah Daftar -->
    <?php if ($show_modal): ?>
    <div id="successModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 modal-backdrop">
        <div class="bg-white rounded-2xl shadow-2xl max-w-lg w-full relative animate-slide-in">
            <div class="p-6 md:p-8 text-center">
                <div class="w-20 h-20 bg-secondary text-white rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg">
                    <i class="fas fa-check text-4xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-primary mb-2">Pendaftaran & Akun Ujian Berhasil!</h2>
                <p class="text-gray-600 mb-4">Data peserta dan akun ujian sudah tersimpan. Simpan username berikut untuk hari ujian.</p>
                <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6">
                    <p class="text-xs text-gray-500 uppercase tracking-wide">Username akun ujian</p>
                    <p class="text-xl font-bold text-primary font-mono"><?= htmlspecialchars($modal_data['username']) ?></p>
                    <p class="text-xs text-gray-500 mt-1">Password tidak ditampilkan kembali.</p>
                </div>

                <p class="text-sm font-semibold text-gray-700 mb-3">Langkah komunikasi berikutnya:</p>
                <div class="space-y-4 mb-6">
                    <a href="<?= htmlspecialchars($modal_data['wa_url']) ?>" target="_blank" 
                       class="block w-full bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-6 rounded-lg transition shadow-md flex items-center justify-center space-x-2">
                        <i class="fab fa-whatsapp text-xl"></i>
                        <span>1. Konfirmasi ke Silvi</span>
                    </a>
                    <a href="<?= htmlspecialchars($modal_data['wa_nata_url']) ?>" target="_blank" 
                       class="block w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-6 rounded-lg transition shadow-md flex items-center justify-center space-x-2">
                        <i class="fab fa-whatsapp text-xl"></i>
                        <span>2. Konfirmasi ke Nata</span>
                    </a>
                    <p class="text-xs text-gray-500">Format chat: <strong><?= htmlspecialchars($modal_data['jenis_pembayaran']) ?>_<?= htmlspecialchars($modal_data['nama']) ?>_<?= htmlspecialchars($modal_data['sekolah']) ?></strong></p>
                    
                    <a href="<?= htmlspecialchars($modal_data['grup_url']) ?>" target="_blank" 
                       class="block w-full bg-primary hover:bg-secondary text-white font-bold py-3 px-6 rounded-lg transition shadow-md flex items-center justify-center space-x-2">
                        <i class="fas fa-users text-xl"></i>
                        <span>3. Join Grup Olimpiade</span>
                    </a>
                </div>
                
                <button onclick="document.getElementById('successModal').remove()" class="text-gray-500 hover:text-primary text-sm font-semibold">
                    Selesai
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <button id="scrollTopBtn" type="button" aria-label="Kembali ke atas" class="scroll-top-btn rounded-full bg-primary hover:bg-secondary text-white shadow-2xl">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Script Progress Bar & Validasi Client-Side -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            const progressCaption = document.getElementById('progress-caption');
            const submitBtn = document.getElementById('submitBtn');
            const btnHint = document.getElementById('btn-hint');
            const scrollTopBtn = document.getElementById('scrollTopBtn');

            function updateProgress() {
                let filledCount = 0;
                
                const requiredValueIds = [
                    'nama', 'nik', 'tempat_lahir', 'tgl_lahir', 'nama_sekolah',
                    'alamat_sekolah', 'alamat_rumah', 'email', 'no_hp',
                    'exam_username', 'exam_password', 'exam_confirm', 'captcha_answer'
                ];
                const requiredFileIds = ['foto_profil', 'bukti_bayar'];

                requiredValueIds.forEach(id => {
                    const el = document.getElementById(id);
                    if (el && el.value.trim() !== '') filledCount++;
                });

                requiredFileIds.forEach(id => {
                    const el = document.getElementById(id);
                    if (el && el.files.length > 0) filledCount++;
                });
                
                // Checkbox pernyataan
                const pernyataan = document.getElementById('pernyataan');
                if (pernyataan && pernyataan.checked) filledCount++;
                
                // Radio jenis pembayaran (salah satu)
                if (document.querySelector('input[name="jenis_pembayaran"]:checked')) {
                    filledCount++;
                }
                
                // Identitas sekolah (salah satu dari 2)
                const kartu = document.getElementById('kartu_pelajar');
                const surat = document.getElementById('surat_aktif');
                if ((kartu && kartu.files.length > 0) || (surat && surat.files.length > 0)) {
                    filledCount++;
                }

                const totalFields = requiredValueIds.length + requiredFileIds.length + 3;
                const percent = Math.round((filledCount / totalFields) * 100);
                
                progressBar.style.width = percent + '%';
                progressText.innerText = percent + '% Lengkap';
                progressCaption.innerText = percent === 100
                    ? 'Sempurna. Form siap dikirim.'
                    : `${filledCount} dari ${totalFields} bagian sudah terisi.`;
                
                progressBar.className = 'progress-bar h-2 rounded-full ' + 
                    (percent < 40 ? 'bg-red-500' : (percent < 100 ? 'bg-yellow-500' : 'bg-secondary'));

                if (percent === 100) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('bg-gray-400', 'cursor-not-allowed');
                    submitBtn.classList.add('bg-primary', 'hover:bg-secondary', 'cursor-pointer', 'transform', 'hover:-translate-y-0.5');
                    btnHint.classList.add('text-secondary', 'font-semibold');
                    btnHint.innerText = "Semua data lengkap! Silakan klik Daftar.";
                } else {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('bg-gray-400', 'cursor-not-allowed');
                    submitBtn.classList.remove('bg-primary', 'hover:bg-secondary', 'cursor-pointer', 'transform', 'hover:-translate-y-0.5');
                    btnHint.classList.remove('text-secondary', 'font-semibold');
                    btnHint.innerText = "Lengkapi semua field (100%) untuk mengaktifkan tombol.";
                }
            }

            // Event listeners untuk semua field
            document.querySelectorAll('input, textarea').forEach(el => {
                el.addEventListener('input', updateProgress);
                el.addEventListener('change', updateProgress);
            });

            const examPassword = document.getElementById('exam_password');
            const examConfirm = document.getElementById('exam_confirm');
            function validatePasswordConfirmation() {
                if (!examConfirm) return;
                examConfirm.setCustomValidity(
                    examPassword && examConfirm.value !== examPassword.value
                        ? 'Konfirmasi password tidak cocok.'
                        : ''
                );
            }
            examPassword?.addEventListener('input', validatePasswordConfirmation);
            examConfirm?.addEventListener('input', validatePasswordConfirmation);
            validatePasswordConfirmation();
            updateProgress();

            function toggleScrollTop() {
                scrollTopBtn.classList.toggle('is-visible', window.scrollY > 420);
            }

            scrollTopBtn.addEventListener('click', function() {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });

            window.addEventListener('scroll', toggleScrollTop, { passive: true });
            toggleScrollTop();
        });

        function showExistingAlert(message, type = 'error') {
            const cls = type === 'success'
                ? 'bg-green-50 border-secondary text-secondary'
                : 'bg-red-50 border-red-500 text-red-700';
            document.getElementById('existing-alert').innerHTML = `<div class="${cls} border-l-4 p-3 mb-4 rounded text-sm">${message}</div>`;
        }

        document.getElementById('existingVerifyForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            document.getElementById('existing-alert').innerHTML = '';
            const fd = new FormData();
            fd.append('action', 'verify_account');
            fd.append('email', document.getElementById('ex_email').value);
            fd.append('nik', document.getElementById('ex_nik').value);
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const result = await res.json();
                if (result.success) {
                    document.getElementById('ex_info_nama').innerText = result.nama;
                    document.getElementById('ex_info_sekolah').innerText = result.sekolah;
                    document.getElementById('existingCreateForm').classList.remove('hidden');
                    showExistingAlert('Verifikasi berhasil. Silakan buat username dan password.', 'success');
                } else {
                    showExistingAlert(result.error || 'Verifikasi gagal.');
                }
            } catch (err) {
                showExistingAlert('Terjadi kesalahan koneksi.');
            }
        });

        document.getElementById('existingCreateForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            const password = document.getElementById('ex_password').value;
            const confirm = document.getElementById('ex_confirm').value;
            if (password !== confirm) {
                showExistingAlert('Konfirmasi password tidak cocok.');
                return;
            }

            const fd = new FormData();
            fd.append('action', 'create_account');
            fd.append('email', document.getElementById('ex_email').value);
            fd.append('nik', document.getElementById('ex_nik').value);
            fd.append('username', document.getElementById('ex_username').value);
            fd.append('password', password);
            fd.append('confirm_password', confirm);
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd });
                const result = await res.json();
                if (result.success) {
                    document.getElementById('existingCreateForm').classList.add('hidden');
                    showExistingAlert(`Akun ujian berhasil dibuat. Username: ${result.username}`, 'success');
                } else {
                    showExistingAlert(result.error || 'Gagal membuat akun.');
                }
            } catch (err) {
                showExistingAlert('Terjadi kesalahan koneksi.');
            }
        });
    </script>
<script>document.addEventListener("DOMContentLoaded",()=>{document.addEventListener("selectstart",e=>e.preventDefault()),document.addEventListener("contextmenu",e=>e.preventDefault()),document.addEventListener("copy",e=>e.preventDefault()),document.addEventListener("cut",e=>e.preventDefault()),document.addEventListener("paste",e=>e.preventDefault()),document.addEventListener("keydown",e=>{if((e.ctrlKey&&["u","c","v","x","s","a"].includes(e.key.toLowerCase()))||(e.ctrlKey&&e.shiftKey&&["i","j"].includes(e.key.toLowerCase()))||"F12"===e.key)e.preventDefault()})});</script>
</body>
</html>
