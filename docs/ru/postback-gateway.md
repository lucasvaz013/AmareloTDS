# Postback Gateway

Некоторые платформы продаж принимают postback URL только на корневом домене и отклоняют поддомены. Postback Gateway публикует выделенный apex, например `https://example.com/api/postback.php`, на текущей установке AmareloTDS.

## Граница безопасности

Gateway не является доменом кампании или вторым сайтом TDS. Его nginx virtual host открывает только:

```text
/api/postback.php
```

Корень, admin path, Connect endpoints, landings, произвольные PHP-файлы и все остальные URL возвращают `404`. Кампания определяется по `clickid`, поэтому gateway должен принадлежать той установке, база которой создала этот click.

Для защиты включите **Key protection** кампании и добавьте `pbkey`, если партнер поддерживает статический секрет.

## Создание

Откройте **Domains → Postback Gateway**, введите корневой домен из подключенного аккаунта Cloudflare, прочитайте предупреждение и подтвердите действие.

Операция:

1. сохраняет MX, TXT и другие неадресные записи;
2. оставляет ровно одну apex `A`, указывающую на эту установку;
3. выключает Cloudflare proxy;
4. удаляет конфликтующие apex `AAAA` и `CNAME`;
5. ставит в очередь изолированный nginx virtual host и HTTPS certificate.

Замена адресных записей apex может отключить существующий сайт. Используйте домен, выделенный для postback.

## Автоматическая сверка

Состояние хранится в versioned object `postbackGateway` файла `settings.local.php`; SQL migration не нужна. Существующие crons разделяют права:

- `refresh_domains.php` под `www-data` сверяет Cloudflare DNS;
- `provision_domains.php` под root вызывает `install.sh --add-postback-gateway`, проверяет nginx и HTTPS.

Root cron принимает только конфиги с marker `# amarelotds-postback-gateway v1` и не перезаписывает чужой сайт. Ready gateway не переписывается каждые 5 минут, чтобы не затереть правку из панели; **Check now** заново сверяет DNS и сбрасывает исчерпанные nginx attempts.

Удаление из панели прекращает сверку, но намеренно оставляет DNS и nginx на месте, чтобы случайно не остановить активную интеграцию.

## Проверка

```text
GET /                         -> 404
GET /admin/                   -> 404
GET /api/postback.php         -> 400 missing_clickid
GET /api/postback.php?clickid=unknown -> 404 click_not_found
```

DNS должен возвращать только IPv4 установки в `A` и не иметь `AAAA`.
