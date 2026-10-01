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
            mkdir($this->cacheDir, 0755, true);
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
        curl_close($ch);

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
        $file = $this->cacheDir . '/token.json';
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
        file_put_contents($this->cacheDir . '/token.json', json_encode([
            'token'  => $this->accessToken,
            'expiry' => $this->tokenExpiry,
        ]));
    }

    // =========================================================
    //  HTTP
    // =========================================================

    private function apiGet(string $path, array $params = []): array
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
        curl_close($ch);

        if ($httpCode === 401) {
            // Token expirou, tenta re-auth uma vez
            $this->accessToken = null;
            return $this->apiGet($path, $params);
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            return [
                'error'     => true,
                'http_code' => $httpCode,
                'message'   => $data['message'] ?? $response,
            ];
        }

        return $data ?? [];
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
        if (is_array($serialNumbers)) {
            $serialNumbers = implode(',', $serialNumbers);
        }

        $data = $this->apiGet("/sn2info/v2/coverage/summary/serial_numbers/{$serialNumbers}");

        if (isset($data['error'])) {
            return $data;
        }

        // Normaliza a resposta
        $results = [];
        $records = $data['serial_numbers'] ?? [];

        foreach ($records as $record) {
            $warranties = [];
            foreach ($record['warranty_end_date'] ?? [] as $w) {
                $warranties[] = [
                    'tipo'       => $w['warranty_type'] ?? null,
                    'descricao'  => $w['warranty_description'] ?? null,
                    'data_fim'   => $w['warranty_end_date'] ?? null,
                ];
            }

            $results[] = [
                'serial_number'   => $record['sr_no'] ?? null,
                'coberto'         => ($record['is_covered'] ?? '') === 'YES',
                'status_cobertura'=> $record['coverage_status'] ?? null,
                'contrato'        => $record['contract_site_customer_name'] ?? null,
                'produto' => [
                    'pid'        => $record['orderable_pid'] ?? null,
                    'descricao'  => $record['item_description'] ?? null,
                    'tipo'       => $record['item_type'] ?? null,
                ],
                'garantias'       => $warranties,
                'data_envio'      => $record['shipped_date'] ?? null,
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
        if (is_array($serialNumbers)) {
            $serialNumbers = implode(',', $serialNumbers);
        }

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
        if (is_array($serialNumbers)) {
            $serialNumbers = implode(',', $serialNumbers);
        }

        $data = $this->apiGet("/product/v1/information/serial_numbers/{$serialNumbers}");

        if (isset($data['error'])) {
            return $data;
        }

        $results = [];
        foreach ($data['product_list'] ?? [] as $product) {
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
                'dimensoes'          => $product['dimensions'] ?? null,
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
        if (is_array($productIds)) {
            $productIds = implode(',', $productIds);
        }

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
        if (is_array($productIds)) {
            $productIds = implode(',', $productIds);
        }

        $data = $this->apiGet("/supporttools/eox/rest/5/EOXByProductID/1/{$productIds}", [
            'responseencoding' => 'json',
        ]);

        if (isset($data['error'])) {
            return $data;
        }

        $results = [];
        foreach ($data['EOXRecord'] ?? [] as $eox) {
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
                'migracao_pid'            => $eox['MigrationInformation']['MigrationProductId'] ?? null,
                'migracao_nome'           => $eox['MigrationInformation']['MigrationProductName'] ?? null,
                'migracao_url'            => $eox['MigrationInformation']['MigrationProductInfoURL'] ?? null,
            ];
        }

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
            if (!empty($prodInfo['produtos'][0])) {
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
            if (!empty($warranty['resultados'][0])) {
                $result['garantia'] = $warranty['resultados'][0];
            }
        } catch (\Exception $e) {
            $result['erros'][] = 'warranty: ' . $e->getMessage();
        }

        // 3. End-of-Life (precisa do PID)
        if ($partNumber) {
            try {
                $eol = $this->getEoLByPID($partNumber);
                if (!empty($eol['eol'][0])) {
                    $result['eol'] = $eol['eol'][0];
                }
            } catch (\Exception $e) {
                $result['erros'][] = 'eol: ' . $e->getMessage();
            }
        }

        return $result;
    }
}
