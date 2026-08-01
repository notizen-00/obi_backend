# CBT JSON API

Base URL:

```text
/obi-archive/_/api.php
```

Pada development lokal, HTTP diperbolehkan untuk `localhost`, `127.0.0.1`,
dan domain Laragon berakhiran `.test` selama request berasal dari mesin yang
sama. Contoh:

```text
http://obi-archive.test/_/api.php
```

HTTPS tetap dapat digunakan secara opsional di lokal dan tetap diwajibkan pada
server non-lokal.

## Migrasi database saat deploy

API tidak menjalankan `CREATE TABLE`, pemeriksaan `information_schema`, atau
migrasi database pada setiap request. Jalankan migrasi satu kali setelah upload
kode:

```bash
php db/migrate_exam.php
```

Di CloudPanel, gunakan binary yang sesuai dengan versi PHP situs bila perintah
`php` mengarah ke versi lain, misalnya:

```bash
php8.3 db/migrate_exam.php
```

Untuk development lokal saja, migrasi otomatis dapat diaktifkan dengan
environment variable berikut:

```text
CBT_AUTO_MIGRATE=1
```

Jangan aktifkan `CBT_AUTO_MIGRATE` di production karena pemeriksaan schema
menambah query dan lock yang tidak diperlukan pada setiap request.

Pembersihan login attempt dan token lama dijalankan di luar request API:

```bash
php db/cleanup_exam_api.php
```

Di production, jadwalkan perintah cleanup tersebut satu kali per hari melalui
Cron Jobs CloudPanel.

CORS API hanya mengizinkan frontend dari origin berikut:

```text
https://exam.obi.apps.unej.ac.id
```

Origin lain, termasuk `http`, `localhost`, domain `.test`, port berbeda, dan
subdomain lain akan ditolak dengan HTTP `403`.

API menggunakan session cookie PHP. Frontend browser harus mengirim cookie:

```js
fetch(url, {
  method: 'POST',
  credentials: 'include',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify(data)
})
```

## Endpoint

### Daftar ujian aktif

```http
GET api.php?action=exams
```

Tidak memerlukan login. Field `is_ready` menunjukkan apakah jumlah soal aktif sudah lengkap.
Ujian dengan `is_active=0` tidak dikirim oleh endpoint ini dan attempt miliknya
juga tidak dimasukkan dalam response endpoint `me`.

### Login

Login berbasis session cookie:

```http
POST api.php?action=login
Content-Type: application/json

{"username":"peserta","password":"rahasia"}
```

Login berbasis bearer token:

```http
POST api.php?action=token_login
Content-Type: application/json

{"username":"peserta","password":"rahasia"}
```

Respons `token_login` mengandung `access_token`, `token_type`, `expires_in`, dan
`expires_at`. Simpan token di memori aplikasi bila memungkinkan, lalu kirim pada
endpoint yang memerlukan login:

```http
Authorization: Bearer ACCESS_TOKEN
```

Contoh:

```js
const response = await fetch(`${baseUrl}?action=me`, {
  headers: {Authorization: `Bearer ${accessToken}`}
});
```

Token disimpan dalam bentuk hash di database, otomatis kedaluwarsa, dan dapat
dicabut melalui endpoint logout. Default masa berlaku adalah 8 jam. Nilainya
dapat diatur antara 5 menit hingga 24 jam:

```text
CBT_TOKEN_TTL=28800
```

Login dibatasi maksimal lima kegagalan per kombinasi username dan alamat IP
dalam 15 menit.

### Cek username berdasarkan NIK

```http
POST api.php?action=check_username
Content-Type: application/json

{"nik":"1234567890123456"}
```

NIK wajib berisi tepat 16 digit. Jika akun ujian aktif ditemukan, responsnya:

```json
{
  "success": true,
  "data": {"username": "peserta"}
}
```

### Lupa password

Gunakan username dari endpoint `check_username`, lalu kirim password baru:

```http
POST api.php?action=forgot_password
Content-Type: application/json

{
  "nik": "1234567890123456",
  "username": "peserta",
  "email": "peserta@example.com",
  "password": "password-baru",
  "confirm_password": "password-baru"
}
```

