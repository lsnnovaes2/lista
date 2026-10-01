<?php
/**
 * POST /api/scan.php
 *
 * Body JSON: { "barcode": "FPR3110-NGFW-K9" }
 * Opcionais:  { "barcode": "...", "local": "Almoxarifado", "operador": "Luciano" }
 *
 * Retorna info do equipamento + registra a leitura.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/CiscoBarcode.php';

// --- Validação ---
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['barcode'])) {
    jsonResponse(['error' => 'Campo "barcode" é obrigatório.'], 400);
}

$barcode  = trim($input['barcode']);
$local    = $input['local']    ?? null;
$operador = $input['operador'] ?? null;

// --- Parse do barcode ---
$parser = new CiscoBarcode($barcode);
$parsed = $parser->toArray();

// --- Busca no catálogo ---
$db   = getDB();
$equip = null;

$columns = $parser->getSearchColumns();
$value   = $parser->getValue();

if ($columns && $value) {
    // Monta query dinâmica: WHERE col1 = ? OR col2 = ? ...
    $clauses = array_map(fn($c) => "$c = ?", $columns);
    // Também tenta LIKE pra pegar variantes
    $likeClauses = array_map(fn($c) => "$c LIKE ?", $columns);

    $sql = "SELECT * FROM equipamentos WHERE "
         . implode(' OR ', $clauses)
         . " OR " . implode(' OR ', $likeClauses)
         . " LIMIT 5";

    $params = [];
    foreach ($columns as $_) $params[] = $value;
    foreach ($columns as $_) $params[] = "%{$value}%";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    if (count($results) === 1) {
        $equip = $results[0];
    } elseif (count($results) > 1) {
        // Prioriza match exato
        foreach ($results as $r) {
            if (strcasecmp($r['part_number'], $value) === 0 ||
                strcasecmp($r['base_pn'], $value) === 0) {
                $equip = $r;
                break;
            }
        }
        if (!$equip) $equip = $results[0];
    }
}

// --- Decodifica specs JSON ---
if ($equip && $equip['specs']) {
    $equip['specs'] = json_decode($equip['specs'], true);
}

// --- Registra leitura ---
$serialDetected = ($parser->getFieldType() === 'S') ? $value : null;

$stmt = $db->prepare("
    INSERT INTO leituras (equipamento_id, serial_number, barcode_raw, campo_tipo, local_leitura, lido_por)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $equip['id'] ?? null,
    $serialDetected,
    $barcode,
    $parser->getFieldType(),
    $local,
    $operador,
]);

$leituraId = $db->lastInsertId();

// --- Resposta ---
$response = [
    'encontrado' => $equip !== null,
    'parse'      => $parsed,
    'leitura_id' => (int) $leituraId,
];

if ($equip) {
    $response['equipamento'] = [
        'id'         => (int) $equip['id'],
        'part_number'=> $equip['part_number'],
        'base_pn'    => $equip['base_pn'],
        'modelo'     => $equip['modelo'],
        'descricao'  => $equip['descricao'],
        'categoria'  => $equip['categoria'],
        'serie'      => $equip['serie'],
        'specs'      => $equip['specs'],
    ];
} else {
    $response['sugestao'] = [
        'categoria_detectada' => $parsed['category'],
        'mensagem' => 'Equipamento não encontrado no catálogo. Verifique o part number ou cadastre manualmente.',
    ];
}

jsonResponse($response);
