# Demo pública da Rendora no cPanel

A demo é de consulta. Os catálogos da Casa Lisboa e da Porto Living são públicos. Os quatro perfis da Casa Lisboa e o gestor da Porto Living entram com um clique. Cada sessão respeita as permissões da empresa e do perfil. Só a entrada e a saída podem escrever pela API. A administração global, os formulários públicos de contacto, as alterações de dados, os carregamentos e as integrações externas ficam bloqueados no servidor. Os ficheiros privados pertencem à instalação da demo, em rendora-demo/storage/uploads.

## Preparar no computador

1. Executa `npm ci`, `npm run prepare:cpanel` e `npm run package -- --demo`. Usa o diretório indicado pelo último comando, em `release/`. A variante de demo usa o empacotador Apache existente e um ponto de entrada próprio. Não inclui bases nem segredos.
2. Cria a base fictícia com `php bin/demo-snapshot.php /tmp/demo-20261106123456.sqlite`. Substitui a data de exemplo por um carimbo UTC de 14 dígitos (ano, mês, dia, hora, minuto e segundo). O comando só cria ficheiros novos e verifica a integridade da base. Guarda a base fora da pasta pública.
3. Compacta as pastas `rendora-demo` e `demo_public` do pacote num ZIP, preservando os ficheiros ocultos, em particular `demo_public/.htaccess` e `demo_public/.user.ini`.

## Publicar após revisão

1. No cPanel, em **Domínios → Criar novo domínio**, introduz `demo.rendora.pt`. Desmarca a partilha da raiz documental e configura `/home3/rendora/demo_public`. Confirma que a interface mostra esta raiz separada antes de prosseguir. Se o painel restringir a raiz a `public_html`, pede ao alojamento que configure `/home3/rendora/demo_public` para o subdomínio. [Criação de domínios no cPanel](https://docs.cpanel.net/cpanel/domains/domains/create-a-new-domain/)
2. Em **Gestor de Ficheiros**, ativa a visualização de ficheiros ocultos. Carrega e extrai o ZIP em `/home3/rendora`. Confirma que `index.php` está em `/home3/rendora/demo_public` e que `src/bootstrap.php` está em `/home3/rendora/rendora-demo`. Usa apenas essas duas pastas para esta publicação. A demo lê a sua configuração fixa no ponto de entrada e no bootstrap, sem depender do .env da instalação principal.
3. Na pasta privada `/home3/rendora/rendora-demo/storage`, carrega a base com a extensão temporária `.upload`. Com o carregamento concluído, usa **Mudar nome** para lhe dar o nome final `demo-AAAAMMDDHHMMSS.sqlite`. Confirma que o utilizador do PHP pode escrever na base e na pasta privada, onde o SQLite cria o journal. Mantém a base e a pasta restritas à conta (tipicamente 0600 e 0700 quando o PHP corre com o utilizador da conta).
4. Em **MultiPHP Manager**, confirma PHP 8.2 ou superior e as extensões exigidas pela aplicação, incluindo PDO SQLite e mbstring. Em **SSL/TLS Status**, confirma um certificado válido para o subdomínio, executa AutoSSL se necessário e ativa **Force HTTPS Redirect** em **Domínios**. Se o DNS for externo, aponta o subdomínio para o alojamento e confirma a resolução antes de validar o certificado.
5. Abre `https://demo.rendora.pt/api/v1/health` e confirma HTTP 200. Regista a versão de SQLite indicada no campo `sqlite_version`. Na raiz da demo, testa cada entrada numa sessão privada separada.
   - **Gestão (Casa Lisboa)** vê os imóveis e contratos da Casa Lisboa e os tickets da empresa.
   - **Morador** vê o contrato da Inês e o ticket da fuga de água sem a nota interna.
   - **Proprietário** vê os seus imóveis e consegue exportar as rendas.
   - **Técnico** vê os tickets que lhe foram atribuídos. O ticket de iluminação, sem atribuição, fica fora da sua vista.
   - **Gestão · Porto Living** vê o apartamento de Cedofeita e os dados da Porto Living.
6. Confirma que a cookie `__Host-rendora_demo` tem `Secure`, `HttpOnly`, `SameSite=Strict`, `Path=/` e nenhum atributo `Domain`. Confirma HTTP 403 em `/api/v1/platform` e ao tentar alterar dados. Termina uma sessão e confirma que a sessão da aplicação principal continua válida.

O Gestor de Ficheiros é suficiente. O fluxo **Deploy HEAD Commit** da instalação principal usa outros destinos. A demo não precisa de tarefas cron.

## Repor os dados

Gera localmente uma nova base com um nome cronologicamente posterior ao da geração ativa. Carrega-a para a pasta privada da demo com extensão temporária e muda o nome para `.sqlite` quando o carregamento terminar. A demo passa a usar o ficheiro novo no pedido seguinte. As sessões anteriores deixam de ser válidas e os visitantes podem voltar a entrar. Os pedidos que já estavam em curso terminam na geração anterior, pelo que deves conservar essa geração até terminarem. Se precisares de recuperar a geração anterior, deixa a nova geração sair de serviço publicando uma cópia validada da anterior com um carimbo mais recente, feita depois de terminar a atividade na base antiga. Mantém toda a operação em `/home3/rendora/rendora-demo/storage`.

## Compatibilidade SQLite

As migrações preservam os triggers de unicidade de checkout de `2f0854f`. A limitação de tentativas usa as instruções separadas de `f4a9a2e`. O teste `npm run test:demo` cobre a base, a autenticação, a consulta e a troca de geração no pacote Apache com PHP 8.2 e SQLite 3.7.17.

O SQL mais recente encontrado fora da superfície pública está no registo de dispositivos e na alteração de rendas (UPSERT) e no backup por consola e no deploy Git (`VACUUM INTO`). A publicação e a reposição da demo usam o Gestor de Ficheiros e uma base nova, sem executar esses fluxos. [Histórico do UPSERT](https://www.sqlite.org/lang_upsert.html)
