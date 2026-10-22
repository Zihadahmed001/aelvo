<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use RuntimeException;
final class Finance extends Service {
    public function account(array $v): int {
        $this->allow('finance','create');$name=Input::text($v,'name',120);$kind=Input::text($v,'kind',20);
        if(!in_array($kind,['Cash','Bank','Mobile wallet'],true)) throw new InvalidArgumentException('Invalid account type');
        return $this->transaction(function() use($name,$kind) {$q=$this->db->prepare('INSERT INTO aelvo_accounts(name,kind) VALUES (?,?)');$q->execute([$name,$kind]);$id=(int)$this->db->lastInsertId();$this->audit('finance.account-created:'.$id);return $id;});
    }
    public function post(array $v): string {
        $this->allow('finance','edit');$kind=Input::text($v,'kind',30);
        if(!in_array($kind,['deposit','expense','transfer','adjustment'],true)) throw new InvalidArgumentException('Unknown financial operation');
        $amount=Input::money($v['amount']??null);if($amount<1) throw new InvalidArgumentException('Amount must be positive');
        $account=Input::integer($v['account_id']??null);$target=$kind==='transfer'?Input::integer($v['target_id']??null):null;
        if($target===$account) throw new InvalidArgumentException('Choose two different accounts');
        $reason=Input::text($v,'explanation',2000,$kind==='adjustment');
        $direction=$kind==='adjustment'?Input::text($v,'direction',10):'add';
        if(!in_array($direction,['add','remove'],true)) throw new InvalidArgumentException('Invalid adjustment direction');
        $document=Input::text($v,'document_id',32,false);
        $request=Input::text($v,'request_key',64);if(!preg_match('/^[a-f0-9]{64}$/D',$request)) throw new InvalidArgumentException('Reload the finance form');
        $key=hash('sha256','finance:'.$this->actor['id'].':'.$request);
        $hash=hash('sha256',json_encode([$kind,$amount,$account,$target,$reason,$direction,$document],JSON_THROW_ON_ERROR));
        return $this->transaction(function() use($key,$hash,$kind,$amount,$account,$target,$reason,$direction,$document) {
            $q=$this->db->prepare('SELECT id,request_hash FROM aelvo_finance_events WHERE request_key=?');$q->execute([$key]);$previous=$q->fetch();
            if($previous) {if(!hash_equals($previous['request_hash'],$hash))throw new RuntimeException('Request content changed; reload the form',409);return $previous['id'];}
            if($document!==''){$q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND is_public=0 AND owner_id=?');$q->execute([$document,$this->actor['id']]);if(!$q->fetch())throw new InvalidArgumentException('Document not found');}
            $ids=$target?[$account,$target]:[$account];sort($ids,SORT_NUMERIC);$rows=[];
            foreach($ids as $id){$q=$this->db->prepare('SELECT * FROM aelvo_accounts WHERE id=? AND active=1 FOR UPDATE');$q->execute([$id]);$rows[$id]=$q->fetch();if(!$rows[$id])throw new InvalidArgumentException('Account unavailable');}
            $negative=in_array($kind,['expense','transfer'],true)||($kind==='adjustment'&&$direction==='remove');
            $deltas=[$account=>$negative?-$amount:$amount];if($target)$deltas[$target]=$amount;
            foreach($deltas as $id=>$delta) if((int)$rows[$id]['balance_minor']+$delta<0 || (int)$rows[$id]['balance_minor']+$delta>1000000000000)throw new InvalidArgumentException('Insufficient funds or account limit exceeded');
            $event=bin2hex(random_bytes(16));$q=$this->db->prepare('INSERT INTO aelvo_finance_events(id,request_key,request_hash,kind,explanation,document_id,actor_id) VALUES (?,?,?,?,?,?,?)');$q->execute([$event,$key,$hash,$kind,$reason,$document?:null,$this->actor['id']]);
            foreach($deltas as $id=>$delta){$after=(int)$rows[$id]['balance_minor']+$delta;$q=$this->db->prepare('UPDATE aelvo_accounts SET balance_minor=? WHERE id=?');$q->execute([$after,$id]);$q=$this->db->prepare('INSERT INTO aelvo_ledger(event_id,account_id,delta_minor,balance_after_minor) VALUES (?,?,?,?)');$q->execute([$event,$id,$delta,$after]);}
            $this->audit('finance.'.$kind.':'.$event);return $event;
        });
    }
}
