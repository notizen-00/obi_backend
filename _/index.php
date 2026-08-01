<?php
session_start();
require_once __DIR__ . '/../db/exam.php';

ensure_exam_tables();
$exam_type = default_exam_type();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = db()->prepare("SELECT eu.*, p.nama_sekolah, p.status_bayar, p.foto_profil FROM exam_users eu JOIN participants p ON p.id = eu.participant_id WHERE LOWER(eu.username) = LOWER(?) AND eu.status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            exam_json(['success' => false, 'error' => 'Username atau password salah.']);
        }
        if (($user['status_bayar'] ?? '') !== 'lunas') {
            exam_json(['success' => false, 'error' => 'Akun belum bisa ujian karena pembayaran belum LUNAS.']);
        }
        $_SESSION['exam_user'] = [
            'id' => $user['id'],
            'nama' => $user['nama'],
            'username' => $user['username'],
            'email' => $user['email'],
            'nik' => $user['nik'],
            'participant_id' => $user['participant_id'],
            'sekolah' => $user['nama_sekolah'],
            'foto_profil' => $user['foto_profil'],
        ];
        exam_log($user['id'], 'login');
        exam_json(['success' => true]);
    }

    if ($action === 'logout') {
        session_destroy();
        exam_json(['success' => true]);
    }

    if (!$exam_type) {
        exam_json(['success' => false, 'error' => 'Belum ada ujian utama yang aktif. Hubungi panitia.'], 503);
    }

    $user = require_exam_user();

    if ($action === 'me') {
        $profile_stmt = db()->prepare("SELECT eu.id, eu.nama, eu.username, eu.email, eu.nik, eu.participant_id, p.nama_sekolah, p.foto_profil FROM exam_users eu JOIN participants p ON p.id = eu.participant_id WHERE eu.id = ? LIMIT 1");
        $profile_stmt->execute([$user['id']]);
        $profile = $profile_stmt->fetch();
        if ($profile) {
            $_SESSION['exam_user'] = [
                'id' => $profile['id'],
                'nama' => $profile['nama'],
                'username' => $profile['username'],
                'email' => $profile['email'],
                'nik' => $profile['nik'],
                'participant_id' => $profile['participant_id'],
                'sekolah' => $profile['nama_sekolah'],
                'foto_profil' => $profile['foto_profil'],
            ];
            $user = $_SESSION['exam_user'];
        }
        $attempt = refresh_attempt_status(get_or_create_attempt($user['id']));
        exam_json(['success' => true, 'user' => $user, 'attempt' => [
            'status' => $attempt['status'],
            'started_at' => $attempt['started_at'],
            'ends_at' => $attempt['ends_at'],
            'server_time' => date('Y-m-d H:i:s'),
            'survey_url' => SURVEY_URL,
        ], 'exam_type' => [
            'name' => $exam_type['name'],
            'duration_minutes' => (int)$exam_type['duration_minutes'],
            'question_count' => (int)$exam_type['question_count'],
        ]]);
    }

    if ($action === 'start') {
        $questions = active_questions();
        $required_questions = (int)$exam_type['question_count'];
        if (count($questions) < $required_questions) {
            exam_json(['success' => false, 'error' => 'Soal aktif belum lengkap ' . $required_questions . ' nomor. Hubungi panitia.']);
        }
        $attempt = refresh_attempt_status(get_or_create_attempt($user['id']));
        if ($attempt['status'] === 'submitted' || $attempt['status'] === 'expired') {
            exam_json(['success' => false, 'finished' => true, 'survey_url' => SURVEY_URL, 'error' => 'Ujian sudah selesai.']);
        }
        if ($attempt['status'] === 'not_started') {
            $order = array_map(fn($q) => (int)$q['question_no'], $questions);
            $stmt = db()->prepare("UPDATE exam_attempts SET started_at = NOW(), ends_at = DATE_ADD(NOW(), INTERVAL ? MINUTE), status = 'in_progress', question_order_json = ? WHERE id = ?");
            $stmt->execute([(int)$exam_type['duration_minutes'], json_encode($order), $attempt['id']]);
            exam_log($user['id'], 'start');
        }
        exam_json(['success' => true]);
    }

    if ($action === 'load_exam') {
        $attempt = refresh_attempt_status(get_or_create_attempt($user['id']));
        if ($attempt['status'] !== 'in_progress') {
            exam_json(['success' => false, 'finished' => in_array($attempt['status'], ['submitted', 'expired'], true), 'status' => $attempt['status'], 'survey_url' => SURVEY_URL]);
        }
        exam_json([
            'success' => true,
            'questions' => active_questions(),
            'answers' => json_decode($attempt['answers_json'] ?: '{}', true) ?: [],
            'ends_at' => $attempt['ends_at'],
            'server_time' => date('Y-m-d H:i:s'),
            'survey_url' => SURVEY_URL,
            'exam_type' => [
                'name' => $exam_type['name'],
                'duration_minutes' => (int)$exam_type['duration_minutes'],
                'question_count' => (int)$exam_type['question_count'],
            ],
        ]);
    }

    if ($action === 'save') {
        $attempt = refresh_attempt_status(get_or_create_attempt($user['id']));
        if ($attempt['status'] !== 'in_progress') {
            exam_json(['success' => false, 'finished' => true, 'survey_url' => SURVEY_URL]);
        }
        $answers = json_decode($_POST['answers'] ?? '{}', true);
        if (!is_array($answers)) $answers = [];
        $clean = [];
        foreach ($answers as $no => $ans) {
            $n = (int)$no;
            if ($n >= 1 && $n <= (int)$exam_type['question_count'] && in_array($ans, ['A', 'B', 'C', 'D', 'E'], true)) {
                $clean[(string)$n] = $ans;
            }
        }
        $stmt = db()->prepare('UPDATE exam_attempts SET answers_json = ?, last_saved_at = NOW() WHERE id = ?');
        $stmt->execute([json_encode($clean), $attempt['id']]);
        exam_json(['success' => true, 'saved_at' => date('H:i:s'), 'answered' => count($clean)]);
    }

    if ($action === 'submit') {
        $attempt = get_or_create_attempt($user['id']);
        if ($attempt['status'] === 'in_progress') {
            $stmt = db()->prepare("UPDATE exam_attempts SET status = 'submitted', submitted_at = NOW(), last_saved_at = NOW() WHERE id = ?");
            $stmt->execute([$attempt['id']]);
            exam_log($user['id'], 'submit');
        }
        exam_json(['success' => true, 'survey_url' => SURVEY_URL]);
    }

    exam_json(['success' => false, 'error' => 'Action tidak valid.']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OBI EXAM</title>
    <link rel="icon" type="image/png" href="/../unejmask.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>tailwind.config={theme:{extend:{colors:{primary:'#133D56',secondary:'#025C26'}}}}</script>
    <style>
        body{background:linear-gradient(135deg,#102f43,#f8fafc 34%,#eef7f1);}.choice.selected{background:#133D56;color:white;border-color:#133D56}.navbtn.answered{background:#025C26;color:white}.navbtn.active{outline:3px solid #f59e0b}.slidewarn{animation:slide .35s ease-out}@keyframes slide{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
    </style>
</head>
<body class="min-h-screen text-gray-800">
<div id="app"></div>
<div id="warningBox" class="fixed top-4 right-4 z-50 hidden max-w-sm rounded-xl p-4 text-white shadow-2xl slidewarn"></div>
<script>
const app=document.getElementById('app');let user=null,questions=[],answers={},current=1,endsAt=null,serverOffset=0,timer=null,lastWarn10=false,lastWarn3=false,saveTimer=null,examConfig={name:'OBI EXAM 2026',duration_minutes:120,question_count:50};
const post=async(action,data={})=>{const fd=new FormData();fd.append('action',action);Object.entries(data).forEach(([k,v])=>fd.append(k,v));const r=await fetch('index.php',{method:'POST',body:fd});return r.json()};
function loginView(msg=''){app.innerHTML=`<main class="min-h-screen flex items-center justify-center p-4"><section class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-7"><div class="text-center mb-6"><img src="/../obi-logo.png" class="w-20 h-20 mx-auto bg-white rounded-full shadow p-1 mb-3"><h1 class="text-3xl font-black text-primary">OBI EXAM 2026</h1><p class="text-sm text-gray-500">Masuk menggunakan akun ujian peserta</p></div>${msg?`<div class="bg-red-50 text-red-700 border-l-4 border-red-500 p-3 rounded mb-4 text-sm">${msg}</div>`:''}<form id="loginForm" class="space-y-4"><input id="u" class="w-full border rounded-xl px-4 py-3" placeholder="Username" required><input id="p" type="password" class="w-full border rounded-xl px-4 py-3" placeholder="Password" required><button class="w-full bg-primary hover:bg-secondary text-white font-bold py-3 rounded-xl">Login</button></form></section></main>`;document.getElementById('loginForm').onsubmit=async e=>{e.preventDefault();const res=await post('login',{username:u.value,password:p.value});res.success?boot():loginView(res.error||'Login gagal')}} 
function profileView(attempt){const foto=user.foto_profil?`/uploads/${encodeURIComponent(user.foto_profil)}`:`https://ui-avatars.com/api/?name=${encodeURIComponent(user.nama)}&background=133D56&color=fff`;const uniqueId=`${user.participant_id || '-'} / ${user.nik || '-'}`;app.innerHTML=`<main class="max-w-5xl mx-auto p-4 md:p-8"><header class="bg-primary text-white rounded-3xl shadow-xl p-5 md:p-7 mb-5 overflow-hidden relative"><div class="relative z-10 flex flex-col sm:flex-row items-center gap-4 text-center sm:text-left"><img src="/../obi-logo.png" class="w-16 h-16 md:w-20 md:h-20 bg-white rounded-full p-1 shadow-lg"><div><p class="text-xs font-black tracking-[0.28em] text-yellow-300">OLIMPIADE NASIONAL BAHASA INDONESIA</p><h1 class="text-3xl md:text-4xl font-black leading-tight">${escapeHtml(examConfig.name)}</h1><p class="text-sm text-gray-200">Ruang persiapan peserta sebelum memulai ujian</p></div></div><div class="absolute inset-0 opacity-10 bg-[url('/../obi-logo.png')] bg-[length:90px_90px] rotate-[-8deg] scale-125"></div></header><section class="bg-white rounded-2xl shadow-xl p-5 md:p-8"><div class="grid grid-cols-1 md:grid-cols-[280px_1fr] gap-6 md:items-stretch"><div><div class="rounded-2xl overflow-hidden bg-gray-100 border border-gray-200 shadow-inner"><img src="${foto}" alt="Foto peserta" class="w-full h-72 md:h-[330px] object-cover" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(user.nama)}&background=133D56&color=fff'"></div><div class="mt-3 bg-gray-950 text-green-300 rounded-xl px-4 py-3 shadow-inner"><p class="text-[10px] uppercase tracking-widest text-gray-400 font-bold">Unique Identifier</p><p class="font-mono text-sm md:text-base font-bold break-all mt-1">${escapeHtml(uniqueId)}</p></div></div><div class="flex flex-col justify-between gap-5"><div><p class="text-xs font-black tracking-widest text-secondary">PROFIL PESERTA</p><h2 class="text-3xl md:text-4xl font-black text-primary mt-1 leading-tight">${escapeHtml(user.nama)}</h2><p class="text-lg text-gray-600 mt-2">${escapeHtml(user.sekolah)}</p><div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm"><div class="bg-gray-50 rounded-xl p-4"><p class="text-gray-400 font-bold uppercase text-xs">Username</p><p class="font-black text-primary mt-1">${escapeHtml(user.username)}</p></div><div class="bg-primary text-white rounded-xl p-4"><p class="text-gray-200 font-bold uppercase text-xs">Durasi</p><p class="font-black text-2xl mt-1">${examConfig.duration_minutes} Menit</p></div></div></div><div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded text-sm text-yellow-800">Timer dimulai saat tombol Mulai Ujian ditekan. Setelah waktu habis, ujian otomatis ditutup.</div></div></div><button id="startBtn" class="mt-6 w-full bg-secondary hover:bg-primary text-white font-black py-4 rounded-xl text-lg">Mulai Ujian</button></section></main>`;startBtn.onclick=async()=>{const r=await post('start');r.success?loadExam():alert(r.error||'Gagal mulai ujian')}} 
function examView(){app.innerHTML=`<header class="bg-primary text-white sticky top-0 z-30 shadow"><div class="max-w-7xl mx-auto px-4 py-3 flex justify-between items-center gap-3"><div><p class="font-black">OBI EXAM</p><p class="text-xs text-gray-200">${user.nama}</p></div><div id="timer" class="font-mono text-xl md:text-2xl font-black bg-white text-primary px-4 py-2 rounded-xl">--:--:--</div></div></header><main class="max-w-7xl mx-auto p-4 grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-4"><section class="bg-white rounded-2xl shadow p-5 md:p-7"><div class="flex items-center justify-between mb-4"><h2 class="text-2xl font-black text-primary">Soal <span id="qno"></span></h2><span id="saveState" class="text-xs text-gray-400">Belum tersimpan</span></div><p id="qtext" class="text-lg leading-relaxed mb-5 whitespace-pre-wrap"></p><div id="choices" class="space-y-3"></div><div class="flex gap-3 mt-6"><button onclick="go(current-1)" class="flex-1 bg-gray-100 py-3 rounded-xl font-bold">Sebelumnya</button><button onclick="go(current+1)" class="flex-1 bg-primary text-white py-3 rounded-xl font-bold">Berikutnya</button></div></section><aside class="bg-white rounded-2xl shadow p-5 h-fit lg:sticky lg:top-24"><p class="font-black text-primary mb-3">Navigasi Soal</p><div id="nav" class="grid grid-cols-5 gap-2"></div><button onclick="finishExam()" class="mt-5 w-full bg-red-600 hover:bg-red-700 text-white py-3 rounded-xl font-black">Selesai Ujian</button></aside></main>`;renderQuestion();renderNav();tick();timer=setInterval(tick,1000);saveTimer=setInterval(saveAnswers,25000)}
function renderQuestion(){const q=questions.find(x=>parseInt(x.question_no)===current);qno.textContent=current;qtext.textContent=q?q.question_text:'Soal belum tersedia';choices.innerHTML=['A','B','C','D','E'].map(l=>`<button class="choice w-full text-left border-2 rounded-xl px-4 py-3 font-bold ${answers[current]===l?'selected':''}" onclick="choose('${l}')"><span class="inline-flex w-8 h-8 rounded-full bg-gray-100 text-primary items-center justify-center mr-3">${l}</span>${q?escapeHtml(q['option_'+l.toLowerCase()]):''}</button>`).join('')}
function renderNav(){nav.innerHTML='';for(let i=1;i<=examConfig.question_count;i++){const b=document.createElement('button');b.className=`navbtn rounded-lg py-2 font-bold border ${answers[i]?'answered':'bg-gray-50'} ${i===current?'active':''}`;b.textContent=i;b.onclick=()=>go(i);nav.appendChild(b)}}
function choose(l){answers[current]=l;renderQuestion();renderNav();saveAnswers()}
function go(n){if(n<1||n>examConfig.question_count)return;current=n;renderQuestion();renderNav()}
async function saveAnswers(){const r=await post('save',{answers:JSON.stringify(answers)});if(r.finished){location.href=r.survey_url;return}if(r.success)saveState.textContent=`Tersimpan ${r.saved_at} | ${r.answered}/${examConfig.question_count} dijawab`}
function tick(){const remain=Math.max(0,Math.floor((endsAt-Date.now()-serverOffset)/1000));const h=String(Math.floor(remain/3600)).padStart(2,'0'),m=String(Math.floor(remain%3600/60)).padStart(2,'0'),s=String(remain%60).padStart(2,'0');document.getElementById('timer').textContent=`${h}:${m}:${s}`;if(remain<=600&&!lastWarn10){lastWarn10=true;warn('Sisa waktu 10 menit. Periksa kembali jawabanmu.','bg-orange-500')}if(remain<=180&&!lastWarn3){lastWarn3=true;warn('Sisa waktu 3 menit. Ujian akan segera ditutup.','bg-red-600')}if(remain<=0)finishExam(true)}
function warn(t,c){warningBox.className=`fixed top-4 right-4 z-50 max-w-sm rounded-xl p-4 text-white shadow-2xl slidewarn ${c}`;warningBox.textContent=t;warningBox.classList.remove('hidden');setTimeout(()=>warningBox.classList.add('hidden'),7000)}
async function finishExam(auto=false){if(!auto&&!confirm('Yakin selesai ujian?'))return;await saveAnswers();const r=await post('submit');location.href=r.survey_url}
async function loadExam(){const r=await post('load_exam');if(!r.success){if(r.finished)location.href=r.survey_url;else alert(r.error||'Ujian belum dimulai');return}examConfig=r.exam_type||examConfig;questions=r.questions;answers=r.answers||{};endsAt=new Date(r.ends_at.replace(' ','T')).getTime();serverOffset=Date.now()-new Date(r.server_time.replace(' ','T')).getTime();examView()}
async function boot(){const r=await post('me');if(!r.success){loginView(r.error||'Sesi ujian tidak tersedia.');return}user=r.user;examConfig=r.exam_type||examConfig;if(['submitted','expired'].includes(r.attempt.status)){location.href=r.attempt.survey_url;return}r.attempt.status==='in_progress'?loadExam():profileView(r.attempt)}
function escapeHtml(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
if(location.hash!=='#/obiexam/') history.replaceState(null,'','#/obiexam/'); boot();
</script>
</body>
</html>
