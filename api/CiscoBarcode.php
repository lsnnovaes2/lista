<?php
/**
 * CiscoBarcode - Parser dos códigos de barras das etiquetas Cisco
 *
 * As etiquetas usam Data Identifiers ANSI MH10.8.2, impressos como "(1P)", "(S)" etc.
 * Dependendo do leitor/etiqueta o código vem com ou sem o prefixo:
 *   "1PFPR3110-NGFW-K9", "(1P)FPR3110-NGFW-K9" ou só "FPR3110-NGFW-K9".
 *
 * Sem prefixo, o tipo do campo é deduzido pelo formato do valor.
 * QR codes com vários campos (separados por GS, RS, quebra de linha ou ';') também são aceitos:
 * o campo mais útil para identificar o equipamento vira o principal.
 */
class CiscoBarcode
{
    public const FIELDS = [
        '1P'  => 'Product Number',
        '30P' => 'Base Part Number',
        'S'   => 'Serial Number',
        '7P'  => 'CLEI Code',
        '31P' => 'Version ID',
        'K'   => 'Customer PO',
        '3S'  => 'Package ID',
    ];

    // Colunas de `equipamentos` consultadas para cada tipo de campo
    private const SEARCH_COLUMNS = [
        '1P'  => ['part_number', 'base_pn', 'modelo'],
        '30P' => ['base_pn', 'part_number', 'modelo'],
        '7P'  => ['clei'],
    ];

    // Prioridade para escolher o campo principal num QR com vários campos
    private const PRIORITY = ['1P', '30P', '7P', 'S', '31P', 'K', '3S'];

    private const SERIAL_REGEX  = '/^[A-Z]{3}\d{4}[A-Z0-9]{4}$/';
    private const CLEI_REGEX    = '/^(?=.*[A-Z])(?=.*\d)[A-Z0-9]{10}$/';
    private const VERSION_REGEX = '/^V\d{2}$/';
    private const PO_REGEX      = '/^\d[\dA-Z-]*$/';

    private string $raw;
    private array $fields = [];   // [ ['type' => '1P', 'value' => '...', 'by' => 'prefixo'], ... ]
    private ?array $primary = null;

    public function __construct(string $raw)
    {
        $this->raw = trim($raw);
        $this->parse();
    }

    // =========================================================
    //  PARSE
    // =========================================================

