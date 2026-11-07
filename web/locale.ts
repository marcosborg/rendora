export const en = /^\/en(?:\/|$)/.test(location.pathname);
export const locale = en ? 'en-GB' : 'pt-PT';
export const tr = (pt: string, english: string) => en ? english : pt;
export const homePath = en ? '/en/' : '/';
export const catalogPath = (slug: string) => (en ? '/en/properties/' : '/alojamentos/') + encodeURIComponent(slug);

export function otherLanguagePath() {
  const path = location.pathname;
  const alternate = path.startsWith('/en/properties/')
    ? path.replace('/en/properties/', '/alojamentos/')
    : path.startsWith('/alojamentos/')
      ? path.replace('/alojamentos/', '/en/properties/')
      : en ? '/' : '/en/';
  return alternate + location.search + location.hash;
}
