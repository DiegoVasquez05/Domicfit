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
├── admin/              → panel de administración (con contraseña)
├── api/                → agenda, pedidos y Mercado Pago (PHP)
├── assets/             → estilos, scripts e imágenes
├── data/               → precios, equipo, testimonios y galería
├── config/             → config.example.php (config.php va solo en el servidor)
└── privado/            → base de datos (se crea sola, protegida)
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
