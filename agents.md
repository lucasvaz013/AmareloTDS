# AGENT.md — AmareloTDS (STG): manual operacional autossuficiente

Este arquivo é **autossuficiente**: um agente/LLM opera **e** mantém o STG AmareloTDS usando só
este documento — **não depende do `CLAUDE.md`**. Para o campo‑a‑campo exaustivo, a fonte
versionada é `docs/en/` (referência EN); a verdade final de comportamento
é sempre o código (`code/…php:linha`). Datas/versões citadas são de validação em 2026‑08‑14.

**O produto.** AmareloTDS é um **Traffic Distribution System** em PHP + SQLite. Por requisição
decide o que devolver: **white** (safe page, tráfego filtrado/bloqueado), **black** (o funil
real) ou **trafficback** (nenhuma campanha casa). A campanha é encontrada **pelo domínio** da
requisição — não há ID/alias na URL. A distribuição inteira mora em `code/` (autocontida).

---

## 1. Guardrails (ler antes de qualquer ação)

1. **Segredos.** `adminPath` (caminho hex do painel), senhas (`adminPassword`) e chaves de API
   (Namecheap, Cloudflare) vivem só no `settings.local.php` de cada máquina (gitignored); o token
   CAPI vive em `campaigns.settings`. **O fork é público** — nada de segredo em arquivo
   versionado. Hook `pre-commit` roda `gitleaks`; verde não é prova (não pega senha curta).
   `code/db/common.json` **é rastreado** → sem segredo ali.
2. **staging‑first.** Todo trabalho vive em `staging`; `production` só recebe **merge** de `staging`, nunca commit direto. Staging à frente de produção é esperado — não "sincronizar" por reflexo.
3. **Deploy é clique, não `push`.** O servidor não tem git; o auto‑updater baixa o zip da branch
   quando alguém clica **atualizar** no painel. Só dispara se `code/admin/version.txt` for
   **bumpado** para valor maior. Bumpar **antes de promover** para produção.
4. **Schema imutável.** `db.sql` roda uma única vez (`code/db/db.php:46`, só quando o `.db` não
   existe). Não há migração. **Dado novo vai para JSON** (`campaigns.settings` / `common.settings`),
   lido com `?? default`. Adicionar coluna SQL quebra silenciosamente toda instalação existente.
5. **`settings.local.php` só como www‑data.** Editar via `sudo -u www-data php …`; um `save()`
   como root recria o arquivo `root:root` e derruba o painel inteiro (tela branca, CSS 404).
6. **Nunca commitar/pushar de dentro do VPS** — lá a pasta admin está renomeada; um commit
   vazaria o endereço secreto no fork público.
7. **Testar as duas suítes, separadas**: `./vendor/bin/phpunit` (engine) **e**
   `./vendor/bin/phpunit tests/application`. Nunca canalizar para `| tail`/`| grep` numa cadeia
   `&&` (o exit code vira o do `tail` e a falha some).
8. **UI, roteamento por domínio e APIs externas** só aparecem no navegador/staging — os testes
   locais não pegam. Abrir a tela mexida na staging antes de promover.
9. **Campanha nova nasce com defaults perigosos do autor** — filtro de país `RU,BG,SG`, um step de
   redirect para `rolltrk.com` e um postback S2S para `eu.roerads.com` (`code/db/default.json`).
   **Trocar os três** ao criar campanha, senão manda tráfego e conversões para um terceiro.

---

## 2. Infraestrutura

Roda em **qualquer VPS Linux** com PHP 8.4‑FPM + nginx. O `install.sh` provisiona nginx, PHP‑FPM, Let's Encrypt/certbot, firewall e swap. Requisito prático: 2+ cores, 2+ GB RAM. Uma ou mais instâncias podem conviver no mesmo host, isoladas por domínio:

| Ambiente | Domínio (exemplo) | Diretório | Branch |
|---|---|---|---|
| produção | `<seu-dominio>` | `/var/www/<seu-dominio>` | `production` |
| staging | `stg.<seu-dominio>` | `/var/www/stg.<seu-dominio>` | `staging` |

- **DNS apontando direto para o IP público do host (sem proxy/CDN na frente)** — o instalador compara o IP resolvido com o IP da máquina, e o certbot precisa alcançar o domínio direto.
- Firewall liberando só **22, 80, 443**; swap recomendado; `certbot.timer` para renovação; `php8.4-fpm`.
- Conferir o que uma instância roda (o `<adminPath>` é o nome hex, lido do `settings.local.php`):
  ```bash
  ssh <user>@<VPS_IP>
  cat /var/www/<seu-dominio>/<adminPath>/version.txt
  ```

