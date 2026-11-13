# 0010 — A primeira versão é 0.1.0, publicada pelo Packagist, com o pacote distribuído conferido no CI

**Marco:** M4

## Contexto

Com o M3, o pacote cobre login, renovação, erros, coleções e nova tentativa. Para alguém instalar com `composer require`, ele precisa estar no Packagist, que lê as tags do GitHub.

Todo o comportamento sobre o Protheus vem da documentação pública da TOTVS e foi testado com respostas gravadas no formato dela. Nenhuma chamada foi feita contra um Protheus de verdade: o único a que o autor tem acesso é o do trabalho, e o pacote não nasce dele ([`CLAUDE.md`](../../CLAUDE.md)).

## Decisão

- A primeira versão é **0.1.0**. Pelo Versionamento Semântico, 0.x é desenvolvimento inicial: a API pode mudar entre versões menores.
- A versão vem só da tag (`v0.1.0`), e não de um campo `version` no `composer.json`, que o Composer desaconselha para pacote com repositório.
- O pacote vai para o Packagist com atualização automática pelo GitHub: cada tag nova vira versão sem passo manual.
- `CHANGELOG.md` no formato Keep a Changelog, com o que entra em cada versão.
- O CI confere o que o Packagist vai ler e entregar:
  - `composer validate --strict`;
  - o `git archive` da versão leva o `src/` e não leva testes, docs, CI, `CLAUDE.md` nem `phpunit.xml`.

## Alternativas descartadas

- **Começar em 1.0.0:** prometeria uma API estável sem uma única chamada a um Protheus real.
- **Não publicar e mandar instalar pelo repositório (`"repositories": [{"type": "vcs"}]`):** quem instala precisaria editar o `composer.json` e não acharia o pacote na busca do Packagist.
- **Automação de versão (release-please, semantic-release):** para um repositório de uma pessoa, uma tag à mão com o CHANGELOG atualizado basta.

## Consequências

- Quem instala com `^0.1` recebe só correções 0.1.x: no Composer, o `^` em 0.x trava a versão menor. Mudança que quebra vai para 0.2.0.
- O 1.0.0 fica para quando houver uso relatado contra um Protheus de verdade, por alguém que tenha um fora do trabalho do autor.
- Publicar é passo do dono da conta: a tag, o envio ao Packagist e a conferência da atualização automática.

## Como é verificado

- `ci.yml`: *Composer válido*
- `ci.yml`: *Pacote distribuído sem testes nem docs*
