<?php
declare(strict_types=1);
namespace App\Command;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:wait-schema')]
final class WaitSchemaCommand extends Command
{
    public function __construct(private Connection $db) { parent::__construct(); }
    protected function execute(InputInterface $input,OutputInterface $output): int { for($n=0;$n<120;$n++) { try { if ($this->db->fetchOne("SELECT id FROM users WHERE login='admin'")) return 0; } catch (\Throwable) { $this->db->close(); } sleep(2); } return 1; }
}
