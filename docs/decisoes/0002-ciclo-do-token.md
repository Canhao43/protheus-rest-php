# 0002 — O token é renovado um minuto antes de vencer, pelo refresh primeiro, e um 401 repete a chamada uma vez

**Marco:** M1

## Contexto

Segundo o TDN ([pageId=465383509](https://tdn.totvs.com/pages/releaseview.action?pageId=465383509)):

- o access token vale uma hora e o refresh token, 24 horas; nenhum dos dois prazos é configurável;
- a renovação é `POST {base}/api/oauth2/v1/token?grant_type=refresh_token&refresh_token=...` e renova os dois;
- com o refresh vencido, só resta o login com usuário e senha;
- com o access token vencido, a API responde `401` *"como se não houvesse sido enviado um token"*.

## Decisão

- O vencimento é calculado na chegada do token, com o `expires_in` da resposta, e não com uma hora fixa.
- Antes de cada chamada, se faltar menos de 60 segundos para vencer, o token é renovado. A margem cobre diferença de relógio com o servidor e o tempo da própria requisição.
- A renovação tenta o `refresh_token` primeiro, para não trafegar a senha de novo. Se ele for recusado (4xx), faz o login com usuário e senha. Se o servidor falhar (5xx), o erro sobe: senha não conserta servidor.
- Se uma chamada voltar `401`, o token é renovado e a chamada é repetida **uma vez**. Um segundo `401` sobe como erro.
- Login recusado não é repetido.
- O token vive em memória, na instância do `ClienteProtheus`.

## Alternativas descartadas

- **Só reagir ao 401:** custaria uma chamada perdida a cada hora. A renovação antecipada evita o caso comum; o 401 cobre o que o relógio não vê (servidor reiniciado, token revogado).
- **Guardar as 24 h do refresh e ir direto para a senha depois disso:** seria fixar no código um prazo que é do servidor. Tentar o refresh e cair para a senha se ajusta sozinho, ao custo de uma requisição por dia.
- **Repetir no 401 sem limite:** com a senha trocada ou o usuário bloqueado, viraria laço.
- **Não repetir POST no 401:** o guia da TOTVS ([pageId=484701329](https://tdn.totvs.com/pages/releaseview.action?pageId=484701329), "Código 4xx versus 5xx") define o 401 como chamada de quem *"não estava devidamente autenticado"*. Nada foi processado, então repetir não duplica nada.

## Consequências

- Cada processo PHP novo faz um login. Guardar o token entre processos (cache) fica para um marco seguinte.
- `hasMFA` (que o TDN marca como presente a partir do release 20241007) é lido e exposto no `Token`, mas o pacote não implementa o fluxo de MFA: a página não o descreve.

## Como é verificado

- `AutenticacaoTest::test_renova_pelo_refresh_token_um_minuto_antes_de_vencer`, que ainda usa o token no segundo 3539 e renova no 3540
- `AutenticacaoTest::test_o_token_e_reaproveitado_enquanto_vale`
- `AutenticacaoTest::test_refresh_recusado_cai_para_o_login_com_senha`
- `AutenticacaoTest::test_erro_do_servidor_na_renovacao_nao_vira_tentativa_de_senha`
- `AutenticacaoTest::test_sem_refresh_token_na_resposta_a_renovacao_e_pelo_login`
- `AutenticacaoTest::test_401_da_api_renova_o_token_e_repete_a_chamada_uma_vez`
- `AutenticacaoTest::test_401_de_novo_depois_de_renovar_sobe_sem_laco`
- `AutenticacaoTest::test_outro_erro_da_api_nao_renova_o_token`
- `AutenticacaoTest::test_login_recusado_lanca_erro_de_autenticacao_sem_tentar_de_novo`
