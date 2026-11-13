# 0003 — Erros no formato do guia da TOTVS, uma exceção por reação e nenhum segredo na mensagem

**Marco:** M1

## Contexto

O "Guia de Implementação de API V1.0" da TOTVS ([pageId=484701329](https://tdn.totvs.com/pages/releaseview.action?pageId=484701329)) define que todo erro 4xx e 5xx traz `code`, `message` (para o usuário, no idioma da requisição) e `detailedMessage` (técnica), e pode trazer `helpUrl` e `details`, uma lista recursiva de erros. Também separa 4xx (erro de negócio ou da requisição) de 5xx (erro não esperado).

Nem todo erro chega nesse formato: um proxy na frente do Protheus devolve HTML. E a mensagem de exceção do Guzzle termina com a URL inteira, que na renovação do token carrega o `refresh_token` na query, como o TDN define.

## Decisão

- Toda exceção do pacote estende `ErroDoProtheus`. Abaixo dela, uma classe por reação de quem chama:
  - `ErroDaApi`: o Protheus respondeu 4xx ou 5xx. Traz o `status` e a `MensagemDeErro`, ou `null` se o corpo não estiver no padrão;
  - `ErroDeAutenticacao`: o endpoint de token recusou (4xx). Conferir usuário e senha;
  - `ErroDeConexao`: não houve resposta. Talvez tentar de novo;
  - `RespostaInvalida`: 2xx com corpo que não é objeto JSON.
- `MensagemDeErro` mantém os nomes da TOTVS e reconhece o formato quando `code` e `message` vêm; o resto entra se vier. `code` numérico vira texto.
- A mensagem da exceção leva status, método, caminho **sem a query** e `[code] message`. Filtro de consulta pode ter dado pessoal, e mensagem de exceção vai para log.
- `ErroDeConexao` usa o motivo do contexto do cURL, mostra a URL sem a query e não encadeia a exceção do Guzzle.

## Alternativas descartadas

- **Uma exceção por status (`NaoEncontrado`, `Proibido`...):** muitas classes para o que um `$erro->status` resolve.
- **Deixar o Guzzle lançar as exceções dele:** quem usa o pacote passaria a depender do Guzzle e ainda teria de ler o corpo da TOTVS sozinho.
- **Exigir `detailedMessage`, como o guia manda:** um erro sem ele continua sendo erro, e descartar a mensagem esconderia o `message`, que é o que interessa ao usuário.
- **Encadear a exceção do Guzzle no `ErroDeConexao`:** o `previous` levaria a URL com o `refresh_token` para o log.

## Consequências

- No erro de conexão, quem chama perde o rastro do Guzzle; fica com o motivo do cURL, o método e o endereço.
- Para depurar a query de uma chamada que falhou, é preciso olhar a chamada, não a mensagem.

## Como é verificado

- `ErrosTest::test_erro_no_padrao_totvs_traz_os_campos_do_guia`
- `ErrosTest::test_erro_fora_do_padrao_tambem_vira_erro_da_api`
- `ErrosTest::test_code_numerico_e_aceito_como_texto`
- `ErrosTest::test_a_mensagem_de_erro_nao_leva_a_query`
- `ErrosTest::test_falha_de_conexao_na_renovacao_nao_vaza_o_refresh_token`
- `ErrosTest::test_sucesso_com_corpo_que_nao_e_json_e_resposta_invalida`
- `AutenticacaoTest::test_login_recusado_lanca_erro_de_autenticacao_sem_tentar_de_novo`
