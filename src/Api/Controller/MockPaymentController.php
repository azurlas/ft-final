<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Payment\{MockPaymentService,PaymentContext};
use App\Payment\Dto\PaymentDetailsDto;
use App\RuntimeProfile;
use App\Repository\CoursePurchaseRepository;
use App\Persistence\Transaction;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{Request,JsonResponse};
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException,BadRequestHttpException};
use Symfony\Component\Routing\Attribute\Route;

final readonly class MockPaymentController
{
    public function __construct(private MockPaymentService $mock, private RuntimeProfile $profile, private CoursePurchaseRepository $purchases, private Transaction $transaction, #[Autowire('%env(MOCK_PAYMENT_TOKEN)%')] private string $token)
    {
    }
    #[Route('/internal/payment', methods:['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->profile->role !== 'mock' || !hash_equals('Bearer '.$this->token, $request->headers->get('Authorization', ''))) {
            throw new AccessDeniedHttpException();
        }
        $body = $request->toArray();
        $ctx = $body['context'] ?? [];
        $p = $this->purchases->find((int)($ctx['purchaseId'] ?? 0));
        if (!$p || (int)($ctx['amount'] ?? 0) !== (int)$p->amount || ($ctx['currency'] ?? '') !== $p->currency || !is_int($ctx['attemptNumber'] ?? null) || $ctx['attemptNumber'] < 1 || $ctx['attemptNumber'] > 3) {
            throw new BadRequestHttpException('Invalid payment context');
        }
        $d = new PaymentDetailsDto();
        foreach (get_object_vars($d) as $f => $v) {
            if (!is_string($body['details'][$f] ?? null)) {
                throw new BadRequestHttpException();
            } $d->$f = $body['details'][$f];
        }
        return new JsonResponse($this->transaction->run(fn () => $this->mock->pay($d, new PaymentContext((int)$ctx['purchaseId'], $ctx['attemptNumber'], $p->amount, $p->currency))));
    }
}
