<?php
// POST JSON: crea el pedido, guarda el horario y devuelve cómo pagar.
require __DIR__ . '/lib/core.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder_error('Método no permitido.', 405);
}
$cfg = config();
$e = leer_json_entrada();

// Campo trampa para bots: las personas no lo ven ni lo llenan.
if (!empty($e['website'])) {
    responder_error('Solicitud inválida.');
}

/* --- Productos: los precios salen del catálogo del servidor, nunca del navegador --- */
$catalogo = catalogo();
$modalidad = (string) ($e['modalidad'] ?? '');
if (!in_array($modalidad, ['sede', 'domicilio'], true)) {
    responder_error('Elige si entrenarás a domicilio o en la sede.');
}
$items = [];
$total = 0;
$hayPrueba = false;
foreach ((array) ($e['items'] ?? []) as $item) {
    $p = $catalogo[(string) ($item['id'] ?? '')] ?? null;
    $cantidad = (int) ($item['cantidad'] ?? 0);
    if ($p === null || $cantidad < 1 || $cantidad > 5) {
        responder_error('Uno de los productos del carrito no es válido. Actualiza la página e inténtalo otra vez.');
    }
    if ($p['modalidad'] !== $modalidad) {
        responder_error('Todos los productos del carrito deben ser de la misma modalidad.');
    }
    if ($p['tipo'] === 'prueba') {
        $hayPrueba = true;
        $cantidad = 1;
    }
    $items[] = ['id' => $p['id'], 'nombre' => $p['nombre'], 'precio' => (int) $p['precio'], 'cantidad' => $cantidad];
    $total += (int) $p['precio'] * $cantidad;
}
if (!$items) {
    responder_error('Tu carrito está vacío.');
}
if ($hayPrueba && count($items) > 1) {
    responder_error('La clase de prueba se reserva sola, sin otros productos en el carrito.');
}

/* --- Datos del alumno --- */
$c = (array) ($e['cliente'] ?? []);
$texto = fn(string $campo, int $max) => mb_substr(trim((string) ($c[$campo] ?? '')), 0, $max);
$nombre = $texto('nombre', 100);
$email = $texto('email', 150);
$telefono = preg_replace('/[^\d+]/', '', $texto('telefono', 30));
$personas = (int) ($c['personas'] ?? 1) === 2 ? 2 : 1;
$objetivo = $texto('objetivo', 100);
$comuna = $texto('comuna', 80);
$direccion = $texto('direccion', 200);
$comentarios = $texto('comentarios', 1000);

if (mb_strlen($nombre) < 2) responder_error('Escribe tu nombre.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) responder_error('Escribe un correo válido.');
if (strlen(preg_replace('/\D/', '', $telefono)) < 8) responder_error('Escribe un teléfono válido.');
if ($modalidad === 'domicilio' && ($comuna === '' || mb_strlen($direccion) < 4)) {
    responder_error('Para entrenar a domicilio necesitamos tu comuna y dirección.');
}
if (empty($e['acepta'])) {
    responder_error('Debes aceptar las condiciones del servicio.');
}

/* --- Método de pago --- */
$metodo = $total === 0 ? 'gratis' : (string) ($e['metodo'] ?? '');
if ($metodo === 'transferencia' && empty($cfg['transferencia']['activa'])) {
    responder_error('El pago por transferencia no está disponible.');
}
if (!in_array($metodo, ['gratis', 'mercadopago', 'transferencia'], true)) {
    responder_error('Elige un método de pago.');
}

/* --- Horario: se revisa y se guarda dentro de una transacción para que nadie lo tome al mismo tiempo --- */
$fecha = (string) ($e['fecha'] ?? '');
$hora = (string) ($e['hora'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !preg_match('/^\d{2}:\d{2}$/', $hora)) {
    responder_error('Elige el día y la hora de tu primera sesión.');
}

$pdo = db();
$pdo->exec('BEGIN IMMEDIATE');
try {
    if ($metodo === 'gratis') {
        // Una clase de prueba gratis pendiente por correo o teléfono.
        $st = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE metodo = 'gratis' AND estado = 'reservado' AND inicio >= ? AND (email = ? OR telefono = ?)");
        $st->execute([fmt(ahora()), $email, $telefono]);
        if ((int) $st->fetchColumn() > 0) {
            $pdo->exec('ROLLBACK');
            responder_error('Ya tienes una clase de prueba reservada. Si necesitas cambiarla, escríbenos por WhatsApp.');
        }
    }
    if (!hora_libre($modalidad, $fecha, $hora)) {
        $pdo->exec('ROLLBACK');
        responder_error('Ese horario ya no está disponible. Elige otro, por favor.', 409);
    }

    $inicio = DateTimeImmutable::createFromFormat('Y-m-d H:i', "$fecha $hora");
    $fin = $inicio->modify('+' . (int) $cfg['duracion_sesion'] . ' minutes');
    [$estado, $expira] = match ($metodo) {
        'gratis' => ['reservado', null],
        'mercadopago' => ['pendiente_pago', ahora()->modify('+' . (int) $cfg['minutos_pago_online'] . ' minutes')],
        'transferencia' => ['pendiente_transferencia', ahora()->modify('+' . (int) $cfg['transferencia']['horas_para_pagar'] . ' hours')],
    };

    $ref = strtoupper(bin2hex(random_bytes(8)));
    $pdo->prepare('INSERT INTO pedidos (ref, creado, estado, metodo, total, items_json, modalidad, inicio, fin, expira,
            nombre, email, telefono, personas, objetivo, comuna, direccion, comentarios)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $ref, fmt(ahora()), $estado, $metodo, $total, json_encode($items, JSON_UNESCAPED_UNICODE), $modalidad,
            fmt($inicio), fmt($fin), $expira ? fmt($expira) : null,
            $nombre, $email, $telefono, $personas, $objetivo, $modalidad === 'domicilio' ? $comuna : '',
            $modalidad === 'domicilio' ? $direccion : '', $comentarios,
        ]);
    $pdo->exec('COMMIT');
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->exec('ROLLBACK');
    }
    error_log('Domicfit: error al crear pedido: ' . $ex->getMessage());
    responder_error('No pudimos guardar tu reserva. Inténtalo otra vez.', 500);
}

$pedido = buscar_pedido($ref);

if ($metodo === 'mercadopago') {
    try {
        $pref = mp_crear_preferencia($pedido, $items);
        $link = (string) ($pref['init_point'] ?? '');
        actualizar_pedido((int) $pedido['id'], ['mp_preference_id' => (string) ($pref['id'] ?? ''), 'mp_link' => $link]);
        responder(['ok' => true, 'ref' => $ref, 'redirigir' => $link]);
    } catch (Throwable $ex) {
        // Se libera el horario para no dejarlo tomado sin poder pagar.
        actualizar_pedido((int) $pedido['id'], ['estado' => 'cancelado']);
        responder_error('No pudimos conectar con Mercado Pago. Inténtalo en unos minutos o paga por transferencia.', 502);
    }
}

enviar_avisos($pedido);
responder(['ok' => true, 'ref' => $ref, 'redirigir' => 'pago.html?ref=' . $ref]);
