# Ensaio local da demo em MySQL 5.7

O ensaio usa uma instância MySQL descartável e uma base vazia chamada `rendora_trial`. A demo publicada continua a usar SQLite. O router de teste só aceita pedidos locais e fica fora do pacote de publicação.

1. Cria uma base vazia com o conjunto de caracteres `utf8mb4`. Aplica `database/mysql/001_demo_trial.sql` com o cliente MySQL. O ficheiro usa `DELIMITER` para criar os triggers de validação.
2. Gera uma base fictícia com `php bin/demo-snapshot.php /tmp/demo-20261107170000.sqlite`. A importação aceita exclusivamente uma base marcada como demo, sem administradores globais. Exclui sessões, tokens de recuperação e limites de autenticação.
3. Define `RENDORA_TRIAL_MYSQL_DSN`, `RENDORA_TRIAL_MYSQL_USER` e `RENDORA_TRIAL_MYSQL_PASSWORD` no ambiente local. Usa uma conta da base de ensaio. O DSN deve indicar `dbname=rendora_trial` e `charset=utf8mb4`.
4. Executa `php scripts/mysql-demo-trial.php /tmp/demo-20261107170000.sqlite`. O importador exige um destino vazio e verifica contagens e totais em cêntimos antes de confirmar a transação.
5. Corre `npm run build` e `npm run test:demo`. O teste valida os perfis, o isolamento entre empresas, os bloqueios da demo e dois pedidos simultâneos de criação de contrato. Por fim, volta a servir uma base SQLite e verifica que uma sessão MySQL não é aceite e que é possível entrar novamente. Para repetir o ensaio MySQL, começa com uma base vazia, porque o teste cria um contrato.

A transação de escrita do ensaio usa uma linha de bloqueio InnoDB para reproduzir a serialização de SQLite. O resultado confirma a regra de sobreposição, mas não mede capacidade de escrita concorrente. A suíte geral pode ser executada em MySQL numa base de ensaio vazia com as mesmas variáveis. O teste específico do backup SQLite corre apenas em SQLite. A preparação da base no alojamento e a ativação de MySQL no cPanel ficam fora deste ensaio local.

Para ensaiar a importação de uma cópia do site principal, executa o importador com o argumento adicional `--presentation-rehearsal` e usa um socket MySQL local. Esta modalidade preserva as contas e exclui sessões, tokens temporários e limites de autenticação. Usa uma base de ensaio vazia e uma cópia protegida do ficheiro de origem.
