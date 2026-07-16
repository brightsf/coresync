# Как катать релиз модуля CoreSync

Версия модуля — **не ручное поле**, а следствие тега. Единственный источник
истины — тег релиза; `Init/module.json.version` обязан ему совпадать (CI красит
сборку при расхождении).

**Формат тега:** `<engine>-vX.Y.Z` (движко-префикс + semver). Сейчас: `okay-v1.2.0`.
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
