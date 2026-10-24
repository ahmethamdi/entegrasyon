<?php

declare(strict_types=1);

namespace Tests\Support\Backup;

use App\Support\Backup\DatabaseSnapshot;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * Off-site yedek testlerinin sahte dünyası: bellekte bir S3 bucket'ı,
 * dosya üreten sahte pg_dump / age / pg_restore, sahte an ve geçici DB.
 *
 * Sahte S3 imzayı DOĞRULAMAZ (imza `S3SignerTest`'te AWS örnekleriyle
 * sınanır) ama her istekte Authorization başlığı olduğunu kaydeder.
 */
final class FakeBackupWorld
{
    public const ENDPOINT = 'https://s3.test.example';

    public const BUCKET = 'yedek-bucket';

    /** @var array<string, string> anahtar => içerik */
    public array $objects = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<array<int, string>> */
    public array $commands = [];

    public int $putStatus = 200;

    public bool $pgDumpFails = false;

    public bool $pgRestoreFails = false;

    public bool $unsignedRequestSeen = false;

    public function install(string $identityFile): void
    {
        file_put_contents($identityFile, "# created: test\nAGE-SECRET-KEY-1TEST\n");

        config([
            'entegrasyon.backup.s3.endpoint' => self::ENDPOINT,
            'entegrasyon.backup.s3.region' => 'eu-central-1',
            'entegrasyon.backup.s3.bucket' => self::BUCKET,
            'entegrasyon.backup.s3.key' => 'AKIA-TEST',
            'entegrasyon.backup.s3.secret' => 'GIZLI-S3-SECRET',
            'entegrasyon.backup.prefix' => '34pazar',
            'entegrasyon.backup.age_identity_file' => $identityFile,
            'entegrasyon.backup.work_dir' => sys_get_temp_dir().'/backup-test-'.getmypid(),
            'entegrasyon.backup.healthcheck_url' => 'https://hc.test/ping/yedek',
        ]);

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_starts_with($url, 'https://hc.test/')) {
                return Http::response('OK');
            }
            if (str_starts_with($url, 'https://ntfy.example')) {
                return Http::response(['id' => 'n']);
            }
            if (! str_starts_with($url, self::ENDPOINT.'/'.self::BUCKET)) {
                return Http::response('beklenmeyen istek', 599);
            }

            if (! $request->hasHeader('Authorization')) {
                $this->unsignedRequestSeen = true;
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
            $key = rawurldecode(ltrim(substr($path, strlen('/'.self::BUCKET)), '/'));

            return match ($request->method()) {
                'PUT' => $this->put($key, $request->body()),
                'GET' => $key === '' ? $this->list() : $this->get($key),
                'DELETE' => $this->delete($key),
                default => Http::response('', 405),
            };
        });

        Process::fake(function (PendingProcess $process) {
            /** @var array<int, string> $command */
            $command = $process->command;
            $this->commands[] = $command;

            return match ($command[0]) {
                'pg_dump' => $this->pgDump($command),
                'age' => $this->age($command),
                'pg_restore' => $this->pgRestoreFails
                    ? Process::result(errorOutput: "pg_restore: error: bozuk arşiv\n", exitCode: 1)
                    : Process::result(),
                default => Process::result(errorOutput: 'bilinmeyen komut', exitCode: 127),
            };
        });
    }

    /** Bucket'a önceden konmuş tam bir yedek (döküm + manifest). */
    public function seedComplete(string $stamp, array $tables = ['tenants' => 2]): void
    {
        $dump = "SIFRELI:{$stamp}";
        $this->objects["34pazar/34pazar-{$stamp}.dump.age"] = $dump;
        $this->objects["34pazar/34pazar-{$stamp}.manifest.json"] = (string) json_encode([
            'version' => 1,
            'stamp' => $stamp,
            'tables' => $tables,
            'total_rows' => array_sum($tables),
            'sha256' => hash('sha256', $dump),
        ]);
    }

    public function snapshot(array $counts = ['tenants' => 2, 'orders' => 5]): DatabaseSnapshot
    {
        return new class($counts) implements DatabaseSnapshot
        {
            public function __construct(private readonly array $counts) {}

            public function capture(callable $work): mixed
            {
                return $work('00000003-0000001B-1', $this->counts);
            }
        };
    }

    /** @param array<string, int|null> $restoredCounts */
    public function scratch(array $restoredCounts): FakeScratchDatabase
    {
        return new FakeScratchDatabase($restoredCounts);
    }

    private function put(string $key, string $body): PromiseInterface
    {
        if ($this->putStatus !== 200) {
            return Http::response('<Error><Code>InternalError</Code></Error>', $this->putStatus);
        }
        $this->objects[$key] = $body;

        return Http::response('', 200);
    }

    private function get(string $key): PromiseInterface
    {
        return isset($this->objects[$key]) ? Http::response($this->objects[$key]) : Http::response('<Error><Code>NoSuchKey</Code></Error>', 404);
    }

    private function delete(string $key): PromiseInterface
    {
        unset($this->objects[$key]);
        $this->deleted[] = $key;

        return Http::response('', 204);
    }

    private function list(): PromiseInterface
    {
        $items = '';
        foreach (array_keys($this->objects) as $key) {
            $items .= '<Contents><Key>'.htmlspecialchars($key).'</Key><Size>1</Size></Contents>';
        }

        return Http::response('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult><IsTruncated>false</IsTruncated>'.$items.'</ListBucketResult>');
    }

    private function pgDump(array $command): ProcessResult
    {
        if ($this->pgDumpFails) {
            return Process::result(errorOutput: "pg_dump: error: connection refused\n", exitCode: 1);
        }
        $file = substr((string) current(array_filter($command, fn ($a) => str_starts_with($a, '--file='))), 7);
        file_put_contents($file, 'PGDMP-DUZ-DOKUM');

        return Process::result();
    }

    private function age(array $command): ProcessResult
    {
        $output = $command[array_search('--output', $command, true) + 1];
        $input = end($command);
        $decrypt = in_array('--decrypt', $command, true);

        file_put_contents($output, $decrypt ? 'PGDMP-COZULMUS' : 'SIFRELI:'.file_get_contents($input));

        return Process::result();
    }
}
