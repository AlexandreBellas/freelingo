#!/bin/bash
set -euo pipefail

source "$(dirname "${BASH_SOURCE[0]}")/local-env.sh"
require_local_runtimes

"${ROOT_DIR}/scripts/format.sh"

pushd "${ROOT_DIR}/backend" >/dev/null
"${VIRTUAL_ENV}/bin/python" -m pytest -v
popd >/dev/null

pushd "${ROOT_DIR}/frontend" >/dev/null
"${NODE_HOME}/bin/npm" run lint
./node_modules/.bin/tsc --noEmit
"${NODE_HOME}/bin/npm" run test:run
popd >/dev/null
