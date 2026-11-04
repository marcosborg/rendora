# Rendora — identidade e implementação

Nome: **Rendora**. Descritivo: **Gestão de imóveis e arrendamentos**.
Slogan: **Os teus imóveis. Tudo em ordem.**

A aplicação mantém a arquitetura PHP/Slim, SQLite e React/TypeScript. A mudança é de apresentação: nomes de pacotes, namespaces PHP, cookies, contas de demonstração, diretórios, tabelas, rotas e dados existentes permanecem iguais. Não é necessária uma migração de dados.

## Identidade

- `web/theme.css`: cores semânticas centralizadas — verde-petróleo, superfícies claras, texto neutro, dourado discreto e estados de aviso/erro/informação.
- `web/styles.css`: layouts existentes, agora com referências aos tokens de cor.
- `web/rendora.css`: aplicação da identidade aos componentes e ajustes responsivos. O tema é claro; a página de acesso usa uma superfície inversa escura, sem introduzir um modo escuro global.
- Manrope para títulos e DM Sans para texto, já usadas no projeto; mantém-se o fallback sans-serif.
- `web/Brand.tsx`: componente partilhado do logótipo.
- `public/brand/`: SVGs do logótipo e símbolo para fundos claros/escuros e favicon. O símbolo combina o contorno de um imóvel com linhas de registos organizados. Não depende de imagens remotas.

## Superfícies

Identidade aplicada à navegação, cabeçalho móvel, autenticação, dashboard, rodapés, catálogo, documentação da API, PDFs e emails de recuperação de acesso. Metadados gerais em `app.html`; metadados do catálogo renderizados pelo PHP sem duplicar a descrição.

A página de acesso apresenta a proposta de valor e liga às funcionalidades reais de registo e autenticação. Os catálogos públicos continuam a apresentar a empresa gestora, a sua descrição e os seus imóveis. A cor configurável da empresa identifica o cabeçalho; a Rendora assina a plataforma no rodapé. Fotografias são dos imóveis existentes, sem imagem decorativa externa de substituição.

## Verificações

- TypeScript e compilação Vite de produção.
- 49 testes PHP e 18 testes HTTP aprovados, incluindo isolamento de empresas, contratos, pagamentos, manutenção e documentos.
- Inspeção de autenticação, dashboard, imóveis, rendas, formulários e catálogo em browser.
- Computador 1440 px, tablet 820 px e telemóvel 390 px; correção do excesso de largura do formulário de acesso.
- Navegação por teclado e foco visível nos formulários; filtros e abertura de pedido de informações no catálogo, sem envio de dados.
- Verificação de contraste por estilos calculados nas páginas inspecionadas; avisos e texto sobre a fotografia ajustados. Esta verificação não substitui uma auditoria completa de acessibilidade.
- O empacotador copia `public/brand` para `public_html/brand`, incluindo o favicon e ambas as versões do logótipo.

As integrações existentes e o alojamento de produção não foram alterados.
