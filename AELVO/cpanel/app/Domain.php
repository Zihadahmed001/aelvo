<?php
declare(strict_types=1);
namespace Aelvo;
use PDO;
use InvalidArgumentException;
use RuntimeException;
final class Input {
    public static function text(array $data,string $key,int $max=180,bool $required=true): string {
        $v=$data[$key]??'';
        if (!is_string($v)) throw new InvalidArgumentException('Invalid '.$key);
        $v=trim($v);
        if (($required && $v==='') || strlen($v)>$max) throw new InvalidArgumentException('Check '.$key.' (maximum '.$max.' bytes).');
        return $v;
    }
    public static function integer(mixed $value,int $min=1,int $max=100000000): int {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^-?\d+$/D',(string)$value)) throw new InvalidArgumentException('Invalid number');
        if ((float)$value<$min || (float)$value>$max) throw new InvalidArgumentException('Number outside allowed range');
        return (int)$value;
    }
    public static function money(mixed $value): int {
        if (!is_string($value) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D',$value)) throw new InvalidArgumentException('Enter a valid BDT amount.');
        [$whole,$fraction]=array_pad(explode('.',$value,2),2,'');
        return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }
    public static function phone(string $value): string {
        $value=strtr($value,['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
        $value=preg_replace('/[\s()\-]/u','',$value);
        if (str_starts_with($value,'+88')) $value=substr($value,3);
        elseif (str_starts_with($value,'0088')) $value=substr($value,4);
        elseif (str_starts_with($value,'88')) $value=substr($value,2);
        if (!preg_match('/^01[3-9]\d{8}$/D',$value)) throw new InvalidArgumentException('Enter a valid Bangladesh mobile number.');
        return $value;
    }
}
abstract class Service {
    public function __construct(protected PDO $db,protected array $actor) {}
    protected function allow(string $module,string $action): void {
        if (!Security::can($this->db,$this->actor,$module,$action)) throw new RuntimeException('Permission denied',403);
    }
    protected function owner(): void {
        if (($this->actor['is_owner']??false)!==true || $this->actor['status']!=='Active') throw new RuntimeException('Owner access required',403);
    }
    protected function transaction(callable $callback): mixed {
        $this->db->beginTransaction();
        try { $result=$callback(); $this->db->commit(); return $result; }
        catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    protected function audit(string $event): void {
        $q=$this->db->prepare('INSERT INTO aelvo_audit(actor_id,event) VALUES (?,?)'); $q->execute([$this->actor['id'],$event]);
    }
}
