# Registro de decisões

Cada decisão de arquitetura do pacote, num arquivo só, com o mesmo modelo: **contexto**, **decisão**, **alternativas descartadas**, **consequências** e **como é verificado**, que aponta o teste ou a etapa do CI que prova a decisão.

O registro é conferido pelo build: `RegistroDeDecisoesTest` falha se a numeração tiver buraco, se faltar uma seção, se um ADR não estiver aqui ou se um teste ou etapa citados não existirem.

Toda afirmação sobre o Protheus cita a página pública da TOTVS de onde saiu. Uma decisão que mudar ganha um ADR novo que substitui o antigo. O antigo não some.

## M1 — autenticação e erros

- [0001 — Usuário e senha vão em cabeçalho, como o texto do TDN define](0001-credenciais-em-cabecalho.md)
- [0002 — O token é renovado um minuto antes de vencer, pelo refresh primeiro, e um 401 repete a chamada uma vez](0002-ciclo-do-token.md)
- [0003 — Erros no formato do guia da TOTVS, uma exceção por reação e nenhum segredo na mensagem](0003-erros-no-padrao-totvs.md)
- [0004 — Guzzle direto e relógio PSR-20](0004-guzzle-e-relogio-psr20.md)
