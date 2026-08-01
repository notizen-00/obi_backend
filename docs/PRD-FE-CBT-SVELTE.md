# Product Requirements Document

## Frontend CBT OBI — Svelte

| Informasi | Nilai |
|---|---|
| Produk | OBI CBT |
| Platform | Web responsif |
| Frontend | SvelteKit + TypeScript |
| API | OBI CBT JSON API |
| Dokumen | Versi 1.0 |
| Bahasa UI | Bahasa Indonesia |
| Target pengguna | Peserta OBI |

## 1. Ringkasan

Frontend CBT OBI adalah aplikasi web untuk peserta yang memungkinkan peserta:

1. masuk menggunakan akun ujian;
2. memilih Ujian Utama atau Tryout yang tersedia;
3. melihat informasi dan status ujian;
4. memulai ujian dengan timer;
5. menjawab dan menyimpan jawaban secara otomatis;
6. meninjau progres jawaban;
7. mengumpulkan ujian secara final; dan
8. keluar serta mencabut token autentikasi.

Frontend menggunakan bearer token dari API. Ujian Utama dan setiap Tryout
memiliki attempt, durasi, jumlah soal, jawaban, dan status yang terpisah.

## 2. Tujuan produk

### Tujuan utama

- Memberikan pengalaman CBT yang stabil pada desktop dan perangkat seluler.
- Mencegah kehilangan jawaban akibat refresh atau koneksi tidak stabil.
- Menampilkan timer yang mengikuti waktu server.
- Mendukung Ujian Utama dan beberapa Tryout tanpa mencampur bank soal.
- Memberikan status penyimpanan yang mudah dipahami peserta.
- Menjaga token, data peserta, dan soal dari akses tidak sah.

### Indikator keberhasilan

- Peserta dapat menyelesaikan alur login sampai submit tanpa bantuan operator.
- Jawaban tersimpan maksimal 20 detik setelah perubahan ketika koneksi tersedia.
- Refresh halaman tidak menghapus jawaban yang telah tersimpan.
- Timer tetap konsisten terhadap `server_time` dan `ends_at` dari API.
- Tidak ada `correct_option` atau kunci jawaban di state/frontend.
- Ujian yang belum siap tidak dapat dimulai.

## 3. Di luar ruang lingkup versi pertama

- Registrasi akun peserta.
- Reset password.
- Pengelolaan soal atau jenis ujian.
- Penilaian dan perhitungan skor.
- Ranking peserta.
- Pembahasan jawaban.
- Pengawasan kamera, mikrofon, atau screen recording.
- Retry attempt yang sudah `submitted` atau `expired`.
- Mode offline penuh.

API saat ini belum menyediakan skor atau hasil detail. Halaman selesai hanya
menampilkan konfirmasi bahwa ujian telah dikumpulkan.

## 4. Persona

### Peserta

- Sudah memiliki username dan password ujian.
- Pembayaran telah berstatus lunas.
- Menggunakan laptop, desktop, tablet, atau ponsel.
- Bisa memiliki koneksi yang tidak selalu stabil.
- Tidak diasumsikan memahami istilah teknis.

### Operator

Operator tidak menggunakan frontend ini. Pengelolaan ujian dan soal tetap
dilakukan melalui `admin.php`.

## 5. Arsitektur frontend

### Teknologi

- SvelteKit
- TypeScript
- Fetch API
- CSS responsif atau utility CSS sesuai standar tim
- Vitest untuk unit test
- Playwright untuk end-to-end test

SvelteKit digunakan untuk routing dan struktur aplikasi. Seluruh halaman CBT
yang memerlukan token dirender sebagai aplikasi client-side agar token tidak
perlu diteruskan ke proses SSR.

### Environment variable

```text
PUBLIC_CBT_API_BASE_URL=http://obi-archive.test/_/api.php
PUBLIC_CBT_SERVER_TIMEZONE=Asia/Jakarta
```

Pada production:

```text
PUBLIC_CBT_API_BASE_URL=https://domain-production/_/api.php
```

HTTP hanya digunakan untuk localhost atau domain Laragon `.test`. Production
wajib HTTPS.

### Struktur rute

