import assert from 'node:assert/strict';
import {checkoutItems,startBuyNow,relatedProducts,submitReview,reviewSection,popupEligible,savePopup,saveBanner,homepageHero,isOwnerPreview} from '../dist/assets/commerce.js';
import {detectChatLanguage,localizeMarkup} from '../dist/assets/i18n.js';
import {readFileSync, existsSync, readdirSync} from 'node:fs';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {state} from '../dist/assets/data.js';
import {attach,readProduct} from '../dist/assets/editors.js';
import {can} from '../dist/assets/access.js';
import {canChat, eligibleStaff, newWebsiteConversation, receiveCustomerMessage} from '../dist/assets/engagement.js';
import {recordActions} from '../dist/assets/management.js';
import {cartTotals, addCart, available, normalizePhone, validPhone, needsOTP, codBalance, profitStatement, demoReport, validateRedirect} from '../dist/assets/core.js';

// This harness invokes the app's real event handlers. It does not emulate browser
// layout, native form validation, focus, animation, or a supported WebMCP context.
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const initial = structuredClone(state);
const listeners = new Map();
const windowListeners = new Map();
const selectors = new Map();
const element = () => ({
  innerHTML: '', textContent: '', style: {}, open: false,
  classList: {add() {}, remove() {}},
  addEventListener() {}, querySelector() {return null;}, querySelectorAll() {return [];},
  showModal() {this.open = true;}, close() {this.open = false;}, scrollIntoView() {}
});
const app = element(), dialog = element(), toast = element();
selectors.set('#app', app); selectors.set('#modal', dialog); selectors.set('#toast', toast);
globalThis.document = {
  title: '', querySelector: s => selectors.get(s) || null,
  querySelectorAll: () => [],
  addEventListener(type, fn) {listeners.set(type, [...(listeners.get(type) || []), fn]);},
  createElement: () => ({click() {}, append() {}})
};
globalThis.location = {hash: '#/'};
globalThis.history = {replaceState(_a, _b, hash) {location.hash = hash;}};
globalThis.window = {
  addEventListener(type, fn) {windowListeners.set(type, fn);},
  scrollTo() {}, print() {}
};
globalThis.FormData = class {
  constructor(form) {this.entries = Object.entries(form.values).filter(([,v]) => typeof v !== 'boolean');}
  [Symbol.iterator]() {return this.entries[Symbol.iterator]();}
};
const nativeTimeout = globalThis.setTimeout;
const nativeInterval = globalThis.setInterval;
globalThis.setTimeout = (...args) => nativeTimeout(...args).unref();
globalThis.setInterval = (...args) => nativeInterval(...args).unref();

const {render} = await import('../dist/assets/app.js');
let passed = 0;
function reset() {
  for (const key of Object.keys(state)) delete state[key];
  Object.assign(state, structuredClone(initial));
  selectors.delete('#checkout-form');
  location.hash = '#/'; dialog.close(); render();
}
async function test(name, fn) {
  reset();
  try {await fn(); passed++; console.log('PASS ' + name);}
  catch (err) {console.error('FAIL ' + name); throw err;}
}
async function dispatch(type, target, extra = {}) {
  for (const fn of listeners.get(type) || []) await fn({target, preventDefault() {}, ...extra});
}
async function click(action, data = {}) {
  const target = {dataset: {action, ...data}, closest: s => s === '[data-action]' ? target : null};
  await dispatch('click', target);
}
function makeForm(kind, data = {}, dataset = {}) {
  const error = element();
  const form = {
    values: Object.fromEntries(Object.entries(data).filter(([k])=>!k.startsWith('_')).map(([k,v]) => [k, typeof v === 'boolean' ? v : String(v)])),
    dataset: {form: kind, ...dataset},
    closest: s => s === '[data-form]' ? form : null,
    querySelector: s => s === '[data-error]' ? error : null,
    querySelectorAll: selector => selector==='[data-colour-row]' ? data._colours||[] : Object.entries(data).filter(([,v]) => typeof v === 'boolean').map(([name,checked]) => ({name,checked})),
    elements: {namedItem: name => ({value: String(data[name] || ''),files:data._files?.[name]||[]})},
    reset() {}, error
  };
  return form;
}
async function submit(kind, data, dataset, intent) {
  const form = makeForm(kind, data, dataset);
  if (kind === 'checkout') selectors.set('#checkout-form', form);
  await dispatch('submit', form, {submitter: intent ? {value:intent} : undefined});
  return form.error.textContent;
}
const variant = () => state.products[0].variants.find(v => v.size === '42');
const checkout = {name:'Demo Guest', phone:'01812345678', city:'Dhaka', address:'Sample road', area:'Inside Dhaka', payment:'COD', demoConsent:true};

await test('JavaScript syntax and entry assets', () => {
  for (const file of readdirSync(resolve(root, 'dist/assets')).filter(f => f.endsWith('.js'))) {
    const result = spawnSync(process.execPath, ['--check', resolve(root, 'dist/assets', file)], {encoding:'utf8'});
    assert.equal(result.status, 0, result.stderr);
  }
  const html = readFileSync(resolve(root, 'dist/index.html'), 'utf8');
  assert.match(html, /type="module" src="assets\/app.js"/);
  assert.match(html, /noindex,nofollow/);
  for (const p of state.products) assert.ok(existsSync(resolve(root, 'dist/assets', p.image)));
});

await test('Bangladesh numbers and the three checkout OTP branches', () => {
  assert.equal(normalizePhone('+880 1712-345678'), '01712345678');
  assert.equal(normalizePhone('008801712345678'), '01712345678');
  assert.ok(validPhone('01712345678'));
  assert.ok(!validPhone('01212345678'));
  const user = {phone:'01712345678', verified:true};
  assert.equal(needsOTP(user, '+8801712345678'), false);
  assert.equal(needsOTP(user, '01812345678'), true);
  assert.equal(needsOTP(null, '01712345678'), true);
  assert.equal(needsOTP(null, '01712345678', '01712345678'), false);
  assert.equal(needsOTP(null, '01812345678', '01712345678'), true);
});

