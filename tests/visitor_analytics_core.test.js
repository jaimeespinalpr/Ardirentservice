const assert = require('node:assert/strict');
const {
  canonicalPage,
  sanitizeClickLabel,
  safeClickDescriptor,
  normalizeActiveDelta,
  formatDuration,
} = require('../assets/visitor-analytics-core.js');

assert.equal(canonicalPage('/equipment.html?email=secret@example.com#checkout'), '/equipment.html');
assert.equal(canonicalPage('https://www.ardirentservice.com/about.html?x=1'), '/about.html');
assert.equal(canonicalPage('https://evil.example/secret?email=x'), '/');
assert.equal(canonicalPage('https://www.ardirentservice.com/customer/Jaime-Espinal?email=x'), '/other');

const redacted = sanitizeClickLabel('Email secret@example.com or call +1 (939) 555-1212');
assert.equal(redacted.includes('secret@example.com'), false);
assert.equal(redacted.includes('939'), false);
assert.ok(redacted.includes('[redacted]'));
assert.ok(sanitizeClickLabel('x'.repeat(200)).length <= 80);
assert.equal(safeClickDescriptor({ kind: 'link', href: '/equipment.html?email=secret@example.com' }), 'link:/equipment.html');
assert.equal(safeClickDescriptor({ kind: 'link', href: 'mailto:secret@example.com' }), 'external-link');
assert.equal(safeClickDescriptor({ kind: 'button', action: 'request-quote' }), 'action:request-quote');
assert.equal(safeClickDescriptor({ kind: 'button', action: 'jaime-customer-123' }), 'button');
assert.equal(safeClickDescriptor({ kind: 'button', action: 'Jaime Espinal' }), 'button');
assert.equal(safeClickDescriptor({ kind: 'link', href: '/customer/Jaime-Espinal' }), 'link:/other');

assert.equal(normalizeActiveDelta(-1), 0);
assert.equal(normalizeActiveDelta(1234.8), 1234);
assert.equal(normalizeActiveDelta(90000), 30000);
assert.equal(formatDuration(0), '0s');
assert.equal(formatDuration(65000), '1m 5s');

console.log('visitor-analytics-core tests passed');
