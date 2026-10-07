<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:wait-schema')]
final class WaitSchemaCommand extends Command
{
    public function __construct(private readonly UserRepository $users, private readonly ManagerRegistry $doctrine)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        for ($n = 0;$n < 120;$n++) {
            try {
                if ($this->users->findOneBy(['login' => 'admin'])) {
                    return 0;
                }
            } catch (\Throwable) {
                $this->doctrine->resetManager();
            } sleep(2);
        } return 1;
    }
}
