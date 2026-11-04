# Criar empresas independentes na Rendora

1. Inicia sessão com uma conta de **administrador da plataforma**.
2. Abre **Plataforma → Nova empresa**.
3. Preenche o nome da empresa, endereço público único (por exemplo `gestao-lisboa`), email público, nome e email do administrador.
4. Clica em **Criar empresa**. A empresa aparece imediatamente na lista com o estado do administrador.

Cada empresa começa vazia, com os seus próprios imóveis, utilizadores, contratos e restantes dados. O catálogo fica em `/alojamentos/gestao-lisboa` e só mostra os imóveis que a empresa publicar.

Se a conta do administrador já existir e tiver email verificado, mantém a palavra-passe e passa a ter acesso à empresa. O nome existente não é substituído. Uma conta nova ou não verificada recebe um convite para definir a palavra-passe; o acesso fica pendente até concluir esse passo. A ligação vale uma hora. Podes usar **Reenviar convite** na lista de empresas se expirar.

Em demonstração, os emails não são enviados. Em produção, os convites exigem Resend, remetente e cron configurados; a área Plataforma mostra quando o email está por configurar. Nunca é necessário escolher ou partilhar a palavra-passe do administrador.

O administrador global não recebe acesso automático à gestão interna da nova empresa. Para seres também o administrador dessa empresa, indica o teu próprio email; uma conta verificada recebe a pertença e o seletor de empresas é atualizado. Apenas administradores globais veem este menu; administradores de empresas não podem criar outras empresas por esta via.

Não são necessárias migrações novas, desde que as migrações existentes estejam aplicadas. As subscrições e contas Stripe mantêm a configuração própria de cada empresa.
