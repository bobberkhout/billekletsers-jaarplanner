(() => {
  let rowCounter = Date.now();
  let dirty = false;

  const forms = [...document.querySelectorAll('.bkp-front-editor-form')];
  forms.forEach((form) => {
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('change', () => { dirty = true; });
    form.addEventListener('submit', () => {
      updateProjectOrder(form);
      dirty = false;
    });
  });

  window.addEventListener('beforeunload', (event) => {
    if (!dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });

  const updateProjectOrder = (scope = document) => {
    const body = scope.querySelector('.bkp-front-grid-body');
    if (!body || !scope.classList?.contains('bkp-front-project-form')) return;
    [...body.querySelectorAll('.bkp-front-grid-row')].forEach((row, index) => {
      const input = row.querySelector('.bkp-front-sort-order');
      if (input) input.value = String(index);
    });
  };

  document.querySelectorAll('.bkp-front-add-row').forEach((button) => {
    button.addEventListener('click', () => {
      const form = button.closest('form');
      const template = form?.querySelector('.bkp-front-row-template');
      const body = form?.querySelector('.bkp-front-grid-body');
      if (!template || !body) return;
      const html = template.innerHTML.split('__INDEX__').join(String(rowCounter++));
      const holder = document.createElement('div');
      holder.innerHTML = html;
      const row = holder.querySelector('tr');
      if (!row) return;
      body.appendChild(row);
      row.querySelector('input:not([type="hidden"]), textarea, select')?.focus();
      updateProjectOrder(form);
      dirty = true;
    });
  });

  document.addEventListener('change', (event) => {
    const checkbox = event.target.closest('.bkp-front-delete-row');
    if (!checkbox) return;
    const row = checkbox.closest('tr');
    if (!row) return;
    const id = row.querySelector('input[name$="[id]"]')?.value || '';
    if (checkbox.checked && (!id || id === '0')) {
      row.remove();
    } else {
      row.classList.toggle('is-deleted', checkbox.checked);
      row.querySelectorAll('input:not(.bkp-front-delete-row):not([type="hidden"]), textarea, select').forEach((field) => {
        field.disabled = checkbox.checked;
      });
      checkbox.disabled = false;
    }
    updateProjectOrder(checkbox.closest('form'));
  });

  document.querySelectorAll('.bkp-front-grid-search').forEach((search) => {
    search.addEventListener('input', () => {
      const form = search.closest('form');
      const query = search.value.trim().toLocaleLowerCase('nl');
      form?.querySelectorAll('.bkp-front-grid-row').forEach((row) => {
        const text = (row.dataset.search || row.textContent || '').toLocaleLowerCase('nl');
        row.hidden = Boolean(query && !text.includes(query));
      });
    });
  });

  document.addEventListener('click', (event) => {
    const up = event.target.closest('.bkp-front-move-up');
    const down = event.target.closest('.bkp-front-move-down');
    if (!up && !down) return;
    const row = (up || down).closest('tr');
    const body = row?.parentElement;
    if (!row || !body) return;
    if (up && row.previousElementSibling) body.insertBefore(row, row.previousElementSibling);
    if (down && row.nextElementSibling) body.insertBefore(row.nextElementSibling, row);
    updateProjectOrder((up || down).closest('form'));
    dirty = true;
  });

  const committeeChoice = document.querySelector('[data-bkp-front-committee-choice]');
  const newCommittee = document.querySelector('[data-bkp-front-new-committee]');
  const updateCommitteeChoice = () => {
    if (!committeeChoice || !newCommittee) return;
    const show = committeeChoice.value === '__new__';
    newCommittee.hidden = !show;
    const input = newCommittee.querySelector('input');
    if (input) input.required = show;
  };
  committeeChoice?.addEventListener('change', updateCommitteeChoice);
  updateCommitteeChoice();

  document.querySelectorAll('.bkp-front-project-form').forEach((form) => updateProjectOrder(form));
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
  document.addEventListener('click', (event) => {
    if (event.target.closest('.bkp-user-picker')) return;
    document.querySelectorAll('.bkp-user-picker[open]').forEach((picker) => picker.removeAttribute('open'));
  });
  document.querySelectorAll('.bkp-user-picker').forEach(updateUserPicker);
})();
