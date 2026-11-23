<?php
// GET ?ref=XXXX[&payment_id=123]  ->  estado del pedido para la página de resultado.
require __DIR__ . '/lib/core.php';

$ref = strtoupper((string) ($_GET['ref'] ?? ''));
if (!preg_match('/^[A-F0-9]{16}$/', $ref)) {
    responder_error('Pedido no encontrado.', 404);
}
$pedido = buscar_pedido($ref);
if ($pedido === null) {
    responder_error('Pedido no encontrado.', 404);
}

// Al volver de Mercado Pago se consulta el pago directamente, por si el aviso automático aún no llega.
$paymentId = (string) ($_GET['payment_id'] ?? $_GET['collection_id'] ?? '');
if ($paymentId !== '' && $pedido['estado'] === 'pendiente_pago') {
    try {
        $actualizado = mp_sincronizar_pago($paymentId);
        if ($actualizado !== null && $actualizado['ref'] === $ref) {
            $pedido = $actualizado;
        }
    } catch (Throwable $ex) {
        // Se muestra el estado guardado; el webhook lo actualizará.
    }
}

$cfg = config();
$estado = $pedido['estado'];
$vencido = in_array($estado, ['pendiente_pago', 'pendiente_transferencia'], true)
    && $pedido['expira'] !== null && $pedido['expira'] < fmt(ahora());

$respuesta = [
    'ok' => true,
    'ref' => $pedido['ref'],
    'estado' => $vencido ? 'vencido' : $estado,
    'metodo' => $pedido['metodo'],
    'total' => (int) $pedido['total'],
    'items' => json_decode($pedido['items_json'], true),
    'modalidad' => $pedido['modalidad'],
    'inicio' => $pedido['inicio'],
    'inicio_texto' => fecha_legible($pedido['inicio']),
    'nombre' => explode(' ', $pedido['nombre'])[0],
    'whatsapp' => $cfg['whatsapp'],
];
if ($estado === 'pendiente_transferencia' && !$vencido) {
    $t = $cfg['transferencia'];
    $respuesta['transferencia'] = array_intersect_key($t, array_flip(['titular', 'rut', 'banco', 'tipo', 'numero', 'correo']));
    $respuesta['expira'] = fecha_legible($pedido['expira']);
}
if ($estado === 'pendiente_pago' && !$vencido && $pedido['mp_link']) {
    $respuesta['link_pago'] = $pedido['mp_link'];
}
responder($respuesta);
