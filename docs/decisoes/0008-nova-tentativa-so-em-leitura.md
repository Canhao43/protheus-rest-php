# 0008 — Nova tentativa só em leitura, só em falha passageira, com espera crescente

**Marco:** M3

## Contexto

Entre o PHP e o Protheus há rede, proxy e um servidor que às vezes está ocupado. O guia de API da TOTVS ([pageId=484701329](https://tdn.totvs.com/pages/releaseview.action?pageId=484701329), "Código 4xx versus 5xx") dá o `503` como exemplo de erro não esperado: *"o servidor não pode responder por que estava sobrecarregado"*. Esse tipo de falha costuma passar em segundos.

Repetir uma chamada, porém, só é seguro se repetir não mudar o resultado. O mesmo guia ("Métodos suportados") marca `POST` e `PATCH` como não idempotentes. E uma falha de conexão no meio do caminho não diz se o servidor gravou ou não.

## Decisão

- Só `GET` é repetido. `POST`, `PUT`, `PATCH` e `DELETE` falham na primeira.
- Só falha passageira: sem resposta (`ErroDeConexao`), `429` (RFC 6585), `502`, `503` e `504`. O `500` não entra: é erro do servidor, e repetir dá o mesmo erro.
- A espera dobra a cada tentativa, até um teto, e é sorteada entre a metade e o teto, para vários clientes que falharam juntos não voltarem juntos.
- Se a resposta trouxer `Retry-After` (RFC 9110), em segundos ou em data HTTP, vale o que o servidor pediu. Se ele pedir mais do que a espera máxima, o pacote desiste na hora, em vez de repetir antes da hora ou prender o processo.
- Padrão: 3 tentativas no total, espera inicial de 0,5 s e máxima de 5 s. `Repeticao::desligada()` desliga.
- A repetição fica por dentro do tratamento do `401` ([0002](0002-ciclo-do-token.md)): a chamada refeita com o token novo também é repetida se falhar de um jeito passageiro.
- O login e a renovação do token (`POST`) não são repetidos.

## Alternativas descartadas

- **Repetir todo método que o guia marca como idempotente (`PUT` e `DELETE` também):** um `DELETE` que deu `503` depois de apagar voltaria `404` na repetição, e quem chama leria "não existe" onde houve sucesso. Quem conhece o endpoint pode repetir por conta própria.
- **Repetir `POST` quando a conexão nem abriu:** separar "não conectou" de "caiu no meio" depende do código de erro do cURL, e errar duplica um pedido no ERP.
- **Espera fixa:** com o servidor sobrecarregado, todos os clientes voltariam no mesmo ritmo e manteriam a sobrecarga.
- **O middleware de repetição do Guzzle:** decide antes de o pacote ler o corpo e o status no padrão TOTVS, e a espera dele não dá para medir no teste sem dormir de verdade.

## Consequências

- Uma leitura pode levar alguns segundos a mais antes de falhar. Com o padrão, sem `Retry-After`, são no máximo 0,5 + 1 s de espera; com `Retry-After`, até 5 s por espera.
- Escrita que falha de um jeito passageiro volta para quem chama decidir.
- A espera é injetável (`dormir`), e os testes anotam cada espera em vez de dormir.

## Como é verificado

- `RepeticaoTest::test_get_com_503_espera_e_repete`
- `RepeticaoTest::test_falha_de_conexao_repete_com_espera_que_dobra`
- `RepeticaoTest::test_desiste_no_limite_de_tentativas_com_o_ultimo_erro`
- `RepeticaoTest::test_chamada_que_escreve_nao_repete`, com POST, PUT, PATCH e DELETE
- `RepeticaoTest::test_post_sem_conexao_nao_repete`
- `RepeticaoTest::test_login_com_503_nao_repete`
- `RepeticaoTest::test_erro_que_nao_passa_sozinho_nao_repete`, com 500, 400, 403 e 404
- `RepeticaoTest::test_429_com_retry_after_em_segundos_espera_o_que_o_servidor_pediu`
- `RepeticaoTest::test_retry_after_em_data_http_conta_a_partir_do_relogio`
- `RepeticaoTest::test_retry_after_maior_que_a_espera_maxima_desiste_na_hora`
- `RepeticaoTest::test_a_espera_nunca_passa_da_maxima`
- `RepeticaoTest::test_a_espera_nunca_e_menor_que_metade_do_teto`
- `RepeticaoTest::test_401_depois_de_uma_repeticao_ainda_renova_o_token`
- `RepeticaoTest::test_a_chamada_depois_do_401_tambem_repete_em_falha_passageira`
