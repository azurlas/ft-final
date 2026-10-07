<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HealthController
{
    #[Route('/health/live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'alive']);
    }
    #[Route('/health/ready', methods: ['GET'])]
    public function ready(UserRepository $users): JsonResponse
    {
        try {
            $users->findOneBy(['login' => 'admin']);
            return new JsonResponse(['status' => 'ready']);
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'unavailable'], 503);
        }
    }
}
