import { en, otherLanguagePath, tr } from './locale';

export function LanguageSwitch() {
  const languages = [
    { code: 'PT', label: 'Português', lang: 'pt-PT', active: !en },
    { code: 'EN', label: 'English', lang: 'en', active: en },
  ];

  return <div className="language-switch" role="group" aria-label={tr('Idioma', 'Language')}>
    {languages.map(({ code, label, lang, active }) => active
      ? <span key={code} aria-current="true" aria-label={label} lang={lang}>{code}</span>
      : <a key={code} href={otherLanguagePath()} hrefLang={lang} aria-label={label} lang={lang}>{code}</a>)}
  </div>;
}
