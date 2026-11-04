# Rendora

Plataforma de gestão de arrendamento mensal em português europeu, preparada para **alojamento partilhado Linux com Apache, PHP 8.2 e SQLite**.

**Instalação sem terminal no cPanel:** [guia Git + Deploy HEAD Commit](docs/CPANEL_GIT.md). O repositório inclui as dependências PHP e a interface compilada. O assistente HTTPS cria o primeiro administrador mediante uma chave privada disponível no Gestor de Ficheiros.

A arquitetura inicialmente proposta foi ajustada ao alojamento indicado: React + TypeScript gera ficheiros estáticos; Slim/PHP disponibiliza a API; PDO/SQLite guarda os dados; cron processa rendas e notificações. Não há dependência de Node.js, PostgreSQL, Redis ou Docker em produção.

## Funcionalidades

- Empresas isoladas, equipa com seis perfis e administração global de planos.
- Edifícios, apartamentos, quartos e áreas comuns; catálogo público por empresa, publicação controlada e carregamento de fotografias.
- CRM, contactos, visitas, tarefas comerciais e conversão em contrato.
- Contratos com vários moradores, prevenção de sobreposições, renovações, alterações de renda, inspeções e cauções separadas.
- Rendas mensais idempotentes, valores proporcionais sujeitos a confirmação, conta-corrente, despesas, exportação CSV e comprovativos PDF não fiscais.
- Stripe Connect para rendas e Stripe Billing para subscrições, sem preços comerciais inventados; confirmação por webhook, reembolsos e contestações auditáveis.
- Avarias, prioridades, responsável, orçamento, aprovação, resolução, reabertura e separação de notas internas.
- Conversas privadas com anexos e leitura, comunicados, lembretes no portal e emails por fila cron/Resend.
- Portais de morador e proprietário; interface responsiva, API versionada, OpenAPI e cliente TypeScript para futura app.

## Executar localmente

Requisitos de desenvolvimento: PHP 8.2+ (PDO SQLite, cURL, mbstring, iconv, fileinfo, OpenSSL), Composer e Node.js 22.12+.

```sh
composer install
npm ci
```

Copie `.env.example` para `.env` e, apenas para desenvolvimento, defina:

```dotenv
APP_ENV=development
APP_URL=http://127.0.0.1:8080
DB_PATH=storage/homealone.sqlite
DEMO_MODE=true
COOKIE_SECURE=false
```

```sh
npm run setup
npm run build
npm run api
```

Abra `http://127.0.0.1:8080`. Os botões da demonstração permitem experimentar gestão, morador, proprietário e técnico. Os dados são fictícios e pertencem a duas empresas, Casa Lisboa e Porto Living. As contas de demonstração não têm uma palavra-passe publicada; o atalho só existe com `DEMO_MODE=true` fora de produção.

Para desenvolvimento da interface com recarregamento automático, mantenha o PHP a correr e execute `npm run dev`; abra a rota `/app.html` do Vite. A API é encaminhada para PHP. Para testar SEO e os caminhos públicos definitivos, use a versão compilada servida por PHP.

## Qualidade e entrega

```sh
npm test                     # regras, permissões, eventos financeiros e backup
npm run test:api             # servidor PHP real, HTTP, uploads, contratos e pagamentos
npm run build               # TypeScript + compilação de produção
node scripts/openapi.mjs
npm run client:generate
npm run package             # pacote sem dados de demonstração nem segredos
```

Os testes usam bases independentes, não a base de demonstração. Os testes HTTP necessitam da porta local 18082 livre.

- [Instalação no Apache e cron](docs/DEPLOY_APACHE.md)
- [API, autenticação móvel e pagamentos](docs/API.md)
- [Especificação OpenAPI](public/openapi.json)
- [Validação e limites conhecidos](docs/VALIDATION.md)

## Integrações

Stripe e Resend estão implementados, mas exigem contas, chaves e configuração do utilizador. Sem chaves, as ações apresentam um estado de configuração em falta. Nenhum pagamento real foi efetuado. A aplicação móvel nativa, entrega push, faturação fiscal, assinatura certificada, SMS/WhatsApp e reservas turísticas não fazem parte desta versão.

As fotografias de demonstração são carregadas do Unsplash; tipografia de Google Fonts com alternativas locais. Fotografias reais podem ser carregadas no próprio alojamento.
