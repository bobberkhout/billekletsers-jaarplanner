(() => {
  'use strict';

  const ready = (callback) => {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
    } else {
      callback();
    }
  };

  ready(() => {
    const tabs = Array.from(document.querySelectorAll('[data-tab]'));
    const panels = Array.from(document.querySelectorAll('[data-panel]'));

    /*
     * Mobiele menu's worden als bodemlade rechtstreeks onder <body> gezet.
     * Daarmee kunnen sticky balken, overflow en backdrop-filter de submenu's
     * niet meer afknippen of onder de pagina-inhoud laten verdwijnen.
     */
    const mobileMenuMedia = window.matchMedia('(max-width: 760px)');
    const mobileMenuEntries = Array.from(document.querySelectorAll('.bkp-nav-group, .bkp-action-menu'))
      .map((details) => ({
        details,
        summary: Array.from(details.children).find((child) => child.tagName === 'SUMMARY') || null,
        panel: Array.from(details.children).find((child) => child.classList?.contains('bkp-nav-submenu') || child.classList?.contains('bkp-action-menu-panel')) || null,
        placeholder: null,
        header: null,
        previousRole: null,
        previousAriaModal: null,
      }))
      .filter((entry) => entry.summary && entry.panel);
    let activeMobileMenu = null;
    let mobileMenuBackdrop = null;

    const restoreMobileMenu = (restoreFocus = false) => {
      const entry = activeMobileMenu;
      if (!entry) return;

      entry.panel.classList.remove('is-visible', 'bkp-mobile-menu-sheet');
      entry.header?.remove();
      entry.header = null;

      if (entry.previousRole === null) entry.panel.removeAttribute('role');
      else entry.panel.setAttribute('role', entry.previousRole);
      if (entry.previousAriaModal === null) entry.panel.removeAttribute('aria-modal');
      else entry.panel.setAttribute('aria-modal', entry.previousAriaModal);

      if (entry.placeholder?.parentNode) {
        entry.placeholder.parentNode.insertBefore(entry.panel, entry.placeholder);
        entry.placeholder.remove();
      }
      entry.placeholder = null;

      mobileMenuBackdrop?.remove();
      mobileMenuBackdrop = null;
      document.body.classList.remove('bkp-mobile-menu-open');
      activeMobileMenu = null;

      if (restoreFocus) entry.summary.focus({ preventScroll: true });
    };

    const closeMobileMenu = (restoreFocus = false) => {
      if (!activeMobileMenu) return;
      const entry = activeMobileMenu;
      entry.details.removeAttribute('open');
      restoreMobileMenu(restoreFocus);
    };

    const openMobileMenu = (entry) => {
      if (!mobileMenuMedia.matches || !entry?.panel || activeMobileMenu === entry) return;
      if (activeMobileMenu) {
        activeMobileMenu.details.removeAttribute('open');
        restoreMobileMenu(false);
      }

      entry.placeholder = document.createComment('bkp-mobile-menu-placeholder');
      entry.panel.parentNode.insertBefore(entry.placeholder, entry.panel);
      entry.previousRole = entry.panel.getAttribute('role');
      entry.previousAriaModal = entry.panel.getAttribute('aria-modal');

      const header = document.createElement('div');
      header.className = 'bkp-mobile-menu-sheet-head';
      const title = document.createElement('strong');
      title.className = 'bkp-mobile-menu-sheet-title';
      title.textContent = entry.summary.textContent.trim() || 'Menu';
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'bkp-mobile-menu-sheet-close';
      close.setAttribute('aria-label', 'Menu sluiten');
      close.textContent = '×';
      close.addEventListener('click', () => closeMobileMenu(true));
      header.append(title, close);
      entry.header = header;

      entry.panel.prepend(header);
      entry.panel.classList.add('bkp-mobile-menu-sheet');
      entry.panel.setAttribute('role', 'dialog');
      entry.panel.setAttribute('aria-modal', 'true');
      document.body.append(entry.panel);

      mobileMenuBackdrop = document.createElement('button');
      mobileMenuBackdrop.type = 'button';
      mobileMenuBackdrop.className = 'bkp-mobile-menu-backdrop';
      mobileMenuBackdrop.setAttribute('aria-label', 'Menu sluiten');
      mobileMenuBackdrop.addEventListener('click', () => closeMobileMenu(true));
      document.body.append(mobileMenuBackdrop);
      document.body.classList.add('bkp-mobile-menu-open');
      activeMobileMenu = entry;

      requestAnimationFrame(() => {
        mobileMenuBackdrop?.classList.add('is-visible');
        entry.panel.classList.add('is-visible');
        const firstItem = entry.panel.querySelector('a,button:not(.bkp-mobile-menu-sheet-close)');
        firstItem?.focus({ preventScroll: true });
      });
    };

    mobileMenuEntries.forEach((entry) => {
      entry.details.addEventListener('toggle', () => {
        if (entry.details.open && mobileMenuMedia.matches) openMobileMenu(entry);
        else if (!entry.details.open && activeMobileMenu === entry) restoreMobileMenu(false);
      });
    });

    const handleMobileMenuBreakpoint = () => {
      if (!mobileMenuMedia.matches && activeMobileMenu) {
        activeMobileMenu.details.removeAttribute('open');
        restoreMobileMenu(false);
      }
    };
    if (typeof mobileMenuMedia.addEventListener === 'function') {
      mobileMenuMedia.addEventListener('change', handleMobileMenuBreakpoint);
    } else if (typeof mobileMenuMedia.addListener === 'function') {
      mobileMenuMedia.addListener(handleMobileMenuBreakpoint);
    }

    const activate = (name, updateHash = true) => {
      const panel = panels.find((item) => item.dataset.panel === name);
      const fallback = tabs[0];
      const activeName = panel ? name : (fallback?.dataset.tab || '');
      if (!activeName) return;

      tabs.forEach((tab) => {
        tab.setAttribute('aria-selected', String(tab.dataset.tab === activeName));
      });
      panels.forEach((item) => {
        item.classList.toggle('is-active', item.dataset.panel === activeName);
      });

      document.querySelectorAll('.bkp-nav-group').forEach((group) => {
        const childIsActive = Array.from(group.querySelectorAll('[data-tab]'))
          .some((item) => item.dataset.tab === activeName);
        group.classList.toggle('is-active', childIsActive);
      });

      if (updateHash && window.history?.replaceState) {
        window.history.replaceState(null, '', `#${activeName}`);
      }
    };

    tabs.forEach((tab) => {
      tab.addEventListener('click', () => {
        activate(tab.dataset.tab || 'overview');
        if (activeMobileMenu) closeMobileMenu(false);
        tab.closest('details')?.removeAttribute('open');
      });
    });

    document.querySelectorAll('[data-open-tab]').forEach((link) => {
      link.addEventListener('click', (event) => {
        const name = link.dataset.openTab || '';
        if (!panels.some((panel) => panel.dataset.panel === name)) return;
        event.preventDefault();
        activate(name);
        if (activeMobileMenu) closeMobileMenu(false);
        link.closest('details')?.removeAttribute('open');
        panels.find((panel) => panel.dataset.panel === name)?.scrollIntoView({
          behavior: 'smooth',
          block: 'start',
        });
      });
    });

    window.addEventListener('hashchange', () => {
      activate(window.location.hash.replace('#', '') || 'overview', false);
    });
    activate(window.location.hash.replace('#', '') || 'overview', false);

    const search = document.querySelector('#bkp-search');
    const month = document.querySelector('#bkp-month-filter');
    const status = document.querySelector('#bkp-status-filter');
    const reset = document.querySelector('#bkp-filter-reset');
    const resultText = document.querySelector('#bkp-filter-results');
    const empty = document.querySelector('#bkp-no-results');
    const events = Array.from(document.querySelectorAll('[data-event]'));
    const groups = Array.from(document.querySelectorAll('[data-month-group]'));
    const monthHeadings = Array.from(document.querySelectorAll('[data-month-heading]'));

    const normalize = (value) => String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLocaleLowerCase('nl-NL')
      .replace(/\s+/g, ' ')
      .trim();

    const setFilteredOut = (element, filteredOut) => {
      element.hidden = filteredOut;
      element.classList.toggle('bkp-is-filtered-out', filteredOut);
      element.setAttribute('aria-hidden', String(filteredOut));
    };

    const applyFilters = () => {
      if (!events.length) {
        if (resultText) resultText.textContent = '0 activiteiten';
        return;
      }

      const query = normalize(search?.value);
      const selectedMonth = String(month?.value || '');
      const selectedStatus = String(status?.value || '');
      let visible = 0;

      events.forEach((event) => {
        const haystack = normalize(event.dataset.search);
        const matchesSearch = query === '' || haystack.includes(query);
        const matchesMonth = selectedMonth === '' || String(event.dataset.month || '') === selectedMonth;
        const matchesStatus = selectedStatus === '' || String(event.dataset.status || '') === selectedStatus;
        const show = matchesSearch && matchesMonth && matchesStatus;

        setFilteredOut(event, !show);
        if (show) visible += 1;
      });

      groups.forEach((group) => {
        const groupHasVisibleEvent = Array.from(group.querySelectorAll('[data-event]'))
          .some((event) => !event.classList.contains('bkp-is-filtered-out'));
        setFilteredOut(group, !groupHasVisibleEvent);

        const key = String(group.dataset.monthGroup || '');
        const heading = monthHeadings.find((item) => String(item.dataset.monthHeading || '') === key);
        if (heading) setFilteredOut(heading, !groupHasVisibleEvent);
      });

      const hasActiveFilter = query !== '' || selectedMonth !== '' || selectedStatus !== '';
      if (reset) reset.hidden = !hasActiveFilter;
      if (empty) setFilteredOut(empty, visible !== 0);
      if (resultText) {
        resultText.textContent = `${visible} van ${events.length} ${events.length === 1 ? 'activiteit' : 'activiteiten'}`;
      }
    };

    [search, month, status].filter(Boolean).forEach((field) => {
      field.addEventListener(field.tagName === 'INPUT' ? 'input' : 'change', applyFilters);
    });

    reset?.addEventListener('click', () => {
      if (search) search.value = '';
      if (month) month.value = '';
      if (status) status.value = '';
      applyFilters();
      search?.focus();
    });

    applyFilters();


    document.addEventListener('click', (event) => {
      mobileMenuEntries.forEach((entry) => {
        if (!entry.details.open) return;
        const insideTrigger = entry.details.contains(event.target);
        const insidePanel = entry.panel.contains(event.target);
        if (!insideTrigger && !insidePanel) entry.details.removeAttribute('open');
      });
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && activeMobileMenu) {
        event.preventDefault();
        closeMobileMenu(true);
      }
    });

    const todoTopic = document.querySelector('#bkp-todo-topic-filter');
    const todoRows = Array.from(document.querySelectorAll('[data-todo-row]'));
    const todoResults = document.querySelector('#bkp-todo-topic-results');
    const todoEmpty = document.querySelector('#bkp-todo-no-results');

    const applyTodoTopicFilter = () => {
      if (!todoRows.length) return;
      const selected = String(todoTopic?.value || '');
      let visible = 0;
      todoRows.forEach((row) => {
        const topic = String(row.dataset.topic || '');
        const show = selected === '' || (selected === '__empty__' ? topic === '' : topic === selected);
        setFilteredOut(row, !show);
        if (show) visible += 1;
      });
      if (todoResults) todoResults.textContent = `${visible} van ${todoRows.length} ${todoRows.length === 1 ? 'werkpunt' : 'werkpunten'}`;
      if (todoEmpty) setFilteredOut(todoEmpty, visible !== 0);
    };

    todoTopic?.addEventListener('change', applyTodoTopicFilter);
    applyTodoTopicFilter();

    const responsibilitySearch = document.querySelector('#bkp-responsibility-search');
    const responsibilityProject = document.querySelector('#bkp-responsibility-project-filter');
    const responsibilitySection = document.querySelector('#bkp-responsibility-section-filter');
    const responsibilityBucket = document.querySelector('#bkp-responsibility-bucket-filter');
    const responsibilityReset = document.querySelector('#bkp-responsibility-filter-reset');
    const responsibilityResults = document.querySelector('#bkp-responsibility-filter-results');
    const responsibilityNoResults = document.querySelector('#bkp-responsibility-no-results');
    const responsibilityItems = Array.from(document.querySelectorAll('[data-bkp-responsibility-item]'));
    const responsibilityProjectGroups = Array.from(document.querySelectorAll('[data-bkp-responsibility-project-group]'));
    const responsibilityGroupsUi = Array.from(document.querySelectorAll('[data-bkp-responsibility-group]'));
    const completedWrapper = document.querySelector('[data-bkp-completed-wrapper]');
    const openEmpty = document.querySelector('.bkp-responsibility-open-empty');

    const applyResponsibilityFilters = () => {
      if (!responsibilityItems.length) return;
      const query = normalize(responsibilitySearch?.value);
      const project = String(responsibilityProject?.value || '');
      const section = String(responsibilitySection?.value || '');
      const bucket = String(responsibilityBucket?.value || '');
      const filtersActive = query !== '' || project !== '' || section !== '' || bucket !== '';
      let visible = 0;

      responsibilityItems.forEach((item) => {
        const matchesSearch = query === '' || normalize(item.dataset.search).includes(query);
        const matchesProject = project === '' || String(item.dataset.project || '') === project;
        const matchesSection = section === '' || String(item.dataset.section || '') === section;
        const matchesBucket = bucket === '' || String(item.dataset.bucket || '') === bucket;
        const show = matchesSearch && matchesProject && matchesSection && matchesBucket;
        setFilteredOut(item, !show);
        if (show) visible += 1;
      });

      responsibilityProjectGroups.forEach((group) => {
        const shown = Array.from(group.querySelectorAll('[data-bkp-responsibility-item]'))
          .filter((item) => !item.classList.contains('bkp-is-filtered-out'));
        setFilteredOut(group, shown.length === 0);
        const count = group.querySelector('[data-bkp-responsibility-project-count]');
        if (count) count.textContent = String(shown.length);
      });

      responsibilityGroupsUi.forEach((group) => {
        const shown = Array.from(group.querySelectorAll('[data-bkp-responsibility-item]'))
          .filter((item) => !item.classList.contains('bkp-is-filtered-out'));
        setFilteredOut(group, shown.length === 0);
        const count = group.querySelector('[data-bkp-responsibility-group-count]');
        if (count) count.textContent = String(shown.length);
      });

      if (completedWrapper) {
        const completedVisible = Array.from(completedWrapper.querySelectorAll('[data-bkp-responsibility-item]'))
          .some((item) => !item.classList.contains('bkp-is-filtered-out'));
        setFilteredOut(completedWrapper, !completedVisible);
        if (filtersActive && completedVisible) completedWrapper.open = true;
        if (!filtersActive && bucket === '') completedWrapper.open = false;
      }

      if (openEmpty) setFilteredOut(openEmpty, filtersActive);
      if (responsibilityNoResults) setFilteredOut(responsibilityNoResults, visible !== 0);
      if (responsibilityResults) {
        responsibilityResults.textContent = `${visible} van ${responsibilityItems.length} ${responsibilityItems.length === 1 ? 'verantwoordelijkheid' : 'verantwoordelijkheden'}`;
      }
      if (responsibilityReset) responsibilityReset.hidden = !filtersActive;
    };

    [responsibilitySearch, responsibilityProject, responsibilitySection, responsibilityBucket].filter(Boolean).forEach((field) => {
      field.addEventListener(field.tagName === 'INPUT' ? 'input' : 'change', applyResponsibilityFilters);
    });
    responsibilityReset?.addEventListener('click', () => {
      if (responsibilitySearch) responsibilitySearch.value = '';
      if (responsibilityProject) responsibilityProject.value = '';
      if (responsibilitySection) responsibilitySection.value = '';
      if (responsibilityBucket) responsibilityBucket.value = '';
      applyResponsibilityFilters();
      responsibilityProject?.focus();
    });
    applyResponsibilityFilters();

    document.querySelectorAll('[data-bkp-responsibility-url]').forEach((card) => {
      const openCard = () => {
        const url = String(card.dataset.bkpResponsibilityUrl || '');
        if (url) window.location.assign(url);
      };
      card.addEventListener('click', (event) => {
        if (event.target.closest('a,button,input,select,textarea,label,summary,details')) return;
        openCard();
      });
      card.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        if (event.target.closest('a,button,input,select,textarea,label,summary,details')) return;
        event.preventDefault();
        openCard();
      });
    });
  });
})();
