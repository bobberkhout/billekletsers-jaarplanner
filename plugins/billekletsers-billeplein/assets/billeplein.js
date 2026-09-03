(() => {
  'use strict';
  const ready = (fn) => document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn, {once:true}) : fn();
  ready(() => {
    const typeSelect = document.querySelector('#bkb-type');
    const pollFields = document.querySelector('#bkb-poll-fields');
    const compose = document.querySelector('#bkb-compose');
    const syncPoll = () => { if (pollFields && typeSelect) pollFields.hidden = typeSelect.value !== 'poll'; };
    typeSelect?.addEventListener('change', syncPoll); syncPoll();

    // Poll: dynamische lijst met keuzes i.p.v. één tekstvak met regels
    const pollList = document.querySelector('#bkb-poll-options-list');
    const pollAddBtn = document.querySelector('#bkb-poll-option-add');
    const MIN_POLL_OPTIONS = 2;
    const renumberPollOptions = () => {
      if (!pollList) return;
      const rows = pollList.querySelectorAll('.bkb-poll-option-row');
      rows.forEach((row, i) => {
        const input = row.querySelector('input');
        if (input) input.placeholder = `Keuze ${i + 1}`;
        const removeBtn = row.querySelector('.bkb-poll-option-remove');
        if (removeBtn) removeBtn.hidden = rows.length <= MIN_POLL_OPTIONS;
      });
    };
    pollAddBtn?.addEventListener('click', () => {
      if (!pollList) return;
      const row = document.createElement('div');
      row.className = 'bkb-poll-option-row';
      row.innerHTML = '<input class="bkp-field" type="text" name="bkb_poll_option[]" maxlength="200"><button type="button" class="bkb-poll-option-remove" aria-label="Verwijder deze keuze">&times;</button>';
      pollList.appendChild(row);
      renumberPollOptions();
      row.querySelector('input')?.focus();
    });
    pollList?.addEventListener('click', (e) => {
      const btn = e.target.closest('.bkb-poll-option-remove');
      if (!btn) return;
      if (pollList.querySelectorAll('.bkb-poll-option-row').length <= MIN_POLL_OPTIONS) return;
      btn.closest('.bkb-poll-option-row')?.remove();
      renumberPollOptions();
    });
    renumberPollOptions();

    document.querySelectorAll('[data-bkb-compose]').forEach((button) => {
      button.addEventListener('click', () => {
        const type = button.dataset.bkbCompose || 'question';
        window.setTimeout(() => {
          if (compose) compose.open = true;
          if (typeSelect) { typeSelect.value = type; typeSelect.dispatchEvent(new Event('change')); }
          compose?.scrollIntoView({behavior:'smooth',block:'start'});
          compose?.querySelector('input[name="bkb_title"]')?.focus({preventScroll:true});
        }, 80);
      });
    });

    const filterType=document.querySelector('#bkb-filter-type');
    const filterProject=document.querySelector('#bkb-filter-project');
    const filterSearch=document.querySelector('#bkb-filter-search');
    const posts=Array.from(document.querySelectorAll('[data-bkb-post]'));
    const empty=document.querySelector('#bkb-no-results');
    const normalize=(v)=>String(v||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
    const apply=()=>{
      let shown=0; const q=normalize(filterSearch?.value); const t=filterType?.value||''; const p=filterProject?.value||'';
      posts.forEach(post=>{ const okT=!t||post.dataset.type===t; const okP=!p||post.dataset.project===p; const okQ=!q||normalize(post.dataset.search).includes(q); const show=okT&&okP&&okQ; post.classList.toggle('bkb-is-filtered',!show); if(show) shown++; });
      if(empty) empty.hidden=shown!==0;
    };
    [filterType,filterProject].forEach(el=>el?.addEventListener('change',apply)); filterSearch?.addEventListener('input',apply);

    const params=new URLSearchParams(window.location.search); const focus=params.get('bkb_post');
    if(focus && window.location.hash==='#billeplein') window.setTimeout(()=>document.querySelector(`#bkb-post-${CSS.escape(focus)}`)?.scrollIntoView({behavior:'smooth',block:'center'}),220);
  });
})();
