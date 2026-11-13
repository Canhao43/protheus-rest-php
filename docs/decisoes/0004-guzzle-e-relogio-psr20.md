# 0004 — Guzzle direto e relógio PSR-20

**Marco:** M1

## Contexto

O pacote precisa de um cliente HTTP, de testes que não toquem a rede e de controle sobre o tempo, porque o vencimento do token é a regra mais delicada.

## Decisão

- `guzzlehttp/guzzle` direto. O construtor aceita um `ClientInterface` do Guzzle, para quem precisa de proxy, certificado próprio ou outros timeouts. O padrão espera 10 s pela conexão e 30 s pela resposta.
- `psr/clock` (PSR-20) para o relógio: `RelogioDoSistema` por padrão, e qualquer relógio PSR-20 (Symfony Clock, Carbon) serve.
- Nos testes, o `MockHandler` do Guzzle responde com as respostas gravadas em `tests/respostas`, no formato da documentação e com dados fictícios. Se a fila acaba, o teste falha: nada vai para a rede. O `RelogioParado` faz o tempo andar sem `sleep`.

## Alternativas descartadas

- **PSR-18 com descoberta automática do cliente (`php-http/discovery`):** mais uma camada e uma escolha feita por mágica, para um pacote pequeno. O cliente HTTP do Laravel já é o Guzzle, então quem usa Laravel não ganha dependência nova.
- **`time()` direto e `sleep` nos testes:** testes de vencimento lentos e instáveis.
- **O Guzzle sem timeout, que é o padrão dele:** espera para sempre; um ERP travado travaria quem chama.

## Consequências

- Quem usa outro cliente HTTP (Symfony HttpClient) recebe o Guzzle como dependência a mais.

## Como é verificado

- `ci.yml`: *Testes*, que roda a suíte em PHP 8.2, 8.3 e 8.4
- `AutenticacaoTest::test_renova_pelo_refresh_token_um_minuto_antes_de_vencer`, que depende do relógio parado
