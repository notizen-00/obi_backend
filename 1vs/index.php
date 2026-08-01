<?php
declare(strict_types=1);

require_once __DIR__ . '/../db/mysql.php';

function school_identity_key(string $school): string
{
    $school = trim(preg_replace('/\s+/u', ' ', $school) ?? $school);
    if (function_exists('mb_strtoupper')) {
        $school = mb_strtoupper($school, 'UTF-8');
    } else {
        $school = strtoupper($school);
    }

    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $school) ?? $school);
}

$schools = [];
$total_participants = 0;
$error = '';

try {
    $total_participants = (int)db()
        ->query("SELECT COUNT(*) FROM participants WHERE status_bayar = 'lunas'")
        ->fetchColumn();

    // Data personal tidak diambil. Arena hanya memerlukan institusi asal.
    $stmt = db()->query(
        "SELECT DISTINCT TRIM(nama_sekolah) AS nama_sekolah
         FROM participants
         WHERE status_bayar = 'lunas'
           AND nama_sekolah IS NOT NULL
           AND TRIM(nama_sekolah) <> ''
         ORDER BY nama_sekolah ASC"
    );

    $unique_schools = [];
    foreach ($stmt->fetchAll() as $row) {
        $school = trim((string)($row['nama_sekolah'] ?? ''));
        $key = school_identity_key($school);
        if ($school !== '' && $key !== '' && !isset($unique_schools[$key])) {
            $unique_schools[$key] = $school;
        }
    }
    $schools = array_values($unique_schools);
} catch (Throwable $e) {
    $error = 'Daftar delegasi sekolah belum dapat dimuat. Silakan akses kembali beberapa saat lagi.';
}

