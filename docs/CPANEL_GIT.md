# Instalar Rendora pelo Git do cPanel — sem terminal

Esta entrega inclui **vendor PHP, interface compilada, SVGs, migrações e instalador**. Não é necessário executar Composer, npm ou comandos num terminal do alojamento. O botão **Deploy HEAD Commit** executa a tarefa registada em `.cpanel.yml`.

## Antes da instalação

- PHP 8.2 ou superior no domínio, com PDO SQLite, mbstring, cURL, fileinfo, iconv, OpenSSL e zlib. Ativa no seletor PHP/MultiPHP do painel; se uma extensão não estiver disponível, pede-a ao alojamento.
- Certificado HTTPS ativo, Apache com `.htaccess` e `mod_rewrite`, disco local para SQLite e permissões de escrita na pasta privada.
- Git Version Control e Cron Jobs disponíveis. Não é necessário Terminal, mas o alojamento tem de permitir as tarefas de deploy do Git e a execução agendada de PHP.

## 1. Ligar ao repositório privado

Repositório: **https://github.com/marcosborg/rendora**. No cPanel → Git Version Control → Create, usa uma pasta privada como `/home/UTILIZADOR/repositories/rendora`, nunca a raiz pública do domínio. Seleciona a branch preparada `codex/rendora-initial` e confirma que inclui `.cpanel.yml`, `vendor` e `public/app.html`.

O acesso ao GitHub privado precisa de autenticação. Sem terminal, podemos gerar a chave fora do servidor, importar a chave pelo cPanel → SSH Access → Manage SSH Keys e registar a chave pública como deploy key **só de leitura** no GitHub. A configuração SSH e `known_hosts` pode ser colocada pelo Gestor de Ficheiros. Não colocar tokens na URL do repositório nem chaves privadas no Git. A disponibilidade destas opções depende do alojamento e será confirmada quando houver acesso ao painel.

## 2. Destinos seguros e domínio

O deploy usa, por defeito, estas pastas relativas à pasta da conta:

| Pasta | Conteúdo |
|---|---|
| `rendora/` | Código PHP, dependências, configuração e dados privados |
| `public_html/rendora/` | Apenas ficheiros que podem ser servidos pelo domínio |

No cPanel → Domains, cria o domínio/subdomínio pretendido e define o **Document Root** como `/home/UTILIZADOR/public_html/rendora`. A aplicação deve abrir na raiz desse domínio, por exemplo `https://app.exemplo.pt/`, **não** em `https://exemplo.pt/rendora/`.

O deploy não altera DNS, não cria domínios, não instala certificados nem adivinha o nome da conta. Não substitui o site principal em `public_html`.

Se forem necessários outros destinos, cria pelo Gestor de Ficheiros o ficheiro `/home/UTILIZADOR/.rendora-deploy.json`, fora do repositório, copiando `deploy/config.example.json` e adaptando os dois caminhos relativos. A pasta privada deve ficar fora de **todos** os document roots da conta. Destinos com ligações simbólicas ou caminhos `..` são recusados. Não mudar estes caminhos numa instalação existente sem migrar os dados separadamente.

## 3. Instalar pelo painel

1. Git Version Control → Manage → **Pull or Deploy**.
2. Clica em **Update from Remote** e depois **Deploy HEAD Commit**.
3. Confirma a conclusão no registo do cPanel. Se um destino já contiver ficheiros não identificados como Rendora, o deploy recusa alterá-lo; escolhe pastas dedicadas vazias.
4. Ativa HTTPS para o domínio e seleciona a mesma versão PHP compatível no MultiPHP Manager.
5. No Gestor de Ficheiros, abre `rendora/storage/install.key` e copia a chave privada de instalação.
6. Abre o domínio por HTTPS. Preenche a chave, o endereço HTTPS do domínio, o nome e email do administrador global e a palavra-passe (mínimo 12 caracteres).
7. Conclui a instalação. A chave é eliminada, o instalador fica bloqueado e a configuração passa a produção, com demonstração e pagamentos reais desativados.
8. Inicia sessão e usa **Plataforma → Nova empresa**. Não são copiadas contas ou palavras-passe da demonstração local.

