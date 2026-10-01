<?php
/**
 * POST /api/cisco-lookup.php
 *
 * Consulta as APIs oficiais da Cisco (SN2INFO, Product Info, EoX)
 *
 * Body JSON:
 *   { "serial": "TSP2951ABUS" }
 *   { "serial": "TSP2951ABUS", "part_number": "FPR3110-NGFW-K9" }
 *   { "part_number": "FPR3110-NGFW-K9" }  (só EoL, sem warranty)
 *
 * Retorna: produto, garantia, end-of-life
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/CiscoAPI.php';
require_once __DIR__ . '/CiscoBarcode.php';

// --- Credenciais Cisco API ---
// Registre sua app em https://apiconsole.cisco.com
// Selecione: Client Credentials + Support APIs (SN2INFO, Product Info, EoX)
$ciscoClientId     = CISCO_API_CLIENT_ID;
$ciscoClientSecret = CISCO_API_CLIENT_SECRET;

if (empty($ciscoClientId) || empty($ciscoClientSecret)) {
    jsonResponse([
        'error'   => true,
        'code'    => 'not_configured',
        'message' => 'Credenciais da Cisco API não configuradas. Defina CISCO_API_CLIENT_ID e CISCO_API_CLIENT_SECRET em api/config.local.php (ou como variáveis de ambiente).',
        'setup'   => [
            'passo_1' => 'Acesse https://apiconsole.cisco.com e faça login',
            'passo_2' => 'Registre uma nova aplicação',
            'passo_3' => 'Selecione "Client Credentials" como Grant Type',
            'passo_4' => 'Ative as APIs: Serial Number to Information, Product Information, End of Life',
            'passo_5' => 'Copie api/config.local.example.php para api/config.local.php e preencha Client ID e Client Secret',
        ],
    ], 503);
}

// --- Validação ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => true, 'message' => 'Método não permitido.'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$serial     = strtoupper(trim((string) ($input['serial']      ?? '')));
$partNumber = strtoupper(trim((string) ($input['part_number'] ?? '')));

if ($serial === '' && $partNumber === '') {
    jsonResponse(['error' => true, 'message' => 'Informe "serial" e/ou "part_number".'], 400);
}
// Até 75 serials/PIDs por chamada na API; aqui consultamos um por vez
if (strpos($serial . $partNumber, ',') !== false) {
    jsonResponse(['error' => true, 'message' => 'Consulte um serial/part number por vez.'], 400);
}

// --- Consulta ---
try {
    $cisco = new CiscoAPI($ciscoClientId, $ciscoClientSecret);

    if ($serial) {
        // Lookup completo: Product + Warranty + EoL
        $result = $cisco->fullLookup($serial, $partNumber ?: null);
    } else {
        // Só temos PID — consulta EoL e Product Info
        $result = [
            'serial_number' => null,
            'part_number'   => $partNumber,
            'produto'       => null,
            'garantia'      => null,
            'eol'           => null,
            'erros'         => [],
        ];

        // Product Info pelo PID
        $prodInfo = $cisco->getProductInfoByPID($partNumber);
        $p = $prodInfo['product_list'][0] ?? null;
        if (isset($prodInfo['error'])) {
            $result['erros'][] = 'product_info: ' . $prodInfo['message'];
        } elseif ($p && !isset($p['ErrorResponse'])) {
            $result['produto'] = [
                'pid'         => $p['orderable_pid'] ?? $p['product_id'] ?? $partNumber,
                'base_pid'    => $p['base_pid'] ?? null,
                'descricao'   => $p['product_name'] ?? $p['item_description'] ?? null,
                'categoria'   => $p['product_category'] ?? null,
                'serie'       => $p['product_series'] ?? null,
            ];
        }

        // EoL
        $eol = $cisco->getEoLByPID($partNumber);
        if (isset($eol['error'])) {
            $result['erros'][] = 'eol: ' . $eol['message'];
        } elseif (!empty($eol['eol'][0])) {
            $result['eol'] = $eol['eol'][0];
        }
    }

    // --- Atualiza catálogo local automaticamente ---
    // Falha no banco não pode esconder a resposta da Cisco, que já foi obtida.
    $pidParaCatalogo = $result['produto']['pid'] ?? $result['garantia']['produto']['pid'] ?? null;
    if ($pidParaCatalogo) try {
        $produto = $result['produto'] ?? $result['garantia']['produto'];
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM equipamentos WHERE part_number = ?");
        $stmt->execute([$pidParaCatalogo]);

        if (!$stmt->fetch()) {
            // Auto-cadastra no catálogo local
            $cat = CiscoBarcode::detectCategory($pidParaCatalogo);
            $db->prepare("
                INSERT IGNORE INTO equipamentos (part_number, base_pn, descricao, categoria, serie)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $pidParaCatalogo,
                $produto['base_pid'] ?? null,
                $produto['descricao'] ?? 'Importado da Cisco API',
                $cat,
                $produto['serie'] ?? null,
            ]);
            $result['catalogo_atualizado'] = true;
        }
    } catch (\PDOException $e) {
        $result['erros'][] = 'catalogo: ' . $e->getMessage();
    }

    jsonResponse(['success' => true, 'cisco' => $result]);

} catch (\Exception $e) {
    jsonResponse([
        'error'   => true,
        'message' => 'Erro ao consultar Cisco API: ' . $e->getMessage(),
    ], 500);
}
