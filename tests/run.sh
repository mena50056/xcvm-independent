#!/bin/sh
set -eu
LAB_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
export XCVM_LAB_STATE="$LAB_ROOT/.test-state/run-$(date +%s)-$$"
php -l "$LAB_ROOT/runtime/LocalCore.php"
php "$LAB_ROOT/tests/core.php"
