<?php

declare(strict_types=1);

namespace App\Support\Backup;

/**
 * S13 · off-site yedek ayarları (`config/entegrasyon.php` → `backup`).
 *
 * YAPILANDIRILMAMIŞ = ALARM, SESSİZ GEÇİŞ DEĞİL: bucket, anahtar ya da
 * şifreleme anahtarı dosyası eksikse yedek işi çalışmaz ve 34Devs'e "off-site
 * yedek yapılandırılmadı" bildirimi gider (`missing()` hangi alanın eksik
 * olduğunu söyler — değerini değil).
 *
 * Şifreleme anahtarı (age kimliği) .env'de DEĞİL, ayrı bir dosyadadır;
 * konteynere salt okunur bağlanır. Kopyası işletenin elindedir: sunucu
 * tümden kaybolursa yedeği yalnız o kopya açar (docs/RUNBOOK.md).
 */
final class BackupConfig
{
    public function s3Endpoint(): string
    {
        return (string) config('entegrasyon.backup.s3.endpoint');
    }

    public function s3Region(): string
    {
        return (string) config('entegrasyon.backup.s3.region');
    }

    public function s3Bucket(): string
    {
        return (string) config('entegrasyon.backup.s3.bucket');
    }

    public function s3AccessKey(): string
    {
        return (string) config('entegrasyon.backup.s3.key');
    }

    public function s3SecretKey(): string
    {
        return (string) config('entegrasyon.backup.s3.secret');
    }

    public function s3TimeoutSeconds(): int
    {
        return max(10, (int) config('entegrasyon.backup.s3.timeout_seconds', 300));
    }

    /** Anahtar öneki, sonda `/` ile (ör. `34pazar/`). */
    public function prefix(): string
    {
        $prefix = trim((string) config('entegrasyon.backup.prefix', '34pazar'), '/');

        return $prefix === '' ? '' : $prefix.'/';
    }

    public function identityFile(): string
    {
        return (string) config('entegrasyon.backup.age_identity_file');
    }

    public function workDir(): string
    {
        return (string) config('entegrasyon.backup.work_dir', storage_path('app/backup-tmp'));
    }

    public function healthcheckUrl(): string
    {
        return rtrim((string) config('entegrasyon.backup.healthcheck_url'), '/');
    }

    /** @return list<string> eksik ayarların adları (değerleri değil) */
    public function missing(): array
    {
        $missing = [];

        foreach ([
            'BACKUP_S3_ENDPOINT' => $this->s3Endpoint(),
            'BACKUP_S3_REGION' => $this->s3Region(),
            'BACKUP_S3_BUCKET' => $this->s3Bucket(),
            'BACKUP_S3_KEY' => $this->s3AccessKey(),
            'BACKUP_S3_SECRET' => $this->s3SecretKey(),
        ] as $name => $value) {
            if (trim($value) === '') {
                $missing[] = $name;
            }
        }

        $identity = $this->identityFile();
        if ($identity === '' || ! is_file($identity) || ! is_readable($identity)) {
            $missing[] = 'BACKUP_AGE_IDENTITY_FILE (dosya yok ya da okunamıyor)';
        }

        // Üretimde düz http YASAK (anahtar ve şifreli döküm ağda açık gitmesin).
        // Yerelde MinIO sandbox'ı http ile koşabilsin diye yalnız üretimde.
        if ($this->s3Endpoint() !== '' && app()->isProduction() && ! str_starts_with($this->s3Endpoint(), 'https://')) {
            $missing[] = 'BACKUP_S3_ENDPOINT https:// ile başlamalı';
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missing() === [];
    }
}
