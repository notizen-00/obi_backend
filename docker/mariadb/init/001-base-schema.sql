SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS participants (
    id VARCHAR(32) NOT NULL,
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nama VARCHAR(150) NOT NULL,
    nik VARCHAR(32) NOT NULL,
    tempat_lahir VARCHAR(100) NOT NULL,
    tgl_lahir DATE NOT NULL,
    nama_sekolah VARCHAR(200) NOT NULL,
    alamat_sekolah TEXT NOT NULL,
    alamat_rumah TEXT NOT NULL,
    email VARCHAR(190) NOT NULL,
    no_hp VARCHAR(30) NOT NULL,
    jenis_pembayaran VARCHAR(50) NOT NULL,
    foto_profil VARCHAR(255) NOT NULL,
    bukti_bayar VARCHAR(255) NOT NULL,
    identitas_type VARCHAR(50) NOT NULL,
    identitas_file VARCHAR(255) NOT NULL,
    status_bayar ENUM('pending', 'lunas', 'ditolak') NOT NULL DEFAULT 'pending',
    verified_at DATETIME DEFAULT NULL,
    verified_by VARCHAR(120) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_participants_nik (nik),
    UNIQUE KEY uniq_participants_email (email),
    KEY idx_participants_payment (status_bayar, registered_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exam_users (
    id VARCHAR(32) NOT NULL,
    participant_id VARCHAR(32) NOT NULL,
    nama VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    nik VARCHAR(32) NOT NULL,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_exam_users_participant (participant_id),
    UNIQUE KEY uniq_exam_users_email (email),
    UNIQUE KEY uniq_exam_users_nik (nik),
    UNIQUE KEY uniq_exam_users_username (username),
    CONSTRAINT fk_exam_users_participant FOREIGN KEY (participant_id)
        REFERENCES participants (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
