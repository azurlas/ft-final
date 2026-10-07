<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Message\ProcessPurchase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PurchaseHandler
{
    public function __construct(private ClaimPurchaseHandler $claim, private SettlePurchaseHandler $settle, private HttpPaymentService $provider, private MockScenarios $scenarios, private LoggerInterface $logger, private ?FailureHooks $hooks = null)
    {
    }
    public function __invoke(ProcessPurchase $msg): void
    {
        $attempt = $this->claim->handle($msg->purchaseId);
        if (!$attempt) {
            return;
        }
        $purchase = $attempt->purchase;
        $number = $attempt->attemptNumber;
        $metadata = $purchase->metadata[0];
        $start = microtime(true);
        $result = $this->provider->pay($this->scenarios->dto($metadata['fixture'], $metadata['beneficiaryName']), new PaymentContext($purchase->id, $number, $purchase->amount, $purchase->currency));
        $duration = (int)((microtime(true) - $start) * 1000);
        if ($result->status === 'succeeded') {
            $this->hooks?->checkpoint('after_provider_success', $purchase->id);
        }
        if (!$this->settle->handle($purchase->id, $number, $result, $duration)) {
            return;
        }
        $this->logger->info('payment.attempt_completed', ['request_id' => $msg->requestId, 'purchase_id' => $purchase->id, 'attempt' => $number, 'status' => $result->status, 'duration_ms' => $duration]);
    }
}
