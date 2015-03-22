<?php
/* configuration */
$sqlServer = '';
$sqlDb = '';
$sqlUser = '';
$sqlPassword = '';
$fbId = 215635565175170;
$fbUser = 'ShadowpainterBand';


/* set up database */
$db = new PDO("mysql:host=$sqlServer;dbname=$sqlDb", $sqlUser, $sqlPassword, array(
	PDO::MYSQL_ATTR_INIT_COMMAND => 'SET CHARACTER SET utf8'
));
if (!$db) {
	die("Database connection could not be established.");
}


/* load news */
$newsfeed = new Newsfeed($db, $fbId, $fbUser);
$newsfeed->loadNews();

$newsResource = $db->query("SELECT `time`, `title`, `content`, `url` FROM `news` ORDER BY `time` DESC LIMIT 0,10");

foreach ($newsResource as $newsEntry) {
	echo '<p class="news-title">' . $newsEntry['title'] . '</p>';
	echo '<p class="news-body">' . $newsEntry['content'] . '</p>';
	echo '<p class="news-time">' . $newsEntry['time'] . '</p>';
	if ($newsEntry['url']) {
		echo '<div class="news-footer shariff" data-url="' . $newsEntry['url'] . '" data-theme="grey"></div>';
	}
}