**Estado por desenho:** staging carrega as features novas; produção carrega só código validado. O `version.txt` de cada instância é a verdade do que ela roda. Promover é decisão do operador, após validação em uso real — nunca reflexo.

---

## 3. Deploy — não é `git pull`

O servidor **não tem git**. O deploy usa o auto‑updater embutido (`code/admin/autoupdate.php`),
acionado pelo botão **atualizar** do painel. Ao clicar:

1. Lê `code/admin/version.txt` do GitHub via API e compara com a cópia local.
2. Se houver versão maior, cria backup (`BackupManager::create('pre_update')`).
3. Baixa o zipball da branch configurada, extrai em `temp_update/`.
4. Copia por cima da instalação, **pulando dados de produção** (`shouldSkipUpdatePath` exclui
   banco, backups, logs, cache, geobases, `settings.local.php`).
5. Confere se o painel admin ficou completo; se falhou, restaura o backup.

**`push` não faz deploy** — só o clique no painel daquela instância. O download usa a API pública
do GitHub sem token → só funciona com **repositório público**.

### `version.txt` é o gatilho

Formato `DD.MM.YY[.BUILD]` (contador opcional de 1–3 dígitos):

```
05.08.26        primeiro deploy do dia
05.08.26.1      segundo deploy do mesmo dia
06.08.26        dia seguinte, contador zera
```

`convertVersionToTimestamp()` = `timestamp_do_dia * 1000 + build` → ordenação total. Comparação
**estritamente maior**; sem bump, o painel diz "already up to date" e **não baixa nada**. Limite é
**por instância** (compara a cópia local com a branch que ela segue).

> **Armadilha de bootstrap:** o código original do autor exige exatamente 3 partes e lança
> `Invalid version format` ao ver 4 (erro engolido → "está atualizado", nunca atualiza). Numa
> instância ainda rodando o código do autor, o **primeiro** deploy usa versão de 3 partes; só
> depois o contador (4ª parte) pode ser usado.

### Origem do update, por instância

`GITHUB_REPO`/`GITHUB_BRANCH` em `autoupdate.php` são só fallback. O valor real vem do
`settings.local.php` (gitignored, por máquina): `'updateRepo' => 'lucasvaz013/AmareloTDS'`,
`'updateBranch' => 'staging'` (ou `'production'`). Chave nova precisa existir em
`SettingsManager::defaults()` senão o `load()` a descarta. O `install.sh` deste fork usa
`AMARELOTDS_UPDATE_BRANCH` para escolher a branch do ZIP **e** gravar `updateBranch` (padrão
`production`):

```bash
# Produção
curl -fsSL https://raw.githubusercontent.com/lucasvaz013/AmareloTDS/production/code/install.sh \
  | sudo AMARELOTDS_DOMAIN=tds.seudominio.com bash
# Staging (única diferença é a variável)
curl -fsSL https://raw.githubusercontent.com/lucasvaz013/AmareloTDS/staging/code/install.sh \
  | sudo AMARELOTDS_DOMAIN=teste.seudominio.com AMARELOTDS_UPDATE_BRANCH=staging bash
```

---

## 4. Branches e ciclo de trabalho

| Branch | Papel | Regra |
|---|---|---|
| `staging` | desenvolvimento | onde todo trabalho começa |
| `production` | código em produção | só **merge** de `staging`, nunca commit direto |

**Regra de ouro:** nada é publicado sem as duas suítes verdes e varredura de segredos (`gitleaks`); cada commit é uma unidade de rollback — uma mudança lógica, árvore verde. `production` só recebe **merge** de `staging`.

```bash
# 1. Desenvolver + commitar (em staging, sem tocar version.txt)
git checkout staging
php -r 'require "code/db/db.php"; new Db();'                 # evita o flake do UniquenessTest (§5)
./vendor/bin/phpunit && ./vendor/bin/phpunit tests/application && git commit -am "..."
# 2. Publicar
git push origin staging
# 3. Deploy na staging: bump version.txt -> push -> painel staging -> "atualizar"
git commit -am "Release 06.08.26.1" code/admin/version.txt && git push origin staging
# 4. Promover para produção (só após validar): é merge, nunca commit novo
git checkout production && git merge staging && git push origin production && git checkout staging
```

