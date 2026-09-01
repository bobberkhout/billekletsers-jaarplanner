(function () {
  'use strict';

  const config = window.BKPA_CONFIG || {};
  const installButton = document.getElementById('bkpa-install-button');
  const installDialog = document.getElementById('bkpa-install-dialog');
  const instructions = document.getElementById('bkpa-install-instructions');
  const installStatus = document.getElementById('bkpa-install-status');

  const updateNotice = document.getElementById('bkpa-update-notice');
  const updateNowButton = document.getElementById('bkpa-update-now');
  const updateLaterButton = document.getElementById('bkpa-update-later');
  const updateDialog = document.getElementById('bkpa-update-dialog');
  const accountUpdateLink = document.getElementById('bkpa-account-update-link');
  const checkUpdateButton = document.getElementById('bkpa-check-update');
  const dialogUpdateNowButton = document.getElementById('bkpa-dialog-update-now');
  const updateStatus = document.getElementById('bkpa-update-status');
  const lastCheckOutput = document.getElementById('bkpa-last-check');

  let deferredPrompt = null;
  let registration = null;
  let waitingWorker = null;
  let refreshing = false;
  let installDialogReturnFocus = null;
  let updateDialogReturnFocus = null;
  const lastCheckKey = 'bkpa_last_update_check';

  const label = function (name, fallback) {
    return config.labels && config.labels[name] ? config.labels[name] : fallback;
  };

  const isStandalone = function () {
    return window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true;
  };

  const isMobileLike = function () {
    return window.matchMedia('(max-width: 900px)').matches ||
      window.matchMedia('(pointer: coarse)').matches ||
      /Android|iPhone|iPad|iPod/i.test(window.navigator.userAgent || '');
  };

  const isiOS = function () {
    return /iPhone|iPad|iPod/i.test(window.navigator.userAgent || '') ||
      (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
  };

  const isAndroid = function () {
    return /Android/i.test(window.navigator.userAgent || '');
  };

  const formatCheckTime = function (timestamp) {
    if (!timestamp) return 'Nog niet gecontroleerd';
    const date = new Date(Number(timestamp));
    if (Number.isNaN(date.getTime())) return 'Nog niet gecontroleerd';
    try {
      return new Intl.DateTimeFormat('nl-NL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      }).format(date);
    } catch (error) {
      return date.toLocaleString();
    }
  };

  const readLastCheck = function () {
    try {
      return window.localStorage.getItem(lastCheckKey) || '';
    } catch (error) {
      return '';
    }
  };

  const storeLastCheck = function () {
    const value = String(Date.now());
    try {
      window.localStorage.setItem(lastCheckKey, value);
    } catch (error) {
      // De updatefunctie blijft werken wanneer lokale opslag is uitgeschakeld.
    }
    if (lastCheckOutput) lastCheckOutput.textContent = formatCheckTime(value);
  };

  const setUpdateStatus = function (message, state) {
    if (!updateStatus) return;
    updateStatus.textContent = message;
    updateStatus.dataset.state = state || '';
  };

  const installMenuSelector = '.bkpa-menu-install-link';

  const ensureInstallMenuLinks = function () {
    document.querySelectorAll('.bkp-header-actions .bkp-action-menu-panel').forEach(function (panel) {
      if (panel.querySelector(installMenuSelector)) return;
      const link = document.createElement('a');
      link.href = '#bkpa-install-app';
      link.className = 'bkpa-menu-install-link';
      link.textContent = 'App installeren';
      panel.insertBefore(link, panel.firstChild);
    });
  };

  const setInstallMenuVisibility = function (visible) {
    ensureInstallMenuLinks();
    document.querySelectorAll(installMenuSelector).forEach(function (link) {
      link.hidden = !visible;
      link.setAttribute('aria-hidden', visible ? 'false' : 'true');
    });
  };

  const placeInstallButton = function () {
    if (!installButton) return;
    const headerActions = document.querySelector('.bkp-header-actions');
    if (headerActions && installButton.parentNode !== headerActions) {
      headerActions.insertBefore(installButton, headerActions.firstChild);
    }
  };

  const showInstallButton = function () {
    setInstallMenuVisibility(!isStandalone());
    if (!installButton || !isMobileLike() || isStandalone()) return;
    placeInstallButton();
    installButton.hidden = false;
  };

  const hideInstallButton = function () {
    if (installButton) installButton.hidden = true;
    setInstallMenuVisibility(false);
  };

  const openInstallDialog = function (mode) {
    if (!installDialog || !instructions) return;
    let html = '';
    if (mode === 'ios') {
      html = '<ol><li>Open deze pagina in <strong>Safari</strong>.</li><li>Tik onderaan op de knop <strong>Deel</strong>.</li><li>Kies <strong>Zet op beginscherm</strong>.</li><li>Tik op <strong>Voeg toe</strong>.</li></ol>';
    } else if (mode === 'android') {
      html = '<ol><li>Open het browsermenu via de <strong>drie puntjes</strong>.</li><li>Kies <strong>App installeren</strong> of <strong>Toevoegen aan startscherm</strong>.</li><li>Bevestig de installatie.</li></ol>';
    } else {
      html = '<p>Open deze pagina op je telefoon in Safari of Chrome. Gebruik daarna het browsermenu en kies <strong>App installeren</strong> of <strong>Zet op beginscherm</strong>.</p>';
    }
    installDialogReturnFocus = document.activeElement;
    instructions.innerHTML = html;
    installDialog.hidden = false;
    document.documentElement.classList.add('bkpa-dialog-open');
    const closeButton = installDialog.querySelector('.bkpa-dialog-close');
    if (closeButton) closeButton.focus();
  };

  const closeInstallDialog = function () {
    if (!installDialog) return;
    installDialog.hidden = true;
    document.documentElement.classList.remove('bkpa-dialog-open');
    if (installDialogReturnFocus && installDialogReturnFocus.focus) installDialogReturnFocus.focus();
  };

  const startInstallation = async function (control) {
    if (isStandalone()) {
      hideInstallButton();
      return;
    }

    if (deferredPrompt) {
      const originalHtml = control && typeof control.innerHTML === 'string' ? control.innerHTML : '';
      if (control) {
        control.setAttribute('aria-busy', 'true');
        if ('disabled' in control) control.disabled = true;
        control.textContent = label('installing', 'Installeren…');
      }
      try {
        await deferredPrompt.prompt();
        await deferredPrompt.userChoice;
      } catch (error) {
        openInstallDialog(isAndroid() ? 'android' : 'other');
      } finally {
        deferredPrompt = null;
        if (control) {
          control.removeAttribute('aria-busy');
          if ('disabled' in control) control.disabled = false;
          if (originalHtml) control.innerHTML = originalHtml;
        }
        if (!isStandalone()) {
          setInstallMenuVisibility(true);
          showInstallButton();
        }
      }
      return;
    }

    openInstallDialog(isiOS() ? 'ios' : (isAndroid() ? 'android' : 'other'));
  };

  const openUpdateDialog = function () {
    if (!updateDialog) return;
    updateDialogReturnFocus = document.activeElement;
    if (lastCheckOutput) lastCheckOutput.textContent = formatCheckTime(readLastCheck());
    updateDialog.hidden = false;
    document.documentElement.classList.add('bkpa-dialog-open');
    const closeButton = updateDialog.querySelector('.bkpa-dialog-close');
    if (closeButton) closeButton.focus();
  };

  const closeUpdateDialog = function () {
    if (!updateDialog) return;
    updateDialog.hidden = true;
    document.documentElement.classList.remove('bkpa-dialog-open');
    if (updateDialogReturnFocus && updateDialogReturnFocus.focus) updateDialogReturnFocus.focus();
  };

  const showUpdateAvailable = function (worker) {
    if (worker) waitingWorker = worker;
    if (!waitingWorker && registration && registration.waiting) waitingWorker = registration.waiting;
    if (!waitingWorker) return;
    if (updateNotice) updateNotice.hidden = false;
    if (dialogUpdateNowButton) dialogUpdateNowButton.hidden = false;
    setUpdateStatus(label('available', 'Er staat een nieuwe versie klaar.'), 'available');
  };

  const hideUpdateNotice = function () {
    if (updateNotice) updateNotice.hidden = true;
  };

  const activateWaitingWorker = function () {
    const worker = waitingWorker || (registration && registration.waiting);
    if (!worker) {
      setUpdateStatus(label('latest', 'Je gebruikt de nieuwste versie.'), 'latest');
      return;
    }
    setUpdateStatus(label('activating', 'Nieuwe versie activeren…'), 'checking');
    if (updateNowButton) updateNowButton.disabled = true;
    if (dialogUpdateNowButton) dialogUpdateNowButton.disabled = true;
    worker.postMessage({ type: 'BKPA_SKIP_WAITING' });
  };

  const watchInstallingWorker = function (worker) {
    if (!worker) return;
    worker.addEventListener('statechange', function () {
      if (worker.state === 'installed' && navigator.serviceWorker.controller) {
        showUpdateAvailable(worker);
      }
    });
  };

  const checkForUpdates = async function (manual) {
    if (!registration) {
      setUpdateStatus(label('failed', 'Controleren is niet gelukt. Probeer het later opnieuw.'), 'error');
      return;
    }
    if (checkUpdateButton) checkUpdateButton.disabled = true;
    setUpdateStatus(label('checking', 'Controleren op updates…'), 'checking');
    storeLastCheck();
    try {
      await registration.update();
      if (registration.waiting) {
        showUpdateAvailable(registration.waiting);
      } else if (registration.installing) {
        watchInstallingWorker(registration.installing);
        window.setTimeout(function () {
          if (!waitingWorker) setUpdateStatus('De controle loopt nog. Een beschikbare update verschijnt automatisch.', 'checking');
        }, 1200);
      } else {
        window.setTimeout(function () {
          if (!waitingWorker && !(registration && registration.waiting)) {
            setUpdateStatus(label('latest', 'Je gebruikt de nieuwste versie.'), 'latest');
          }
        }, manual ? 1200 : 400);
      }
    } catch (error) {
      setUpdateStatus(label('failed', 'Controleren is niet gelukt. Probeer het later opnieuw.'), 'error');
    } finally {
      window.setTimeout(function () {
        if (checkUpdateButton) checkUpdateButton.disabled = false;
      }, 700);
    }
  };

  document.querySelectorAll('[data-bkpa-install-close]').forEach(function (control) {
    control.addEventListener('click', closeInstallDialog);
  });

  document.querySelectorAll('[data-bkpa-update-close]').forEach(function (control) {
    control.addEventListener('click', closeUpdateDialog);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (updateDialog && !updateDialog.hidden) closeUpdateDialog();
    else if (installDialog && !installDialog.hidden) closeInstallDialog();
  });

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredPrompt = event;
    showInstallButton();
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    hideInstallButton();
    if (installStatus) installStatus.textContent = label('installed', 'De app is geïnstalleerd.');
  });

  if (installButton) {
    installButton.addEventListener('click', function () {
      startInstallation(installButton);
    });
  }

  document.addEventListener('click', function (event) {
    const installLink = event.target.closest(installMenuSelector);
    if (!installLink) return;
    event.preventDefault();
    const parentDetails = installLink.closest('details');
    if (parentDetails) parentDetails.open = false;
    startInstallation(installLink);
  });

  if (accountUpdateLink) {
    accountUpdateLink.addEventListener('click', function (event) {
      event.preventDefault();
      const parentDetails = accountUpdateLink.closest('details');
      if (parentDetails) parentDetails.open = false;
      openUpdateDialog();
    });
  }

  if (checkUpdateButton) {
    checkUpdateButton.addEventListener('click', function () {
      checkForUpdates(true);
    });
  }

  if (updateNowButton) updateNowButton.addEventListener('click', activateWaitingWorker);
  if (dialogUpdateNowButton) dialogUpdateNowButton.addEventListener('click', activateWaitingWorker);
  if (updateLaterButton) updateLaterButton.addEventListener('click', hideUpdateNotice);

  if ('serviceWorker' in navigator && window.isSecureContext && config.workerUrl) {
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      if (refreshing) return;
      refreshing = true;
      window.location.reload();
    });

    window.addEventListener('load', async function () {
      try {
        registration = await navigator.serviceWorker.register(config.workerUrl, {
          scope: config.scopePath || '/'
        });

        if (registration.waiting && navigator.serviceWorker.controller) {
          showUpdateAvailable(registration.waiting);
        }

        registration.addEventListener('updatefound', function () {
          watchInstallingWorker(registration.installing);
        });

        const lastCheck = Number(readLastCheck() || 0);
        const sixHours = 6 * 60 * 60 * 1000;
        if (!lastCheck || (Date.now() - lastCheck) > sixHours) {
          checkForUpdates(false);
        } else if (lastCheckOutput) {
          lastCheckOutput.textContent = formatCheckTime(lastCheck);
        }
      } catch (error) {
        setUpdateStatus(label('failed', 'Controleren is niet gelukt. Probeer het later opnieuw.'), 'error');
        // De site blijft volledig bruikbaar wanneer registratie door hosting
        // of browserbeleid wordt geblokkeerd.
      }
    });
  } else {
    setUpdateStatus('App-updates zijn alleen beschikbaar via HTTPS in een ondersteunde browser.', 'error');
  }

  ensureInstallMenuLinks();
  setInstallMenuVisibility(!isStandalone());

  if (!isStandalone() && isMobileLike()) {
    // Op iPhone bestaat geen programmeerbare installatieprompt; daarom is
    // de knop direct beschikbaar en toont hij de juiste korte instructie.
    if (isiOS()) showInstallButton();
    // Voor overige mobiele browsers tonen we de knop ook als vangnet. Zodra
    // beforeinstallprompt beschikbaar is, wordt dezelfde knop native gebruikt.
    else window.setTimeout(showInstallButton, 900);
  }
})();
