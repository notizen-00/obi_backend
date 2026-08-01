<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Olimpiade Nasional Bahasa Indonesia 2026</title>
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
                        gold: '#F7C948'
                    }
                }
            }
        }
    </script>
    <style>
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background:
                radial-gradient(circle at 10% 8%, rgba(247, 201, 72, 0.2), transparent 22rem),
                radial-gradient(circle at 92% 0%, rgba(2, 92, 38, 0.18), transparent 24rem),
                linear-gradient(180deg, #f8fafc 0%, #eef4f7 100%);
        }
        .hero {
            background:
                linear-gradient(135deg, rgba(16, 47, 67, 0.94), rgba(19, 61, 86, 0.9), rgba(2, 92, 38, 0.88)),
                url('overlay.png');
            background-size: cover, 180px;
            background-repeat: no-repeat, repeat;
            background-position: center, center;
        }
        .glass {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.72);
            box-shadow: 0 18px 55px rgba(16, 47, 67, 0.14);
            backdrop-filter: blur(14px);
        }
        .nav-link { transition: color .2s ease, background-color .2s ease; }
        .nav-link.is-active {
            color: #fff;
            background: #133D56;
            box-shadow: 0 10px 24px rgba(19, 61, 86, 0.18);
        }
        .section-title { letter-spacing: -0.02em; }
        .scroll-top {
            position: fixed;
            right: 1rem;
            bottom: 1rem;
            z-index: 60;
            width: 3rem;
            height: 3rem;
            opacity: 0;
            pointer-events: none;
            transform: translateY(14px) scale(.96);
            transition: opacity .25s ease, transform .25s ease, background-color .25s ease;
        }
        .scroll-top.is-visible {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0) scale(1);
        }
    </style>