**Varredura de segredos:** hook `pre-commit` roda `gitleaks git --staged` (~46 ms). `.git/hooks/`
não é versionado (clone novo não tem a proteção — reinstalar). O hook **libera o commit se o
gitleaks não estiver no PATH** (só avisa em amarelo → `brew install gitleaks`). Primeira camada é
o `.gitignore`.

---

## 5. Ambiente local e testes

```bash
composer install                        # instala phpunit em vendor/ (gitignored)
./vendor/bin/phpunit                    # engine: 546 testes, ~8s
./vendor/bin/phpunit tests/application  # application: 129 testes
```

- **Duas suítes, rodadas separadas.** O `phpunit.xml` só registra `tests/engine`, então
  `./vendor/bin/phpunit` sozinho ignora os 128 testes de `tests/application` **sem avisar**.
- **Não juntar as suítes.** Três arquivos de `tests/application` (`AdminAccessControlTest`,
  `PluginsTest`, `GeoBasesTest`) sobrescrevem o global `$cloSettings` sem `cachingDir` e derrubam 6
  testes de `FlowsTest` posteriores. É bug **do autor** — não "consertar" juntando no `phpunit.xml`.
- `InstallerScriptTest` fixa trechos **literais** do `install.sh`: toda alteração no instalador
  quebra esse teste e ele precisa ser atualizado junto.
- **Geobases MMDB** (gitignored) são necessárias, senão ~10 testes geo falham:
  ```bash
  cd code/bases
  curl -fsSL https://github.com/sapics/ip-location-db/releases/download/latest/geolite2-country.mmdb -o country.mmdb
  curl -fsSL https://github.com/sapics/ip-location-db/releases/download/latest/origin-asn.mmdb -o asn.mmdb
  ```
- **Flake conhecido:** `UniquenessTest::testTwoConcurrentWritersProduceExactlyOneUniqueClick` falha
  com `table campaigns already exists` quando `code/db/clicks.db` não existe (dois escritores rodam
  `db.sql`; o 2º morre porque `CREATE TABLE campaigns` não tem `IF NOT EXISTS`). Contorno: rodar 2×,
  ou criar o banco antes com `php -r 'require "code/db/db.php"; new Db();'`.
- **Rodar o sistema no Mac** (iterar sem deploy): `cd code && php -S 127.0.0.1:8899 -t .` → painel em
  `http://127.0.0.1:8899/admin/` (redireciona para `login.php`; sem `settings.local.php` valem os
  defaults: caminho `/admin/`, senha `12345qweasd`). Banco local criado na 1ª requisição. Roteamento
  por domínio precisa da staging. Mac roda PHP 8.5 e o servidor 8.4 → 1 deprecation em `TestDb.php` é
  ruído local, não mexer.

---

## 6. Modelo de dados — não existe migração de schema

Sete tabelas em `code/db/db.sql`: `campaigns`, `clicks`, `click_steps`, `conversions`, `blocked`,
`trafficback`, `common`. `db.sql` roda **uma única vez** (`Db::__construct` só chama
`create_new_db()` quando o `.db` não existe, `code/db/db.php:46`); o auto‑updater preserva o banco.
**Atualizar o código nunca atualiza o schema.** Nova coluna SQL quebra silenciosamente instalações
existentes e reexecutar `db.sql` aborta (`CREATE TABLE campaigns` sem `IF NOT EXISTS` dentro de
`BEGIN IMMEDIATE`).

**Dado novo vai para JSON:**

| Escopo | Onde | Compatibilidade |
|---|---|---|
| config de campanha | `campaigns.settings` (JSON) | default em `code/db/default.json`; `$s['x'] ?? default` |
| config global | `common.settings` ou `settings.local.php` | merge recursivo vs `SettingsManager::defaults()` |
| dado por step | `click_steps.events` / `.mvt` | `json_set()`; cada chave imutável após a 1ª escrita |

Se um dia uma coluna real for necessária: script de migração deliberado, idempotente, rodado à mão
nas duas instâncias (único precedente: `tests/tools/update_indexes.php`, que mexe só em índices).

---

## 7. `settings.local.php` — cofre de segredos, acesso e como estender

Formato: PHP que retorna um array (`<?php\n\nreturn <var_export(payload)>;\n`) com `_revision` +
todas as chaves de `defaults()`. Guarda segredos (Namecheap, Cloudflare) e a config por‑máquina
(`adminPath`, `updateBranch`, `adminIp`, `adminDomain`).

