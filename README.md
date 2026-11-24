# Domicfit · Página web

Página de [domicfit.cl](https://www.domicfit.cl): entrenamiento personalizado y deportivo a domicilio y en la sede San Joaquín.

Incluye:

- **Página de una sola sección**: servicios, planes y valores, evaluación mensual, horarios, equipo, preguntas frecuentes y contacto.
- **Carrito** para comprar planes y la clase de prueba.
- **Agenda**: al comprar, el alumno elige el día y la hora de su primera sesión. Solo se muestran las horas libres.
- **Pagos** con Mercado Pago (débito y crédito) o por transferencia.
- **Correos automáticos** al alumno y a Domicfit en cada compra o reserva.
- **Panel de administración** (`/admin`) para ver reservas, confirmar transferencias, cancelar y bloquear horarios.

Hecha con HTML, CSS y JavaScript, más PHP y SQLite para la agenda y los pagos. Funciona en cualquier hosting con cPanel.

---

## Vista previa en línea

Cada cambio en `main` publica una **vista previa** en GitHub Pages (`.github/workflows/vista-previa.yml`): la misma página, pero en modo demostración. El carrito, la agenda y el pago se simulan en el navegador (`public/assets/js/demo.js`); no se cobra nada ni se guardan reservas. Un aviso amarillo arriba lo indica.

Para activarla una sola vez: el repositorio debe ser público y en **Settings → Pages → Source** hay que elegir **GitHub Actions**.

Para armarla en el computador: `./scripts/construir-vista-previa.sh` (queda en `_site/`).

## Google y redes sociales

- **Al compartir el link** (WhatsApp, Instagram, Facebook) se muestra `public/assets/img/compartir.jpg` con título y descripción (etiquetas `og:` en `index.html`).
- **Datos del negocio para Google** (bloque `application/ld+json` en `index.html`): dirección, teléfono, horario, redes y algunos precios. Si cambian, actualízalos ahí también.
- `public/robots.txt` y `public/sitemap.xml` indican a Google qué páginas mostrar (no muestra el panel, la API ni las páginas de reserva y pago).
- La vista previa no se indexa: su `robots.txt` y las etiquetas `noindex` lo impiden.

## Pruebas automáticas

En cada cambio, GitHub ejecuta `.github/workflows/pruebas.yml`:

- **Servidor (PHP 8.1):** sintaxis, archivos de datos y 32 pruebas de la agenda, pedidos, Mercado Pago (con una imitación de su API) y el panel. Se ejecutan con `./tests/ejecutar.sh`.
- **Navegador:** compra, clase de prueba y validaciones en la vista previa, en computador y celular (`tests/vista-previa.e2e.js`, con Playwright).

## Páginas legales

`public/terminos.html` y `public/privacidad.html`. Tienen datos marcados **"por completar"** o **"por definir"** (RUT de la empresa, plazo para usar las sesiones y política de retracto y devoluciones) que debe completar Domicfit. Conviene que un abogado las revise antes de publicar.

## Cómo actualizar el contenido

| Quiero cambiar… | Archivo |
|---|---|
| Precios o planes | `public/data/productos.json` (la página y el cobro leen el mismo archivo) |
| Profesores del equipo | `public/data/equipo.json` (fotos en `public/assets/img/equipo/`) |
| Opiniones de alumnos | `public/data/testimonios.json` (la sección aparece sola cuando hay al menos una) |
| Fotos de alumnos | `public/data/galeria.json` (fotos en `public/assets/img/galeria/`) |
| Horarios, capacidad por hora, datos de transferencia | `public/config/config.php` (en el servidor) |
| Textos de la página | `public/index.html` |

## Estructura

```
public/                 → todo esto se sube a public_html
├── index.html          → página principal
├── reservar.html       → datos, día y hora, y pago
├── pago.html           → resultado de la reserva o del pago
├── terminos.html       → términos y condiciones
├── privacidad.html     → política de privacidad
├── admin/              → panel de administración (con contraseña)
├── api/                → agenda, pedidos y Mercado Pago (PHP)
├── assets/             → estilos, scripts e imágenes
├── data/               → precios, equipo, testimonios y galería
├── config/             → config.example.php (config.php va solo en el servidor)
└── privado/            → base de datos (se crea sola, protegida)
scripts/                → arma la vista previa
tests/                  → pruebas automáticas
```

## Instalación en el hosting (cPanel)

Requisitos: PHP 8.1 o superior con `pdo_sqlite` y `curl` (vienen activados en casi todos los hosting).

1. Sube el contenido de `public/` a `public_html`.
2. En `public_html/config/`, copia `config.example.php` como `config.php` y completa:
   - `mp_access_token`: en Mercado Pago, *Tus integraciones → Credenciales*. Para probar usa las de prueba (`TEST-…`); para cobrar de verdad, las de producción (`APP_USR-…`).
   - `admin_clave_hash`: la contraseña del panel, guardada como hash. Se genera con `php -r "echo password_hash('TU_CLAVE', PASSWORD_DEFAULT);"`.
   - Los datos de la cuenta para transferencias.
   - `correo_remitente`: una casilla del dominio, por ejemplo `no-responder@domicfit.cl` (se crea en cPanel → Cuentas de correo).
3. Revisa que el sitio abra con `https://`. cPanel entrega el certificado gratis con AutoSSL.
4. Haz una compra de prueba con las credenciales de prueba de Mercado Pago y revisa que llegue el correo y que aparezca en `/admin`.

**`config.php` nunca se sube a GitHub**: tiene las claves. Está en `.gitignore`.

## Publicación automática

Cada cambio que entra a la rama `main` se sube solo al hosting con GitHub Actions (`.github/workflows/publicar.yml`). Para activarlo, en GitHub ve a **Settings → Secrets and variables → Actions** y crea:

| Secret | Qué va |
|---|---|
| `FTP_SERVIDOR` | El servidor FTP, por ejemplo `ftp.domicfit.cl` |
| `FTP_USUARIO` | Usuario FTP de cPanel |
| `FTP_CLAVE` | Contraseña de ese usuario |

Si la carpeta de destino no es `public_html/`, crea también la variable `FTP_CARPETA`. Mientras no existan los secrets, la publicación se salta sin error. `config/config.php` y la base de datos nunca se suben ni se borran.

## Cómo funciona el pago

1. El alumno elige el plan, el día y la hora. El horario queda guardado 30 minutos mientras paga (24 horas si es transferencia).
2. Con Mercado Pago, el servidor calcula el total con los precios de `productos.json` (nunca confía en el precio que manda el navegador) y crea el pago.
3. Mercado Pago avisa a `api/webhook-mp.php`. El servidor consulta el pago directamente a Mercado Pago y, si está aprobado y el monto coincide, lo marca como pagado y envía los correos.
4. Con transferencia, Fabián confirma el pago desde `/admin` y el alumno recibe la confirmación.

Mercado Pago entrega el comprobante del pago, pero **no emite la boleta del SII**. La boleta se hace aparte.

## Probar en el computador

```bash
cd public
cp config/config.example.php config/config.php   # y completar
php -S localhost:8000
```
