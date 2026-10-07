<?php

declare(strict_types=1);

namespace App\Command;

use App\Storage\S3Storage;
use Doctrine\DBAL\{DriverManager,Tools\DsnParser};
use Doctrine\ORM\{EntityManager,EntityManagerInterface};
use App\Repository\DatabaseSnapshotRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputArgument};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:restore-verify', description:'Restore backup into a new isolated database, never overwrite the application database')]
final class RestoreVerifyCommand extends Command
{
    public function __construct(private readonly S3Storage $storage, private readonly EntityManagerInterface $em, private readonly DatabaseSnapshotRepository $snapshots, #[Autowire('%env(DATABASE_URL)%')] private readonly string $dsn, #[Autowire('%env(MYSQL_ROOT_PASSWORD)%')] private readonly string $rootPassword)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');
        $manifest = json_decode((string)$this->storage->restore($key.'.json'), true, 512, JSON_THROW_ON_ERROR);
        $bytes = (string)$this->storage->restore($key);
        if (!hash_equals($manifest['sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException('Backup checksum mismatch');
        }
        $p = parse_url($this->dsn);
        $name = 'university_restore_'.gmdate('YmdHis').'_'.bin2hex(random_bytes(3));
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($this->dsn);
        $params['user'] = 'root';
        $params['password'] = $this->rootPassword;
        unset($params['dbname']);
        $root = DriverManager::getConnection($params);
        $root->createSchemaManager()->createDatabase($name);
        $root->close();
        $tmp = tempnam(sys_get_temp_dir(), 'restore-');
        file_put_contents($tmp, gzdecode($bytes));
        $env = getenv();
        $env['MYSQL_PWD'] = $this->rootPassword;
        $proc = proc_open(['mysql','-h',$p['host'],'-P',(string)($p['port'] ?? 3306),'-u','root',$name], [0 => ['file',$tmp,'r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes, null, $env);
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        unlink($tmp);
        if (proc_close($proc) !== 0) {
            throw new \RuntimeException('Restore failed');
        }
        $params['dbname'] = $name;
        $restoredManager = new EntityManager(DriverManager::getConnection($params), $this->em->getConfiguration());
        try {
            $repository = new DatabaseSnapshotRepository($restoredManager);
            $restored = $repository->snapshot();
            $source = $this->snapshots->snapshot();
            $violations = $repository->violations();
        } finally {
            $restoredManager->getConnection()->close();
        }
        $output->writeln(json_encode(['database' => $name, 'checksumVerified' => true, 'rowHashes' => $restored['hashes'], 'matchesCurrentSource' => $restored['hashes'] === $source['hashes'], 'counts' => $restored['counts'], 'violations' => $violations, 'sourceCounts' => $source['counts']], JSON_THROW_ON_ERROR));
        return $violations ? 1 : 0;
    }
}
