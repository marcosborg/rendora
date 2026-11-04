# Rendora API v1

Contrato de integração: [`public/openapi.json`](../public/openapi.json). Leitura no navegador: `/docs.html`. Cliente TypeScript em `packages/client/src`, com tipos gerados pelo OpenAPI.

## Autenticação

A implementação foi adaptada ao alojamento partilhado: PHP 8.2 + SQLite, sem NestJS, PostgreSQL, Redis ou Supabase. As contas são locais. Palavras-passe usam `password_hash`; os tokens de sessão são aleatórios, armazenados apenas como SHA-256 na base de dados. Não são JWT.

- Browser: `POST /api/v1/auth/login` com `email` e `password`. O servidor emite cookie `HttpOnly; SameSite=Strict; Secure` em produção. Guardar `csrf` em memória e enviar `X-CSRF-Token` nas escritas.
- App móvel: o mesmo pedido com `client: "mobile"` devolve `token`. Guardar no Keychain/Keystore e enviar `Authorization: Bearer <token>`. Validade de 24 horas; ao receber 401, pedir nova autenticação. Nunca guardar a palavra-passe.
- `GET /api/v1/auth/me` devolve utilizador, CSRF e empresas acessíveis. Enviar `X-Company-Id` nas rotas de negócio.
- `POST /api/v1/auth/logout` revoga a sessão. A alteração de palavra-passe revoga todas as sessões.
- Convites de contas sem email comprovado ficam pendentes; só se ativam ao abrir a ligação enviada por email e definir a palavra-passe. Recuperar a palavra-passe não reativa acessos suspensos.

## Permissões

| Perfil | Acesso |
|---|---|
| Administrador da plataforma | Cria empresas, atribui administradores, reenvia convites e gere planos; não obtém acesso automático aos dados privados das empresas |
| Administrador da empresa | Gestão completa, equipa, pagamentos e definições |
| Gestor | Imóveis, contratos, CRM, manutenção, comunicação e geração de rendas |
| Financeiro | Consulta operacional, geração de rendas, pagamentos manuais, reembolsos e despesas |
| Técnico | Apenas pedidos atribuídos, respetivos anexos e conversas em que participa |
| Proprietário | Imóveis atribuídos, contratos, rendas, despesas e pedidos desses imóveis |
| Morador | Contratos próprios, rendas, respetivos espaços, pedidos criados e conversas em que participa |

Notas internas são visíveis apenas à equipa operacional autorizada. Conversas exigem participação explícita mesmo para administradores. IDs de outras empresas devolvem 404; uma empresa sem pertença ativa devolve 403.

## Convenções

### Criação de empresas pela plataforma

`POST /api/v1/platform/companies` exige uma conta com `platform_admin` e recebe `name`, `slug`, `email` (público da empresa), `admin_name` e `admin_email`. Devolve 201 com `id`, `name`, `slug` e `administrator` (id, nome, email, active, invitation_pending). Não exige `X-Company-Id`.

A criação da empresa, associação do administrador, auditoria e preparação do convite é transacional. O endereço público é único (409 se já existir). Uma conta existente com email verificado mantém a identidade, palavra-passe e outras pertenças; uma conta nova ou não verificada fica pendente até definir a palavra-passe pela ligação enviada por email. Não se atribuem privilégios de administrador da plataforma ao novo administrador.

`GET /api/v1/platform` inclui os administradores e os seus estados por empresa. `POST /api/v1/platform/companies/{id}/administrators/{userId}/invite` reenvia apenas convites pendentes de administradores dessa empresa. As ligações expiram em uma hora; o reenvio invalida a ligação anterior e está sujeito a limitação de frequência.

Em demonstração não são enviados emails. Em produção, configurar Resend, remetente e cron. Não são expostas palavras-passe, ligações privadas ou tokens na resposta da API.

