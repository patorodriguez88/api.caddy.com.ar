CREATE TABLE IF NOT EXISTS `MeliWebhookQueue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `raw_payload` text NOT NULL,
  `shipments_id` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `substatus` varchar(50) DEFAULT NULL,
  `procesado` tinyint(1) NOT NULL DEFAULT 0,
  `intentos` int(11) NOT NULL DEFAULT 0,
  `resultado` text DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_procesado` (`procesado`, `intentos`),
  KEY `idx_shipments_id` (`shipments_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