| Rute | Fungsi | Auth |
|---|---|---|
| `/login` | Login peserta | Publik |
| `/exams` | Daftar Ujian Utama dan Tryout | Wajib |
| `/exam/[id]/lobby` | Informasi sebelum mulai | Wajib |
| `/exam/[id]` | Ruang pengerjaan CBT | Wajib |
| `/exam/[id]/completed` | Konfirmasi selesai | Wajib |
| `/exam/[id]/survey` | Survey setelah ujian | Wajib |

Rute `/` mengarahkan pengguna ke `/exams` jika memiliki token, atau `/login`
jika belum login.

## 6. Autentikasi

### Login

Frontend memanggil:

```http
POST ?action=token_login
Content-Type: application/json

{
  "username": "peserta",
  "password": "password"
}
```

Respons sukses berisi:

```json
{
  "success": true,
  "data": {
    "user": {},
    "access_token": "TOKEN",
    "token_type": "Bearer",
    "expires_in": 28800,
    "expires_at": "2026-07-29 20:00:00"
  }
}
```

### Penyimpanan token

- Token disimpan di `sessionStorage`, bukan `localStorage`.
- Token tidak boleh ditulis ke log, analytics, URL, atau error report.
- Password tidak pernah disimpan setelah request login selesai.
- Semua request terlindungi menggunakan:

```http
Authorization: Bearer ACCESS_TOKEN
```

- Ketika API mengembalikan HTTP `401`, frontend menghapus token dan mengarahkan
  pengguna ke `/login`.
- Logout memanggil endpoint API terlebih dahulu agar token dicabut, kemudian
  membersihkan seluruh state lokal.

### Rate limit

Jika login mengembalikan HTTP `429`, UI menampilkan:

> Terlalu banyak percobaan login. Silakan coba kembali dalam 15 menit.

Tombol login dinonaktifkan sementara untuk mencegah request berulang.

## 7. Alur pengguna

### Alur utama

```text
Login
  → Daftar ujian
  → Pilih Ujian Utama/Tryout
  → Lobby
  → Mulai ujian
  → Kerjakan dan autosave
  → Konfirmasi submit
  → Halaman selesai
  → Kembali ke daftar ujian atau logout
```

### Pemulihan sesi

```text
Halaman direfresh
  → Ambil token dari sessionStorage
  → GET me
  → GET exam untuk attempt in_progress
  → Pulihkan soal, jawaban, dan timer dari server
```

Frontend tidak boleh memulai timer baru saat refresh.

## 8. Kebutuhan halaman

### 8.1 Login

#### Komponen

- Logo dan identitas OBI CBT.
- Input username.
- Input password dengan tombol tampil/sembunyikan.
- Tombol Masuk.
- Area pesan error.
- Loading state saat request berlangsung.

#### Perilaku

- Trim username sebelum dikirim.
- Password tidak diubah.
- Cegah double submit.
- Enter menjalankan login.
- Setelah login sukses, simpan token dan arahkan ke `/exams`.

#### Error

| Status | Pesan |
|---|---|
| 400 | Request login tidak valid |
| 401 | Username atau password salah |
| 403 | Pembayaran peserta belum lunas |
| 429 | Terlalu banyak percobaan login |
| 500 | Server sedang bermasalah |
| Network error | Tidak dapat terhubung ke server |

### 8.2 Daftar ujian

Data diperoleh dari:

```http
GET ?action=exams
```

dan status attempt pengguna diperoleh dari:

```http
GET ?action=me
```

#### Pengelompokan

- Ujian Utama
- Tryout

#### Isi kartu ujian

- Nama ujian.
- Jenis: `main` atau `tryout`.
- Deskripsi.
- Durasi.
- Jumlah soal.
- Status kesiapan.
- Status attempt peserta.
- Tombol aksi.

#### Status dan tombol

| Kondisi | Label | Aksi |
|---|---|---|
| `is_ready=false` | Belum tersedia | Tombol nonaktif |
| Belum ada attempt | Belum dimulai | Buka lobby |
| `not_started` | Belum dimulai | Buka lobby |
| `in_progress` | Sedang berlangsung | Lanjutkan |
| `submitted` | Sudah dikumpulkan | Lihat konfirmasi |
| `expired` | Waktu habis | Lihat konfirmasi |

### 8.3 Lobby ujian

#### Informasi

