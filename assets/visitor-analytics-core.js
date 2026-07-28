(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.ArdiVisitorAnalyticsCore = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';

  const ALLOWED_HOSTS = new Set(['ardirentservice.com', 'www.ardirentservice.com']);
  const ALLOWED_PATHS = new Set([
    '/', '/about.html', '/account.html', '/case-study.html', '/contact.html',
    '/equipment.html', '/lenses.html', '/prints.html', '/production.html', '/services.html',
  ]);
  const ALLOWED_ACTIONS = new Set([
    'request-quote', 'rent-now', 'contact', 'book-now', 'view-equipment', 'checkout',
  ]);

  function canonicalPage(value) {
    try {
      const base = 'https://www.ardirentservice.com/';
      const url = new URL(String(value || '/'), base);
      if (!ALLOWED_HOSTS.has(url.hostname)) return '/';
      const path = url.pathname.replace(/\/{2,}/g, '/').slice(0, 180);
      if (path === '/index.html') return '/';
      return /^\/[A-Za-z0-9_./-]*$/.test(path) && ALLOWED_PATHS.has(path || '/') ? (path || '/') : '/other';
    } catch (_) {
      return '/';
    }
  }

  function sanitizeClickLabel(value) {
    let text = String(value || '').replace(/\s+/g, ' ').trim();
    text = text.replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi, '[redacted]');
    text = text.replace(/(?:\+?\d[\d\s().-]{7,}\d)/g, '[redacted]');
    return text.slice(0, 80);
  }

  function safeClickDescriptor({ kind = '', href = '', action = '' } = {}) {
    const cleanAction = String(action || '').toLowerCase().trim();
    if (ALLOWED_ACTIONS.has(cleanAction)) return `action:${cleanAction}`;
    if (kind === 'link') {
      try {
        const url = new URL(String(href || ''), 'https://www.ardirentservice.com/');
        if ((url.protocol === 'http:' || url.protocol === 'https:') && ALLOWED_HOSTS.has(url.hostname)) {
          return `link:${canonicalPage(url.href)}`;
        }
      } catch (_) {}
      return 'external-link';
    }
    return 'button';
  }

  function normalizeActiveDelta(value) {
    const number = Number(value);
    if (!Number.isFinite(number) || number <= 0) return 0;
    return Math.min(30000, Math.floor(number));
  }

  function formatDuration(milliseconds) {
    const totalSeconds = Math.max(0, Math.floor(Number(milliseconds || 0) / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    return minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`;
  }

  return { canonicalPage, sanitizeClickLabel, safeClickDescriptor, normalizeActiveDelta, formatDuration };
});
