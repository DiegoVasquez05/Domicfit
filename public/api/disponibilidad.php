<?php
// GET ?modalidad=sede|domicilio&fecha=YYYY-MM-DD  ->  horas disponibles de ese día
require __DIR__ . '/lib/core.php';

$modalidad = (string) ($_GET['modalidad'] ?? '');
$fecha = (string) ($_GET['fecha'] ?? '');
if (!in_array($modalidad, ['sede', 'domicilio'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    responder_error('Parámetros inválidos.');
}
responder(['ok' => true, 'horas' => disponibilidad($modalidad, $fecha)]);