</head>
<body class="text-gray-800 font-sans antialiased">
    <header class="sticky top-0 z-50 bg-white/90 backdrop-blur border-b border-gray-100">
        <nav class="max-w-7xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
            <a href="#" class="flex items-center gap-3 min-w-0">
                <img src="/../obi-logo.png" alt="Logo OBI" class="w-11 h-11 md:w-12 md:h-12 rounded-full bg-white object-contain shadow p-1 flex-shrink-0">
                <div class="min-w-0">
                    <p class="font-black text-primary leading-tight truncate">OBI 2026</p>
                    <p class="text-[11px] text-gray-500 leading-tight hidden sm:block">Universitas Jember</p>
                </div>
            </a>
            <button id="menuBtn" class="md:hidden w-10 h-10 rounded-lg bg-gray-100 text-primary" aria-label="Buka menu">
                <i class="fas fa-bars"></i>
            </button>
            <div id="menu" class="hidden md:flex absolute md:static left-4 right-4 top-[4.5rem] md:top-auto bg-white md:bg-transparent rounded-2xl md:rounded-none shadow-xl md:shadow-none border md:border-0 border-gray-100 p-3 md:p-0 flex-col md:flex-row items-stretch md:items-center gap-1 md:gap-2">
                <a class="nav-link px-4 py-2 rounded-lg hover:bg-gray-100 hover:text-primary font-semibold text-sm" href="#profil">Profil</a>
                <a class="nav-link px-4 py-2 rounded-lg hover:bg-gray-100 hover:text-primary font-semibold text-sm" href="#kisi-kisi">Kisi-kisi</a>
                <a class="nav-link px-4 py-2 rounded-lg hover:bg-gray-100 hover:text-primary font-semibold text-sm" href="#petunjuk">Petunjuk Pelaksanaan</a>
                <a class="nav-link px-4 py-2 rounded-lg hover:bg-gray-100 hover:text-primary font-semibold text-sm" href="#video">Video Panduan</a>
                <a class="nav-link px-4 py-2 rounded-lg hover:bg-gray-100 hover:text-primary font-semibold text-sm" href="#twibbonz">Twibbonz</a>
                <a class="nav-link px-4 py-2 rounded-lg hover:bg-gray-100 hover:text-primary font-semibold text-sm" href="#arena">Arena</a>
                <a class="nav-link px-4 py-2 rounded-lg hover:bg-gray-100 hover:text-primary font-semibold text-sm" href="#kontak">Kontak</a>
            </div>
        </nav>
    </header>

    <main>
        <section class="hero text-white">
            <div class="max-w-7xl mx-auto px-4 py-16 md:py-24 grid grid-cols-1 lg:grid-cols-[1.1fr_0.9fr] gap-10 items-center">
                <div>
                    <div class="flex items-center gap-2 mb-5">
                        <span class="inline-flex items-center gap-2 bg-white/12 border border-white/20 text-white text-xs font-bold px-3 py-1.5 rounded-full">
                            <i class="fas fa-award text-gold"></i>
                            FKIP Universitas Jember
                        </span>
                    </div>
                    <h1 class="text-3xl md:text-5xl font-black leading-tight section-title">
                        Olimpiade Nasional Bahasa Indonesia 2026
                    </h1>
                    <p class="mt-5 text-base md:text-xl text-gray-100 max-w-2xl leading-relaxed">
                        Ruang kompetisi akademik untuk mengasah nalar, literasi, dan kecintaan peserta terhadap Bahasa Indonesia.
                    </p>
                    <div class="mt-8 flex flex-col sm:flex-row gap-3">
                        <a href="#aksi" class="bg-gold hover:bg-yellow-300 text-ink font-black px-6 py-3 rounded-xl shadow-lg text-center">
                            Daftar Sekarang
                        </a>
                        <a href="/1vs/" class="bg-white/12 hover:bg-white/20 border border-white/20 text-white font-bold px-6 py-3 rounded-xl text-center">
                            <i class="fas fa-users mr-2"></i>Arena Peserta
                        </a>
                    </div>
                </div>
                <div class="glass rounded-3xl p-5 md:p-6 text-gray-800">
                    <div class="grid grid-cols-4 gap-3 mb-5">
                        <img src="/../obi-logo.png" alt="Logo OBI" class="w-full aspect-square rounded-2xl bg-white object-contain p-2 shadow">
                        <img src="/../unejmask.png" alt="Logo UNEJ" class="w-full aspect-square rounded-2xl bg-white object-contain p-2 shadow">
                        <img src="/../fkipmask.png" alt="Logo FKIP" class="w-full aspect-square rounded-2xl bg-white object-contain p-2 shadow">
                        <img src="/../imabinamask.png" alt="Logo IMABINA" class="w-full aspect-square rounded-2xl bg-white object-contain p-2 shadow">
                    </div>
                    <div class="bg-primary text-white rounded-2xl p-5">
                        <p class="text-xs uppercase tracking-widest text-gray-200">Informasi Utama</p>
                        <div class="grid grid-cols-2 gap-4 mt-4">
                            <div>
                                <p class="text-3xl font-black">Puluhan Juta</p>
                                <p class="text-xs text-gray-200">Total Hadiah</p>
                            </div>
                            <div>
                                <p class="text-3xl font-black">120</p>
                                <p class="text-xs text-gray-200">Menit Kompetisi</p>
                            </div>
                            <div>
                                <p class="text-3xl font-black">Indonesia</p>
                                <p class="text-xs text-gray-200">Olimpiade Nasional</p>
                            </div>
                            <div>
                                <p class="text-3xl font-black">Online</p>
                                <p class="text-xs text-gray-200">Mode Ujian</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section id="profil" class="max-w-7xl mx-auto px-4 py-14 md:py-20">
            <div class="grid grid-cols-1 lg:grid-cols-[0.8fr_1.2fr] gap-8 items-start">
                <div class="lg:sticky lg:top-24">
                    <p class="text-sm font-black text-secondary uppercase tracking-widest">Profil</p>
                    <h2 class="text-3xl md:text-5xl font-black text-primary mt-2 section-title">Profil Olimpiade Bahasa Indonesia</h2>
                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="bg-white rounded-2xl p-4 border border-gray-100 shadow-sm">
                            <p class="text-3xl font-black text-primary">2016</p>
                            <p class="text-xs text-gray-500">Pertama diselenggarakan</p>
                        </div>
                        <div class="bg-white rounded-2xl p-4 border border-gray-100 shadow-sm">
                            <p class="text-3xl font-black text-secondary">10</p>
                            <p class="text-xs text-gray-500">Tahun penyelenggaraan</p>
                        </div>
                    </div>
                </div>
                <article class="bg-white rounded-3xl p-6 md:p-8 shadow-sm border border-gray-100 text-gray-700 leading-relaxed space-y-5">
                    <p>Olimpiade Bahasa Indonesia yang kerap dikenal dengan OBI merupakan ajang kompetisi tingkat nasional yang diselenggarakan setiap tahun oleh Himpunan Mahasiswa Program Studi Pendidikan Bahasa dan Sastra Indonesia (IMABINA), Fakultas Keguruan dan Ilmu Pendidikan, Universitas Jember. OBI pertama kali diselenggarakan pada tahun 2016 sebagai wadah pengembangan potensi, kreativitas, dan kemampuan berbahasa siswa-siswi tingkat SMA/SMK/MA.</p>
                    <p>Pada awal penyelenggaraannya, OBI diikuti oleh peserta dari wilayah Jawa Timur dan Bali. Seiring berjalannya waktu, OBI terus mengalami perkembangan hingga meningkatkan cakupan penyelenggaraannya menjadi ajang tingkat nasional. Pada tahun 2026, OBI memasuki tahun ke-10 penyelenggaraan dan dapat diikuti oleh peserta dari berbagai daerah di seluruh Indonesia.</p>
                    <p>OBI tidak sekadar menjadi lomba, tetapi juga menjadi perayaan intelektual dan budaya, tempat di mana bahasa Indonesia tidak hanya digunakan sebagai alat komunikasi, tetapi juga sebagai media ekspresi artistik, pemikiran kritis, dan identitas kebangsaan. Dalam suasana yang kompetitif sekaligus inspiratif, peserta diajak untuk menggali kedalaman makna bahasa, memperhalus rasa sastra, dan menunjukkan kemampuan mereka dalam berbagai cabang lomba yang disiapkan dengan serius dan profesional oleh panitia.</p>
                    <p>Tahun ini, babak semifinal OBI 2026 menghadirkan lomba bercerita dan babak final menghadirkan lomba monolog sebagai tantangan utama bagi para peserta. Melalui monolog, peserta ditantang untuk memerankan teks naratif secara dramatik, dengan penghayatan mendalam dan teknik keaktoran yang matang. Monolog menjadi sarana untuk menyuarakan isu-isu sosial, kebudayaan, dan kenegaraan dalam bentuk yang reflektif sekaligus menyentuh.</p>
                    <p>Lebih dari sekadar kompetisi, OBI berupaya membentuk generasi muda yang mencintai bahasa dan sastra Indonesia, memiliki kecakapan literasi yang tinggi, serta mampu berkontribusi dalam pelestarian dan pengembangan budaya bangsa. Melalui OBI, Universitas Jember berkomitmen menjadikan bahasa Indonesia bukan hanya sebagai mata pelajaran di sekolah, tetapi sebagai kekuatan pemersatu, sumber inspirasi, dan jembatan menuju masa depan.</p>
                </article>
            </div>
        </section>

        <section id="kisi-kisi" class="bg-white py-14 md:py-20">
            <div class="max-w-7xl mx-auto px-4">
                <div class="grid grid-cols-1 lg:grid-cols-[1fr_0.38fr] gap-6 lg:items-end mb-8">
                    <div>
                        <p class="text-sm font-black text-secondary uppercase tracking-widest">Kisi-kisi</p>
                        <h2 class="text-3xl md:text-5xl font-black text-primary mt-2 section-title">Fokus belajar sebelum masuk arena OBI.</h2>
                        <p class="text-gray-600 mt-4">Gunakan kisi-kisi ini sebagai arah persiapan umum. Detail teknis ujian tetap mengikuti panduan resmi panitia.</p>
                    </div>
                    <a href="kisi-kisi-OBI-2026.pdf" target="_blank" rel="noopener" class="bg-primary hover:bg-secondary text-white rounded-2xl p-5 text-center shadow-lg transition">
                        <i class="fas fa-file-download text-4xl text-gold"></i>
                        <p class="font-black mt-3">Download Kisi-kisi PDF</p>
                        <p class="text-xs text-gray-200 mt-1">File resmi OBI 2026</p>
                    </a>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                        <i class="fas fa-language text-3xl text-primary"></i>
                        <h3 class="font-black text-primary mt-4">Kebahasaan</h3>
                        <p class="text-sm text-gray-600 mt-2">Ejaan, diksi, kalimat efektif, paragraf, dan kaidah Bahasa Indonesia.</p>
                    </div>
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                        <i class="fas fa-book-reader text-3xl text-secondary"></i>
                        <h3 class="font-black text-primary mt-4">Pemahaman Bacaan</h3>
                        <p class="text-sm text-gray-600 mt-2">Menemukan gagasan utama, inferensi, simpulan, dan informasi tersirat.</p>
                    </div>
                    <div class="rounded-2xl border border-gray-100 bg-gray-50 p-5">
                        <i class="fas fa-pen-nib text-3xl text-primary"></i>
                        <h3 class="font-black text-primary mt-4">Sastra & Wacana</h3>
                        <p class="text-sm text-gray-600 mt-2">Unsur karya sastra, analisis teks, dan ketepatan penggunaan bahasa dalam konteks.</p>
                    </div>
                </div>
            </div>
        </section>

        <section id="petunjuk" class="max-w-7xl mx-auto px-4 py-14 md:py-20">
            <div class="rounded-[2rem] bg-gradient-to-br from-primary via-ink to-secondary text-white p-6 md:p-10 shadow-2xl overflow-hidden relative">
                <div class="absolute inset-0 opacity-10 bg-[url('/../obi-logo.png')] bg-[length:120px_120px] rotate-[-6deg] scale-125"></div>
                <div class="relative grid grid-cols-1 lg:grid-cols-[1fr_0.75fr] gap-8 items-center">
                    <div>
                        <p class="text-sm font-black text-gold uppercase tracking-widest">Petunjuk Pelaksanaan</p>
                        <h2 class="text-3xl md:text-5xl font-black mt-2 section-title">Baca panduan resmi sebelum mengikuti rangkaian OBI.</h2>
                        <p class="text-gray-100 mt-4 leading-relaxed">Unduh dan pelajari petunjuk pelaksanaan dalam format PDF agar memahami ketentuan, alur, serta teknis kegiatan secara lengkap.</p>
                    </div>
                    <a href="juklak-juknis-OBI-2026.pdf" target="_blank" rel="noopener" class="bg-white text-primary hover:bg-gold rounded-2xl p-6 text-center shadow-lg transition">
                        <i class="fas fa-file-pdf text-5xl text-red-600"></i>
                        <p class="font-black text-xl mt-3">Buka PDF Petunjuk</p>
                        <!-- <p class="text-sm text-gray-500 mt-1">Ganti file ini dengan PDF resmi panitia.</p> -->
                    </a>
                </div>
            </div>
        </section>

        <section id="video" class="py-14 md:py-20">
            <div class="max-w-7xl mx-auto px-4 grid grid-cols-1 lg:grid-cols-2 gap-8 items-center">
                <a href="https://drive.google.com/file/d/1MxdB15xgUejiR95FLeu4Ap21vmhhAthE/view?usp=drive_link" target="_blank" rel="noopener" class="rounded-3xl bg-gray-950 aspect-video flex items-center justify-center text-white shadow-2xl overflow-hidden relative group">
                    <div class="absolute inset-0 opacity-20 bg-[url('/../obi-logo.png')] bg-[length:120px_120px]"></div>
                    <div class="relative text-center">
                        <div class="w-20 h-20 bg-white text-primary rounded-full flex items-center justify-center mx-auto shadow-xl group-hover:scale-105 transition">
                            <i class="fas fa-play text-2xl ml-1"></i>
                        </div>
                        <p class="font-black mt-4">Video Panduan Peserta</p>
                        <p class="text-sm text-gray-300">Klik untuk membuka video panduan resmi.</p>
                    </div>
                </a>
                <div>
                    <p class="text-sm font-black text-secondary uppercase tracking-widest">Video Panduan</p>
                    <h2 class="text-3xl md:text-5xl font-black text-primary mt-2 section-title">Pahami alur sebelum berkompetisi.</h2>
                    <p class="text-gray-600 mt-4 leading-relaxed">Peserta dapat mempelajari cara registrasi, membuat akun ujian, login OBI EXAM, mengerjakan soal, dan mengisi survey setelah ujian.</p>
                </div>
            </div>
        </section>

        <section id="twibbonz" class="max-w-7xl mx-auto px-4 py-14 md:py-20">
            <div class="glass rounded-3xl p-6 md:p-10 grid grid-cols-1 lg:grid-cols-[1fr_0.8fr] gap-8 items-center">
                <div>
                    <p class="text-sm font-black text-secondary uppercase tracking-widest">Twibbonz</p>
                    <h2 class="text-3xl md:text-5xl font-black text-primary mt-2 section-title">Tunjukkan semangatmu sebagai peserta OBI.</h2>
                    <p class="text-gray-600 mt-4">"Saya Siap Mengikuti Olimpiade Bahasa Indonesia 2026!"</p>
                </div>
                <a href="https://twb.nz/olimpiadebahasaindonesia26" target="_blank" rel="noopener" class="bg-primary hover:bg-secondary text-white rounded-2xl p-6 text-center shadow-lg transition">
                    <i class="fas fa-image text-4xl text-gold"></i>
                    <p class="font-black text-xl mt-3">Buka Twibbonz</p>
                    <p class="text-sm text-gray-200 mt-1">Gunakan twibbon resmi OBI 2026.</p>
                </a>
            </div>
        </section>

        <section id="arena" class="bg-primary text-white py-14 md:py-20">
            <div class="max-w-7xl mx-auto px-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <p class="text-sm font-black text-gold uppercase tracking-widest">Arena</p>
                    <h2 class="text-3xl md:text-5xl font-black mt-2 section-title">Lihat peserta yang sudah siap masuk arena.</h2>
                    <!-- <p class="text-gray-200 mt-4 max-w-2xl">Halaman arena menampilkan peserta dengan pembayaran lunas dalam format publik yang tetap menjaga privasi.</p> -->
                </div>
                <div class="flex items-center md:justify-end">
                    <a href="/1vs/" class="w-full md:w-auto bg-white text-primary hover:bg-gold font-black px-7 py-4 rounded-xl text-center shadow-lg">
                        Masuk Arena
                    </a>
                </div>
            </div>
        </section>

        <section id="aksi" class="max-w-7xl mx-auto px-4 py-14 md:py-20">
            <div class="rounded-[2rem] bg-gradient-to-br from-primary to-secondary text-white p-6 md:p-12 shadow-2xl">
                <div class="grid grid-cols-1 lg:grid-cols-[1fr_0.85fr] gap-8 items-center">
                    <div>
                        <p class="text-sm font-black text-gold uppercase tracking-widest">PENDAFTARAN</p>
                        <h2 class="text-3xl md:text-5xl font-black mt-2 section-title">Siap berkompetisi pada OBI 2026?</h2>
                        <!-- <p class="text-gray-100 mt-4">Mulai dari registrasi, konfirmasi pembayaran, sampai pembuatan akun ujian dilakukan melalui sistem resmi ini.</p> -->
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-1 gap-3">
                        <a href="/gate/?next=reg" class="bg-gold hover:bg-yellow-300 text-ink font-black px-6 py-4 rounded-xl text-center shadow-lg">
                            Daftar Sekarang
                        </a>
                        <a href="/gate/?next=akun" class="bg-white/12 hover:bg-white/20 border border-white/25 text-white font-black px-6 py-4 rounded-xl text-center">
                            Sudah Daftar, Belum Punya Akun?
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section id="kontak" class="bg-white py-14 md:py-20">
            <div class="max-w-7xl mx-auto px-4 grid grid-cols-1 lg:grid-cols-[0.8fr_1.2fr] gap-8">
                <div>
                    <p class="text-sm font-black text-secondary uppercase tracking-widest">Kontak</p>
                    <h2 class="text-3xl md:text-5xl font-black text-primary mt-2 section-title">Butuh bantuan?</h2>
                    <p class="text-gray-600 mt-4">Hubungi panitia melalui kanal resmi berikut.</p>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <a href="https://wa.me/6281244120600" class="border border-gray-100 bg-gray-50 hover:bg-green-50 rounded-2xl p-5 transition">
                        <i class="fab fa-whatsapp text-3xl text-secondary"></i>
                        <p class="font-black text-primary mt-3">Silvi</p>
                        <p class="text-sm text-gray-500">Konfirmasi dan informasi peserta.</p>
                    </a>
                    <a href="https://wa.me/6281234771917" class="border border-gray-100 bg-gray-50 hover:bg-green-50 rounded-2xl p-5 transition">
                        <i class="fab fa-whatsapp text-3xl text-secondary"></i>
                        <p class="font-black text-primary mt-3">Nata</p>
                        <p class="text-sm text-gray-500">Konfirmasi dan bantuan teknis.</p>
                    </a>
                </div>
            </div>
        </section>
    </main>

    <footer class="bg-ink text-white">
        <div class="max-w-7xl mx-auto px-4 py-8 flex flex-col md:flex-row gap-4 md:items-center md:justify-between text-sm text-gray-300">
            <p>&copy; 2026 Olimpiade Bahasa Indonesia - FKIP Universitas Jember.</p>
            <p>HMP IMABINA</p>
        </div>
    </footer>

    <button id="scrollTopBtn" type="button" aria-label="Kembali ke atas" class="scroll-top rounded-full bg-primary hover:bg-secondary text-white shadow-2xl">
        <i class="fas fa-arrow-up"></i>
    </button>

    <script>
        const menuBtn = document.getElementById('menuBtn');
        const menu = document.getElementById('menu');
        const scrollTopBtn = document.getElementById('scrollTopBtn');
        const navLinks = Array.from(document.querySelectorAll('.nav-link'));
        const sections = navLinks
            .map((link) => document.querySelector(link.getAttribute('href')))
            .filter(Boolean);

        menuBtn?.addEventListener('click', () => menu.classList.toggle('hidden'));
        menu?.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) menu.classList.add('hidden');
            });
        });

        function setActiveNav(id) {
            navLinks.forEach((link) => {
                link.classList.toggle('is-active', link.getAttribute('href') === `#${id}`);
            });
        }

        const observer = new IntersectionObserver((entries) => {
            const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
            if (visible) setActiveNav(visible.target.id);
        }, {
            rootMargin: '-28% 0px -58% 0px',
            threshold: [0.15, 0.3, 0.55]
        });

        sections.forEach((section) => observer.observe(section));

        function toggleScrollTop() {
            scrollTopBtn.classList.toggle('is-visible', window.scrollY > 520);
        }

        scrollTopBtn?.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        window.addEventListener('scroll', toggleScrollTop, { passive: true });
        toggleScrollTop();
    </script>
<script>document.addEventListener("DOMContentLoaded",()=>{document.addEventListener("selectstart",e=>e.preventDefault()),document.addEventListener("contextmenu",e=>e.preventDefault()),document.addEventListener("copy",e=>e.preventDefault()),document.addEventListener("cut",e=>e.preventDefault()),document.addEventListener("paste",e=>e.preventDefault()),document.addEventListener("keydown",e=>{if((e.ctrlKey&&["u","c","v","x","s","a"].includes(e.key.toLowerCase()))||(e.ctrlKey&&e.shiftKey&&["i","j"].includes(e.key.toLowerCase()))||"F12"===e.key)e.preventDefault()})});</script>
</body>
</html>
