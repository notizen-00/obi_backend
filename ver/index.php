<?php
session_start();
require_once __DIR__ . '/../db/mysql.php';

// --- 1. HANDLE AJAX REQUESTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    
    // ====== LOGIN ======
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        $stmt = db()->prepare('SELECT * FROM operators WHERE LOWER(username) = LOWER(?) LIMIT 1');
        $stmt->execute([$username]);
        $found = $stmt->fetch();
        
        if ($found && password_verify($password, $found['password_hash'])) {
            $_SESSION['operator'] = [
                'username' => $found['username'],
                'nama' => $found['nama'] ?? $found['username'],
                'login_at' => date('Y-m-d H:i:s')
            ];
            echo json_encode(['success' => true, 'nama' => $_SESSION['operator']['nama']]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Username atau password salah.']);
        }
        exit;
    }
    
    // ====== LOGOUT ======
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }
    
    // ====== CEK SESSION ======
    if (!isset($_SESSION['operator'])) {
        echo json_encode(['success' => false, 'error' => 'Sesi berakhir. Silakan login ulang.']);
        exit;
    }
    
    // ====== UPDATE STATUS BAYAR ======
    if ($action === 'update_status') {
        $reg_id = trim($_POST['reg_id'] ?? '');
        $new_status = trim($_POST['status'] ?? '');
        
        if (!in_array($new_status, ['lunas', 'pending'])) {
            echo json_encode(['success' => false, 'error' => 'Status tidak valid.']);
            exit;
        }
        
        $stmt = db()->prepare('UPDATE participants SET status_bayar = ?, verified_at = NOW(), verified_by = ? WHERE id = ?');
        $stmt->execute([$new_status, $_SESSION['operator']['username'], $reg_id]);
            
        if ($stmt->rowCount() === 0) {
            echo json_encode(['success' => false, 'error' => 'Data peserta tidak ditemukan.']);
            exit;
        }

        $name_stmt = db()->prepare('SELECT nama FROM participants WHERE id = ? LIMIT 1');
        $name_stmt->execute([$reg_id]);
        $participant_name = (string)$name_stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'message' => $new_status === 'lunas' ? 'Status berhasil diubah menjadi LUNAS.' : 'Status dikembalikan ke PENDING.',
                'status' => $new_status,
                'nama' => $participant_name
            ]);
            exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Action tidak valid.']);
    exit;
}

// --- 2. CHECK LOGIN STATUS ---
$is_logged_in = isset($_SESSION['operator']);
$operator_name = $is_logged_in ? ($_SESSION['operator']['nama'] ?? $_SESSION['operator']['username']) : '';

// --- 3. LOAD DATA PESERTA (jika sudah login) ---
$all_peserta = [];
if ($is_logged_in) {
    $all_peserta = db()->query('SELECT *, registered_at AS timestamp FROM participants ORDER BY registered_at DESC')->fetchAll();
}

// --- 4. FILTER & SEARCH ---
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'pending';
$search = isset($_GET['search']) ? trim(strtolower($_GET['search'])) : '';

$filtered = array_filter($all_peserta, function($row) use ($filter, $search) {
    // Filter by status
    if ($filter === 'pending' && ($row['status_bayar'] ?? 'pending') !== 'pending') return false;
    if ($filter === 'lunas' && ($row['status_bayar'] ?? 'pending') !== 'lunas') return false;
    
    // Search
    if ($search !== '') {
        $match = strpos(strtolower($row['nik'] ?? ''), $search) !== false ||
                 strpos(strtolower($row['nama'] ?? ''), $search) !== false ||
                 strpos(strtolower($row['nama_sekolah'] ?? ''), $search) !== false;
        if (!$match) return false;
    }
    return true;
});
$filtered = array_values($filtered);

// --- 5. PAGINATION ---
$limit = 10;
$total_records = count($filtered);
$total_pages = ceil($total_records / $limit);
$current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
if ($current_page > $total_pages && $total_pages > 0) $current_page = $total_pages;
$offset = ($current_page - 1) * $limit;
$paged_data = array_slice($filtered, $offset, $limit);