    private function parse(): void
    {
        $parts = preg_split('/[\x1D\x1E\r\n;]+/', $this->raw, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($parts as $part) {
            $part = strtoupper(trim($part));
            // Remove cabeçalho ISO/IEC 15434 ("[)>" + "06") de códigos 2D
            $part = preg_replace('/^\[\)>\s*(06)?/', '', $part);
            if ($part === '') continue;

            $this->fields[] = $this->parseField($part);
        }

        usort($this->fields, function ($a, $b) {
            return $this->priorityOf($a['type']) <=> $this->priorityOf($b['type']);
        });
        $this->primary = $this->fields[0] ?? null;
    }

    private function parseField(string $part): array
    {
        // 1. Prefixo entre parênteses, ex.: "(1P)FPR3110-NGFW-K9" ou "(S) TSP2951ABUS"
        if (preg_match('/^\((\d{0,2}[A-Z])\)\s*(.+)$/', $part, $m) && isset(self::FIELDS[$m[1]])) {
            return ['type' => $m[1], 'value' => trim($m[2]), 'by' => 'prefixo'];
        }

        // 2. Prefixo colado no valor. Prefixos mais longos primeiro (30P antes de 3S/S).
        //    Os de uma letra (S, K) só valem se o restante tiver o formato esperado,
        //    senão "SFP-10G-SR" viraria serial "FP-10G-SR".
        foreach (['30P', '31P', '1P', '7P', '3S'] as $di) {
            if (strpos($part, $di) === 0 && strlen($part) > strlen($di)) {
                $value = substr($part, strlen($di));
                if ($this->prefixedValueIsPlausible($di, $value)) {
                    return ['type' => $di, 'value' => $value, 'by' => 'prefixo'];
                }
            }
        }
        if ($part[0] === 'S' && preg_match(self::SERIAL_REGEX, substr($part, 1))) {
            return ['type' => 'S', 'value' => substr($part, 1), 'by' => 'prefixo'];
        }
        if ($part[0] === 'K' && preg_match(self::PO_REGEX, substr($part, 1))) {
            return ['type' => 'K', 'value' => substr($part, 1), 'by' => 'prefixo'];
        }

        // 3. Sem prefixo: deduz pelo formato
        if (preg_match(self::SERIAL_REGEX, $part))  return ['type' => 'S',   'value' => $part, 'by' => 'heuristica'];
        if (preg_match(self::VERSION_REGEX, $part)) return ['type' => '31P', 'value' => $part, 'by' => 'heuristica'];
        if (preg_match(self::CLEI_REGEX, $part))    return ['type' => '7P',  'value' => $part, 'by' => 'heuristica'];

        return ['type' => '1P', 'value' => $part, 'by' => 'heuristica'];
    }

    private function prefixedValueIsPlausible(string $di, string $value): bool
    {
        switch ($di) {
            case '31P': return (bool) preg_match(self::VERSION_REGEX, $value);
            case '7P':  return (bool) preg_match('/^[A-Z0-9]{10}$/', $value);
            default:    return (bool) preg_match('/^[A-Z0-9][A-Z0-9\/+.-]*$/', $value);
        }
    }

    private function priorityOf(string $type): int
    {
        $i = array_search($type, self::PRIORITY, true);
        return $i === false ? PHP_INT_MAX : $i;
    }

    // =========================================================
    //  GETTERS
    // =========================================================

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function getFieldType(): ?string
    {
        return $this->primary['type'] ?? null;
    }

    public function getFieldName(): ?string
    {
        $type = $this->getFieldType();
        return $type ? self::FIELDS[$type] : null;
    }

    public function getValue(): ?string
    {
        return $this->primary['value'] ?? null;
    }

    /** Valor de um campo específico (útil para QR com vários campos) */
    public function getField(string $type): ?string
    {
        foreach ($this->fields as $f) {
            if ($f['type'] === $type) return $f['value'];
        }
        return null;
    }

    public function getSerial(): ?string
    {
        return $this->getField('S');
    }

    /**
     * Colunas do catálogo a consultar. Vazio quando o campo não identifica modelo
     * (serial, PO, package ID) — nesse caso a identificação vem da Cisco API.
     * Um CLEI deduzido sem prefixo também é buscado como part number,
     * já que alguns PNs têm 10 caracteres alfanuméricos.
     */
    public function getSearchColumns(): array
    {
        $type = $this->getFieldType();
        if ($type === '7P' && ($this->primary['by'] ?? '') === 'heuristica') {
            return ['clei', 'part_number', 'base_pn'];
        }
        return self::SEARCH_COLUMNS[$type] ?? [];
    }

    public function getCategory(): ?string
    {
        if (!in_array($this->getFieldType(), ['1P', '30P'], true)) {
            return null;
        }
        $cat = self::detectCategory($this->getValue());
        return $cat === 'outro' ? null : $cat;
    }

    /**
     * Detecta a categoria pelo padrão do part number.
     * Componentes (fan, fonte, ssd) vêm antes dos chassis — "FPR3K-FAN" não é firewall —
     * e access points antes de switches — "C9120AXI" não é Catalyst 9000 switch.
     */
    public static function detectCategory(string $pn): string
    {
        $patterns = [
            'fan'          => '/(-FAN|FAN-)/i',
            'fonte'        => '/(-PWR|-PSU|PWR-)/i',
            'ssd'          => '/(-SSD|SSD-)/i',
            'optico'       => '/^(GLC-|SFP-|QSFP|CFP)/i',
            'cabo'         => '/^(CAB-|STACK-)/i',
            'modulo'       => '/^(NIM-|SM-|C9\d{3}-NM|NM-|FPR\d+K?-NM)/i',
            'firewall'     => '/^(FPR|ASA|FTD)-?\d/i',
            'access_point' => '/^(C91\d{2}AX|AIR-|CW9)/i',
            'switch'       => '/^(C9\d{3}|C1000|WS-C|N9K|N3K)/i',
            'router'       => '/^(ISR|C8\d{3}|ASR)/i',
        ];
        foreach ($patterns as $cat => $regex) {
            if (preg_match($regex, $pn)) return $cat;
        }
        return 'outro';
    }

    public function toArray(): array
    {
        return [
            'raw'         => $this->raw,
            'value'       => $this->getValue(),
            'field_type'  => $this->getFieldType(),
            'field_name'  => $this->getFieldName(),
            'detected_by' => $this->primary['by'] ?? null,
            'category'    => $this->getCategory(),
            'campos'      => array_map(fn($f) => [
                'tipo'  => $f['type'],
                'nome'  => self::FIELDS[$f['type']],
                'valor' => $f['value'],
            ], $this->fields),
        ];
    }
}
