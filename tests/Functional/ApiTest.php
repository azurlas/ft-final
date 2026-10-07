<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiTest extends WebTestCase
{
    public function testAuthorizationEnrollmentAndIdentity(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/courses');
        self::assertResponseIsSuccessful();
        $login = 'functional-'.bin2hex(random_bytes(6));
        $client->jsonRequest('POST', '/api/register', ['login' => $login,'username' => 'Functional Student','password' => 'StudentPass123!']);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/login', ['login' => $login,'password' => 'StudentPass123!']);
        self::assertResponseIsSuccessful();
        $token = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();
        self::assertSame($login, json_decode($client->getResponse()->getContent(), true)['login']);
        $client->jsonRequest('POST', '/api/courses', ['name' => 'Forbidden']);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', '/api/courses/1/enroll');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/api/courses/1/assignments');
        self::assertResponseIsSuccessful();
        $assignments = json_decode($client->getResponse()->getContent(), true);
        $assignmentId = $assignments[0]['id'];
        $path = tempnam(sys_get_temp_dir(), 'assignment-');
        file_put_contents($path, str_repeat('x', 10000001));
        $client->request('POST', '/api/assignments/'.$assignmentId.'/submit', [], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'any.bin', null, null, true)]);
        self::assertResponseStatusCodeSame(400);
        file_put_contents($path, str_repeat('x', 10000000));
        $client->request('POST', '/api/assignments/'.$assignmentId.'/submit', [], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'arbitrary.format', null, null, true)]);
        self::assertResponseStatusCodeSame(201);
        $submission = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(10000000, $submission['sizeBytes']);
        file_put_contents($path, 'duplicate');
        $client->request('POST', '/api/assignments/'.$assignmentId.'/submit', [], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'second.bin', null, null, true)]);
        self::assertResponseStatusCodeSame(409);
        $client->request('GET', '/api/submissions/'.$submission['id'].'/download');
        self::assertResponseIsSuccessful();
        self::assertSame($submission['sha256'], hash('sha256', $client->getInternalResponse()->getContent()));
        $client->jsonRequest('POST', '/api/login', ['login' => 'other','password' => 'StudentPass123!']);
        $other = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$other);
        $client->request('GET', '/api/submissions/'.$submission['id']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/submissions/'.$submission['id'].'/download');
        self::assertResponseStatusCodeSame(404);
        $client->jsonRequest('POST', '/api/login', ['login' => 'admin','password' => 'AdminPass123!']);
        $admin = json_decode($client->getResponse()->getContent(), true)['token'];
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$admin);
        $client->request('POST', '/api/assignments/'.$assignmentId.'/submit', [], ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'admin.bin', null, null, true)]);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', '/api/grades', ['assignmentId' => $assignmentId,'userId' => $submission['userId'],'grade' => 101]);
        self::assertResponseStatusCodeSame(400);
        $client->jsonRequest('POST', '/api/grades', ['assignmentId' => $assignmentId,'userId' => $submission['userId'],'grade' => 87,'comment' => '<script>escaped</script>']);
        self::assertResponseStatusCodeSame(201);
        $grade = json_decode($client->getResponse()->getContent(), true);
        $client->request('GET', '/api/courses/1/report');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF-', $client->getResponse()->getContent());
        if (!is_dir('output/reports')) {
            mkdir('output/reports', 0775, true);
        } file_put_contents('output/reports/course-report.pdf', $client->getResponse()->getContent());
        $client->request('DELETE', '/api/grades/'.$grade['id']);
        self::assertResponseStatusCodeSame(204);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);
        $client->jsonRequest('POST', '/api/courses/2/enroll');
        self::assertResponseStatusCodeSame(409);
        $client->jsonRequest('POST', '/api/courses/2/purchase', ['beneficiaryName' => 'Demo','cardNumber' => '9900000000000010','expiryDate' => '12/2035','cvv' => '123'], ['HTTP_IDEMPOTENCY_KEY' => 'functional-'.bin2hex(random_bytes(6))]);
        self::assertResponseIsSuccessful();
    }
}