$total_schools = count($schools);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Daftar sekolah asal peserta Olimpiade Bahasa Indonesia 2026 dengan pembayaran lunas.">
    <title>OBI Arena 2026 — Delegasi Sekolah Nasional</title>

    <link rel="icon" type="image/png" href="/../unejmask.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#133D56',
                        secondary: '#025C26',
                        ink: '#102F43',
                        gold: '#F7C948',
                        paper: '#F4F7F8'
                    }
                }
            }
        }
    </script>

    <style>
        :root {
            --obi-primary: #133d56;
            --obi-ink: #102f43;
            --obi-green: #025c26;
            --obi-gold: #f7c948;
            --obi-paper: #f4f7f8;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background:
                radial-gradient(circle at 8% 14%, rgba(247, 201, 72, 0.16), transparent 23rem),
                radial-gradient(circle at 94% 7%, rgba(2, 92, 38, 0.12), transparent 28rem),
                linear-gradient(180deg, #eef4f6 0%, #f9fbfc 38%, #edf3f4 100%);
        }

        .arena-hero {
            isolation: isolate;
            background:
                linear-gradient(115deg, rgba(11, 39, 55, 0.98) 0%, rgba(19, 61, 86, 0.96) 49%, rgba(2, 92, 38, 0.92) 100%);
            box-shadow: 0 28px 80px rgba(16, 47, 67, 0.28);
        }

        .arena-hero::before {
            content: '';
            position: absolute;
            inset: 0;
            z-index: -2;
            opacity: 0.2;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.08) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to bottom, black, transparent 88%);
        }

        .arena-hero::after {
            content: '';
            position: absolute;
            width: 30rem;
            height: 30rem;
            right: -12rem;
            top: -17rem;
            z-index: -1;
            border: 1px solid rgba(247, 201, 72, 0.38);
            border-radius: 999px;
            box-shadow:
                0 0 0 42px rgba(247, 201, 72, 0.05),
                0 0 0 84px rgba(255, 255, 255, 0.035);
            animation: orbit 14s linear infinite;
        }

        .hero-beam {
            position: absolute;
            left: 8%;
            right: 8%;
            bottom: 0;
            height: 1px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.1);
        }

        .hero-beam::after {
            content: '';
            position: absolute;
            inset: 0;
            width: 28%;
            background: linear-gradient(90deg, transparent, var(--obi-gold), transparent);
            animation: beam 5s ease-in-out infinite;
        }

        .glass-panel {
            border: 1px solid rgba(255, 255, 255, 0.74);
            background: rgba(255, 255, 255, 0.88);
            box-shadow: 0 20px 55px rgba(16, 47, 67, 0.12);
            backdrop-filter: blur(16px);
        }

        .logo-medallion {
            position: relative;
        }

        .logo-medallion::after {
            content: '';
            position: absolute;
            inset: -5px;
            z-index: -1;
            border: 1px solid rgba(247, 201, 72, 0.58);
            border-radius: 999px;
            animation: pulse-ring 3.2s ease-in-out infinite;
        }

        .metric-card {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: rgba(255, 255, 255, 0.09);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.14);
            backdrop-filter: blur(10px);
        }

        .metric-card::after {
            content: '';
            position: absolute;
            width: 5rem;
            height: 5rem;
            right: -2.3rem;
            bottom: -2.7rem;
            border: 1px solid rgba(247, 201, 72, 0.26);
            border-radius: 999px;
        }

        .discipline-strip {
            border-block: 1px solid rgba(19, 61, 86, 0.1);
            background:
                linear-gradient(90deg, rgba(19, 61, 86, 0.04), rgba(2, 92, 38, 0.07), rgba(19, 61, 86, 0.04));
        }

        .school-card {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(19, 61, 86, 0.1);
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 12px 30px rgba(16, 47, 67, 0.08);
            opacity: 0;
            transform: translateY(18px);
            animation: card-entry 0.6s cubic-bezier(.2, .7, .2, 1) forwards;
            transition:
                transform 0.24s ease,
                border-color 0.24s ease,
                box-shadow 0.24s ease;
        }

        .school-card::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: linear-gradient(180deg, var(--obi-gold), var(--obi-green));
            transition: width 0.24s ease;
        }

        .school-card::after {
            content: '';
            position: absolute;
            width: 7rem;
            height: 7rem;
            right: -4.2rem;
            top: -4.4rem;
            border: 1px solid rgba(19, 61, 86, 0.08);
            border-radius: 999px;
        }

        .school-card:hover {
            transform: translateY(-5px);
            border-color: rgba(247, 201, 72, 0.72);
            box-shadow: 0 22px 42px rgba(16, 47, 67, 0.14);
        }

        .school-card:hover::before {
            width: 7px;
        }

        .school-number {
            color: var(--obi-primary);
            background:
                linear-gradient(135deg, rgba(247, 201, 72, 0.28), rgba(247, 201, 72, 0.08));
            border: 1px solid rgba(247, 201, 72, 0.55);
        }


        @keyframes card-entry {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes orbit {
            to {
                transform: rotate(360deg);
            }
        }

        @keyframes beam {
            0%, 100% {
                transform: translateX(-110%);
                opacity: 0;
            }
            18%, 72% {
                opacity: 1;
            }
            80% {
                transform: translateX(360%);
                opacity: 0;
            }
        }

        @keyframes pulse-ring {
            0%, 100% {
                transform: scale(0.96);
                opacity: 0.45;
            }
            50% {
                transform: scale(1.05);
                opacity: 1;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                scroll-behavior: auto !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body class="min-h-screen text-slate-800 antialiased">
    <header class="relative z-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-5">
            <nav class="flex items-center justify-between gap-4" aria-label="Navigasi OBI Arena">
                <a href="../" class="group inline-flex items-center gap-3 text-ink" aria-label="Kembali ke portal OBI 2026">
                    <span class="logo-medallion block rounded-full">
                        <img src="/../obi-logo.png" alt="Logo OBI" class="w-12 h-12 sm:w-14 sm:h-14 object-contain bg-white rounded-full p-1 shadow-lg">
                    </span>
                    <span>
                        <span class="block text-[0.65rem] sm:text-xs uppercase tracking-[0.24em] font-black text-secondary">Olimpiade Bahasa Indonesia</span>
                        <span class="block text-lg sm:text-xl font-black leading-tight">OBI Arena 2026</span>
                    </span>
                </a>

                <div class="hidden sm:flex items-center gap-2">
                    <img src="/../unejmask.png" alt="Universitas Jember" class="w-10 h-10 object-contain bg-white rounded-full p-1 shadow">
                    <img src="/../fkipmask.png" alt="FKIP Universitas Jember" class="w-10 h-10 object-contain bg-white rounded-full p-1 shadow">
                    <img src="/../imabinamask.png" alt="HMP IMABINA" class="w-10 h-10 object-contain bg-white rounded-full p-1 shadow">
                </div>
            </nav>
        </div>
    </header>

    <main class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 pb-14">
        <section class="arena-hero relative overflow-hidden rounded-[1.75rem] sm:rounded-[2.25rem] text-white">
            <div class="relative z-10 grid grid-cols-1 lg:grid-cols-[1.2fr_0.8fr] gap-9 lg:gap-12 items-end px-6 py-10 sm:px-10 sm:py-14 lg:px-14 lg:py-16">
                <div>
                    <div class="inline-flex items-center gap-2.5 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-xs font-extrabold uppercase tracking-[0.18em] backdrop-blur-md">
                        <span class="relative flex h-2.5 w-2.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-gold opacity-70"></span>
                            <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-gold"></span>
                        </span>
                        Daftar Sekolah Peserta
                    </div>

                    <p class="mt-7 text-sm font-black uppercase tracking-[0.32em] text-gold">OBI Arena 2026</p>
                    <h1 class="mt-3 max-w-4xl text-4xl sm:text-5xl lg:text-7xl font-black leading-[1.03] tracking-[-0.04em]">
                        Dari Sekolah,<br>
                        <span class="text-gold">Menuju Arena Nasional.</span>
                    </h1>
                    <p class="mt-6 max-w-2xl text-base sm:text-lg leading-relaxed text-slate-200">
                        Daftar sekolah asal peserta OBI 2026 yang telah diverifikasi oleh panitia.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:gap-4">
                    <div class="metric-card col-span-2 rounded-2xl p-5 sm:p-6">
                        <div class="flex items-end justify-between gap-4">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-300">Sekolah Asal Peserta</p>
                                <p class="mt-2 text-5xl sm:text-6xl font-black text-gold"><?= $total_schools ?></p>
                            </div>
                            <i class="fas fa-school text-3xl text-white/35" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="metric-card rounded-2xl p-4 sm:p-5">
                        <i class="fas fa-user-check text-gold" aria-hidden="true"></i>
                        <p class="mt-3 text-lg font-black"><?= $total_participants ?> Peserta</p>
                        <p class="mt-1 text-xs leading-relaxed text-slate-300">Siap bertanding.</p>
                    </div>
                    <div class="metric-card rounded-2xl p-4 sm:p-5">
                        <i class="fas fa-medal text-gold" aria-hidden="true"></i>
                        <p class="mt-3 text-lg font-black">OBI Ke-10</p>
                        <p class="mt-1 text-xs leading-relaxed text-slate-300">Penyelenggaraan 2026.</p>
                    </div>
                </div>
            </div>
            <div class="hero-beam" aria-hidden="true"></div>
        </section>

        <section class="discipline-strip my-7 rounded-2xl px-5 py-4" aria-label="Nilai kompetisi OBI">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 text-center">
                <div class="flex items-center justify-center gap-2 text-xs sm:text-sm font-extrabold text-primary">
                    <i class="fas fa-pen-nib text-secondary" aria-hidden="true"></i>
                    Nalar Kebahasaan
                </div>
                <div class="flex items-center justify-center gap-2 text-xs sm:text-sm font-extrabold text-primary">
                    <i class="fas fa-book-open-reader text-secondary" aria-hidden="true"></i>
                    Ketajaman Literasi
                </div>
                <div class="flex items-center justify-center gap-2 text-xs sm:text-sm font-extrabold text-primary">
                    <i class="fas fa-masks-theater text-secondary" aria-hidden="true"></i>
                    Apresiasi Sastra
                </div>
                <div class="flex items-center justify-center gap-2 text-xs sm:text-sm font-extrabold text-primary">
                    <i class="fas fa-award text-secondary" aria-hidden="true"></i>
                    Integritas Akademik
                </div>
            </div>
        </section>

        <section class="glass-panel rounded-[1.75rem] sm:rounded-[2rem] p-5 sm:p-8 lg:p-10">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-5 border-b border-slate-200 pb-7">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.26em] text-secondary">Kontingen OBI 2026</p>
                    <h2 class="mt-2 text-3xl sm:text-4xl font-black tracking-[-0.03em] text-ink">Sekolah Asal Peserta</h2>
                </div>
                <div class="grid grid-cols-2 gap-6 sm:gap-8 text-left sm:text-right">
                    <div>
                        <p class="text-3xl font-black text-primary"><?= $total_schools ?></p>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Sekolah tercatat</p>
                    </div>
                    <div>
                        <p class="text-3xl font-black text-secondary"><?= $total_participants ?></p>
                        <p class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Total Peserta</p>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="mt-8 rounded-2xl border border-red-200 bg-red-50 p-6 text-red-700" role="alert">
                    <div class="flex items-start gap-4">
                        <i class="fas fa-triangle-exclamation mt-1 text-xl" aria-hidden="true"></i>
                        <div>
                            <h3 class="font-black">Daftar sekolah belum dapat dimuat</h3>
                            <p class="mt-1 text-sm"><?= htmlspecialchars($error) ?></p>
                        </div>
                    </div>
                </div>
            <?php elseif (empty($schools)): ?>
                <div class="mt-8 rounded-3xl border border-dashed border-slate-300 bg-slate-50 px-6 py-14 text-center">
                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-white text-3xl text-primary shadow-lg">
                        <i class="fas fa-school-circle-exclamation" aria-hidden="true"></i>
                    </div>
                    <h3 class="mt-5 text-2xl font-black text-ink">Belum Ada Sekolah dalam Daftar</h3>
                    <p class="mx-auto mt-2 max-w-xl text-sm leading-relaxed text-slate-500">
                        Daftar akan terisi setelah pembayaran peserta dinyatakan lunas.
                    </p>
                </div>
            <?php else: ?>
                <div class="mt-8 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-5">
                    <?php foreach ($schools as $index => $school): ?>
                        <article class="school-card rounded-2xl p-5 sm:p-6"
                                 style="animation-delay: <?= min($index * 45, 720) ?>ms">
                            <div class="flex items-start gap-4">
                                <div class="school-number flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-sm font-black">
                                    <?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?>
                                </div>
                                <div class="min-w-0 pt-0.5">
                                    <p class="text-[0.65rem] font-black uppercase tracking-[0.2em] text-secondary">Nomor <?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></p>
                                    <h3 class="mt-1.5 text-lg sm:text-xl font-black uppercase leading-snug text-ink">
                                        <?= htmlspecialchars($school) ?>
                                    </h3>
                                </div>
                            </div>
                            <div class="mt-5 flex items-center gap-2 border-t border-slate-100 pt-4 text-xs font-bold text-secondary">
                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-green-50 text-secondary">
                                    <i class="fas fa-check" aria-hidden="true"></i>
                                </span>
                                READY
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <footer class="mt-8 flex flex-col sm:flex-row items-center justify-between gap-4 px-2 text-center sm:text-left text-xs text-slate-500">
            <p>© 2026 Olimpiade Bahasa Indonesia · FKIP Universitas Jember</p>
            <a href="../" class="inline-flex items-center gap-2 font-bold text-primary transition hover:text-secondary">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                Kembali ke Portal OBI
            </a>
        </footer>
    </main>
</body>
</html>
