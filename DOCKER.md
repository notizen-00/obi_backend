# Menjalankan aplikasi dengan Docker

Kebutuhan: Docker Desktop atau Docker Engine dengan Docker Compose v2.

1. Salin `.env.example` menjadi `.env`, lalu ganti kedua password database.
2. Dari folder `obi_backend`, build dan jalankan database, backend, dan frontend:

   ```bash
   docker compose up -d --build
   ```

3. Buka frontend di `http://localhost:3000` dan phpMyAdmin di
   `http://localhost:8081`. Frontend menggunakan API di
   `http://localhost:8080/_/api.php`. Port frontend, backend, dan phpMyAdmin
   dapat diubah lewat `FRONTEND_PORT`, `APP_PORT`, dan `PHPMYADMIN_PORT`.
   Login phpMyAdmin menggunakan nilai `DB_USER` dan `DB_PASSWORD` dari `.env`.

Untuk domain deployment, sesuaikan `ORIGIN`, `PUBLIC_CBT_API_BASE_URL`, dan
`CBT_ALLOWED_ORIGINS` di `.env`. Nilai `CBT_ALLOWED_ORIGINS` dapat memuat
beberapa origin dipisahkan koma. URL API harus dapat dijangkau oleh browser
pengguna.

Service `migrate` membuat tabel ujian setelah MariaDB sehat, kemudian selesai
dengan status sukses. Ini normal. Data MariaDB disimpan dalam named volume
`mariadb_data`; file aplikasi dan upload tetap berada di direktori proyek.

## Perintah berguna

```bash
# Melihat status dan log
docker compose ps
docker compose logs -f frontend nginx php database migrate phpmyadmin

# Menjalankan ulang migrasi setelah perubahan schema
docker compose run --rm migrate

# Menghentikan container tanpa menghapus data
docker compose down
```

Untuk menghapus database lokal dan membuat ulang dari nol, gunakan
`docker compose down -v`, lalu jalankan kembali perintah `up`. Perintah tersebut
menghapus seluruh data MariaDB di volume Docker.

Pada Linux, bila upload gagal karena permission, berikan akses tulis ke user
PHP-FPM pada folder `uploads` (UID 82 pada image Alpine).
