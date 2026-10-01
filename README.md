# Cisco Equipment Scanner

Aplicação PHP para leitura de códigos de barras de equipamentos Cisco, identificando o equipamento sem abrir a caixa.

## Requisitos

- PHP 7.4+ com extensão `pdo_mysql`
- MySQL 5.7+ ou MariaDB 10.3+
- Servidor web (Apache/Nginx) ou PHP built-in server
- HTTPS para usar câmera em produção (localhost funciona sem)

## Instalação rápida

```bash
# 1. Clone/copie os arquivos para seu servidor web

# 2. Crie o banco de dados
mysql -u root -p < db/schema.sql

# 3. Ajuste as credenciais em api/config.php
#    DB_HOST, DB_NAME, DB_USER, DB_PASS

# 4. Inicie o servidor (dev)
php -S 0.0.0.0:8080

# 5. Acesse: http://localhost:8080
```

## Estrutura

```
cisco-scanner/
├── index.html          # Frontend (scanner + histórico + catálogo)
├── api/
│   ├── config.php      # Configuração do banco
│   ├── CiscoBarcode.php # Parser de códigos Cisco
│   ├── scan.php        # POST - identifica equipamento
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

## Como usar

1. Abra no celular (funciona melhor com câmera traseira)
2. Clique "Iniciar Câmera" e aponte para o código de barras do campo **(1P)** ou **(30P)**
3. O sistema identifica o equipamento e mostra modelo, specs e categoria
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
