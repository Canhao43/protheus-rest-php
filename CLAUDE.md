# Como agentes trabalham neste repositório

Este projeto é construído com agentes de IA (Claude Code) e revisado por mim, Caio. As regras abaixo valem para qualquer agente que mexa aqui.

## O critério

**Eu preciso saber explicar cada decisão.** Código que funciona, mas que eu não consigo defender numa conversa técnica, não entra.

## De onde vem o conhecimento

- **Só da documentação pública da TOTVS** (TDN e Central de Atendimento) e de padrões abertos (OAuth 2.0, HTTP). Toda afirmação sobre o comportamento do Protheus aponta a página pública de onde saiu, no ADR ou no comentário.
- **Nada do meu trabalho.** Nenhum endpoint, regra, campo, nome de cliente ou detalhe de ambiente que eu conheça por trabalhar com Protheus entra aqui.
- Se a documentação pública não diz, o pacote não supõe: deixa configurável ou diz no README que não cobre.

## Fluxo

- Um marco do roteiro = uma branch = um PR. Nada vai direto para o `main`.
- Commits pequenos e lógicos, em português, com mensagem curta e direta.
- Descrição de PR resumida: o que entra, em poucos tópicos.
- Commits e PRs sem trailer ou rodapé automático (`Co-Authored-By`, link de sessão, "Generated with").
- Quem mergeia sou eu, depois de ler.

## Código

- Domínio em português (credenciais, página, tenant); nomes da API da TOTVS ficam como a TOTVS escreve (`hasNext`, `items`, `access_token`).
- Teste junto com a funcionalidade, nunca depois. `vendor/bin/phpunit` e `vendor/bin/pint --test` precisam passar.
- Nenhum teste fala com a internet nem com um Protheus de verdade: as respostas são gravadas, com o formato da documentação e dados fictícios.
- Comentário explica o porquê, não o quê.
- Decisão de arquitetura vira ADR em `docs/decisoes`, com a prova em "Como é verificado".

## Não fazer

- Instalar pacote ou ferramenta sem dizer por quê no PR.
- Guardar usuário, senha ou token em código, teste ou log.
