<?php
declare(strict_types=1);
namespace Aelvo;

use InvalidArgumentException;
use RuntimeException;

/** Human-reviewed evidence for an EXISTING ambiguous booking. Does not call create_order. */
final class CourierResolution extends Service {
    public static function proposalFields(array $input): array {
        $outcome=Input::text($input,'outcome',15);
        if(!in_array($outcome,['found','not_found'],true))throw new InvalidArgumentException('Choose a valid merchant-portal finding.');
        $consignment=Input::text($input,'consignment_id',60,false);
        $tracking=Input::text($input,'tracking_code',100,false);
        $notes=Input::text($input,'proposal_notes',1500);
        if($outcome==='found'){
            if(!preg_match('/^[1-9][0-9]{0,29}$/D',$consignment))throw new InvalidArgumentException('Enter the numeric consignment ID verified in the merchant portal.');
            if(($input['match_confirmed']??null)!=='1')throw new InvalidArgumentException('Confirm the exact invoice, COD, recipient and contact matched the Steadfast portal.');
        }elseif($consignment!==''||$tracking!=='')throw new InvalidArgumentException('No consignment can be attached to an unconfirmed booking.');
        if(preg_match('/[\x00-\x1f\x7f]/',$notes.$tracking))throw new InvalidArgumentException('Invalid control characters.');
        if(($input['portal_checked']??null)!=='1')throw new InvalidArgumentException('Confirm that the merchant portal was independently checked.');
        return [$outcome,$consignment!==''?$consignment:null,$tracking!==''?$tracking:null,$notes];
    }
    private function document(string $id): void {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Private courier portal evidence is required.');
        $q=$this->db->prepare('SELECT id FROM aelvo_media WHERE id=? AND owner_id=? AND is_public=0');
        $q->execute([$id,$this->actor['id']]);
        if(!$q->fetchColumn())throw new InvalidArgumentException('Document must be a private upload belonging to the proposing staff member.');
    }
    private static function ambiguous(array $shipment): bool {
        if($shipment['booking_state']==='Unknown')return true;
        // An interrupted Prepared booking may have reached the provider. Never retry it.
        if($shipment['booking_state']==='Prepared')return (int)$shipment['age_minutes']>=30;
        return false;
    }
    public function propose(array $input): int {
        $this->allow('courier','edit');
        $shipmentId=Input::integer($input['shipment_id']??null);
        [$outcome,$consignment,$tracking,$notes]=self::proposalFields($input);
        $document=Input::text($input,'document_id',32);$this->document($document);
        return $this->transaction(function()use($shipmentId,$outcome,$consignment,$tracking,$notes,$document): int {
            $q=$this->db->prepare('SELECT *,TIMESTAMPDIFF(MINUTE,updated_at,UTC_TIMESTAMP()) AS age_minutes FROM aelvo_courier_shipments WHERE id=? FOR UPDATE');
            $q->execute([$shipmentId]);$shipment=$q->fetch();
            if(!$shipment||!self::ambiguous($shipment))throw new InvalidArgumentException('Only an uncertain booking (or an old interrupted attempt) can be reviewed.');
            $q=$this->db->prepare("SELECT id FROM aelvo_courier_resolution_cases WHERE shipment_id=? AND state='Pending' LIMIT 1 FOR UPDATE");
            $q->execute([$shipmentId]);if($q->fetchColumn()!==false)throw new RuntimeException('A courier reconciliation is already awaiting approval.',409);
            $q=$this->db->prepare("INSERT INTO aelvo_courier_resolution_cases(shipment_id,outcome,consignment_id,tracking_code,evidence_id,proposal_notes,proposal_actor_id) VALUES (?,?,?,?,?,?,?)");
            $q->execute([$shipmentId,$outcome,$consignment,$tracking,$document,$notes,$this->actor['id']]);
            $caseId=(int)$this->db->lastInsertId();
            $q=$this->db->prepare("INSERT INTO aelvo_courier_events(shipment_id,event_type,remote_status,detail,actor_id) VALUES (?,'ReviewRequested','',?,?)");
            $q->execute([$shipmentId,'Evidence submitted for case #'.$caseId.'; no courier or accounting event applied.',$this->actor['id']]);
            $this->audit('courier.reconciliation-proposed:'.$caseId);
            return $caseId;
        });
    }
    public function review(array $input): void {
        $this->allow('courier','approve');
        $caseId=Input::integer($input['case_id']??null);
        $decision=Input::text($input,'decision',12);
        if(!in_array($decision,['approve','reject'],true))throw new InvalidArgumentException('Choose approve or reject.');
        $notes=Input::text($input,'review_notes',1500);
        if(($input['evidence_reviewed']??'')!=='1')throw new InvalidArgumentException('Confirm the evidence and merchant portal record were independently reviewed.');
        $this->transaction(function()use($caseId,$decision,$notes): void {
            // Read only to identify the parent; every writer locks the shipment first.
            $q=$this->db->prepare('SELECT shipment_id FROM aelvo_courier_resolution_cases WHERE id=?');
            $q->execute([$caseId]);$shipmentId=$q->fetchColumn();if($shipmentId===false)throw new InvalidArgumentException('Courier review not found.');
            $q=$this->db->prepare('SELECT *,TIMESTAMPDIFF(MINUTE,updated_at,UTC_TIMESTAMP()) AS age_minutes FROM aelvo_courier_shipments WHERE id=? FOR UPDATE');
            $q->execute([$shipmentId]);$shipment=$q->fetch();
            $q=$this->db->prepare('SELECT * FROM aelvo_courier_resolution_cases WHERE id=? FOR UPDATE');
            $q->execute([$caseId]);$case=$q->fetch();
            if(!$shipment||!$case||$case['state']!=='Pending'||!self::ambiguous($shipment))throw new RuntimeException('Reconciliation changed. Refresh the queue.',409);
            if((int)$case['proposal_actor_id']===(int)$this->actor['id'])throw new RuntimeException('A second authorized user must review this case.',403);
            if($decision==='approve' && $case['outcome']==='found'){
                $q=$this->db->prepare('SELECT status FROM aelvo_orders WHERE id=? FOR UPDATE');
                $q->execute([$shipment['order_id']]);if($q->fetchColumn()!=='Confirmed')throw new RuntimeException('Order state changed; separate manual reconciliation is required.',409);
                GatewayPayments::assertNoUnresolved($this->db,(int)$shipment['order_id']);
                $payment=Payments::summary($this->db,(int)$shipment['order_id']);
                if((int)$payment['due_minor']!==(int)$shipment['cod_minor']||$payment['advance_due_minor']>0||$payment['refunded_minor']>0||Returns::committed($this->db,(int)$shipment['order_id'])>0)
                    throw new RuntimeException('COD or refund balance changed; do not attach this consignment.',409);
                $q=$this->db->prepare("UPDATE aelvo_courier_shipments SET booking_state='Booked',consignment_id=?,tracking_code=?,remote_status='',last_error='' WHERE id=? AND booking_state IN ('Unknown','Prepared')");
                $q->execute([$case['consignment_id'],$case['tracking_code'],(int)$shipmentId]);
                if($q->rowCount()!==1)throw new RuntimeException('Booking state changed during reconciliation.',409);
                $event='ReviewMatched';$detail='Two-person portal reconciliation: existing consignment verified; sync status separately.';
            }elseif($decision==='approve'){
                // A negative portal search alone does NOT authorize a new booking, cancellation or stock release.
                $event='ReviewNoMatch';$detail='Portal search found no matching parcel; booking remains uncertain and locked. No retry authorized.';
            }else{
                $event='ReviewRejected';$detail='Reconciliation rejected; existing booking remains locked until new evidence.';
            }
            $state=$decision==='approve'?'Approved':'Rejected';
            $q=$this->db->prepare('UPDATE aelvo_courier_resolution_cases SET state=?,review_actor_id=?,review_notes=?,reviewed_at=UTC_TIMESTAMP() WHERE id=? AND state=?');
            $q->execute([$state,$this->actor['id'],$notes,$caseId,'Pending']);
            if($q->rowCount()!==1)throw new RuntimeException('Case was already reviewed.',409);
            $q=$this->db->prepare('INSERT INTO aelvo_courier_events(shipment_id,event_type,remote_status,detail,actor_id) VALUES (?,?,?,?,?)');
            $q->execute([$shipmentId,$event,'',$detail.' Case #'.$caseId,$this->actor['id']]);
            $this->audit('courier.reconciliation-'.$state.':'.$caseId);
        });
    }
}
