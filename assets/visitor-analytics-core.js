(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) root.ArdiVisitorAnalyticsCore = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
  'use strict';

  const ALLOWED_HOSTS = new Set(['ardirentservice.com', 'www.ardirentservice.com']);

  function canonicalPage(value) {
    try {
      const base = 'https://www.ardirentservice.com/';
      const url = new URL(String(value || '/'), base);
      if (!ALLOWED_HOSTS.has(url.hostname)) return '/';
      const path = url.pathname.replace(/\/{2,}/g, '/').slice(0, 180);
      return /^\/[A-Za-z0-9_./-]*$/.test(path) ? (path || '/') : '/';
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

  return { canonicalPage, sanitizeClickLabel, normalizeActiveDelta, formatDuration };
});
