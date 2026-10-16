#!/bin/bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/local-env.sh"

recreate=false
case "${1:-}" in
  '') ;;
  --recreate) recreate=true ;;
  *) local_fail 'Usage: ./scripts/bootstrap.sh [--recreate]' ;;
esac
[[ $# -le 1 ]] || local_fail 'Usage: ./scripts/bootstrap.sh [--recreate]'

case "$(uname -s)-$(uname -m)" in
  Darwin-arm64) platform=DARWIN_ARM64; python_target=aarch64-apple-darwin; node_target=darwin-arm64 ;;
  Darwin-x86_64) platform=DARWIN_X64; python_target=x86_64-apple-darwin; node_target=darwin-x64 ;;
  Linux-aarch64) platform=LINUX_ARM64; python_target=aarch64-unknown-linux-gnu; node_target=linux-arm64 ;;
  Linux-x86_64) platform=LINUX_X64; python_target=x86_64-unknown-linux-gnu; node_target=linux-x64 ;;
  *) local_fail 'Supported hosts: macOS and glibc Linux, arm64 or x86_64.' ;;
esac

for command in curl tar; do
  command -v "${command}" >/dev/null || local_fail "Required OS utility is missing: ${command}"
done
if command -v shasum >/dev/null; then
  checksum=(shasum -a 256)
elif command -v sha256sum >/dev/null; then
  checksum=(sha256sum)
else
  local_fail 'A SHA-256 utility (shasum or sha256sum) is required.'
fi

downloads="${XDG_CACHE_HOME}/downloads"
mkdir -p "${downloads}" "${LOCAL_DIR}/python" "${LOCAL_DIR}/node"
stage="$(mktemp -d "${TMPDIR}/bootstrap.XXXXXX")"
trap 'rm -rf "${stage}"' EXIT

install_runtime() {
  local url="$1" filename="$2" expected="$3" destination="$4"
  local archive="${downloads}/${filename}" actual
  [[ ! -e "${destination}" ]] || return 0
  if [[ ! -f "${archive}" ]]; then
    curl -q --fail --location --retry 3 --proto '=https' --tlsv1.2 \
      "${url}" --output "${archive}.part"
    mv "${archive}.part" "${archive}"
  fi
  actual="$("${checksum[@]}" "${archive}" | awk '{print $1}')"
  [[ "${actual}" == "${expected}" ]] || local_fail "Checksum mismatch: ${archive}"
  mkdir "${stage}/runtime"
  tar -xzf "${archive}" --strip-components=1 -C "${stage}/runtime"
  mv "${stage}/runtime" "${destination}"
}

python_archive="cpython-${PYTHON_VERSION}+${PYTHON_BUILD}-${python_target}-install_only_stripped.tar.gz"
node_archive="node-v${NODE_VERSION}-${node_target}.tar.gz"
python_hash="PYTHON_SHA256_${platform}"
node_hash="NODE_SHA256_${platform}"
install_runtime \
  "https://github.com/astral-sh/python-build-standalone/releases/download/${PYTHON_BUILD}/${python_archive}" \
  "${python_archive}" "${!python_hash}" "${PYTHON_HOME}"
install_runtime "https://nodejs.org/dist/v${NODE_VERSION}/${node_archive}" \
  "${node_archive}" "${!node_hash}" "${NODE_HOME}"

"${PYTHON_HOME}/bin/python3" -c 'import sys; assert sys.version.split()[0] == sys.argv[1]' "${PYTHON_VERSION}"
if [[ "${recreate}" == true ]]; then
  rm -rf "${VIRTUAL_ENV}"
fi
if [[ ! -e "${VIRTUAL_ENV}" ]]; then
  "${PYTHON_HOME}/bin/python3" -m venv "${VIRTUAL_ENV}"
fi
require_local_runtimes

"${VIRTUAL_ENV}/bin/python" -m pip install "pip==${TOOLCHAIN_PIP_VERSION}"

# Resolve from the committed pins before installing, and refuse any unpinned transitive package.
report="${stage}/python-packages.json"
"${VIRTUAL_ENV}/bin/python" -m pip install --dry-run --ignore-installed --report "${report}" \
  -r "${ROOT_DIR}/backend/requirements-dev.txt"
"${VIRTUAL_ENV}/bin/python" - "${ROOT_DIR}" "${report}" <<'PY'
import json
import re
import sys
from pathlib import Path

root = Path(sys.argv[1]) / "backend"
pins = {}
for name in ("requirements.txt", "constraints.txt", "requirements-dev.txt", "constraints-dev.txt"):
    for line in (root / name).read_text().splitlines():
        match = re.fullmatch(r"([\w.-]+)(?:\[[\w,.-]+\])?==([^\s]+)", line.strip())
        if match:
            pins[re.sub(r"[-_.]+", "-", match[1]).lower()] = match[2]
for item in json.loads(Path(sys.argv[2]).read_text())["install"]:
    metadata = item["metadata"]
    name = re.sub(r"[-_.]+", "-", metadata["name"]).lower()
    if pins.get(name) != metadata["version"]:
        raise SystemExit(f"Missing exact dependency pin: {name}=={metadata['version']}")
PY
"${VIRTUAL_ENV}/bin/python" -m pip install -r "${ROOT_DIR}/backend/requirements-dev.txt"

cd "${ROOT_DIR}/frontend"
"${NODE_HOME}/bin/npm" ci
printf '\nLocal environment ready. Use ./scripts/local.sh {root|backend|frontend} command [arguments...].\n'
