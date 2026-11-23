<?php
// Mercado Pago avisa aquí cada vez que cambia un pago. Se consulta el pago directo a su API antes de marcarlo como pagado.
require __DIR__ . '/lib/core.php';

$cuerpo = json_decode((string) file_get_contents('php://input'), true) ?: [];
$tipo = (string) ($cuerpo['type'] ?? $_GET['type'] ?? $_GET['topic'] ?? '');
$id = (string) ($cuerpo['data']['id'] ?? $_GET['data_id'] ?? $_GET['id'] ?? '');

if ($tipo === 'payment' && $id !== '') {
    try {
        mp_sincronizar_pago($id);
    } catch (Throwable $ex) {
        error_log('Domicfit webhook: ' . $ex->getMessage());
        http_response_code(500); // Mercado Pago reintentará más tarde
        exit;
    }
}
http_response_code(200);
echo 'ok';
