<?php

declare(strict_types=1);

namespace App\Command;

use App\Payment\{OutboxClaimHandler, OutboxPublishedHandler, ReconcilePurchaseHandler};
use App\Repository\CoursePurchaseRepository;
use App\Payment\FailureHooks;
use App\Payment\Message\ProcessPurchase;
use App\RuntimeProfile;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Psr\Log\LoggerInterface;

#[AsCommand(name:'app:payment-relay', description:'Publish outbox and reconcile abandoned purchases')]
final class PaymentRelayCommand extends Command
{
    public function __construct(private readonly OutboxClaimHandler $claim, private readonly OutboxPublishedHandler $published, private readonly ReconcilePurchaseHandler $reconcileHandler, private readonly CoursePurchaseRepository $purchases, private readonly ManagerRegistry $doctrine, private readonly MessageBusInterface $bus, private readonly RuntimeProfile $profile, private readonly LoggerInterface $logger, private readonly FailureHooks $hooks)
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
                    $this->doctrine->resetManager();
                }
            }
            $this->doctrine->getManager()->clear();
            if ($input->getOption('once')) {
                break;
            } sleep(1);
        } while (!$stop);
        return Command::SUCCESS;
    }
    public function publish(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $message = $this->claim->handle();
            if (!$message) {
                return;
            }
            $purchaseId = $message->purchase->id;
            $this->bus->dispatch(new ProcessPurchase($purchaseId, $message->payload['requestId'] ?? ''));
            $this->hooks->checkpoint('after_outbox_publish', $purchaseId);
            $this->published->handle($message->id);
        }
    }
    public function reconcile(): void
    {
        foreach ($this->purchases->abandoned() as $purchase) {
            $this->reconcileHandler->handle($purchase->id);
        }
    }
}
