<?php
// Datos públicos que necesita la página de reserva (nunca incluye claves).
require __DIR__ . '/lib/core.php';

$cfg = config();
$dias = [];
foreach ($cfg['agenda'] as $modalidad => $agenda) {
    $dias[$modalidad] = array_map('intval', array_keys($agenda['horarios'] ?? $agenda['rangos'] ?? []));
}
responder([
    'ok' => true,
    'hoy' => ahora()->format('Y-m-d'),
    'dias_maximos' => (int) $cfg['dias_maximos'],
    'dias_con_atencion' => $dias,
    'transferencia' => !empty($cfg['transferencia']['activa']),
    'horas_transferencia' => (int) ($cfg['transferencia']['horas_para_pagar'] ?? 24),
    'minutos_pago_online' => (int) $cfg['minutos_pago_online'],
]);
