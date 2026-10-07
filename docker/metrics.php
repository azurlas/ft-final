<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
header('Content-Type: text/plain; version=0.0.4');
$kernel = new App\Kernel($_ENV['APP_ENV'] ?? 'prod', false);
try {
    $kernel->boot();
    $manager = $kernel->getContainer()->get('doctrine')->getManager();
    echo (new App\Repository\MetricsRepository($manager))->render();
} catch (Throwable) {
    echo "university_database_up 0\n";
} finally {
    $kernel->shutdown();
}
