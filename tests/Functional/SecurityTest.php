<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Misuse scenarios: each must be denied with the expected status and leave an audit record. */
final class SecurityTest extends WebTestCase
{
    public function testBruteForceLoginIsThrottledAndAudited(): void
    {
        $client = self::createClient();
        // A fresh public client address keeps throttling state independent of other tests.
        $ip = '203.0.113.'.random_int(1, 254);
        $client->setServerParameter('HTTP_X_FORWARDED_FOR', $ip);
        $login = 'brute-'.bin2hex(random_bytes(6));
        $client->jsonRequest('POST', '/api/register', ['login' => $login,'username' => 'Brute Force Target','password' => 'StudentPass123!']);
        self::assertResponseStatusCodeSame(201);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $client->jsonRequest('POST', '/api/login', ['login' => $login,'password' => 'wrong-'.$attempt]);
            self::assertResponseStatusCodeSame(401);
        }
        $failed = $this->record('security.authentication_failed');
        self::assertSame($login, $failed->context['login']);
        self::assertSame('BadCredentialsException', $failed->context['reason']);
        self::assertSame($ip, $failed->context['client_ip']);
        self::assertStringNotContainsString('wrong-5', json_encode($failed->context, JSON_THROW_ON_ERROR));

        // The sixth attempt is refused before the password is checked, even if it is correct.
        $client->jsonRequest('POST', '/api/login', ['login' => $login,'password' => 'StudentPass123!']);
        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Retry-After', '60');
        self::assertSame('TooManyLoginAttemptsAuthenticationException', $this->record('security.authentication_failed')->context['reason']);
        self::assertSame(429, $this->record('security.denied')->context['status']);

        // Another account from another address is unaffected.
        $client->setServerParameter('HTTP_X_FORWARDED_FOR', '198.51.100.'.random_int(1, 254));
        $client->jsonRequest('POST', '/api/login', ['login' => 'student','password' => 'StudentPass123!']);
        self::assertResponseIsSuccessful();
    }

    public function testDeniedRequestsAreAudited(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/me');
        self::assertResponseStatusCodeSame(401);
        $denied = $this->record('security.denied');
        self::assertSame(['/api/me', 401, null], [$denied->context['path'], $denied->context['status'], $denied->context['user']]);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer eyJhbGciOiJSUzI1NiJ9.eyJsb2dpbiI6ImFkbWluIn0.forged');
        $client->request('GET', '/api/purchases');
        self::assertResponseStatusCodeSame(401);
        self::assertSame(401, $this->record('security.denied')->context['status']);

        $this->loginAs($client, 'student', 'StudentPass123!');
        $client->jsonRequest('POST', '/api/courses', ['name' => 'Privilege escalation attempt']);
        self::assertResponseStatusCodeSame(403);
        $denied = $this->record('security.denied');
        self::assertSame(['POST', '/api/courses', 403, 'student'], [$denied->context['method'], $denied->context['path'], $denied->context['status'], $denied->context['user']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $denied->context['request_id']);
    }

    private function loginAs(KernelBrowser $client, string $login, string $password): void
    {
        $client->setServerParameter('HTTP_AUTHORIZATION', '');
        $client->jsonRequest('POST', '/api/login', ['login' => $login,'password' => $password]);
        self::assertResponseIsSuccessful();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.json_decode((string) $client->getResponse()->getContent(), true)['token']);
    }

    /** The test handler only holds records of the last request (the kernel reboots per request). */
    private function record(string $message): LogRecord
    {
        $handler = self::getContainer()->get('monolog.handler.test');
        self::assertInstanceOf(TestHandler::class, $handler);
        foreach (array_reverse($handler->getRecords()) as $record) {
            if ($record->message === $message) {
                return $record;
            }
        }
        self::fail('No "'.$message.'" log record for the last request');
    }
}
