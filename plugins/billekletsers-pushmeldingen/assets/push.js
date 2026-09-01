(function () {
  'use strict';

  const boot = function () {
    const config = window.BKPP_CONFIG || {};
    const dialog = document.getElementById('bkpp-dialog');
    const hiddenTrigger = document.getElementById('bkpp-open-settings');
    const status = document.getElementById('bkpp-status');
    const support = document.getElementById('bkpp-support-message');
    const enableButton = document.getElementById('bkpp-enable');
    const disableButton = document.getElementById('bkpp-disable');
    const testButton = document.getElementById('bkpp-test');

    if (!dialog || !status || !support || !enableButton || !disableButton || !testButton) {
      return;
    }

    let currentSubscription = null;
    let returnFocus = null;
    let busy = false;

    const post = async function (action, data) {
      const form = new URLSearchParams();
      form.set('action', action);
      form.set('nonce', config.nonce || '');
      Object.keys(data || {}).forEach(function (key) { form.set(key, data[key]); });
      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
        body: form.toString(),
        cache: 'no-store'
      });
      let json = null;
      try {
        json = await response.json();
      } catch (error) {
        throw new Error('De server gaf geen geldig antwoord. Controleer de websitecache en probeer opnieuw.');
      }
      if (!response.ok && (!json || typeof json.success === 'undefined')) {
        throw new Error('De server kon de aanvraag niet verwerken.');
      }
      return json;
    };

    const setStatus = function (message, error) {
      status.textContent = message || '';
      status.classList.toggle('is-error', Boolean(error));
    };

    const setBusy = function (value) {
      busy = Boolean(value);
      enableButton.disabled = busy;
      disableButton.disabled = busy;
      testButton.disabled = busy;
      dialog.setAttribute('aria-busy', busy ? 'true' : 'false');
    };

    const supported = function () {
      return window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    };

    const isIOS = function () {
      return /iPhone|iPad|iPod/i.test(navigator.userAgent || '') ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    };

    const isStandalone = function () {
      return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    };

    const urlBase64ToUint8Array = function (value) {
      if (!value) throw new Error('De server heeft nog geen geldige meldingssleutel. Neem contact op met de beheerder.');
      const padding = '='.repeat((4 - value.length % 4) % 4);
      const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
      const raw = window.atob(base64);
      const output = new Uint8Array(raw.length);
      for (let i = 0; i < raw.length; i += 1) output[i] = raw.charCodeAt(i);
      return output;
    };

    const waitWithTimeout = function (promise, milliseconds) {
      return Promise.race([
        promise,
        new Promise(function (_, reject) {
          window.setTimeout(function () {
            reject(new Error('De telefoonapp is nog niet gereed. Sluit de app volledig, open hem opnieuw en probeer nogmaals.'));
          }, milliseconds);
        })
      ]);
    };

    const registration = async function () {
      let existing = null;
      try {
        existing = await navigator.serviceWorker.getRegistration();
      } catch (error) {
        existing = null;
      }
      if (existing && existing.active) return existing;
      return waitWithTimeout(navigator.serviceWorker.ready, 10000);
    };

    const selectPreference = function () {
      document.querySelectorAll('input[name="bkpp_preference"]').forEach(function (radio) {
        radio.checked = radio.value === (config.preference || 'both');
      });
    };

    const refresh = async function () {
      selectPreference();
      enableButton.hidden = true;
      disableButton.hidden = true;
      testButton.hidden = true;

      if (!window.isSecureContext) {
        support.textContent = 'Pushmeldingen werken alleen wanneer de Jaarplanner via HTTPS is geopend.';
        return;
      }
      if (!supported()) {
        support.textContent = 'Deze browser ondersteunt geen pushmeldingen. Gebruik de geïnstalleerde Jaarplanner-app in Safari of Chrome.';
        return;
      }
      if (isIOS() && !isStandalone()) {
        support.textContent = config.appInstalledHint || 'Installeer de app eerst op het beginscherm.';
        enableButton.hidden = false;
        return;
      }

      const reg = await registration();
      currentSubscription = await reg.pushManager.getSubscription();
      const permission = Notification.permission;

      if (currentSubscription && permission === 'granted') {
        support.textContent = 'Pushmeldingen staan aan op dit toestel.';
        disableButton.hidden = false;
        testButton.hidden = false;
        return;
      }

      if (permission === 'denied') {
        support.textContent = 'Meldingen zijn door de telefoon of browser geblokkeerd. Sta meldingen toe in de instellingen van de Jaarplanner-app of browser.';
        return;
      }

      support.textContent = 'Pushmeldingen staan nog niet aan op dit toestel.';
      enableButton.hidden = false;
    };

    const closeActionMenu = function (trigger) {
      const details = trigger && trigger.closest ? trigger.closest('details.bkp-action-menu') : null;
      if (details) details.open = false;
    };

    const removeOpenParameter = function () {
      try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('bkpp_open')) return;
        url.searchParams.delete('bkpp_open');
        window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + (url.hash || ''));
      } catch (error) {
        // Niet essentieel voor de werking.
      }
    };

    const open = async function (event) {
      if (event) {
        event.preventDefault();
        returnFocus = event.currentTarget || event.target;
        closeActionMenu(event.target);
      }
      dialog.hidden = false;
      document.documentElement.classList.add('bkpp-dialog-open');
      setStatus('');
      setBusy(true);
      try {
        await refresh();
      } catch (error) {
        support.textContent = 'De meldingsstatus kon niet worden opgehaald.';
        setStatus(error.message || 'Probeer de app opnieuw te openen.', true);
      } finally {
        setBusy(false);
      }
      const closeButton = dialog.querySelector('.bkpp-close');
      if (closeButton) closeButton.focus();
    };

    const close = function () {
      dialog.hidden = true;
      document.documentElement.classList.remove('bkpp-dialog-open');
      removeOpenParameter();
      if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
      returnFocus = null;
    };

    const addMenuItem = function () {
      document.querySelectorAll('.bkp-action-menu-panel').forEach(function (panel) {
        if (panel.querySelector('[data-bkpp-menu]')) return;
        const link = document.createElement('a');
        link.href = config.settingsUrl || '#bkpp-meldingen';
        link.textContent = 'Meldingen instellen';
        link.setAttribute('data-bkpp-menu', '1');
        const logout = Array.from(panel.querySelectorAll('a')).find(function (item) {
          return /uitloggen/i.test(item.textContent || '');
        });
        panel.insertBefore(link, logout || null);
      });
    };

    if (hiddenTrigger) hiddenTrigger.addEventListener('click', open);

    document.addEventListener('click', function (event) {
      const trigger = event.target && event.target.closest ? event.target.closest('[data-bkpp-menu]') : null;
      if (!trigger) return;
      open(event);
    });

    dialog.querySelectorAll('[data-bkpp-close]').forEach(function (button) {
      button.addEventListener('click', close);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !dialog.hidden) close();
    });

    document.querySelectorAll('input[name="bkpp_preference"]').forEach(function (radio) {
      radio.addEventListener('change', async function () {
        if (busy) return;
        config.preference = radio.value;
        setBusy(true);
        try {
          const result = await post('bkpp_save_preference', {preference: radio.value});
          setStatus(result.success ? result.data.message : (result.data && result.data.message) || 'Opslaan mislukt.', !result.success);
        } catch (error) {
          setStatus(error.message || 'Je meldingskeuze kon niet worden opgeslagen.', true);
        } finally {
          setBusy(false);
        }
      });
    });

    enableButton.addEventListener('click', async function () {
      if (busy) return;
      setStatus('Toestemming aanvragen…');
      setBusy(true);
      try {
        if (!supported()) throw new Error('Pushmeldingen worden niet ondersteund op dit toestel of de verbinding is niet beveiligd.');
        if (isIOS() && !isStandalone()) throw new Error(config.appInstalledHint || 'Installeer de app eerst.');
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') throw new Error('Toestemming voor meldingen is niet gegeven.');
        const reg = await registration();
        let subscription = await reg.pushManager.getSubscription();
        if (!subscription) {
          subscription = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(config.publicKey || '')
          });
        }
        const result = await post('bkpp_subscribe', {subscription: JSON.stringify(subscription.toJSON())});
        if (!result.success) throw new Error((result.data && result.data.message) || 'Koppelen mislukt.');
        currentSubscription = subscription;
        setStatus(result.data.message);
        await refresh();
      } catch (error) {
        setStatus(error.message || 'Pushmeldingen konden niet worden aangezet.', true);
      } finally {
        setBusy(false);
      }
    });

    disableButton.addEventListener('click', async function () {
      if (busy) return;
      setStatus('Pushmeldingen uitzetten…');
      setBusy(true);
      try {
        const reg = await registration();
        const subscription = currentSubscription || await reg.pushManager.getSubscription();
        const endpoint = subscription ? subscription.endpoint : '';
        if (subscription) await subscription.unsubscribe();
        const result = await post('bkpp_unsubscribe', {endpoint: endpoint});
        currentSubscription = null;
        setStatus(result.success ? result.data.message : (result.data && result.data.message) || 'Uitzetten mislukt.', !result.success);
        await refresh();
      } catch (error) {
        setStatus(error.message || 'Pushmeldingen konden niet worden uitgezet.', true);
      } finally {
        setBusy(false);
      }
    });

    testButton.addEventListener('click', async function () {
      if (busy) return;
      setStatus('Testmelding versturen…');
      setBusy(true);
      try {
        const result = await post('bkpp_test_push', {});
        setStatus(result.success ? result.data.message : (result.data && result.data.message) || 'Test mislukt.', !result.success);
      } catch (error) {
        setStatus(error.message || 'De testmelding kon niet worden verstuurd.', true);
      } finally {
        setBusy(false);
      }
    });

    addMenuItem();
    window.setTimeout(addMenuItem, 600);

    const queryRequestsOpen = (new URLSearchParams(window.location.search)).get('bkpp_open') === '1';
    if (!dialog.hidden || queryRequestsOpen) {
      window.setTimeout(function () { open(null); }, 0);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, {once: true});
  } else {
    boot();
  }
})();
