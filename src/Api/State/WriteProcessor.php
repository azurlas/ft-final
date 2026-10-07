<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Course\{AcademicService, CatalogueCache};
use App\Payment\CheckoutService;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Persistence\Transaction;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException};

/** @implements ProcessorInterface<mixed,object|null> */
final readonly class WriteProcessor implements ProcessorInterface
{
    public function __construct(private RequestStack $requests, private AcademicService $academic, private CheckoutService $checkout, private Transaction $transaction, private UserRepository $users, private UserPasswordHasherInterface $hasher, private Mapper $mapper, private CatalogueCache $cache)
    {
    }
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $kind = $operation->getExtraProperties()['kind'];
        $action = $operation->getExtraProperties()['action'] ?? '';
        $request = $this->requests->getCurrentRequest();
        $isNewPurchase = false;
        $entity = $this->transaction->run(function () use ($data, $uriVariables, $request, $action, $kind, &$isNewPurchase) {
            if ($action === 'enroll') {
                return $this->checkout->enroll((int)$uriVariables['id']);
            }
            if ($action === 'purchase') {
                $purchase = $this->checkout->purchase((int)$uriVariables['id'], $data, $request->headers->get('Idempotency-Key', ''));
                $isNewPurchase = $purchase->id === null;
                return $purchase;
            }
            if ($action === 'register') {
                if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $data->login) || !trim($data->username) || mb_strlen($data->username) > 100 || strlen($data->password) < 12 || strlen($data->password) > 128) {
                    throw new BadRequestHttpException('Valid login, username and password of 12-128 bytes required');
                }
                if ($this->users->findOneBy(['login' => $data->login])) {
                    throw new ConflictHttpException('Login already registered');
                }
                $user = new User();
                $user->login = $data->login;
                $user->username = $data->username;
                $user->passwordHash = $this->hasher->hashPassword($user, $data->password);
                $this->users->save($user);
                return $user;
            }
            return $this->academic->mutate($kind, $request->getMethod(), isset($uriVariables['id']) ? (int)$uriVariables['id'] : null, $request->getMethod() === 'DELETE' ? [] : $request->toArray());
        });
        if ($isNewPurchase) {
            $this->checkout->afterCommit($entity);
        }
        if (in_array($kind, ['courses', 'assignments', 'grades'], true)) {
            $this->cache->invalidate();
        }
        return $entity ? $this->mapper->map($action === 'enroll' ? 'enrollments' : ($action === 'purchase' ? 'purchases' : $kind), $entity) : null;
    }
}
