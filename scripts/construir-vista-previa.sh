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
# Al compartir el link de la vista previa, la imagen y la dirección deben ser las de la vista previa.
url_vista="${VISTA_PREVIA_URL:-https://diegovasquez05.github.io/Domicfit/}"
sed -i "s|https://www.domicfit.cl/assets/img/compartir.jpg|${url_vista}assets/img/compartir.jpg|; s|<meta property=\"og:url\" content=\"https://www.domicfit.cl/\">|<meta property=\"og:url\" content=\"${url_vista}\">|" "$destino/index.html"

# Que Google no indexe la vista previa (la página real es www.domicfit.cl).
printf 'User-agent: *\nDisallow: /\n' > "$destino/robots.txt"
rm -f "$destino/sitemap.xml"
touch "$destino/.nojekyll"
echo "Vista previa lista en $destino"
