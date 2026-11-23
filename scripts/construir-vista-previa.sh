#!/usr/bin/env bash
# Arma la vista previa estática en _site/ a partir de public/.
# Quita la parte de servidor (PHP, panel, configuración) y activa el modo demostración,
# que simula la agenda y los pagos en el navegador.
set -euo pipefail

raiz="$(cd "$(dirname "$0")/.." && pwd)"
destino="${1:-$raiz/_site}"

rm -rf "$destino"
cp -r "$raiz/public" "$destino"
rm -rf "$destino/api" "$destino/admin" "$destino/config" "$destino/privado"
find "$destino" -name ".htaccess" -delete

# Cargar demo.js antes que el resto de los scripts en cada página.
for pagina in "$destino"/*.html; do
  sed -i 's|<script src="assets/js/comun.js"></script>|<script src="assets/js/demo.js"></script>\n<script src="assets/js/comun.js"></script>|' "$pagina"
  # La vista previa no debe aparecer en Google.
  sed -i 's|<meta charset="utf-8">|<meta charset="utf-8">\n  <meta name="robots" content="noindex">|' "$pagina"
done
touch "$destino/.nojekyll"
echo "Vista previa lista en $destino"