await test('Discount cap, delivery charge, and variant availability', () => {
  addCart(state.cart, state.products, 'grid-runner', '42', 2);
  assert.deepEqual(cartTotals(state.cart, state.products, 'AELVO10', 130), {subtotal:11980,discount:500,shipping:130,total:11610});
  assert.equal(cartTotals(state.cart, state.products, 'SHIPFREE').shipping, 0);
  assert.equal(cartTotals([], state.products).total, 0);
  assert.throws(() => addCart(state.cart, state.products, 'grid-runner', '42', 100));
  assert.throws(() => addCart(state.cart, state.products, 'grid-runner', '42', 0.5));
  assert.equal(available(state.products[0], '42'), 40);
  assert.equal(codBalance(3500, 700), 2800);
  assert.throws(() => codBalance(3500, 3501));
});

await test('Guest verification and checkout reserve inventory once', async () => {
  addCart(state.cart, state.products, 'grid-runner', '42', 1);
  assert.match(await submit('checkout', checkout), /Verify/);
  assert.equal(state.orders.length, initial.orders.length);
  selectors.set('#checkout-form', makeForm('checkout', checkout));
  await click('checkout-otp');
  assert.match(dialog.innerHTML, /123456/);
  assert.match(await submit('otp', {code:'000000'}), /Incorrect/);
  assert.equal(await submit('otp', {code:'123456'}), '');
  assert.equal(state.user, null, 'Guest OTP must not create an account');
  assert.equal(state.verifiedCheckoutPhone, checkout.phone);
  assert.equal(await submit('checkout', checkout), '');
  assert.equal(state.lastOrder.total, 6070);
  assert.equal(state.lastOrder.paid, 0);
  assert.equal(variant().reserved, 9);
  assert.equal(variant().onHand, 48);
  assert.equal(state.cart.length, 0);
  assert.match(await submit('checkout', checkout), /Verify|empty/);
  assert.equal(state.orders.length, initial.orders.length + 1);
});

await test('Account signup requires OTP and phone changes remain unverified until OTP', async () => {
  assert.match(await submit('signup', {name:'New Demo', email:'', phone:'01912345678', password:'sample123'}), /valid email/);
  await submit('signup', {name:'New Demo', email:'new@example.com', phone:'01912345678', password:'sample123'});
  assert.equal(state.user, null);
  await submit('otp', {code:'123456'});
  assert.equal(state.user.phone, '01912345678');
  assert.equal(state.user.email, 'new@example.com');assert.equal(state.user.emailVerified,false);
  await submit('profile', {name:'New Demo', phone:'01812345678', email:''});
  assert.equal(state.user.phone, '01912345678');
  await submit('otp', {code:'123456'});
  assert.equal(state.user.phone, '01812345678');
});

await test('Payment proof stays pending; verified advance unblocks fulfilment', async () => {
  await click('demo-login');
  addCart(state.cart, state.products, 'grid-runner', '42', 1);
  const data = {...checkout, phone:state.user.phone, payment:'bKash', reference:'DEMO-CUSTOMER-PROOF'};
  assert.equal(await submit('checkout', data), '');
  const order = state.lastOrder, funds = state.accounts.bKash;
  assert.equal(order.status, 'Awaiting Advance Payment');
  assert.equal(order.paid, 0);
  assert.equal(state.records.payments[0].status, 'Review required');
  assert.match(await submit('order-status', {status:'Processing',reason:'Test'}, {id:order.id}), /advance/);
  await click('courier-book', {id:order.id});
  assert.match(toast.textContent, /advance/);
  const payment = {amount:500, account:'bKash', reference:'DEMO-MERCHANT-001', verified:true};
  assert.equal(await submit('order-payment', payment, {id:order.id}), '');
  assert.equal(order.paid, 500);
  assert.equal(order.status, 'Processing');
  assert.equal(state.accounts.bKash, funds + 500);
  assert.match(await submit('order-payment', payment, {id:order.id}), /already/);
  assert.match(await submit('order-payment', {...payment, amount:99999,reference:'DIFFERENT'}, {id:order.id}), /exceeds/);
  assert.equal(state.accounts.bKash, funds + 500);
});

await test('Cancellation releases reservations once and cannot reopen without new stock reservation', async () => {
  assert.equal(await submit('order-status', {status:'Cancelled', reason:'Test'}, {id:'AEL-1025'}), '');
  assert.equal(variant().reserved, 7);
  await submit('order-status', {status:'Cancelled', reason:'Repeated'}, {id:'AEL-1025'});
  assert.equal(variant().reserved, 7);
  assert.match(await submit('order-status', {status:'Processing', reason:'Reopen'}, {id:'AEL-1025'}), /new order/);
});

await test('Delivery commits physical stock once without treating COD as settled money', async () => {
  const funds = structuredClone(state.accounts);
  await submit('order-status', {status:'Delivered',reason:'Test'}, {id:'AEL-1025'});
  assert.equal(variant().onHand, 47);
  assert.equal(variant().reserved, 7);
  await submit('order-status', {status:'Delivered',reason:'Repeated'}, {id:'AEL-1025'});
  assert.equal(variant().onHand, 47);
  assert.deepEqual(state.accounts, funds);
  assert.equal(state.orders[0].paid, 500);
});

