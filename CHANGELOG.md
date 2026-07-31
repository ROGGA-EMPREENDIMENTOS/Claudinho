# Changelog

## v1.0.0

Sai do `0.x`. Não é uma promessa de estabilidade de API — o pacote ainda está em evolução e
mudança incompatível pode acontecer em minor; leia o changelog antes de subir de versão.

Quem está na v0.2.0 pode subir trocando a constraint para `^1.0`; não há nada a migrar.
Vindo da v0.1.x, os passos da seção abaixo continuam valendo.

### Adicionado

- **Seletor de tema no header** — cicla entre seguindo o sistema → claro → escuro, com a
  escolha no `localStorage`. Um `<script>` inline aplica a classe durante o parse do HTML,
  antes do primeiro paint, para quem escolheu escuro não ver um lampejo claro.
- `tema.alvo` decide onde a classe `dark` entra: `componente` (padrão, só o card do chat —
  funciona mesmo em aplicação sem tema escuro próprio) ou `documento` (o `<html>`, para
  aplicação que já tem tema escuro em todas as telas).
- `tema.seletor => false` esconde o botão para quem já tem seletor próprio, sem desligar o
  tema — quem havia escolhido antes não perde a escolha.

## v0.2.0

### Atualizando da v0.1.x

Quatro passos manuais — sem eles a atualização quebra a instalação:

```bash
composer update rogga/claudinho
php artisan migrate                                          # tabela de configurações
php artisan vendor:publish --tag=claudinho-assets --force     # logo do header
php artisan vendor:publish --tag=claudinho-config --force     # opcional, ver abaixo
```

E no `tailwind.config.js` (ou via `@source` no `app.css`, em Tailwind 4), inclua as views do
pacote no `content`:

```js
'./vendor/rogga/claudinho/resources/views/**/*.blade.php',
```

Isso **já era necessário** na v0.1.x — as classes arbitrárias (`min-h-[24rem]`, `max-h-[60vh]`)
nunca eram compiladas sem isso — mas não estava documentado. Agora que o tema escuro e o
modal dependem de mais classes, a falta fica visível.

Republicar o config é opcional: `mergeConfigFrom` entrega as chaves novas
(`permissao_admin`, `modelos`, `titulo`, `placeholder_vazio`, `logo`) a partir do pacote,
então uma cópia publicada antiga continua funcionando. Republique só se quiser os comentários
novos no arquivo.

### Mudança de padrão

- O modelo padrão passou de `claude-opus-5` para `claude-sonnet-5`. Quem define
  `ANTHROPIC_MODEL` no `.env` não é afetado. Vale saber que o prefixo mínimo cacheável no
  Sonnet 5 é 1024 tokens contra 512 no Opus 5 — abaixo disso o cache não é criado e não há
  erro, o sintoma é `cache_read_input_tokens` sempre em zero.

### Adicionado

- **Configuração em tela** — engrenagem no header abre um modal para trocar modelo e chave
  da API sem deploy, atrás do gate `permissao_admin` (padrão `claudinho_admin`). A chave vai
  criptografada com a `APP_KEY` e nunca volta para o navegador: o campo é só de escrita e o
  que a tela mostra é uma máscara. O que foi gravado vence o `config`/`.env`; valor vazio
  cai no `.env` de novo.
- **Tema claro e escuro** em todo o componente — container, bolhas, textarea, botões,
  markdown (`dark:prose-invert`) e os rótulos do gráfico. Funciona com `darkMode: 'media'`
  e `'class'`, sem configuração extra.
- **Logo no header** — quatro PNGs publicados em `public/vendor/claudinho`: ícone no mobile,
  assinatura horizontal de `sm` para cima, em versão clara e escura. `'logo' => false`
  desliga.
- **Indicador de digitação animado** — o cursor `▌` deu lugar à marca em SVG inline, com os
  olhos piscando e as antenas se movendo. Respeita `prefers-reduced-motion`.
- **Auto-scroll no chat** — acompanha mensagem nova e token de streaming, mas para enquanto
  o usuário está lendo o histórico mais acima. Enviar volta ao fim.
- **Botão enviar só com ícone**, com `aria-label` como nome acessível.
- `titulo`, `placeholder_vazio` e `logo` no arquivo de config — as duas primeiras já eram
  lidas pela view, mas não existiam no config publicado.
- `phpunit.xml`, que faltava: o `./vendor/bin/pest` documentado no README não rodava.

### Corrigido

- `app.key` no `TestCase` — qualquer render de Livewire estourava com
  `MissingAppKeyException`. Nenhum teste renderizava a view antes, então ninguém tinha
  batido nisso.
- `allow-plugins.pestphp/pest-plugin` no `composer.json` — o `composer install` não
  interativo abortava.

### Notas de teste

Os 13 testes de `ConfiguracoesTest` exigem a extensão `pdo_sqlite` para o banco em memória
do testbench. Sem ela eles pulam com a mensagem explicando o motivo, em vez de falhar.

## v0.1.2

- Remove a versão fixa do `composer.json`.

## v0.1.1

- Expõe a versão instalada do pacote.

## v0.1.0

- Versão inicial.
