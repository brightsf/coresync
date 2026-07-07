<!--
  VENDORED COPY. Источник истины: b2bCRM `docs/contracts/satellite/v1/` (контракт заморожен, SAT-A).
  Копия синхронизирована модулем Format/CoreSync (SAT-M1). Не редактировать вручную — обновлять
  переносом из ядра при смене мажора schema_version.
-->
# Satellite snapshot contract — v1 (`schema_version = 1.0.0`)

Пинованный контракт обмена **ядро → Okay-сателлит** (спека
`docs/superpowers/specs/2026-07-06-satellite-sync-design.md`). Ядро (b2bCRM) — единственный
**источник правды** этих схем; Okay-модуль `Format/CoreSync` (repo `coreSatellites`) получает
их vendored-копией и валидирует на чтении. Рассинхрон ловится полем `schema_version` манифеста
(fail-closed: незнакомый мажор → модуль отказывается применять).

Схемы — **документация-артефакт**: ядро НЕ тянет json-schema-валидатор в рантайм (структуру
гарантирует golden-тест `SnapshotGoldenTest` + точечные asserts). Модуль-приёмник может валидировать
строки этими схемами.

## Формат на диске

Полный снапшот канала публикуется атомарно под монотонной версией в
`snapshots/{channel_id}/{version}/`:

```
manifest.json                — паспорт снапшота (manifest.schema.json)
categories.ndjson.gz         — дерево категорий канала (category.schema.json)
brands.ndjson.gz             — бренды выгрузки (brand.schema.json)
features.ndjson.gz           — словарь характеристик (feature.schema.json)
products-0001.ndjson.gz …    — товары+варианты, чанки по 2500 (product.schema.json)
redirects.ndjson.gz          — old_slug → new_slug (redirect.schema.json; v1 — пустой, наполнение SAT-A2)
```

Каждый `*.ndjson.gz` — gzip над NDJSON (одна строка = один JSON-объект). `manifest.files` перечисляет
ВСЕ ndjson-файлы с `sha256`/`bytes`/`rows` фактического (gz) файла — приёмник докачивает и сверяет.

## Строка NDJSON

Любая строка сущности: `{"external_id": "<string>", "hash": "<sha256>", "data": {…}}`.

- `external_id` — стабильный строковый id ядра: категория → `channel_categories.id`,
  бренд → `brands.id`, характеристика → id определения словаря (kind=characteristic),
  товар → `products.id`, вариант → `product_variants.id` (внутри товара).
- `hash` — sha256 **канонической** сериализации `data`: рекурсивный ksort ассоц-ключей,
  `json_encode(JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)` без pretty. Порядок ключей источника
  не значим → одинаковый `data` = один `hash` (основа skip-логики applier'а).

## Инварианты

- **KI-06 by construction:** ни закупочной цены, ни себестоимости, ни поставщика, ни служебных
  полей — только whitelist, зафиксированный схемами и тестом `SnapshotKi06WhitelistTest`.
- Всё пер-языковое срезолвлено в ОДИН язык канала (фолбэки ADR-0010 — на стороне ядра; сателлит про
  фолбэк не знает).
- Цена — финальная цена канала (`ChannelPriceResolver`), `amount` — десятичная строка в валюте канала.
- Картинки — АБСОЛЮТНЫЕ исходные URL (root-relative скипаются на генерации).
- `sync_mode` (`full`/`price_stock`) и `absent_policy` (`out_of_stock`/`hide`) — настройки канала,
  едут в манифесте; семантика применения — на стороне сателлита.

## Совместимость

Аддитивные поля — свободно (модуль игнорирует незнакомые). Breaking-изменение → мажор `schema_version`;
модуль отказывается применять незнакомый мажор (витрина остаётся на прошлой применённой версии).
