# Menjalankan aplikasi dengan Docker

Kebutuhan: Docker Desktop atau Docker Engine dengan Docker Compose v2.

1. Salin `.env.example` menjadi `.env`, lalu ganti kedua password database.
2. Build dan jalankan seluruh service:

   ```bash
   docker compose up -d --build
   ```

3. Buka `http://localhost:8080`. Port dapat diubah lewat `APP_PORT` di `.env`.

Service `migrate` membuat tabel ujian setelah MariaDB sehat, kemudian selesai
dengan status sukses. Ini normal. Data MariaDB disimpan dalam named volume
`mariadb_data`; file aplikasi dan upload tetap berada di direktori proyek.

## Perintah berguna

```bash
# Melihat status dan log
docker compose ps
docker compose logs -f nginx php database migrate

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
