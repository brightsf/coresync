#!/usr/bin/env bash
#
# build-artifact.sh — детерминированная сборка релизного тарбола модуля CoreSync.
#
# Контракт артефакта (пинится stage-rollout-ci; читают core/updater):
#   * имя тарбола  : <tag>.tar.gz            (напр. okay-v1.2.0.tar.gz)
#   * корень внутри: единственный каталог CoreSync/  (содержимое
#                    Okay/Modules/Format/CoreSync/, без .git/tests/доков репо-уровня)
#   * контрольная сумма: <tag>.tar.gz.sha256, формат "<hex>  <имя файла>"
#                    (sha256sum-совместимый; сверяется `sha256sum -c` / `shasum -a 256 -c`)
#
# Детерминизм: одинаковый вход → одинаковый листинг (стабильная сортировка,
# нормализованные mtime/uid/gid/права, gzip без имени/времени). На GNU tar
# (CI/контейнер) сборка воспроизводима байт-в-байт; на bsdtar (macOS) —
# идентичный листинг и валидная sha (кросс-платформенная байт-идентичность не
# требуется контрактом).
#
# Использование:
#   tools/build-artifact.sh okay-v1.2.0            # артефакты в CWD
#   OUT_DIR=/path tools/build-artifact.sh okay-v1.2.0
#
# Коды возврата: 0 — успех; 2 — ошибка использования/окружения.

set -euo pipefail

# --- TODO(D-SAT-UPDATE-SIGNING): подпись артефакта -------------------------
# Фаза 1 требует подписи тарбола офлайн-ключом (публичный ключ вшит в модуль),
# т.к. sha256 рядом с артефактом не защищает от скомпрометированного ядра
# (оно назовёт и URL, и «правильный» хеш). Здесь появится шаг:
#   gpg/minisign --sign "${tarball}"  → <tag>.tar.gz.sig
# Пока (решение владельца, фаза 0) — только sha256, без подписи.
# --------------------------------------------------------------------------

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/.." && pwd)"
module_dir="${repo_root}/Okay/Modules/Format/CoreSync"

tag="${1:-}"
if [ -z "${tag}" ]; then
  echo "usage: $(basename "$0") <tag>   (напр. okay-v1.2.0)" >&2
  exit 2
fi
# Тег той же формы, что и в release-check.sh: <engine>-vX.Y.Z.
if [[ ! "${tag}" =~ ^[a-z][a-z0-9]*-v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "build-artifact: некорректный тег '${tag}' — ожидается <engine>-vX.Y.Z." >&2
  exit 2
fi

if [ ! -d "${module_dir}" ]; then
  echo "build-artifact: не найден каталог модуля ${module_dir}" >&2
  exit 2
fi

out_dir="${OUT_DIR:-$PWD}"
mkdir -p "${out_dir}"
out_dir="$(cd "${out_dir}" && pwd)"
tarball="${out_dir}/${tag}.tar.gz"
shafile="${tarball}.sha256"

# Детерминированная сортировка/локаль/таймзона на всём протяжении сборки.
# TZ=UTC обязателен: `touch -t 197001010000` трактуется в ЛОКАЛЬНОЙ таймзоне; в
# зоне впереди UTC это даёт отрицательный epoch → libarchive/bsdtar «modification
# time too large» и пустой архив. В UTC та же дата = ровно epoch 0 (совпадает с
# GNU `--mtime='@0'`).
export LC_ALL=C
export TZ=UTC

# Промежуточный staging с единственным корнем CoreSync/.
work="$(mktemp -d "${TMPDIR:-/tmp}/coresync-artifact.XXXXXX")"
trap 'rm -rf "${work}"' EXIT
stage="${work}/CoreSync"
mkdir -p "${stage}"

# Копируем содержимое модуля. Исключения — явные (защитно; в норме их в
# каталоге модуля нет, т.к. тесты/доки репо-уровня лежат выше по дереву).
#   .git*      — исходник/история в веб-доступ не кладём
#   .DS_Store  — мусор macOS (ломает детерминизм)
#   tests      — тестов внутри модуля быть не должно
#   HANDBACK-* — исполнительские отчёты
# Список файлов формируем find'ом с prune по этим паттернам, копируем по одному.
while IFS= read -r rel; do
  src="${module_dir}/${rel}"
  dst="${stage}/${rel}"
  mkdir -p "$(dirname "${dst}")"
  cp -p "${src}" "${dst}"
done < <(
  cd "${module_dir}" && find . \
    \( -name '.git' -o -name '.git*' -o -name 'tests' \) -prune -o \
    \( -type f ! -name '.DS_Store' ! -name 'HANDBACK-*' -print \) \
  | sed 's#^\./##' | LC_ALL=C sort
)

# Нормализация метаданных → воспроизводимость.
#   права: каталоги 755, файлы 644
#   владелец: сбрасывается флагами tar (ниже)
#   mtime: фиксированная эпоха (1970-01-01 UTC)
find "${stage}" -type d -exec chmod 755 {} +
find "${stage}" -type f -exec chmod 644 {} +
find "${work}" -exec touch -h -t 197001010000.00 {} + 2>/dev/null || \
  find "${work}" -exec touch -t 197001010000.00 {} +

# Отсортированный список членов архива (пути относительно work → CoreSync/...).
members="${work}/.members"
( cd "${work}" && find CoreSync \( -type f -o -type d \) | LC_ALL=C sort ) > "${members}"

# Сборка: GNU tar даёт байт-воспроизводимость; bsdtar — тот же листинг.
# --no-recursion — КЛЮЧЕВОЕ: `members` уже содержит и каталоги, и файлы; без
# него tar дополнительно рекурсивно добирает содержимое каждого каталога из
# списка → файлы задваиваются в архиве.
if tar --version 2>/dev/null | grep -q 'GNU tar'; then
  tar --format=ustar --no-recursion \
      --owner=0 --group=0 --numeric-owner \
      --mtime='@0' --sort=name \
      -C "${work}" -cf "${work}/artifact.tar" -T "${members}"
else
  # bsdtar: сортировку задаёт members, метаданные — флагами; mtime уже нормализован.
  tar --format=ustar --no-recursion \
      --uid 0 --gid 0 --numeric-owner \
      -C "${work}" -cf "${work}/artifact.tar" -T "${members}"
fi

# gzip -n: без имени/времени в контейнере (иначе sha «плавает» между сборками).
gzip -n -9 -c "${work}/artifact.tar" > "${tarball}"

# sha256 рядом, формат "<hex>  <имя файла>" (только имя, без пути → переносимо).
(
  cd "${out_dir}"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "${tag}.tar.gz" > "${tag}.tar.gz.sha256"
  else
    shasum -a 256 "${tag}.tar.gz" > "${tag}.tar.gz.sha256"
  fi
)

echo "build-artifact: собрано"
echo "  тарбол : ${tarball}"
echo "  sha256 : ${shafile}"
echo "  корень : CoreSync/  ($(grep -c '' "${members}") записей)"
exit 0
