<?php
declare(strict_types=1);
namespace App\Payment;
use Doctrine\DBAL\Connection;
use App\RuntimeProfile;
final class CircuitBreaker
{
    public function __construct(private Connection $db,private RuntimeProfile $profile) {}
    public function allow(): bool
    {
        if (!$this->profile->resilient()) return true;
        return $this->db->transactional(function() {
            $r=$this->db->fetchAssociative("SELECT * FROM payment_circuit WHERE provider='mock' FOR UPDATE");
            if (!$r || !$r['opened_until']) return true;
            if (strtotime($r['opened_until'].' UTC')>time() || ($r['probe_lease_until'] && strtotime($r['probe_lease_until'].' UTC')>time())) return false;
            $this->db->executeStatement("UPDATE payment_circuit SET probe_lease_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 SECOND) WHERE provider='mock'"); return true;
        });
    }
    public function record(bool $transient): void
    {
        if (!$this->profile->resilient()) return;
        $this->db->transactional(function() use($transient) {
            $r=$this->db->fetchAssociative("SELECT * FROM payment_circuit WHERE provider='mock' FOR UPDATE"); $n=$transient?(int)$r['consecutive_failures']+1:0;
            $this->db->executeStatement("UPDATE payment_circuit SET consecutive_failures=?,opened_until=".($n>=5?"DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 SECOND)":"NULL").",probe_lease_until=NULL WHERE provider='mock'",[$n]);
        });
    }
}
