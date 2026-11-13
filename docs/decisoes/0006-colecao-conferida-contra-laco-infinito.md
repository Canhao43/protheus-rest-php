# 0006 — A resposta de coleção é conferida, e o gerador para em vez de girar para sempre

**Marco:** M2

## Contexto

O gerador do [0005](0005-colecao-como-gerador-preguicoso.md) depende de duas coisas que vêm do servidor: o `hasNext` e o respeito ao parâmetro `page`. Um endpoint fora do guia pode quebrar as duas:

- mandar `hasNext` verdadeiro com a página vazia, para sempre;
- ignorar o `page` e devolver sempre a primeira página com `hasNext` verdadeiro.

Nos dois casos, um `foreach` sobre a coleção nunca termina, e quem chama descobre pelo servidor sobrecarregado ou pelo processo travado.

## Decisão

- `Pagina` só aceita o formato do guia: `hasNext` booleano e `items` em lista. Qualquer outra coisa é `RespostaInvalida`, com o número da página e o caminho.
- Página vazia com `hasNext` verdadeiro para a coleção com `RespostaInvalida`.
- Página com os mesmos itens da anterior para a coleção com `RespostaInvalida`, **antes** de entregar os itens repetidos.

## Alternativas descartadas

- **Aceitar `hasNext` em texto (`"true"`) ou a lista solta sem envelope:** o guia mostra booleano e objeto. Adivinhar formato é o pacote supor o que a documentação não diz.
- **Um limite fixo de páginas:** cortaria em silêncio uma coleção grande de verdade, e o número seria arbitrário.
- **Guardar os ids já vistos para achar repetição:** o pacote não sabe qual campo é o id de cada endpoint, e a memória cresceria com a coleção. Comparar só com a página anterior pega o caso do `page` ignorado com custo de uma página.

## Consequências

- Uma coleção com duas páginas seguidas idênticas de verdade seria recusada. Com registros que têm chave, isso não acontece.
- Um servidor que ignora o `page` e responde páginas diferentes por outro motivo (dado mudando) não é pego. O caso que trava, o da mesma página para sempre, é.

## Como é verificado

- `ColecoesTest::test_resposta_fora_do_formato_de_colecao_e_resposta_invalida`, com cinco formatos errados
- `ColecoesTest::test_pagina_vazia_que_diz_ter_proxima_para_a_colecao`
- `ColecoesTest::test_pagina_repetida_para_a_colecao`, que também confere que os itens repetidos não saem
