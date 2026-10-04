#!/usr/bin/env bash
# Exercise early refusal paths in a filesystem sandbox, without Docker/DB access.
set -euo pipefail
cd "$(dirname "$0")/../.."
sandbox=$(mktemp -d)
trap 'rm -rf "$sandbox"' EXIT
mkdir -p "$sandbox/tests/integration" "$sandbox/.test-site" "$sandbox/bin"
cp tests/integration/run.sh "$sandbox/tests/integration/run.sh"
printf '%s\n' 'existing-site-sentinel' > "$sandbox/.test-site/wp-config.php"
cat > "$sandbox/bin/ddev" <<'SH'
#!/usr/bin/env bash
# Any DDEV call on these refusal paths is a safety regression.
printf called > "$SAFETY_SANDBOX/ddev-called"
exit 99
SH
chmod +x "$sandbox/bin/ddev"
export SAFETY_SANDBOX="$sandbox"
export PATH="$sandbox/bin:$PATH"
if bash "$sandbox/tests/integration/run.sh" > "$sandbox/output" 2>&1; then
  echo 'Setup unexpectedly accepted an existing unmarked site.' >&2
  exit 1
fi
grep -q 'Refusing to reset an existing unmarked' "$sandbox/output"
test "$(<"$sandbox/.test-site/wp-config.php")" = existing-site-sentinel
test ! -e "$sandbox/ddev-called"
test ! -e "$sandbox/.test-site/.gq-disposable"
rm "$sandbox/.test-site/wp-config.php"
if POLYLANG_DIR=/unused POLYLANG_VERSION=3.7 bash "$sandbox/tests/integration/run.sh" > "$sandbox/output" 2>&1; then
  echo 'Setup unexpectedly accepted conflicting dependency selectors.' >&2
  exit 1
fi
grep -q 'Choose POLYLANG_DIR or POLYLANG_VERSION' "$sandbox/output"
test ! -e "$sandbox/ddev-called"
echo 'Disposable setup refusal checks passed.'
