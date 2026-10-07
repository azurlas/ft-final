<?php

declare(strict_types=1);

namespace App\Command;

use App\Storage\S3Storage;
use App\Repository\SubmissionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:cleanup-orphans', description:'Dry-run aged objects missing database metadata; --apply deletes objects older than 24h')]
final class CleanupOrphansCommand extends Command
{
    public function __construct(private readonly S3Storage $storage, private readonly SubmissionRepository $submissions)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->storage->objects() as $o) {
            if (!$o || $o['LastModified']->getTimestamp() > time() - 86400 || $this->submissions->findOneBy(['objectKey' => $o['Key']])) {
                continue;
            } $output->writeln($o['Key']);
            if ($input->getOption('apply')) {
                $this->storage->delete($o['Key']);
            }
        } return 0;
    }
}
