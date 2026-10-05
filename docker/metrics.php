<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
header('Content-Type: text/plain; version=0.0.4');
try {
    // DBAL 4 no longer parses the url in DriverManager.
    $params=(new Doctrine\DBAL\Tools\DsnParser(['mysql'=>'pdo_mysql']))->parse($_ENV['DATABASE_URL']); $db=Doctrine\DBAL\DriverManager::getConnection($params);
    echo "university_database_up 1\n";
    foreach($db->fetchAllAssociative('SELECT status,COUNT(*) n FROM purchases GROUP BY status') as $r) echo 'university_purchases{status="'.$r['status'].'"} '.$r['n']."\n";
    echo 'university_outbox_pending '.$db->fetchOne('SELECT COUNT(*) FROM outbox WHERE published_at IS NULL')."\n";
    echo 'university_purchase_oldest_pending_seconds '.($db->fetchOne("SELECT COALESCE(MAX(TIMESTAMPDIFF(SECOND,created_at,UTC_TIMESTAMP())),0) FROM purchases WHERE status IN ('pending','processing','retrying')"))."\n";
    foreach($db->fetchAllAssociative('SELECT status,COUNT(*) n,SUM(duration_ms)/1000 seconds FROM payment_attempts GROUP BY status') as $r) { echo 'university_payment_attempts_total{status="'.$r['status'].'"} '.$r['n']."\n"; echo 'university_payment_duration_seconds_sum{status="'.$r['status'].'"} '.$r['seconds']."\n"; }
    echo 'university_circuit_open '.(int)$db->fetchOne('SELECT COUNT(*) FROM payment_circuit WHERE opened_until>UTC_TIMESTAMP()')."\n";
} catch (Throwable) { echo "university_database_up 0\n"; }
