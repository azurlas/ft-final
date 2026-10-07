<?php

declare(strict_types=1);

namespace App\Storage;

use Aws\S3\S3Client;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class S3Storage
{
    private S3Client $client;
    public function __construct(#[Autowire('%env(S3_ENDPOINT)%')] string $endpoint, #[Autowire('%env(S3_ACCESS_KEY)%')] string $key, #[Autowire('%env(S3_SECRET_KEY)%')] string $secret, #[Autowire('%env(S3_BUCKET)%')] public readonly string $bucket, #[Autowire('%env(S3_BACKUP_BUCKET)%')] public readonly string $backupBucket)
    {
        $this->client = new S3Client(['version' => 'latest','region' => 'us-east-1','endpoint' => $endpoint,'use_path_style_endpoint' => true,'credentials' => ['key' => $key,'secret' => $secret],'http' => ['connect_timeout' => 1,'timeout' => 5],'retries' => 1]);
    }
    public function initialize(): void
    {
        foreach ([$this->bucket,$this->backupBucket] as $bucket) {
            if (!$this->client->doesBucketExistV2($bucket)) {
                $this->client->createBucket(['Bucket' => $bucket]);
            }
        } $this->client->putBucketLifecycleConfiguration(['Bucket' => $this->backupBucket,'LifecycleConfiguration' => ['Rules' => [['ID' => 'expire-mysql-backups','Status' => 'Enabled','Filter' => ['Prefix' => 'mysql/'],'Expiration' => ['Days' => 7]]]]]);
    }
    public function put(string $key, mixed $body, string $contentType): void
    {
        $this->client->putObject(['Bucket' => $this->bucket,'Key' => $key,'Body' => $body,'ContentType' => $contentType]);
    }
    public function get(string $key): mixed
    {
        return $this->client->getObject(['Bucket' => $this->bucket,'Key' => $key])['Body'];
    }
    public function delete(string $key): void
    {
        $this->client->deleteObject(['Bucket' => $this->bucket,'Key' => $key]);
    }
    public function objects(): iterable
    {
        return $this->client->getPaginator('ListObjectsV2', ['Bucket' => $this->bucket,'Prefix' => 'submissions/'])->search('Contents[]');
    }
    public function backup(string $key, mixed $body): void
    {
        $this->client->putObject(['Bucket' => $this->backupBucket,'Key' => $key,'Body' => $body]);
    }
    public function restore(string $key): mixed
    {
        return $this->client->getObject(['Bucket' => $this->backupBucket,'Key' => $key])['Body'];
    }
}
