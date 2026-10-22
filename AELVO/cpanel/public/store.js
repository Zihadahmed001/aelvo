'use strict';
const variants = document.querySelector('#variant-select');
const gallery = document.querySelector('[data-product-gallery]');
if (gallery) {
  const image=gallery.querySelector('#product-photo');
  const thumbs=Array.from(gallery.querySelectorAll('[data-gallery-photo]'));
  const selectPhoto=(button)=>{
    if (!button || !image) return;
    const source=button.dataset.src;
    if (!source) return;
    image.src=source;
    image.alt=button.dataset.alt||'';
    for(const thumb of thumbs){
      const selected=thumb===button;
      thumb.setAttribute('aria-pressed',String(selected));
      thumb.classList.toggle('active',selected);
    }
  };
  thumbs.forEach(button=>button.addEventListener('click',()=>selectPhoto(button)));
  if(thumbs.length)selectPhoto(thumbs[0]);
  variants?.addEventListener('change',()=>{
    const colour=variants.selectedOptions[0]?.dataset.colour;
    const matching=colour?thumbs.filter(button=>button.dataset.colour===colour):[];
    thumbs.forEach(button=>{button.hidden=Boolean(colour)&&button.dataset.colour!==colour&&button.dataset.colour!=='';});
    if(matching.length)selectPhoto(matching[0]);
    else if(thumbs.length)selectPhoto(thumbs.find(button=>!button.hidden)||thumbs[0]);
    else {
      const photo=variants.selectedOptions[0]?.dataset.photo;
      if(photo&&image)image.src=photo;
    }
  });
}
// Progressive enhancement of the actual server-side variant select.
// Colour/size buttons never submit invented variant IDs or prices.
const picker=document.querySelector('[data-variant-picker]');
if(picker && variants){
 const options=Array.from(variants.options).filter(opt=>opt.value!=='' && !opt.disabled);
 const colours=Array.from(picker.querySelectorAll('[data-pick-colour]'));
 const sizes=Array.from(picker.querySelectorAll('[data-pick-size]'));
 const qty=picker.closest('form')?.querySelector('input[name="qty"]');
 const priceLabel=picker.closest('.detail-copy')?.querySelector('[data-product-price]');
 const regularLabel=picker.closest('.detail-copy')?.querySelector('[data-product-regular]');
 if(options.length){
  const choose=(colour,size)=>{
   const matches=options.filter(opt=>opt.dataset.colour===colour);
   const chosen=matches.find(opt=>opt.dataset.size===size)||matches[0]||options.find(opt=>opt.dataset.size===size)||options[0];
   if(!chosen)return;
   variants.value=chosen.value;
   const pickedColour=chosen.dataset.colour,pickedSize=chosen.dataset.size;
   for(const button of colours){
    const active=button.dataset.pickColour===pickedColour;
    button.disabled=!options.some(opt=>opt.dataset.colour===button.dataset.pickColour);
    button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));
   }
   for(const button of sizes){
    const active=button.dataset.pickSize===pickedSize;
    button.disabled=!options.some(opt=>opt.dataset.colour===pickedColour && opt.dataset.size===button.dataset.pickSize);
    button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));
   }
   const available=Math.max(0,Number(chosen.dataset.available)||0);
   const maxQty=Math.min(100,available);
   if(qty){qty.max=String(maxQty);if(Number(qty.value)>maxQty)qty.value=String(maxQty);}
   picker.querySelector('[data-selected-colour]').textContent=pickedColour;
   picker.querySelector('[data-selected-size]').textContent=pickedSize;
   picker.querySelector('[data-variant-available]').textContent=String(available);
   const extra=picker.querySelector('[data-variant-extra]');
   if(extra){const attrs=[chosen.dataset.material,chosen.dataset.style].filter(Boolean);extra.textContent=attrs.join(' · ');extra.hidden=attrs.length===0;}
   if(priceLabel){const minor=Number(chosen.dataset.priceMinor);if(Number.isSafeInteger(minor)&&minor>=0)priceLabel.textContent='৳'+(minor/100).toLocaleString(document.documentElement.lang==='bn'?'bn-BD':'en-BD',{minimumFractionDigits:2,maximumFractionDigits:2});}
   if(regularLabel){const normal=Number(chosen.dataset.regularPriceMinor),selling=Number(chosen.dataset.priceMinor);
    const discounted=Number.isSafeInteger(normal)&&Number.isSafeInteger(selling)&&normal>selling&&selling>0;
    regularLabel.hidden=!discounted;
    regularLabel.textContent=discounted?((document.documentElement.lang==='bn'?'Regular price: ৳':'Regular: ৳')+(normal/100).toLocaleString(document.documentElement.lang==='bn'?'bn-BD':'en-BD',{minimumFractionDigits:2,maximumFractionDigits:2})):'';
   }
   variants.dispatchEvent(new Event('change',{bubbles:true}));
  };
  colours.forEach(button=>button.addEventListener('click',()=>{
   const selected=variants.selectedOptions[0];choose(button.dataset.pickColour,selected?.dataset.size||'');
  }));
  sizes.forEach(button=>button.addEventListener('click',()=>{
   const selected=variants.selectedOptions[0];choose(selected?.dataset.colour||'',button.dataset.pickSize);
  }));
  picker.hidden=false;
  picker.closest('form')?.classList.add('variant-picker-ready');
  variants.tabIndex=-1;
  variants.setAttribute('aria-hidden','true');
  choose(options[0].dataset.colour,options[0].dataset.size);
 }
}

