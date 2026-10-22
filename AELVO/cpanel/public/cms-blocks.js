/* Progressive enhancement for the existing server-rendered CMS block editor. */
(() => {
  'use strict';
  const root = document.querySelector('#cms-block-editor');
  const add = document.querySelector('#cms-add-block');
  if (!root || !add) return;
  const limit = Math.min(24, Number(root.dataset.limit) || 24);
  const rows = () => [...root.querySelectorAll('[data-cms-block]')];
  const update = () => {
    rows().forEach((row, index) => {
      row.querySelector('[data-cms-order]').textContent = String(index + 1);
      for (const field of row.querySelectorAll('input[name],select[name],textarea[name]')) {
        field.name = field.name.replace(/^blocks\[\d+\]/, `blocks[${index}]`);
      }
    });
    add.disabled = rows().length >= limit;
  };
  root.addEventListener('click', (event) => {
    const action = event.target.closest('[data-cms-remove],[data-cms-move]');
    if (!action) return;
    const row = action.closest('[data-cms-block]');
    if (!row) return;
    if (action.hasAttribute('data-cms-remove')) {
      if (rows().length > 1) row.remove();
      else for (const field of row.querySelectorAll('input,textarea,select')) field.value = '';
    }
    else if (action.dataset.cmsMove === 'up' && row.previousElementSibling) root.insertBefore(row, row.previousElementSibling);
    else if (action.dataset.cmsMove === 'down' && row.nextElementSibling) root.insertBefore(row.nextElementSibling, row);
    update();
  });
  add.addEventListener('click', () => {
    if (rows().length >= limit) return;
    const row = rows()[0]?.cloneNode(true);
    if (!row) return;
    for (const field of row.querySelectorAll('input,textarea,select')) field.value = '';
    root.append(row);
    update();
    row.querySelector('select')?.focus();
  });
  update();
})();
