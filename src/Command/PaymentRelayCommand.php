<?php

declare(strict_types=1);

namespace App\Command;

use App\Payment\CheckoutService;
use App\Payment\FailureHooks;
use App\Payment\Message\ProcessPurchase;
use App\RuntimeProfile;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;

#[AsCommand(name:'app:payment-relay', description:'Publish outbox and reconcile abandoned purchases')]
final class PaymentRelayCommand extends Command
{
    public function __construct(private Connection $db, private MessageBusInterface $bus, private RuntimeProfile $profile, private LoggerInterface $logger, private FailureHooks $hooks)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stop = false;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () use (&$stop) {
            $stop = true;
        });
        pcntl_signal(SIGINT, function () use (&$stop) {
            $stop = true;
        });
        do {
            if ($this->profile->resilient()) {
                try {
                    $this->reconcile();
                    $this->publish();
                } catch (\Throwable $e) {
                    $this->logger->warning('payment.relay_dependency_unavailable', ['exception_class' => $e::class]);
                    $this->db->close();
                }
            } if ($input->getOption('once')) {
                break;
            } sleep(1);
        } while (!$stop);
        return Command::SUCCESS;
    }
    public function publish(): void
    {
        for ($i = 0;$i < 20;$i++) {
            $r = $this->db->transactional(function () {
                $r = $this->db->fetchAssociative('SELECT * FROM outbox WHERE published_at IS NULL AND available_at<=UTC_TIMESTAMP() AND (lease_until IS NULL OR lease_until<UTC_TIMESTAMP()) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED');
                if ($r) {
                    $this->db->executeStatement('UPDATE outbox SET lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 SECOND),delivery_count=delivery_count+1 WHERE id=?', [$r['id']]);
                } return $r;
            });
            if (!$r) {
                return;
            }
            $this->bus->dispatch(new ProcessPurchase((int)$r['aggregate_id'], json_decode($r['payload'], true)['requestId'] ?? ''));
            $this->hooks->checkpoint('after_outbox_publish', (int)$r['aggregate_id']);
            $this->db->executeStatement('UPDATE outbox SET published_at=UTC_TIMESTAMP(),lease_until=NULL WHERE id=?', [$r['id']]);
        }
    }
    public function reconcile(): void
    {
        $ids = $this->db->fetchFirstColumn("SELECT p.id FROM purchases p WHERE p.status IN ('pending','processing','retrying') AND (p.processing_lease_until IS NULL OR p.processing_lease_until<UTC_TIMESTAMP()) AND NOT EXISTS (SELECT 1 FROM outbox o WHERE o.aggregate_id=p.id AND (o.published_at IS NULL OR o.published_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 SECOND))) LIMIT 100");
        foreach ($ids as $id) {
            $this->db->transactional(function () use ($id) {
                $p = $this->db->fetchAssociative('SELECT * FROM purchases WHERE id=? FOR UPDATE', [$id]);
                if (in_array($p['status'], ['succeeded','failed','declined'])) {
                    return;
                } if (!$this->db->fetchOne('SELECT id FROM outbox WHERE aggregate_id=? AND published_at IS NULL', [$id])) {
                    CheckoutService::outbox($this->db, (int)$id);
                    $this->logger->notice('payment.reconciled', ['purchase_id' => $id]);
                }
            });
        }
    }
}
