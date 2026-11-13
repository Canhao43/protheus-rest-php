# protheus-rest

[![CI](https://github.com/Canhao43/protheus-rest-php/actions/workflows/ci.yml/badge.svg)](https://github.com/Canhao43/protheus-rest-php/actions/workflows/ci.yml)

Cliente PHP para a API REST do TOTVS Protheus: faz o login OAuth2, renova o token sozinho (e pode guardá-lo entre processos), percorre coleções página por página, tenta de novo quando a leitura falha de um jeito passageiro e transforma os erros no formato de API da TOTVS em exceções que dá para tratar.

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
- se uma chamada voltar `401`, renova e repete **uma vez**;
- se uma **leitura** (`GET`) falhar de um jeito passageiro, espera e tenta de novo (abaixo).

As regras, com o motivo de cada uma, estão em [`docs/decisoes`](docs/decisoes/README.md).

## Token entre processos

Em PHP, cada requisição web e cada job da fila é um processo novo, e cada um faria o próprio login. Com um cache PSR-16, o token vale para todos:

```php
$protheus = new ClienteProtheus(
    'https://erp.exemplo.com.br:8080/rest',
    new Credenciais(getenv('PROTHEUS_USUARIO'), getenv('PROTHEUS_SENHA')),
    cache: $cache, // no Laravel: app('cache.store'); no Symfony: new Psr16Cache($pool)
);
```

- Uma entrada por servidor e usuário. A senha nunca vai para o cache.
- **O cache guarda tokens:** quem lê o cache entra no Protheus com esse usuário até o token vencer. Use um cache que só a aplicação lê.
- Se o cache cair, as chamadas seguem; só volta a haver um login por processo.

## Nova tentativa

Uma leitura (`GET`) que fica sem resposta ou volta `429`, `502`, `503` ou `504` é repetida, com espera que dobra a cada vez e um sorteio entre a metade e o teto. Se o servidor mandar `Retry-After`, vale o que ele pediu, até a espera máxima.

```php
use Canhao43\ProtheusRest\Http\Repeticao;

new ClienteProtheus($url, $credenciais, repeticao: new Repeticao(tentativas: 5, esperaInicialMs: 200, esperaMaximaMs: 10_000));
new ClienteProtheus($url, $credenciais, repeticao: Repeticao::desligada());
```

- Padrão: 3 tentativas no total, de 0,5 s a 5 s de espera.
- `POST`, `PUT`, `PATCH` e `DELETE` nunca são repetidos: uma escrita que caiu no meio pode ter sido gravada, e repetir duplicaria no ERP. A decisão fica com quem chama.
- `500` não é repetido: é erro do servidor, e repetir dá o mesmo erro.

## Coleções

Endpoints de coleção no padrão TOTVS recebem `page` e `pageSize` e respondem `{"hasNext": ..., "items": [...]}`.

```php
use Canhao43\ProtheusRest\Colecoes\Consulta;

$consulta = new Consulta(
    filtros: ['ativo' => 'true'],
    ordem: ['nome', '-codigo'],      // "-" na frente: decrescente
    campos: ['codigo', 'nome'],      // só estes campos em cada item
    expandir: ['enderecos'],
);

// Todos os itens, uma página por vez: a próxima só é pedida quando a atual acaba.
foreach ($protheus->colecao('api/exemplo/v1/recursos', $consulta, tamanhoDaPagina: 50) as $item) {
    // ...
}

// Uma página só, para tela com "anterior" e "próxima".
$pagina = $protheus->pagina('api/exemplo/v1/recursos', numero: 2, tamanho: 20, consulta: $consulta);
$pagina->items;    // os itens desta página
$pagina->hasNext;  // há próxima?
$pagina->extras;   // o que mais a API mandou, como "total"
```

- Sem tamanho, vale o padrão do endpoint.
- Se o token vencer no meio da leitura, ele é renovado entre uma página e outra.
- Uma resposta fora do formato, uma página vazia que diz ter próxima ou uma página igual à anterior param a leitura com `RespostaInvalida`, em vez de deixar o `foreach` rodar para sempre.
- Paginação por número não é uma foto: se registros entrarem ou saírem durante a leitura, um item pode pular ou repetir. Para leitura consistente, ordene por uma chave estável.

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

- **Requisição assíncrona (202 com `Location`), HATEOAS e filtro OData montado por objeto:** o guia descreve, mas cada endpoint decide se usa. O `$filter` OData passa como filtro simples: `filtros: ['$filter' => "..."]`.
- **MFA:** o campo `hasMFA` do login é lido e fica em `Token`, mas o fluxo de MFA não está na página pública do token.
- **Basic Auth:** existe no Protheus, mas o TDN o descreve como menos seguro que o token.

## Fontes

Tudo o que o pacote supõe sobre o Protheus vem destas páginas públicas:

- [API para obtenção do token de acesso às API's REST no Protheus](https://tdn.totvs.com/pages/releaseview.action?pageId=465383509) (TDN)
- [Guia de Implementação de API V1.0](https://tdn.totvs.com/pages/releaseview.action?pageId=484701329) (TDN)

Os testes não falam com um Protheus de verdade: usam respostas gravadas no formato dessas páginas, com dados fictícios ([`tests/respostas`](tests/respostas/LEIAME.md)).

## Roteiro

- [x] **M1 — Autenticação e erros:** login OAuth2, renovação, 401, erros no padrão TOTVS.
- [x] **M2 — Coleções:** percorrer páginas (`page`, `pageSize`, `hasNext`, `items`), filtro, ordenação, campos e expansão.
- [x] **M3 — Resiliência:** nova tentativa com espera em falha passageira de leitura, token em cache entre processos.
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
