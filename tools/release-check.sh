#!/usr/bin/env bash
#
# release-check.sh — проверка релиз-идентичности модуля CoreSync.
#
# Единственный источник истины версии — тег релиза. Скрипт сверяет версию,
# зашитую в тег (`okay-v1.2.0` → `1.2.0`), с полем `version` в
# `Init/module.json`. Расхождение → exit 1 с внятным сообщением: релиз
# нельзя катать, пока номер в module.json не догнал тег (или наоборот).
#
# Тег: <engine>-vX.Y.Z, где engine ∈ {okay, simpla, ...} (движко-агностично).
# Версия сравнивается как строка X.Y.Z (semver, без префикса движка).
#
# Использование:
#   tools/release-check.sh okay-v1.2.0
#   GITHUB_REF_NAME=okay-v1.2.0 tools/release-check.sh   # так зовёт CI
#
# Коды возврата:
#   0 — версии совпали;
#   1 — расхождение версий;
#   2 — ошибка использования / некорректный тег / нечитаемый module.json.

set -euo pipefail

# Корень модуля относительно этого скрипта: tools/ лежит в корне репо,
# module.json — в Okay/Modules/Format/CoreSync/Init/module.json.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/.." && pwd)"
module_json="${repo_root}/Okay/Modules/Format/CoreSync/Init/module.json"

usage() {
  echo "usage: $(basename "$0") <tag>  (или задай GITHUB_REF_NAME=<tag>)" >&2
  echo "  <tag> = <engine>-vX.Y.Z, напр. okay-v1.2.0" >&2
}

# Тег из аргумента, иначе из окружения CI.
tag="${1:-${GITHUB_REF_NAME:-}}"
if [ -z "${tag}" ]; then
  echo "release-check: тег не задан." >&2
  usage
  exit 2
fi

# Строгая форма тега: <engine>-vX.Y.Z. engine — строчные буквы/цифры.
if [[ ! "${tag}" =~ ^[a-z][a-z0-9]*-v([0-9]+\.[0-9]+\.[0-9]+)$ ]]; then
  echo "release-check: некорректный тег '${tag}' — ожидается <engine>-vX.Y.Z (напр. okay-v1.2.0)." >&2
  exit 2
fi
tag_version="${BASH_REMATCH[1]}"

if [ ! -f "${module_json}" ]; then
  echo "release-check: не найден ${module_json}" >&2
  exit 2
fi

# Версию читаем через php (гарантирован в этом PHP-модуле; без сторонних зависимостей).
# shellcheck disable=SC2016  # php-код в одинарных кавычках намеренно: $argv не разворачивает bash.
module_version="$(
  php -r '
    $j = json_decode(file_get_contents($argv[1]), true);
    if (!is_array($j) || !isset($j["version"])) { fwrite(STDERR, "no version field\n"); exit(3); }
    echo (string) $j["version"];
  ' "${module_json}"
)" || {
  echo "release-check: не удалось прочитать version из ${module_json}" >&2
  exit 2
}

if [ "${module_version}" != "${tag_version}" ]; then
  echo "release-check: РАСХОЖДЕНИЕ версий." >&2
  echo "  тег         : ${tag}  → ${tag_version}" >&2
  echo "  module.json : ${module_version}" >&2
  echo "  Синхронизируй Init/module.json.version с версией тега перед релизом." >&2
  exit 1
fi

echo "release-check: OK — тег ${tag} совпадает с module.json (${module_version})."
exit 0
