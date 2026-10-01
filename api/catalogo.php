<?php
/**
 * GET  /api/catalogo.php?q=FPR&categoria=firewall
 *      Lista equipamentos do catálogo.
 *
 * POST /api/catalogo.php
 *      Body JSON: { "part_number": "C9300-48T-A", "descricao": "...", "categoria": "switch",
 *                   "base_pn": "C9300", "modelo": "...", "clei": "...", "serie": "...",
 *                   "specs": { ... } }
 *      Cadastra ou atualiza (pelo part_number).
 */

require_once __DIR__ . '/config.php';

const CATEGORIAS = ['firewall', 'switch', 'router', 'access_point', 'modulo',
                    'fonte', 'fan', 'ssd', 'cabo', 'optico', 'outro'];

$db = getDB();

// --- GET: listagem ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q         = trim($_GET['q'] ?? '');
    $categoria = trim($_GET['categoria'] ?? '');

    $where  = [];
    $params = [];

    if ($q !== '') {
        $where[] = "(part_number LIKE ? OR base_pn LIKE ? OR modelo LIKE ?
                     OR clei LIKE ? OR descricao LIKE ? OR serie LIKE ?)";
        $like = "%{$q}%";
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    if ($categoria !== '') {
        $where[]  = "categoria = ?";
        $params[] = $categoria;
    }

    $sql = "SELECT * FROM equipamentos"
         . ($where ? " WHERE " . implode(' AND ', $where) : '')
         . " ORDER BY categoria, part_number";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $equipamentos = $stmt->fetchAll();

    foreach ($equipamentos as &$eq) {
        $eq['id']    = (int) $eq['id'];
        $eq['specs'] = $eq['specs'] ? json_decode($eq['specs'], true) : null;
    }
    unset($eq);

    jsonResponse([
        'total'        => count($equipamentos),
        'equipamentos' => $equipamentos,
    ]);
}

// --- POST: cadastro / atualização ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['part_number']) || empty($input['descricao'])) {
        jsonResponse(['error' => 'Campos "part_number" e "descricao" são obrigatórios.'], 400);
    }

    $categoria = $input['categoria'] ?? 'outro';
    if (!in_array($categoria, CATEGORIAS, true)) {
        jsonResponse(['error' => 'Categoria inválida. Use: ' . implode(', ', CATEGORIAS)], 400);
    }

    $clei = isset($input['clei']) ? strtoupper(trim($input['clei'])) : null;
    if ($clei !== null && $clei !== '' && !preg_match('/^[A-Z0-9]{10}$/', $clei)) {
        jsonResponse(['error' => 'CLEI deve ter exatamente 10 caracteres alfanuméricos.'], 400);
    }

    $specs = $input['specs'] ?? null;
    if ($specs !== null && !is_array($specs)) {
        jsonResponse(['error' => 'Campo "specs" deve ser um objeto JSON.'], 400);
    }

    $stmt = $db->prepare("
        INSERT INTO equipamentos (part_number, base_pn, modelo, clei, descricao, categoria, serie, specs)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            base_pn   = VALUES(base_pn),
            modelo    = VALUES(modelo),
            clei      = VALUES(clei),
            descricao = VALUES(descricao),
            categoria = VALUES(categoria),
            serie     = VALUES(serie),
            specs     = VALUES(specs)
    ");
    $stmt->execute([
        strtoupper(trim($input['part_number'])),
        $input['base_pn'] ?? null,
        $input['modelo']  ?? null,
        $clei ?: null,
        trim($input['descricao']),
        $categoria,
        $input['serie']   ?? null,
        $specs !== null ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null,
    ]);

    // rowCount: 1 = inserido, 2 = atualizado, 0 = sem mudança
    $acao = match ($stmt->rowCount()) {
        1       => 'inserido',
        2       => 'atualizado',
        default => 'sem alteração',
    };

    jsonResponse(['ok' => true, 'acao' => $acao], $acao === 'inserido' ? 201 : 200);
}

jsonResponse(['error' => 'Método não permitido.'], 405);
