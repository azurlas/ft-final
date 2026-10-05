<?php
declare(strict_types=1);
namespace App\Tests\Integration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\{MockHttpClient,Response\MockResponse};
use App\Payment\{MockScenarios,MockPaymentService,HttpPaymentService,PurchaseHandler,PaymentContext,CircuitBreaker};
use App\Payment\Message\ProcessPurchase;
use App\RuntimeProfile;
use Doctrine\DBAL\Connection;
use Psr\Log\NullLogger;
final class PaymentRecoveryTest extends KernelTestCase
{
    public function testRetryLimitsIdempotentReceiptAndAbandonedAttempt(): void
    {
        self::bootKernel(); $db=self::getContainer()->get(Connection::class); $db->beginTransaction();
        try {
            $s=new MockScenarios(); $mock=new MockPaymentService($db,$s); $profile=new RuntimeProfile('resilient','USD','worker');
            $client=new MockHttpClient(function($method,$url,$options) use($mock) { $j=json_decode($options['body'],true); $d=new \App\Payment\Dto\PaymentDetailsDto(); foreach($j['details'] as $k=>$v) $d->$k=$v; $c=$j['context']; return new MockResponse(json_encode($mock->pay($d,new PaymentContext($c['purchaseId'],$c['attemptNumber'],$c['amount'],$c['currency'])))); });
            $handler=new PurchaseHandler($db,new HttpPaymentService($client,'http://mock','token'),$s,new CircuitBreaker($db,$profile),$profile,new NullLogger());
            foreach(['success'=>1,'retry_once'=>2,'retry_twice'=>3,'failure'=>3,'decline'=>1] as $fixture=>$attempts) {
                $db->executeStatement("INSERT INTO users (login,username,password_hash,is_admin,created_at) VALUES (?,?,'test',0,UTC_TIMESTAMP())",['test-'.bin2hex(random_bytes(8)),$fixture]); $uid=(int)$db->lastInsertId();
                $db->executeStatement("INSERT INTO purchases (user_id,course_id,amount,currency,status,metadata,idempotency_key,request_fingerprint,created_at,updated_at) VALUES (?,2,5000,'USD','pending',?,'test-key',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$uid,json_encode([['fixture'=>$fixture,'beneficiaryName'=>'Test']]),str_repeat('a',64)]); $id=(int)$db->lastInsertId();
                if ($fixture==='success') { // A receipt exists, but the worker died before settlement.
                    $first=$mock->pay($s->dto($fixture,'Test'),new PaymentContext($id,1,5000,'USD'));
                    $db->executeStatement("INSERT INTO payment_attempts (purchase_id,attempt_number,status,started_at,duration_ms,created_at) VALUES (?,1,'started',UTC_TIMESTAMP(),0,UTC_TIMESTAMP())",[$id]);
                    self::assertSame($first->reference,$mock->pay($s->dto($fixture,'Test'),new PaymentContext($id,1,5000,'USD'))->reference);
                }
                for($n=1;$n<=$attempts;$n++) { $db->executeStatement('UPDATE payment_attempts SET next_attempt_at=NULL WHERE purchase_id=?',[$id]); $db->executeStatement('UPDATE payment_circuit SET opened_until=NULL,consecutive_failures=0'); $handler(new ProcessPurchase($id)); }
                $expected=in_array($fixture,['success','retry_once','retry_twice'])?'succeeded':($fixture==='decline'?'declined':'failed');
                self::assertSame($expected,$db->fetchOne('SELECT status FROM purchases WHERE id=?',[$id]));
                self::assertSame($attempts,(int)$db->fetchOne('SELECT COUNT(*) FROM payment_attempts WHERE purchase_id=?',[$id]));
                $handler(new ProcessPurchase($id)); self::assertSame($attempts,(int)$db->fetchOne('SELECT COUNT(*) FROM payment_attempts WHERE purchase_id=?',[$id]));
                self::assertSame($expected==='succeeded'?1:0,(int)$db->fetchOne('SELECT COUNT(*) FROM enrollments WHERE purchase_id=?',[$id]));
                self::assertSame($expected==='succeeded'?1:0,(int)$db->fetchOne('SELECT COUNT(*) FROM mock_receipts WHERE purchase_id=?',[$id]));
            }
        } finally { $db->rollBack(); }
    }
}
