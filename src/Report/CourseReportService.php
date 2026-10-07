<?php

declare(strict_types=1);

namespace App\Report;

use App\Api\State\Access;
use App\Repository\{CourseRepository, CourseEnrollmentRepository};
use Dompdf\{Dompdf,Options};
use Twig\Environment;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class CourseReportService
{
    public function __construct(private Access $access, private CourseRepository $courses, private CourseEnrollmentRepository $enrollments, private Environment $twig)
    {
    }
    public function pdf(int $courseId): string
    {
        $this->access->admin();
        $course = $this->courses->find($courseId) ?? throw new NotFoundHttpException();
        $rows = $this->enrollments->reportRows($course);
        foreach ($rows as &$row) {
            foreach (['deadline', 'submitted_at'] as $field) {
                if ($row[$field] instanceof \DateTimeInterface) {
                    $row[$field] = $row[$field]->format('Y-m-d H:i:s');
                }
            }
        }
        unset($row);
        $averages = $this->enrollments->reportAverages($course);
        $options = new Options(['isRemoteEnabled' => false,'isPhpEnabled' => false,'defaultFont' => 'DejaVu Sans']);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->twig->render('reports/course.html.twig', ['course' => $course,'rows' => $rows,'averages' => $averages,'generated' => gmdate('Y-m-d H:i:s').' UTC']));
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        return $pdf->output();
    }
}
