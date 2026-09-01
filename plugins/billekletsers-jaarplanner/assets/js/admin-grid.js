(() => {
  let rowCounter = Date.now();
  let dirty = false;

  const updateCount = (scope) => {
    const count = scope.querySelectorAll('.bkp-grid-body .bkp-grid-row:not(.is-deleted)').length;
    const label = scope.querySelector('.bkp-grid-count');
    if (label) label.textContent = `${count} regels`;
  };

  document.querySelectorAll('.bkp-add-row').forEach((button) => {
    button.addEventListener('click', () => {
      const form = button.closest('form') || document;
      const panel = button.closest('.bkp-admin-panel');
      const scope = panel || form;
      const template = scope.querySelector('.bkp-row-template') || form.querySelector('.bkp-row-template');
      const body = scope.querySelector('.bkp-grid-body') || form.querySelector('.bkp-grid-body');
      if (!template || !body) return;
      const html = template.innerHTML.split('__INDEX__').join(String(rowCounter++));
      const holder = document.createElement('tbody');
      holder.innerHTML = html.trim();
      const row = holder.firstElementChild;
      if (!row) return;
      body.appendChild(row);
      row.querySelector('input:not([type="hidden"]), textarea, select')?.focus();
      dirty = true;
      updateCount(form);
    });
  });

  document.addEventListener('change', (event) => {
    const checkbox = event.target.closest('.bkp-delete-row');
    if (!checkbox) {
      if (event.target.closest('.bkp-grid-form')) dirty = true;
      return;
    }
    const row = checkbox.closest('.bkp-grid-row');
    if (!row) return;
    const id = row.querySelector('input[name$="[id]"]')?.value || '';
    if (checkbox.checked && (!id || id === '0')) {
      row.remove();
    } else {
      row.classList.toggle('is-deleted', checkbox.checked);
      row.querySelectorAll('input:not(.bkp-delete-row), textarea, select').forEach((field) => {
        field.disabled = checkbox.checked;
      });
      const hiddenId = row.querySelector('input[name$="[id]"]');
      if (hiddenId) hiddenId.disabled = false;
      checkbox.disabled = false;
    }
    dirty = true;
    updateCount(checkbox.closest('form') || document);
  });

  document.querySelectorAll('.bkp-grid-search').forEach((search) => {
    search.addEventListener('input', () => {
      const scope = search.closest('.bkp-admin-wrap') || document;
      const query = search.value.trim().toLocaleLowerCase('nl');
      scope.querySelectorAll('.bkp-grid-body .bkp-grid-row').forEach((row) => {
        const text = (row.dataset.search || row.textContent || '').toLocaleLowerCase('nl');
        row.hidden = Boolean(query && !text.includes(query));
      });
    });
  });

  document.querySelectorAll('.bkp-grid-form').forEach((form) => {
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
  });

  window.addEventListener('beforeunload', (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });
})();

(() => {
  const choice = document.querySelector('[data-bkp-committee-choice]');
  const newCommitteeField = document.querySelector('[data-bkp-new-committee]');
  const newCommitteeInput = newCommitteeField?.querySelector('input[name="new_committee"]');

  const updateCommitteeChoice = () => {
    if (!choice || !newCommitteeField || !newCommitteeInput) return;
    const isNew = choice.value === '__new__';
    newCommitteeField.hidden = !isNew;
    newCommitteeInput.required = isNew;
    if (isNew) newCommitteeInput.focus();
  };

  choice?.addEventListener('change', updateCommitteeChoice);
  updateCommitteeChoice();

  document.addEventListener('change', (event) => {
    const checkbox = event.target.closest('.bkp-committee-delete-member');
    if (!checkbox) return;
    const row = checkbox.closest('.bkp-simple-member-row');
    if (!row) return;
    row.classList.toggle('is-deleted', checkbox.checked);
    const nameInput = row.querySelector('input[type="text"]');
    if (nameInput) nameInput.disabled = checkbox.checked;
  });

  document.querySelectorAll('.bkp-confirm-delete-committee').forEach((button) => {
    button.addEventListener('click', (event) => {
      const name = button.value || 'deze commissie';
      if (!window.confirm(`Weet je zeker dat je ${name} met alle personen wilt verwijderen?`)) {
        event.preventDefault();
      }
    });
  });

  const search = document.querySelector('[data-bkp-committee-search]');
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase('nl');
    document.querySelectorAll('[data-bkp-committee-panel]').forEach((panel) => {
      const text = (panel.dataset.search || panel.textContent || '').toLocaleLowerCase('nl');
      panel.hidden = Boolean(query && !text.includes(query));
    });
  });
})();


