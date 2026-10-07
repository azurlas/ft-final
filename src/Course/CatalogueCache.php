<?php

declare(strict_types=1);

namespace App\Course;

use App\RuntimeProfile;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CatalogueCache
{
    public function __construct(private RuntimeProfile $profile, private LoggerInterface $logger, #[Autowire('%env(REDIS_CACHE_URL)%')] private string $url)
    {
    }
    public function read(string $identity, callable $load): array
    {
        $key = 'catalogue:'.hash('sha256', $identity);
        try {
            $redis = new \Redis();
            $p = parse_url($this->url);
            $redis->connect($p['host'], $p['port'] ?? 6379, 0.2);
            $redis->setOption(\Redis::OPT_READ_TIMEOUT, 0.2);
            $data = $redis->get($key);
            if ($data !== false) {
                return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (\Throwable $e) {
            if (!$this->profile->resilient()) {
                throw $e;
            } $this->logger->warning('cache.fallback');
            $redis = null;
        }
        $rows = $load();
        if ($redis) {
            try {
                $redis->setex($key, 30, json_encode($rows, JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                if (!$this->profile->resilient()) {
                    throw $e;
                }
            }
        }
        return $rows;
    }
    public function invalidate(): void
    {
        try {
            $r = new \Redis();
            $p = parse_url($this->url);
            $r->connect($p['host'], $p['port'] ?? 6379, 0.2);
            $r->setOption(\Redis::OPT_READ_TIMEOUT, 0.2);
            $it = null;
            do {
                $keys = $r->scan($it, 'catalogue:*', 100);
                if ($keys) {
                    $r->del($keys);
                }
            } while ($it !== 0);
        } catch (\Throwable) {
            $this->logger->warning('cache.invalidation_failed');
        }
    }
}
