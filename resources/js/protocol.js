export const protocol = 'site-builder.preview';
export const version = 1;

export function validMessage(event, expected, types) {
  const data = event.data;
  if (event.origin !== expected.origin || event.source !== expected.source || expected.origin === 'null') return false;
  if (!data || typeof data !== 'object' || Array.isArray(data)) return false;
  if (data.protocol !== protocol || data.version !== version || !types.includes(data.type)) return false;
  for (const key of ['pageId', 'locale', 'session', 'channel']) if (data[key] !== expected[key]) return false;
  if (!Number.isSafeInteger(data.pageId) || data.pageId < 1) return false;
  const keys = ['protocol', 'version', 'type', 'pageId', 'locale', 'session', 'channel'];
  if (data.type === 'select' || data.type === 'highlight') {
    keys.push('nodeId');
    if (!(data.type === 'highlight' && data.nodeId === null) && (typeof data.nodeId !== 'string' || data.nodeId.length < 1 || data.nodeId.length > 128)) return false;
  }
  if (data.type === 'select-binding' || data.type === 'highlight-binding') {
    keys.push('binding');
    if (!(data.type === 'highlight-binding' && data.binding === null) && !['header', 'footer', 'siteDesign'].includes(data.binding)) return false;
  }
  return Object.keys(data).length === keys.length && Object.keys(data).every(key => keys.includes(key));
}

export function previewMessage(context, type, extra = {}) {
  return { protocol, version, pageId: context.pageId, locale: context.locale, session: context.session, channel: context.channel, type, ...extra };
}