await test('Stock drafts, posted receipts, duplicate references and reserved write-offs', async () => {
  const receipt = {variant:'grid-runner::42',qty:2,type:'Purchase receipt',reference:'PO-TEST',note:'Received',intent:'draft'};
  await submit('record', receipt, {type:'stock'});
  assert.equal(variant().onHand, 48);
  assert.equal(state.saved.stockDrafts.length, 1);
  await submit('record', {...receipt,intent:'post'}, {type:'stock'});
  assert.equal(variant().onHand, 50);
  assert.match(await submit('record', {...receipt,intent:'post'}, {type:'stock'}), /already/);
  assert.match(await submit('record', {...receipt,intent:'post',qty:43,type:'Damage / write-off',reference:'DAMAGE'}, {type:'stock'}), /unreserved/);
  assert.equal(variant().onHand, 50);
});

await test('Purchase drafts change neither inventory nor account balances', async () => {
  const funds = structuredClone(state.accounts);
  await submit('record', {supplier:'Northline Footwear',variant:'grid-runner::42',qty:20,cost:2800,discount:2000,shipping:2000,other:400,_files:{purchaseSlip:[new File(['%PDF-1.4 sample'],'purchase.pdf',{type:'application/pdf'})]}}, {type:'purchases'});
  assert.equal(state.records.purchases[0].total, 56400);
  assert.equal(state.records.purchases[0].paid, 0);
  assert.equal(variant().onHand, 48);
  assert.deepEqual(state.accounts, funds);
});

await test('Manual sale draft versus completed sale and correct change accounting', async () => {
  await submit('manual-add', {size:'42',qty:1}, {id:'grid-runner'});
  await submit('manual-sale', {customer:'Walk-in',discount:0,received:0,account:'Cash'}, {}, 'draft');
  assert.equal(variant().onHand, 48);
  assert.equal(state.accounts.Cash, 25000);
  await submit('manual-add', {size:'42',qty:1}, {id:'grid-runner'});
  assert.match(await submit('manual-sale', {discount:0,received:5000,account:'Cash'}, {}, 'complete'), /less/);
  await submit('manual-sale', {customer:'Walk-in',discount:90,received:6000,account:'Cash'}, {}, 'complete');
  assert.equal(state.accounts.Cash, 30900, 'Revenue is sale total, not cash tendered');
  assert.equal(variant().onHand, 47);
  assert.equal(state.orders[0].total, 5900);
});

await test('Transfers preserve total funds and expense posting cannot repeat', async () => {
  const total = Object.values(state.accounts).reduce((a,b) => a+b, 0);
  assert.match(await submit('transfer', {from:'Cash',to:'Bank',amount:99999,explanation:'Move funds'}), /Insufficient/);
  await submit('transfer', {from:'Cash',to:'Bank',amount:10000,reference:'TRANSFER',explanation:'Move funds'});
  assert.equal(state.accounts.Cash, 15000);
  assert.equal(Object.values(state.accounts).reduce((a,b) => a+b, 0), total);
  const expense = {category:'Software',amount:1200,account:'Bank',status:'Posted',date:'2026-10-07'};
  await submit('record', expense, {type:'expenses',id:'EXP-004'});
  assert.equal(state.accounts.Bank, 133800);
  assert.match(await submit('record', expense, {type:'expenses',id:'EXP-004'}), /cannot/);
  assert.equal(state.accounts.Bank, 133800);
});

await test('Settlement variance must resolve and bank receives it only once', async () => {
  assert.match(await submit('settlement', {amount:14650,note:'Unresolved'}), /variance/);
  assert.equal(state.accounts.Bank, 125000);
  await submit('settlement', {amount:14700,note:'Matched receipt'});
  assert.equal(state.accounts.Bank, 139700);
  assert.match(await submit('settlement', {amount:14700,note:'Repeat'}), /already/);
  assert.equal(state.accounts.Bank, 139700);
});

await test('Restock and refund approval remain separate, with duplicate and amount guards', async () => {
  const wallet = state.products[2].variants[0], funds = structuredClone(state.accounts);
  assert.match(await submit('restock-return', {variant:'grid-runner::42'}, {id:'RMA-0021'}), /inspected product/);
  await submit('restock-return', {variant:'leather-wallet::One size'}, {id:'RMA-0021'});
  assert.equal(wallet.onHand, 33);
  assert.match(await submit('restock-return', {variant:'leather-wallet::One size'}, {id:'RMA-0021'}), /already/);
  assert.match(await submit('refund-review', {amount:2500,account:'bKash'}, {id:'RMA-0021'}), /exceeds/);
  await submit('refund-review', {amount:2490,account:'bKash'}, {id:'RMA-0021'});
  assert.equal(state.records.returns[0].refund, 'Approved');
  assert.deepEqual(state.accounts, funds, 'Approval must not execute a refund');
});

await test('Actual COGS profit statement and redirect loop rejection', () => {
  assert.deepEqual(profitStatement(demoReport), {revenue:17500,cogs:11000,grossProfit:6500,netProfit:4000});
  assert.throws(() => validateRedirect('/a','/b',[{from:'/b',to:'/a'}]));
  assert.throws(() => validateRedirect('/a','/a'));
  assert.throws(() => validateRedirect('//external.example','/a'));
  assert.equal(validateRedirect('/old','/new'), true);
});

await test('Role routes restrict the visible modules', () => {
  state.previewRole = 'Entry Staff'; location.hash = '#/admin/accounts'; render();
  assert.match(app.innerHTML, /outside the selected role/);
  location.hash = '#/admin/manual'; render(); assert.match(app.innerHTML, /Manual/);
  state.previewRole = 'SEO Manager'; location.hash = '#/admin/content'; render();
  assert.match(app.innerHTML, /outside the selected role/);
  location.hash = '#/admin/seo'; render(); assert.match(app.innerHTML, /SEO readiness/);
});

await test('Checkout restores payment proof fields after verification renders', () => {
  state.cart = [{id:'grid-runner',size:'42',qty:1}];
  state.checkoutDraft = {...checkout,payment:'bKash',reference:'DEMO-SAVED'};
  location.hash = '#/checkout'; render();
  assert.match(app.innerHTML, /value="bKash" checked/);
  assert.match(app.innerHTML, /value="DEMO-SAVED"/);
  assert.doesNotMatch(app.innerHTML, /id="advance-fields" hidden/);
});

