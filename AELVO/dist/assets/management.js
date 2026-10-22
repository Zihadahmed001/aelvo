import {E,button,field,textarea,table,notice} from './ui.js';
import {can,requirePermission} from './access.js';
import {variantFor} from './core.js';

export const recordModule=t=>({expenses:'accounts',staff:'team',pages:'content',redirects:'seo',movements:'inventory',variants:'inventory'}[t]||t);
export function listFor(s,type){return type==='orders'?s.orders:type==='products'?s.products:s.records[type]||[];}
export function recordFor(s,type,id){const r=listFor(s,type).find(r=>String(r.id)===String(id));if(!r)throw Error('This record is unavailable.');return r;}
export function recordActions(s,type,id){const module=recordModule(type),view=type==='orders'?'/admin/orders/'+id:type==='customers'?'/admin/customers/'+id:type==='products'?'/product/'+id:null;return `<div class="record-actions">${['View','Edit','Delete'].map(label=>label==='View'&&view&&can(s,module)?`<a href="#${view}" class="btn small secondary">View</a>`:button(label,'manage-'+label.toLowerCase(),`data-type="${type}" data-id="${E(id)}" ${can(s,module,label==='View'?'view':label.toLowerCase())?'':'disabled'}`,'btn small secondary'+(label==='Delete'?' danger':''))).join('')}</div>`;}
export function recordDetails(r){return table(['Field','Value'],Object.entries(r).filter(([k])=>!['password','secret','images','colors','variants','items','image'].includes(k)).map(([k,v])=>[E(k.replace(/([A-Z])/g,' $1')),v&&typeof v==='object'?E(JSON.stringify(v,(key,value)=>key==='url'?undefined:value).slice(0,600)):E(v)]));}
export function annotationForm(r){return `${notice('Posted amounts and stock history are retained. You can edit descriptive details here; balance/stock corrections use their dedicated actions.')}${field('Reference / label','reference',r.reference||r.id||'')}${textarea('Internal note','note',r.note||r.notes||'')}`;}
export function archiveRecord(s,type,id,reason){
  requirePermission(s,recordModule(type),'delete');const r=recordFor(s,type,id);if(!reason.trim())throw Error('Add a reason for deleting this record.');if(r.archived)throw Error('This record is already archived.');
  if(type==='staff'&&r.role==='Super Admin')throw Error('Your owner account cannot be deleted.');
  if(type==='orders'&&!['Delivered','Cancelled','Draft','Failed Delivery'].includes(r.status)){
    if(r.paid>0)throw Error('Resolve the payment and cancel this active order before deleting it.');
    if(r.reserved){for(const item of r.items){const v=variantFor(s.products.find(p=>p.id===item.id),item.size,item.color);v.reserved=Math.max(0,v.reserved-item.qty);}r.reserved=false;}r.status='Cancelled';
  }
  if(type==='coupons'&&s.coupon===r.id)s.coupon='';
  r.archived=true;r.archiveReason=reason;r.archivedAt=new Date().toISOString();
  if(type==='products'){r.previousStatus=r.status;r.status='Archived';}
  if(type==='staff'){r.previousStatus=r.status;r.status='Disabled';}
  return r;
}
export function restoreRecord(s,type,id){requirePermission(s,recordModule(type),'delete');const r=recordFor(s,type,id);r.archived=false;if(r.previousStatus){r.status=r.previousStatus;delete r.previousStatus;}return r;}
export function archiveView(s){const types=['orders','products',...Object.keys(s.records)];const rows=types.flatMap(type=>can(s,recordModule(type))?listFor(s,type).filter(r=>r.archived).map(r=>[E(type),E(r.id),E(r.name||r.customer||r.category||''),E(r.archiveReason),button('Restore','manage-restore',`data-type="${type}" data-id="${E(r.id)}" ${can(s,recordModule(type),'delete')?'':'disabled'}`,'btn small secondary')]):[]);return `<h1 class="mb">Archived records</h1>${notice('Deleted records are removed from active lists. Their financial and stock history remains intact.')}${table(['Module','Reference','Record','Reason',''],rows)}`;}
