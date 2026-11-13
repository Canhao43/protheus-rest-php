# protheus-rest

[![CI](https://github.com/Canhao43/protheus-rest-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Canhao43/protheus-rest-php/actions/workflows/ci.yml)

Cliente PHP para a API REST do TOTVS Protheus: faz o login OAuth2, renova o token sozinho e transforma os erros no formato de API da TOTVS em exceções que dá para tratar.

> Construído com agentes de IA (Claude Code) e revisado por mim em cada PR, **só a partir da documentação pública da TOTVS**. As regras que os agentes seguem estão em [`CLAUDE.md`](CLAUDE.md).

## Uso

```php
use Canhao43\ProtheusRest\ClienteProtheus;
use Canhao43\ProtheusRest\Credenciais;

$protheus = new ClienteProtheus(
    'https://erp.exemplo.com.br:8080/rest',
    new Credenciais(getenv('PROTHEUS_USUARIO'), getenv('PROTHEUS_SENHA')),
);

$recurso = $protheus->get('api/exemplo/v1/recursos/1');
$criado = $protheus->post('api/exemplo/v1/recursos', ['nome' => 'Exemplo']);
$protheus->delete('api/exemplo/v1/recursos/1');
```

Os caminhos são relativos à URL base do REST. `get`, `post`, `put`, `patch` e `delete` devolvem o JSON da resposta como array.

O login acontece na primeira chamada. Depois disso, o pacote:

- manda `Authorization: Bearer` em cada chamada;
- renova o token um minuto antes de vencer, pelo `refresh_token`, e só volta a usar a senha se o refresh for recusado;
- se uma chamada voltar `401`, renova e repete **uma vez**.

As regras, com o motivo de cada uma, estão em [`docs/decisoes`](docs/decisoes/README.md).

## Erros

Toda exceção estende `ErroDoProtheus`.

| Exceção | Quando | O que ela traz |
|---|---|---|
| `ErroDaApi` | O Protheus respondeu 4xx ou 5xx | `status` e `mensagemDeErro` (`code`, `message`, `detailedMessage`, `helpUrl`, `details`), ou `null` se o corpo não estiver no padrão |
| `ErroDeAutenticacao` | O login foi recusado | O mesmo que `ErroDaApi`, de quem ela é filha |
| `ErroDeConexao` | Não houve resposta: rede, DNS, certificado, tempo esgotado | O motivo e o endereço, sem a query |
| `RespostaInvalida` | Sucesso, mas o corpo não é um objeto JSON | A mensagem |

```php
use Canhao43\ProtheusRest\Erros\ErroDaApi;

try {
    $protheus->get('api/exemplo/v1/recursos/42');
} catch (ErroDaApi $erro) {
    if ($erro->status === 404) {
        // $erro->mensagemDeErro?->message é o texto para o usuário, no padrão TOTVS
    }
}
```

## Segurança

- Usuário e senha vão em cabeçalho, só no login, como o TDN define. Nunca na URL.
- Senha e tokens não aparecem em `var_dump`, `print_r` nem em mensagem de exceção. A mensagem também não leva a query da chamada.
- Use `https`. O TDN recomenda fortemente para o endpoint de token, porque a senha passa por ele.

## O que ainda não cobre

- **Paginação e cache do token entre processos:** estão no roteiro abaixo.
- **MFA:** o campo `hasMFA` do login é lido e fica em `Token`, mas o fluxo de MFA não está na página pública do token.
- **Basic Auth:** existe no Protheus, mas o TDN o descreve como menos seguro que o token.

## Fontes

Tudo o que o pacote supõe sobre o Protheus vem destas páginas públicas:

- [API para obtenção do token de acesso às API's REST no Protheus](https://tdn.totvs.com/pages/releaseview.action?pageId=465383509) (TDN)
- [Guia de Implementação de API V1.0](https://tdn.totvs.com/pages/releaseview.action?pageId=484701329) (TDN)

Os testes não falam com um Protheus de verdade: usam respostas gravadas no formato dessas páginas, com dados fictícios ([`tests/respostas`](tests/respostas/LEIAME.md)).

## Roteiro

- [x] **M1 — Autenticação e erros:** login OAuth2, renovação, 401, erros no padrão TOTVS.
- [ ] **M2 — Coleções:** percorrer páginas (`page`, `pageSize`, `hasNext`, `items`), ordenação e filtro de campos.
- [ ] **M3 — Resiliência:** nova tentativa com espera em falha de rede e 5xx de leitura, token em cache entre processos.
- [ ] **M4 — Publicação:** Packagist e exemplos.

## Desenvolvimento

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint --test
```

O CI roda os dois em PHP 8.2, 8.3 e 8.4.

## Licença

MIT
