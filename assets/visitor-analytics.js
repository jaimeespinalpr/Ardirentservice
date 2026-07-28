(() => {
  'use strict';

  const Core = window.ArdiVisitorAnalyticsCore;
  if (!Core) return;

  const API_URL = 'https://pay.ardirentservice.com/visitor_analytics.php?action=event';
  const GA_ID = 'G-CL6G607YCV';
  const CONSENT_KEY = 'ardi_analytics_consent_v1';
  const SESSION_KEY = 'ardi_visitor_session_v2';
  const TAB_KEY = 'ardi_anonymous_tab_v1';
  const CONSENT_VERSION = '2026-07-27-v1';
  const SESSION_TTL_MS = 15 * 60 * 1000;
  const HEARTBEAT_MS = 20 * 1000;

  let tracking = false;
  let sessionId = '';
  let tabId = '';
  let visibleSince = document.visibilityState === 'visible' ? Date.now() : 0;
  let heartbeatTimer = 0;

  function uuid() {
    return crypto.randomUUID();
  }

  function readSession() {
    try {
      const stored = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null');
      if (stored && typeof stored.id === 'string' && stored.expiresAt > Date.now()) {
        stored.expiresAt = Date.now() + SESSION_TTL_MS;
        localStorage.setItem(SESSION_KEY, JSON.stringify(stored));
        return { id: stored.id, created: false };
      }
    } catch (_) {}
    const fresh = { id: uuid(), expiresAt: Date.now() + SESSION_TTL_MS };
    localStorage.setItem(SESSION_KEY, JSON.stringify(fresh));
    return { id: fresh.id, created: true };
  }

  function readTabId() {
    let id = sessionStorage.getItem(TAB_KEY);
    if (!id) {
      id = uuid();
      sessionStorage.setItem(TAB_KEY, id);
    }
    return id;
  }

  function touchSession() {
    try {
      const stored = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null');
      if (stored && stored.id === sessionId) {
        stored.expiresAt = Date.now() + SESSION_TTL_MS;
        localStorage.setItem(SESSION_KEY, JSON.stringify(stored));
      }
    } catch (_) {}
  }

  function payload(eventType, extra = {}) {
    return {
      event_type: eventType,
      session_id: sessionId,
      tab_id: tabId,
      page_path: Core.canonicalPage(location.href),
      active_ms: 0,
      consent_version: CONSENT_VERSION,
      locale: String(document.documentElement.lang || navigator.language || '').slice(0, 12),
      ...extra,
    };
  }

  function send(eventType, extra = {}) {
    if (!tracking) return;
    touchSession();
    const body = JSON.stringify(payload(eventType, extra));
    fetch(API_URL, {
      method: 'POST',
      mode: 'cors',
      credentials: 'omit',
      cache: 'no-store',
      keepalive: true,
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      body,
    }).catch(() => {});
  }

  function activeDelta() {
    if (!visibleSince) return 0;
    const now = Date.now();
    const delta = Core.normalizeActiveDelta(now - visibleSince);
    visibleSince = now;
    return delta;
  }

  function flushActive() {
    const milliseconds = activeDelta();
    if (milliseconds > 0) send('heartbeat', { active_ms: milliseconds });
  }

  function loadGoogleAnalytics() {
    window[`ga-disable-${GA_ID}`] = false;
    if (document.querySelector(`script[data-ardi-ga="${GA_ID}"]`)) return;
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', GA_ID, {
      anonymize_ip: true,
      allow_google_signals: false,
      allow_ad_personalization_signals: false,
      cookie_expires: 604800,
      page_location: `${window.location.origin}${Core.canonicalPage(window.location.href)}`,
      page_referrer: '',
    });
    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(GA_ID)}`;
    script.dataset.ardiGa = GA_ID;
    document.head.appendChild(script);
  }

  function startTracking() {
    if (tracking) return;
    tracking = true;
    const session = readSession();
    sessionId = session.id;
    tabId = readTabId();
    visibleSince = document.visibilityState === 'visible' ? Date.now() : 0;
    loadGoogleAnalytics();
    send(session.created ? 'start' : 'page_view');
    heartbeatTimer = window.setInterval(flushActive, HEARTBEAT_MS);
  }

  function rotateExpiredSession() {
    try {
      const stored = JSON.parse(localStorage.getItem(SESSION_KEY) || 'null');
      if (stored && stored.id === sessionId && stored.expiresAt > Date.now()) return;
    } catch (_) {}
    const session = readSession();
    sessionId = session.id;
    send('start');
  }

  function stopTracking() {
    if (tracking) {
      flushActive();
      send('end');
    }
    tracking = false;
    window.clearInterval(heartbeatTimer);
    heartbeatTimer = 0;
    window[`ga-disable-${GA_ID}`] = true;
    localStorage.removeItem(SESSION_KEY);
    sessionStorage.removeItem(TAB_KEY);
  }

  function addStyles() {
    if (document.getElementById('ardi-consent-styles')) return;
    const style = document.createElement('style');
    style.id = 'ardi-consent-styles';
    style.textContent = `
      .ardi-consent{position:fixed;z-index:99999;left:16px;right:16px;bottom:16px;max-width:760px;margin:auto;background:#fff;color:#151515;border:1px solid #d9d9d9;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.18);padding:16px;font:14px/1.45 system-ui,sans-serif}
      .ardi-consent p{margin:0 0 12px}.ardi-consent-actions{display:flex;gap:8px;flex-wrap:wrap}
      .ardi-consent button,.ardi-privacy-settings{border:1px solid #222;border-radius:6px;padding:9px 14px;background:#fff;color:#111;cursor:pointer;font:600 13px/1 system-ui,sans-serif}
      .ardi-consent button[data-accept]{background:#111;color:#fff}.ardi-privacy-settings{margin:10px 0;padding:7px 10px}
      @media(max-width:520px){.ardi-consent{left:10px;right:10px;bottom:10px}.ardi-consent-actions button{flex:1}}
    `;
    document.head.appendChild(style);
  }

  function settingsButton() {
    if (document.querySelector('.ardi-privacy-settings')) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'ardi-privacy-settings';
    button.textContent = 'Privacidad / Privacy';
    button.addEventListener('click', showConsent);
    const footer = document.querySelector('footer');
    (footer || document.body).appendChild(button);
  }

  function showConsent() {
    document.querySelector('.ardi-consent')?.remove();
    const spanish = String(document.documentElement.lang || '').toLowerCase().startsWith('es');
    const panel = document.createElement('aside');
    panel.className = 'ardi-consent';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', spanish ? 'Preferencias de analítica' : 'Analytics preferences');
    const message = spanish
      ? 'Con tu permiso usamos analítica anónima para medir páginas, tiempo visible y clics en botones o enlaces. Nunca guardamos lo que escribes en formularios, contraseñas, pagos ni información personal.'
      : 'With your permission, we use anonymous analytics to measure pages, visible time, and clicks on buttons or links. We never store form entries, passwords, payment data, or personal information.';
    panel.innerHTML = `<p>${message}</p><div class="ardi-consent-actions"><button type="button" data-accept>${spanish ? 'Aceptar analítica' : 'Accept analytics'}</button><button type="button" data-reject>${spanish ? 'Rechazar' : 'Decline'}</button></div>`;
    panel.querySelector('[data-accept]').addEventListener('click', () => {
      localStorage.setItem(CONSENT_KEY, 'accepted');
      panel.remove();
      startTracking();
      settingsButton();
    });
    panel.querySelector('[data-reject]').addEventListener('click', () => {
      localStorage.setItem(CONSENT_KEY, 'denied');
      stopTracking();
      panel.remove();
      settingsButton();
    });
    document.body.appendChild(panel);
  }

  document.addEventListener('click', (event) => {
    if (!tracking) return;
    const target = event.target instanceof Element ? event.target.closest('a,button,[role="button"]') : null;
    if (!target || target.closest('.ardi-consent') || target.classList.contains('ardi-privacy-settings')) return;
    if (target.closest('form') || target.closest('input,textarea,select,[contenteditable="true"]')) return;
    const type = target.matches('a') ? 'link' : 'button';
    const label = Core.safeClickDescriptor({
      kind: type,
      href: type === 'link' ? target.getAttribute('href') : '',
      action: target.getAttribute('data-analytics-action') || '',
    });
    if (label) send('click', { target_type: type, target_label: label });
  }, { passive: true });

  document.addEventListener('visibilitychange', () => {
    if (!tracking) return;
    if (document.visibilityState === 'hidden') {
      flushActive();
      visibleSince = 0;
    } else {
      rotateExpiredSession();
      visibleSince = Date.now();
    }
  });
  window.addEventListener('pagehide', flushActive);

  addStyles();
  const consent = localStorage.getItem(CONSENT_KEY);
  if (consent === 'accepted') {
    startTracking();
    settingsButton();
  } else if (consent === 'denied') {
    settingsButton();
  } else {
    showConsent();
  }
})();
