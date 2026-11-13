# 0007 — A consulta usa os parâmetros do guia e recusa o que mudaria a URL por baixo

**Marco:** M2

## Contexto

O guia de API da TOTVS ([pageId=484701329](https://tdn.totvs.com/pages/releaseview.action?pageId=484701329)) define os parâmetros de uma consulta:

- filtro simples como `propriedade=valor` ("Filtros simples");
- `order` com campos separados por vírgula, e `-` na frente para decrescente ("Ordenação");
- `fields` com os campos que voltam ("Filtro de campos da resposta");
- `expand` com as propriedades a expandir, e ponto para subnível ("Parâmetros expansíveis").

Montar isso à mão em cada chamada é fácil de errar: um filtro chamado `page` sobrescreve a paginação, e um nome de campo com vírgula vira dois campos.

## Decisão

- `Consulta` recebe `filtros`, `ordem`, `campos` e `expandir` por argumento nomeado e gera a query com os nomes do guia.
- Um filtro com nome reservado (`page`, `pageSize`, `order`, `fields`, `expand`) é recusado com `InvalidArgumentException`.
- Nome de campo vazio ou com vírgula é recusado. Na ordem, o `-` da frente é o sinal e não entra na conferência do nome.
- Lista vazia não manda o parâmetro.
- O filtro OData (`$filter`), que o guia trata como opcional, passa como um filtro simples qualquer: `filtros: ['$filter' => "..."]`.

## Alternativas descartadas

- **Construtor fluente (`->ordenarPor()->campos()`):** mais métodos para o mesmo resultado. Argumento nomeado do PHP 8 já deixa a chamada legível.
- **Juntar arrays com vírgula dentro do `get()`:** mágica escondida num método que também serve para chamadas que não são coleção.
- **Montar o OData com objetos:** o guia o chama de opcional, e cada endpoint aceita o que quiser dele.

## Consequências

- Erro de quem chama aparece na hora, como `InvalidArgumentException`, e não como resposta estranha do servidor.
- Um endpoint que tenha mesmo um filtro chamado `order` ou `page` não pode ser filtrado pela `Consulta`. Pelo guia, esses nomes são da consulta.

## Como é verificado

- `ColecoesTest::test_consulta_vira_filtros_order_fields_e_expand`
- `ColecoesTest::test_consulta_vazia_nao_manda_parametro_nenhum`
- `ColecoesTest::test_filtro_odata_passa_como_filtro_simples`
- `ColecoesTest::test_consulta_recusa_o_que_mudaria_a_url_por_baixo`
- `ColecoesTest::test_numero_e_tamanho_comecam_em_1`
