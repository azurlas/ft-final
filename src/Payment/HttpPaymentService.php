<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Dto\PaymentDetailsDto;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HttpPaymentService implements PaymentServiceInterface
{
    public function __construct(private HttpClientInterface $http, #[Autowire('%env(MOCK_PAYMENT_URL)%')] private string $url, #[Autowire('%env(MOCK_PAYMENT_TOKEN)%')] private string $token)
    {
    }
    public function pay(PaymentDetailsDto $details, PaymentContext $context): PaymentResult
    {
        try {
            $r = $this->http->request('POST', $this->url, ['headers' => ['Authorization' => 'Bearer '.$this->token],'json' => ['details' => get_object_vars($details),'context' => get_object_vars($context)],'timeout' => 2,'max_duration' => 2])->toArray();
            if (!in_array($r['status'] ?? null, ['succeeded','transient','declined'], true) || ($r['status'] === 'succeeded' && (!is_string($r['reference'] ?? null) || !$r['reference'] || strlen($r['reference']) > 100))) {
                return new PaymentResult('transient', null, 'invalid_provider_response');
            }
            if (isset($r['error']) && (!is_string($r['error']) || strlen($r['error']) > 100)) {
                return new PaymentResult('transient', null, 'invalid_provider_response');
            }
            return new PaymentResult($r['status'], $r['reference'] ?? null, $r['error'] ?? null);
        } catch (\Throwable) {
            return new PaymentResult('transient', null, 'provider_unreachable');
        }
    }
}
