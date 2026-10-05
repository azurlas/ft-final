<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Course\AcademicService;
use App\Payment\CheckoutService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpKernel\Exception\{BadRequestHttpException,ConflictHttpException};

/** @implements ProcessorInterface<mixed,object|null> */
final class WriteProcessor implements ProcessorInterface
{
    public function __construct(private RequestStack $requests, private AcademicService $academic, private CheckoutService $checkout, private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher, private Mapper $mapper)
    {
    }
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?object
    {
        $kind = $operation->getExtraProperties()['kind'];
        $action = $operation->getExtraProperties()['action'] ?? '';
        $request = $this->requests->getCurrentRequest();
        if ($action === 'enroll') {
            return $this->checkout->enroll((int)$uriVariables['id']);
        }
        if ($action === 'purchase') {
            return $this->checkout->purchase((int)$uriVariables['id'], $data, $request->headers->get('Idempotency-Key', ''));
        }
        if ($action === 'register') {
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/', $data->login) || !trim($data->username) || mb_strlen($data->username) > 100 || strlen($data->password) < 12 || strlen($data->password) > 128) {
                throw new BadRequestHttpException('Valid login, username and password of 12-128 bytes required');
            }
            if ($this->em->getRepository(User::class)->findOneBy(['login' => $data->login])) {
                throw new ConflictHttpException('Login already registered');
            }
            $u = new User();
            $u->login = $data->login;
            $u->username = $data->username;
            $u->passwordHash = $this->hasher->hashPassword($u, $data->password);
            $this->em->persist($u);
            $this->em->flush();
            return $this->mapper->map('users', ['id' => $u->id,'login' => $u->login,'username' => $u->username,'is_admin' => false]);
        }
        return $this->academic->mutate($kind, $request->getMethod(), isset($uriVariables['id']) ? (int)$uriVariables['id'] : null, $request->getMethod() === 'DELETE' ? [] : $request->toArray());
    }
}
