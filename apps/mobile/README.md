# Rendora para inquilinos

Primeira versão Android e iOS em Expo e React Native. O portal está disponível em português e inglês. Usa a API da Rendora para consultar contratos, rendas do mês, pedidos de manutenção e conversas. O acesso de gestores, proprietários e técnicos continua no site.

## Ensaio local

Na raiz do repositório, prepara uma base fictícia dedicada e inicia o PHP. Usa um PHP com PDO SQLite, mbstring e cURL.

```sh
mkdir -p .local
export DB_PATH="$PWD/.local/mobile-demo.sqlite"
export DB_MYSQL_DSN='' APP_ENV=test DEMO_MODE=true COOKIE_SECURE=false
export APP_URL=http://127.0.0.1:18084
export ALLOW_LIVE_PAYMENTS=false BANK_TRANSFERS_ENABLED=false PAYPAL_SANDBOX_ENABLED=false
php bin/console.php migrate
php bin/console.php seed
php -S 127.0.0.1:18084 -t public public/router.php
```

Noutra janela, a partir de `apps/mobile`:

```sh
npm ci
npm run typecheck
npm run bundle
EXPO_PUBLIC_API_URL=http://127.0.0.1:18084 npm run ios
EXPO_PUBLIC_API_URL=http://10.0.2.2:18084 npm run android
```

Os dois últimos comandos destinam-se ao simulador iOS e ao emulador Android, respectivamente. No Android, pode ser necessário usar `adb reverse tcp:18084 tcp:18084` e definir o URL da API como `http://127.0.0.1:18084` caso o emulador não consiga aceder ao processo no Mac. A ligação HTTP local só é permitida em desenvolvimento. Para testes em dispositivos físicos, usa uma API de teste isolada e acessível por HTTPS.

A versão por defeito aponta para a demo da Rendora. Essa instalação precisa de receber a alteração da API que permite obter uma sessão da app de inquilinos antes de se poder usar o botão de demonstração num dispositivo. A sessão é guardada no Keychain ou Keystore. O servidor restringe-a às empresas em que o utilizador tem acesso como inquilino, mesmo que tenha outras funções noutras empresas.

## Antes de distribuir

- Validar em dispositivos Android e iOS o início e fim de sessão, a recuperação após expiração, a consulta das quatro áreas e a mudança de idioma.
- Confirmar os identificadores da app nas contas da Apple e da Google. Gerar builds assinadas de teste antes de preparar as fichas das lojas.
- Rever a política de privacidade e as declarações de tratamento de dados das lojas. Testar acessibilidade e tamanhos de ecrã.
- A primeira versão é de consulta. Transferências, PayPal, subscrições e anúncios ficam fora do binário de teste.
