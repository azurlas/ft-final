<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Report\CourseReportService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ReportController
{
    #[Route('/api/courses/{id}/report', methods:['GET'])]
    public function __invoke(int $id, CourseReportService $reports): Response
    {
        return new Response($reports->pdf($id), 200, ['Content-Type' => 'application/pdf','Content-Disposition' => 'attachment; filename="course-'.$id.'-report.pdf"','Cache-Control' => 'private, no-store']);
    }
}
