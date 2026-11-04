#!/usr/bin/env bash
# Gera o .zip de instalação (pasta "crm/" dentro do zip) a partir do que está commitado.
set -euo pipefail
cd "$(dirname "$0")/.."
VERSAO=$(sed -n "s/.*'PLUGIN_CRM_VERSION', '\(.*\)'.*/\1/p" setup.php)
git archive --format=zip --prefix=crm/ -o "crm-glpi-${VERSAO}.zip" HEAD -- setup.php hook.php src front public LICENSE README.md CHANGELOG.md
echo "crm-glpi-${VERSAO}.zip"