Proteção: gitignored, `0640`, legível pelo PHP‑FPM (o painel grava como `www-data`, então fica
`www-data:www-data`; o modo de falha é `root:root`, que tira a leitura do www‑data). Editar por
fora **sempre como www‑data** e preservar `0640`.

**Adicionar chave nova — dois passos obrigatórios (falham em silêncio):**
1. Registrar em `SettingsManager::defaults()` (`code/settings.php`), senão `load()` a descarta no
   `array_intersect_key` e `validate()` a rejeita.
2. Se for **segredo**: entrar em `SettingsManager::MASKED_KEYS` (senão volta em claro ao navegador)
   **e** ganhar a regra "vazio mantém o atual" no `validate()` (senão o 1º save apaga a credencial).

**Acesso ao painel** (`code/admin/accesscontrol.php`): `adminIp` = lista de IPs **exatos**
(sem CIDR), separados por vírgula; vazio = allowlist desligada (o painel segue protegido pelo
caminho hex + senha). Com Cloudflare em DNS‑only, o IP checado é o `REMOTE_ADDR` real → IP dinâmico
trava a cada rotação. `adminDomain` restringe por host. Bloqueado, o painel responde 404 (ou a
mensagem, se Debug ligado).

**Pasta admin renomeada:** o instalador renomeia `admin/` para um nome hex (endereço secreto),
guardado em `adminPath`. O updater resolve em `mapUpdateDestination()`: ao copiar, troca o 1º
segmento `admin/…` pelo `adminPath` ativo. **Não renomear pastas neste repo** — a estrutura aqui é
sempre `code/admin/`.

---

## 8. Crons e Rollback

**Crons** (`/etc/cron.d/`, nomes fixos `amarelotds-currency`, `amarelotds-domains`,
`amarelotds-provision`; `app_dir` embutido no arquivo). As duas instâncias dividem o host → a 2ª
instalação sobrescreve os crons da 1ª. Hoje os três apontam para a staging; produção sem cron. Ao
promover Domains para produção, instalar `refresh_domains`/`provision_domains` à mão lá.
`provision_domains` roda como **root** (nginx + certbot); os demais como `www-data`.

**Rollback — dois procedimentos diferentes.** Reverter no git **não** desfaz o deploy (o servidor
só muda no clique, e a versão publicada precisa ser *maior*):
- **A. Servidor quebrado agora (emergência):** painel → aba **Backups** → restaurar (o auto‑updater
  cria backup antes de cada update; devolve o código anterior **preservando o banco**).
  ```bash
  ssh <user>@<VPS_IP>; ls -1t /var/www/<seu-dominio>/backups/*.bak | head -5
  ```
- **B. Corrigir a linha do tempo (git):** `git revert <sha-ruim>` (revert, não `reset --hard`) →
  rodar as duas suítes → **bumpar `version.txt` para valor MAIOR** que o quebrado → commit → push →
  clicar atualizar. O bump é obrigatório.

---

## 9. Arquitetura interna (request flow + mapa de `code/`)

```
index.php                     — entrada, roteamento
  ├─ directload.php           — Direct Load: serve estático de landings/whites direto
  ├─ tds.php (class Tds)      — roteamento (getAction, getJsAction, getPhpAction, pick_flow_index)
  │    ├─ core.php (FiltrationCore)  — coleta params do clique + matching de filtros
  │    ├─ main.php                   — white(), black(), jscheck(), traficback()
  │    │    ├─ abtest.php (AbTest)   — equal / weighted / Thompson Sampling (Beta, Marsaglia-Tsang)
  │    │    ├─ htmlprocessing.php    — HTML de landings/whites (macros, backfix, rewrite, sanitize)
  │    │    └─ macros.php            — macros ({clickid},{country},{c.*}, …)
  │    └─ campaign.php               — modelos (Campaign, White/Black/Flow/Step/Postback/Statistics…)
  └─ actions.php              — TdsAction/JsAction/PhpAction (html/redirect/error)
```

