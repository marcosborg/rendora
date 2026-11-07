import { homePath, tr } from './locale';

/** Shared product identity. Company branding remains separate. */
export function Brand({ inverse = false }: { inverse?: boolean }) {
  return <a className="brand rendora-brand" href={homePath} aria-label={tr("Rendora — início","Rendora — home")}>
    <img src={`/brand/rendora-${inverse ? 'dark' : 'light'}.svg`} width="184" height="40" alt="Rendora"/>
  </a>;
}
