<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\{User, Course, Assignment, PaymentCircuitState};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:seed-demo', description: 'Idempotently seed local demonstration users and courses')]
final class SeedDemoCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = [];
        foreach (['admin', 'student', 'other'] as $login) {
            $user = $this->em->getRepository(User::class)->findOneBy(['login' => $login]);
            if (!$user) {
                $user = new User();
                $user->login = $login;
                $user->username = ucfirst($login).' Demo';
                $user->isAdmin = $login === 'admin';
                $user->passwordHash = $this->hasher->hashPassword($user, $user->isAdmin ? 'AdminPass123!' : 'StudentPass123!');
                $this->em->persist($user);
                $this->em->flush();
            }
            $ids[$login] = $user->id;
        }
        foreach (['Free Computing' => null, 'Paid Computing' => 5000, 'Payment Success' => 1000, 'Payment Retry Once' => 1000, 'Payment Retry Twice' => 1000, 'Payment Failure' => 1000, 'Payment Decline' => 1000, 'Payment Timeout' => 1000] as $name => $price) {
            $course = $this->em->getRepository(Course::class)->findOneBy(['name' => $name]);
            if (!$course) {
                $course = new Course();
                $course->name = $name;
                $course->price = $price;
                $this->em->persist($course);
                $this->em->flush();
                $assignment = new Assignment();
                $assignment->name = 'Midterm submission';
                $assignment->course = $course;
                $assignment->description = 'Upload exactly one file, any format, maximum 10 MB.';
                $this->em->persist($assignment);
                $this->em->flush();
            }
            $ids[$name] = $course->id;
        }
        if (!$this->em->getRepository(PaymentCircuitState::class)->findOneBy(['provider' => 'mock'])) {
            $this->em->persist(new PaymentCircuitState());
            $this->em->flush();
        }
        $output->writeln(json_encode($ids, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }
}