await test('Search and status filters combine instead of overwriting each other', async () => {
  const rows = [{textContent:'Rahim Processing'},{textContent:'Nabila Processing'},{textContent:'Rahim Delivered'}];
  const search = {value:'Rahim',dataset:{tableSearch:'orders'},matches:s=>s==='[data-table-search]'};
  const status = {value:'Processing',dataset:{tableStatus:'orders'},matches:s=>s==='[data-table-status]'};
  selectors.set('[data-table-search="orders"]', search);
  selectors.set('[data-table-status="orders"]', status);
  selectors.set('[data-filter-table="orders"] table', {querySelectorAll:()=>rows});
  await dispatch('input', search); await dispatch('change', status);
  assert.deepEqual(rows.map(r=>r.hidden), [false,true,true]);
});

await test('Storefront, linked Admin modules and all installer screens render with local assets', () => {
  state.user = {name:'Rahim Ahmed',phone:'01700007788',verified:true};
  state.cart = [{id:'grid-runner',size:'42',qty:1}];
  state.wishlist = ['everyday-tote']; state.lastOrder = state.orders[0];
  const paths = new Set(['/', '/shop', '/search?q=shoe', '/product/grid-runner', '/cart', '/checkout', '/confirmation', '/tracking?id=AEL-1024', '/signin', '/signup', '/account', '/admin', '/install', '/not-found']);
  let rendered = 0;
  for (const path of paths) {
    assert.ok(paths.size < 180, 'Unexpected unbounded route collection');
    location.hash = '#' + path; render(); rendered++;
    assert.ok(app.innerHTML.length > 100);
    assert.doesNotMatch(app.innerHTML, /\b(?:undefined|NaN)\b/, path);
    for (const [,asset] of app.innerHTML.matchAll(/src="(assets\/[^"]+)"/g)) assert.ok(existsSync(resolve(root, 'dist', asset)), `${path}: ${asset}`);
    for (const [,route] of app.innerHTML.matchAll(/href="#(\/[^"#]*)"/g)) paths.add(route.replaceAll('&amp;','&'));
  }
  for (let step = 0; step <= 10; step++) {state.installerStep=step; location.hash='#/install';render();assert.doesNotMatch(app.innerHTML,/\bundefined\b/);rendered++;}
  console.log(`  Rendered ${rendered} route/state templates; asset references resolved.`);
});

await test('Installer password rules reject mismatch and do not save passwords', async () => {
  state.installerStep = 4;
  assert.match(await submit('installer', {password:'StrongDemo123',confirm:'Different123'}), /do not match/);
  assert.equal(state.installerStep, 4);
  await submit('installer', {password:'StrongDemo123',confirm:'StrongDemo123'});
  assert.equal(state.installerStep, 5);
  assert.ok(!JSON.stringify(state).includes('StrongDemo123'));
});

await test('Autofill tolerates spaces, country codes and Bengali numerals without a native pattern trap', async () => {
  for(const phone of ['+880 1700 007788','00880-1700-007788','০১৭০০০০৭৭৮৮','1700007788'])assert.equal(normalizePhone(phone),'01700007788');
  await click('demo-login'); state.cart=[{id:'grid-runner',size:'42',qty:1,color:'cloud'}];
  location.hash='#/checkout';render();assert.doesNotMatch(app.innerHTML,/name="phone"[^>]*pattern=/);
  assert.equal(await submit('checkout',{...checkout,phone:'+880 1700 007788'}),'');
  assert.equal(state.lastOrder.phone,'01700007788');
});

await test('Colour choice changes the photo and reserves the matching colour/size only', async () => {
  location.hash='#/product/grid-runner';render();
  await click('select-colour',{id:'grid-runner',color:'graphite'});
  assert.match(app.innerHTML,/shoe-black.png/);
  await click('add-cart',{id:'grid-runner',size:'42',color:'graphite'});
  await click('add-cart',{id:'grid-runner',size:'42',color:'cloud'});
  assert.equal(state.cart.length,2);
  assert.deepEqual(state.cart.map(r=>r.color),['graphite','cloud']);
  state.verifiedCheckoutPhone=checkout.phone;await submit('checkout',checkout);
  assert.equal(state.products[0].variants.find(v=>v.color==='graphite'&&v.size==='42').reserved,1);
  assert.equal(variant().reserved,9);
  assert.throws(()=>addCart(state.cart,state.products,'grid-runner','42',8,'graphite'));
});

await test('Product image uploads save galleries for separate colours and retain existing stock', async () => {
  const data={name:'Test shoes',category:'Shoes',price:1000,cost:500,description:'Sample',sizes:'40, 41',status:'Active',colorName0:'Red',colorHex0:'#ba2020',colorName1:'Black',colorHex1:'#222222',
    _colours:[{dataset:{index:'0',colourId:''}},{dataset:{index:'1',colourId:''}}],
    _files:{colorFiles0:[new File(['sample'],'red.png',{type:'image/png'})],colorFiles1:[new File(['sample'],'black.jpg',{type:'image/jpeg'})]}};
  assert.equal(await submit('record',data,{type:'products'}),'');
  const p=state.products.at(-1);assert.equal(p.colors.length,2);assert.equal(p.variants.length,4);assert.ok(p.colors[0].images[0].startsWith('blob:'));
  p.variants[0].onHand=5;
  const edit={...data,price:1200,_files:{},_colours:p.colors.map((c,i)=>({dataset:{index:String(i),colourId:c.id}}))};
  assert.equal(await submit('record',edit,{type:'products',id:p.id}),'');assert.equal(p.variants[0].onHand,5);assert.equal(p.variants.length,4);
  const noPhotos={...data,_files:{}};assert.match(await submit('record',noPhotos,{type:'products'}),/photo/);
  assert.throws(()=>attach(new File(['sample'],'bad.svg',{type:'image/svg+xml'}),true,true),/JPG/);
});

await test('Purchase slip is mandatory and accepts PDF and common image files', async () => {
  const purchase={supplier:'Northline Footwear',variant:'grid-runner::42::cloud',qty:2,cost:1000,discount:0,shipping:0,other:0};
  assert.match(await submit('record',purchase,{type:'purchases'}),/required document/);
  assert.equal(state.records.purchases.length,initial.records.purchases.length);
  assert.equal(await submit('record',{...purchase,_files:{purchaseSlip:[new File(['sample'],'slip.png',{type:'image/png'})]}},{type:'purchases'}),'');
  assert.equal(state.records.purchases[0].slip.name,'slip.png');
  location.hash='#/admin/purchases';render();assert.match(app.innerHTML,/slip.png/);
});

await test('Owner stock corrections preserve reserved units and create traceable movements', async () => {
  const args={id:'grid-runner',size:'42',color:'graphite'};
  await submit('inventory-adjust',{onHand:11,reason:'Physical count'},args);
  const m=state.records.movements.at(-1);assert.equal(m.qty,3);assert.equal(m.color,'graphite');assert.match(m.reference,/^ADJ-/);
  assert.match(m.note,/8 → 11/);
  assert.match(await submit('inventory-adjust',{onHand:1,reason:'Count'},{...args,color:'cloud'}),/reserved/);
  assert.equal(variant().onHand,48);
  location.hash='#/admin/inventory';render();assert.match(app.innerHTML,/shoe-black.png/);assert.match(app.innerHTML,/data-action="inventory-adjust"/);
});

await test('Balance edits and funds record explanations, documents and before/after amounts', async () => {
  assert.match(await submit('balance-adjust',{account:'Cash',balance:26000,explanation:''}),/Explain/);
  await submit('balance-adjust',{account:'Cash',balance:26000,explanation:'Opening count correction'});
  assert.equal(state.accounts.Cash,26000);assert.deepEqual(state.financeHistory[0].changes,[{account:'Cash',before:25000,after:26000}]);
  await submit('add-funds',{account:'Bank',amount:500,explanation:'Owner contribution',reference:'OWN-1',_files:{document:[new File(['%PDF'],'receipt.pdf',{type:'application/pdf'})]}});
  assert.equal(state.accounts.Bank,125500);assert.equal(state.financeHistory.at(-1).document.name,'receipt.pdf');
  await submit('transfer',{from:'Bank',to:'Cash',amount:1000,explanation:'Petty cash',reference:'TX-1'});
  assert.equal(state.financeHistory.at(-1).document,null);assert.equal(state.financeHistory.at(-1).changes.length,2);
});

await test('Deleted promotions cannot be used again and past discounts stay intact', async () => {
  state.cart=[{id:'grid-runner',size:'42',qty:1}];state.coupon='AELVO10';
  await submit('delete-coupon',{}, {id:'AELVO10'});
  assert.equal(state.coupon,'');assert.ok(!state.records.coupons.some(c=>c.id==='AELVO10'));
  assert.equal(state.deletedCoupons[0].used,24);assert.equal(state.orders[0].discount,848);
  assert.match(await submit('coupon',{code:'AELVO10'}),/deleted/);
});

await test('Owner can grant/revoke role actions and staff overrides without losing owner access', async () => {
  state.previewRole='Entry Staff';
  assert.match(await submit('inventory-adjust',{onHand:50,reason:'Count'},{id:'grid-runner',size:'42',color:'cloud'}),/does not have edit/);
  state.previewRole='Super Admin';state.permissionRole='Entry Staff';
  await submit('permissions',{inventory__view:true,inventory__edit:true});
  state.previewRole='Entry Staff';assert.equal(can(state,'inventory','edit'),true);assert.equal(can(state,'manual','create'),false);
  assert.equal(await submit('inventory-adjust',{onHand:50,reason:'Count'},{id:'grid-runner',size:'42',color:'cloud'}),'');
  state.previewRole='Super Admin';assert.equal(can(state,'accounts','edit'),true);
  await submit('staff-permissions',{inherit:false,products__view:true},{id:'USR-002'});
  state.previewStaff='USR-002';state.previewRole='Entry Staff';assert.equal(can(state,'products'),true);assert.equal(can(state,'inventory'),false);
  state.previewStaff=null;state.previewRole='Super Admin';assert.equal(can(state,'team','delete'),true);
  await submit('new-role',{name:'Warehouse'});assert.ok(Object.hasOwn(state.roles,'Warehouse'));
  assert.match(await submit('new-role',{name:'Super Admin'}),/unique/);
});

await test('Staff contact and profile fields save; owner access cannot be disabled', async () => {
  const profile={name:'Owner',phone:'+880 1700 007788',email:'owner@example.com',designation:'Owner',address:'Dhaka',joined:'2026-10-01',notes:'Profile updated',role:'Entry Staff',status:'Disabled'};
  assert.equal(await submit('record',profile,{type:'staff',id:'USR-001'}),'');
  const owner=state.records.staff[0];assert.equal(owner.phone,'01700007788');assert.equal(owner.role,'Super Admin');assert.equal(owner.status,'Active');
  location.hash='#/admin/team';render();assert.match(app.innerHTML,/01700007788/);
  location.hash='#/admin/settings/sms';render();assert.match(app.innerHTML,/BulkSMSBD/);assert.match(app.innerHTML,/disabled placeholder="Add securely/);
});

await test('Header search opens in place, filters live, and mobile bag is centered', async () => {
  state.cart=[{id:'everyday-tote',size:'One size',qty:1,color:'black'}];
  location.hash='#/';render();
  const nav=app.innerHTML.slice(app.innerHTML.indexOf('<nav class="mobile-nav"'));
  const shop=nav.indexOf('href="#/shop"'),bag=nav.indexOf('center-bag'),wish=nav.indexOf('href="#/wishlist"');
  assert.ok(shop>=0&&bag>shop&&wish>bag,'Mobile nav order is Home, Shop, Bag, Wishlist, Account');
  await click('open-search');assert.equal(state.searchOpen,true);assert.match(app.innerHTML,/class="header-search is-open"/);
  const results=element();selectors.set('#header-search-results',results);
  const input={id:'header-query',value:'Graphite',matches:()=>false,closest:()=>null};
  await dispatch('input',input);
  assert.match(results.innerHTML,/Grid Runner/);assert.match(results.innerHTML,/Matching pieces/);
  assert.equal(state.cart.length,1,'Search must not alter the bag');
  await click('close-search');assert.equal(state.searchOpen,false);
  location.hash='#/product/everyday-tote';render();await click('add-cart',{id:'everyday-tote',size:'One size',color:'black'});
  assert.match(toast.innerHTML,/href="#\/cart"/,'The complete added-to-bag toast links to the bag');
});

await test('Assistant handoff pauses automated replies and routes into the authorized queue', () => {
  const c=newWebsiteConversation(state);
  receiveCustomerMessage(state,'I need to speak to a human agent');
  assert.equal(c.mode,'human');assert.equal(c.status,'Waiting');assert.equal(c.assignee,null);
  const before=c.messages.length;receiveCustomerMessage(state,'Show me shoes');
  assert.equal(c.messages.length,before+1,'After handoff, a customer message waits for staff instead of receiving an automated reply');
  assert.equal(c.messages.at(-1).role,'customer');
  assert.match(c.messages.find(m=>m.role==='system').text,/authorized support queue/);
});

await test('Support inbox scopes channels and rechecks staff permissions on replies', async () => {
  state.previewRole='Entry Staff';
  assert.equal(canChat(state,'WhatsApp'),false);assert.ok(!eligibleStaff(state,'WhatsApp').some(p=>p.id==='USR-002'));
  location.hash='#/admin/support';render();assert.match(app.innerHTML,/outside the selected role/);
  state.roles['Entry Staff']={support:['view','edit'],'support-whatsapp':['view','edit']};
  assert.equal(canChat(state,'WhatsApp','edit'),true);assert.equal(canChat(state,'Messenger'),false);
  assert.deepEqual(eligibleStaff(state,'WhatsApp').map(p=>p.id),['USR-001','USR-002']);
  location.hash='#/admin/support/CHAT-001';render();assert.match(app.innerHTML,/Sample WhatsApp customer/);assert.doesNotMatch(app.innerHTML,/Sample Messenger customer|CHAT-002/);
  await click('support-take',{id:'CHAT-001'});assert.equal(state.support.conversations[0].assignee,'USR-002');
  assert.equal(await submit('support-reply',{message:'I can help with the fit.'},{id:'CHAT-001'}),'');
  const c=state.support.conversations[0];assert.equal(c.messages.at(-1).author,'Entry Staff');
  state.roles['Entry Staff']['support-whatsapp']=['view'];
  assert.match(await submit('support-reply',{message:'This should be blocked.'},{id:'CHAT-001'}),/permission/);
  assert.notEqual(c.messages.at(-1).text,'This should be blocked.');
});

await test('Assistant order lookup protects private order status', () => {
  const c=newWebsiteConversation(state);receiveCustomerMessage(state,'What is my order status?');
  assert.match(c.messages.at(-1).text,/Sign in to your verified account/);
  assert.doesNotMatch(c.messages.at(-1).text,/AEL-10\d+/);
  state.user={name:'Rahim',phone:'01700007788',verified:true};
  receiveCustomerMessage(state,'Check my order status');
  assert.match(c.messages.at(-1).text,/AEL-1025/);
});

await test('Assistant product selection adds only to bag; checkout and OTP still gate order creation', async () => {
  const reserved=variant().reserved,orderCount=state.orders.length;
  await click('assistant-open');await click('assistant-prompt',{text:'Show shoes'});
  assert.match(app.innerHTML,/Grid Runner/);await click('assistant-product',{id:'grid-runner'});
  assert.match(dialog.innerHTML,/>Colour &amp; size</);
  assert.equal(await submit('assistant-cart',{variant:'grid-runner::42::cloud',qty:'1'}),'');
  assert.equal(state.cart.length,1);assert.equal(state.orders.length,orderCount);
  assert.equal(variant().reserved,reserved,'Adding to bag does not reserve stock or create an order');
  assert.match(await submit('checkout',checkout),/Verify/);
  assert.equal(state.orders.length,orderCount);
});

await test('Out-of-stock variants do not appear as assistant purchase suggestions', () => {
  const p=state.products[0];p.variants.forEach(v=>{v.onHand=v.reserved;});
  const c=newWebsiteConversation(state);receiveCustomerMessage(state,'Show shoes');
  assert.equal(c.suggestions.length,0);assert.match(c.messages.at(-1).text,/No catalogue items match/);
});

await test('Admin role permissions expose consistent record actions; audit remains append-only', async () => {
  const actions=recordActions(state,'coupons','AELVO10');
  for(const label of ['View','Edit','Delete'])assert.match(actions,new RegExp(label));
  state.previewRole='Entry Staff';state.roles['Entry Staff']={coupons:['view']};
  const limited=recordActions(state,'coupons','AELVO10');assert.match(limited,/data-action="manage-edit"[^>]*disabled/);assert.match(limited,/data-action="manage-delete"[^>]*disabled/);
  state.previewRole='Super Admin';location.hash='#/admin/audit';render();
  assert.match(app.innerHTML,/data-action="audit-locked" disabled title="Audit history is append-only"/);
  assert.match(app.innerHTML,/data-action="audit-view"/);
});

await test('Archiving a posted expense preserves account balances and supports a permissioned restore', async () => {
  const before=state.accounts.Cash,history=state.financeHistory.length,expense=state.records.expenses.find(r=>r.id==='EXP-001');
  await click('manage-delete',{type:'expenses',id:expense.id});
  assert.match(dialog.innerHTML,/Financial and stock history stays intact/);
  assert.equal(await submit('record-delete',{reason:'Duplicate display record'},{type:'expenses',id:expense.id}),'');
  assert.equal(expense.archived,true);assert.equal(state.accounts.Cash,before);assert.equal(state.financeHistory.length,history);
  state.previewRole='Entry Staff';state.roles['Entry Staff']={accounts:['view']};location.hash='#/admin/archive';render();
  assert.match(app.innerHTML,/data-action="manage-restore"[^>]*disabled/);
  state.previewRole='Super Admin';location.hash='#/admin/archive';render();await click('manage-restore',{type:'expenses',id:expense.id});
  assert.equal(expense.archived,false);assert.equal(state.accounts.Cash,before);
});

await test('Deleting unpaid reservations releases once; deleting paid live orders is blocked', async () => {
  const unpaid=state.orders.find(o=>o.id==='AEL-1024'),stock=state.products[0].variants.find(v=>v.color==='cloud'&&v.size==='41');
  const onHand=stock.onHand,reserved=stock.reserved;
  assert.equal(await submit('record-delete',{reason:'Duplicate test order'},{type:'orders',id:unpaid.id}),'');
  assert.equal(unpaid.archived,true);assert.equal(unpaid.status,'Cancelled');assert.equal(stock.onHand,onHand);assert.equal(stock.reserved,reserved-1);
  const paid=state.orders.find(o=>o.id==='AEL-1025'),paidReserved=paid.reserved,paidStock=variant().reserved;
  assert.match(await submit('record-delete',{reason:'Not safe'},{type:'orders',id:paid.id}),/Resolve the payment/);
  assert.equal(paid.archived,undefined);assert.equal(paid.reserved,paidReserved);assert.equal(variant().reserved,paidStock);
});

await test('Archiving a product removes it from assistant suggestions and prevents adding it to bag', async () => {
  const p=state.products[0];
  assert.equal(await submit('record-delete',{reason:'Discontinued sample item'},{type:'products',id:p.id}),'');
  assert.equal(p.status,'Archived');assert.equal(recordActions(state,'products',p.id).includes('manage-delete'),true);
  assert.throws(()=>addCart(state.cart,state.products,p.id,'42',1,'cloud'));
  const c=newWebsiteConversation(state);receiveCustomerMessage(state,'Show shoes');assert.ok(!c.suggestions.includes(p.id));
});

await test('Theme and social login settings save safely without faking OAuth authentication', async () => {
  location.hash='#/admin/settings/theme';render();assert.match(app.innerHTML,/Main theme colours/);
  assert.equal(await submit('config',{accent:'#abcdef',surface:'#f3f4f5'},{section:'settings-theme'}),'');
  assert.equal(state.saved['settings-theme'].accent,'#abcdef');
  assert.match(await submit('config',{accent:'blue',surface:'#ffffff'},{section:'settings-theme'}),/valid theme colours/);
  assert.equal(state.saved['settings-theme'].accent,'#abcdef','Invalid colours do not overwrite the saved theme');
  await submit('config',{google:false,facebook:true},{section:'settings-login'});
  location.hash='#/signup';render();assert.doesNotMatch(app.innerHTML,/Continue with Google/);assert.match(app.innerHTML,/Continue with Facebook/);
  await click('social-login',{provider:'Facebook'});
  assert.match(dialog.innerHTML,/not connected yet/);assert.equal(state.user,null);assert.equal(state.authAccount,undefined);
});

await test('Redirect records expose all three actions and edits retain the chosen HTTP status', async () => {
  const redirect=state.records.redirects[0];redirect.type='302';location.hash='#/admin/seo/redirects';render();
  assert.match(app.innerHTML,/data-action="manage-view"/);assert.match(app.innerHTML,/data-action="manage-edit"/);assert.match(app.innerHTML,/data-action="manage-delete"/);
  await click('manage-edit',{type:'redirects',id:redirect.id});assert.match(dialog.innerHTML,/value="302" selected/);
  assert.equal(await submit('record',{from:'/old-shoes','to':'/shop','type':'302'},{type:'redirects',id:redirect.id}),'');
  assert.equal(redirect.type,'302');
  assert.equal(await submit('record-delete',{reason:'Expired campaign'},{type:'redirects',id:redirect.id}),'');
  assert.equal(redirect.archived,true);
});

await test('Buy It Now checks out the selected variant and preserves the existing bag', async()=>{
  addCart(state.cart,state.products,'leather-wallet','One size',1,'black');const bag=structuredClone(state.cart);
  await click('buy-now',{id:'grid-runner',size:'42',color:'graphite'});render();
  assert.match(location.hash,/#?\/checkout/);assert.equal(checkoutItems(state)[0].color,'graphite');assert.deepEqual(state.cart,bag);
  assert.match(await submit('checkout',checkout),/Verify/);
  state.verifiedCheckoutPhone=checkout.phone;
  assert.equal(await submit('checkout',checkout),'');assert.deepEqual(state.cart,bag);assert.equal(state.lastOrder.items.length,1);assert.equal(state.lastOrder.items[0].id,'grid-runner');assert.equal(state.lastOrder.items[0].color,'graphite');
});
await test('Buy It Now rejects unavailable variants and abandoning it restores normal checkout',async()=>{
  assert.throws(()=>startBuyNow(state,'grid-runner','99','cloud'),/available/);
  addCart(state.cart,state.products,'leather-wallet','One size',1,'black');startBuyNow(state,'grid-runner','42','cloud');
  location.hash='#/shop';render();assert.equal(state.buyNow,null);assert.equal(checkoutItems(state)[0].id,'leather-wallet');
  assert.match(app.innerHTML,/data-action="buy-now-select"/);
});
await test('Reviews require a delivered owned product and publish only after moderation',async()=>{
  state.user={name:'Verified customer',phone:'01900002233',verified:true};const input={order:'AEL-1023',rating:'5',body:'Fits well.'};
  assert.throws(()=>submitReview(state,'grid-runner',input),/delivered/);
  const review=submitReview(state,'leather-wallet',input);assert.equal(review.status,'Pending');
  assert.doesNotMatch(reviewSection(state,state.products[2]),/Fits well/);
  assert.throws(()=>submitReview(state,'leather-wallet',input),/already exists/);
  await submit('review-moderation',{status:'Published',reply:'Thank you!',reason:'Verified review'},{id:review.id});
  assert.match(reviewSection(state,state.products[2]),/Fits well/);assert.match(reviewSection(state,state.products[2]),/Verified Purchase/);
  state.previewRole='Entry Staff';state.roles['Entry Staff']={products:['view','edit']};
  assert.match(await submit('review-moderation',{status:'Removed',reason:'Remove'},{id:review.id}),/delete access/);
  assert.equal(review.status,'Published');assert.equal(review.body,'Fits well.');
});
await test('Related products exclude the current, archived, draft and out-of-stock products',()=>{
  const p=state.products[0];assert.ok(!relatedProducts(state,p).includes(p));
  state.products[1].archived=true;state.products[2].variants.forEach(v=>v.onHand=v.reserved);
  assert.equal(relatedProducts(state,p).length,0);
});
await test('Popup publication, scheduling, frequency and destinations are enforced',()=>{
  const v={enabled:true,heading:'New',frequency:'daily',delay:0,destination1:'/shop',destination2:'/shop',start:'2026-10-01T00:00',end:'2026-11-01T00:00'};
  const now=Date.parse('2026-10-10T12:00Z');savePopup(state,v,null,'Draft');assert.equal(popupEligible(state,'/',now),false);
  savePopup(state,v,null,'Published');assert.equal(popupEligible(state,'/',now),true);
  assert.equal(popupEligible(state,'/checkout',now),false);assert.equal(popupEligible(state,'/account',now),false);
  assert.equal(popupEligible(state,'/',now,{day:'2026-10-10'}),false);assert.equal(popupEligible(state,'/',Date.parse('2026-12-01T00:00Z')),false);
  assert.throws(()=>savePopup(state,{...v,destination1:'javascript:alert(1)'},null,'Published'),/destination/);
  assert.throws(()=>savePopup(state,{...v,start:'invalid'},null,'Published'),/schedule/);
});
await test('Video banners need a video and poster; draft edits preserve the published hero',()=>{
  const v={heading:'Published title',mediaType:'video',placement:'Homepage hero',destination:'All products'};
  assert.throws(()=>saveBanner(state,v,makeForm('banner',{}),'Published'),/video and its poster/);
  const files={heroVideo:[new File(['sample'],'hero.mp4',{type:'video/mp4'})],heroPoster:[new File(['sample'],'poster.png',{type:'image/png'})]};
  saveBanner(state,v,makeForm('banner',{_files:files}),'Published');assert.match(homepageHero(state),/<video/);assert.match(homepageHero(state),/muted loop playsinline/);
  saveBanner(state,{...v,heading:'Draft title'},makeForm('banner',{}),'Draft');assert.match(homepageHero(state),/Published title/);assert.doesNotMatch(homepageHero(state),/Draft title/);
});
await test('Customer assistance follows Bengali, Banglish and English in the latest message',()=>{
  assert.equal(detectChatLanguage('amar ekta juta lagbe'),'bn-Latn');assert.equal(detectChatLanguage('আমার জুতা লাগবে'),'bn');
  let c=receiveCustomerMessage(state,'আমার জুতা লাগবে');assert.match(c.messages.at(-1).text,/পণ্য/);
  receiveCustomerMessage(state,'ami juta kinbo');assert.match(c.messages.at(-1).text,/Nicher product/);
  receiveCustomerMessage(state,'please show shoes');assert.match(c.messages.at(-1).text,/Choose a piece/);
  receiveCustomerMessage(state,'payment kivabe korbo');assert.match(c.messages.at(-1).text,/Ami checkout/);assert.equal(state.orders.length,initial.orders.length);
});
await test('Language switching translates controls without changing input values',async()=>{
  assert.equal(localizeMarkup('<button>Buy it now</button><input value="Bag">',{language:'bn'}),'<button>এখনই কিনুন</button><input value="Bag">');
  await click('set-language',{value:'bn'});assert.match(app.innerHTML,/সব পণ্য/);assert.match(app.innerHTML,/এখনই কিনুন/);
  await click('set-language',{value:'en'});assert.match(app.innerHTML,/Buy it now/);
});
await test('Owner AI is inaccessible to staff, including staff with a Super Admin role, and has no demo unlock',async()=>{
  assert.equal(isOwnerPreview(state),true);state.previewStaff='USR-002';state.previewRole='Super Admin';assert.equal(can(state,'owner-ai'),false);
  location.hash='#/admin/owner-ai';render();assert.doesNotMatch(app.innerHTML,/data-form="owner-ai-verify"/);
  state.previewStaff=null;render();assert.match(app.innerHTML,/Financial operations are permanently blocked/);
  assert.match(await submit('owner-ai-verify',{code:'123456'}),/No demo code can unlock/);
});

console.log(`\n${passed} checks passed. Browser visual/interaction QA and supported-context WebMCP validation remain unperformed.`);
