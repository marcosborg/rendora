# PayPal Sandbox na Rendora

A integração só funciona com `APP_ENV=test`, `PAYPAL_SANDBOX_ENABLED=true` e pagamentos reais desactivados. Usa uma base isolada com dados fictícios. A demo pública e o site em produção não disponibilizam estas rotas.

## Configuração privada

1. Aplica as migrações da base de teste. Em MySQL 5.7 aplica, pela ordem indicada, `database/mysql/002_bank_transfers.sql` e `database/mysql/003_paypal_sandbox.sql` depois do esquema inicial.
2. Usa uma aplicação comercial Sandbox para subscrições e outra para cada beneficiário de renda a testar. Confirma o identificador comercial de cada conta e cria um plano recorrente Sandbox em euros.
3. Configura o beneficiário no contrato de renda em teste. A associação PayPal é feita por empresa e utilizador beneficiário. A conta PayPal associada à renda tem de ser distinta da usada para as subscrições.
4. Guarda o JSON abaixo na variável privada `PAYPAL_SANDBOX_APPS`. Usa apenas credenciais Sandbox e mantém-nas fora do Git.

```json
{
  "subscriptions": {
    "client_id": "<sandbox-client-id>",
    "client_secret": "<sandbox-secret>",
    "merchant_id": "<sandbox-merchant-rendora>",
    "webhook_id": "<sandbox-webhook-id>",
    "plans": [
      {
        "plan_id": "<id-do-plano-na-base-de-teste>",
        "paypal_plan_id": "<id-do-plano-sandbox>",
        "months": 1,
        "amount_cents": 1900
      }
    ]
  },
  "rent-owner": {
    "client_id": "<sandbox-client-id>",
    "client_secret": "<sandbox-secret>",
    "merchant_id": "<sandbox-merchant-beneficiario>",
    "webhook_id": "<sandbox-webhook-id>",
    "company_id": "<id-da-empresa-de-teste>",
    "recipient_id": "<id-do-beneficiario-de-teste>"
  }
}
```

## Notificações e ensaio

- Regista um webhook HTTPS separado por aplicação Sandbox. O endpoint de teste é `/api/v1/webhooks/paypal-sandbox/{alias}`.
- Nas subscrições, subscreve `PAYMENT.SALE.COMPLETED`, `BILLING.SUBSCRIPTION.ACTIVATED`, `BILLING.SUBSCRIPTION.CANCELLED`, `BILLING.SUBSCRIPTION.SUSPENDED`, `BILLING.SUBSCRIPTION.EXPIRED` e `BILLING.SUBSCRIPTION.PAYMENT.FAILED`. A confirmação de pagamento inicial e de renovação usa o evento de venda concluída. A activação sem confirmação de venda fica sem período pago.
- Nas rendas, subscreve `CHECKOUT.ORDER.APPROVED` e `PAYMENT.CAPTURE.COMPLETED`. A Rendora consulta a ordem na conta do beneficiário, confirma o identificador comercial, a referência da cobrança, a moeda e o valor, e só regista a captura concluída uma vez.
- Faz uma subscrição e uma renda com um comprador fictício, compara os destinatários no painel PayPal e confirma os estados e os movimentos na base de teste. Para observar uma renovação real do Sandbox, espera pelo próximo ciclo do plano. Os testes automatizados exercitam a renovação com uma resposta de verificação simulada.
- O simulador genérico do PayPal não verifica eventos pelo endpoint de verificação remota usado nesta integração. Usa notificações emitidas pelas aplicações Sandbox associadas aos checkouts para verificar o percurso completo.

## Retomar o ensaio da próxima renovação

Preserva a base isolada que recebeu o primeiro pagamento. A subscrição externa só pode ser associada ao pedido local se a base mantiver o registo original. Antes do próximo ciclo, confirma em PayPal Sandbox a data em `billing_info.next_billing_time` e anota o número de vendas e o fim do período pago na base de teste.

1. Arranca a mesma base num servidor isolado, com pagamentos reais desactivados. Disponibiliza por HTTPS apenas o endpoint do webhook da aplicação de subscrições.
2. Regista a URL para `PAYMENT.SALE.COMPLETED` na mesma aplicação Sandbox que criou a subscrição e configura na instância o identificador do novo webhook. Confirma que o destino está acessível antes da data de cobrança.
3. Depois da cobrança, confirma no PayPal o identificador da nova venda e compara-o com o registo em `paypal_subscription_payments`. O total deve subir de uma para duas vendas e o período em `paypal_intents.covered_until` deve avançar. Reenvia a notificação a partir do histórico Sandbox e confirma que o total fica em duas vendas.
4. Retira o webhook e fecha o servidor de ensaio após a validação. Se o webhook não estiver registado no momento da cobrança, verifica no histórico PayPal se existe uma entrega que possa ser reenviada antes de dar o ensaio como concluído.

[Webhooks de subscrições](https://developer.paypal.com/subscriptions/webhooks) · [Verificação de webhooks](https://developer.paypal.com/api/rest/webhooks/rest/) · [Simulador de webhooks](https://developer.paypal.com/api/rest/webhooks/simulator/)
