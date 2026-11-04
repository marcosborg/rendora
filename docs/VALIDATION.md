# Validação

Ambiente usado: Windows, PHP 8.2.12 com PDO SQLite, Composer, Node.js 24; interface compilada com React, TypeScript e Vite. O runtime de produção é PHP, sem Node.

Resultado final: **49 testes de regras/segurança + 18 testes HTTP = 67 testes aprovados**. Build TypeScript/Vite concluído. `npm audit` e `composer audit` sem vulnerabilidades conhecidas na execução.

## Verificado automaticamente

- Regras de arrendamento: datas válidas, sobreposição inclusiva, apartamento/quarto incompatíveis, quartos independentes, mês bissexto, valores proporcionais e alterações de renda por data de efeito.
- Isolamento: empresas, contratos, cobranças, documentos, downloads, mensagens, destinatários, atribuições de técnicos e chaves estrangeiras compostas.
- Financeiro: geração mensal idempotente, pagamentos parciais, saldos, sobrepagamentos manuais rejeitados, cauções separadas e reembolsos limitados.
- Eventos Stripe: assinatura inválida rejeitada, duplicações deduplicadas, sucesso não revertido por falha tardia, reembolso aplicado uma vez e contestação resolvida antes do evento de abertura.
- CRM: pedido público com autorização de contacto, visita, proposta e conversão única em contrato.
- Manutenção: criação, atribuição, resolução, reabertura e ocultação de notas internas.
- Comunicação: conversas exigem participação; leitura registada; notificações e jobs sem duplicações; revalidação do saldo antes do envio e repetição de falhas.
- HTTP real: login, CSRF, gravação e edição de imóveis, contrato, geração/confirmacão/liquidação de renda, multipart, downloads, bloqueio de ficheiros executáveis, fotografia privada/pública e exportação CSV.
- Backup: cópia consistente SQLite, abertura numa segunda ligação, integridade, chaves estrangeiras e comparação dos movimentos financeiros.

## Verificado no navegador

- Login de demonstração, dashboard com dados reais da API, navegação administrativa, catálogo de gestão e detalhe de avaria com notas internas.
- Site público com título renderizado pelo servidor, filtros e formulários.
- Layout a 390 px, sem transbordo horizontal; menu e formulário de alojamento adaptados ao telemóvel. Portal do morador com pagamento, pedidos e mensagens; nota interna confirmada ausente.
- Capturas em `docs/screenshots`: dashboard desktop, site público e portal móvel. Nenhum erro de JavaScript foi registado no navegador.

## Limites e configuração pendente

- Sem credenciais Stripe/Resend neste ambiente: foram testados eventos financeiros determinísticos e respostas de configuração em falta, não chamadas reais de cobrança, onboarding ou entrega de email.
- A instalação em Apache Linux e o cron do alojamento de destino ainda precisam de validação nesse servidor.
- SQLite serializa escritas. Para volume elevado de empresas/utilizadores ou armazenamento NFS, será necessária uma migração de base de dados; esta versão destina-se ao arranque em alojamento partilhado com disco local.
- A API de listagem devolve arrays sem paginação por cursor. Sessões móveis expiram em 24 horas e exigem nova autenticação.
- A reconciliação de valores após uma saída antecipada é explícita por ajustes/reembolsos; não altera pagamentos nem documentos passados automaticamente.
- Os ficheiros privados são guardados fora do DocumentRoot. Galerias de imóveis publicados são públicas por opção do gestor.
- Os PDFs são documentos de gestão; comprovativos identificados como não fiscais. Os modelos de contrato são editáveis por empresa.
