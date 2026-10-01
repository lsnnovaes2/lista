# Cisco Equipment Scanner

Aplicação PHP para leitura de códigos de barras de equipamentos Cisco, identificando o equipamento sem abrir a caixa.

## Requisitos

- PHP 8.0+ com extensões `pdo_mysql` e `curl`
- MySQL 5.7+ ou MariaDB 10.3+
- Servidor web (Apache/Nginx) ou PHP built-in server
- HTTPS para usar câmera em produção (localhost funciona sem)

## Instalação rápida

```bash
# 1. Clone/copie os arquivos para seu servidor web

# 2. Crie o banco de dados
mysql -u root -p < db/schema.sql

# 3. Crie api/config.local.php (fora do git) com as credenciais
cp api/config.local.example.php api/config.local.php
#    DB_HOST, DB_NAME, DB_USER, DB_PASS, CISCO_API_CLIENT_ID, CISCO_API_CLIENT_SECRET
#    (ou exporte como variáveis de ambiente)

# 4. Inicie o servidor (dev)
php -S 0.0.0.0:8080

# 5. Acesse: http://localhost:8080
```

## Estrutura

```
cisco-scanner/
├── index.html          # Frontend (scanner + histórico + catálogo)
├── api/
│   ├── config.php      # Configuração (lê config.local.php / env)
│   ├── config.local.example.php # Modelo das credenciais
│   ├── CiscoBarcode.php # Parser de códigos Cisco
│   ├── CiscoAPI.php    # Cliente das Cisco Support APIs
│   ├── scan.php        # POST - identifica equipamento
│   ├── cisco-lookup.php # POST - garantia / produto / EoL na Cisco
│   ├── historico.php   # GET  - lista leituras
│   └── catalogo.php    # GET/POST - gerencia catálogo
└── db/
    └── schema.sql      # Schema + seed de equipamentos
```

## Campos Cisco nas etiquetas

| Prefixo | Campo             | Exemplo            |
|---------|-------------------|--------------------|
| (1P)    | Product Number    | FPR3110-NGFW-K9    |
| (30P)   | Base Part Number  | FPR-3110           |
| (S)     | Serial Number     | TSP2951ABUS        |
| (7P)    | CLEI Code         | FWMCN10ARB         |
| (31P)   | Version ID        | V01, V02           |
| (K)     | Customer PO       | 5180285-D          |
| (3S)    | Package ID        | SCC+1231599183     |

O código pode vir com o prefixo (`1PFPR3110-NGFW-K9`, `(S)TSP2951ABUS`) ou sem ele.
Sem prefixo, o tipo é deduzido pelo formato: serial `LLLAASSXXXX` (11 caracteres),
CLEI com 10 caracteres alfanuméricos, versão `Vnn` e, no resto, part number.
QR codes com vários campos (separados por GS, quebra de linha ou `;`) também são aceitos.

## Cisco Support APIs

O botão **Consultar na Cisco** usa as [Support APIs](https://apiconsole.cisco.com/page/read/supportapis):

| API | Endpoint | Uso |
|-----|----------|-----|
| Serial Number to Information (SN2INFO v2) | `/sn2info/v2/coverage/summary/serial_numbers/{sn}` | Garantia, contrato, PID do serial |
| Product Information v1 | `/product/v1/information/serial_numbers/{sn}` e `/product_ids/{pid}` | Nome, série, categoria |
| EoX v5 | `/supporttools/eox/rest/5/EOXByProductID/1/{pid}` | Datas de End-of-Life e migração |

Configuração:

1. Em https://apiconsole.cisco.com registre uma app com Grant Type **Client Credentials**
   e habilite as três APIs acima.
2. Coloque Client ID e Secret em `api/config.local.php`.
3. A garantia (SN2INFO) só retorna dados de seriais cobertos pelo contrato da sua conta
   (SNTC/PSS); fora disso a API responde "não encontrado" ou 401/403.

O token OAuth2 (`id.cisco.com`) fica em cache em `/tmp/cisco_api` por 1 hora.
Lendo um **serial**, a consulta traz produto + garantia + EoL. Lendo um **part number**,
traz produto + EoL. PIDs retornados pela Cisco que não estão no catálogo são cadastrados automaticamente.

## Como usar

1. Abra no celular (funciona melhor com câmera traseira)
2. Clique "Iniciar Câmera" e aponte para o código de barras do campo **(1P)**, **(30P)** ou **(S)**
3. O sistema identifica o equipamento e mostra modelo, specs e categoria
   (pelo serial, use "Consultar Garantia na Cisco" para identificar)
4. Ou digite o código manualmente no campo de busca

## Adicionando equipamentos ao catálogo

Via API:
```bash
curl -X POST http://localhost:8080/api/catalogo.php \
  -H "Content-Type: application/json" \
  -d '{
    "part_number": "C9300-48T-A",
    "base_pn": "C9300",
    "descricao": "Catalyst 9300 48-port Data",
    "categoria": "switch",
    "serie": "Catalyst 9300",
    "specs": {"portas": "48x 1G", "poe": false}
  }'
```

Ou diretamente no MySQL via `INSERT INTO equipamentos (...)`.
