import { LanguageSwitch } from '../LanguageSwitch';
import { useState } from 'react';
import { ArrowRight, ArrowUpRight, BedDouble, Check, HeartHandshake, House, KeyRound, Mail, MapPin, Maximize2, Phone, ShieldCheck } from 'lucide-react';
import { Brand } from '../Brand';
import { api, money, type Row } from '../api';
import { catalogPath, homePath, tr as copy } from '../locale';
import { Empty, Loading, useApp, useData } from '../ui';

const monthly = copy('/mês', '/month');

export function PublicSite({ slug }: { slug: string }) {
  const encodedSlug = encodeURIComponent(slug);
  const companyPath = catalogPath(slug);
  const { data, error } = useData<Row>(`/public/${encodedSlug}`);
  const { modal } = useApp();
  const [city, setCity] = useState('');
  const [limit, setLimit] = useState('');
  const [available, setAvailable] = useState(false);

  if (!data) return <div className="public-loading"><Loading error={error}/></div>;
  const { company, properties } = data;
  const filtered = properties.filter((p: Row) =>
    (!city || p.city === city) && (!limit || p.rent_cents <= Number(limit) * 100) && (!available || p.available));
  const ask = copy('Pedir informações', 'Ask for information');

  function visit(property: Row) {
    modal({
      title: copy('O primeiro passo para a tua nova casa.', 'Take the first step towards your new home.'),
      subtitle: property.name,
      submit: ask,
      fields: [
        { name: 'name', label: copy('O teu nome', 'Your name'), wide: true },
        { name: 'email', label: 'Email', type: 'email' },
        { name: 'phone', label: copy('Telefone', 'Phone'), required: false },
        {
          name: 'notes', label: copy('Conta-nos o que procuras', 'Tell us what you are looking for'),
          type: 'textarea', required: false,
          placeholder: copy('Quando gostarias de entrar? Tens alguma questão?', 'When would you like to move in? Do you have any questions?'),
        },
        {
          name: 'consent', type: 'checkbox',
          label: copy('Autorizo o contacto da empresa para responder a este pedido.', 'I agree to be contacted by the company about this enquiry.'),
        },
      ],
      onSubmit: details => api(`/public/${encodedSlug}/leads`, 'POST', { ...details, property_id: property.id }),
    });
  }

  function detail(property: Row) {
    modal({
      title: property.name,
      subtitle: property.address,
      content: <div className="detail-body">
        <div className="public-gallery">
          {property.photos.map((url: string) => <img src={url} alt={property.name} key={url}/>)}
        </div>
        <div className="detail-stats">
          <span>{copy('Renda mensal', 'Monthly rent')}<strong>{money(property.rent_cents)}</strong></span>
          <span>{copy('Área', 'Floor area')}<strong>{property.area} m²</strong></span>
          <span>{copy('Quartos', 'Bedrooms')}<strong>{property.bedrooms}</strong></span>
        </div>
        <p>{property.description}</p>
        <div className="tag-list">
          {property.amenities.map((amenity: string) => <span key={amenity}><Check size={13}/>{amenity}</span>)}
        </div>
        <p className="muted">{property.available
          ? copy('Disponível neste momento. Confirma as condições e a data de entrada com a equipa.', 'Currently available. Contact the team to confirm the terms and move-in date.')
          : copy('Atualmente ocupado. Contacta a equipa para saber mais sobre disponibilidade futura.', 'Currently occupied. Contact the team about future availability.')}</p>
        <button className="button" onClick={() => visit(property)}>{ask}<ArrowRight size={17}/></button>
      </div>,
    });
  }

  return <div className="public-site" style={{ '--company-color': company.color } as React.CSSProperties}>
    <header className="public-header">
      <a className="public-brand" href={companyPath}><span><House size={24}/></span>{company.name}<i/></a>
      <nav style={{ flexWrap: 'wrap', justifyContent: 'flex-end', gap: 8 }}>
        <a href="#alojamentos">{copy('As nossas casas', 'Our homes')}</a>
        <a href="#sobre">{copy('A nossa forma de cuidar', 'How we support you')}</a>
        <LanguageSwitch/>
        <a className="public-login" href={homePath}>{copy('Área do morador', 'Resident portal')}<ArrowUpRight size={16}/></a>
      </nav>
    </header>
    <main>
      <section className="public-hero">
        <div className="public-hero-copy">
          <span className="eyebrow"><i/> {copy('IMÓVEIS E ARRENDAMENTOS', 'PROPERTY AND RENTALS')}</span>
          <h1>{copy('A tua próxima casa.', 'Your next home.')}<br/><em>{copy('Tudo em ordem.', 'Everything in order.')}</em></h1>
          <p>{company.description || copy(
            'Explora os alojamentos, consulta as características e pede informações à equipa. O teu próximo arrendamento começa aqui.',
            'Browse the properties, see what each home offers and ask the team for more information. Your next rental starts here.',
          )}</p>
          <a href="#alojamentos" className="button">{copy('Descobrir alojamentos', 'Browse properties')}<ArrowUpRight size={19}/></a>
          <div className="hero-trust">
            <span><Check size={15}/> {copy('Arrendamento mensal', 'Monthly rentals')}</span>
            <span><Check size={15}/> {copy('Gestão próxima', 'Hands-on management')}</span>
          </div>
        </div>
        <div className="public-hero-image">
          {properties[0]?.photos[0] ? <>
            <img src={properties[0].photos[0]} alt={properties[0].name}/>
            <div className="hero-image-caption"><span><MapPin size={15}/>{properties[0].city}</span><strong>{properties[0].name}</strong></div>
          </> : <div className="catalog-placeholder"><House size={64}/><span>{copy('Alojamentos de', 'Properties by')} {company.name}</span></div>}
        </div>
      </section>
      <section id="alojamentos" className="public-catalog">
        <div className="public-section-heading">
          <div><span className="eyebrow">{copy('ESPAÇOS COM PERSONALIDADE', 'HOMES WITH CHARACTER')}</span><h2>{copy('Uma casa à tua medida.', 'A home that suits you.')}</h2></div>
          <p>{copy('Escolhe o espaço.', 'Find your space.')}<br/>{copy('Nós ajudamos com o próximo passo.', 'We’ll help you take the next step.')}</p>
        </div>
        <div className="public-filters">
          <label>
            <MapPin size={18}/>
            <select aria-label={copy('Localização', 'Location')} value={city} onChange={event => setCity(event.target.value)}>
              <option value="">{copy('Todas as localizações', 'All locations')}</option>
              {[...new Set<string>(properties.map((property: Row) => property.city))].map(location => <option key={location}>{location}</option>)}
            </select>
          </label>
          <label>
            <span>{copy('Até', 'Up to')}</span>
            <input aria-label={copy('Renda máxima', 'Maximum rent')} type="number" min="0" value={limit} placeholder={copy('Qualquer valor', 'Any amount')} onChange={event => setLimit(event.target.value)}/>
            <span>€{monthly}</span>
          </label>
          <label className="inline-check"><input type="checkbox" checked={available} onChange={event => setAvailable(event.target.checked)}/>{copy('Só disponíveis', 'Available only')}</label>
          <span>{filtered.length} {filtered.length === 1 ? copy('alojamento', 'property') : copy('alojamentos', 'properties')}</span>
        </div>
        <div className="public-properties">
          {filtered.map((property: Row) => <article className="public-property" key={property.id}>
            <button className="public-property-image" aria-label={property.name} onClick={() => detail(property)}>
              {property.photos[0] ? <img src={property.photos[0]} alt={property.name} loading="lazy"/> : <div className="property-placeholder"><House size={48}/></div>}
              <span>{property.available ? copy('Disponível', 'Available') : copy('Ocupado', 'Occupied')}</span>
            </button>
            <div className="public-property-info">
              <span className="property-location"><MapPin size={13}/>{property.city}</span>
              <button onClick={() => detail(property)}><h3>{property.name}</h3><ArrowUpRight size={21}/></button>
              <div className="property-features">
                <span><BedDouble size={16}/>{property.bedrooms} {property.bedrooms === 1 ? copy('quarto', 'bedroom') : copy('quartos', 'bedrooms')}</span>
                <span><Maximize2 size={15}/>{property.area} m²</span>
              </div>
              <div className="public-property-bottom">
                <strong>{money(property.rent_cents)}<small>{monthly}</small></strong>
                <button onClick={() => visit(property)}>{copy('Saber mais', 'Find out more')}<ArrowRight size={16}/></button>
              </div>
            </div>
          </article>)}
        </div>
        {!filtered.length && <Empty
          title={copy('Ainda não encontrámos esse espaço', 'No matching properties found')}
          text={copy('Experimenta alterar os filtros ou fala com a equipa.', 'Try changing the filters or contact the team.')}
        />}
      </section>
      <section className="public-values" id="sobre">
        <div>
          <span className="eyebrow">{copy('UMA FORMA MAIS PRÓXIMA DE ARRENDAR', 'A MORE PERSONAL WAY TO RENT')}</span>
          <h2>{copy('A tua casa.', 'Your home.')}<br/>{copy('A gestão em contacto.', 'Your property team within reach.')}</h2>
          <p>{copy('Com a Rendora, tens um espaço para consultar rendas e documentos, reportar avarias e falar com a gestão.', 'With Rendora, you can check rent and documents, report maintenance issues and contact your property team in one place.')}</p>
        </div>
        <div className="values-grid">
          <article><KeyRound size={25}/><h3>{copy('Entrar, instalar, viver.', 'Move in and make yourself at home.')}</h3><p>{copy('Consulta os documentos do teu contrato no portal do morador.', 'Find your tenancy documents in the resident portal.')}</p></article>
          <article><HeartHandshake size={25}/><h3>{copy('Estamos por perto.', 'We’re here to help.')}</h3><p>{copy('Um canal direto para falar com a gestão e acompanhar qualquer pedido.', 'Talk directly to your property team and track your requests.')}</p></article>
          <article><ShieldCheck size={25}/><h3>{copy('Tudo no teu lugar.', 'Everything in one place.')}</h3><p>{copy('Rendas, documentos e mensagens organizados no teu portal de morador.', 'Rent, documents and messages are all in your resident portal.')}</p></article>
        </div>
      </section>
      <section className="public-cta">
        <div>
          <span className="eyebrow">{copy('O PRÓXIMO CAPÍTULO COMEÇA COM UMA CONVERSA', 'THE NEXT CHAPTER STARTS WITH A CONVERSATION')}</span>
          <h2>{copy('Vamos encontrar a tua casa?', 'Ready to find your home?')}</h2>
          <p>{copy('Conta à equipa o que procuras e esclarece as tuas dúvidas.', 'Tell the team what you’re looking for and ask any questions you have.')}</p>
        </div>
        <a className="button" href={`mailto:${company.email}`}>{copy('Falar com a equipa', 'Contact the team')}<ArrowUpRight size={18}/></a>
      </section>
    </main>
    <footer className="public-footer">
      <div>
        <a className="public-brand" href={companyPath}><span><House size={22}/></span>{company.name}</a>
        <p>{copy('Casas com personalidade. Gestão com proximidade.', 'Homes with character. A property team within reach.')}</p>
      </div>
      <div>
        <a href={`mailto:${company.email}`}><Mail size={16}/>{company.email}</a>
        {company.phone && <a href={`tel:${company.phone}`}><Phone size={16}/>{company.phone}</a>}
      </div>
      <div className="public-footer-bottom">
        <span>© {new Date().getFullYear()} {company.name}</span>
        <span>{copy('Uma plataforma', 'Powered by')} <Brand/></span>
        <a href={homePath}>{copy('Área reservada', 'Sign in')}<ArrowUpRight size={13}/></a>
      </div>
    </footer>
  </div>;
}
