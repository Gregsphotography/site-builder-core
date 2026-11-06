import { validMessage, previewMessage } from './protocol.js';

let selectNode = () => {};
let selectBinding = () => {};

// CSP also blocks native form.submit(), which bypasses the submit event.
document.addEventListener('submit', event => {
  event.preventDefault();
  event.stopImmediatePropagation();
}, true);
document.addEventListener('click', event => {
  const node = event.target instanceof Element && event.target.closest('[data-site-builder-node]');
  const binding = event.target instanceof Element && event.target.closest('[data-site-builder-binding]');
  if (binding && ['header', 'footer', 'siteDesign'].includes(binding.dataset?.siteBuilderBinding)) selectBinding(binding.dataset.siteBuilderBinding);
  else if (node) selectNode(node.dataset.siteBuilderNode);
  if (event.target instanceof Element && event.target.closest('a, button[type="submit"], input[type="submit"]')) {
    event.preventDefault();
    event.stopImmediatePropagation();
  }
}, true);

const element = document.getElementById('site-builder-preview-context');
if (element && window.parent !== window) {
  const context = JSON.parse(element.textContent);
  const origin = window.location.origin;
  const send = (type, extra) => window.parent.postMessage(previewMessage(context, type, extra), origin);
  selectNode = nodeId => send('select', { nodeId });
  selectBinding = binding => send('select-binding', { binding });
  window.addEventListener('message', event => {
    if (!validMessage(event, { ...context, origin, source: window.parent }, ['highlight', 'highlight-binding'])) return;
    document.querySelectorAll('[data-site-builder-node]').forEach(node => {
      node.style.outline = event.data.type === 'highlight' && node.dataset.siteBuilderNode === event.data.nodeId ? '2px solid #315b57' : '';
      node.style.outlineOffset = '-2px';
    });
    document.querySelectorAll('[data-site-builder-binding]').forEach(element => {
      element.style.outline = event.data.type === 'highlight-binding' && element.dataset.siteBuilderBinding === event.data.binding ? '2px solid #315b57' : '';
      element.style.outlineOffset = '-2px';
    });
  });
  send('ready');
}
