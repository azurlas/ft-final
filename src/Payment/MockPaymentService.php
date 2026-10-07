<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Dto\PaymentDetailsDto;
use App\Entity\MockPaymentReceipt;
use App\Repository\{MockPaymentReceiptRepository, CoursePurchaseRepository};

/** Hard-coded provider scenarios, with durable success receipts before replies. */
final readonly class MockPaymentService implements PaymentServiceInterface
{
    public const CARDS = ['success' => '9900000000000010','retry_once' => '9900000000000028','retry_twice' => '9900000000000036','failure' => '9900000000000044','decline' => '9900000000000051','timeout' => '9900000000000069'];
    public static function outcome(string $fixture, int $attempt): string
    {
        return match($fixture) {
            'success' => 'succeeded','retry_once' => $attempt >= 2 ? 'succeeded' : 'transient','retry_twice' => $attempt >= 3 ? 'succeeded' : 'transient','failure' => 'transient','decline' => 'declined','timeout' => 'timeout',default => throw new \LogicException('Unknown fixture')
        };
    }
    public function __construct(private MockPaymentReceiptRepository $receipts, private CoursePurchaseRepository $purchases, private MockScenarios $scenarios)
    {
    }
    public function pay(PaymentDetailsDto $details, PaymentContext $context): PaymentResult
    {
        $fixture = $this->scenarios->resolve($details);
        $purchase = $this->purchases->lock($context->purchaseId) ?? throw new \LogicException('Purchase missing');
        $receipt = $this->receipts->findOneBy(['purchase' => $purchase]);
        $ref = $receipt?->providerReference;
        if ($ref) {
            return new PaymentResult('succeeded', $ref);
        }
        $outcome = self::outcome($fixture, $context->attemptNumber);
        if ($outcome === 'timeout') {
            usleep(3000000);
            return new PaymentResult('transient', null, 'provider_timeout');
        }
        if ($outcome !== 'succeeded') {
            return new PaymentResult($outcome, null, $outcome === 'declined' ? 'card_declined' : 'provider_unavailable');
        }
        $ref = 'mock-'.hash('sha256', 'purchase:'.$context->purchaseId);
        $receipt = new MockPaymentReceipt();
        $receipt->purchase = $purchase;
        $receipt->providerReference = $ref;
        $receipt->amount = $context->amount;
        $receipt->currency = $context->currency;
        $this->receipts->save($receipt);
        return new PaymentResult('succeeded', $ref);
    }
}
