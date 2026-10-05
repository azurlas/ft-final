<?php
declare(strict_types=1);
namespace App\Api\State;
use ApiPlatform\Metadata\{Operation,GetCollection};
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use App\Course\CatalogueCache;
/** @implements ProviderInterface<object> */
final class ReadProvider implements ProviderInterface
{
    public function __construct(private Connection $db, private Access $access, private Mapper $mapper, private CatalogueCache $cache) {}
    public function provide(Operation $operation, array $uriVariables=[], array $context=[]): object|array
    {
        $kind=$operation->getExtraProperties()['kind'];
        if (($operation->getExtraProperties()['action']??'')==='me') { $u=$this->access->user(); return $this->mapper->map('users',['id'=>$u->id,'login'=>$u->login,'username'=>$u->username,'is_admin'=>$u->isAdmin]); }
        $where=[]; $params=[];
        if (in_array($kind,['courses','assignments'])) $where[]='deleted_at IS NULL';
        if ($kind==='assignments') {
            if (isset($uriVariables['courseId'])) { $this->access->enrolled((int)$uriVariables['courseId']); $where[]='course_id=?'; $params[]=(int)$uriVariables['courseId']; }
            $where[]='course_id IN (SELECT id FROM courses WHERE deleted_at IS NULL)';
        }
        if (in_array($kind,['enrollments','grades','purchases','submissions'])) { $u=$this->access->user(); if (!$u->isAdmin) { $where[]='user_id=?'; $params[]=$u->id; } }
        if (!($operation instanceof GetCollection)) { $where[]='id=?'; $params[]=(int)($uriVariables['id']??0); }
        $sql='SELECT * FROM '.$kind.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id';
        if ($operation instanceof GetCollection) { $page=max(1,(int)($context['filters']['page']??1)); $sql.=' LIMIT 20 OFFSET '.(($page-1)*20); }
        $rows=$kind==='courses'?$this->cache->read($sql,$params):$this->db->fetchAllAssociative($sql,$params);
        if (!($operation instanceof GetCollection)) {
            if (!$rows) throw new NotFoundHttpException();
            if ($kind==='assignments') $this->access->enrolled((int)$rows[0]['course_id']);
            return $this->mapper->map($kind,$rows[0]);
        }
        return array_map(fn(array $r)=>$this->mapper->map($kind,$r),$rows);
    }
}
