-- Cisco Equipment Scanner - Database Schema
-- MySQL 5.7+

CREATE DATABASE IF NOT EXISTS cisco_scanner
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE cisco_scanner;

-- Catálogo de equipamentos Cisco
CREATE TABLE IF NOT EXISTS equipamentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  part_number VARCHAR(100) NOT NULL COMMENT 'SKU / Prod.# (campo 1P)',
  base_pn VARCHAR(100) DEFAULT NULL COMMENT 'Base P/N (campo 30P)',
  modelo VARCHAR(100) DEFAULT NULL COMMENT 'Model # / CPN',
  clei VARCHAR(10) DEFAULT NULL COMMENT 'CLEI code (campo 7P)',
  descricao VARCHAR(255) NOT NULL,
  categoria ENUM('firewall','switch','router','access_point','modulo','fonte','fan','ssd','cabo','optico','outro') NOT NULL DEFAULT 'outro',
  serie VARCHAR(100) DEFAULT NULL COMMENT 'Linha do produto (ex: Firepower 3100)',
  specs JSON DEFAULT NULL COMMENT 'Especificações técnicas em JSON',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_part_number (part_number),
  INDEX idx_base_pn (base_pn),
  INDEX idx_clei (clei),
  INDEX idx_categoria (categoria)
) ENGINE=InnoDB;

-- Registro de leituras / inventário
CREATE TABLE IF NOT EXISTS leituras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  equipamento_id INT DEFAULT NULL,
  serial_number VARCHAR(100) DEFAULT NULL,
  barcode_raw VARCHAR(500) NOT NULL COMMENT 'Valor bruto lido do código de barras',
  campo_tipo VARCHAR(10) DEFAULT NULL COMMENT 'Tipo do campo Cisco: 1P, 30P, S, etc.',
  local_leitura VARCHAR(255) DEFAULT NULL,
  observacao TEXT DEFAULT NULL,
  lido_por VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (equipamento_id) REFERENCES equipamentos(id) ON DELETE SET NULL,
  INDEX idx_serial (serial_number),
  INDEX idx_data (created_at)
) ENGINE=InnoDB;

-- Seed: equipamentos comuns das fotos + alguns extras
INSERT INTO equipamentos (part_number, base_pn, modelo, descricao, categoria, serie, specs) VALUES
-- Firepower 3100 Series
('FPR3110-NGFW-K9', 'FPR-3110', 'FPR-3110', 'Cisco Firepower 3110 NGFW Appliance', 'firewall', 'Firepower 3100',
 '{"throughput_fw":"17 Gbps","throughput_ips":"11 Gbps","interfaces":"8x 10G SFP+, 8x 1G RJ45, 2x Mgmt","max_sessions":"1.5M","rack_units":1}'),

('FPR3120-NGFW-K9', 'FPR-3120', 'FPR-3120', 'Cisco Firepower 3120 NGFW Appliance', 'firewall', 'Firepower 3100',
 '{"throughput_fw":"24 Gbps","throughput_ips":"17 Gbps","interfaces":"8x 10G SFP+, 8x 1G RJ45, 2x Mgmt","max_sessions":"2M","rack_units":1}'),

('FPR3130-NGFW-K9', 'FPR-3130', 'FPR-3130', 'Cisco Firepower 3130 NGFW Appliance', 'firewall', 'Firepower 3100',
 '{"throughput_fw":"38 Gbps","throughput_ips":"24 Gbps","interfaces":"8x 25G SFP28, 8x 10G SFP+, 2x Mgmt","max_sessions":"3M","rack_units":1}'),

('FPR3140-NGFW-K9', 'FPR-3140', 'FPR-3140', 'Cisco Firepower 3140 NGFW Appliance', 'firewall', 'Firepower 3100',
 '{"throughput_fw":"45 Gbps","throughput_ips":"30 Gbps","interfaces":"8x 25G SFP28, 8x 10G SFP+, 2x 40G QSFP+, 2x Mgmt","max_sessions":"5M","rack_units":1}'),

-- Componentes FPR3K (das fotos)
('FPR3K-FAN', 'FPR3K-FAN', NULL, 'Cisco Firepower 3000 Series Fan Module', 'fan', 'Firepower 3000',
 '{"tipo":"Fan Tray","compativel":"FPR-3110, FPR-3120, FPR-3130, FPR-3140"}'),

