<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/mysql.php';

$password = getenv('SEED_TEST_PASSWORD') ?: 'Test123!';
if (strlen($password) < 6) {
    fwrite(STDERR, "SEED_TEST_PASSWORD harus minimal 6 karakter.\n");
    exit(1);
}

$database = db();
$database->beginTransaction();

try {
    for ($number = 1; $number <= 3; $number++) {
        $suffix = str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        $participantId = 'TEST-REG-' . $suffix;
        $examUserId = 'TEST-EXAM-' . $suffix;
        $username = 'test' . $number;
        $email = 'test' . $number . '@example.test';
        $nik = '999999999999' . str_pad((string) $number, 4, '0', STR_PAD_LEFT);

        $participantCheck = $database->prepare('SELECT id FROM participants WHERE id = ? OR email = ? OR nik = ? LIMIT 1');
        $participantCheck->execute([$participantId, $email, $nik]);
        $existingParticipant = $participantCheck->fetchColumn();

        if ($existingParticipant === false) {
            $insertParticipant = $database->prepare(
                'INSERT INTO participants (
                    id, registered_at, nama, nik, tempat_lahir, tgl_lahir,
                    nama_sekolah, alamat_sekolah, alamat_rumah, email, no_hp,
                    jenis_pembayaran, foto_profil, bukti_bayar, identitas_type,
                    identitas_file, status_bayar, verified_at, verified_by
                ) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'lunas\', NOW(), ?)'
            );
            $insertParticipant->execute([
                $participantId,
                'test',
                $nik,
                'Jember',
                '2000-01-01',
                'Sekolah Test',
                'Alamat sekolah test',
                'Alamat rumah test',
                $email,
                '08000000000' . $number,
                'transfer',
                'seed/test-profile.png',
                'seed/test-payment.png',
                'kartu_pelajar',
                'seed/test-identity.png',
                'seed',
            ]);
        } elseif ($existingParticipant !== $participantId) {
            throw new RuntimeException("Email/NIK seed peserta $number sudah dipakai oleh data lain.");
        }

        $examCheck = $database->prepare('SELECT id, participant_id, username, email, nik FROM exam_users WHERE participant_id = ? OR username = ? OR email = ? OR nik = ? LIMIT 1');
        $examCheck->execute([$participantId, $username, $email, $nik]);
        $existingExamUser = $examCheck->fetch();

        if ($existingExamUser === false) {
            $insertExamUser = $database->prepare(
                'INSERT INTO exam_users (
                    id, participant_id, nama, email, nik, username,
                    password_hash, created_at, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), \'active\')'
            );
            $insertExamUser->execute([
                $examUserId,
                $participantId,
                'test',
                $email,
                $nik,
                $username,
                password_hash($password, PASSWORD_DEFAULT),
            ]);
        } elseif (
            $existingExamUser['id'] !== $examUserId
            || $existingExamUser['participant_id'] !== $participantId
            || $existingExamUser['username'] !== $username
            || $existingExamUser['email'] !== $email
            || $existingExamUser['nik'] !== $nik
        ) {
            throw new RuntimeException("Username/email/NIK seed akun $number sudah dipakai oleh data lain.");
        }
    }

    $database->commit();
    fwrite(STDOUT, "Seed 3 peserta dan akun ujian selesai. Username: test1, test2, test3. Password: $password\n");
} catch (Throwable $error) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    fwrite(STDERR, "Seed gagal: {$error->getMessage()}\n");
    exit(1);
}
