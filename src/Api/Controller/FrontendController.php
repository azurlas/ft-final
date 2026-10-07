<?php

declare(strict_types=1);

namespace App\Api\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FrontendController
{
    #[Route('/', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse('/ui/courses.html', 302, ['Cache-Control' => 'no-store']);
    }
}