Outros: `settings.php` (`SettingsManager`), `cookies.php` (userid/clickid/px, dedup), `send.php`
(proxy do form → partner, UTP), `next.php` (passo do funil), `currency.php` (Frankfurter+TCMB),
`requestfunc.php` (get/post cURL). `api/` = `postback.php`, `phpconnect.php`, `events.php`,
`updateparams.php`. `js/` = `index.php` (JS Connect), `detect.js` (BotDetector), `connect.js`,
`replace.js`, `iframe.js`. `bases/` = DeviceDetector, `ipcountry.php` (MaxMind GeoIP2), `iputils.php`.
`plugins/` = currency, vpn (Blackbox/GetIPIntel). `db/` = `db.php`, `db.sql`, `default.json`,
`common.json`, `clicks.db`. `caching/{landings,whites,whites_curl,devices,currency}` (subpastas de
nome fixo; `get_cache_path($sub)`).

Entrypoints públicos: `index.php` (runtime), `js/index.php` (JS Connect), `api/phpconnect.php`
(PHP Connect), `api/postback.php` (postback de entrada), `send.php` (form/lead), `next.php` (step),
`admin/` (painel).

### Features deste fork e como estender (padrão página‑registro)

Construídas neste fork (vivem em `staging`), com o design em plan docs commitados:

| Feature | O que faz | Entrada no código | Design |
|---|---|---|---|
| CAPI | Conversões server‑side p/ Meta | `code/capi.php` | `docs/en/meta-capi.md` |
| Domains | Registra/aponta/provisiona domínios (Namecheap + Cloudflare + nginx) | `code/domains.php`, `code/cron/*_domains.php` | — |
| Postback Gateway | Expõe somente `/api/postback.php` em domínio raiz, com DNS/nginx reconciliados | `code/postbackgateway.php` | `docs/en/postback-gateway.md` |
| Integrations | Cofre + teste de saúde das credenciais externas | `code/integrations.php` | — |
| Landings | Biblioteca de pastas de landing (upload/editar/duplicar/excluir) | `code/landings.php`, `code/admin/landings.php` | — |
| Destinations `{link:N}` | Destino externo por‑landing no step, gravado como snapshot no render | `code/campaign.php`, `code/htmlprocessing.php` | `docs/en/networks-and-destinations.md` |
| Networks + Destinations | Bibliotecas globais (`common.settings`) que alimentam `{link:N}` | `code/networks.php`, `code/destinations.php` | `docs/en/networks-and-destinations.md` |
| Checkout Routes | Experimento ponderado Network→Destinations por step, sticky e congelado no click | `code/checkoutroutes.php`, `code/experiments.php` | `docs/en/networks-and-destinations.md` |
| ytds CLI (fases 0–4) | Operação por agentes — ver **§15** e `docs/en/ytds-cli.md`. Local in‑process (`--env local`) ou remoto via `/api/admin.php` (Bearer `adminApiToken`). Reads + mutação segura (`--dry-run` padrão, `--yes` commita) sobre `campaign`/`networks`/`destinations`/`landing`, pelos MESMOS validators do painel; segredos mascarados. | `bin/ytds` + `cli/`, `code/adminops.php`, `code/api/admin.php` | `docs/en/ytds-cli.md`, `admin-api.md` |

**Padrão "página‑registro":** Integrations, Landings, Networks e Destinations seguem o mesmo
molde; a próxima feature desse tipo deve copiá‑lo, sem inventar uma 2ª convenção: botão em
`code/admin/index.php` → página `code/admin/<nome>.php` (molde do `integrations.php`) → editor
`code/admin/<nome>editor.php` → lógica pura opcional em `code/<nome>.php` (testável, molde do
`landings.php`/`networks.php`) → teste em `tests/engine/`. Registro global mora em
`common.settings`; config por‑campanha em `campaigns.settings`.

---

## 10. Operar o TDS — ferramentas

