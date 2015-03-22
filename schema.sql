CREATE TABLE IF NOT EXISTS `config` (
	`key` varchar(32) NOT NULL,
	`value` varchar(255) NOT NULL,
	PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `news` (
	`id` int(11) NOT NULL AUTO_INCREMENT,
	`time` int(10) NOT NULL DEFAULT '0',
	`title` varchar(255) NOT NULL DEFAULT '',
	`content` text NOT NULL,
	`url` varchar(255) DEFAULT NULL,
	`feed_guid` varchar(64) DEFAULT NULL,
	PRIMARY KEY (`id`),
	KEY `time` (`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO `config` (`key`, `value`) VALUES ('newsfeedLastUpdate', '0');
