<?php
// Panel de Domicfit: ver reservas, confirmar transferencias, cancelar y bloquear horarios.
declare(strict_types=1);
require __DIR__ . '/../api/lib/core.php';

$cfg = config();
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_name('domicfit_admin');
session_start();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$aviso = '';

/* --- Ingreso --- */
if (isset($_POST['clave'])) {
    // Pausa breve para dificultar que adivinen la clave probando muchas veces.
    usleep(500000);
    if (password_verify((string) $_POST['clave'], (string) $cfg['admin_clave_hash'])) {
        session_regenerate_id(true);
        $_SESSION['ok'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        header('Location: ./');
        exit;
    }
    $aviso = 'Contraseña incorrecta.';
}
if (isset($_GET['salir'])) {
    session_destroy();
    header('Location: ./');
    exit;
}

if (empty($_SESSION['ok'])) {
    ?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Panel Domicfit</title><link rel="stylesheet" href="admin.css"></head>
<body class="login"><form method="post" class="tarjeta">
<img src="../assets/img/logo-domicfit-240.png" alt="Domicfit" width="96" height="96">
<h1>Panel Domicfit</h1>
<?php if ($aviso): ?><p class="error"><?= h($aviso) ?></p><?php endif; ?>
<label>Contraseña <input type="password" name="clave" required autofocus autocomplete="current-password"></label>
<button>Entrar</button></form></body></html><?php
    exit;
}

/* --- Acciones --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sesión vencida. Vuelve a cargar la página.');
    }
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($accion === 'confirmar_pago' && $id) {
        actualizar_pedido($id, ['estado' => 'pagado', 'expira' => null]);
        $st = db()->prepare('SELECT * FROM pedidos WHERE id = ?');
        $st->execute([$id]);
        if ($p = $st->fetch()) {
            enviar_avisos($p);
        }
        $aviso = 'Pago confirmado. Se envió la confirmación al alumno.';
    } elseif ($accion === 'cancelar' && $id) {
        actualizar_pedido($id, ['estado' => 'cancelado']);
        $aviso = 'Reserva cancelada: el horario quedó libre.';
    } elseif ($accion === 'bloquear') {
        $fecha = (string) ($_POST['fecha'] ?? '');
        $desde = (string) ($_POST['desde'] ?? '');
        $hasta = (string) ($_POST['hasta'] ?? '');
        $modalidad = (string) ($_POST['modalidad'] ?? 'todas');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) && preg_match('/^\d{2}:\d{2}$/', $desde)
            && preg_match('/^\d{2}:\d{2}$/', $hasta) && $desde < $hasta
            && in_array($modalidad, ['todas', 'sede', 'domicilio'], true)) {
            db()->prepare('INSERT INTO bloqueos (modalidad, inicio, fin, motivo) VALUES (?, ?, ?, ?)')
                ->execute([$modalidad, "$fecha $desde", "$fecha $hasta", mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 200)]);
            $aviso = 'Horario bloqueado.';
        } else {
            $aviso = 'Revisa la fecha y las horas del bloqueo.';
        }
    } elseif ($accion === 'desbloquear' && $id) {
        db()->prepare('DELETE FROM bloqueos WHERE id = ?')->execute([$id]);
        $aviso = 'Bloqueo eliminado.';
    }
    $_SESSION['aviso'] = $aviso;
    header('Location: ./' . (isset($_GET['ver']) ? '?ver=' . urlencode((string) $_GET['ver']) : ''));
    exit;
}
$aviso = $_SESSION['aviso'] ?? '';
unset($_SESSION['aviso']);

/* --- Datos --- */
$ver = (string) ($_GET['ver'] ?? 'proximas');
$hoy = ahora()->format('Y-m-d') . ' 00:00';
$ahora = fmt(ahora());
if ($ver === 'todas') {
    $pedidos = db()->query('SELECT * FROM pedidos ORDER BY id DESC LIMIT 200')->fetchAll();
} else {
    $st = db()->prepare("SELECT * FROM pedidos WHERE inicio >= ? AND estado IN ('pagado','reservado','pendiente_transferencia','pendiente_pago') ORDER BY inicio");
    $st->execute([$hoy]);
    // Los pendientes vencidos no se muestran en la agenda.
    $pedidos = array_filter($st->fetchAll(), fn($p) => !(str_starts_with($p['estado'], 'pendiente') && $p['expira'] !== null && $p['expira'] < $ahora));
}
$st = db()->prepare('SELECT * FROM bloqueos WHERE fin >= ? ORDER BY inicio');
$st->execute([$hoy]);
$bloqueos = $st->fetchAll();

$nombresEstado = [
    'pagado' => 'Pagado', 'reservado' => 'Prueba gratis', 'pendiente_transferencia' => 'Esperando transferencia',
    'pendiente_pago' => 'Pagando en Mercado Pago', 'cancelado' => 'Cancelado',
];
$csrf = h($_SESSION['csrf']);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Panel Domicfit</title><link rel="stylesheet" href="admin.css"></head>
<body>
<header class="barra">
  <strong>Panel Domicfit</strong>
  <nav><a href="./" <?= $ver !== 'todas' ? 'class="activo"' : '' ?>>Próximas reservas</a>
  <a href="?ver=todas" <?= $ver === 'todas' ? 'class="activo"' : '' ?>>Todos los pedidos</a>
  <a href="../" target="_blank">Ver página</a> <a href="?salir=1">Salir</a></nav>
</header>
<main>
<?php if ($aviso): ?><p class="aviso"><?= h($aviso) ?></p><?php endif; ?>

<section>
<h2><?= $ver === 'todas' ? 'Todos los pedidos' : 'Próximas reservas' ?></h2>
<?php if (!$pedidos): ?><p class="vacio">No hay reservas por ahora.</p><?php endif; ?>
<div class="lista">
<?php foreach ($pedidos as $p):
    $items = json_decode($p['items_json'], true);
    $tel = preg_replace('/\D/', '', $p['telefono']); ?>
  <article class="tarjeta estado-<?= h($p['estado']) ?>">
    <div class="fila">
      <strong class="fecha"><?= h(ucfirst(fecha_legible($p['inicio']))) ?></strong>
      <span class="etiqueta"><?= h($nombresEstado[$p['estado']] ?? $p['estado']) ?></span>
    </div>
    <p><strong><?= h($p['nombre']) ?></strong> · <?= (int) $p['personas'] ?> persona<?= $p['personas'] == 2 ? 's' : '' ?>
      · <?= $p['modalidad'] === 'sede' ? 'Sede San Joaquín' : 'Domicilio: ' . h($p['direccion']) . ', ' . h($p['comuna']) ?></p>
    <p><?= h(implode(', ', array_map(fn($i) => $i['nombre'] . ($i['cantidad'] > 1 ? ' x' . $i['cantidad'] : ''), $items))) ?>
      · <strong><?= pesos((int) $p['total']) ?></strong> · <?= h($p['metodo']) ?></p>
    <?php if ($p['objetivo']): ?><p>Objetivo: <?= h($p['objetivo']) ?></p><?php endif; ?>
    <?php if ($p['comentarios']): ?><p>Comentarios: <?= h($p['comentarios']) ?></p><?php endif; ?>
    <p class="contacto"><a href="https://wa.me/<?= h($tel) ?>" target="_blank" rel="noopener">WhatsApp <?= h($p['telefono']) ?></a>
      · <a href="mailto:<?= h($p['email']) ?>"><?= h($p['email']) ?></a> · Pedido <?= h($p['ref']) ?></p>
    <div class="acciones">
      <?php if ($p['estado'] === 'pendiente_transferencia'): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="accion" value="confirmar_pago"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <button>Confirmar transferencia recibida</button></form>
      <?php endif; ?>
      <?php if ($p['estado'] !== 'cancelado'): ?>
      <form method="post" onsubmit="return confirm('¿Cancelar esta reserva? El horario quedará libre.')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
        <button class="secundario">Cancelar reserva</button></form>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
</section>

<section>
<h2>Bloquear horarios</h2>
<p class="ayuda">Úsalo para vacaciones, clases que coordinaste por WhatsApp o cualquier hora en que no puedas atender. Esas horas no aparecerán disponibles en la página.</p>
<form method="post" class="tarjeta formulario">
  <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="accion" value="bloquear">
  <label>Día <input type="date" name="fecha" required></label>
  <label>Desde <input type="time" name="desde" required></label>
  <label>Hasta <input type="time" name="hasta" required></label>
  <label>Dónde <select name="modalidad"><option value="todas">Sede y domicilio</option><option value="sede">Solo sede</option><option value="domicilio">Solo domicilio</option></select></label>
  <label class="ancho">Motivo (opcional) <input type="text" name="motivo" maxlength="200" placeholder="Ej: clase de Juan, vacaciones"></label>
  <button>Bloquear</button>
</form>
<?php if ($bloqueos): ?>
<div class="lista">
<?php foreach ($bloqueos as $b): ?>
  <article class="tarjeta fila">
    <span><strong><?= h(ucfirst(fecha_legible($b['inicio']))) ?></strong> hasta las <?= h(substr($b['fin'], 11)) ?>
      · <?= h(['todas' => 'Sede y domicilio', 'sede' => 'Sede', 'domicilio' => 'Domicilio'][$b['modalidad']] ?? '') ?>
      <?= $b['motivo'] ? '· ' . h($b['motivo']) : '' ?></span>
    <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="accion" value="desbloquear"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
      <button class="secundario">Quitar</button></form>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
</section>
</main>
</body></html>
