<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class RuntimeProfile
{
    public function __construct(
        #[Autowire(env: 'APP_PROFILE')] public string $name,
        #[Autowire(env: 'APP_CURRENCY')] public string $currency,
        #[Autowire(env: 'APP_ROLE')] public string $role,
    ) {
    }
    public function resilient(): bool
    {
        return $this->name !== 'baseline';
    }
    public function maxAttempts(): int
    {
        return $this->resilient() ? 3 : 1;
    }
}