document.querySelector('[data-dismiss-bag]')?.addEventListener('click',()=>{
  document.querySelector('[data-bag-confirmation]')?.remove();
});

const heroVideo = document.querySelector('[data-hero-video]');
const videoButton = document.querySelector('[data-video-toggle]');
if (heroVideo && videoButton) {
  videoButton.hidden = false;
  const label = () => {videoButton.textContent = document.documentElement.lang === 'bn' ? (heroVideo.paused ? 'ভিডিও চালান' : 'ভিডিও থামান') : (heroVideo.paused ? 'Play video' : 'Pause video');};
  videoButton.addEventListener('click', () => {if(heroVideo.paused)heroVideo.play().catch(label);else heroVideo.pause();});
  heroVideo.addEventListener('play', label);heroVideo.addEventListener('pause', label);
  if(!matchMedia('(prefers-reduced-motion: reduce)').matches && !matchMedia('(max-width: 700px)').matches)heroVideo.play().catch(label);
  document.addEventListener('visibilitychange',()=>{if(document.hidden)heroVideo.pause();});
}
const welcome = document.querySelector('[data-welcome-popup]');
if(welcome && typeof welcome.showModal === 'function') {
  const key = 'aelvo-welcome-' + welcome.dataset.revision;
  let seen = false;try{seen=sessionStorage.getItem(key)==='1';}catch{}
  if(!seen)setTimeout(()=>{if(!document.hidden){welcome.showModal();try{sessionStorage.setItem(key,'1');}catch{}}},Number(welcome.dataset.delay)*1000);
}

const headerSearch=document.querySelector('[data-header-search]');
const searchInput=document.querySelector('#header-query');
const suggestions=document.querySelector('#search-suggestions');
const searchStatus=document.querySelector('[data-search-status]');
if(headerSearch&&searchInput&&suggestions&&searchStatus){
 let timer,controller,sequence=0;
 const bn=document.documentElement.lang==='bn';
 const clearSearch=()=>{sequence++;clearTimeout(timer);controller?.abort();suggestions.replaceChildren();searchStatus.textContent='';};
 searchInput.addEventListener('input',()=>{
  clearSearch();const query=searchInput.value.trim();if(query.length<2)return;const ticket=sequence;
  timer=setTimeout(async()=>{
   controller=new AbortController();searchStatus.textContent=bn?'খোঁজা হচ্ছে…':'Searching…';
   try{
    const response=await fetch('search.php?q='+encodeURIComponent(query),{signal:controller.signal,headers:{Accept:'application/json'}});
    if(!response.ok)throw new Error('Search unavailable');const data=await response.json();if(ticket!==sequence)return;
    suggestions.replaceChildren();
    for(const product of data.products){
     const link=document.createElement('a');link.className='search-result';link.href=product.url;
     const name=document.createElement('span');name.textContent=bn&&product.name_bn?product.name_bn:product.name;
     const price=document.createElement('strong');price.textContent='৳'+(product.price_minor/100).toLocaleString(bn?'bn-BD':'en-BD',{minimumFractionDigits:2});
     link.append(name,price);suggestions.append(link);
    }
    searchStatus.textContent=data.products.length?(bn?'মিল পাওয়া গেছে। সব ফলাফল দেখতে সার্চ করুন।':'Matches found. Submit to see all results.'):(bn?'কোনো পণ্য পাওয়া যায়নি।':'No matching products.');
   }catch(error){if(error.name!=='AbortError'&&ticket===sequence)searchStatus.textContent=bn?'সাজেশন পাওয়া যাচ্ছে না। সার্চ বাটন ব্যবহার করুন।':'Suggestions unavailable. Use the search button.';}
  },250);
 });
 headerSearch.addEventListener('toggle',()=>{if(headerSearch.open)searchInput.focus();else clearSearch();});
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&headerSearch.open){headerSearch.open=false;headerSearch.querySelector('summary').focus();}});
 document.addEventListener('click',event=>{if(headerSearch.open&&!headerSearch.contains(event.target))headerSearch.open=false;});
}
const mobileNav=document.querySelector('.mobile-nav');
if(mobileNav){
 try{const current=Number(mobileNav.dataset.active);const previous=Number(sessionStorage.getItem('aelvo-store-tab'));if(Number.isInteger(previous)&&previous>=0&&previous<5)mobileNav.classList.add('mobile-from-'+previous);sessionStorage.setItem('aelvo-store-tab',String(current));}catch{}
}
