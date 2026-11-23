<?php
// Imitación mínima de la API de Mercado Pago para las pruebas.
// - Si el pagador se llama "FALLA", responde con error al crear el pago.
// - El pago 666 responde aprobado pero con un monto distinto (debe rechazarse).
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$estado = sys_get_temp_dir() . '/domicfit-mp-falso.json';
header('Content-Type: application/json');

if ($ruta === '/checkout/preferences' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $cuerpo = json_decode(file_get_contents('php://input'), true);
    if (($cuerpo['payer']['name'] ?? '') === 'FALLA') {
        http_response_code(500);
        exit('{"message":"error simulado"}');
    }
    $total = array_sum(array_map(fn($i) => $i['unit_price'] * $i['quantity'], $cuerpo['items']));
    file_put_contents($estado, json_encode(['ref' => $cuerpo['external_reference'], 'total' => $total, 'cuerpo' => $cuerpo]));
    exit(json_encode(['id' => 'PREF-PRUEBA', 'init_point' => 'https://mp.example/pagar?pref=PREF-PRUEBA']));
}
if (preg_match('#^/v1/payments/(\d+)$#', $ruta, $m)) {
    $s = json_decode((string) @file_get_contents($estado), true) ?: [];
    $monto = $m[1] === '666' ? 1 : ($s['total'] ?? 0);
    exit(json_encode(['id' => (int) $m[1], 'status' => 'approved', 'external_reference' => $s['ref'] ?? '',
        'transaction_amount' => $monto, 'currency_id' => 'CLP']));
}
http_response_code(404);
echo '{}';
