INSERT INTO sites (id, name) VALUES (1, 'Canareef Resort Maldives');

INSERT INTO meter_groups (id, site_id, name, entry_type, sort_order) VALUES
 (1, 1, 'Feeder Reading 1', 'interval', 1),
 (2, 1, 'Feeder Reading 2', 'interval', 2),
 (3, 1, 'Solar',            'interval', 3),
 (4, 1, 'Solar inverters',  'daily',    4),
 (5, 1, 'Water',            'daily',    5),
 (6, 1, 'Generators',       'daily',    6),
 (7, 1, 'Fuel',             'daily',    7);

-- Feeder Reading 1 (hourly kW)
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, interval_min, window_start, window_end, is_incomer, sort_order) VALUES
 ( 6,1,1,'HV-NETWORK','HV Network','feeder','interval','kW',60,'00:00','23:00',1, 1),
 ( 7,1,1,'JSB-9001','JSB-9001','feeder','interval','kW',60,'00:00','23:00',0, 2),
 ( 8,1,1,'JSB-8001','JSB-8001','feeder','interval','kW',60,'00:00','23:00',0, 3),
 ( 9,1,1,'JSB-9002','JSB-9002','feeder','interval','kW',60,'00:00','23:00',0, 4),
 (10,1,1,'JSB-8002','JSB-8002','feeder','interval','kW',60,'00:00','23:00',0, 5),
 (11,1,1,'EX-STAFF-BLK','Ex. Staff Blk','feeder','interval','kW',60,'00:00','23:00',0, 6),
 (12,1,1,'S-STAFF-BLK','S. Staff Blk','feeder','interval','kW',60,'00:00','23:00',0, 7),
 (13,1,1,'MDB-116-117','MDB 116-117 BV 169-189','feeder','interval','kW',60,'00:00','23:00',0, 8),
 (14,1,1,'MDB-118-119','MDB 118-119 BV 272-290','feeder','interval','kW',60,'00:00','23:00',0, 9),
 (15,1,1,'KATHEEB-VILLA','Katheeb Villa','feeder','interval','kW',60,'00:00','23:00',0,10),
 (16,1,1,'STP-ENG-OFFICE','STP Eng Office','feeder','interval','kW',60,'00:00','23:00',0,11),
 (17,1,1,'LAUNDRY-IRONS','Laundry Irons','feeder','interval','kW',60,'00:00','23:00',0,12);

-- Feeder Reading 2 (hourly kW)
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, interval_min, window_start, window_end, sort_order) VALUES
 (18,1,2,'MAIN-STORE','Main Store','feeder','interval','kW',60,'00:00','23:00', 1),
 (19,1,2,'MAIN-KITCHEN','Main Kitchen','feeder','interval','kW',60,'00:00','23:00', 2),
 (20,1,2,'SECURITY-HUT','Security Hut','feeder','interval','kW',60,'00:00','23:00', 3),
 (21,1,2,'HEAT-EX-PUMP','Heat Ex. Pump','feeder','interval','kW',60,'00:00','23:00', 4),
 (22,1,2,'MDB-120-121','MDB 120-121 BV 150-167','feeder','interval','kW',60,'00:00','23:00', 5),
 (23,1,2,'IT-BLDG','IT Bldg','feeder','interval','kW',60,'00:00','23:00', 6),
 (24,1,2,'HR-MOSQUE','HR / Mosque','feeder','interval','kW',60,'00:00','23:00', 7),
 (25,1,2,'POWER-HOUSE','Power House','feeder','interval','kW',60,'00:00','23:00', 8),
 (26,1,2,'LAUNDRY-MACHINES','Laundry Machines','feeder','interval','kW',60,'00:00','23:00', 9),
 (27,1,2,'LAUNDRY-DRYERS','Laundry Dryers','feeder','interval','kW',60,'00:00','23:00',10),
 (28,1,2,'WATER-PLANT','Water Plant','feeder','interval','kW',60,'00:00','23:00',11),
 (29,1,2,'INCINERATOR-WS','Incinerator Work Shop','feeder','interval','kW',60,'00:00','23:00',12),
 (30,1,2,'PUMP-ROOM','Pump Room','feeder','interval','kW',60,'00:00','23:00',13),
 (31,1,2,'CARPENTRY','Carpentry','feeder','interval','kW',60,'00:00','23:00',14);

