'use strict';
// <details> is the no-script fallback. JS only improves dismissal/focus behavior.
const adminMore = document.querySelector('.admin-mobile-more');
if (adminMore) {
  const trigger = adminMore.querySelector('summary');
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && adminMore.open) {
      adminMore.open = false;
      trigger?.focus();
    }
  });
  document.addEventListener('pointerdown', event => {
    if (adminMore.open && !adminMore.contains(event.target)) adminMore.open = false;
  });
}

// In-place module suggestions use only links rendered after server-side RBAC.
const moduleFinder = document.querySelector('[data-admin-finder]');
if (moduleFinder) {
  const input = moduleFinder.querySelector('[data-admin-module-query]');
  const links = Array.from(moduleFinder.querySelectorAll('[data-admin-module-link]'));
  const empty = moduleFinder.querySelector('[data-admin-module-empty]');
  const filter = () => {
    const query = input.value.trim().toLocaleLowerCase();
    let count = 0;
    for (const link of links) {
      link.hidden = !link.textContent.toLocaleLowerCase().includes(query);
      if (!link.hidden) count++;
    }
    empty.hidden = count !== 0;
  };
  input?.addEventListener('input', filter);
  moduleFinder.addEventListener('toggle', () => {
    if (moduleFinder.open) { filter(); input?.focus(); }
    else { input.value = ''; filter(); }
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && moduleFinder.open) {
      moduleFinder.open = false;
      moduleFinder.querySelector('summary')?.focus();
    }
  });
  document.addEventListener('pointerdown', event => {
    if (moduleFinder.open && !moduleFinder.contains(event.target)) moduleFinder.open = false;
  });
}
