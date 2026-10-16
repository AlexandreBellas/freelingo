#!/bin/bash
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/local-env.sh"
require_local_runtimes

pushd "${ROOT_DIR}/backend" >/dev/null
"${VIRTUAL_ENV}/bin/ruff" check --fix .
"${VIRTUAL_ENV}/bin/python" -m black .
popd >/dev/null

pushd "${ROOT_DIR}/frontend" >/dev/null
./node_modules/.bin/eslint src/ --ext .ts,.tsx --fix
./node_modules/.bin/prettier --write src/
popd >/dev/null
