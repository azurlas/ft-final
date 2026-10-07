<?php

declare(strict_types=1);

namespace App\Command;

use App\Storage\S3Storage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:backup', description:'Consistent MySQL dump, gzip, checksum manifest, private S3 upload')]
final class BackupCommand extends Command
{
    public function __construct(private readonly S3Storage $storage, #[Autowire('%env(DATABASE_URL)%')] private readonly string $dsn)
    {
        parent::__construct();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $p = parse_url($this->dsn);
        $cmd = ['mysqldump','--single-transaction','--skip-lock-tables','--hex-blob','-h',$p['host'],'-P',(string)($p['port'] ?? 3306),'-u',rawurldecode($p['user']),ltrim($p['path'], '/')];
        $env = getenv();
        $env['MYSQL_PWD'] = rawurldecode($p['pass']);
        $proc = proc_open($cmd, [1 => ['pipe','w'],2 => ['pipe','w']], $pipes, null, $env);
        $sql = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new \RuntimeException('Database dump failed');
        }
        $compressed = gzencode($sql, 6);
        $key = 'mysql/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql.gz';
        $manifest = ['key' => $key,'createdAt' => gmdate(DATE_ATOM),'sha256' => hash('sha256', $compressed),'bytes' => strlen($compressed)];
        $this->storage->backup($key, $compressed);
        $this->storage->backup($key.'.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $output->writeln(json_encode($manifest));
        return 0;
    }
}
