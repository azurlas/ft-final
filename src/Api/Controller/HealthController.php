<?php
declare(strict_types=1);
namespace App\Api\Controller;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
final class HealthController
{
    #[Route('/health/live', methods: ['GET'])]
    public function live(): JsonResponse { return new JsonResponse(['status' => 'alive']); }
    #[Route('/health/ready', methods: ['GET'])]
    public function ready(Connection $db): JsonResponse
    {
        try { $db->executeQuery('SELECT 1'); return new JsonResponse(['status' => 'ready']); }
        catch (\Throwable) { return new JsonResponse(['status' => 'unavailable'], 503); }
    }
}