- Nama dan jenis ujian.
- Nama peserta.
- Sekolah.
- Durasi.
- Jumlah soal.
- Ketentuan singkat.
- Peringatan bahwa timer berjalan setelah tombol mulai ditekan.

#### Tombol mulai

Request:

```http
POST ?action=start
Content-Type: application/json

{
  "exam_type_id": 1,
  "exam_kind": "main"
}
```

Untuk Tryout:

```json
{
  "exam_type_id": 4,
  "exam_kind": "tryout"
}
```

`exam_type_id` selalu dikirim oleh frontend. `exam_kind` dikirim sebagai
validasi tambahan.

Setelah sukses, arahkan ke `/exam/[id]`.

### 8.4 Ruang ujian

#### Layout desktop

- Header tetap:
  - nama ujian;
  - nama peserta;
  - indikator koneksi;
  - status penyimpanan;
  - timer.
- Area soal utama.
- Daftar pilihan A–E.
- Tombol Sebelumnya dan Berikutnya.
- Panel navigasi nomor soal.
- Tombol Selesai Ujian.

#### Layout seluler

- Header ringkas dengan timer.
- Soal dan pilihan memenuhi lebar layar.
- Navigasi nomor soal dibuka melalui bottom sheet/drawer.
- Tombol Sebelumnya/Berikutnya tetap mudah dijangkau.
- Tombol Submit tidak berdekatan dengan navigasi biasa.

#### Pengambilan data

```http
GET ?action=exam&exam_type_id=1
Authorization: Bearer TOKEN
```

Data penting:

```ts
type ExamQuestion = {
  question_no: number | string;
  question_text: string;
  option_a: string;
  option_b: string;
  option_c: string;
  option_d: string;
  option_e: string;
};
```

Frontend harus menolak atau melaporkan respons jika terdapat
`correct_option`.

#### Pemilihan jawaban

- Klik pilihan langsung memperbarui state lokal.
- Pilihan terpilih mempunyai kontras warna dan ikon yang jelas.
- Pengguna dapat mengganti jawaban.
- Jawaban dikunci hanya setelah ujian selesai.

#### Navigasi soal

| Tampilan nomor | Arti |
|---|---|
| Netral | Belum dijawab |
| Hijau/terisi | Sudah dijawab |
| Outline kuat | Soal aktif |
| Ditandai | Ragu-ragu, hanya state lokal |

Fitur “ragu-ragu” bersifat lokal karena API belum memiliki field khusus.

### 8.5 Halaman selesai

Menampilkan:

- Nama ujian.
- Status `submitted` atau `expired`.
- Waktu pengumpulan jika tersedia.
- Pesan bahwa jawaban berhasil diterima.
- Tombol kembali ke daftar ujian.
- Tombol isi survey jika `survey_submitted=false`.
- Tombol logout.

Tidak menampilkan skor karena API belum menyediakan skor.

### 8.6 Survey setelah ujian

Frontend mengambil eligibility dan konfigurasi form melalui:

```http
GET ?action=survey&exam_type_id=[id]
Authorization: Bearer TOKEN
```

Halaman hanya menampilkan form bila `eligible=true` dan `submitted=false`.
Form terdiri dari rating 1–5, kesan wajib, dan saran opsional. Konfigurasi label,
required, serta panjang maksimal mengikuti object `fields` dari API.

Submit:

```http
POST ?action=survey
Authorization: Bearer TOKEN
Content-Type: application/json

{
  "exam_type_id": 1,
  "rating": 5,
  "kesan": "Pelaksanaan ujian berjalan baik.",
  "saran": "Pertahankan kualitas sistem."
}
```

Setelah HTTP `201`, tampilkan ucapan terima kasih dan cegah pengiriman ulang.
HTTP `409` dengan `submitted=true` diperlakukan sebagai survey yang sudah
selesai, bukan sebagai kehilangan data.

## 9. Timer

Timer harus menggunakan waktu server, bukan hanya jam perangkat peserta.

API mengirim:

- `server_time`
- `ends_at`

Perhitungan:

```ts
const serverOffsetMs = parseServerTime(serverTime) - Date.now();
const currentServerTime = Date.now() + serverOffsetMs;
const remainingMs = endsAtMs - currentServerTime;
```

### Ketentuan timer

