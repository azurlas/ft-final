<?php
declare(strict_types=1);
namespace App\Report;
use App\Api\State\Access;
use Doctrine\DBAL\Connection;
use Dompdf\{Dompdf,Options};
use Twig\Environment;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
final class CourseReportService
{
    public function __construct(private Access $access,private Connection $db,private Environment $twig) {}
    public function pdf(int $courseId): string
    {
        $this->access->admin(); $course=$this->db->fetchAssociative('SELECT * FROM courses WHERE id=?',[$courseId])?:throw new NotFoundHttpException();
        $rows=$this->db->fetchAllAssociative('SELECT u.username,u.login,a.name assignment_name,a.deadline,s.submitted_at,g.grade,g.comment FROM enrollments e JOIN users u ON u.id=e.user_id LEFT JOIN assignments a ON a.course_id=e.course_id LEFT JOIN submissions s ON s.assignment_id=a.id AND s.user_id=u.id LEFT JOIN grades g ON g.assignment_id=a.id AND g.user_id=u.id WHERE e.course_id=? ORDER BY u.id,a.id',[$courseId]);
        $averages=$this->db->fetchAllAssociative('SELECT u.username,AVG(g.grade) average_grade,COUNT(g.id) graded_count FROM enrollments e JOIN users u ON u.id=e.user_id LEFT JOIN assignments a ON a.course_id=e.course_id LEFT JOIN grades g ON g.assignment_id=a.id AND g.user_id=u.id WHERE e.course_id=? GROUP BY u.id,u.username ORDER BY u.id',[$courseId]);
        $options=new Options(['isRemoteEnabled'=>false,'isPhpEnabled'=>false,'defaultFont'=>'DejaVu Sans']); $pdf=new Dompdf($options); $pdf->loadHtml($this->twig->render('reports/course.html.twig',['course'=>$course,'rows'=>$rows,'averages'=>$averages,'generated'=>gmdate('Y-m-d H:i:s').' UTC'])); $pdf->setPaper('A4','landscape'); $pdf->render(); return $pdf->output();
    }
}
