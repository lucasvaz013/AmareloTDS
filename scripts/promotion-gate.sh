#!/usr/bin/env bash
#
# promotion-gate.sh — proves that `staging` is ready to be promoted to `production`.
#
# Runs the full readiness battery and prints a GO / NO-GO verdict. Exits non-zero on NO-GO so it can
# gate a merge in CI or locally. Run from the repo root on the `staging` branch:
#
#   scripts/promotion-gate.sh                 # compare against local `production`
#   BASE_REF=origin/production scripts/promotion-gate.sh
#
set -uo pipefail
cd "$(dirname "$0")/.."

BASE_REF="${BASE_REF:-production}"
PASS=0; FAIL=0
green() { printf '  \033[32mPASS\033[0m %s\n' "$1"; PASS=$((PASS+1)); }
red()   { printf '  \033[31mFAIL\033[0m %s\n' "$1"; FAIL=$((FAIL+1)); }
info()  { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

# DD.MM.YY[.BUILD] -> sortable integer YYYYMMDDBBB
ver_key() { echo "$1" | awk -F. '{ b=($4==""?0:$4); printf "%04d%02d%02d%03d\n", 2000+$3, $2, $1, b }'; }

info "1. Árvore de trabalho limpa"
if [ -z "$(git status --porcelain)" ]; then green "sem mudanças não-commitadas"; else red "há mudanças não-commitadas (commite antes de promover)"; fi

info "2. Boot da aplicação (bootstrap do DB)"
if php -r 'require "code/db/db.php"; new Db();' >/dev/null 2>&1; then green "code/db/db.php instancia sem erro"; else red "falha ao instanciar Db"; fi

info "3. Lint PHP (code/, cli/, bin/)"
lint_errors=0
while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || { echo "     erro de sintaxe: $f"; lint_errors=$((lint_errors+1)); }; done < <(find code cli bin -type f \( -name '*.php' -o -name 'ytds' \) 2>/dev/null | grep -v '/thankyou/vendor/')
if [ "$lint_errors" -eq 0 ]; then green "sem erros de sintaxe"; else red "$lint_errors arquivo(s) com erro de sintaxe"; fi

info "4. Suíte engine (./vendor/bin/phpunit)"
if ./vendor/bin/phpunit >/tmp/gate_engine.log 2>&1; then green "$(grep -Eo 'Tests: [0-9]+' /tmp/gate_engine.log | tail -1)"; else red "suíte engine vermelha (veja /tmp/gate_engine.log)"; fi

info "5. Suíte application (./vendor/bin/phpunit tests/application)"
if ./vendor/bin/phpunit tests/application >/tmp/gate_app.log 2>&1; then green "$(grep -Eo 'Tests: [0-9]+' /tmp/gate_app.log | tail -1)"; else red "suíte application vermelha (veja /tmp/gate_app.log)"; fi

info "6. Varredura de segredos (gitleaks)"
if command -v gitleaks >/dev/null 2>&1; then
  if gitleaks git --no-banner >/dev/null 2>&1; then green "sem segredos"; else red "gitleaks encontrou segredo"; fi
else
  red "gitleaks não instalado (brew install gitleaks) — não é possível certificar"
fi

info "7. version.txt bumpado acima de $BASE_REF (gatilho do deploy)"
staging_ver="$(tr -d '[:space:]' < code/admin/version.txt)"
base_ver="$(git show "$BASE_REF:code/admin/version.txt" 2>/dev/null | tr -d '[:space:]')"
if [ -z "$base_ver" ]; then
  red "não consegui ler version.txt de $BASE_REF"
elif [ "$(ver_key "$staging_ver")" -gt "$(ver_key "$base_ver")" ]; then
  green "staging $staging_ver > $BASE_REF $base_ver (updater vai disparar)"
else
  red "staging $staging_ver não é maior que $BASE_REF $base_ver — bump code/admin/version.txt antes de promover"
fi

info "8. Merge limpo staging -> $BASE_REF (fast-forward)"
if git merge-base --is-ancestor "$BASE_REF" HEAD 2>/dev/null; then green "$BASE_REF é ancestral de HEAD (merge sem conflito)"; else red "$BASE_REF divergiu de staging — reconcilie antes de promover"; fi

echo
if [ "$FAIL" -eq 0 ]; then
  printf '\033[1;32m==> GO\033[0m — %d checks verdes; staging pronto para promover para production.\n' "$PASS"
  exit 0
else
  printf '\033[1;31m==> NO-GO\033[0m — %d falha(s), %d verde(s). NÃO promova.\n' "$FAIL" "$PASS"
  exit 1
fi