- Hitung ulang setiap 1 detik.
- Jangan mengurangi angka secara lokal tanpa koreksi waktu server.
- Saat memuat ulang data ujian, perbarui offset.
- Tampilkan format `HH:MM:SS`.
- Peringatan visual pada sisa 10 menit dan 3 menit.
- Saat mencapai nol:
  1. hentikan input;
  2. lakukan autosave terakhir jika memungkinkan;
  3. panggil submit;
  4. jika API menyatakan expired, arahkan ke halaman selesai.

Timestamp API saat ini mengikuti timezone server `Asia/Jakarta`. Parser frontend
harus memperlakukannya sebagai waktu server tersebut. Peningkatan backend yang
direkomendasikan adalah mengirim timestamp ISO 8601 lengkap dengan offset.

## 10. Autosave dan ketahanan koneksi

Endpoint:

```http
POST ?action=save
Content-Type: application/json
Authorization: Bearer TOKEN

{
  "exam_type_id": 1,
  "answers": {
    "1": "A",
    "2": "C"
  }
}
```

API mengganti snapshot jawaban dengan object terbaru. Frontend harus selalu
mengirim seluruh jawaban, bukan hanya jawaban yang baru berubah.

### Strategi penyimpanan

- Debounce 800–1200 ms setelah jawaban berubah.
- Autosave berkala setiap 20 detik jika terdapat perubahan.
- Simpan sebelum perpindahan halaman dan sebelum submit.
- Hanya satu request save berjalan dalam satu waktu.
- Jika ada perubahan ketika request berjalan, kirim snapshot terbaru setelah
  request selesai.

### Status penyimpanan

| Status | Tampilan |
|---|---|
| `idle` | Semua jawaban tersimpan |
| `dirty` | Belum tersimpan |
| `saving` | Menyimpan… |
| `saved` | Tersimpan HH:mm:ss |
| `offline` | Koneksi terputus, jawaban belum terkirim |
| `error` | Gagal menyimpan, mencoba kembali |

### Retry

- Retry otomatis dengan jeda 2, 4, 8, lalu maksimal 15 detik.
- Jangan menampilkan banyak toast untuk kegagalan yang sama.
- Simpan snapshot jawaban sementara di `sessionStorage` berdasarkan
  `user_id + exam_type_id`.
- Setelah koneksi kembali, bandingkan state server dan state lokal, lalu kirim
  snapshot lokal terbaru jika attempt masih `in_progress`.
- Hapus cache lokal setelah submit sukses.

## 11. Submit ujian

### Alur

1. Pengguna menekan Selesai Ujian.
2. Modal menampilkan jumlah terjawab dan belum terjawab.
3. Pengguna melakukan konfirmasi final.
4. Frontend melakukan save terakhir.
5. Frontend memanggil:

```http
POST ?action=submit

{
  "exam_type_id": 1
}
```

6. Setelah sukses, state ujian dikunci dan cache jawaban dihapus.
7. Arahkan ke `/exam/[id]/completed`.

### Ketentuan

- Submit tidak dapat dibatalkan.
- Tombol submit dinonaktifkan selama proses berlangsung.
- Double click tidak boleh menghasilkan request ganda.
- Jika save terakhir gagal, tampilkan peringatan dan tawarkan:
  - Coba simpan lagi; atau
  - Tetap submit jika API masih dapat dijangkau.
- HTTP `409` diperlakukan sebagai status attempt yang sudah tidak dapat
  dikerjakan.

## 12. API client

Gunakan satu wrapper API, misalnya:

```ts
type ApiResponse<T> =
  | { success: true; data: T; message?: string }
  | { success: false; error: string; finished?: boolean; status?: string };
```

Tanggung jawab API client:

- Menambahkan base URL.
- Menambahkan bearer token.
- Mengirim dan membaca JSON.
- Mengubah respons non-2xx menjadi typed error.
- Menangani HTTP 401 secara global.
- Tidak mencetak token atau password.
- Mendukung `AbortController`.

Contoh kontrak:

```ts
interface ExamSummary {
  id: number;
  name: string;
  kind: 'main' | 'tryout';
  description: string | null;
  duration_minutes: number;
  question_count: number;
  is_default: boolean;
  ready_questions: number;
  is_ready: boolean;
}

type AttemptStatus =
  | 'not_started'
  | 'in_progress'
  | 'submitted'
  | 'expired';
```