NIK, username, dan email harus cocok dengan akun ujian aktif. Password baru
minimal enam karakter dan konfirmasinya harus sama. Setelah reset berhasil,
seluruh bearer token milik peserta dicabut sehingga peserta harus login kembali.
Pencarian username dan reset password masing-masing dibatasi maksimal lima
kegagalan per kombinasi NIK dan alamat IP dalam 15 menit.

### Profil dan riwayat attempt

```http
GET api.php?action=me
```

### Mulai ujian

```http
POST api.php?action=start
Content-Type: application/json

{"exam_type_id":1,"exam_kind":"main"}
```

Parameter `exam_kind` menerima `main` atau `tryout`:

- `exam_type_id` disarankan dan wajib jika ada beberapa tryout aktif.
- `exam_kind: "main"` tanpa ID otomatis memilih Ujian Utama/default yang aktif.
- `exam_kind: "tryout"` tanpa ID dapat digunakan bila hanya ada satu tryout aktif.
- Jika ID dan kategori dikirim bersama, API memastikan keduanya sesuai.

Contoh memilih satu tryout tertentu:

```json
{"exam_type_id":4,"exam_kind":"tryout"}
```

### Ambil soal dan jawaban tersimpan

```http
GET api.php?action=exam&exam_type_id=1
```

Kunci jawaban tidak pernah dikirim ke frontend.

### Autosave jawaban

```http
POST api.php?action=save
Content-Type: application/json

{
  "exam_type_id":1,
  "answers":{"1":"A","2":"C","3":"B"}
}
```

### Kumpulkan ujian

```http
POST api.php?action=submit
Content-Type: application/json

{"exam_type_id":1}
```

Respons submit memberikan `survey_required: true`. Frontend kemudian membuka
form survey untuk `exam_type_id` yang sama.

### Status dan konfigurasi survey

```http
GET api.php?action=survey&exam_type_id=1
Authorization: Bearer ACCESS_TOKEN
```

Respons berisi:

- `eligible`: hanya `true` setelah attempt berstatus `submitted` atau `expired`;
- `submitted`: menandakan survey sudah pernah dikirim;
- `survey`: jawaban survey yang sudah tersimpan atau `null`; dan
- `fields`: aturan field serta pilihan rating untuk membangun form frontend.

### Kirim survey

```http
POST api.php?action=survey
Authorization: Bearer ACCESS_TOKEN
Content-Type: application/json

{
  "exam_type_id": 1,
  "rating": 5,
  "kesan": "Pelaksanaan ujian berjalan baik.",
  "saran": "Pertahankan kualitas sistem."
}
```

Ketentuan:

- rating wajib bernilai 1–5;
- kesan wajib diisi, maksimal 2000 karakter;
- saran opsional, maksimal 2000 karakter;
- attempt harus sudah selesai; dan
- setiap attempt hanya dapat mengirim satu survey.

Submit sukses menggunakan HTTP `201`. Pengiriman kedua untuk attempt yang sama
menghasilkan HTTP `409`.

### Logout

```http
POST api.php?action=logout
```

Kirim header `Authorization: Bearer ...` agar token tersebut langsung dicabut.

## Format respons

Berhasil:

```json
{"success":true,"data":{}}
```

Gagal:

```json
{"success":false,"error":"Pesan kesalahan"}
```

Status HTTP yang digunakan antara lain `200`, `204`, `400`, `401`, `403`, `404`, `409`, `422`, dan `500`.

## Frontend pada domain berbeda

CORS dikunci ke `https://exam.obi.apps.unej.ac.id` dan tidak dapat ditambah
melalui environment variable. Karena autentikasi memakai cookie, wildcard (`*`)
tidak digunakan. Jika frontend dan API benar-benar cross-site, server wajib
memakai HTTPS dan atur:

```text
CBT_COOKIE_SAMESITE=None
```

Untuk frontend satu domain atau subdomain dalam site yang sama, nilai default `Lax` dapat digunakan.

Jika HTTPS dihentikan oleh reverse proxy dan PHP menerima request internal melalui
HTTP, aktifkan kepercayaan terhadap header `X-Forwarded-Proto` hanya pada server
yang proxy-nya sudah dikonfigurasi dengan benar:

```text
CBT_TRUST_PROXY_HTTPS=1
```