Campo‑a‑campo em `docs/en/`; a campanha é achada pelo domínio.
**Fluxo:** criar → **Domains** → **Safe Page** (filtros+método) → **Flows** e **Steps** → **Conversions/Postbacks** → salvar; trocar os 3 defaults do autor (Guardrail #9).

- **Domains** — amarra domínio↔campanha; o link do anúncio é `https://SEU-DOMINIO/` + seus params (sem gerador). Wildcard só `*.dom.com`; **sobreposição bloqueia o save**; path ignorado (só o host roteia); `?cpc=0.05` vira custo do clique (não use `cpc` como sub‑id).
- **Safe Page / White** — pré‑filtro que roda ANTES de tudo (casou → white + aba Blocked). Métodos `folder`, `redirect` (302/307, **nunca 301**), `curl` (proxy 1‑página), `error`. **Filtro vazio = todo mundo vê o white = campanha desligada** (`no-filters`). Escopo Global/Domain.
- **Flows** — rotas do black, **first‑match** (sem split % entre flows; flow de filtro vazio = catch‑all e sombreia os de baixo). Cada flow tem funil + Distribution (`equal`/`weighted`/`thompson`; `optimize_for` Lead/Purchase; `optimize_mode` funnels/separate). A/B mora **dentro do step**.
- **Steps** — pressel→landing→oferta; `{next}`/`{offer}` avançam via `next.php`. **O caminho inteiro é sorteado no 1º pageview** e gravado em `clicks.path` (trocar landings depois mata clicks em voo). Só `folder`/`redirect` (curl/error são white‑only); `{next}` não substitui no último step; `target="_blank"` removido em steps não‑finais. **MVT** (`#TEST1#`) sempre uniforme, imutável após salvar. Checkout Routes fazem roll ponderado separado; no máximo um step roteado por flow.
- **Landings** — pastas `caching/landings/<pasta>` (upload ZIP/editar/duplicar/excluir); modo `base` (nginx direto) ou `direct` (`/__dl/<clickid>/<step>/`).
- **Conversions** — status (`Lead`/`Purchase`/`Reject`/`Trash` + custom com aliases); dedup por tid no namespace `(campanha, parâmetro, tid)` → **um** param de tid por postback; **Cap** é filtro de flow (não campo aqui), corte do dia pelo Campaign timezone. Atalhos sem postback: Form submission (`send.php`) e `ytdsConversion(status)`.
- **Events** — micro‑interações por `clickid+step` (`click_steps.events`); **não** viram conversão/payout/CAPI. Custom: `^[a-z][a-z0-9_]{0,63}$`.
- **Postback in / S2S out** — entrada `/api/postback.php?clickid=…&status=…&payout=…&tid=…` (key protection opcional); parceiro que rejeita subdomínio usa o Postback Gateway isolado (`docs/en/postback-gateway.md`); saída CAPI server‑side p/ Meta (`code/capi.php`, só Purchase/Lead mapeáveis, Purchase exige `payout>0`), **máx. 5** postbacks S2S.
- **Integration / Connect** — PHP Connect por API key (`/api/phpconnect.php`, UA precisa conter `AmareloTDS` senão 404); JS Connect por domínio (`/js/`, domínio precisa estar em Domains). API key é `readonly`; vazou → duplicar campanha.
- **Scripts** — Backfix, Next/Form‑Submit Redirect, lazy‑load. **Sem** campo de HTML/JS livre (pixel vai no ZIP da landing).
- **Misc / Statistics** — Uniqueness counting (não desliga enquanto algum flow usar a regra) + Campaign timezone; relatórios com colunas custom e `group by` por `param.<nome>` (`clicks.params`, sem whitelist); venda por variante na aba Statistics do flow (`click_steps.variant` ⋈ `clicks.status`).
- **`{link:N}` e site tracking** — ver §13 e §14.

### Macros
- **HTML da landing:** só `{clickid}`, `{userid}`, `{px}` (qualquer posição); `{next}`/`{offer}` avançam o step (não no último).
- **URL de redirect/S2S:** macro só substitui se for o **valor inteiro** de um query param (`?cid={clickid}` sim; `?cid=pre-{clickid}` e `/track/{clickid}/` literais). `{c.NOME}` = param da entrada; `{domain}` = host.

---

## 11. Verificação (o que é "done")

- **Código**: as duas suítes verdes — `./vendor/bin/phpunit` (546) **e**
  `./vendor/bin/phpunit tests/application` (129). Só deprecations do PHP 8.5 são aceitáveis.
- **UI / rota por domínio / API externa**: exercido no navegador ou na staging, não só teste local.
- **Deploy**: `version.txt` bumpado para valor maior, "atualizar" clicado, e `version.txt` do
  servidor conferido por SSH.
- **Campanha nova**: filtro `RU,BG,SG` e o redirect+postback do autor (`rolltrk.com`/`eu.roerads.com`)
  substituídos (Guardrail #9).

---

## 12. O que nunca chega ao GitHub

`shouldSkipUpdatePath()` protege no deploy e o `.gitignore` no commit: `settings.local.php`,
`code/db/*.db`, `code/backups/`, `code/logs/`, `code/ycclogs/`, `code/tmp/`, `code/caching/*`,
`code/bases/*.mmdb`. **O GitHub é backup do código, não do sistema** — campanhas, cliques,
estatísticas e config vivem só no VPS (backup próprio via `backupmanager.php` → `code/backups`).

---

## 13. Networks + Destinations `{link:N}` (invariantes)

Dois andares em JSON, sem coluna SQL. Campo‑a‑campo em `docs/en/networks-and-destinations.md`.

- **Andar 1 — bibliotecas globais** (`common.settings`; páginas `code/admin/networks.php`/`destinations.php`): Network = nome + query reutilizável (`?`/`&` inicial stripado, `Network::normalizeParams`); Destination = nome + `base_url` (sem scheme → `https://`) + `network_id` opcional; URL efetiva = `Destination::compose`. Network órfã → destino degrada para **só o base** (não quebra o save). Destinos são **por instalação**, não por campanha.
- **Andar 2 — snapshot no step** (`code/admin/js/flows/links.js` → `campaigns.settings.…folders[].links`): o dropdown **copia a URL efetiva** para a string do step; **editar/apagar o destino global depois NÃO muda campanha salva** (snapshot, não referência). `N` é explícito e estável (apagar `{link:1}` não renumera `{link:2}`). Cap 20/folder; `n<1`, `n` duplicado ou URL sem `http(s)` bloqueiam o save (`normalize_links_input`). `links: []` = checkout literal no HTML (não misturar as duas formas no mesmo `href`).
- **Checkout Routes** (`StepSettings.checkout_routes`): rotas ponderadas escolhem uma Network + slots `N→destination_id`; todas têm os mesmos slots e cada destino pertence à Network. O roll é independente do A/B de landing, sticky, e congela id/nome da Network + URLs compostas em `clicks.params._ytds_*`. Refresh/Direct Load leem o snapshot; biblioteca muda só clicks futuros. Rota ativa torna o editor legado read‑only; excluir Network/Destination referenciada retorna `RESOURCE_IN_USE`.
- **Resolução no HTML** (`htmlprocessing.php:218-223`): `{link:N}` resolve **depois do MVT, antes das macros `{clickid}`**; sem mapa → `#` + log `Unmapped {link:N}`, nunca vaza o token. Macro na URL de destino só substitui se for o **valor inteiro** de um query param (`?sub1={clickid}` e `?utm={c.utm_source}` sim; `?ref=user-{clickid}` e `/track/{clickid}/` ficam literais). No HTML cru fora de URL de destino, só `{clickid}`/`{userid}`/`{px}`.

---

## 14. Pixel, CAPI e site tracking (invariantes)

Diagramas e detalhe em `docs/en/meta-capi.md`.

- **PageView é do browser:** o TDS **não** injeta o Meta Pixel — o snippet (`fbq('init'…)` + `PageView` + noscript) vai no ZIP/`index.html` da landing; dispara depois do 302 `/__dl/…` (modo `direct`), fora do postback.
- **CAPI depois do postback:** rede → `/api/postback.php` → `ConversionService::record` (resolve aliases da campanha) → INSERT `conversions` + UPDATE `clicks.status` → `process_capi_conversion` (mapa status TDS→evento Meta; Purchase exige `payout>0`, `EVENTS_REQUIRING_VALUE`) → `POST graph.facebook.com/v25.0/{pixel}/events`. `event_id = sha1(clickid|eventName|tid)` deduplica retry no Meta (48 h); `fbc` só nasce com `clicks.params.fbclid`.
- **Params só entram por GET real no host da campanha:** PHP Connect (`/api/phpconnect.php`) tem `QUERY_STRING` vazio e **não** grava params. O 302 para `/__dl/<clickid>/0/` **não leva a query** → `sub1={clickid}` precisa já estar resolvido no servidor (macro no HTML ou `{link:N}`).
- **Site tracking** (`conversions.site.enabled`, redes sem “início de checkout”): injeta `window.ytdsConversion`; no CTA grava `conversions` (`source=site_script`) + `clicks.status` e dispara CAPI (default Lead→`InitiateCheckout`, sem payout). Venda depois vira Purchase pelo postback; o Lead **não some**.

---

## 15. Operar via `ytds` CLI (agente LLM)

Um agente opera o TDS pelo **`ytds` CLI** — não clica no painel nem edita SQLite. Contrato de
máquina: stdout = JSON do resultado, stderr = `{code,message,hint}`, exit estável (`0` ok, `2`
input, `3` not‑found, `4` auth/config, `5` overlap de domínio, `1` interno). Superfície completa
de comandos no §9; referência exaustiva em `docs/en/ytds-cli.md`, procedimentos‑padrão em
`docs/en/operating-with-ytds.md`, endpoint remoto em `docs/en/admin-api.md`.

- **Dois modos.** Local (`--env local`, in‑process, `--db <path>`) ou remoto (`--env stg\|prod` via
  `/api/admin.php`, Bearer `adminApiToken` de `~/.config/ytds/config.json`). Sempre nomeie o
  `--env`; `prod` é produção, sem experimento.
- **Mutação = dry‑run por padrão.** Todo write (`create\|clone\|rename\|delete\|domains\|patch\|kill-defaults`,
  `networks/destinations add\|update\|delete`, `landing upload\|duplicate\|delete`) valida e imprime
  before/after sem gravar; só `--yes` commita. Overlap de domínio → exit 5 em dry‑run e commit.
- **Loop seguro.** ler (`campaign get`) → dry‑run → conferir diff → `--yes`. Diff maior que o
  esperado: **não** commita, investiga.
- **Segredos mascarados.** `apikey`, token CAPI e postback keys voltam `<redacted>`; nunca desmascare
  nem cole em log/commit. A escrita lê settings crus, então nunca grava `<redacted>` sobre segredo real.
- **Edição amigável.** `campaign patch <id> --set path=value` (por campo) ou `--apply f.json` (seção
  inteira, mutuamente exclusivos); `kill-defaults` remove os 3 defaults do autor (Guardrail #9);
  `clicks` filtra com `--filter field:op:value` (+ `--filter-cond`), `--param KEY`, `--sort`/`--dir`,
  `--page`, `--search`.

## 16. Como escrever este `AGENTS.md`

Este arquivo é o **contrato do produto** AmareloTDS. Um agente/LLM opera o sistema
com ele. Não é diário de campanha, não é log de sessão, não é o `AGENTS.md` do
Hermes (`~/.hermes/hermes-agent/AGENTS.md`).

1. **Só produto e comportamento do código.** Feature, invariante, armadilha,
   comando de verificação genérico (`ytds.<apex>`, `<adminPath>`). A verdade
   final é `code/…php`.
2. **Operação de uma offer/campanha/pixel/clickid não entra aqui.** Isso vai
   para o arquivo interno `ytds_logrecall` (`amarelotds_log_NN.md`), nunca para
   o git. Exemplos proibidos: nome de offer, Pixel ID real, `affid`, clickid
   de teste, host de um anúncio, id de campanha no SQLite.
3. **Sem segredo.** Nada de token CAPI, senha, `adminPath` hex, chave de API.
   O fork é público.
4. **Sem estado de uma máquina.** IP do VPS e paths de instância já estão na
   §2 porque são infra do fork; não acrescente settings.local, campanhas do
   SQLite, nem “o que está no ar hoje”.
5. **Enxugar > ampliar.** Hermes injeta este arquivo só quando o cwd da sessão
   é este repo, e corta em **20 000 caracteres** (head+tail). Passou disso, o
   meio some do system prompt. Antes de acrescentar um parágrafo, apague um
   snapshot ou mova o detalhe de campo para `docs/en/`.
6. **Uma regra, um sítio.** Se o §13 já explica `{link:N}`, o índice só aponta.
   Não duplique o mesmo fluxo em três seções.
7. **Exemplos são genéricos.** `checkout.example.com`, `landing-folder-name`,
   `{clickid}` — nunca o checkout de uma offer viva.
8. **Atualizar quando o código mudar**, não quando uma campanha mudar. Feature
   nova no `staging` → documentar aqui. Troca de pixel de uma offer → log
   interno, não este arquivo.
9. **Doc segue código, na mesma entrega.** Mudou a superfície observável — comando do CLI, ação
   da API, campo de settings, endpoint, exit code — atualize no MESMO commit: a página relevante em
   `docs/en/` (e a simetria em `docs/ru/`, se existir), a linha de feature do §9 aqui, e o
   `version.txt`. Suítes verdes antes do commit. Feature nova sem doc = entrega incompleta.

`agents.md` e `AGENTS.md` neste repo são o **mesmo inode**. Editar um edita o
outro. Não criar um terceiro `AGENTS.md` em `~/.hermes/` “para o AmareloTDS”.

