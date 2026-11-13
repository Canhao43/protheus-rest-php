# Mudanças

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/), e as versões seguem o [Versionamento Semântico](https://semver.org/lang/pt-BR/). Enquanto a versão for 0.x, a API pode mudar entre versões menores ([ADR 0010](docs/decisoes/0010-primeira-versao-0-1-0.md)).

## [0.1.0] — 2026-11-13

Primeira versão. Feita só a partir da documentação pública da TOTVS e testada com respostas gravadas no formato dela; ainda não rodou contra um Protheus de verdade.

### Adicionado

- Login OAuth2 com usuário e senha em cabeçalho, `Authorization: Bearer` em cada chamada, renovação um minuto antes de vencer pelo `refresh_token` e volta para a senha se ele for recusado.
- `401` renova o token e repete a chamada uma vez.
- `get`, `post`, `put`, `patch` e `delete`, com o JSON da resposta como array.
- Erros no padrão de API da TOTVS: `ErroDaApi` com `MensagemDeErro` (`code`, `message`, `detailedMessage`, `helpUrl`, `details`), `ErroDeAutenticacao`, `ErroDeConexao` e `RespostaInvalida`. Nenhuma senha, token ou query na mensagem.
- Coleções: `pagina()` e `colecao()`, que só pede a próxima página quando precisa e para em vez de girar para sempre com um servidor fora do padrão.
- `Consulta` com filtros, `order`, `fields` e `expand`.
- Nova tentativa de leitura em falha passageira (sem resposta, `429`, `502`, `503`, `504`), com espera crescente e `Retry-After`.
- Token em cache PSR-16 entre processos, opcional.

[0.1.0]: https://github.com/Canhao43/protheus-rest-php/releases/tag/v0.1.0