## 13. State management

Store minimum:

### Auth store

```ts
{
  token: string | null;
  expiresAt: string | null;
  user: User | null;
  status: 'loading' | 'authenticated' | 'anonymous';
}
```

### Exams store

```ts
{
  exams: ExamSummary[];
  attempts: Attempt[];
  loading: boolean;
  error: string | null;
}
```

### Active exam store

```ts
{
  exam: ExamSummary | null;
  questions: ExamQuestion[];
  answers: Record<string, 'A' | 'B' | 'C' | 'D' | 'E'>;
  currentQuestion: number;
  markedQuestions: Set<number>;
  endsAt: string | null;
  serverOffsetMs: number;
  saveState: 'idle' | 'dirty' | 'saving' | 'saved' | 'offline' | 'error';
  submitting: boolean;
}
```

State Ujian Utama dan Tryout tidak boleh memakai cache key yang sama.

## 14. Komponen utama

```text
AppShell
├── AuthGuard
├── ConnectionIndicator
├── ToastRegion
├── LoginForm
├── ExamList
│   ├── ExamSection
│   └── ExamCard
├── ExamLobby
├── ExamHeader
│   ├── CountdownTimer
│   └── SaveStatus
├── QuestionPanel
│   └── AnswerOption
├── QuestionNavigator
├── MobileQuestionDrawer
├── SubmitConfirmationModal
└── CompletedPanel
```

## 15. UX dan aksesibilitas

- Semua tombol dapat digunakan dengan keyboard.
- Fokus terlihat jelas.
- Pilihan jawaban menggunakan elemen button atau radio yang semantik.
- Modal mengunci fokus dan dapat ditutup dengan Escape sebelum submit dimulai.
- Timer mempunyai `aria-live="polite"` tanpa membacakan setiap detik.
- Peringatan 10 dan 3 menit diumumkan melalui live region.
- Kontras minimum mengikuti WCAG AA.
- Target sentuh minimum 44×44 px.
- Jangan hanya menggunakan warna untuk membedakan status soal.
- Pertahankan scroll position ketika berpindah nomor jika relevan.

## 16. Keamanan frontend

- Production wajib HTTPS.
- Jangan menyimpan token di `localStorage`.
- Jangan memasukkan token ke query string.
- Jangan merender HTML dari soal menggunakan `{@html}`.
- Teks soal dan opsi dirender sebagai plain text.
- Terapkan Content Security Policy yang ketat.
- Jangan memuat analytics pihak ketiga di halaman ujian.
- Bersihkan seluruh state saat logout atau HTTP 401.
- Jangan mempercayai timer atau status attempt dari cache lokal.
- Jangan pernah menghitung atau menyimpan kunci jawaban di frontend.

## 17. Penanganan error global

| Kondisi | Perilaku |
|---|---|
| Tidak ada koneksi | Banner persisten dan retry |
| HTTP 400/422 | Tampilkan pesan validasi API |
| HTTP 401 | Bersihkan token dan ke login |
| HTTP 403 | Tampilkan alasan akses ditolak |
| HTTP 404 | Tampilkan ujian tidak ditemukan |
| HTTP 409 | Sinkronkan ulang status attempt |
| HTTP 429 | Tampilkan waktu tunggu login |
| HTTP 500 | Tampilkan pesan server dan tombol coba lagi |
| JSON invalid | Catat error tanpa data sensitif dan tampilkan fallback |

## 18. Acceptance criteria

### Autentikasi

- [ ] Peserta dapat login menggunakan `token_login`.
- [ ] Token otomatis dipakai pada request terlindungi.
- [ ] Login salah menampilkan pesan tanpa menghapus input username.
- [ ] HTTP 401 mengembalikan peserta ke halaman login.
- [ ] Logout mencabut token dan membersihkan state.

### Daftar ujian

- [ ] Ujian Utama dan Tryout ditampilkan terpisah.
- [ ] Ujian yang belum siap tidak dapat dimulai.
- [ ] Status attempt sesuai hasil endpoint `me`.
- [ ] Beberapa Tryout dapat dipilih melalui `exam_type_id` masing-masing.

### Pengerjaan

