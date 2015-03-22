<?php
/**
 * This class loads news from a Facebook feed into a database.
 *
 * @copyright (c) 2014-2015 Daniel Gerhardt (code@dgerhardt.net)
 * @license MIT
 */
class Newsfeed {
	const UPDATE_DELAY = 1800;
	const CURL_TIMEOUT = 3;
	const CURL_CONNECT_TIMEOUT = 2;

	const NEWSFEED_TYPE_ATOM = 1;
	const NEWSFEED_TYPE_RSS = 2;

	private $db;
	private $url;
	private $type;
	private $urlFilterPattern;
	private $baseUrl;

	/**
	 * Creates a new Newsfeed instance.
	 *
	 * The ID of a Facebook page can be determined via Facebook's graph API.
	 * E.g. http://graph.facebook.com/ShadowpainterBand/
	 *
	 * @param \PDO $db a PDO instance
	 * @param integer $fbId ID of the Facebook page
	 * @param string $fbUsername Username of the Facebook page
	 */
	public function __construct($db, $fbId, $fbUsername) {
		$this->db = $db;
		$this->url = 'https://www.facebook.com/feeds/page.php?format=atom10&id=' . $fbId;
		$this->type = self::NEWSFEED_TYPE_ATOM;
		$this->urlFilterPattern = '.*\\/' . $fbUsername . '\\/posts\\/[0-9]+';
		$this->baseUrl = 'https://www.facebook.com';
	}

	/**
	 * Loads news from Facebook into the database.
	 *
	 * @return integer the number of newly fetched news entries
	 */
	public function loadNews() {
		$res = $this->db->query("SELECT `value`
			FROM `config`
			WHERE `key` = 'newsfeedLastUpdate'");
		$lastUpdate = $res->fetchColumn();

		if (time() < $lastUpdate + self::UPDATE_DELAY) {
			return false;
		}

		$xml = self::loadFromUrls($this->url);
		$xml = @simplexml_load_string($xml[0]);
		if (!$xml)
			// TODO: error handling
			return false;

		$guids = array();
		$sql = 'SELECT `feed_guid`
			FROM `news`
			WHERE `feed_guid` IS NOT NULL';
		foreach ($this->db->query($sql) as $row) {
			$guids[] = $row['feed_guid'];
		}

		$updates = 0;
		switch ($this->type) {
			case self::NEWSFEED_TYPE_RSS:
				$atomFeed = $xml->channel;
				if (!$atomFeed)
					throw new Exception("Invalid RSS.");
				foreach ($atomFeed->item as $item) {
					$guid = $item->guid;
					if (in_array($guid, $guids))
						continue;
					$title = mb_ereg_replace(chr(194) . chr(150), chr(226) . chr(128) . chr(147), $item->title);
					$time = strtotime($item->pubDate);
					$content = $this->parseContent($item->description);
					$url = $item->link;
					$stmt = $this->db->prepare('INSERT INTO `news`
						(`time`, `title`, `content`, `url`, `feed_guid`)
						VALUES (:time, :title, :content, :url, :guid)');
					$stmt->execute(array(
						':time' => $time,
						':title' => '',
						':content' => $content,
						':url' => $url,
						':guid' => $guid
					));
					$updates++;
				}

				break;
			case self::NEWSFEED_TYPE_ATOM:
				if (!$xml->title && $xml->updated)
					throw new Exception("Invalid Atom.");
				foreach ($xml->entry as $item) {
					$guid = $item->id;
					if (in_array($guid, $guids))
						continue;
					$title = mb_ereg_replace(chr(194) . chr(150), chr(226) . chr(128) . chr(147), $item->title);
					$time = strtotime($item->published);
					$content = $this->parseContent($item->content);
					$url = $item->link['href'];
					$stmt = $this->db->prepare('INSERT INTO `news`
						(`time`, `title`, `content`, `url`, `feed_guid`)
						VALUES (:time, :title, :content, :url, :guid)');
					$stmt->execute(array(
						':time' => $time,
						':title' => '',
						':content' => $content,
						':url' => $url,
						':guid' => $guid
					));
					$updates++;
				}

				break;
		}
		$stmt = $this->db->prepare("UPDATE `config`
			SET `value` = :time
			WHERE `key` = 'newsfeedLastUpdate'");
		$stmt->execute(array(
			':time' => time()
		));

		return $updates;
	}

	/**
	 * Removes unsecure HTML content and replaces redirection links.
	 *
	 * @param string $content the unsecure HTML content
	 * @return string the sanitized content
	 */
	private function parseContent($content) {
		$result = $content;
		$result = preg_replace('#&lt;iframe .*?&lt;/iframe&gt;#', '', $result);
		$result = preg_replace('#</?(embed|iframe|object|script|style)( .*?)?>#', '', $result);
		$that = $this; // PHP < 5.4 compatibility
		$result = preg_replace_callback('#<([a-z]) .*?(href|src)="(.*?)".*?( ?/?)>#', function ($matches) use ($that) {
			return "<$matches[1] $matches[2]=\"" . $that->replaceUrl($matches[3]) . "\"$matches[4]>";
		}, $result);

		return $result;
	}

	/**
	 * Replaces a Facebook redirection URL with the original target URL.
	 *
	 * @param string $url the URL from Facebook
	 * @return string the original target URL
	 */
	private function replaceUrl($url) {
		$baseUrl = $this->baseUrl;
		$result = $url;
		$result = preg_replace_callback('#^https?://(www|l).facebook.com/l.php\\?u=([^&]*).*#', function ($matches) {
			return urldecode($matches[2]);
		}, $result);
		$result = preg_replace_callback('#^/[^/].*#', function ($matches) use ($baseUrl) {
			return $baseUrl . $matches[0];
		}, $result);
		$result = preg_replace('#^https?://(www\.)?youtube\.com/embed/#', 'https://www.youtube.com/watch?v=', $result);

		return $result;
	}

	/**
	 * Loads external contents via HTTP.
	 * 
	 * @param string|string[] $urls the URL(s) from which the content is loaded
	 * @return string[] an array containing the fetched contents
	 */
	private static function loadFromUrls($urls) {
		if (!is_array($urls)) {
			$urls = array($urls);
		}

		$mh = curl_multi_init();
		$ch = array();
		foreach ($urls as $k => $url) {
			$ch[$k] = curl_init();
			curl_setopt($ch[$k], CURLOPT_URL, $url);
			curl_setopt($ch[$k], CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch[$k], CURLOPT_CONNECTTIMEOUT, self::CURL_CONNECT_TIMEOUT);
			curl_setopt($ch[$k], CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
			curl_setopt($ch[$k], CURLOPT_USERAGENT, "Mozilla/5.0 (rv:34.0) Gecko/20100101 Firefox/34.0");
			curl_setopt($ch[$k], CURLOPT_HTTPHEADER, array("Accept-Language: de-de,de;"));
			curl_multi_add_handle($mh, $ch[$k]);
		}

		$running = 0;
		do {
			curl_multi_exec($mh, $running);
		} while ($running > 0);

		$content = array();
		foreach ($ch as $k => $v) {
			$content[$k] = curl_multi_getcontent($v);
			curl_multi_remove_handle($mh, $v);
		}

		curl_multi_close($mh);

		return $content;
	}
}