- Valores monetários: inteiros em cêntimos de EUR. Nunca enviar números decimais como dinheiro.
- Datas de negócio: `YYYY-MM-DD`; períodos: `YYYY-MM`. Instantes técnicos armazenados em UTC e datas de vencimento avaliadas em Europe/Lisbon.
- Os campos em JSON usam `snake_case`. Listas devolvem arrays; nesta versão não existe paginação por cursor.
- Erros: `{"message":"…"}`, com 401/403/404/409/422/429/503 conforme o caso. Falhas inesperadas devolvem um identificador de ocorrência, sem SQL, caminhos privados ou segredos.
- Uploads: `multipart/form-data`, até 10 MB, campos `file`, `entity_type`, `entity_id`. PDFs, JPEG, PNG, WebP e TXT. Acesso aos downloads passa sempre pela autorização da entidade.
- Fotografias de galeria: `POST /properties/{id}/photos`. Ficam privadas até publicar o alojamento; apenas as imagens explicitamente associadas à galeria podem ser servidas publicamente.
- `POST /devices` regista um dispositivo, mas retorna `push_enabled: false`. A entrega push fica para a app nativa.

## Fluxos

1. Um formulário público cria um contacto e uma oportunidade na empresa do catálogo. As etapas comerciais podem avançar para qualificação, visita e proposta. `/leads/{id}/convert` cria o contrato e marca a oportunidade como convertida numa transação.
2. `/charges/generate` cria rendas do período sem duplicar. O cron também gera o mês corrente e, a partir do dia 23, o seguinte. Valores proporcionais ficam em `DRAFT` até confirmação por gestor/financeiro.
3. `/charges/{id}/checkout` cria ou reutiliza uma sessão Stripe na conta da empresa. As chaves idempotentes ligam a sessão à cobrança. Nenhuma página de sucesso marca uma renda como paga.
4. Webhooks verificam assinatura e conta. Eventos duplicados não duplicam movimentos. Eventos assíncronos mantêm o estado em processamento. Confirmações tardias não são revertidas por falhas antigas.
5. Pagamentos manuais e ajustes de crédito exigem justificação e `idempotency_key`. A reutilização da chave com dados diferentes devolve 409. Um checkout online em curso impede registo manual concorrente.
6. Reembolsos e contestações são novos movimentos; não apagam o pagamento original. Um reembolso online só altera o saldo após confirmação do prestador. Eventos recebidos antes do movimento original devolvem 409 para nova tentativa.
7. A saída do morador preserva cobranças existentes; se existirem cobranças no mês da saída ou posteriores, exige `acknowledge_charges: true`. O gestor reconcilia os valores por ajustes e reembolsos.

## Stripe e emails

Configure dois endpoints distintos: `/api/v1/webhooks/stripe` para contas Connect e `/api/v1/webhooks/billing` para subscrições da plataforma. Ative os métodos de pagamento na conta conectada. MB WAY e Multibanco são pagamentos individuais iniciados pelo morador, não débitos automáticos.

Eventos de rendas: `payment_intent.succeeded`, `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `refund.created`, `refund.updated`, `charge.dispute.created`, `charge.dispute.closed`. Subscrições: `customer.subscription.created`, `.updated`, `.deleted`.

A subscrição usa o estado atual recuperado da Stripe para evitar regressões por eventos fora de ordem. Não há preço comercial predefinido. O administrador configura um `stripe_price_id` de subscrição válido.

A fila de emails faz até cinco tentativas com intervalos crescentes e chaves idempotentes no Resend. O saldo e a pertença ativa são revalidados antes do envio de lembretes. Operações externas têm entrega pelo menos uma vez com deduplicação no prestador; em falhas prolongadas, verificar o estado do prestador antes de repetir manualmente.

## Gerar cliente

```sh
node scripts/openapi.mjs
npm run client:generate
```

Exemplo móvel:

```ts
import { HomeAloneClient } from './packages/client/src';
const client = new HomeAloneClient('https://app.exemplo.pt', readTokenFromSecureStorage, companyId);
const charges = await client.charges('2026-11');
await client.request(`/charges/${charges[0].id}/checkout`, { method: 'POST', body: '{}' });
```
