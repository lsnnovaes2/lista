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

// --- Credenciais Cisco API ---
// Registre sua app em https://apiconsole.cisco.com
// Selecione: Client Credentials + Support APIs (SN2INFO, Product Info, EoX)
$ciscoClientId     = CISCO_API_CLIENT_ID     ?? '';
$ciscoClientSecret = CISCO_API_CLIENT_SECRET ?? '';

if (empty($ciscoClientId) || empty($ciscoClientSecret)) {
    jsonResponse([
        'error'   => true,
        'message' => 'Credenciais da Cisco API não configuradas. Defina CISCO_API_CLIENT_ID e CISCO_API_CLIENT_SECRET no config.php.',
        'setup'   => [
            'passo_1' => 'Acesse https://apiconsole.cisco.com e faça login',
            'passo_2' => 'Registre uma nova aplicação',
            'passo_3' => 'Selecione "Client Credentials" como Grant Type',
            'passo_4' => 'Ative as APIs: Serial Number to Information, Product Information, End of Life',
            'passo_5' => 'Copie Client ID e Client Secret para api/config.php',
        ],
    ], 503);
}

// --- Validação ---
$input = json_decode(file_get_contents('php://input'), true);

$serial     = trim($input['serial']      ?? '');
$partNumber = trim($input['part_number'] ?? '');

if (empty($serial) && empty($partNumber)) {
    jsonResponse(['error' => 'Informe "serial" e/ou "part_number".'], 400);
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
        if (!empty($prodInfo['product_list'][0])) {
            $p = $prodInfo['product_list'][0];
            $result['produto'] = [
                'pid'         => $p['orderable_pid'] ?? $partNumber,
                'descricao'   => $p['product_name'] ?? $p['item_description'] ?? null,
                'categoria'   => $p['product_category'] ?? null,
                'serie'       => $p['product_series'] ?? null,
            ];
        }

        // EoL
        $eol = $cisco->getEoLByPID($partNumber);
        if (!empty($eol['eol'][0])) {
            $result['eol'] = $eol['eol'][0];
        }
    }

    // --- Atualiza catálogo local automaticamente ---
    if (!empty($result['produto']['pid'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id FROM equipamentos WHERE part_number = ?");
        $stmt->execute([$result['produto']['pid']]);

        if (!$stmt->fetch()) {
            // Auto-cadastra no catálogo local
            $cat = detectCategory($result['produto']['pid']);
            $db->prepare("
                INSERT IGNORE INTO equipamentos (part_number, base_pn, descricao, categoria, serie)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $result['produto']['pid'],
                $result['produto']['base_pid'] ?? null,
                $result['produto']['descricao'] ?? 'Importado da Cisco API',
                $cat,
                $result['produto']['serie'] ?? null,
            ]);
        }
    }

    jsonResponse(['success' => true, 'cisco' => $result]);

} catch (\Exception $e) {
    jsonResponse([
        'error'   => true,
        'message' => 'Erro ao consultar Cisco API: ' . $e->getMessage(),
    ], 500);
}

/**
 * Detecta categoria pelo padrão do part number
 */
function detectCategory(string $pn): string
{
    $patterns = [
        'firewall'     => '/^(FPR|ASA|FTD)\d/i',
        'switch'       => '/^(C9[0-9]{3}|WS-C|N9K|N3K)/i',
        'router'       => '/^(ISR|C8[0-9]{3}|ASR)/i',
        'access_point' => '/^(C9[0-9]{3}AX|AIR-|CW9)/i',
        'fan'          => '/(-FAN|FAN-)/i',
        'fonte'        => '/(-PWR|-PSU|PWR-)/i',
        'ssd'          => '/(-SSD|SSD-)/i',
        'optico'       => '/^(GLC-|SFP-|QSFP)/i',
    ];
    foreach ($patterns as $cat => $regex) {
        if (preg_match($regex, $pn)) return $cat;
    }
    return 'outro';
}
