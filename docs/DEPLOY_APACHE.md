# Instalação em Apache / alojamento partilhado

Para **cPanel sem terminal**, usa o [guia de instalação pelo Git](CPANEL_GIT.md). As instruções seguintes descrevem o pacote manual e a operação por CLI quando disponível.

## Requisitos

- PHP **8.2 ou superior** com PDO SQLite, cURL, mbstring, iconv, fileinfo, OpenSSL, JSON e zlib; Apache 2.4 com `mod_rewrite` e `.htaccess` permitido.
- HTTPS, acesso de escrita à pasta privada `storage`, ligações HTTPS de saída para Stripe e Resend e cron (idealmente a cada cinco minutos).
- SQLite deve ficar em **disco local**, fora da pasta pública. Não usar um ficheiro SQLite numa partilha NFS/SMB. O servidor configura `foreign_keys`, transações `BEGIN IMMEDIATE` e espera por bloqueios de até 10 segundos.
- Não precisa de Node.js, Redis, Docker ou processos PHP permanentes no servidor. Node.js e Composer são usados na preparação do pacote; `vendor` e a interface compilada vão incluídos.

## Pacote pronto a carregar

Na máquina de desenvolvimento:

```sh
npm ci
composer install --no-dev --prefer-dist --optimize-autoloader
npm run build
node scripts/openapi.mjs
npm run client:generate
npm test
npm run test:api
npm run package
```

O comando produz uma pasta nova em `release/` com:

```text
homealone/           # código PHP, vendor, migrações, configuração e storage privados
public_html/         # ficheiros públicos: index.php, .htaccess, assets e documentação API
INSTALL.txt
```

Carregue ambas as pastas para a **pasta da conta do alojamento**, mantendo-as lado a lado. Não carregue `homealone` para dentro de `public_html`. Se já existir um site no domínio, utilize um subdomínio com diretório próprio; não substitua o site existente.

O `index.php` do pacote já aponta para `../homealone/src/bootstrap.php`. Se o alojamento usar outro nome de pasta pública, basta manter a pasta privada `homealone` como irmã. `homealone/public/app.html` é o modelo da interface usado pelo PHP e faz parte do pacote; os assets servidos estão em `public_html/assets`.

Para instalar diretamente o repositório num VPS, aponte o DocumentRoot para `<projeto>/public`. Nunca use a raiz do repositório como DocumentRoot. O `.htaccess` da raiz bloqueia acesso ao código; o de `public` autoriza a aplicação e encaminha as rotas.

## Configuração

Copie `homealone/.env.example` para `homealone/.env` e preencha:

```dotenv
APP_ENV=production
APP_URL=https://alojamentos.seudominio.pt
DB_PATH=storage/homealone.sqlite
DEMO_MODE=false
COOKIE_SECURE=true
STRIPE_SECRET_KEY=
STRIPE_WEBHOOK_SECRET=
STRIPE_BILLING_WEBHOOK_SECRET=
ALLOW_LIVE_PAYMENTS=false
RESEND_API_KEY=
EMAIL_FROM="Empresa <noreply@seudominio.pt>"
```

Não ative a demonstração em produção. O pacote não inclui a base de dados local nem contas de demonstração. Defina permissões de acordo com o utilizador PHP do alojamento: configuração `600`, diretórios privados `700` ou `750`; nunca `777`.

Execute pelo terminal do painel ou SSH:

```sh
cd /home/CONTA/homealone
php bin/console.php migrate
```

Se o painel não fornecer terminal, pode usar temporariamente a mesma linha num cron do painel e removê-la depois de confirmar a execução. As migrações são idempotentes. Não existe um instalador web público.

Abra o domínio, escolha **Criar empresa** e defina a conta administrativa. Configure a identidade, adicione imóveis e contactos, convide a equipa e ligue a conta Stripe. Convites e recuperação de palavra-passe precisam de um remetente de email funcional.

Para criar um administrador global da plataforma por SSH, passe a palavra-passe por stdin (não como argumento do processo):

```sh
read -s -p 'Palavra-passe (mínimo 12 caracteres): ' ADMIN_PASSWORD
printf '%s' "$ADMIN_PASSWORD" | php bin/console.php admin admin@seudominio.pt
unset ADMIN_PASSWORD
```

Se a conta já existir, o comando atribui o perfil global sem alterar a palavra-passe existente. Um administrador global não recebe automaticamente acesso aos dados das empresas.

## Cron

Adicione no painel, usando o caminho PHP fornecido pelo alojamento:

```cron
*/5 * * * * /usr/bin/php /home/CONTA/homealone/bin/console.php cron >> /home/CONTA/homealone/storage/cron.log 2>&1
15 3 * * * /usr/bin/php /home/CONTA/homealone/bin/console.php backup >> /home/CONTA/homealone/storage/backup.log 2>&1
```

O cron gera rendas, cria lembretes, processa até 30 emails por execução e limpa sessões expiradas. As falhas ficam em **Definições → Envios e tarefas**. Configure rotação dos ficheiros de log e retenção dos backups no painel do alojamento.

## Stripe e ativação

1. Use chaves de teste primeiro. Ative Stripe Connect e disponibilize o onboarding às empresas.
2. Registe os endpoints e eventos descritos em `docs/API.md`; configure segredos de assinatura distintos.
3. A empresa deve concluir a identificação na Stripe e ativar os métodos pretendidos. A elegibilidade dos métodos é decidida pelo prestador.
4. Configure o Resend, verifique o domínio de envio e publique os registos DNS pedidos pelo prestador.
5. Teste um pagamento completo em sandbox, incluindo o webhook. Só depois substitua as chaves e defina `ALLOW_LIVE_PAYMENTS=true`.

Sem estas configurações, a interface informa que o serviço está por configurar; não simula pagamentos nem emails reais.

## Backups, recuperação e atualizações

`php bin/console.php backup` cria uma cópia consistente da base com `VACUUM INTO`. Copie também `storage/uploads` e a configuração privada para o seu sistema de backup. Backups contêm dados pessoais e devem permanecer privados.

```sh
php bin/console.php verify-backup /caminho/backup.sqlite
```

Para restaurar: coloque o site em manutenção no painel/Apache, suspenda o cron, guarde uma cópia da base atual, substitua o ficheiro indicado por `DB_PATH` pelo backup verificado, restaure os uploads correspondentes, execute `migrate` e valide a integridade antes de reativar o site e o cron. O teste automatizado restaura um backup numa segunda ligação SQLite e verifica integridade, chaves estrangeiras e movimentos financeiros.

Para atualizar código, faça backup primeiro, envie o novo código e assets, preserve `.env` e `storage`, execute `migrate` e valide `/api/v1/health`. As migrações aplicadas são imutáveis; novas versões acrescentam migrações.

## Validação após instalação

- O domínio abre sem erros, o login funciona e o cookie de sessão é `Secure`.
- `/api/v1/health` devolve `status: ok`; `/docs.html` apresenta a API.
- `/storage/homealone.sqlite`, `/.env` e `/vendor/autoload.php` não são servidos pelo domínio.
- O site público por empresa funciona em `/alojamentos/{slug}`; uma empresa não vê registos de outra.
- Convites e lembretes chegam ao email, e o cron não apresenta erros.
- Um pagamento Stripe de teste só liquida a cobrança depois do webhook.

Este ambiente não executou Apache Linux nem os serviços externos reais. Foram testados PHP 8.2, SQLite e pedidos HTTP locais. O comportamento de `.htaccess`, caminhos, permissões e cron deve ser confirmado no alojamento de destino.
