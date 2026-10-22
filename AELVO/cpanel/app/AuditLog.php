<?php
declare(strict_types=1);
namespace Aelvo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Read-only review of the existing append-only AELVO event history. */
final class AuditLog {
    public const PAGE_SIZE = 25;
    public const EXPORT_LIMIT = 1000;

    public function __construct(private PDO $db, private array $actor) {
        if (($actor['is_owner'] ?? false) !== true || ($actor['status'] ?? '') !== 'Active') {
            throw new RuntimeException('Only the original Owner can read the audit log.', 403);
        }
    }

    /** Strictly bounded filters; no SQL fragments may originate from a request. */
    public static function filters(array $input): array {
        $today = gmdate('Y-m-d');
        $defaults = ['from' => gmdate('Y-m-d', strtotime('-29 days')), 'to' => $today];
        $from = $input['from'] ?? $defaults['from'];
        $to = $input['to'] ?? $defaults['to'];
        $event = $input['event'] ?? '';
        $actor = $input['actor'] ?? '';
        $page = $input['p'] ?? '1';
        foreach ([$from,$to,$event,$actor,$page] as $value) {
            if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Invalid audit filter.');
        }
        $from = self::date((string)$from);
        $to = self::date((string)$to);
        $f = new DateTimeImmutable($from, new DateTimeZone('UTC'));
        $t = new DateTimeImmutable($to, new DateTimeZone('UTC'));
        if ($t < $f || $f->diff($t)->days > 366) throw new InvalidArgumentException('Select a range no longer than 367 days.');
        $event = trim((string)$event);
        if (strlen($event) > 80 || preg_match('/[\x00-\x1f\x7f]/', $event)) throw new InvalidArgumentException('Invalid event search.');
        $actor = (string)$actor;
        if ($actor !== '' && $actor !== 'system' && (!preg_match('/^[1-9][0-9]{0,14}$/D',$actor) || (float)$actor > PHP_INT_MAX)) {
            throw new InvalidArgumentException('Invalid staff filter.');
        }
        if (!preg_match('/^[1-9][0-9]{0,4}$/D', (string)$page) || (int)$page > 10000) {
            throw new InvalidArgumentException('Invalid audit page.');
        }
        return ['from'=>$from,'to'=>$to,'event'=>$event,'actor'=>$actor,'p'=>(int)$page];
    }

    private static function date(string $value): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) throw new InvalidArgumentException('Invalid UTC date.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException('Invalid UTC date.');
        return $value;
    }

    private static function escapeLike(string $s): string {
        return str_replace(['!','%','_'], ['!!','!%','!_'], $s);
    }

    /** @return array{0:string,1:list<string|int>} */
    private static function condition(array $filters): array {
        $end = (new DateTimeImmutable($filters['to'],new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        $terms = ['a.created_at >= ?','a.created_at < ?'];
        $bindings = [$filters['from'].' 00:00:00', $end.' 00:00:00'];
        if ($filters['actor'] === 'system') $terms[] = 'a.actor_id IS NULL';
        elseif ($filters['actor'] !== '') { $terms[] = 'a.actor_id = ?'; $bindings[] = (int)$filters['actor']; }
        if ($filters['event'] !== '') {
            $terms[] = "a.event LIKE ? ESCAPE '!'";
            $bindings[] = '%'.self::escapeLike($filters['event']).'%';
        }
        return [' WHERE '.implode(' AND ', $terms),$bindings];
    }

    private static function bind(PDO $db, string $sql, array $params): \PDOStatement {
        $q = $db->prepare($sql);
        foreach ($params as $i=>$value) $q->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        return $q;
    }

    public function search(array $filters, bool $export=false): array {
        [$where,$values] = self::condition($filters);
        $base = ' FROM aelvo_audit a LEFT JOIN aelvo_users u ON u.id=a.actor_id'.$where;
        $count = self::bind($this->db,'SELECT COUNT(*)'.$base,$values);
        $count->execute();
        $total = (int)$count->fetchColumn();
        $pages = max(1,(int)ceil($total/self::PAGE_SIZE));
        $page = min($filters['p'],$pages);
        $limit = $export ? self::EXPORT_LIMIT : self::PAGE_SIZE;
        $offset = $export ? 0 : ($page-1)*self::PAGE_SIZE;
        $query = self::bind($this->db,
            'SELECT a.id,a.actor_id,a.event,a.created_at,u.name AS actor_name,u.email AS actor_email'.$base
            .' ORDER BY a.created_at DESC,a.id DESC LIMIT ? OFFSET ?', $values);
        $query->bindValue(count($values)+1,$limit,PDO::PARAM_INT);
        $query->bindValue(count($values)+2,$offset,PDO::PARAM_INT);
        $query->execute();
        return ['rows'=>$query->fetchAll(),'total'=>$total,'pages'=>$pages,'page'=>$page,'limited'=>$export && $total>self::EXPORT_LIMIT];
    }

    /** Do not allow spreadsheet formula interpretation of audit fields. */
    public static function csvCell(mixed $value): string {
        $text = (string)($value??'');
        return preg_match('/^\s*[=+\-@]/u',$text) ? "'".$text : $text;
    }
}