/* Sorteerbare projectplanning in versie 3.3. */
(() => {
  const form = document.querySelector('.bkp-project-grid-form');
  if (!form) return;

  const body = form.querySelector('.bkp-grid-body');
  if (!body) return;

  const rows = () => Array.from(body.querySelectorAll('.bkp-grid-row'));

  const updateProjectOrder = (markDirty = true) => {
    rows().forEach((row, index) => {
      const orderInput = row.querySelector('.bkp-sort-order-input');
      const number = row.querySelector('.bkp-row-number span');
      if (orderInput) orderInput.value = String(index);
      if (number) number.textContent = String(index + 1);
    });
    if (markDirty) {
      const firstOrder = body.querySelector('.bkp-sort-order-input');
      firstOrder?.dispatchEvent(new Event('input', { bubbles: true }));
    }
  };

  const fieldValue = (row, key) => {
    const field = row.querySelector(`[name$="[${key}]"]`);
    return (field?.value || '').trim().toLocaleLowerCase('nl');
  };

  const firstActiveMonth = (row) => {
    const monthInputs = Array.from(row.querySelectorAll('input[name*="[months]"]'));
    const index = monthInputs.findIndex((input) => input.checked);
    return index < 0 ? Number.MAX_SAFE_INTEGER : index;
  };

  const compareText = (a, b) => a.localeCompare(b, 'nl', { sensitivity: 'base', numeric: true });

  document.querySelector('.bkp-apply-project-sort')?.addEventListener('click', () => {
    const mode = document.querySelector('.bkp-project-sort')?.value || 'custom';
    if (mode === 'custom') return;

    const sorted = rows().sort((a, b) => {
      if (mode === 'month') {
        const result = firstActiveMonth(a) - firstActiveMonth(b);
        if (result !== 0) return result;
        return compareText(fieldValue(a, 'title'), fieldValue(b, 'title'));
      }
      const key = mode === 'responsible' ? 'responsible' : mode === 'status' ? 'status' : 'title';
      const result = compareText(fieldValue(a, key), fieldValue(b, key));
      return result !== 0 ? result : compareText(fieldValue(a, 'title'), fieldValue(b, 'title'));
    });

    sorted.forEach((row) => body.appendChild(row));
    updateProjectOrder();
    document.querySelector('.bkp-project-sort').value = 'custom';
  });

  document.addEventListener('click', (event) => {
    const up = event.target.closest('.bkp-move-up');
    const down = event.target.closest('.bkp-move-down');
    const button = up || down;
    if (!button || !form.contains(button)) return;

    const row = button.closest('.bkp-grid-row');
    if (!row) return;
    if (up && row.previousElementSibling) body.insertBefore(row, row.previousElementSibling);
    if (down && row.nextElementSibling) body.insertBefore(row.nextElementSibling, row);
    updateProjectOrder();
  });

  if (window.jQuery?.fn?.sortable) {
    window.jQuery(body).sortable({
      axis: 'y',
      handle: '.bkp-drag-handle',
      items: '> .bkp-grid-row:not(.is-deleted)',
      placeholder: 'bkp-sort-placeholder',
      forcePlaceholderSize: true,
      helper(event, row) {
        row.children().each(function () {
          window.jQuery(this).width(window.jQuery(this).width());
        });
        return row;
      },
      start(event, ui) {
        ui.placeholder.html(`<td colspan="${ui.item.children().length}"></td>`);
        ui.item.addClass('is-sorting');
      },
      stop(event, ui) {
        ui.item.removeClass('is-sorting');
        updateProjectOrder();
      },
    });
  }

  document.querySelector('.bkp-add-row')?.addEventListener('click', () => {
    window.setTimeout(() => updateProjectOrder(), 0);
  });

  form.addEventListener('submit', () => updateProjectOrder(false));
  updateProjectOrder(false);
})();

/* Samenvatting van gekoppelde gebruikers bijwerken - versie 3.6.3. */
(() => {
  const updateUserPicker = (picker) => {
    if (!picker) return;
    const summary = picker.querySelector('[data-bkp-user-summary]');
    if (!summary) return;
    const names = [...picker.querySelectorAll('input[type="checkbox"]:checked')]
      .map((input) => input.closest('label')?.querySelector('span')?.textContent?.trim())
      .filter(Boolean);
    summary.textContent = names.length === 0
      ? 'Geen gebruiker gekoppeld'
      : names.length <= 2 ? names.join(', ') : `${names.length} gebruikers gekoppeld`;
  };
  document.addEventListener('change', (event) => {
    const checkbox = event.target.closest('.bkp-user-picker input[type="checkbox"]');
    if (!checkbox) return;
    updateUserPicker(checkbox.closest('.bkp-user-picker'));
  });
  document.querySelectorAll('.bkp-user-picker').forEach(updateUserPicker);
})();
