#!/bin/bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/local-env.sh"

[[ $# -ge 2 ]] || local_fail 'Usage: ./scripts/local.sh {root|backend|frontend} command [arguments...]'
case "$1" in
  root) cd "${ROOT_DIR}" ;;
  backend|frontend) cd "${ROOT_DIR}/$1" ;;
  *) local_fail 'Working directory must be root, backend or frontend.' ;;
esac
shift
require_local_runtimes
exec "$@"
