# 0001 — Usuário e senha vão em cabeçalho, como o texto do TDN define

**Marco:** M1

## Contexto

A página do TDN sobre o token ([pageId=465383509](https://tdn.totvs.com/pages/releaseview.action?pageId=465383509)) diz que os parâmetros do login *"são trafegados via http headers"*: `grant_type=password` vai na query e `username` e `password` vão em cabeçalho.

A mesma página traz um exemplo em ADVPL que manda tudo na URL: `/api/oauth2/v1/token?grant_type=password&username=user1&password=1234`. As duas formas aparecem na documentação.

## Decisão

- O login é `POST {base}/api/oauth2/v1/token?grant_type=password`, com `username` e `password` em cabeçalho e sem corpo.
- A senha fica em `Credenciais`, marcada com `#[\SensitiveParameter]` (some do rastro de erro do PHP) e escondida no `__debugInfo` (some do `var_dump` e do `print_r`). O token recebe o mesmo cuidado.

## Alternativas descartadas

- **Senha na query, como no exemplo ADVPL:** a URL vai para o log de acesso do servidor, do proxy e para a mensagem de exceção do Guzzle. O texto da página dá o cabeçalho como a forma oficial.
- **Corpo `application/x-www-form-urlencoded`, como a RFC 6749 (OAuth 2.0) define para esse fluxo:** o TDN não documenta corpo nesse endpoint, e o pacote não supõe o que a documentação não diz.

## Consequências

- Um servidor que só lesse a query recusaria o login. A documentação não descreve esse caso; se aparecer, vira ADR novo.
- O pacote não bloqueia `http://`. O TDN *"recomenda fortemente"* https para esse endpoint, mas não o exige; o README avisa.

## Como é verificado

- `AutenticacaoTest::test_o_login_manda_usuario_e_senha_em_cabecalho_e_nunca_na_url`
- `ErrosTest::test_senha_e_tokens_nao_aparecem_ao_inspecionar_os_objetos`
