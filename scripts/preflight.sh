#!/usr/bin/env bash
set -euo pipefail

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$project_dir"

echo "[1/4] Проверка diff"
git diff --check

echo "[2/4] PHP syntax"
find app lib public tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l

echo "[3/4] JavaScript syntax"
node --check public/assets/panel.js

echo "[4/4] Regression tests"
php tests/notification_groups_test.php
php tests/conversations_test.php
php tests/reporting_calculator_test.php
php scripts/test_reporting_calc.php

echo "Preflight: OK"
