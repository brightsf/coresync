#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
okay_root="${CORESYNC_OKAY_ROOT:-/Users/formatiai/Projects/coreSatellites}"
safe_run="${CORESYNC_SAFE_RUN:-/Users/formatiai/b2bCRM/scripts/safe-run.sh}"
contracts_root="${CORESYNC_CONTRACTS_ROOT:-}"
contract_name="satellite-product-i18n-v3.json"
runtime="7.4"

case "${1:-}" in
  ''|--runtime=7.4) runtime="7.4" ;;
  --runtime=7.4-cli) runtime="7.4-cli" ;;
  --runtime=8.0) runtime="8.0" ;;
  *)
    echo "usage: tools/php74-suite/run.sh [--runtime=7.4|--runtime=7.4-cli|--runtime=8.0]" >&2
    exit 2
    ;;
esac

if [ ! -r "$safe_run" ]; then
  echo "php74-suite: safe-run wrapper is unavailable at $safe_run" >&2
  exit 2
fi
if [ ! -r "$okay_root/vendor/composer/autoload_classmap.php" ] || [ ! -d "$okay_root/Okay" ]; then
  echo "php74-suite: Okay host root is incomplete at $okay_root" >&2
  exit 2
fi
if [ -z "$contracts_root" ] || [ ! -r "$contracts_root/$contract_name" ]; then
  echo "php74-suite: shared contract is unavailable at ${contracts_root:-<unset>}/$contract_name" >&2
  exit 2
fi

case "$runtime" in
  7.4)
    image="${CORESYNC_PHP74_IMAGE:-artazru-web:latest}"
    skip_list="tools/php74-suite/skip-list.txt"
    ;;
  7.4-cli)
    image="${CORESYNC_PHP74_CLI_IMAGE:-php:7.4-cli}"
    skip_list="tools/php74-suite/skip-list.php74-cli.txt"
    ;;
  8.0)
    image="${CORESYNC_PHP80_IMAGE:-coresatellites-web:latest}"
    skip_list="tools/php74-suite/skip-list.txt"
    ;;
esac

container_args=(
  docker run --rm
  --env PHPRC=/workspace/tools/php74-suite/php.ini
  --env CORESYNC_OKAY_ROOT=/host
  --env "CORESYNC_SKIP_LIST=$skip_list"
  --env "SATELLITE_I18N_CONTRACT=/contracts/$contract_name"
  --volume "$repo_root/Okay/Modules/Format/CoreSync:/workspace/Okay/Modules/Format/CoreSync:ro"
  --volume "$repo_root/tests/Modules/Format/CoreSync:/workspace/tests/Modules/Format/CoreSync:ro"
  --volume "$repo_root/tools:/workspace/tools:ro"
  --volume "$contracts_root:/contracts:ro"
  --volume "$okay_root:/host:ro"
  --volume "$okay_root/Okay/Core/config:/workspace/Okay/Core/config:ro"
  --volume "$okay_root/design:/workspace/design:ro"
  --workdir /workspace
  "$image"
  bash tools/php74-suite/run-inside.sh
)

exec bash "$safe_run" --class targeted --oneoff -- "${container_args[@]}"
