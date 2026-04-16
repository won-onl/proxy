# ТЗ: зеркало `won-onl.ru` → origin `*.won.onl` (обход Cloudflare по IP)

## Цель

Трафик игроков из РФ идёт на **зеркало** (`*.won-onl.ru` и т.д. на РФ-хостинге). Прокси **исходящим** TCP подключается к **реальному IP origin** (`upstream_direct_ip`), **минуя DNS Cloudflare**, чтобы не попасть на IP Cloudflare.

На уровне **TLS и HTTP** к origin должно быть **indistinguishably** от сценария «клиент открыл нужный поддомен `*.won.onl`», как если бы запрос шёл с edge (в духе Cloudflare → origin): правильные **SNI**, **Host**, согласованные **заголовки о схеме HTTPS и имени хоста**, чтобы приложение на origin могло построить URL и выбрать vhost.

## Реализация

В репозитории — **PHP** (`index.php`, `proxy.php`, `config.php`).

## Требования к исходящему запросу

1. **URL**: `https://{upstreamHost}{REQUEST_URI}` или `http://…` если явно включён режим HTTP к origin (`upstream_use_http`).
2. **TCP**: на `{upstream_direct_ip}` и порт из конфига (`upstream_direct_port`). **Приоритет SSL:** перебор портов HTTPS в порядке `upstream_https_port_priority` (сначала `upstream_direct_ip`, затем остальные без дублей); при неудаче всех HTTPS — опциональный **retry HTTP** на `retry_http_port` (как CF Flexible).
3. **CURLOPT_RESOLVE**: `{upstreamHost}:{port}:{upstream_direct_ip}` — чтобы cURL не резолвил имя через CF DNS.
4. **TLS**: SNI = `{upstreamHost}` (hostname в URL). `verify_upstream_tls`: `false` — типично при прямом IP/самоподпись; `true` — если на origin для `*.won.onl` выдан валидный сертификат и имя совпадает с запросом.
5. **Host**: `Host: {upstreamHost}` (например `ru.won.onl`), **не** домен зеркала, если не включён отдельный режим отладки.
6. **Схема для приложения (HTTPS «как у пользователя»)**:
   - Даже если к origin фактически идёт **HTTP** (режим Flexible / retry на :80), для фреймворков нужно явно передать, что **внешняя** схема — **https** (как делает Cloudflare к origin).
   - Обязательны согласованные: **`X-Forwarded-Proto`**, при необходимости **`X-Forwarded-Port`**, **`CF-Visitor`**, опционально **`Forwarded` (RFC 7239)** с полями `proto`, `host` = **имя на origin** (`upstreamHost`), `for` = IP клиента.
7. **Доверие на origin**: IP прокси (РФ-хостинг) должен быть в **trusted proxies** приложения — иначе Laravel/Symfony и т.п. **игнорируют** `X-Forwarded-*` и считают схему **http** при реальном HTTP до PHP.

## Маппинг публичного хоста → upstream

- `won-onl.ru` / алиасы → `public_apex_upstream_subdomain.won.onl` (например `ru.won.onl`).
- `game.won-onl.ru` → `game.won.onl`.

## Конфигурация

См. `config.php`: `upstream_https_port_priority`, `upstream_reported_proto`, `upstream_x_forwarded_port`, `upstream_forwarded_rfc7239`, `verify_upstream_tls`, `retry_upstream_http_on_ssl_failure`.

## Критерии приёмки

- Запрос к origin содержит **Host** = вычисленный `upstreamHost`.
- При HTTPS к origin в логах/TLS видно **SNI** = тот же хост.
- В заголовках к origin **согласованы** proto/port (и при необходимости `Forwarded`), чтобы приложение не строило `http://` там, где ожидается `https://`.
- Тело/редиректы с зеркала переписываются на публичный домен зеркала там, где уже реализовано.