('FPR3K-PWR-AC-400', 'FPR3K-PWR-AC-400', NULL, 'Cisco Firepower 3000 Series 400W AC Power Supply', 'fonte', 'Firepower 3000',
 '{"potencia":"400W","tipo":"AC","redundancia":"1+1","compativel":"FPR-3110, FPR-3120, FPR-3130, FPR-3140"}'),

('FPR3K-SSD900', 'FPR3K-SSD900', NULL, 'Cisco Firepower 3000 Series 900GB SSD', 'ssd', 'Firepower 3000',
 '{"capacidade":"900 GB","tipo":"SSD","compativel":"FPR-3110, FPR-3120, FPR-3130, FPR-3140"}'),

-- Firepower 1000 Series
('FPR1010-NGFW-K9', 'FPR-1010', 'FPR-1010', 'Cisco Firepower 1010 NGFW Desktop', 'firewall', 'Firepower 1000',
 '{"throughput_fw":"2 Gbps","interfaces":"8x 1G RJ45","rack_units":0}'),

('FPR1120-NGFW-K9', 'FPR-1120', 'FPR-1120', 'Cisco Firepower 1120 NGFW Appliance', 'firewall', 'Firepower 1000',
 '{"throughput_fw":"3 Gbps","interfaces":"4x 1G SFP, 4x 1G RJ45","rack_units":1}'),

('FPR1140-NGFW-K9', 'FPR-1140', 'FPR-1140', 'Cisco Firepower 1140 NGFW Appliance', 'firewall', 'Firepower 1000',
 '{"throughput_fw":"6 Gbps","interfaces":"4x 10G SFP+, 4x 1G RJ45","rack_units":1}'),

-- Catalyst Switches
('C9200L-24T-4G-E', 'C9200L', 'C9200L', 'Catalyst 9200L 24-port Data, 4x1G uplink, Network Essentials', 'switch', 'Catalyst 9200',
 '{"portas":"24x 1G RJ45","uplinks":"4x 1G SFP","poe":false,"rack_units":1}'),

('C9200L-24P-4G-E', 'C9200L', 'C9200L', 'Catalyst 9200L 24-port PoE+, 4x1G uplink, Network Essentials', 'switch', 'Catalyst 9200',
 '{"portas":"24x 1G RJ45","uplinks":"4x 1G SFP","poe":true,"poe_budget":"370W","rack_units":1}'),

('C9300-48T-A', 'C9300', 'C9300', 'Catalyst 9300 48-port Data, Network Advantage', 'switch', 'Catalyst 9300',
 '{"portas":"48x 1G RJ45","uplinks":"Modular","poe":false,"rack_units":1}'),

-- ISR Routers
('ISR4331/K9', 'ISR4331', 'ISR4331', 'Cisco ISR 4331 Integrated Services Router', 'router', 'ISR 4000',
 '{"throughput":"100 Mbps - 300 Mbps","wan":"3x GE","slots":"1x NIM, 1x SM","rack_units":1}'),

('ISR4451-X/K9', 'ISR4451', 'ISR4451-X', 'Cisco ISR 4451-X Integrated Services Router', 'router', 'ISR 4000',
 '{"throughput":"1-2 Gbps","wan":"4x GE","slots":"3x NIM, 2x SM","rack_units":2}'),

-- Access Points
('C9120AXI-B', 'C9120AXI', 'C9120AXI', 'Catalyst 9120AXI Wi-Fi 6 AP, Internal Antennas', 'access_point', 'Catalyst 9100',
 '{"wifi":"Wi-Fi 6 (802.11ax)","bandas":"2.4/5 GHz","mimo":"4x4:4 + 4x4:4","antena":"Internal"}'),

-- Módulos SFP
('GLC-LH-SMD', 'GLC-LH-SMD', NULL, 'Cisco 1G SFP LH (Long Haul) Single-Mode 10km', 'optico', 'SFP',
 '{"velocidade":"1 Gbps","tipo":"Single-Mode","alcance":"10 km","conector":"LC"}'),

('SFP-10G-SR', 'SFP-10G-SR', NULL, 'Cisco 10G SFP+ SR Multi-Mode 400m', 'optico', 'SFP+',
 '{"velocidade":"10 Gbps","tipo":"Multi-Mode","alcance":"400 m","conector":"LC"}')

ON DUPLICATE KEY UPDATE descricao=VALUES(descricao);

-- CLEI codes conhecidos
UPDATE equipamentos SET clei = 'FWMCN10ARB' WHERE part_number = 'FPR3110-NGFW-K9';
