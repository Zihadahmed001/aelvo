<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;
use RuntimeException;
use PDO;

/** One remote booking attempt per order. Uncertain requests are frozen for human reconciliation. */
final class Courier extends Service {
    public function book(array $input,string $encryptionKey,?callable $transport=null): int {
        $this->allow('courier','create');
        if(($input['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm the courier booking and current COD amount.');
        $orderId=Input::integer($input['order_id']??null);
        $credentials=Steadfast::credentials($this->db,$encryptionKey);
        if($transport===null&&!function_exists('curl_init'))throw new RuntimeException('PHP cURL must be enabled before courier booking.');
        $shipment=$this->transaction(function()use($orderId){
            $q=$this->db->prepare('SELECT * FROM aelvo_orders WHERE id=? FOR UPDATE');$q->execute([$orderId]);$o=$q->fetch();
            if(!$o||$o['status']!=='Confirmed')throw new InvalidArgumentException('Only confirmed orders may be booked.');
            $q=$this->db->prepare('SELECT id FROM aelvo_courier_shipments WHERE order_id=? FOR UPDATE');$q->execute([$orderId]);
            if($q->fetchColumn()!==false)throw new InvalidArgumentException('This order already has a courier attempt. Never retry until reconciled.');
            GatewayPayments::assertNoUnresolved($this->db,$orderId);
            OrderCancellationRequests::assertNoPending($this->db,$orderId);
            $s=Payments::summary($this->db,$orderId);
            if($s['advance_due_minor']>0||$s['refunded_minor']>0||Returns::committed($this->db,$orderId)>0)
                throw new InvalidArgumentException('Advance payment or refund review must be reconciled before booking.');
            $address=trim((string)$o['address']);
            if(strlen((string)$o['customer_name'])>100)throw new InvalidArgumentException('Recipient name is too long for courier booking (100 bytes).');
            if($address===''||strlen($address)>250)throw new InvalidArgumentException('Courier address must be at most 250 bytes.');
            $q=$this->db->prepare('INSERT INTO aelvo_courier_shipments(order_id,invoice,cod_minor,recipient_name,recipient_phone,recipient_address,created_by) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$orderId,$o['reference'],$s['due_minor'],$o['customer_name'],$o['phone'],$address,$this->actor['id']]);
            $id=(int)$this->db->lastInsertId();
            $this->event($id,'Prepared','','Courier booking attempt reserved; network outcome unknown until response.');
            $this->audit('courier.booking-prepared:'.$id);
            return ['id'=>$id,'invoice'=>$o['reference'],'cod_minor'=>$s['due_minor'],'recipient_name'=>$o['customer_name'],'recipient_phone'=>$o['phone'],'recipient_address'=>$address];
        });
        // External HTTP is intentionally OUTSIDE the DB transaction. A lost response freezes this attempt.
        try {
            $payload=Steadfast::bookingPayload($shipment);
            $response=$transport?$transport('POST','/create_order',$payload,$credentials):Steadfast::request($credentials,'POST','/create_order',$payload);
            if(!is_array($response))throw new RuntimeException('Courier response could not be verified.');
            $remote=Steadfast::parseBooking($response,$shipment['invoice'],(int)$shipment['cod_minor']);
            $this->transaction(function()use($shipment,$remote){
                $q=$this->db->prepare("UPDATE aelvo_courier_shipments SET booking_state='Booked',consignment_id=?,tracking_code=?,remote_status=?,last_error='' WHERE id=? AND booking_state='Prepared'");
                $q->execute([$remote['consignment_id'],$remote['tracking_code'],$remote['remote_status'],$shipment['id']]);
                if($q->rowCount()!==1)throw new RuntimeException('Courier booking state changed; investigate.');
                $this->event((int)$shipment['id'],'Booked',$remote['remote_status'],'Confirmed consignment '.$remote['consignment_id']);
                $this->audit('courier.booking-confirmed:'.$shipment['id']);
            });
        }catch(\Throwable $error){
            // Even HTTP 500/timeout is ambiguous: the provider may have created a parcel.
            $this->transaction(function()use($shipment){
                $q=$this->db->prepare("UPDATE aelvo_courier_shipments SET booking_state='Unknown',last_error='Provider outcome needs human reconciliation' WHERE id=? AND booking_state='Prepared'");
                $q->execute([$shipment['id']]);
                if($q->rowCount()===1)$this->event((int)$shipment['id'],'Unknown','','Do NOT book again. Check invoice in Steadfast merchant portal.');
                $this->audit('courier.booking-unconfirmed:'.$shipment['id']);
            });
            throw new RuntimeException('Courier booking cannot be confirmed. Check the existing invoice in the Steadfast portal; do not retry the booking.');
        }
        return (int)$shipment['id'];
    }
    public function sync(array $input,string $encryptionKey,?callable $transport=null): string {
        $this->allow('courier','edit');$id=Input::integer($input['shipment_id']??null);
        $credentials=Steadfast::credentials($this->db,$encryptionKey);
        $q=$this->db->prepare('SELECT * FROM aelvo_courier_shipments WHERE id=?');$q->execute([$id]);$shipment=$q->fetch();
        if(!$shipment||$shipment['booking_state']!=='Booked'||!ctype_digit((string)$shipment['consignment_id']))throw new InvalidArgumentException('Only confirmed consignments can sync automatically. Uncertain bookings require manual reconciliation.');
        $path='/status_by_cid/'.$shipment['consignment_id'];
        $response=$transport?$transport('GET',$path,null,$credentials):Steadfast::request($credentials,'GET',$path);
        if(!is_array($response))throw new RuntimeException('Invalid provider response.');
        $status=Steadfast::parseStatus($response);
        $applied=$this->transaction(function()use($id,$status){
            $q=$this->db->prepare('SELECT remote_status FROM aelvo_courier_shipments WHERE id=? AND booking_state=? FOR UPDATE');$q->execute([$id,'Booked']);$old=$q->fetchColumn();
            if($old===false)throw new InvalidArgumentException('Shipment state changed.');
            if($old!==$status){
                // Do not accept a stale provider read that regresses a finalized status.
                if(in_array($old,['delivered','cancelled'],true)){
                    $this->event($id,'Conflict',$status,'Conflicting status after finalized provider state; manual review required.');
                    $this->audit('courier.status-conflict:'.$id);
                    return (string)$old;
                }
                $q=$this->db->prepare('UPDATE aelvo_courier_shipments SET remote_status=? WHERE id=?');$q->execute([$status,$id]);
                $this->event($id,'Status',$status,'Provider status snapshot; no automatic payment, delivery, restock or refund.');
                $this->audit('courier.status-synced:'.$id);
            }
            return $status;
        });return $applied;
    }
    private function event(int $id,string $kind,string $status,string $detail): void {
        $q=$this->db->prepare('INSERT INTO aelvo_courier_events(shipment_id,event_type,remote_status,detail,actor_id) VALUES (?,?,?,?,?)');
        $q->execute([$id,$kind,$status,$detail,$this->actor['id']]);
    }
}
