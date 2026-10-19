-- Flowork schema baseline: every table and column the app uses, no data.
-- Applied first by maintenance/setup_database.php (CREATE TABLE IF NOT EXISTS),
-- so a new install has the full structure before any page is opened.
-- Regenerate from a complete database with:
--   mysqldump --no-data --skip-add-drop-table --skip-comments --skip-triggers <db>
-- then add IF NOT EXISTS and drop AUTO_INCREMENT values.

CREATE TABLE IF NOT EXISTS `amount_split_points` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `device` varchar(100) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NOT NULL DEFAULT (current_timestamp() + interval 30 day),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `token_2` (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `auction_contract` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `auction` int(11) NOT NULL DEFAULT 0,
  `contract` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `backup_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `file_size_kb` int(11) DEFAULT 0,
  `table_count` int(11) DEFAULT 0,
  `status` enum('success','failed','partial') DEFAULT 'success',
  `triggered_by` enum('manual','cron','token') DEFAULT 'manual',
  `notes` text DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `balanced_bales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `splitid` int(11) DEFAULT 1,
  `growerid` int(11) DEFAULT 0,
  `transporter_growersid` int(11) NOT NULL,
  `selling_pointid` int(11) DEFAULT 0,
  `sell_date` date DEFAULT NULL,
  `batch_reference` varchar(60) DEFAULT '',
  `created_at` varchar(100) DEFAULT '',
  `stoporder_processed` int(11) NOT NULL DEFAULT 0,
  `sale_batchid` int(11) NOT NULL DEFAULT 0,
  `printed` int(11) NOT NULL DEFAULT 0,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `rule_so_mode` varchar(10) DEFAULT NULL,
  `rule_rbz_usd` decimal(6,4) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `balanced_bales_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `grower_chargeid` int(11) NOT NULL,
  `balanced_balesid` int(11) NOT NULL,
  `usd_value` double(100,6) NOT NULL DEFAULT 0.000000,
  `zim_value` double(100,6) NOT NULL DEFAULT 0.000000,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bale_junus` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `transporter_growersid` int(11) NOT NULL,
  `bale_massid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `bale_group` int(11) DEFAULT 0,
  `lot` int(11) DEFAULT 0,
  `splitid` int(11) NOT NULL DEFAULT 1,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bale_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) DEFAULT '',
  `subcontractor` varchar(100) DEFAULT '',
  `is_primary` tinyint(4) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `bale_mass` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `seasonid` int(11) DEFAULT 0,
  `selling_pointid` int(11) DEFAULT 0,
  `temp_bacode` varchar(100) NOT NULL,
  `mass` decimal(10,2) NOT NULL,
  `used` int(11) NOT NULL DEFAULT 0,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `booked_bales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `growerid` int(11) NOT NULL,
  `book_date` varchar(100) DEFAULT '',
  `comm_graded` varchar(100) DEFAULT '',
  `bales_booked` varchar(100) DEFAULT '',
  `bales_delivered` varchar(100) DEFAULT '',
  `bales_handled` varchar(100) DEFAULT '',
  `sell_date` varchar(100) DEFAULT '',
  `book_user` varchar(100) DEFAULT '',
  `ip_address` varchar(100) DEFAULT '',
  `floor` varchar(100) DEFAULT '',
  `sale` varchar(100) DEFAULT '',
  `booked_id` varchar(100) DEFAULT '',
  `reoffer` varchar(100) DEFAULT '',
  `booked_by` varchar(100) DEFAULT '',
  `prefered_rep` varchar(100) DEFAULT '',
  `last_sell` varchar(100) DEFAULT '',
  `prefered_time` varchar(100) DEFAULT '',
  `venue` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `booking_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `system_key` varchar(100) NOT NULL,
  `vector` varchar(100) NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `buyer` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL,
  `buyer_code` varchar(100) DEFAULT '',
  `contact_name` varchar(80) DEFAULT '',
  `phone` varchar(30) DEFAULT '',
  `email` varchar(100) DEFAULT '',
  `country` varchar(60) DEFAULT 'Zimbabwe',
  `notes` text DEFAULT '',
  `active` tinyint(4) DEFAULT 1,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `buyer_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `buyerid` int(11) NOT NULL,
  `season_year` int(11) NOT NULL,
  `contract_number` varchar(60) DEFAULT '',
  `credit_limit_usd` decimal(14,2) DEFAULT 0.00,
  `credit_used_usd` decimal(14,2) DEFAULT 0.00,
  `target_bales` int(11) DEFAULT 0,
  `target_mass_kg` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','suspended','expired') DEFAULT 'active',
  `notes` text DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bc` (`selling_pointid`,`buyerid`,`season_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `buyer_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `buyerid` int(11) NOT NULL DEFAULT 1,
  `description` varchar(100) NOT NULL,
  `active` int(11) NOT NULL DEFAULT 1,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `buyer_portal_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `buyerid` int(11) NOT NULL,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(120) DEFAULT '',
  `last_login` timestamp NULL DEFAULT NULL,
  `active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_buyer` (`buyerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cf_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `season` varchar(20) DEFAULT '',
  `contract_date` date NOT NULL,
  `expected_bales` int(11) DEFAULT 0,
  `expected_mass` decimal(10,2) DEFAULT 0.00,
  `input_value_usd` decimal(14,2) DEFAULT 0.00,
  `status` enum('active','completed','defaulted','cancelled') DEFAULT 'active',
  `notes` text DEFAULT '',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp` (`selling_pointid`),
  KEY `idx_grower` (`growerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `cf_inputs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `input_type` varchar(60) DEFAULT '',
  `description` varchar(255) DEFAULT '',
  `quantity` decimal(10,2) DEFAULT 0.00,
  `unit` varchar(20) DEFAULT '',
  `unit_cost_usd` decimal(10,4) DEFAULT 0.0000,
  `total_usd` decimal(14,2) DEFAULT 0.00,
  `issued_date` date NOT NULL,
  `issued_by` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contract` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `charge_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(80) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `currency` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(40) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `daily_totals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `balanced_balesid` int(11) NOT NULL,
  `exchange_rateid` int(11) DEFAULT 0,
  `mass` decimal(12,2) NOT NULL DEFAULT 0.00,
  `usd_value` double(100,4) NOT NULL DEFAULT 0.0000,
  `zim_value` double(100,4) NOT NULL DEFAULT 0.0000,
  `laid_bales` int(11) NOT NULL DEFAULT 0,
  `sold_bales` int(11) NOT NULL DEFAULT 0,
  `rejected_bales` int(11) NOT NULL DEFAULT 0,
  `average_price` double(100,4) NOT NULL DEFAULT 0.0000,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `daily_totals_net_amount` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `balanced_balesid` int(11) NOT NULL,
  `usd_net_value` double(100,4) NOT NULL DEFAULT 0.0000,
  `zim_net_value` double(100,4) NOT NULL DEFAULT 0.0000,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dtn_bbid` (`balanced_balesid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `daily_totals_split_amounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `balanced_balesid` int(11) NOT NULL,
  `usd_split_value` double(100,4) NOT NULL DEFAULT 0.0000,
  `zim_split_value` double(100,4) NOT NULL DEFAULT 0.0000,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `deduction_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT '',
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Array of {label, type, value, currency}' CHECK (json_valid(`items`)),
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp` (`selling_pointid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `device_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `officer_id` int(11) NOT NULL,
  `latitude` decimal(10,7) DEFAULT 0.0000000,
  `longitude` decimal(10,7) DEFAULT 0.0000000,
  `accuracy` decimal(8,2) DEFAULT 0.00,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `officer_id` (`officer_id`),
  KEY `recorded_at` (`recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dispatch` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `dispatch_destinationid` int(11) DEFAULT 0,
  `sold_baleid` int(11) DEFAULT 0,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dispatch_destination` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `selling_point_dispatchid` int(11) DEFAULT 0,
  `driver_name` varchar(100) DEFAULT '',
  `driver_surname` varchar(100) DEFAULT '',
  `driver_id_number` varchar(100) DEFAULT '',
  `horse_num` varchar(100) DEFAULT '',
  `trailer_num` varchar(100) DEFAULT '',
  `open_close` int(11) NOT NULL DEFAULT 1,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dispatch_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dispatch_sessionid` int(11) NOT NULL,
  `sold_balesid` int(11) DEFAULT NULL,
  `bale_massid` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `dispatch_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) DEFAULT 0,
  `destination` varchar(120) DEFAULT '',
  `dispatch_date` date DEFAULT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `created_by` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `email_report_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `report_date` date NOT NULL,
  `sent_to` text DEFAULT '',
  `method` varchar(20) DEFAULT 'mail',
  `status` enum('success','failed') DEFAULT 'success',
  `error_msg` text DEFAULT '',
  `sent_by` int(11) DEFAULT NULL,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp_date` (`selling_pointid`,`report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `event_notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `exchange_rate` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `amount` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `start_of_dayid` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `selling_pointid` int(11) DEFAULT 0,
  `sell_date` date DEFAULT NULL,
  `source` varchar(50) DEFAULT 'manual',
  `userid` int(11) DEFAULT 0,
  `seasonid` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `file_uploads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `file_type` varchar(30) NOT NULL,
  `original_filename` varchar(255) DEFAULT '',
  `direction` enum('upload','generated') DEFAULT 'upload',
  `sell_date` date DEFAULT NULL,
  `records_count` int(11) DEFAULT 0,
  `status` varchar(20) DEFAULT 'pending',
  `notes` text DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `file_type` (`file_type`),
  KEY `sell_date` (`sell_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `floor_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `queue_date` date NOT NULL,
  `queue_position` int(11) NOT NULL,
  `checked_in_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('waiting','selling','sold','skipped','no_show') DEFAULT 'waiting',
  `notes` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_sp_date` (`selling_pointid`,`queue_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `floor_summary` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `ticketsid` int(11) NOT NULL,
  `row` varchar(20) DEFAULT '',
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `flowork_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_num` varchar(50) NOT NULL,
  `selling_pointid` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `status` varchar(20) DEFAULT 'Unpaid',
  `issue_date` date NOT NULL,
  `paid_date` date DEFAULT NULL,
  `license_key` varchar(100) DEFAULT NULL,
  `duration_days` int(11) DEFAULT 30,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_num` (`invoice_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `flowork_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `status` varchar(30) DEFAULT 'Inactive',
  `license_key` varchar(100) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `amount` decimal(14,2) DEFAULT 0.00,
  `last_renewed` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sp_sub` (`selling_pointid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `flowork_system_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fuel_request_growers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fuel_requestid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `planned_day` date NOT NULL,
  `distance_km` decimal(8,2) DEFAULT 0.00,
  `litres` decimal(8,2) DEFAULT 0.00,
  `notes` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `fuel_requestid` (`fuel_requestid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `fuel_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `selling_pointid` int(11) DEFAULT 0,
  `week_start` date NOT NULL,
  `status` enum('draft','submitted','approved','rejected') DEFAULT 'draft',
  `notes` text DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`),
  KEY `week_start` (`week_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gl_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `acct_type` enum('asset','liability','equity','income','expense') NOT NULL,
  `normal_side` enum('debit','credit') NOT NULL,
  `category` varchar(30) DEFAULT '',
  `parent_code` varchar(20) DEFAULT '',
  `active` tinyint(4) DEFAULT 1,
  `sort` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `acct_type` (`acct_type`),
  KEY `parent_code` (`parent_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gl_journal_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `journal_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `account_code` varchar(20) DEFAULT '',
  `debit_usd` decimal(16,2) DEFAULT 0.00,
  `credit_usd` decimal(16,2) DEFAULT 0.00,
  `debit_zig` decimal(18,2) DEFAULT 0.00,
  `credit_zig` decimal(18,2) DEFAULT 0.00,
  `memo` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `journal_id` (`journal_id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gl_journals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `journal_no` varchar(40) NOT NULL,
  `jdate` date NOT NULL,
  `source` varchar(40) DEFAULT 'manual',
  `reference` varchar(120) DEFAULT '',
  `memo` varchar(255) DEFAULT '',
  `selling_pointid` int(11) DEFAULT 0,
  `seasonid` int(11) DEFAULT 0,
  `status` enum('draft','posted','void') DEFAULT 'draft',
  `created_by` int(11) DEFAULT 0,
  `posted_by` int(11) DEFAULT 0,
  `posted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `journal_no` (`journal_no`),
  KEY `jdate` (`jdate`),
  KEY `status` (`status`),
  KEY `selling_pointid` (`selling_pointid`),
  KEY `source` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gl_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `close_journal_no` varchar(40) DEFAULT '',
  `closed_by` int(11) DEFAULT 0,
  `closed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `status` (`status`),
  KEY `start_date` (`start_date`),
  KEY `end_date` (`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `gl_posting_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type` varchar(60) NOT NULL,
  `dr_code` varchar(20) DEFAULT '',
  `cr_code` varchar(20) DEFAULT '',
  `note` varchar(200) DEFAULT '',
  `active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grade_disputes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `sold_balesid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `original_grade` varchar(40) DEFAULT '',
  `disputed_grade` varchar(40) DEFAULT '',
  `original_price` decimal(10,4) DEFAULT 0.0000,
  `disputed_price` decimal(10,4) DEFAULT 0.0000,
  `reason` text NOT NULL,
  `status` enum('open','under_review','approved','rejected','resolved') DEFAULT 'open',
  `raised_by` int(11) NOT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `resolution_notes` text DEFAULT '',
  `final_grade` varchar(40) DEFAULT '',
  `final_price` decimal(10,4) DEFAULT 0.0000,
  `raised_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grower_banking` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `growerid` int(11) NOT NULL,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `currency` enum('usd','zig') NOT NULL DEFAULT 'usd',
  `bank_name` varchar(100) DEFAULT '',
  `branch` varchar(100) DEFAULT '',
  `account_num` varchar(60) DEFAULT '',
  `account_name` varchar(120) DEFAULT '',
  `swift_code` varchar(20) DEFAULT '',
  `phone` varchar(30) DEFAULT '',
  `verified` tinyint(4) DEFAULT 0,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `previous_account_num` varchar(60) DEFAULT '',
  `account_changed_at` timestamp NULL DEFAULT NULL,
  `mobile_number` varchar(30) NOT NULL DEFAULT '',
  `is_primary` tinyint(4) NOT NULL DEFAULT 0,
  `notes` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `growerid` (`growerid`),
  UNIQUE KEY `uk_grower_spid_curr` (`growerid`,`selling_pointid`,`currency`),
  KEY `growerid_2` (`growerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grower_charges` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `statutoryid` int(11) NOT NULL,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `seasonid` int(11) DEFAULT 0,
  `charge_typeid` int(11) NOT NULL DEFAULT 1,
  `amount` double(100,7) NOT NULL DEFAULT 0.0000000,
  `creditor_no` int(11) DEFAULT 0,
  `active` int(11) NOT NULL DEFAULT 1,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grower_creditors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `selling_pointid` int(11) DEFAULT 0,
  `name` varchar(120) DEFAULT '',
  `creditor_no` varchar(100) NOT NULL,
  `creditor_name` varchar(100) DEFAULT '',
  `creditor_type` varchar(100) DEFAULT '',
  `business_type` varchar(100) DEFAULT '',
  `cell_phone` varchar(100) DEFAULT '',
  `bank_name` varchar(80) DEFAULT '',
  `account_num` varchar(40) DEFAULT '',
  `email` varchar(100) DEFAULT '',
  `status` enum('active','suspended','closed') DEFAULT 'active',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `address1` varchar(200) DEFAULT '',
  `address2` varchar(200) DEFAULT '',
  `address3` varchar(200) DEFAULT '',
  `creditor_ref` varchar(100) DEFAULT '',
  `old_creditor_no` varchar(50) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cred_no` (`creditor_no`(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `grower_loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `loan_type` enum('advance','input_loan','emergency','other') DEFAULT 'advance',
  `amount` decimal(14,2) NOT NULL,
  `currency` enum('usd','zig') DEFAULT 'usd',
  `interest_rate` decimal(6,4) DEFAULT 0.0000,
  `issued_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `description` varchar(255) DEFAULT '',
  `status` enum('active','partially_paid','fully_paid','written_off') DEFAULT 'active',
  `total_repaid` decimal(14,2) DEFAULT 0.00,
  `issued_by` int(11) NOT NULL,
  `notes` text DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `growers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `grower_num` varchar(100) NOT NULL,
  `name` varchar(100) NOT NULL,
  `surname` varchar(100) NOT NULL,
  `national_id` varchar(100) DEFAULT '',
  `phone` varchar(100) DEFAULT '',
  `address1` varchar(100) DEFAULT '',
  `address2` varchar(100) DEFAULT '',
  `contractor` varchar(100) DEFAULT '',
  `farm_name` varchar(100) DEFAULT '',
  `province` varchar(100) DEFAULT '',
  `district` varchar(100) DEFAULT '',
  `area` varchar(100) DEFAULT '',
  `crop_type` varchar(30) DEFAULT 'Virginia',
  `contract_number` varchar(60) DEFAULT '',
  `dry_land_ha` varchar(100) DEFAULT '',
  `irr_land_ha` varchar(100) DEFAULT '',
  `dryland` varchar(100) DEFAULT '',
  `active` tinyint(4) DEFAULT 1,
  `notes` text DEFAULT '',
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_grower_floor` (`grower_num`,`selling_pointid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `internal_stop_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `selling_pointid` int(11) NOT NULL,
  `description` varchar(100) NOT NULL DEFAULT '',
  `creditor_number` int(11) NOT NULL DEFAULT 0,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `charge_typeid` int(11) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `internal_stop_order_amount` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `internal_stop_orderid` int(11) NOT NULL,
  `seasonid` int(11) NOT NULL DEFAULT 0,
  `charge_typeid` int(11) NOT NULL DEFAULT 1,
  `amount` double NOT NULL DEFAULT 0,
  `priority` int(11) NOT NULL DEFAULT 1,
  `active` int(11) NOT NULL DEFAULT 1,
  `default_deduction` int(11) NOT NULL DEFAULT 0,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `internal_stop_order_deducted_amounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `split_internal_stop_orderid` int(11) NOT NULL,
  `balanced_balesid` int(11) NOT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `internal_stop_order_amountid` int(11) DEFAULT NULL,
  `amount_deducted` double NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `internal_stop_order_to_balanced_bales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `internal_stop_order_amountid` int(11) NOT NULL,
  `balanced_balesid` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `internal_stoporders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `transporter_growersid` int(11) DEFAULT NULL,
  `charge_type` varchar(60) NOT NULL DEFAULT 'other',
  `description` varchar(200) NOT NULL DEFAULT '',
  `amount` double NOT NULL DEFAULT 0,
  `sell_date` date NOT NULL,
  `splitid` int(11) DEFAULT 1,
  `active` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `creditor_no` varchar(100) DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `job_queue` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `job_type` varchar(64) NOT NULL,
  `payload` longtext NOT NULL,
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_created` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `lat_long` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `growerid` int(11) NOT NULL,
  `latitude` decimal(10,7) DEFAULT 0.0000000,
  `longitude` decimal(10,7) DEFAULT 0.0000000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `growerid` (`growerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `loan_repayments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `repayment_date` date NOT NULL,
  `sell_date` date DEFAULT NULL,
  `method` enum('sale_deduction','cash','bank_transfer','other') DEFAULT 'sale_deduction',
  `reference` varchar(80) DEFAULT '',
  `recorded_by` int(11) NOT NULL,
  `notes` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `notification_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `type` enum('sms','email','whatsapp') DEFAULT 'sms',
  `recipient` varchar(255) DEFAULT '',
  `subject` varchar(255) DEFAULT '',
  `status` enum('sent','failed','simulated') DEFAULT 'sent',
  `response` text DEFAULT '',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `parameters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `param_key` varchar(80) NOT NULL,
  `param_value` text DEFAULT '',
  `selling_pointid` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_param` (`param_key`,`selling_pointid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pay_reconciliations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `batch_ref` varchar(80) DEFAULT '',
  `grower_num` varchar(40) DEFAULT '',
  `expected_amount` decimal(14,2) DEFAULT 0.00,
  `bank_amount` decimal(14,2) DEFAULT 0.00,
  `variance` decimal(14,2) DEFAULT 0.00,
  `status` enum('matched','unmatched','partial','manual') DEFAULT 'unmatched',
  `reconciled_by` int(11) DEFAULT NULL,
  `reconciled_at` timestamp NULL DEFAULT NULL,
  `pay_batchid` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp` (`selling_pointid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_batch` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sell_date` date NOT NULL,
  `currency` enum('usd','zig') DEFAULT 'usd',
  `payment_method` varchar(60) NOT NULL,
  `batch_ref` varchar(100) DEFAULT '',
  `status` enum('draft','reviewed','approved','released','locked') DEFAULT 'draft',
  `notes` varchar(500) DEFAULT '',
  `created_by` int(11) NOT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversed_by` int(11) DEFAULT NULL,
  `reversal_reason` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `sell_date` (`sell_date`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_batch_item` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_batchid` int(11) NOT NULL,
  `transporter_growersid` int(11) NOT NULL,
  `balanced_balesid` int(11) DEFAULT NULL,
  `net_amount` decimal(14,4) DEFAULT 0.0000,
  `amount_paid` decimal(14,4) DEFAULT 0.0000,
  `currency` enum('usd','zig') DEFAULT 'usd',
  PRIMARY KEY (`id`),
  KEY `payment_batchid` (`payment_batchid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_day_close` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sell_date` date NOT NULL,
  `selling_pointid` int(11) NOT NULL,
  `closed_by` int(11) NOT NULL,
  `closed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_paid_usd` decimal(14,4) DEFAULT 0.0000,
  `total_paid_zig` decimal(14,4) DEFAULT 0.0000,
  `grower_count` int(11) DEFAULT 0,
  `notes` varchar(255) DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `sell_date` (`sell_date`),
  KEY `sell_date_2` (`sell_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_failures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transporter_growersid` int(11) NOT NULL,
  `sell_date` date NOT NULL,
  `currency` enum('usd','zig') DEFAULT 'usd',
  `amount` decimal(14,4) DEFAULT 0.0000,
  `gateway` varchar(30) DEFAULT '',
  `phone` varchar(30) DEFAULT '',
  `error_message` varchar(255) DEFAULT '',
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `pf_unique` (`transporter_growersid`,`sell_date`,`currency`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entity_type` enum('payment_status','payment_batch') DEFAULT 'payment_status',
  `entity_id` int(11) NOT NULL,
  `userid` int(11) NOT NULL,
  `action` varchar(40) NOT NULL,
  `currency` enum('usd','zig') DEFAULT 'usd',
  `amount` decimal(14,4) DEFAULT 0.0000,
  `payment_method` varchar(60) DEFAULT '',
  `reference_number` varchar(100) DEFAULT '',
  `notes` varchar(500) DEFAULT '',
  `risk_flag` varchar(100) DEFAULT '',
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `entity_id` (`entity_id`),
  KEY `logged_at` (`logged_at`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_statusid` int(11) NOT NULL,
  `receipt_no` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_receipt_no` (`receipt_no`),
  KEY `idx_psid` (`payment_statusid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_recon_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `transporter_growersid` int(11) NOT NULL,
  `sell_date` date NOT NULL,
  `currency` enum('usd','zig') NOT NULL DEFAULT 'usd',
  `owed_at_review` decimal(14,2) NOT NULL DEFAULT 0.00,
  `paid_at_review` decimal(14,2) NOT NULL DEFAULT 0.00,
  `note` varchar(255) NOT NULL DEFAULT '',
  `reviewed_by` int(11) NOT NULL,
  `reviewed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_recon` (`transporter_growersid`,`sell_date`,`currency`),
  KEY `idx_sp` (`selling_pointid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `pay_role` enum('clerk','accountant','manager','admin') DEFAULT 'clerk',
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid` (`userid`),
  KEY `userid_2` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transporter_growersid` int(11) NOT NULL,
  `sell_date` date NOT NULL,
  `scheduled_date` date NOT NULL,
  `currency` enum('usd','zig') DEFAULT 'usd',
  `amount` decimal(14,4) DEFAULT 0.0000,
  `reason` varchar(255) DEFAULT '',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('scheduled','completed','cancelled') DEFAULT 'scheduled',
  PRIMARY KEY (`id`),
  KEY `scheduled_date` (`scheduled_date`),
  KEY `transporter_growersid` (`transporter_growersid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_sheets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_statusid` int(11) NOT NULL,
  `balanced_balesid` int(11) NOT NULL,
  `sale_batchid` int(11) DEFAULT 0,
  `splitid` int(11) DEFAULT 0,
  `linked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_ref` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pay_sheet` (`payment_statusid`,`balanced_balesid`),
  UNIQUE KEY `uk_sheet_ref` (`payment_ref`),
  KEY `idx_bb` (`balanced_balesid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transporter_growersid` int(11) NOT NULL,
  `sell_date` date NOT NULL,
  `currency` enum('usd','zig') DEFAULT 'usd',
  `gross_amount` decimal(14,4) DEFAULT 0.0000,
  `deductions` decimal(14,4) DEFAULT 0.0000,
  `net_amount` decimal(14,4) DEFAULT 0.0000,
  `amount_paid` decimal(14,4) DEFAULT 0.0000,
  `status` enum('pending','partial','reviewed','approved','released','paid','on_hold') DEFAULT 'pending',
  `payment_method` varchar(60) DEFAULT '',
  `reference_number` varchar(100) DEFAULT '',
  `paid_at` timestamp NULL DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `notes` varchar(500) DEFAULT '',
  `risk_flags` varchar(255) DEFAULT '',
  `payment_ref` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ps_unique` (`transporter_growersid`,`sell_date`,`currency`),
  UNIQUE KEY `uk_payment_ref` (`payment_ref`),
  KEY `sell_date` (`sell_date`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ph_bales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ph_barcode` varchar(100) NOT NULL,
  `source_barcode` varchar(100) DEFAULT '',
  `sold_balesid` int(11) DEFAULT NULL,
  `buyer_gradesid` int(11) DEFAULT NULL,
  `buyer_grade` varchar(100) DEFAULT '',
  `grower_num` varchar(100) DEFAULT '',
  `mass` decimal(10,2) DEFAULT 0.00,
  `house_id` int(11) NOT NULL,
  `pallet_id` int(11) DEFAULT NULL,
  `status` enum('reclassified','palletised','dispatched','received') DEFAULT 'reclassified',
  `reclassified_by` int(11) DEFAULT 0,
  `reclassified_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ph_barcode` (`ph_barcode`),
  KEY `house_id` (`house_id`),
  KEY `pallet_id` (`pallet_id`),
  KEY `status` (`status`),
  KEY `source_barcode` (`source_barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ph_dispatch` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dispatch_no` varchar(40) NOT NULL,
  `house_id` int(11) NOT NULL,
  `vehicle` varchar(120) DEFAULT '',
  `destination` varchar(120) DEFAULT '',
  `status` enum('loading','dispatched','received') DEFAULT 'loading',
  `pallet_count` int(11) DEFAULT 0,
  `bale_count` int(11) DEFAULT 0,
  `dispatched_by` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `dispatched_at` datetime DEFAULT NULL,
  `truck_id` int(11) DEFAULT 0,
  `line_id` int(11) DEFAULT 0,
  `received_at` datetime DEFAULT NULL,
  `received_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dispatch_no` (`dispatch_no`),
  KEY `house_id` (`house_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ph_dispatch_pallets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `dispatch_id` int(11) NOT NULL,
  `pallet_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_disp_pallet` (`dispatch_id`,`pallet_id`),
  KEY `dispatch_id` (`dispatch_id`),
  KEY `pallet_id` (`pallet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ph_houses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(120) DEFAULT '',
  `location` varchar(120) DEFAULT '',
  `active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ph_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `house_id` int(11) NOT NULL,
  `line_no` varchar(20) NOT NULL,
  `active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_house_line` (`house_id`,`line_no`),
  KEY `house_id` (`house_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ph_pallets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pallet_no` varchar(40) NOT NULL,
  `house_id` int(11) NOT NULL,
  `line_id` int(11) DEFAULT NULL,
  `buyer_gradesid` int(11) DEFAULT NULL,
  `buyer_grade` varchar(100) DEFAULT '',
  `status` enum('open','full','dispatched','received') DEFAULT 'open',
  `bale_count` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `pallet_no` (`pallet_no`),
  KEY `house_id` (`house_id`),
  KEY `line_id` (`line_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `proc_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_num` varchar(40) NOT NULL,
  `blend_code` varchar(40) DEFAULT '',
  `description` varchar(200) DEFAULT '',
  `status` enum('open','running','completed') DEFAULT 'open',
  `input_mass` decimal(12,2) DEFAULT 0.00,
  `bale_count` int(11) DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `seasonid` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_num` (`batch_num`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `proc_cartons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `carton_num` varchar(40) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `content_type` enum('lamina','stem') DEFAULT 'lamina',
  `blend_code` varchar(40) DEFAULT '',
  `net_mass` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gross_mass` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('packed','staged','shipped') DEFAULT 'packed',
  `destination` varchar(120) DEFAULT '',
  `packed_by` int(11) DEFAULT 0,
  `packed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `shipment_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `carton_num` (`carton_num`),
  KEY `batch_id` (`batch_id`),
  KEY `status` (`status`),
  KEY `shipment_id` (`shipment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `proc_intake` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barcode` varchar(100) NOT NULL,
  `grower_num` varchar(100) DEFAULT '',
  `timb_grade` varchar(50) DEFAULT '',
  `mass` decimal(10,2) NOT NULL DEFAULT 0.00,
  `source` varchar(120) DEFAULT '',
  `batch_id` int(11) DEFAULT NULL,
  `status` enum('received','allocated','processed') DEFAULT 'received',
  `seasonid` int(11) DEFAULT 0,
  `selling_pointid` int(11) DEFAULT 0,
  `received_by` int(11) DEFAULT 0,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proc_barcode` (`barcode`),
  KEY `batch_id` (`batch_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `proc_outputs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `output_type` enum('lamina','stem','scrap','fines') NOT NULL,
  `mass` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(200) DEFAULT '',
  `recorded_by` int(11) DEFAULT 0,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `proc_shipments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shipment_no` varchar(40) NOT NULL,
  `container_no` varchar(40) DEFAULT '',
  `vessel` varchar(120) DEFAULT '',
  `consignee` varchar(160) DEFAULT '',
  `destination` varchar(120) DEFAULT '',
  `booking_ref` varchar(80) DEFAULT '',
  `status` enum('loading','sealed','shipped') DEFAULT 'loading',
  `carton_count` int(11) DEFAULT 0,
  `net_mass` decimal(12,2) DEFAULT 0.00,
  `gross_mass` decimal(12,2) DEFAULT 0.00,
  `seasonid` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sealed_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_no` (`shipment_no`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rate_fetch_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fetch_date` date NOT NULL,
  `rate_fetched` decimal(14,4) DEFAULT NULL,
  `source` varchar(100) DEFAULT '',
  `raw_response` text DEFAULT '',
  `status` enum('success','failed','no_data') DEFAULT 'success',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
  `ip_address` varchar(45) NOT NULL,
  `action_key` varchar(100) NOT NULL,
  `request_count` int(11) DEFAULT 1,
  `window_start` int(11) NOT NULL,
  PRIMARY KEY (`ip_address`,`action_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbz_off_set` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `selling_pointid` int(11) NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rbz_retention_rate` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usd_percent` double(100,6) NOT NULL DEFAULT 0.000000,
  `other_currency_percent` double(100,6) NOT NULL DEFAULT 0.000000,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `re_offer_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `sold_balesid` int(11) NOT NULL,
  `original_price` decimal(10,4) DEFAULT 0.0000,
  `re_offer_price` decimal(10,4) DEFAULT 0.0000,
  `re_offer_date` date NOT NULL,
  `status` enum('offered','sold','withdrawn') DEFAULT 'offered',
  `new_buyer_gradesid` int(11) DEFAULT NULL,
  `processed_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `rights` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `created_byid` int(11) DEFAULT 0,
  `admin` tinyint(4) DEFAULT 0,
  `transporter_receiving` tinyint(4) DEFAULT 0,
  `scale` tinyint(4) DEFAULT 0,
  `junus` tinyint(4) DEFAULT 0,
  `buyer` tinyint(4) DEFAULT 0,
  `balance` tinyint(4) DEFAULT 0,
  `salessheets` tinyint(4) DEFAULT 0,
  `accounts` tinyint(4) DEFAULT 0,
  `transporter_payments` tinyint(4) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `userid` (`userid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sales_batching` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `selling_pointid` int(11) DEFAULT 0,
  `sell_date` date DEFAULT NULL,
  `batch_reference` varchar(60) DEFAULT '',
  `status` enum('open','printed','reprinted') DEFAULT 'open',
  `printed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sales_sheet_refs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transporter_growersid` int(11) NOT NULL,
  `splitid` int(11) NOT NULL DEFAULT 1,
  `ref_no` varchar(20) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tg_split` (`transporter_growersid`,`splitid`),
  KEY `ref_no` (`ref_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `scale_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `device_id` varchar(60) DEFAULT '',
  `operator` varchar(60) DEFAULT '',
  `temp_bacode` varchar(40) DEFAULT '',
  `mass` decimal(10,2) NOT NULL,
  `tare` decimal(10,2) DEFAULT 0.00,
  `gross` decimal(10,2) DEFAULT 0.00,
  `unit` varchar(10) DEFAULT 'kg',
  `status` enum('pending','processed','rejected') DEFAULT 'pending',
  `raw_data` text DEFAULT '',
  `synced_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `season_growers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `seasonid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `selling_pointid` int(11) NOT NULL,
  `contract_number` varchar(60) DEFAULT '',
  `crop_type` varchar(30) DEFAULT 'Virginia',
  `expected_kg` decimal(10,2) DEFAULT 0.00,
  `status` enum('active','withdrawn','completed') DEFAULT 'active',
  `rolled_from_seasonid` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sg` (`seasonid`,`growerid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `seasons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(120) NOT NULL,
  `active` tinyint(4) DEFAULT 0,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `name` varchar(60) NOT NULL DEFAULT '',
  `year_start` int(11) NOT NULL DEFAULT 0,
  `year_end` int(11) NOT NULL DEFAULT 0,
  `status` enum('upcoming','active','closed') DEFAULT 'upcoming',
  `open_date` date DEFAULT NULL,
  `close_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `selling_days` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `sell_date` date NOT NULL,
  `status` enum('open','closed','locked') DEFAULT 'open',
  `opened_by` int(11) DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `locked_by` int(11) DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `bales_sold` int(11) DEFAULT 0,
  `total_mass` decimal(12,2) DEFAULT 0.00,
  `total_usd` decimal(14,2) DEFAULT 0.00,
  `notes` text DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_day` (`selling_pointid`,`sell_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `selling_point` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `floor_code` varchar(20) DEFAULT '',
  `floor_id` varchar(20) DEFAULT '',
  `active` tinyint(4) DEFAULT 1,
  `company_name` varchar(120) DEFAULT '',
  `location` varchar(120) DEFAULT '',
  `address` text DEFAULT '',
  `phone` varchar(30) DEFAULT '',
  `email` varchar(100) DEFAULT '',
  `is_active` tinyint(4) DEFAULT 1,
  `offtaker_comm_pct` decimal(5,2) DEFAULT 1.50,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `selling_point_creditor_number` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `selling_pointid` int(11) NOT NULL,
  `creditor_no` varchar(100) NOT NULL,
  `description` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `selling_point_dispatch` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `buyerid` int(11) DEFAULT 0,
  `selling_pointid` int(11) DEFAULT 0,
  `destination` varchar(100) DEFAULT '',
  `dispatch_date` date DEFAULT NULL,
  `status` enum('open','closed') DEFAULT 'open',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `selling_point_image` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `image` varchar(100) NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `name` varchar(80) NOT NULL,
  `value` text DEFAULT '',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sms_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `growerid` int(11) DEFAULT 0,
  `grower_num` varchar(30) DEFAULT '',
  `phone` varchar(30) DEFAULT '',
  `sell_date` date NOT NULL,
  `message` text DEFAULT '',
  `status` enum('sent','failed','simulated') DEFAULT 'simulated',
  `response` text DEFAULT '',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `sold_bales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `ticketsid` int(11) NOT NULL,
  `sales_rep` varchar(100) DEFAULT '',
  `sale_code` varchar(100) DEFAULT '',
  `timb_gradesid` int(11) NOT NULL DEFAULT 1,
  `buyer_gradesid` int(11) NOT NULL DEFAULT 1,
  `price` varchar(100) NOT NULL DEFAULT '0',
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `s2_confirmed` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `split_internal_stop_order` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `internal_stop_order_amountid` int(11) NOT NULL,
  `transporter_growersid` int(11) NOT NULL,
  `splitsid` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `splits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `value` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `start_of_day` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` date NOT NULL,
  `selling_pointid` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `userid` int(11) DEFAULT 0,
  `seasonid` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 0,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `statutory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(120) NOT NULL,
  `currencyid` int(11) DEFAULT 1,
  `accessid` int(11) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `stop_order_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `system_key` varchar(100) NOT NULL,
  `vector` varchar(100) NOT NULL,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `stoporder_deductions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `balanced_balesid` int(11) NOT NULL,
  `stoporderid` int(11) NOT NULL,
  `amount_deducted` double NOT NULL DEFAULT 0,
  `creditor_no` varchar(100) DEFAULT '',
  `creditor_ref` varchar(100) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bb_so` (`balanced_balesid`,`stoporderid`),
  KEY `balanced_balesid` (`balanced_balesid`),
  KEY `stoporderid` (`stoporderid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `stoporders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `selling_pointid` int(11) DEFAULT 0,
  `seasonid` int(11) DEFAULT 0,
  `growerid` int(11) NOT NULL,
  `sale_id` varchar(100) DEFAULT '',
  `record_type` int(11) DEFAULT 0,
  `grower_type` varchar(100) DEFAULT '',
  `creditor_no` varchar(100) DEFAULT '',
  `priority` int(11) DEFAULT 0,
  `creditor_ref` varchar(100) DEFAULT '',
  `account_no` varchar(100) DEFAULT '',
  `amount_1` decimal(12,2) DEFAULT 0.00,
  `amount_2` decimal(12,2) DEFAULT 0.00,
  `amount_3` decimal(12,2) DEFAULT 0.00,
  `percent` decimal(8,4) DEFAULT 0.0000,
  `sos_date` varchar(100) DEFAULT '',
  `formatted_sos_date` date DEFAULT NULL,
  `type` varchar(100) DEFAULT '',
  `serial_no` varchar(100) DEFAULT '',
  `active` tinyint(4) DEFAULT 1,
  `processed` tinyint(4) DEFAULT 0,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `stoporders_timb` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `growerid` int(11) NOT NULL,
  `amount` decimal(14,4) DEFAULT 0.0000,
  `currency` varchar(10) DEFAULT 'USD',
  `reference` varchar(100) DEFAULT '',
  `sell_date` date DEFAULT NULL,
  `active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `growerid` (`growerid`),
  KEY `sell_date` (`sell_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `system_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `userid` int(11) NOT NULL DEFAULT 0,
  `action` varchar(30) NOT NULL,
  `table_name` varchar(60) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `note` varchar(255) DEFAULT '',
  `ip_address` varchar(45) DEFAULT '',
  `user_agent` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ticket_check_letter` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `characters` varchar(5) NOT NULL,
  `character_value` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ticket_splits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transporter_growersid` int(11) NOT NULL,
  `splitid` int(11) NOT NULL,
  `bale_qty` int(11) NOT NULL DEFAULT 0,
  `status` enum('open','confirmed') NOT NULL DEFAULT 'open',
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `lot_from` int(11) DEFAULT NULL,
  `lot_to` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ts_unique` (`transporter_growersid`,`splitid`),
  CONSTRAINT `ticket_splits_ibfk_1` FOREIGN KEY (`transporter_growersid`) REFERENCES `transporter_growers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `bale_junusid` int(11) NOT NULL,
  `barcode` varchar(100) NOT NULL,
  `start_of_dayid` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_aes_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key_type` varchar(20) NOT NULL,
  `aes_key` varchar(255) DEFAULT '',
  `aes_iv` varchar(255) DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_booking_ops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `operation` enum('query','insert','update','delete') NOT NULL,
  `grower_num` varchar(40) NOT NULL DEFAULT '',
  `booked_bales_id` int(11) DEFAULT 0,
  `sale_date` date DEFAULT NULL,
  `booking_date` date DEFAULT NULL,
  `bales_booked` int(11) DEFAULT 0,
  `reoffer` char(1) DEFAULT 'N',
  `floor_code` varchar(20) DEFAULT '',
  `booking_id` varchar(60) DEFAULT '',
  `result` varchar(20) DEFAULT '',
  `message` varchar(255) DEFAULT '',
  `ok` tinyint(4) DEFAULT 0,
  `simulated` tinyint(4) DEFAULT 0,
  `log_id` int(11) DEFAULT 0,
  `userid` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp` (`selling_pointid`,`created_at`),
  KEY `idx_grower` (`grower_num`,`sale_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_credentials` (
  `cred_set` varchar(20) NOT NULL,
  `username` varchar(120) DEFAULT '',
  `password_enc` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`cred_set`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_grade_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `grade` varchar(40) NOT NULL,
  `batch_ref` varchar(40) DEFAULT '',
  `batch_size` int(11) DEFAULT 1,
  `confirmation` varchar(60) DEFAULT '',
  `accepted` tinyint(4) DEFAULT 0,
  `simulated` tinyint(4) DEFAULT 0,
  `log_id` int(11) DEFAULT 0,
  `userid` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp` (`selling_pointid`,`created_at`),
  KEY `idx_grade` (`grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `description` varchar(100) NOT NULL,
  `active` int(11) NOT NULL DEFAULT 1,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_grower_registry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `grower_num` varchar(40) NOT NULL,
  `name` varchar(80) DEFAULT '',
  `surname` varchar(80) DEFAULT '',
  `national_id` varchar(40) DEFAULT '',
  `province` varchar(60) DEFAULT '',
  `district_name` varchar(60) DEFAULT '',
  `area` varchar(60) DEFAULT '',
  `farm_name` varchar(80) DEFAULT '',
  `crop_type` varchar(30) DEFAULT 'Virginia',
  `registration_date` date DEFAULT NULL,
  `status` enum('active','suspended','cancelled') DEFAULT 'active',
  `timb_verified` tinyint(4) DEFAULT 0,
  `last_synced` timestamp NOT NULL DEFAULT current_timestamp(),
  `initials` varchar(20) DEFAULT '',
  `postal_address1` varchar(120) DEFAULT '',
  `postal_address2` varchar(120) DEFAULT '',
  `grower_type` varchar(10) DEFAULT '',
  `district` varchar(20) DEFAULT '',
  `grower_class` varchar(10) DEFAULT '',
  `current_reg` varchar(10) DEFAULT '',
  `next_reg` varchar(10) DEFAULT '',
  `email` varchar(120) DEFAULT '',
  `association` varchar(60) DEFAULT '',
  `barn_type` varchar(20) DEFAULT '',
  `amend_type` varchar(5) DEFAULT '',
  `contractor` varchar(60) DEFAULT '',
  `hectares` decimal(10,2) DEFAULT 0.00,
  `landholding` varchar(20) DEFAULT '',
  `obligations_fulfilled` varchar(5) DEFAULT '',
  `source` varchar(20) DEFAULT 'csv',
  `raw_payload` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tgr` (`selling_pointid`,`grower_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_price_matrix` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `matrix_type` enum('daily','seasonal') NOT NULL DEFAULT 'daily',
  `matrix_date` date DEFAULT NULL,
  `grade` varchar(40) NOT NULL,
  `price_raw` varchar(20) DEFAULT '',
  `price_usd` decimal(10,4) DEFAULT 0.0000,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `simulated` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_lookup` (`selling_pointid`,`matrix_type`,`matrix_date`),
  KEY `idx_grade` (`grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_service_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `service` varchar(40) NOT NULL,
  `url` varchar(255) DEFAULT '',
  `request_params` mediumtext DEFAULT NULL,
  `http_code` int(11) DEFAULT 0,
  `duration_ms` int(11) DEFAULT 0,
  `attempts` tinyint(4) DEFAULT 1,
  `ok` tinyint(4) DEFAULT 0,
  `simulated` tinyint(4) DEFAULT 0,
  `rows_returned` int(11) DEFAULT 0,
  `error_message` varchar(255) DEFAULT '',
  `response_body` mediumtext DEFAULT NULL,
  `userid` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp_svc` (`selling_pointid`,`service`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_submission_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `sell_date` date NOT NULL,
  `submission_type` varchar(40) DEFAULT 'daily_sales',
  `bales_count` int(11) DEFAULT 0,
  `payload_size_kb` int(11) DEFAULT 0,
  `status` enum('success','failed','pending') DEFAULT 'pending',
  `response_code` varchar(20) DEFAULT '',
  `api_response` text DEFAULT '',
  `reference_number` varchar(80) DEFAULT '',
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp_date` (`selling_pointid`,`sell_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `sell_date` date NOT NULL,
  `submission_type` enum('daily_sales','origination','grower_schedule','bank_details') DEFAULT 'daily_sales',
  `bales_count` int(11) DEFAULT 0,
  `payload_size_kb` int(11) DEFAULT 0,
  `status` enum('pending','submitted','accepted','rejected','error') DEFAULT 'pending',
  `response_code` varchar(20) DEFAULT '',
  `response_message` text DEFAULT '',
  `submitted_by` int(11) NOT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `reference_number` varchar(80) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `timb_sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `sync_type` varchar(40) DEFAULT '',
  `records_fetched` int(11) DEFAULT 0,
  `records_matched` int(11) DEFAULT 0,
  `records_new` int(11) DEFAULT 0,
  `records_updated` int(11) DEFAULT 0,
  `status` enum('success','failed','partial') DEFAULT 'success',
  `notes` text DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `transporter` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `seasonid` int(11) DEFAULT 0,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL,
  `id_num` varchar(100) DEFAULT '',
  `truck_num` varchar(100) DEFAULT '',
  `location` varchar(100) DEFAULT '',
  `location_id` int(11) DEFAULT NULL,
  `active` int(11) NOT NULL DEFAULT 1,
  `open_close` int(11) NOT NULL DEFAULT 1,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tr_sp` (`selling_pointid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `transporter_growers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `transporterid` int(11) NOT NULL,
  `growerid` int(11) NOT NULL,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `locationid` int(11) DEFAULT NULL,
  `splitid` int(11) DEFAULT 1,
  `bales` int(11) NOT NULL DEFAULT 0,
  `junused_bales` int(11) NOT NULL DEFAULT 0,
  `open_close` int(11) NOT NULL DEFAULT 0,
  `booked` tinyint(4) DEFAULT 0,
  `created_at` varchar(100) DEFAULT '',
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  `sell_date` date DEFAULT NULL,
  `status` enum('active','suspended','blocked') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `user_id` int(11) NOT NULL,
  `device` varchar(120) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `last_used` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT 0,
  `username` varchar(60) DEFAULT '',
  `action` varchar(60) NOT NULL,
  `entity` varchar(60) DEFAULT '',
  `entity_ref` varchar(80) DEFAULT '',
  `detail` varchar(400) DEFAULT '',
  `ip` varchar(45) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_at` (`created_at`),
  KEY `action` (`action`),
  KEY `entity` (`entity`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_bale_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_barcode` varchar(100) NOT NULL,
  `tpz_barcode` varchar(100) DEFAULT '',
  `grower_num` varchar(100) DEFAULT '',
  `buyer_grade` varchar(100) DEFAULT '',
  `mass` decimal(10,2) DEFAULT 0.00,
  `price` decimal(10,2) DEFAULT 0.00,
  `lot` varchar(60) DEFAULT '',
  `status` enum('captured','reclassified') DEFAULT 'captured',
  `captured_by` int(11) DEFAULT 0,
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ticket_barcode` (`ticket_barcode`),
  KEY `status` (`status`),
  KEY `tpz_barcode` (`tpz_barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(40) NOT NULL,
  `line_no` varchar(20) DEFAULT '',
  `description` varchar(200) DEFAULT '',
  `status` enum('open','running','completed') DEFAULT 'open',
  `input_mass` decimal(12,2) DEFAULT 0.00,
  `bale_count` int(11) DEFAULT 0,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `seasonid` int(11) DEFAULT 0,
  `created_by` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_no` (`batch_no`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_blend_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blend_batch_no` varchar(40) NOT NULL,
  `blend_id` int(11) NOT NULL,
  `target_mass` decimal(12,2) DEFAULT 0.00,
  `status` enum('open','mixed') DEFAULT 'open',
  `mixed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `blend_batch_no` (`blend_batch_no`),
  KEY `blend_id` (`blend_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_blend_components` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blend_id` int(11) NOT NULL,
  `grade` varchar(100) DEFAULT '',
  `pct` decimal(5,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `blend_id` (`blend_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_blends` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `blend_code` varchar(40) NOT NULL,
  `name` varchar(120) DEFAULT '',
  `market` varchar(80) DEFAULT '',
  `description` varchar(255) DEFAULT '',
  `active` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `blend_code` (`blend_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_cartons` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `carton_no` varchar(40) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `blend_code` varchar(40) DEFAULT '',
  `export_lot` varchar(40) DEFAULT '',
  `content_type` enum('lamina','stem') DEFAULT 'lamina',
  `customer_code` varchar(40) DEFAULT '',
  `origin` varchar(80) DEFAULT 'Zimbabwe',
  `net_mass` decimal(10,2) DEFAULT 0.00,
  `gross_mass` decimal(10,2) DEFAULT 0.00,
  `status` enum('packed','stored','allocated','shipped') DEFAULT 'packed',
  `location_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `container_id` int(11) DEFAULT NULL,
  `packed_by` int(11) DEFAULT 0,
  `packed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `carton_no` (`carton_no`),
  KEY `status` (`status`),
  KEY `batch_id` (`batch_id`),
  KEY `order_id` (`order_id`),
  KEY `container_id` (`container_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_conditioning` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(40) NOT NULL,
  `target_moisture` decimal(5,2) DEFAULT 0.00,
  `status` enum('open','running','completed') DEFAULT 'open',
  `bale_count` int(11) DEFAULT 0,
  `input_mass` decimal(12,2) DEFAULT 0.00,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `batch_no` (`batch_no`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_containers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `shipment_no` varchar(40) NOT NULL,
  `container_no` varchar(40) DEFAULT '',
  `seal_no` varchar(40) DEFAULT '',
  `vessel` varchar(120) DEFAULT '',
  `consignee` varchar(160) DEFAULT '',
  `destination` varchar(120) DEFAULT '',
  `booking_ref` varchar(80) DEFAULT '',
  `order_id` int(11) DEFAULT NULL,
  `carton_count` int(11) DEFAULT 0,
  `net_mass` decimal(12,2) DEFAULT 0.00,
  `gross_mass` decimal(12,2) DEFAULT 0.00,
  `status` enum('loading','sealed','ready','customs','shipped') DEFAULT 'loading',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sealed_at` datetime DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_no` (`shipment_no`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_customs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `container_id` int(11) NOT NULL,
  `entry_no` varchar(60) DEFAULT '',
  `declaration_no` varchar(60) DEFAULT '',
  `duty` decimal(12,2) DEFAULT 0.00,
  `status` enum('pending','cleared') DEFAULT 'pending',
  `cleared_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `container_id` (`container_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) DEFAULT NULL,
  `container_id` int(11) DEFAULT NULL,
  `doc_type` varchar(60) NOT NULL,
  `doc_ref` varchar(80) DEFAULT '',
  `issued_date` date DEFAULT NULL,
  `status` enum('pending','issued') DEFAULT 'pending',
  `notes` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `container_id` (`container_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_lab_samples` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sample_no` varchar(40) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `blend_batch_id` int(11) DEFAULT NULL,
  `moisture` decimal(6,2) DEFAULT NULL,
  `nicotine` decimal(6,2) DEFAULT NULL,
  `sugar` decimal(6,2) DEFAULT NULL,
  `chloride` decimal(6,2) DEFAULT NULL,
  `foreign_matter` decimal(6,2) DEFAULT NULL,
  `pesticide` varchar(120) DEFAULT '',
  `result` enum('pending','approved','hold','rejected') DEFAULT 'pending',
  `remarks` varchar(255) DEFAULT '',
  `tested_by` int(11) DEFAULT 0,
  `tested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `sample_no` (`sample_no`),
  KEY `result` (`result`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_locations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `zone` varchar(40) DEFAULT '',
  `capacity` int(11) DEFAULT 0,
  `active` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_moisture_tests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conditioning_id` int(11) NOT NULL,
  `moisture` decimal(5,2) DEFAULT 0.00,
  `temperature` decimal(5,2) DEFAULT NULL,
  `tested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tested_by` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `conditioning_id` (`conditioning_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_no` varchar(40) NOT NULL,
  `customer` varchar(160) DEFAULT '',
  `customer_code` varchar(40) DEFAULT '',
  `destination` varchar(120) DEFAULT '',
  `blend_code` varchar(40) DEFAULT '',
  `contract_ref` varchar(80) DEFAULT '',
  `target_mass` decimal(12,2) DEFAULT 0.00,
  `status` enum('open','allocated','shipped','closed') DEFAULT 'open',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_outputs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` int(11) NOT NULL,
  `output_type` enum('lamina','stem','scrap','fines') NOT NULL,
  `mass` decimal(12,2) NOT NULL DEFAULT 0.00,
  `notes` varchar(200) DEFAULT '',
  `recorded_by` int(11) DEFAULT 0,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `batch_id` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_qc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_id` int(11) NOT NULL,
  `ph_barcode` varchar(100) DEFAULT '',
  `export_grade` varchar(100) DEFAULT '',
  `colour` varchar(40) DEFAULT '',
  `leaf_position` varchar(40) DEFAULT '',
  `texture` varchar(40) DEFAULT '',
  `body` varchar(40) DEFAULT '',
  `ripeness` varchar(40) DEFAULT '',
  `cleanliness` varchar(40) DEFAULT '',
  `result` enum('passed','rework','rejected') DEFAULT 'passed',
  `remarks` varchar(255) DEFAULT '',
  `inspected_by` int(11) DEFAULT 0,
  `inspected_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `receipt_id` (`receipt_id`),
  KEY `result` (`result`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ph_barcode` varchar(100) NOT NULL,
  `source_barcode` varchar(100) DEFAULT '',
  `grower_num` varchar(100) DEFAULT '',
  `buyer_grade` varchar(100) DEFAULT '',
  `mass` decimal(10,2) NOT NULL DEFAULT 0.00,
  `house_code` varchar(20) DEFAULT '',
  `pallet_no` varchar(40) DEFAULT '',
  `dispatch_no` varchar(40) DEFAULT '',
  `ph_dispatch_id` int(11) DEFAULT 0,
  `moisture` decimal(5,2) DEFAULT NULL,
  `integrity_ok` tinyint(4) DEFAULT 1,
  `status` enum('received','quarantine','accepted','rejected') DEFAULT 'received',
  `notes` varchar(255) DEFAULT '',
  `batch_id` int(11) DEFAULT NULL,
  `seasonid` int(11) DEFAULT 0,
  `received_by` int(11) DEFAULT 0,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `accepted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tx_barcode` (`ph_barcode`),
  KEY `status` (`status`),
  KEY `dispatch_no` (`dispatch_no`),
  KEY `batch_id` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `skey` varchar(60) NOT NULL,
  `svalue` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `skey` (`skey`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_stock_moves` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `carton_id` int(11) NOT NULL,
  `from_loc` varchar(40) DEFAULT '',
  `to_loc` varchar(40) DEFAULT '',
  `moved_by` int(11) DEFAULT 0,
  `moved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `carton_id` (`carton_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_trucks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reg_no` varchar(40) NOT NULL,
  `carrier` varchar(120) DEFAULT '',
  `driver` varchar(120) DEFAULT '',
  `phone` varchar(40) DEFAULT '',
  `status` enum('available','in_transit','at_plant','empty') DEFAULT 'available',
  `location` varchar(120) DEFAULT '',
  `current_dispatch_id` int(11) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reg_no` (`reg_no`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tx_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(60) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(120) DEFAULT '',
  `role` enum('admin','operator') DEFAULT 'operator',
  `active` tinyint(4) DEFAULT 1,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_2fa` (
  `userid` int(11) NOT NULL,
  `secret` varchar(64) NOT NULL,
  `enabled` tinyint(4) DEFAULT 0,
  `backup_codes` text DEFAULT '',
  `enabled_at` timestamp NULL DEFAULT NULL,
  `last_used` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `userid` int(11) NOT NULL,
  `can_weigh` tinyint(4) DEFAULT 1,
  `can_sell` tinyint(4) DEFAULT 1,
  `can_balance` tinyint(4) DEFAULT 1,
  `can_view_reports` tinyint(4) DEFAULT 1,
  `can_edit_prices` tinyint(4) DEFAULT 0,
  `can_void` tinyint(4) DEFAULT 0,
  `can_manage_growers` tinyint(4) DEFAULT 1,
  `can_manage_payments` tinyint(4) DEFAULT 0,
  `can_export_data` tinyint(4) DEFAULT 1,
  `can_manage_users` tinyint(4) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `user_selling_points` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) NOT NULL,
  `selling_pointid` int(11) NOT NULL,
  `role` enum('operator','manager','admin') DEFAULT 'operator',
  PRIMARY KEY (`id`),
  UNIQUE KEY `usp_u` (`userid`,`selling_pointid`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(80) NOT NULL,
  `password` varchar(255) NOT NULL,
  `active` tinyint(4) DEFAULT 1,
  `confirm` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_admin` tinyint(4) DEFAULT 0,
  `role` varchar(30) DEFAULT 'clerk',
  `full_name` varchar(120) DEFAULT '',
  `email` varchar(100) DEFAULT '',
  `phone` varchar(30) DEFAULT '',
  `selling_pointid` int(11) DEFAULT 0,
  `rights` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rights`)),
  `is_master` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `vehicle_trips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicleid` int(11) NOT NULL,
  `dispatch_sessionid` int(11) DEFAULT NULL,
  `origin` varchar(120) DEFAULT '',
  `destination` varchar(120) DEFAULT '',
  `departed_at` timestamp NULL DEFAULT NULL,
  `arrived_at` timestamp NULL DEFAULT NULL,
  `bales_count` int(11) DEFAULT 0,
  `mass_kg` decimal(10,2) DEFAULT 0.00,
  `driver_name` varchar(80) DEFAULT '',
  `notes` text DEFAULT '',
  `status` enum('loading','departed','arrived','cancelled') DEFAULT 'loading',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL DEFAULT 0,
  `reg_number` varchar(30) NOT NULL,
  `make` varchar(50) DEFAULT '',
  `model` varchar(50) DEFAULT '',
  `type` enum('truck','flatbed','pickup','van','other') DEFAULT 'truck',
  `capacity_bales` int(11) DEFAULT 0,
  `capacity_kg` decimal(10,2) DEFAULT 0.00,
  `driver_name` varchar(80) DEFAULT '',
  `driver_phone` varchar(20) DEFAULT '',
  `status` enum('available','on_route','maintenance','retired') DEFAULT 'available',
  `notes` text DEFAULT '',
  `last_used` date DEFAULT NULL,
  `last_gps_lat` decimal(10,6) DEFAULT NULL,
  `last_gps_lng` decimal(10,6) DEFAULT NULL,
  `last_gps_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `void_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `selling_pointid` int(11) NOT NULL,
  `void_type` enum('ticket','sold_bale','balance','dispatch_item','payment') NOT NULL,
  `record_id` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `authorised_by` int(11) NOT NULL,
  `reversed_by` int(11) NOT NULL,
  `original_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`original_data`)),
  `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actioned_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `year_to_date_totals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userid` int(11) DEFAULT 0,
  `growerid` int(11) NOT NULL,
  `seasonid` int(11) DEFAULT 0,
  `mass` decimal(12,2) NOT NULL DEFAULT 0.00,
  `usd_value` double(100,4) NOT NULL DEFAULT 0.0000,
  `zim_value` double(100,4) NOT NULL DEFAULT 0.0000,
  `laid_bales` int(11) NOT NULL DEFAULT 0,
  `sold_bales` int(11) NOT NULL DEFAULT 0,
  `rejected_bales` int(11) NOT NULL DEFAULT 0,
  `average_price` double(100,4) NOT NULL DEFAULT 0.0000,
  `datetime` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_writeoffs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_statusid` int(11) NOT NULL,
  `amount` decimal(14,4) NOT NULL COMMENT 'paid minus stored net; positive = overpaid',
  `currency` enum('usd','zig') NOT NULL DEFAULT 'usd',
  `reason` varchar(255) NOT NULL,
  `approved_by` varchar(120) NOT NULL,
  `created_by` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_writeoff_payment` (`payment_statusid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
