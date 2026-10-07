<?php

declare(strict_types=1);

namespace App\Payment;

use App\Payment\Dto\PaymentDetailsDto;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final readonly class MockScenarios
{
    public const CARDS = MockPaymentService::CARDS;
    public function resolve(PaymentDetailsDto $dto): string
    {
        $fixture = array_search($dto->cardNumber, self::CARDS, true);
        if ($fixture === false || !trim($dto->beneficiaryName) || mb_strlen($dto->beneficiaryName) > 100 || $dto->expiryDate !== '12/2035' || $dto->cvv !== '123') {
            throw new BadRequestHttpException('Use synthetic fixtures with expiry 12/2035 and CVV 123');
        }
        return $fixture;
    }
    public function dto(string $fixture, string $name): PaymentDetailsDto
    {
        $d = new PaymentDetailsDto();
        $d->beneficiaryName = $name;
        $d->cardNumber = self::CARDS[$fixture];
        $d->expiryDate = '12/2035';
        $d->cvv = '123';
        return $d;
    }
    public function outcome(string $fixture, int $attempt): string
    {
        return MockPaymentService::outcome($fixture, $attempt);
    }
}
