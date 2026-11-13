# Registro de decisões

Cada decisão de arquitetura do pacote, num arquivo só, com o mesmo modelo: **contexto**, **decisão**, **alternativas descartadas**, **consequências** e **como é verificado**, que aponta o teste ou a etapa do CI que prova a decisão.

O registro é conferido pelo build: `RegistroDeDecisoesTest` falha se a numeração tiver buraco, se faltar uma seção, se um ADR não estiver aqui ou se um teste ou etapa citados não existirem.

Toda afirmação sobre o Protheus cita a página pública da TOTVS de onde saiu. Uma decisão que mudar ganha um ADR novo que substitui o antigo. O antigo não some.

## M1 — autenticação e erros

- [0001 — Usuário e senha vão em cabeçalho, como o texto do TDN define](0001-credenciais-em-cabecalho.md)
- [0002 — O token é renovado um minuto antes de vencer, pelo refresh primeiro, e um 401 repete a chamada uma vez](0002-ciclo-do-token.md)
- [0003 — Erros no formato do guia da TOTVS, uma exceção por reação e nenhum segredo na mensagem](0003-erros-no-padrao-totvs.md)
- [0004 — Guzzle direto e relógio PSR-20](0004-guzzle-e-relogio-psr20.md)

## M2 — coleções

- [0005 — A coleção é lida por um gerador que só pede a próxima página quando precisa](0005-colecao-como-gerador-preguicoso.md)
- [0006 — A resposta de coleção é conferida, e o gerador para em vez de girar para sempre](0006-colecao-conferida-contra-laco-infinito.md)
- [0007 — A consulta usa os parâmetros do guia e recusa o que mudaria a URL por baixo](0007-consulta-com-os-parametros-do-guia.md)

## M3 — resiliência

- [0008 — Nova tentativa só em leitura, só em falha passageira, com espera crescente](0008-nova-tentativa-so-em-leitura.md)
- [0009 — O token pode ficar num cache PSR-16, uma entrada por servidor e usuário](0009-token-em-cache-psr16.md)

## M4 — publicação

- [0010 — A primeira versão é 0.1.0, publicada pelo Packagist, com o pacote distribuído conferido no CI](0010-primeira-versao-0-1-0.md)
