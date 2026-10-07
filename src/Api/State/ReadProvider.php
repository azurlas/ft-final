<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\{Operation,GetCollection};
use ApiPlatform\State\ProviderInterface;
use App\Repository\Repositories;
use App\Course\CatalogueCache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<object> */
final readonly class ReadProvider implements ProviderInterface
{
    public function __construct(private Repositories $repositories, private Access $access, private Mapper $mapper, private CatalogueCache $cache)
    {
    }
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array
    {
        $kind = $operation->getExtraProperties()['kind'];
        if (($operation->getExtraProperties()['action'] ?? '') === 'me') {
            return $this->mapper->map('users', $this->access->user());
        }
        $courseId = isset($uriVariables['courseId']) ? (int)$uriVariables['courseId'] : null;
        if ($kind === 'assignments' && $courseId !== null) {
            $this->access->enrolled($courseId);
        }
        $userId = null;
        if (in_array($kind, ['enrollments','grades','purchases','submissions'], true)) {
            $user = $this->access->user();
            $userId = $user->isAdmin ? null : $user->id;
        }
        $collection = $operation instanceof GetCollection;
        $id = $collection ? null : (int)($uriVariables['id'] ?? 0);
        $page = max(1, (int)($context['filters']['page'] ?? 1));
        $load = fn () => $this->repositories->for($kind)->visible($id, $userId, $courseId, $page);
        if ($kind === 'courses') {
            $rows = $this->cache->read('orm-v1:' . json_encode([$id, $page]), fn () => array_map(fn ($e) => get_object_vars($this->mapper->map($kind, $e)), $load()));
            $resources = array_map($this->mapper->cachedCourse(...), $rows);
        } else {
            $rows = $load();
            if (!$collection && $kind === 'assignments' && $rows) {
                $this->access->enrolled($rows[0]->course->id);
            }
            $resources = array_map(fn ($e) => $this->mapper->map($kind, $e), $rows);
        }
        return $collection ? $resources : ($resources[0] ?? throw new NotFoundHttpException());
    }
}
