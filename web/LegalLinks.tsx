import { tr } from './locale';

export function LegalLinks() {
  return <nav className="legal-links" aria-label={tr('Informação legal', 'Legal information')}>
    <a href={tr('/legal', '/en/legal')}>{tr('Informação legal', 'Legal notice')}</a>
    <a href={tr('/privacidade', '/en/privacy')}>{tr('Privacidade', 'Privacy')}</a>
    <a href={tr('/cookies', '/en/cookies')}>Cookies</a>
    <a href={tr('/termos', '/en/terms')}>{tr('Termos de utilização', 'Terms of use')}</a>
    <a href="mailto:info@rendora.pt">info@rendora.pt</a>
  </nav>;
}
