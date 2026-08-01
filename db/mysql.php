<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

const DB_HOST = 'localhost';
#const DB_NAME = 'obibase';
#const DB_USER = 'obirunner';
#const DB_PASS = '.(UX-7]L638wR=D,E+;PFwO/a';
const DB_NAME = 'obibase';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+07:00'");

    return $pdo;
}

function participant_by_email_nik(string $email, string $nik): ?array
{
    $stmt = db()->prepare('SELECT * FROM participants WHERE LOWER(email) = LOWER(?) AND nik = ? LIMIT 1');
    $stmt->execute([$email, $nik]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function exam_account_exists_for_email(string $email): bool
{
    $stmt = db()->prepare('SELECT 1 FROM exam_users WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $stmt->execute([$email]);
    return (bool)$stmt->fetchColumn();
}

function username_exists(string $username): bool
{
    $stmt = db()->prepare('SELECT 1 FROM exam_users WHERE LOWER(username) = LOWER(?) LIMIT 1');
    $stmt->execute([$username]);
    return (bool)$stmt->fetchColumn();
}

function exam_account_validation_error(string $username, string $password, string $confirm): ?string
{
    if ($username === '' || $password === '' || $confirm === '') {
        return 'Username dan password akun ujian wajib diisi.';
    }
    if (strlen($username) < 4 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        return 'Username minimal 4 karakter dan hanya boleh huruf, angka, atau underscore.';
    }
    if (strlen($password) < 6) {
        return 'Password minimal 6 karakter.';
    }
    if ($password !== $confirm) {
        return 'Konfirmasi password tidak cocok.';
    }
    if (username_exists($username)) {
        return 'Username sudah digunakan. Silakan pilih username lain.';
    }
    return null;
}

function create_exam_account(array $participant, string $username, string $password): string
{
    $exam_id = make_public_id('EXAM');
    $stmt = db()->prepare('INSERT INTO exam_users (id, participant_id, nama, email, nik, username, password_hash, created_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)');
    $stmt->execute([
        $exam_id,
        $participant['id'],
        $participant['nama'],
        strtolower($participant['email']),
        $participant['nik'],
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        'active',
    ]);
    return $exam_id;
}

function make_public_id(string $prefix): string
{
    return $prefix . '-' . strtoupper(bin2hex(random_bytes(7)));
}