<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Backup;

use App\Support\Backup\S3Signer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * İmza, AWS'nin S3 belgesindeki örnek isteklerle sınanır ("Signature
 * Calculations for the Authorization Header: Transferring Payload in a
 * Single Chunk" — örnek GET Object ve GET Bucket/List Objects). Beklenen
 * imzalar belgeden; kendi kodumuzun çıktısı DEĞİL.
 */
final class S3SignerTest extends TestCase
{
    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private function signer(): S3Signer
    {
        return new S3Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    }

    #[Test]
    public function aws_get_object_example_signature_matches(): void
    {
        $headers = $this->signer()->sign(
            'GET',
            '/test.txt',
            [],
            ['Host' => 'examplebucket.s3.amazonaws.com', 'Range' => 'bytes=0-9'],
            self::EMPTY_SHA256,
            new DateTimeImmutable('2013-05-24T00:00:00Z'),
        );

        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
            .'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
            .'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
            $headers['Authorization'],
        );
        $this->assertSame('20130524T000000Z', $headers['x-amz-date']);
    }

    #[Test]
    public function aws_list_objects_example_signature_matches(): void
    {
        $headers = $this->signer()->sign(
            'GET',
            '/',
            ['max-keys' => '2', 'prefix' => 'J'],
            ['Host' => 'examplebucket.s3.amazonaws.com'],
            self::EMPTY_SHA256,
            new DateTimeImmutable('2013-05-24T00:00:00Z'),
        );

        $this->assertStringEndsWith(
            'Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7',
            $headers['Authorization'],
        );
    }

    #[Test]
    public function path_segments_are_encoded_but_slashes_kept(): void
    {
        $this->assertSame('/bucket/yedek/34pazar%20a%2Bb.dump.age', S3Signer::encodePath('bucket/yedek/34pazar a+b.dump.age'));
        $this->assertSame('a=1&list-type=2&prefix=yedek%2F', S3Signer::encodeQuery(['prefix' => 'yedek/', 'list-type' => '2', 'a' => '1']));
    }
}
