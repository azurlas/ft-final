<?php

declare(strict_types=1);

namespace App\Command;

use App\Storage\S3Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:storage-init')]
final class StorageInitCommand extends Command
{
    public function __construct(private readonly S3Storage $storage)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->storage->initialize();
        $output->writeln('Private submission and backup buckets ready');
        return 0;
    }
}