-- Solar plant (half-hourly kW from the inverter display). Add plant 2 later as id 41+ etc.
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, interval_min, window_start, window_end, sort_order) VALUES
 (40,1,3,'SOLAR-PLANT-1','Solar Plant 1','solar','interval','kW',30,'06:00','18:00',1);

-- Solar inverters (daily kWh counters), linked to plant 1
INSERT INTO meters (id, site_id, group_id, parent_id, code, name, kind, reading_type, unit, sort_order) VALUES
 (51,1,4,40,'INV-1','Inverter 1','inverter','cumulative','kWh',1),
 (52,1,4,40,'INV-2','Inverter 2','inverter','cumulative','kWh',2),
 (53,1,4,40,'INV-3','Inverter 3','inverter','cumulative','kWh',3),
 (54,1,4,40,'INV-4','Inverter 4','inverter','cumulative','kWh',4),
 (55,1,4,40,'INV-5','Inverter 5','inverter','cumulative','kWh',5),
 (56,1,4,40,'INV-6','Inverter 6','inverter','cumulative','kWh',6),
 (57,1,4,40,'INV-7','Inverter 7','inverter','cumulative','kWh',7),
 (58,1,4,40,'INV-8','Inverter 8','inverter','cumulative','kWh',8),
 (59,1,4,40,'INV-9','Inverter 9','inverter','cumulative','kWh',9);

-- Water lines (daily m3). Only Line 1 active today.
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, sort_order, active) VALUES
 (60,1,5,'WATER-L1','Line 1 Main','water','cumulative','m3', 1,1),
 (61,1,5,'WATER-L2','Line 2','water','cumulative','m3', 2,0),
 (62,1,5,'WATER-L3','Line 3','water','cumulative','m3', 3,0),
 (63,1,5,'WATER-L4','Line 4','water','cumulative','m3', 4,0),
 (64,1,5,'WATER-L5','Line 5','water','cumulative','m3', 5,0),
 (65,1,5,'WATER-L6','Line 6','water','cumulative','m3', 6,0),
 (66,1,5,'WATER-L7','Line 7','water','cumulative','m3', 7,0),
 (67,1,5,'WATER-L8','Line 8','water','cumulative','m3', 8,0),
 (68,1,5,'WATER-L9','Line 9','water','cumulative','m3', 9,0),
 (69,1,5,'WATER-L10','Line 10','water','cumulative','m3',10,0),
 (70,1,5,'WATER-L11','Line 11','water','cumulative','m3',11,0);

-- Generators (daily kWh counter, display x 0.1 = kWh)
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, multiplier, sort_order) VALUES
 (80,1,6,'GEN-1','Generator 1','generator','cumulative','kWh',0.1,1),
 (81,1,6,'GEN-2','Generator 2','generator','cumulative','kWh',0.1,2),
 (82,1,6,'GEN-3','Generator 3','generator','cumulative','kWh',0.1,3),
 (83,1,6,'GEN-4','Generator 4','generator','cumulative','kWh',0.1,4);

-- Diesel
INSERT INTO meters (id, site_id, group_id, code, name, kind, reading_type, unit, sort_order, active) VALUES
 (90,1,7,'DIESEL-PH','Powerhouse diesel flow meter','fuel','cumulative','L',1,1),
 (91,1,7,'DIESEL-OTHER','Other diesel','fuel','cumulative','L',2,0);

INSERT INTO settings (`key`, `value`) VALUES
 ('timezone','Indian/Maldives'),
 ('late_minutes','20'),
 ('operator_edit_days','1'),
 ('median_days','14'),
 ('spike_factor','3'),
 ('spike_floor_feeder','1'),
 ('spike_floor_solar','20'),
 ('daily_change_pct','40'),
 ('feeder_sum_check','0'),
 ('feeder_sum_tolerance_pct','25'),
 ('genset_day_start','08:00'),
 ('genset_oil_bar_min',NULL),
 ('genset_water_c_max',NULL),
 ('genset_battery_v_min',NULL),
 ('genset_hz_min',NULL),
 ('genset_hz_max',NULL),
 ('genset_volts_min',NULL),
 ('genset_volts_max',NULL),
 ('alert_email',NULL);
