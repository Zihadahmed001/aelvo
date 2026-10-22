export const money = n => '৳' + Number(n || 0).toLocaleString('en-BD', {maximumFractionDigits:2});
export const escapeHTML = value => String(value ?? '').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
export function normalizePhone(value) { let n=String(value || '').normalize('NFKC').replace(/[০-৯]/g,c=>'০১২৩৪৫৬৭৮৯'.indexOf(c)).replace(/[٠-٩]/g,c=>'٠١٢٣٤٥٦٧٨٩'.indexOf(c)).replace(/\D/g,'').replace(/^(?:00)?8800?/,'0'); return /^1[3-9]\d{8}$/.test(n)?'0'+n:n; }
export function validPhone(value) { return /^01[3-9]\d{8}$/.test(normalizePhone(value)); }
export function needsOTP(user, phone, verifiedCheckoutPhone) { const n=normalizePhone(phone); return !(validPhone(n) && ((user?.verified && normalizePhone(user.phone)===n) || verifiedCheckoutPhone===n)); }
export function cartTotals(cart, products, coupon='', shipping=80) {
  const subtotal=cart.reduce((sum,row)=>sum+(products.find(p=>p.id===row.id)?.price||0)*row.qty,0);
  const discount=coupon==='AELVO10'&&subtotal>=2000?Math.min(Math.round(subtotal*.1),500):0;
  return {subtotal,discount,shipping:subtotal?(coupon==='SHIPFREE'?0:shipping):0,total:subtotal-discount+(subtotal?(coupon==='SHIPFREE'?0:shipping):0)};
}
export const colours = p => p.colors?.length?p.colors:[{id:'default',name:p.color||'Default',hex:'#282828',images:[p.image]}];
export const colour = (p,id) => colours(p).find(c=>c.id===id)||colours(p)[0];
export const imageSrc = path => /^(blob:|data:image\/)/.test(path||'')?path:'assets/'+(path||'product-placeholder.svg');
export const productImage = (p,id,index=0) => imageSrc(colour(p,id).images?.[index]||colour(p,id).images?.[0]||p.image);
export const variantFor = (p,size,color) => p?.variants.find(v=>v.size===size&&(!v.color||v.color===colour(p,color).id));
export const variantLabel = (p,size,color) => p.name+' · '+colour(p,color).name+' · '+size;
export function available(product, size, color) { const s=variantFor(product,size,color); return s&&!product.archived&&!s.archived?Math.max(0,s.onHand-s.reserved):0; }
export function validateAdd(cart, products, id, size, qty=1, color) { const p=products.find(p=>p.id===id); if(!p||p.archived)throw Error('Product not found.'); if(!Number.isInteger(qty)||qty<1)throw Error('Choose a valid quantity.'); const c=colour(p,color).id; const existing=cart.find(r=>r.id===id&&r.size===size&&colour(p,r.color).id===c)?.qty||0; if(existing+qty>available(p,size,c))throw Error('This quantity is not available.'); return true; }
export function addCart(cart,products,id,size,qty=1,color) { validateAdd(cart,products,id,size,qty,color); const p=products.find(p=>p.id===id),c=colour(p,color).id,line=cart.find(r=>r.id===id&&r.size===size&&colour(p,r.color).id===c); if(line)line.qty+=qty; else cart.push({id,size,qty,color:c}); return cart; }
export function codBalance(total, verifiedPaid) { if(verifiedPaid<0||verifiedPaid>total)throw Error('Paid amount exceeds the order total.'); return Math.round((total-verifiedPaid)*100)/100; }
export function profitStatement({gross,discount,refund,cost,costReversal,expenses}) { const revenue=gross-discount-refund,cogs=cost-costReversal,grossProfit=revenue-cogs; return {revenue,cogs,grossProfit,netProfit:grossProfit-expenses.reduce((a,b)=>a+b,0)}; }
export function validateRedirect(from,to,redirects=[]) { if(!from?.startsWith('/')||!to?.startsWith('/')||from.startsWith('//')||to.startsWith('//'))throw Error('Use internal paths beginning with one /.'); if(/[\s<>]/.test(from+to))throw Error('Paths cannot contain spaces or markup.'); if(from===to)throw Error('A redirect cannot point to itself.'); if(redirects.some(r=>r.from===from))throw Error('That source already has a redirect.'); let cursor=to; const seen=new Set([from]); while(cursor){if(seen.has(cursor))throw Error('This redirect would create a loop.');seen.add(cursor);cursor=redirects.find(r=>r.from===cursor)?.to;} return true; }
export function validSlug(slug) { return /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)&&!['admin','account','cart','checkout','search','install','api','assets','robots','sitemap'].includes(slug); }
export const demoReport = {gross:20000,discount:1000,refund:1500,cost:12000,costReversal:1000,expenses:[800,200,300,1200]};
