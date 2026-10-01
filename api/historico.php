<?php
/**
 * GET /api/historico.php
 *
 * Query: ?limit=30&q=FPR3110
 *
 * Lista as leituras mais recentes, com dados do equipamento quando identificado.
 */

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Método não permitido.'], 405);
}

$limit = (int) ($_GET['limit'] ?? 30);
$limit = max(1, min($limit, 200));
$q     = trim($_GET['q'] ?? '');

$sql = "
    SELECT l.id, l.serial_number, l.barcode_raw, l.campo_tipo, l.local_leitura,
           l.lido_por, l.created_at,
           e.id AS equipamento_id, e.part_number, e.descricao, e.categoria
    FROM leituras l
    LEFT JOIN equipamentos e ON e.id = l.equipamento_id
";
$params = [];

if ($q !== '') {
    $sql .= " WHERE l.barcode_raw LIKE ? OR l.serial_number LIKE ?
              OR e.part_number LIKE ? OR e.descricao LIKE ?";
    $like   = "%{$q}%";
    $params = [$like, $like, $like, $like];
}

$sql .= " ORDER BY l.created_at DESC, l.id DESC LIMIT {$limit}";

$stmt = getDB()->prepare($sql);
$stmt->execute($params);
$leituras = $stmt->fetchAll();

foreach ($leituras as &$l) {
    $l['id'] = (int) $l['id'];
    $l['equipamento_id'] = $l['equipamento_id'] !== null ? (int) $l['equipamento_id'] : null;
}
unset($l);

jsonResponse([
    'total'    => count($leituras),
    'leituras' => $leituras,
]);
