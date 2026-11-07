# Transferências bancárias na Rendora

A opção vem desactivada. Na demo pública fica indisponível. Em produção exige `BANK_TRANSFERS_ENABLED=true` e `ALLOW_LIVE_PAYMENTS=true`. Mantém os pagamentos reais desactivados durante os ensaios.

## Preparar um ensaio isolado

- Usa uma cópia com dados fictícios, `APP_ENV=test` e `BANK_TRANSFERS_ENABLED=true`. Mantém `ALLOW_LIVE_PAYMENTS=false`.
- Em SQLite, executa as migrações habituais. Em MySQL 5.7, aplica `database/mysql/002_bank_transfers.sql` à base seleccionada, por exemplo pelo phpMyAdmin. A migração MySQL é independente do deploy de código.
- Para subscrições, configura o beneficiário, o IBAN fictício, os IDs de planos já existentes e os valores finais a transferir em cêntimos. Configura apenas as periodicidades disponíveis. Confirma os montantes com impostos antes de usar dados reais.
- Na aplicação, o administrador da empresa configura, por contrato, o beneficiário e a conta de renda. Escolhe o proprietário do imóvel ou um gestor autorizado da própria empresa. O morador vê os dados na cobrança e pode enviar o comprovativo.
- Quem recebe a renda verifica no extracto o valor e o identificador único da operação. A aprovação regista o movimento. Nas subscrições, a revisão é feita por um administrador da plataforma e regista o plano e a data até à qual foi pago.
- Os comprovativos ficam em `storage/bank-proofs`, fora da raiz pública. Inclui este directório nas cópias de segurança privadas e fecha os prazos de retenção antes de aceitar comprovativos reais.

A data até à qual a subscrição está paga fica registada. A suspensão automática de acesso no fim do período e a facturação fiscal precisam de tratamento próprio antes do lançamento comercial.

## PayPal em sandbox

Usa contas e credenciais exclusivamente Sandbox. Para subscrições, prepara um plano de teste e confirma o pagamento e a renovação através de eventos autenticados. Para rendas, usa uma conta de beneficiário de teste distinta da conta da plataforma. Confirma que o vendedor na operação é o beneficiário e que o valor não entra na conta da plataforma. A integração multiparte depende das permissões e do registo de beneficiários no PayPal.

- [Autenticação REST](https://developer.paypal.com/api/rest/authentication/)
- [Eventos de subscrições](https://developer.paypal.com/subscriptions/webhooks)
- [Registo de beneficiários](https://developer.paypal.com/platforms/seller-onboarding)

## API para site e apps

Todas as operações exigem uma sessão autenticada. As rotas da empresa usam a empresa seleccionada e o acesso ao contrato ou à cobrança.

| Rota | Operação |
| --- | --- |
| `GET/PUT /api/v1/contracts/{id}/bank-account` | O administrador da empresa consulta ou configura o beneficiário da renda. |
| `GET /api/v1/charges/{id}/bank-transfer` | Consulta instruções e comprovativos visíveis. |
| `POST /api/v1/charges/{id}/bank-transfer` | O morador envia `file` e a `version` das instruções, em multipart. |
| `GET/POST /api/v1/settings/bank-transfer` | O administrador da empresa consulta ofertas ou envia `file`, `plan_id`, `months` e `version` em multipart. |
| `GET /api/v1/bank-proofs/{id}` | Descarrega um comprovativo com autorização. |
| `POST /api/v1/bank-proofs/{id}/review` | O destinatário autorizado revê a renda. |
| `GET /api/v1/platform/bank-proofs` | O administrador da plataforma consulta os pedidos de subscrição. |
| `GET /api/v1/platform/bank-proofs/{id}` | Descarrega um comprovativo de subscrição. |
| `POST /api/v1/platform/bank-proofs/{id}/review` | Revê um pedido de subscrição. |

A revisão recebe `status` (`APPROVED` ou `REJECTED`). Ao aprovar, envia também `bank_reference`, o identificador único da operação confirmado no extracto. O mesmo identificador fica reservado à operação nessa conta.