- [ ] Start mengirim `exam_type_id` dan `exam_kind`.
- [ ] Soal dapat dinavigasi melalui tombol dan panel nomor.
- [ ] Jawaban yang dipilih terlihat jelas.
- [ ] Jumlah terjawab diperbarui secara real time.
- [ ] Refresh memulihkan jawaban dari API.
- [ ] Kunci jawaban tidak ada di response atau state.

### Timer

- [ ] Timer menggunakan `server_time` dan `ends_at`.
- [ ] Refresh tidak mengulang durasi.
- [ ] Peringatan muncul pada 10 dan 3 menit.
- [ ] Input terkunci saat waktu habis.

### Autosave

- [ ] Jawaban disimpan otomatis.
- [ ] UI membedakan saving, saved, offline, dan error.
- [ ] Request save tidak tumpang tindih.
- [ ] Koneksi pulih memicu sinkronisasi ulang.

### Submit

- [ ] Modal menunjukkan jumlah terjawab/belum terjawab.
- [ ] Submit memerlukan konfirmasi.
- [ ] Save terakhir dilakukan sebelum submit.
- [ ] Double submit dicegah.
- [ ] Submit sukses menuju halaman selesai.

### Survey

- [ ] Survey hanya tampil setelah attempt selesai.
- [ ] Rating 1–5 dan kesan divalidasi sebelum dikirim.
- [ ] Setiap attempt hanya dapat mengirim satu survey.
- [ ] HTTP 201 menampilkan ucapan terima kasih.
- [ ] Status `survey_submitted` dipulihkan setelah refresh.

## 19. Strategi pengujian

### Unit test

- Parser response API.
- Perhitungan offset dan sisa waktu.
- Reducer/state jawaban.
- Pemilihan status kartu ujian.
- Retry/backoff autosave.
- Validasi route berdasarkan status attempt.

### Integration test

- API client menambahkan token.
- HTTP 401 membersihkan auth.
- Autosave mengirim seluruh snapshot jawaban.
- Refresh memulihkan attempt.
- Mismatch `exam_type_id` dan `exam_kind` menampilkan error.

### End-to-end test

1. Login sukses.
2. Pilih Ujian Utama.
3. Mulai ujian.
4. Jawab beberapa soal.
5. Refresh dan pastikan jawaban pulih.
6. Submit dan pastikan status selesai.
7. Login peserta lain dan pilih Tryout.
8. Simulasikan jaringan offline/online.
9. Simulasikan token kedaluwarsa.
10. Uji viewport desktop dan seluler.

Pengujian submit harus memakai akun dan jenis ujian khusus QA karena submit
bersifat final.

## 20. Tahapan implementasi

### Fase 1 — Fondasi

- Setup SvelteKit dan TypeScript.
- Environment config.
- API client.
- Auth store dan route guard.
- Login dan logout.

### Fase 2 — Pemilihan ujian

- Daftar ujian.
- Penggabungan data exam dan attempt.
- Lobby Ujian Utama/Tryout.
- Start exam.

### Fase 3 — CBT

- Ruang soal.
- Pilihan jawaban.
- Navigasi.
- Timer server.
- Responsive layout.

### Fase 4 — Ketahanan

- Autosave.
- Retry.
- Offline indicator.
- Restore setelah refresh.
- Submit final.

### Fase 5 — Quality assurance

- Accessibility audit.
- Unit/integration/E2E test.
- Performance test.
- Security review.
- Uji production HTTPS dan CORS.

## 21. Keputusan yang perlu dikonfirmasi sebelum production

1. Apakah Tryout yang selesai boleh diulang?
2. Apakah peserta boleh melihat skor setelah submit?
3. Apakah soal perlu mendukung gambar atau rich text?
4. Apakah urutan soal dan pilihan perlu diacak?
5. Apakah fitur tandai ragu-ragu harus disimpan di server?
6. Ke mana peserta diarahkan setelah Ujian Utama selesai?
7. Apakah akun peserta boleh aktif pada beberapa perangkat sekaligus?
8. Apakah frontend akan satu domain dengan API atau domain terpisah?

Perubahan untuk retry, skor, rich text, randomisasi, dan multi-device memerlukan
kontrak API tambahan dan tidak boleh diasumsikan hanya dari sisi frontend.
