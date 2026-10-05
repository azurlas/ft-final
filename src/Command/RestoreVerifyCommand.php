<?php

declare(strict_types=1);

namespace App\Command;

use App\Storage\S3Storage;
use Doctrine\DBAL\{Connection,DriverManager,Tools\DsnParser};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputArgument};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:restore-verify', description:'Restore backup into a new isolated database, never overwrite the application database')]
final class RestoreVerifyCommand extends Command
{
    public function __construct(private S3Storage $storage, private Connection $db, #[Autowire('%env(DATABASE_URL)%')] private string $dsn, #[Autowire('%env(MYSQL_ROOT_PASSWORD)%')] private string $rootPassword)
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
        $root->executeStatement('CREATE DATABASE `'.$name.'`');
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
        $root->executeStatement('USE `'.$name.'`');
        $tables = ['users','courses','assignments','enrollments','purchases','payment_attempts','submissions','grades','mock_receipts'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int)$root->fetchOne('SELECT COUNT(*) FROM '.$table);
        }
        $hashes = [];
        $sourceHashes = [];
        foreach ($tables as $table) {
            $hashes[$table] = hash('sha256', json_encode($root->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id'), JSON_THROW_ON_ERROR));
            $sourceHashes[$table] = hash('sha256', json_encode($this->db->fetchAllAssociative('SELECT * FROM '.$table.' ORDER BY id'), JSON_THROW_ON_ERROR));
        }
        $violations = (int)$root->fetchOne("SELECT COUNT(*) FROM purchases p LEFT JOIN enrollments e ON e.purchase_id=p.id WHERE p.status='succeeded' AND e.id IS NULL") + (int)$root->fetchOne('SELECT COUNT(*) FROM grades WHERE grade<0 OR grade>100') + (int)$root->fetchOne('SELECT COUNT(*) FROM submissions WHERE size_bytes>10000000');
        $output->writeln(json_encode(['database' => $name,'checksumVerified' => true,'rowHashes' => $hashes,'matchesCurrentSource' => $hashes === $sourceHashes,'counts' => $counts,'violations' => $violations,'sourceCounts' => array_combine($tables, array_map(fn ($t) => (int)$this->db->fetchOne('SELECT COUNT(*) FROM '.$t), $tables))], JSON_THROW_ON_ERROR));
        return $violations ? 1 : 0;
    }
}
