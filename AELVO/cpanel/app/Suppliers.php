<?php
declare(strict_types=1);
namespace Aelvo;
use InvalidArgumentException;

/** Supplier master data is separate from historical text snapshots on a purchase. */
final class Suppliers extends Service {
    public function save(array $form): int {
        $id=isset($form['id'])&&$form['id']!==''?Input::integer($form['id']):null;
        $this->allow('purchases',$id?'edit':'create');
        $name=Input::text($form,'name',180);
        $fields=[ $name,Input::text($form,'company',180,false),
            Input::text($form,'phone',40,false),Input::text($form,'email',254,false),
            Input::text($form,'address',2000,false),Input::text($form,'notes',2000,false),
            ($form['active']??'1')==='1'?1:0 ];
        if($fields[3]!=='' && !filter_var($fields[3],FILTER_VALIDATE_EMAIL))
            throw new InvalidArgumentException('Enter a valid supplier email address.');
        return $this->transaction(function() use($id,$form,$fields): int {
            if($id!==null){
                $version=Input::integer($form['version']??null);
                $q=$this->db->prepare('UPDATE aelvo_suppliers SET name=?,company=?,phone=?,email=?,address=?,notes=?,active=?,version=version+1 WHERE id=? AND version=?');
                $q->execute([...$fields,$id,$version]);
                if($q->rowCount()!==1)throw new \RuntimeException('Supplier changed. Reload before saving.',409);
            }else{
                $q=$this->db->prepare('INSERT INTO aelvo_suppliers(name,company,phone,email,address,notes,active) VALUES (?,?,?,?,?,?,?)');
                $q->execute($fields);$id=(int)$this->db->lastInsertId();
            }
            $this->audit('supplier.saved:'.$id);
            return $id;
        });
    }
}
