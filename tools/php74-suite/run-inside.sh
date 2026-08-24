#!/usr/bin/env bash

set -euo pipefail

cd /workspace

runtime="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
case "$runtime" in
  7.4|8.0) ;;
  *)
    echo "php74-suite: expected PHP 7.4 or parity PHP 8.0, got $(php -r 'echo PHP_VERSION;')" >&2
    exit 2
    ;;
esac

skip_file="${CORESYNC_SKIP_LIST:-tools/php74-suite/skip-list.txt}"
test_root="tests/Modules/Format/CoreSync"
junit="/tmp/coresync-php74-suite-junit.xml"
generated_config="/tmp/coresync-php74-suite-phpunit.xml"

read -r expected_classes skip_count < <(
  php tools/php74-suite/suite-config.php "$skip_file" "$generated_config"
)

# Syntax is checked by the target interpreter before PHPUnit autoloading so a
# PHP 8-only construct cannot hide in a class that this run does not reach.
while IFS= read -r path; do
  php -l "$path" >/dev/null
done < <(find Okay/Modules/Format/CoreSync "$test_root" -type f -name '*.php' | LC_ALL=C sort)

php tools/php74-suite/phpunit.php \
  --configuration "$generated_config" \
  --log-junit "$junit"

php tools/php74-suite/report.php "$junit" "$expected_classes" "$skip_count"
