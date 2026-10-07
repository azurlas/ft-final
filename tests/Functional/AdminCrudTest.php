<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminCrudTest extends WebTestCase
{
    public function testCrudDeadlineAndPrivilegeBoundaries(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
        $login = 'crud-'.bin2hex(random_bytes(6));
        $client->jsonRequest('POST', '/api/register', ['login' => $login,'username' => 'Student','password' => 'StudentPass123!','isAdmin' => true]);
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', '/api/register', ['login' => $login,'username' => 'Student','password' => 'StudentPass123!']);
        self::assertResponseStatusCodeSame(201);
        $student = json_decode($client->getResponse()->getContent(), true);
        $client->jsonRequest('POST', '/api/login', ['login' => 'admin','password' => 'AdminPass123!']);
        $admin = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$admin);
        $client->jsonRequest('POST', '/api/courses', ['name' => 'CRUD '.bin2hex(random_bytes(4)),'price' => null]);
        self::assertResponseStatusCodeSame(201);
        $course = json_decode($client->getResponse()->getContent(), true);
        $client->request('PATCH', '/api/courses/'.$course['id'], [], [], ['CONTENT_TYPE' => 'application/merge-patch+json'], json_encode(['price' => 1200]));
        self::assertResponseIsSuccessful();
        self::assertSame(1200, json_decode($client->getResponse()->getContent(), true)['price']);
        $client->request('PATCH', '/api/courses/'.$course['id'], [], [], ['CONTENT_TYPE' => 'application/merge-patch+json'], json_encode(['price' => null]));
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/assignments', ['name' => 'Past deadline','courseId' => $course['id'],'deadline' => '2020-01-01T00:00:00Z','description' => 'Deadline test']);
        self::assertResponseStatusCodeSame(201);
        $assignment = json_decode($client->getResponse()->getContent(), true);
        $client->jsonRequest('POST', '/api/grades', ['userId' => $student['id'],'assignmentId' => $assignment['id'],'grade' => 50]);
        self::assertResponseStatusCodeSame(409);
        $client->jsonRequest('POST', '/api/login', ['login' => $login,'password' => 'StudentPass123!']);
        $token = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $client->jsonRequest('POST', '/api/courses/'.$course['id'].'/enroll');
        self::assertResponseIsSuccessful();
        $path = tempnam(sys_get_temp_dir(), 'deadline-');
        file_put_contents($path, 'past');
        $client->request('POST', '/api/assignments/'.$assignment['id'].'/submit', [], ['file' => new UploadedFile($path, 'file', null, null, true)]);
        self::assertResponseStatusCodeSame(409);
        unlink($path);
        $client->request('GET', '/api/courses/'.$course['id'].'/report');
        self::assertResponseStatusCodeSame(403);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$admin);
        $client->request('DELETE', '/api/assignments/'.$assignment['id']);
        self::assertResponseStatusCodeSame(204);
        $client->request('GET', '/api/assignments/'.$assignment['id']);
        self::assertResponseStatusCodeSame(404);
        $client->request('DELETE', '/api/courses/'.$course['id']);
        self::assertResponseStatusCodeSame(204);
        $client->request('GET', '/api/courses/'.$course['id']);
        self::assertResponseStatusCodeSame(404);
    }
}