O assistente só aceita o domínio HTTPS aberto. Não há palavra-passe predefinida ou instalador público sem chave. O nome e a palavra-passe do administrador nunca são escritos no Git.

## 4. Configuração e tarefas agendadas

No Gestor de Ficheiros, ativa a visualização de ficheiros ocultos e edita `rendora/.env` para configurar Resend e Stripe quando necessário. Mantém `DEMO_MODE=false`, `COOKIE_SECURE=true` e `ALLOW_LIVE_PAYMENTS=false` até à ativação explícita de cobranças reais.

Em **Cron Jobs**, acrescenta estas tarefas nos campos do painel (não num terminal), substituindo o utilizador e o caminho do PHP pelo indicado pelo alojamento:

| Frequência | Comando no campo do cPanel |
|---|---|
| A cada 5 minutos | `/opt/cpanel/ea-php82/root/usr/bin/php /home/UTILIZADOR/rendora/bin/console.php cron >> /home/UTILIZADOR/rendora/storage/cron.log 2>&1` |
| Diariamente às 03:15 | `/opt/cpanel/ea-php82/root/usr/bin/php /home/UTILIZADOR/rendora/bin/console.php backup >> /home/UTILIZADOR/rendora/storage/backup.log 2>&1` |

O script de deploy procura versões PHP 8.2+ conhecidas do cPanel. O PHP do domínio e o do cron devem ter as mesmas extensões. Convites e lembretes só são entregues com email e cron configurados. Os backups locais devem também ser descarregados/replicados para fora da conta e ter uma política de retenção adequada ao espaço disponível.

## Atualizações e recuperação

1. O código e os artefactos compilados são preparados e enviados para o GitHub na máquina de desenvolvimento.
2. No cPanel, **Update from Remote → Deploy HEAD Commit**.
3. O deploy verifica o manifesto SHA-256, impede concorrência com os pedidos/cron, cria um backup SQLite antes das migrações e copia apenas os diretórios autorizados. Preserva `.env`, `storage`, anexos, contas e configurações `.htaccess`/`.user.ini` do domínio.
4. Se a aplicação estiver ocupada, o deploy pede para repetir. Se falhar depois de iniciar a atualização, fica em manutenção; consulta os logs, corrige a causa e repete o deploy. Não apagues o ficheiro `deploy.maintenance` para esconder uma falha.

Não existe rollback automático de esquema. Antes de reverter código com alterações incompatíveis na base de dados, restaura o backup correspondente, com a aplicação e cron parados. Pelo Gestor de Ficheiros, preserva a base atual, coloca a cópia em `rendora/storage/homealone.sqlite` e restaura o código compatível; confirma o funcionamento antes de retomar o cron. Nunca apagues `storage` ou `.env` para atualizar.

Não edites o clone Git no cPanel: alterações locais podem bloquear o deploy. Configurações específicas do alojamento ficam fora do clone, no `.env` instalado e no `.rendora-deploy.json` da conta.

## Preparação pelo programador

Na máquina de desenvolvimento: `npm ci`, `npm run prepare:cpanel`, `npm test`, `npm run test:api`, `npm run test:cpanel`. Após qualquer alteração ao código, dependências, documentação incluída ou ficheiros públicos, regenera `deploy/artifact-manifest.json` com `node scripts/cpanel-manifest.mjs` e faz commit dos artefactos junto com o código. Não editar manualmente o manifesto para contornar uma falha de integridade.

Testes locais cobrem instalação, CSRF, chave inválida, bloqueio após instalação, separação público/privado, recusa de destinos existentes, backup íntegro e preservação de dados em atualizações. A configuração real de Apache, HTTPS, autenticação Git e permissões do alojamento só pode ser validada quando houver acesso ao cPanel.

Referência oficial: [cPanel — Git Deployment](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-deployment/).
