#!/usr/bin/env bash
# Ejecuta las pruebas automáticas: levanta la página y un Mercado Pago falso en este computador.
#   ./tests/ejecutar.sh
set -euo pipefail
cd "$(dirname "$0")/.."

export PUERTO_SITIO="${PUERTO_SITIO:-8090}"
export PUERTO_MP="${PUERTO_MP:-8091}"
export DOMICFIT_CONFIG="$PWD/tests/config.prueba.php"
export DOMICFIT_DB="$(mktemp -d)/domicfit-pruebas.sqlite"

php -S "127.0.0.1:$PUERTO_SITIO" -t public > /tmp/domicfit-sitio.log 2>&1 &
pid_sitio=$!
php -S "127.0.0.1:$PUERTO_MP" tests/mp-falso.php > /tmp/domicfit-mp.log 2>&1 &
pid_mp=$!
trap 'kill $pid_sitio $pid_mp 2>/dev/null || true; rm -rf "$(dirname "$DOMICFIT_DB")"' EXIT

# Esperar a que ambos servidores respondan
for _ in $(seq 1 50); do
  if curl -s -o /dev/null "http://127.0.0.1:$PUERTO_SITIO/index.html" && curl -s -o /dev/null "http://127.0.0.1:$PUERTO_MP/"; then break; fi
  sleep 0.1
done

php tests/api.test.php
