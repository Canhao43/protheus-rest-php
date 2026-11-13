# 0009 — O token pode ficar num cache PSR-16, uma entrada por servidor e usuário

**Marco:** M3

## Contexto

Em PHP, cada requisição web e cada job da fila é um processo novo. Com o token só em memória ([0002](0002-ciclo-do-token.md)), cada processo faz um login, e a senha trafega a cada um. O TDN ([pageId=465383509](https://tdn.totvs.com/pages/releaseview.action?pageId=465383509)) recomenda o contrário: renovar pelo refresh token *"sem a necessidade de trafegar a senha novamente"*.

## Decisão

- O `ClienteProtheus` aceita um cache PSR-16 (`psr/simple-cache`), opcional. Sem cache, nada muda.
- A chave é `protheus_rest.token.` mais o SHA-256 da URL base (sem a barra final) e do usuário: um token por servidor e por usuário, só com os caracteres que a PSR-16 garante em qualquer cache, e sem usuário em claro. A senha nunca vai para o cache.
- O valor é um array de tipos simples (`access_token`, `refresh_token`, vencimento em timestamp, `hasMFA`), e não um objeto serializado. Uma entrada que não tenha esse formato é ignorada.
- O token é lido do cache no primeiro uso e gravado a cada login ou renovação, inclusive a que vem de um `401`.
- A entrada vale 24 horas, o prazo que o TDN dá ao refresh token. É só faxina: a lógica não depende disso, porque refresh recusado cai para o login.
- Cache fora do ar não derruba a chamada: a leitura vira "não tinha", e a gravação é ignorada.

## Alternativas descartadas

- **Gravar em arquivo, dentro do pacote:** escolheria pelo projeto onde e com que permissão o token fica. Quem usa Laravel ou Symfony já tem cache PSR-16 configurado.
- **Trava entre processos para renovar um de cada vez:** dois processos que renovam juntos com o mesmo refresh token resolvem sozinhos, porque o segundo, se for recusado, cai para o login. A trava custaria uma dependência a mais.
- **Exigir `psr/simple-cache` 3:** o pacote só chama `get` e `set`, que existem nas versões 1, 2 e 3. Aceitar as três evita conflito com quem ainda está na 1.

## Consequências

- **O cache guarda tokens:** quem lê o cache entra no Protheus com o usuário até o token vencer. O cache precisa ser de uso só da aplicação. Está no README.
- Um cache quebrado em silêncio vira um login por processo, como antes do M3.

## Como é verificado

- `CacheDoTokenTest::test_o_segundo_processo_usa_o_token_do_primeiro_sem_login`
- `CacheDoTokenTest::test_token_vencido_no_cache_e_renovado_pelo_refresh_do_cache`
- `CacheDoTokenTest::test_o_cache_tem_uma_chave_por_servidor_e_usuario_sem_dado_em_claro`
- `CacheDoTokenTest::test_outro_usuario_no_mesmo_cache_faz_o_proprio_login`
- `CacheDoTokenTest::test_a_mesma_url_com_ou_sem_barra_final_divide_o_token`
- `CacheDoTokenTest::test_entrada_estranha_no_cache_e_ignorada`, com seis formatos errados
- `CacheDoTokenTest::test_cache_fora_do_ar_nao_derruba_a_chamada`
- `CacheDoTokenTest::test_o_token_fica_no_cache_por_24_horas`
- `CacheDoTokenTest::test_401_renova_e_grava_o_token_novo_no_cache`
