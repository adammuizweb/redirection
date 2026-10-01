(() => {
  'use strict';

  const controls = document.querySelector('[data-jyr-column-controls]');
  const table = document.querySelector('[data-jyr-404-table]');
  if (!(controls instanceof HTMLDetailsElement) || !(table instanceof HTMLTableElement)) return;

  const toggles = Array.from(controls.querySelectorAll('[data-jyr-column-toggle]'));
  const reset = controls.querySelector('[data-jyr-columns-reset]');
  const columns = new Set(toggles.map((toggle) => toggle.value));
  const storageKey = 'jyr.redirection.404.hidden-columns.v1';
  let hiddenColumns = new Set();

  try {
    const stored = JSON.parse(localStorage.getItem(storageKey) || '[]');
    if (Array.isArray(stored)) hiddenColumns = new Set(stored.filter((column) => columns.has(column)));
  } catch (_error) {
    hiddenColumns = new Set();
  }

  const applyColumns = () => {
    toggles.forEach((toggle) => {
      toggle.checked = !hiddenColumns.has(toggle.value);
    });
    table.querySelectorAll('[data-jyr-column]').forEach((cell) => {
      cell.classList.toggle('jyr-column-hidden', hiddenColumns.has(cell.dataset.jyrColumn));
    });
  };

  const storeColumns = () => {
    try {
      localStorage.setItem(storageKey, JSON.stringify(Array.from(hiddenColumns)));
    } catch (_error) {
      // Column visibility still works for the current page when storage is unavailable.
    }
  };

  toggles.forEach((toggle) => {
    toggle.addEventListener('change', () => {
      if (toggle.checked) hiddenColumns.delete(toggle.value);
      else hiddenColumns.add(toggle.value);
      applyColumns();
      storeColumns();
    });
  });

  reset?.addEventListener('click', () => {
    hiddenColumns.clear();
    applyColumns();
    try {
      localStorage.removeItem(storageKey);
    } catch (_error) {
      // Ignore storage failures after restoring the visible table.
    }
  });

  document.addEventListener('pointerdown', (event) => {
    if (controls.open && event.target instanceof Node && !controls.contains(event.target)) controls.open = false;
  });
  controls.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      controls.open = false;
      controls.querySelector('summary')?.focus();
    }
  });

  applyColumns();
  controls.hidden = false;
})();
