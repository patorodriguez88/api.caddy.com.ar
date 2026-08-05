CREATE TABLE IF NOT EXISTS `flex_handshakes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `_id` varchar(50) NOT NULL,
  `topic` text NOT NULL,
  `resource` text NOT NULL,
  `user_id` int(50) NOT NULL,
  `application_id` varchar(50) NOT NULL,
  `sent` datetime NOT NULL,
  `attempts` int(5) NOT NULL,
  `received` datetime NOT NULL,
  `TIME_STAMP` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `shipping_id` bigint(20) DEFAULT NULL,
  `logistic_type` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_flex_received` (`received`),
  KEY `idx_flex_shipping_id` (`shipping_id`),
  KEY `idx_flex_logistic_type` (`logistic_type`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
