<?php
/**
 * CiscoAPI - Cliente para Cisco Support APIs
 *
 * APIs utilizadas:
 *   - SN2INFO v2: Warranty + Coverage por Serial Number
 *   - Product Information: Detalhes por Serial ou PID
 *
 * Requisitos:
 *   - Conta Cisco com acesso SNTC (Smart Net Total Care) ou PSS (Partner Support)
 *   - Client ID + Client Secret registrados em https://apiconsole.cisco.com
 *
 * Auth: OAuth2 Client Credentials → Bearer Token (1h de validade)
 */
class CiscoAPI
{
    private const TOKEN_URL = 'https://id.cisco.com/oauth2/default/v1/token';
    private const API_BASE  = 'https://apix.cisco.com';

    private string $clientId;
    private string $clientSecret;
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;

    // Cache simples em arquivo (evita re-auth a cada request)
    private string $cacheDir;

    public function __construct(string $clientId, string $clientSecret, string $cacheDir = '/tmp/cisco_api')
    {
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->cacheDir     = $cacheDir;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0700, true);
        }

        $this->loadCachedToken();
    }

    // =========================================================
    //  AUTH
    // =========================================================

    /**
     * Obtém access token via OAuth2 Client Credentials
     */
    private function authenticate(): void
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Cisco OAuth: falha de conexão ({$curlErr})");
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("Cisco OAuth falhou (HTTP {$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new \RuntimeException('Cisco OAuth: access_token ausente na resposta');
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600) - 60; // margem de 60s

        $this->saveCachedToken();
    }

    private function getToken(): string
    {
        if (!$this->accessToken || time() >= $this->tokenExpiry) {
            $this->authenticate();
        }
        return $this->accessToken;
    }

    private function loadCachedToken(): void
    {
        $file = $this->tokenFile();
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && ($data['expiry'] ?? 0) > time()) {
                $this->accessToken = $data['token'];
                $this->tokenExpiry = $data['expiry'];
            }
        }
    }

    private function saveCachedToken(): void
    {
        $file = $this->tokenFile();
        file_put_contents($file, json_encode([
            'token'  => $this->accessToken,
            'expiry' => $this->tokenExpiry,
        ]));
        @chmod($file, 0600); // o token dá acesso às APIs: só o dono lê
    }

    private function tokenFile(): string
    {
        return $this->cacheDir . '/token_' . substr(sha1($this->clientId), 0, 12) . '.json';
    }

    // =========================================================
    //  HTTP
    // =========================================================

    private function apiGet(string $path, array $params = [], bool $retried = false): array
    {
        $url = self::API_BASE . $path;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->getToken(),
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Cisco API: falha de conexão ({$curlErr})");
        }

        if ($httpCode === 401 && !$retried) {
            // Token expirou/revogado: re-autentica e tenta uma única vez.
            // Um segundo 401 (ex.: app sem acesso à API) cai no tratamento de erro abaixo.
            $this->accessToken = null;
            return $this->apiGet($path, $params, true);
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = $data['message'] ?? $data['ErrorResponse']['APIError']['ErrorDescription'] ?? $response;
            if ($httpCode === 401 || $httpCode === 403) {
                $message = "Acesso negado (HTTP {$httpCode}). Verifique se a app no API Console "
                         . "tem esta API habilitada e se a conta possui o entitlement (SNTC/PSS). {$message}";
            }
            return [
                'error'     => true,
                'http_code' => $httpCode,
                'message'   => is_string($message) ? $message : json_encode($message),
            ];
        }

        return $data ?? [];
    }

    /**
     * Junta IDs (serial/PID) para o path da URL: "A,B,C", cada um url-encoded.
     * PIDs como "ISR4331/K9" têm barra, que quebraria o path sem encoding.
     */
    private static function idList($ids): string
    {
        $ids = is_array($ids) ? $ids : explode(',', $ids);
        $ids = array_filter(array_map('trim', $ids), 'strlen');
        return implode(',', array_map('rawurlencode', $ids));
    }

    // =========================================================
    //  SN2INFO - Warranty & Coverage
    // =========================================================

    /**
     * Consulta garantia e cobertura por número(s) de série
     *
     * @param string|string[] $serialNumbers Até 75 serial numbers
     * @return array
     */
    public function getWarrantyBySerialNumbers($serialNumbers): array
    {
        $serialNumbers = self::idList($serialNumbers);

        $data = $this->apiGet("/sn2info/v2/coverage/summary/serial_numbers/{$serialNumbers}");

        if (isset($data['error'])) {
            return $data;
        }

        // Normaliza a resposta
        $results = [];
        $records = $data['serial_numbers'] ?? [];

        // Formato da resposta (coverage/summary): cada registro traz warranty_* e
        // coverage_* como campos simples; PIDs vêm em orderable_pid_list / base_pid_list.
        foreach ($records as $record) {
            if (isset($record['ErrorResponse'])) {
                // Serial inválido ou fora do entitlement da conta
                $results[] = [
                    'serial_number' => $record['sr_no'] ?? null,
                    'erro'          => $record['ErrorResponse']['APIError']['ErrorDescription'] ?? 'Serial não encontrado',
                ];
                continue;
            }

            $orderable = $record['orderable_pid_list'][0] ?? [];
            $warranties = [];
            if (!empty($record['warranty_end_date'])) {
                $warranties[] = [
                    'tipo'      => $record['warranty_type'] ?? null,
                    'descricao' => $record['warranty_type_description'] ?? 'Garantia',
                    'data_fim'  => $record['warranty_end_date'],
                ];
            }
            if (!empty($record['covered_product_line_end_date'])) {
                $warranties[] = [
                    'tipo'      => 'contrato',
                    'descricao' => 'Fim do contrato' . (!empty($record['service_contract_number'])
                                    ? ' #' . $record['service_contract_number'] : ''),
                    'data_fim'  => $record['covered_product_line_end_date'],
                ];
            }

            $results[] = [
                'serial_number'   => $record['sr_no'] ?? null,
                'coberto'         => ($record['is_covered'] ?? '') === 'YES',
                'cobertura_fim'   => $record['coverage_end_date'] ?? null,
                'contrato_numero' => $record['service_contract_number'] ?? null,
                'contrato'        => $record['contract_site_customer_name'] ?? null,
                'produto' => [
                    'pid'        => $orderable['orderable_pid'] ?? null,
                    'base_pid'   => $record['base_pid_list'][0]['base_pid'] ?? null,
                    'descricao'  => $orderable['item_description'] ?? null,
                    'tipo'       => $orderable['item_type'] ?? null,
                ],
                'garantias'       => $warranties,
                'service_line'    => $record['service_line_descr'] ?? null,
            ];
        }

        return ['success' => true, 'resultados' => $results];
    }

    /**
     * Verifica status de cobertura (mais simples que warranty)
     */
    public function getCoverageStatus($serialNumbers): array
    {
        $serialNumbers = self::idList($serialNumbers);

        return $this->apiGet("/sn2info/v2/coverage/status/serial_numbers/{$serialNumbers}");
    }

    // =========================================================
    //  PRODUCT INFORMATION
    // =========================================================

    /**
     * Informações do produto por serial number(s)
     */
    public function getProductInfoBySerial($serialNumbers): array
    {
        $serialNumbers = self::idList($serialNumbers);

        $data = $this->apiGet("/product/v1/information/serial_numbers/{$serialNumbers}");

        if (isset($data['error'])) {
            return $data;
        }

        $results = [];
        foreach ($data['product_list'] ?? [] as $product) {
            if (isset($product['ErrorResponse'])) continue;
            $results[] = [
                'serial_number'      => $product['sr_no'] ?? null,
                'pid'                => $product['orderable_pid'] ?? null,
                'base_pid'           => $product['base_pid'] ?? null,
                'descricao'          => $product['product_name'] ?? $product['item_description'] ?? null,
                'categoria'          => $product['product_category'] ?? null,
                'subcategoria'       => $product['product_subcategory'] ?? null,
                'serie'              => $product['product_series'] ?? null,
                'tipo'               => $product['product_type'] ?? null,
                'suporte_ativo'      => $product['product_support_page'] ?? null,
                'visio_stencil'      => $product['visio_stencil_url'] ?? null,
                'rich_media'         => $product['rich_media_urls'] ?? null,
                'dimensoes'          => isset($product['dimensions_value'])
                                        ? trim($product['dimensions_value'] . ' ' . ($product['dimensions_format'] ?? ''))
                                        : null,
                'peso'               => $product['weight'] ?? null,
                'form_factor'        => $product['form_factor'] ?? null,
            ];
        }

        return ['success' => true, 'produtos' => $results];
    }

    /**
     * Informações do produto por Product ID (PID / Part Number)
     */
    public function getProductInfoByPID($productIds): array
    {
        $productIds = self::idList($productIds);

        return $this->apiGet("/product/v1/information/product_ids/{$productIds}");
    }

    // =========================================================
    //  EOX - End of Life
    // =========================================================

    /**
     * Datas de End-of-Life por Product ID
     */
    public function getEoLByPID($productIds): array
    {
        $productIds = self::idList($productIds);

        $data = $this->apiGet("/supporttools/eox/rest/5/EOXByProductID/1/{$productIds}", [
            'responseencoding' => 'json',
        ]);

        if (isset($data['error'])) {
            return $data;
        }

        $results = [];
        foreach ($data['EOXRecord'] ?? [] as $eox) {
            // PID sem anúncio de EoL volta como registro com EOXError (não é falha)
            if (!empty($eox['EOXError']) || empty($eox['EOLProductID'])) continue;

            $mig = $eox['EOXMigrationDetails'] ?? [];
            $results[] = [
                'pid'                     => $eox['EOLProductID'] ?? null,
                'descricao'               => $eox['ProductIDDescription'] ?? null,
                'boletim'                 => $eox['LinkToProductBulletinURL'] ?? null,
                'end_of_sale'             => $eox['EndOfSaleDate']['value'] ?? null,
                'end_of_sw_maintenance'   => $eox['EndOfSWMaintenanceReleases']['value'] ?? null,
                'end_of_security_vuln'    => $eox['EndOfSecurityVulSupportDate']['value'] ?? null,
                'end_of_routine_failure'  => $eox['EndOfRoutineFailureAnalysisDate']['value'] ?? null,
                'end_of_service_attach'   => $eox['EndOfServiceContractRenewal']['value'] ?? null,
                'last_date_support'       => $eox['LastDateOfSupport']['value'] ?? null,
                'end_of_svc_attach'       => $eox['EndOfSvcAttachDate']['value'] ?? null,
                'migracao_pid'            => ($mig['MigrationProductId'] ?? '') ?: null,
                'migracao_nome'           => ($mig['MigrationProductName'] ?? '') ?: null,
                'migracao_url'            => ($mig['MigrationProductInfoURL'] ?? '') ?: null,
            ];
        }

        // A API devolve "" para datas ainda não anunciadas
        foreach ($results as &$r) {
            foreach ($r as $k => $v) {
                if ($v === '') $r[$k] = null;
            }
        }
        unset($r);

        return ['success' => true, 'eol' => $results];
    }

    // =========================================================
    //  HELPER: Consulta completa (tudo de uma vez)
    // =========================================================

    /**
     * Faz lookup completo: Product Info + Warranty + EoL
     */
    public function fullLookup(string $serialNumber, ?string $partNumber = null): array
    {
        $result = [
            'serial_number' => $serialNumber,
            'part_number'   => $partNumber,
            'produto'       => null,
            'garantia'      => null,
            'eol'           => null,
            'erros'         => [],
        ];

        // 1. Product Info pelo Serial
        try {
            $prodInfo = $this->getProductInfoBySerial($serialNumber);
            if (isset($prodInfo['error'])) {
                $result['erros'][] = 'product_info: ' . $prodInfo['message'];
            } elseif (!empty($prodInfo['produtos'][0])) {
                $result['produto'] = $prodInfo['produtos'][0];
                // Se não tínhamos o PID, pegamos daqui
                if (!$partNumber && $result['produto']['pid']) {
                    $partNumber = $result['produto']['pid'];
                    $result['part_number'] = $partNumber;
                }
            }
        } catch (\Exception $e) {
            $result['erros'][] = 'product_info: ' . $e->getMessage();
        }

        // 2. Warranty
        try {
            $warranty = $this->getWarrantyBySerialNumbers($serialNumber);
            $w = $warranty['resultados'][0] ?? null;
            if (isset($warranty['error'])) {
                $result['erros'][] = 'warranty: ' . $warranty['message'];
            } elseif ($w && isset($w['erro'])) {
                $result['erros'][] = 'warranty: ' . $w['erro'];
            } elseif ($w) {
                $result['garantia'] = $w;
                // Sem Product Info (ex.: API não habilitada), usa o PID da garantia
                if (!$partNumber && !empty($w['produto']['pid'])) {
                    $partNumber = $w['produto']['pid'];
                    $result['part_number'] = $partNumber;
                }
            }
        } catch (\Exception $e) {
            $result['erros'][] = 'warranty: ' . $e->getMessage();
        }

        // 3. End-of-Life (precisa do PID)
        if ($partNumber) {
            try {
                $eol = $this->getEoLByPID($partNumber);
                if (isset($eol['error'])) {
                    $result['erros'][] = 'eol: ' . $eol['message'];
                } elseif (!empty($eol['eol'][0])) {
                    $result['eol'] = $eol['eol'][0];
                }
            } catch (\Exception $e) {
                $result['erros'][] = 'eol: ' . $e->getMessage();
            }
        }

        return $result;
    }
}