// --- 6. STATISTIK ---
$stat_total = count($all_peserta);
$stat_pending = count(array_filter($all_peserta, fn($r) => ($r['status_bayar'] ?? 'pending') === 'pending'));
$stat_lunas = count(array_filter($all_peserta, fn($r) => ($r['status_bayar'] ?? 'pending') === 'lunas'));

function build_url($page, $filter, $search) {
    return '?' . http_build_query(array_filter([
        'page' => $page > 1 ? $page : null,
        'filter' => $filter !== 'all' ? $filter : null,
        'search' => $search !== '' ? $search : null
    ]));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Pembayaran - Panitia OBI 2026</title>
    
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
        .modal-backdrop { background: rgba(19, 61, 86, 0.75); backdrop-filter: blur(4px); }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.3s ease-out; }
        .spinner {
            border: 3px solid rgba(255,255,255,0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            width: 18px; height: 18px;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .status-pulse { animation: pulse 2s infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased min-h-screen">

<?php if (!$is_logged_in): ?>
<!-- ==================== LOGIN PAGE ==================== -->
<div class="min-h-screen flex items-center justify-center p-4 bg-gradient-to-br from-primary to-secondary">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-8 fade-in">
        <div class="text-center mb-6">
            <div class="w-20 h-20 bg-primary text-white rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg">
                <i class="fas fa-user-shield text-3xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-primary">Panel Panitia</h1>
            <p class="text-sm text-gray-500 mt-1">Verifikasi Pembayaran OBI 2026</p>
        </div>
        
        <div id="login-alert"></div>
        
        <form id="loginForm" class="space-y-4">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-user mr-2 text-primary"></i>Username</label>
                <input type="text" id="login-username" required 
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition" 
                       placeholder="Masukkan username">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1"><i class="fas fa-lock mr-2 text-primary"></i>Password</label>
                <input type="password" id="login-password" required 
                       class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition" 
                       placeholder="Masukkan password">
            </div>
            <button type="submit" id="btn-login" 
                    class="w-full bg-primary hover:bg-secondary text-white font-bold py-3 rounded-lg shadow-md transition flex items-center justify-center space-x-2">
                <i class="fas fa-sign-in-alt"></i><span>Login</span>
            </button>
        </form>
        
        <!-- <div class="mt-6 p-3 bg-yellow-50 border-l-4 border-yellow-400 rounded text-xs text-yellow-800">
            <p class="font-semibold mb-1"><i class="fas fa-info-circle mr-1"></i>Default Login:</p>
            <p>Username: <code class="bg-white px-1 rounded">admin</code></p>
            <p>Password: <code class="bg-white px-1 rounded">admin123</code></p>
            <p class="mt-1 italic">Ubah password melalui tabel <code>operators</code> di database MySQL.</p>
        </div> -->
    </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btn-login');
    const alertBox = document.getElementById('login-alert');
    alertBox.innerHTML = '';
    
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span><span class="ml-2">Memproses...</span>';
    
    try {
        const fd = new FormData();
        fd.append('action', 'login');
        fd.append('username', document.getElementById('login-username').value);
        fd.append('password', document.getElementById('login-password').value);
        
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const result = await res.json();
        
        if (result.success) {
            window.location.href = 'index.php';
        } else {
            alertBox.innerHTML = `<div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded text-sm"><i class="fas fa-exclamation-triangle mr-2"></i>${result.error}</div>`;
        }
    } catch (err) {
        alertBox.innerHTML = `<div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded text-sm"><i class="fas fa-exclamation-triangle mr-2"></i>Koneksi gagal.</div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sign-in-alt"></i><span>Login</span>';
    }
});
</script>

<?php else: ?>
<!-- ==================== DASHBOARD VERIFIKASI ==================== -->
<header class="bg-primary text-white shadow-lg">
    <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
        <div class="flex items-center space-x-3">
            <i class="fas fa-clipboard-check text-2xl text-secondary bg-white p-2 rounded-full"></i>
            <div>
                <h1 class="text-lg md:text-xl font-bold">Verifikasi Pembayaran</h1>
                <p class="text-xs text-gray-200">Olimpiade Nasional Bahasa Indonesia 2026</p>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <div class="hidden md:block text-right">
                <p class="text-sm font-semibold"><?= htmlspecialchars($operator_name) ?></p>
                <p class="text-xs text-gray-300">Operator</p>
            </div>
            <button onclick="doLogout()" class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-sm font-semibold transition flex items-center space-x-2">
                <i class="fas fa-sign-out-alt"></i><span class="hidden sm:inline">Logout</span>
            </button>
        </div>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 py-6">
    
    <!-- Statistik Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-primary">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase">Total Peserta</p>
                    <p class="text-3xl font-bold text-primary mt-1"><?= $stat_total ?></p>
                </div>
                <i class="fas fa-users text-3xl text-primary opacity-20"></i>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-yellow-500">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase">Pending</p>
                    <p class="text-3xl font-bold text-yellow-600 mt-1"><?= $stat_pending ?></p>
                </div>
                <i class="fas fa-clock text-3xl text-yellow-500 opacity-20"></i>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-5 border-l-4 border-secondary">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs font-semibold text-gray-500 uppercase">Lunas</p>
                    <p class="text-3xl font-bold text-secondary mt-1"><?= $stat_lunas ?></p>
                </div>
                <i class="fas fa-check-circle text-3xl text-secondary opacity-20"></i>
            </div>
        </div>
    </div>

    <!-- Filter Tabs & Search -->
    <div class="bg-white rounded-xl shadow-sm p-4 mb-6 border border-gray-100">
        <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center">
            <div class="flex rounded-lg overflow-hidden border border-gray-300 flex-shrink-0">
                <a href="?filter=all<?= $search ? '&search='.urlencode($search) : '' ?>" 
                   class="px-4 py-2 text-sm font-semibold transition <?= $filter === 'all' ? 'bg-primary text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                    Semua <span class="ml-1 opacity-75">(<?= $stat_total ?>)</span>
                </a>
                <a href="?filter=pending<?= $search ? '&search='.urlencode($search) : '' ?>" 
                   class="px-4 py-2 text-sm font-semibold transition <?= $filter === 'pending' ? 'bg-yellow-500 text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                    Pending <span class="ml-1 opacity-75">(<?= $stat_pending ?>)</span>
                </a>
                <a href="?filter=lunas<?= $search ? '&search='.urlencode($search) : '' ?>" 
                   class="px-4 py-2 text-sm font-semibold transition <?= $filter === 'lunas' ? 'bg-secondary text-white' : 'bg-white text-gray-700 hover:bg-gray-50' ?>">
                    Lunas <span class="ml-1 opacity-75">(<?= $stat_lunas ?>)</span>
                </a>
            </div>
            
            <form method="GET" class="flex-grow flex gap-2">
                <?php if ($filter !== 'all'): ?>
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                <?php endif; ?>
                <div class="relative flex-grow">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Cari NIK, Nama, atau Sekolah..." 
                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none transition text-sm">
                </div>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                    <i class="fas fa-filter"></i>
                </button>
                <?php if ($search !== ''): ?>
                    <a href="?<?= $filter !== 'all' ? 'filter='.$filter : '' ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-semibold transition">
                        <i class="fas fa-times"></i>
                    </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Info -->
    <div class="flex justify-between items-center mb-3 px-1">
        <p class="text-sm text-gray-600">
            Menampilkan <span class="font-bold text-primary"><?= count($paged_data) ?></span> dari <span class="font-bold text-primary"><?= $total_records ?></span> data.
        </p>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[700px]">
                <thead>
                    <tr class="bg-primary text-white text-xs uppercase tracking-wider">
                        <th class="px-4 py-3 font-semibold">Peserta</th>
                        <th class="px-4 py-3 font-semibold">Sekolah</th>
                        <th class="px-4 py-3 font-semibold text-center">Jenis Bayar</th>
                        <th class="px-4 py-3 font-semibold text-center">Status</th>
                        <th class="px-4 py-3 font-semibold text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($paged_data)): ?>
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-gray-500">
                                <i class="fas fa-inbox text-4xl mb-3 text-gray-300"></i>
                                <p class="font-semibold">Tidak ada data ditemukan.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($paged_data as $row): ?>
                            <tr class="hover:bg-gray-50 transition" id="row-<?= $row['id'] ?>">
                                <td class="px-4 py-3">
                                    <div class="flex items-center space-x-3">
                                        <img src="../uploads/<?= htmlspecialchars($row['foto_profil']) ?>" 
                                             alt="Foto" 
                                             class="w-10 h-10 rounded-full object-cover border-2 border-primary"
                                             onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($row['nama']) ?>&background=133D56&color=fff'">
                                        <div>
                                            <p class="font-bold text-gray-800 text-sm leading-tight"><?= htmlspecialchars($row['nama']) ?></p>
                                            <p class="text-xs text-gray-500"><i class="fas fa-id-card mr-1"></i><?= htmlspecialchars($row['nik']) ?></p>
                                            <p class="text-xs text-gray-400"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($row['email']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-gray-700 text-sm"><?= htmlspecialchars($row['nama_sekolah']) ?></p>
                                    <p class="text-xs text-gray-500"><i class="fas fa-phone mr-1"></i><?= htmlspecialchars($row['no_hp']) ?></p>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-block px-2 py-1 rounded text-xs font-semibold bg-gray-100 text-gray-700">
                                        <?= htmlspecialchars($row['jenis_pembayaran'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if (($row['status_bayar'] ?? 'pending') === 'lunas'): ?>
                                        <span class="status-badge inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-secondary">
                                            <i class="fas fa-check-circle mr-1"></i> Lunas
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge status-pulse inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700">
                                            <i class="fas fa-clock mr-1"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($row['verified_at'])): ?>
                                        <p class="text-[10px] text-gray-400 mt-1">
                                            <?= htmlspecialchars($row['verified_at']) ?>
                                        </p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex justify-center gap-2">
                                        <button onclick="showDetail('<?= $row['id'] ?>')" 
                                                class="bg-gray-100 hover:bg-primary hover:text-white text-primary p-2 rounded-lg transition" title="Detail Peserta">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button onclick="showBukti('<?= $row['id'] ?>', '<?= htmlspecialchars(addslashes($row['nama'])) ?>')" 
                                                class="bg-gray-100 hover:bg-primary hover:text-white text-primary p-2 rounded-lg transition" title="Lihat Bukti Bayar">
                                            <i class="fas fa-file-invoice"></i>
                                        </button>
                                        <?php if (($row['status_bayar'] ?? 'pending') === 'pending'): ?>
                                            <button onclick="updateStatus('<?= $row['id'] ?>', 'lunas', this)" 
                                                    class="bg-secondary hover:bg-green-700 text-white text-xs font-bold px-3 py-2 rounded-lg transition flex items-center space-x-1">
                                                <i class="fas fa-check"></i><span>LUNAS</span>
                                            </button>
                                        <?php else: ?>
                                            <button onclick="updateStatus('<?= $row['id'] ?>', 'pending', this)" 
                                                    class="bg-yellow-500 hover:bg-yellow-600 text-white text-xs font-bold px-3 py-2 rounded-lg transition flex items-center space-x-1" title="Reset ke Pending">
                                                <i class="fas fa-undo"></i><span>Reset</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="flex justify-center mt-6 space-x-1">
            <?php if ($current_page > 1): ?>
                <a href="<?= build_url($current_page - 1, $filter, $search) ?>" class="px-3 py-2 bg-white border border-gray-300 rounded-lg text-primary hover:bg-gray-50 text-sm"><i class="fas fa-chevron-left"></i></a>
            <?php endif; ?>
            <?php
            $start = max(1, $current_page - 2);
            $end = min($total_pages, $current_page + 2);
            if ($start > 1) {
                echo '<a href="'.build_url(1, $filter, $search).'" class="px-3 py-2 bg-white border border-gray-300 rounded-lg text-primary hover:bg-gray-50 text-sm">1</a>';
                if ($start > 2) echo '<span class="px-2 py-2 text-gray-400">...</span>';
            }
            for ($i = $start; $i <= $end; $i++): ?>
                <?php if ($i == $current_page): ?>
                    <span class="px-4 py-2 bg-primary text-white rounded-lg font-bold text-sm"><?= $i ?></span>
                <?php else: ?>
                    <a href="<?= build_url($i, $filter, $search) ?>" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-primary hover:bg-gray-50 text-sm"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor;
            if ($end < $total_pages) {
                if ($end < $total_pages - 1) echo '<span class="px-2 py-2 text-gray-400">...</span>';
                echo '<a href="'.build_url($total_pages, $filter, $search).'" class="px-3 py-2 bg-white border border-gray-300 rounded-lg text-primary hover:bg-gray-50 text-sm">'.$total_pages.'</a>';
            }
            ?>
            <?php if ($current_page < $total_pages): ?>
                <a href="<?= build_url($current_page + 1, $filter, $search) ?>" class="px-3 py-2 bg-white border border-gray-300 rounded-lg text-primary hover:bg-gray-50 text-sm"><i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<!-- ==================== MODAL DETAIL PESERTA ==================== -->
<div id="detailModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-backdrop">
    <div class="bg-white rounded-2xl shadow-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto relative">
        <button onclick="closeDetail()" class="absolute top-4 right-4 text-gray-400 hover:text-red-500 transition z-10 bg-white rounded-full p-2 shadow-md">
            <i class="fas fa-times text-xl"></i>
        </button>
        <div class="p-6 md:p-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="md:col-span-1 flex flex-col items-center text-center border-r border-gray-100 pr-4">
                    <img id="detail-foto" src="" alt="Foto Profil" class="w-32 h-32 rounded-full object-cover border-4 border-primary shadow-lg mb-4">
                    <h3 id="detail-nama" class="text-xl font-bold text-primary"></h3>
                    <p id="detail-nik" class="text-sm text-gray-500 mt-1 font-mono"></p>
                    <div id="detail-status" class="mt-3 inline-flex items-center px-3 py-1 rounded-full text-xs font-bold"></div>
                    <div class="mt-6 w-full space-y-2">
                        <a id="detail-identitas" href="#" target="_blank" class="block w-full bg-gray-50 hover:bg-primary hover:text-white text-primary text-sm font-semibold py-2.5 px-4 rounded-lg transition border border-gray-200">
                            <i class="fas fa-id-card mr-2"></i><span id="detail-identitas-label">Identitas Sekolah</span>
                        </a>
                        <a id="detail-bayar" href="#" target="_blank" class="block w-full bg-gray-50 hover:bg-secondary hover:text-white text-secondary text-sm font-semibold py-2.5 px-4 rounded-lg transition border border-gray-200">
                            <i class="fas fa-receipt mr-2"></i>Bukti Bayar
                        </a>
                    </div>
                </div>
                <div class="md:col-span-2 space-y-5">
                    <h4 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4"><i class="fas fa-info-circle text-primary mr-2"></i>Informasi Lengkap</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase">Tempat, Tanggal Lahir</p>
                            <p id="detail-ttl" class="text-gray-800 font-medium mt-1"></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 uppercase">Email & No. HP</p>
                            <p id="detail-email" class="text-gray-800 font-medium mt-1 text-sm"></p>
                            <p id="detail-hp" class="text-gray-800 font-medium text-sm"></p>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase">Asal Sekolah</p>
                        <p id="detail-sekolah" class="text-gray-800 font-bold mt-1"></p>
                        <p id="detail-alamat-sekolah" class="text-gray-600 text-sm mt-1"></p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase">Alamat Rumah</p>
                        <p id="detail-alamat-rumah" class="text-gray-800 font-medium mt-1 text-sm"></p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase">Jenis Pembayaran</p>
                        <p id="detail-jenis-bayar" class="text-gray-800 font-medium mt-1"></p>
                    </div>
                    <div class="pt-4 border-t border-gray-100 text-xs text-gray-400 text-right">
                        ID Pendaftaran: <span id="detail-id" class="font-mono"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==================== MODAL BUKTI BAYAR ==================== -->
<div id="buktiModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-backdrop">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-hidden relative flex flex-col">
        <div class="bg-primary text-white px-6 py-4 flex items-center justify-between flex-shrink-0">
            <div>
                <h3 class="font-bold"><i class="fas fa-file-invoice mr-2"></i>Bukti Pembayaran</h3>
                <p id="bukti-nama" class="text-xs text-gray-200 mt-0.5"></p>
            </div>
            <button onclick="closeBukti()" class="text-white hover:text-red-300 transition text-xl">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="flex-grow overflow-auto bg-gray-100 p-4">
            <div id="bukti-content" class="bg-white rounded-lg shadow-inner min-h-[400px] flex items-center justify-center">
                <div class="text-gray-400"><i class="fas fa-spinner fa-spin text-3xl"></i></div>
            </div>
        </div>
        <div class="bg-gray-50 px-6 py-3 border-t flex justify-between items-center flex-shrink-0">
            <p class="text-xs text-gray-500"><i class="fas fa-info-circle mr-1"></i>Periksa bukti transfer dengan teliti sebelum menekan tombol LUNAS.</p>
            <button onclick="closeBukti()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-semibold transition">
                Tutup
            </button>
        </div>
    </div>
</div>

<!-- ==================== CONFIRM MODAL ==================== -->
<div id="confirmModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4 modal-backdrop">
    <div class="bg-white rounded-xl shadow-2xl max-w-md w-full p-6 fade-in">
        <div class="text-center">
            <div id="confirm-icon" class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 bg-secondary text-white">
                <i class="fas fa-check text-3xl"></i>
            </div>
            <h3 id="confirm-title" class="text-xl font-bold text-primary mb-2">Konfirmasi</h3>
            <p id="confirm-message" class="text-gray-600 text-sm mb-6"></p>
            <div class="flex gap-3">
                <button onclick="closeConfirm()" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold py-2.5 rounded-lg transition">Batal</button>
                <button id="confirm-btn" class="flex-1 bg-secondary hover:bg-green-700 text-white font-bold py-2.5 rounded-lg transition">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== TOAST NOTIFICATION ==================== -->
<div id="toast" class="fixed bottom-6 right-6 z-50 hidden">
    <div id="toast-content" class="bg-secondary text-white px-5 py-3 rounded-lg shadow-lg flex items-center space-x-3 fade-in">
        <i id="toast-icon" class="fas fa-check-circle text-xl"></i>
        <span id="toast-message"></span>
    </div>
</div>

<script>
// Data peserta untuk modal
const pesertaData = <?= json_encode($paged_data) ?>;

function formatTanggal(tgl) {
    if (!tgl) return '';
    const bulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agt', 'Sep', 'Okt', 'Nov', 'Des'];
    const [y, m, d] = tgl.split('-');
    return `${parseInt(d)} ${bulan[parseInt(m) - 1]} ${y}`;
}

function showDetail(regId) {
    const data = pesertaData.find(p => p.id === regId);
    if (!data) return;

    document.getElementById('detail-foto').src = '../uploads/' + data.foto_profil;
    document.getElementById('detail-nama').innerText = data.nama;
    document.getElementById('detail-nik').innerText = 'NIK: ' + data.nik;
    document.getElementById('detail-id').innerText = data.id;
    document.getElementById('detail-ttl').innerText = data.tempat_lahir + ', ' + formatTanggal(data.tgl_lahir);
    document.getElementById('detail-email').innerText = data.email;
    document.getElementById('detail-hp').innerText = data.no_hp;
    document.getElementById('detail-sekolah').innerText = data.nama_sekolah;
    document.getElementById('detail-alamat-sekolah').innerText = data.alamat_sekolah;
    document.getElementById('detail-alamat-rumah').innerText = data.alamat_rumah;
    document.getElementById('detail-jenis-bayar').innerText = data.jenis_pembayaran || 'N/A';
    document.getElementById('detail-bayar').href = '../uploads/' + data.bukti_bayar;

    const status = document.getElementById('detail-status');
    if ((data.status_bayar || 'pending') === 'lunas') {
        status.className = 'mt-3 inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-secondary';
        status.innerHTML = '<i class="fas fa-check-circle mr-1"></i>Lunas';
    } else {
        status.className = 'mt-3 inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700';
        status.innerHTML = '<i class="fas fa-clock mr-1"></i>Pending';
    }

    const identitas = document.getElementById('detail-identitas');
    const identitasLabel = document.getElementById('detail-identitas-label');
    if (data.identitas_file) {
        identitas.style.display = 'block';
        identitas.href = '../uploads/' + data.identitas_file;
        identitasLabel.innerText = data.identitas_type === 'kartu_pelajar' ? 'Kartu Pelajar' : 'Surat Keterangan Aktif';
    } else {
        identitas.style.display = 'none';
    }

    const modal = document.getElementById('detailModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function closeDetail() {
    const modal = document.getElementById('detailModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
}

// ====== SHOW BUKTI BAYAR ======
function showBukti(regId, nama) {
    const data = pesertaData.find(p => p.id === regId);
    if (!data) return;
    
    document.getElementById('bukti-nama').innerText = nama + ' - ' + (data.jenis_pembayaran || '');
    const content = document.getElementById('bukti-content');
    const file = data.bukti_bayar;
    const ext = file.split('.').pop().toLowerCase();
    
    if (['pdf'].includes(ext)) {
        content.innerHTML = `<iframe src="../uploads/${file}" class="w-full h-[600px] rounded-lg border-0" frameborder="0"></iframe>`;
    } else if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
        content.innerHTML = `<img src="../uploads/${file}" class="max-w-full max-h-[600px] mx-auto rounded-lg" alt="Bukti Bayar">`;
    } else {
        content.innerHTML = `<div class="text-center p-8"><i class="fas fa-file text-5xl text-gray-400 mb-3"></i><p class="text-gray-600">Format file tidak dapat ditampilkan.</p><a href="../uploads/${file}" target="_blank" class="inline-block mt-3 text-primary underline">Download File</a></div>`;
    }
    
    const modal = document.getElementById('buktiModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.classList.add('overflow-hidden');
}

function closeBukti() {
    const modal = document.getElementById('buktiModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.body.classList.remove('overflow-hidden');
    document.getElementById('bukti-content').innerHTML = '<div class="text-gray-400"><i class="fas fa-spinner fa-spin text-3xl"></i></div>';
}

// ====== UPDATE STATUS ======
let pendingRegId = null;
let pendingStatus = null;
let pendingBtn = null;

function updateStatus(regId, status, btn) {
    pendingRegId = regId;
    pendingStatus = status;
    pendingBtn = btn;
    
    const icon = document.getElementById('confirm-icon');
    const title = document.getElementById('confirm-title');
    const msg = document.getElementById('confirm-message');
    const cbtn = document.getElementById('confirm-btn');
    
    if (status === 'lunas') {
        icon.className = 'w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 bg-secondary text-white';
        icon.innerHTML = '<i class="fas fa-check-circle text-3xl"></i>';
        title.innerText = 'Konfirmasi Pembayaran Lunas';
        msg.innerHTML = 'Apakah Anda yakin bukti pembayaran <strong>valid dan sesuai</strong>? Status akan diubah menjadi <span class="font-bold text-secondary">LUNAS</span> dan peserta dapat membuat akun ujian.';
        cbtn.className = 'flex-1 bg-secondary hover:bg-green-700 text-white font-bold py-2.5 rounded-lg transition';
        cbtn.innerHTML = '<i class="fas fa-check mr-1"></i> Ya, Tandai Lunas';
    } else {
        icon.className = 'w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 bg-yellow-500 text-white';
        icon.innerHTML = '<i class="fas fa-undo text-3xl"></i>';
        title.innerText = 'Reset Status ke Pending';
        msg.innerHTML = 'Status pembayaran akan dikembalikan ke <span class="font-bold text-yellow-600">PENDING</span>. Peserta tidak dapat membuat akun ujian sampai diverifikasi ulang.';
        cbtn.className = 'flex-1 bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2.5 rounded-lg transition';
        cbtn.innerHTML = '<i class="fas fa-undo mr-1"></i> Ya, Reset';
    }
    
    const modal = document.getElementById('confirmModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeConfirm() {
    const modal = document.getElementById('confirmModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    pendingRegId = null;
    pendingStatus = null;
    pendingBtn = null;
}

document.getElementById('confirm-btn').addEventListener('click', async function() {
    if (!pendingRegId) return;
    
    const btn = this;
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    
    try {
        const fd = new FormData();
        fd.append('action', 'update_status');
        fd.append('reg_id', pendingRegId);
        fd.append('status', pendingStatus);
        
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const result = await res.json();
        
        if (result.success) {
            closeConfirm();
            
            // Update UI row
            const row = document.getElementById('row-' + pendingRegId);
            if (row) {
                const statusCell = row.querySelector('.status-badge');
                if (pendingStatus === 'lunas') {
                    statusCell.className = 'status-badge inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-green-100 text-secondary';
                    statusCell.innerHTML = '<i class="fas fa-check-circle mr-1"></i> Lunas';
                    statusCell.classList.remove('status-pulse');
                    
                    // Ganti tombol jadi Reset
                    const aksiCell = statusCell.closest('tr').querySelector('td:last-child');
                    const tombol = aksiCell.querySelector('button:last-child');
                    tombol.className = 'bg-yellow-500 hover:bg-yellow-600 text-white text-xs font-bold px-3 py-2 rounded-lg transition flex items-center space-x-1';
                    tombol.setAttribute('onclick', `updateStatus('${pendingRegId}', 'pending', this)`);
                    tombol.title = 'Reset ke Pending';
                    tombol.innerHTML = '<i class="fas fa-undo"></i><span>Reset</span>';
                } else {
                    statusCell.className = 'status-badge status-pulse inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700';
                    statusCell.innerHTML = '<i class="fas fa-clock mr-1"></i> Pending';
                    
                    const aksiCell = statusCell.closest('tr').querySelector('td:last-child');
                    const tombol = aksiCell.querySelector('button:last-child');
                    tombol.className = 'bg-secondary hover:bg-green-700 text-white text-xs font-bold px-3 py-2 rounded-lg transition flex items-center space-x-1';
                    tombol.setAttribute('onclick', `updateStatus('${pendingRegId}', 'lunas', this)`);
                    tombol.innerHTML = '<i class="fas fa-check"></i><span>LUNAS</span>';
                }
            }
            
            showToast(result.message, 'success');
        } else {
            showToast(result.error, 'error');
            closeConfirm();
        }
    } catch (err) {
        showToast('Terjadi kesalahan koneksi.', 'error');
        closeConfirm();
    } finally {
        btn.disabled = false;
        btn.innerHTML = original;
    }
});

// ====== TOAST ======
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const content = document.getElementById('toast-content');
    const icon = document.getElementById('toast-icon');
    const msg = document.getElementById('toast-message');
    
    msg.innerText = message;
    if (type === 'success') {
        content.className = 'bg-secondary text-white px-5 py-3 rounded-lg shadow-lg flex items-center space-x-3 fade-in';
        icon.className = 'fas fa-check-circle text-xl';
    } else {
        content.className = 'bg-red-500 text-white px-5 py-3 rounded-lg shadow-lg flex items-center space-x-3 fade-in';
        icon.className = 'fas fa-exclamation-triangle text-xl';
    }
    
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 3500);
}

// ====== LOGOUT ======
async function doLogout() {
    if (!confirm('Yakin ingin logout?')) return;
    const fd = new FormData();
    fd.append('action', 'logout');
    await fetch('index.php', { method: 'POST', body: fd });
    window.location.href = 'index.php';
}

// Tutup modal saat klik backdrop
document.getElementById('buktiModal').addEventListener('click', function(e) {
    if (e.target === this) closeBukti();
});
document.getElementById('detailModal').addEventListener('click', function(e) {
    if (e.target === this) closeDetail();
});
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirm();
});
</script>

<?php endif; ?>

<script>document.addEventListener("DOMContentLoaded",()=>{document.addEventListener("selectstart",e=>e.preventDefault()),document.addEventListener("contextmenu",e=>e.preventDefault()),document.addEventListener("copy",e=>e.preventDefault()),document.addEventListener("cut",e=>e.preventDefault()),document.addEventListener("paste",e=>e.preventDefault()),document.addEventListener("keydown",e=>{if((e.ctrlKey&&["u","c","v","x","s","a"].includes(e.key.toLowerCase()))||(e.ctrlKey&&e.shiftKey&&["i","j"].includes(e.key.toLowerCase()))||"F12"===e.key)e.preventDefault()})});</script>
</body>
</html>
