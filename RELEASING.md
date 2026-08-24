# Как катать релиз модуля CoreSync

Версия модуля — **не ручное поле**, а следствие тега. Единственный источник
истины — тег релиза; `Init/module.json.version` обязан ему совпадать (CI красит
сборку при расхождении).

**Формат тега:** `<engine>-vX.Y.Z` (движко-префикс + semver). Сейчас: `okay-v1.5.0`.
Второй движок поедет тегами `simpla-vX.Y.Z` (добавить триггер в `release.yml`).

## Цикл релиза

1. **Бамп.** Подними `version` в `Okay/Modules/Format/CoreSync/Init/module.json`
   до целевой (напр. `1.3.0`). Больше версию нигде править не нужно.
2. **Проверка (по желанию).** `tools/release-check.sh okay-v1.3.0` → `OK`;
   сборку можно прогнать заранее: `tools/build-artifact.sh okay-v1.3.0`.
3. **Коммит + тег.** Закоммить бамп, тег той же версии:
   `git tag okay-v1.3.0 && git push origin okay-v1.3.0`.
4. **CI (`.github/workflows/release.yml`, по пушу тега `okay-v*`):** `release-check`
   (красит при расхождении тег⇄module.json) → тесты модуля (suite `CoreSync`) →
   `build-artifact` (детерминированный `okay-v1.3.0.tar.gz` с единственным корнем
   `CoreSync/` + `.sha256`) → GitHub Release с обоими файлами (штатный `GITHUB_TOKEN`).

## Гейт PHP 7.4

Перед релизом модуль обязан пройти тесты на том же PHP 7.4-рантайме, что и
витрина. Из корня канона:

```bash
tools/php74-suite/run.sh
```

По умолчанию команда запускает одноразовый `artazru-web:latest` (PHP 7.4.33 с
реальным набором расширений витрины), монтирует канонические module/tests и
read-only Okay host project, проверяет `php -l` всего модуля и тестов, затем
исполняет каждый test class. В конце обязательна строка
`CORESYNC-SUITE runtime=... classes_executed=... classes_skipped=...`;
ненулевой exit, неполный JUnit class count или неописанный skip красит гейт.

Дополнительные прогоны той же матрицы:

```bash
tools/php74-suite/run.sh --runtime=7.4-cli  # vanilla php:7.4-cli
tools/php74-suite/run.sh --runtime=8.0      # coresatellites-web parity
```

Vanilla CLI-образ не содержит `ext-gd`, поэтому его единственный допустимый
skip записан с причиной в `tools/php74-suite/skip-list.php74-cli.txt`. Он не
заменяет основной storefront-гейт: основной 7.4 и parity 8.0 используют пустой
`skip-list.txt` и обязаны исполнить одинаковый набор классов.

Раннер использует штатный PHPUnit/vendor Okay-проекта, но отдельный bootstrap:
общий `vendor/composer/platform_check.php` требует PHP >=8 из-за пакетов, не
участвующих в CoreSync, хотя установленный PHPUnit 9.5 совместим с PHP 7.4.
Bootstrap не загружает общий platform check, а любой реально достигнутый
PHP-8-only vendor/module путь всё равно падает под целевым интерпретатором.

Локальные пути можно переопределить без правки канона:

```bash
CORESYNC_OKAY_ROOT=/path/to/okay \
CORESYNC_SAFE_RUN=/path/to/b2bCRM/scripts/safe-run.sh \
tools/php74-suite/run.sh
```

Все suite-запуски идут блокирующе через `safe-run --class targeted --oneoff`.
Если sandbox сначала предъявил `safe-run` EXIT=6 из-за запрещённого `ps`,
разовый fallback разрешается только явно и оставляет audit-строку:

```bash
SAFE_RUN_ALLOW_BARE='safe-run EXIT=6: <точная причина>' tools/php74-suite/run.sh
```

Детектор проверяется временной PHP-8-only мутацией в исполняемом module path
(например, named argument): 7.4 lint обязан стать красным с именем файла.
После пробы мутация снимается, исходный md5 восстанавливается и тот же полный
гейт запускается заново.

## Целостность и частые ошибки

- Сверка артефакта: `sha256sum -c okay-vX.Y.Z.tar.gz.sha256`. Подпись пока не
  делается — долг **D-SAT-UPDATE-SIGNING** (sha256 не защищает от подмены кода
  скомпрометированным ядром; закрыть до выхода за доверенные витрины).
- Забыл бампнуть `module.json` → CI красный на `release-check`. Тег не той формы
  (`v1.3.0` без движка) → CI не триггерится.

## Канон vs рабочий репо (важно)

Этот репозиторий (`brightsf/coresync`) — **канон модуля**: только
`Okay/Modules/Format/CoreSync` + `tests/Modules/Format/CoreSync` + релиз-обвязка.
Ядра Okay CMS здесь нет ⇒ тесты модуля здесь **не запускаются** (им нужны
`Okay\Core\*`). Тест-гейт релиза — полный сьют `--testsuite CoreSync` в рабочем
репо (coreSatellites, полный сайт) на независимой приёмке каждого этапа.
Канон-CI гейтит: release-check (тег ⇄ module.json) + детерминированную сборку.

⚠ Правка `Okay/Core/Database.php` (tx-делегаты, stage-sat-harden) — часть
рабочего репо, НЕ канона и НЕ артефакта: артефакт пакует только `CoreSync/`.
Модуль работает и на непатченном ядре (guard `method_exists` → graceful
degradation до до-harden семантики); патч ядра ставится вручную на наших
витринах при желании честных транзакций.
