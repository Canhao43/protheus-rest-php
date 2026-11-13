# 0005 — A coleção é lida por um gerador que só pede a próxima página quando precisa

**Marco:** M2

## Contexto

O guia de API da TOTVS ([pageId=484701329](https://tdn.totvs.com/pages/releaseview.action?pageId=484701329), seções "Paginação" e "Mensagens de sucesso para coleções") define:

- `page` e `pageSize` na URL, com semântica de multiplicador: `page=2&pageSize=20` devolve os registros 21 a 40;
- sem `page`, vale 1; sem `pageSize`, vale o padrão do endpoint, e o endpoint **não deve** devolver todos os registros;
- a resposta é um objeto com `hasNext` e `items`, e pode trazer outros campos (o guia cita `total` e `totvs_transaction_id`).

Uma coleção pode ter milhares de registros, e quem lê muitas vezes quer só os primeiros.

## Decisão

- `pagina()` pede uma página e devolve `Pagina`: `numero`, `items`, `hasNext` e os campos a mais em `extras`.
- `colecao()` devolve um gerador que entrega item por item e só pede a página seguinte quando a atual acaba. Para no primeiro `hasNext` falso.
- Sem tamanho informado, o pacote não manda `pageSize`: vale o padrão do endpoint, como o guia manda.
- Os itens saem um a um (`yield $item`), e não com `yield from $pagina->items`, que repetiria as chaves 0, 1, 2... a cada página e faria um `iterator_to_array` sobrescrever a página anterior.
- Cada página passa pelo mesmo caminho das outras chamadas, então o token é renovado entre páginas se vencer no meio da leitura.
- O tamanho da página é conferido na chamada de `colecao()`, fora do gerador: o código de um gerador só roda no primeiro `foreach`, longe de quem errou o argumento.

## Alternativas descartadas

- **Ler tudo e devolver um array:** carrega a coleção inteira na memória e faz todas as requisições mesmo quando quem chama só queria os 10 primeiros.
- **Seguir até uma página vir vazia, ignorando `hasNext`:** custaria uma requisição a mais em toda coleção, e o guia dá o `hasNext` justamente para isso.
- **Mandar sempre um `pageSize` padrão do pacote:** seria escolher pelo endpoint um número que é dele.

## Consequências

- Paginação por número de página não é uma foto: se alguém inserir ou apagar registros durante a leitura, um item pode pular ou repetir. Quem precisa de consistência ordena por uma chave estável e filtra por ela.
- O gerador só pode ser percorrido uma vez. Para ler de novo, chama-se `colecao()` de novo.

## Como é verificado

- `ColecoesTest::test_pagina_manda_page_e_pagesize_e_separa_os_campos_a_mais`
- `ColecoesTest::test_sem_tamanho_vale_o_padrao_do_endpoint`
- `ColecoesTest::test_colecao_percorre_as_paginas_ate_hasnext_falso`, que também confere as chaves de 0 a 4
- `ColecoesTest::test_colecao_so_pede_a_proxima_pagina_quando_precisa`
- `ColecoesTest::test_colecao_vazia_termina_sem_itens`
- `ColecoesTest::test_token_vencido_no_meio_da_colecao_e_renovado_entre_as_paginas`
- `ColecoesTest::test_tamanho_invalido_na_colecao_falha_na_chamada_e_nao_no_foreach`
