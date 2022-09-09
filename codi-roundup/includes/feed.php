<?php

//get rss feed
function codi_roundup_get_feed($url) {
	//set vars
	$items = [];
	//load rss feed
	$rss = new DOMDocument();
	$rss->load($url);
	//loop through items
	foreach($rss->getElementsByTagName('entry') as $node) {
		//set vars
		$link = '';
		$title = '';
		$snippet = '';
		$time = '';
		//has link?
		$tmp = $node->getElementsByTagName('link');
		if($tmp->count() > 0) {
			$link = $tmp->item(0)->getAttribute('href') ?: $tmp->item(0)->nodeValue;
			$link = explode('url=', $link);
			$link = isset($link[1]) ? explode('&ct=', $link[1])[0] : $link[0];
		}
		//has title?
		$tmp = $node->getElementsByTagName('title');
		if($tmp->count() > 0) {
			$title = $tmp->item(0)->nodeValue;
		}
		//has snippet?
		$tmp1 = $node->getElementsByTagName('description');
		$tmp2 = $node->getElementsByTagName('content');
		if($tmp1->count() > 0) {
			$snippet = $tmp1->item(0)->nodeValue;
		} else if($tmp2->count() > 0) {
			$snippet = $tmp2->item(0)->nodeValue;
		} 
		//has time?
		$tmp1 = $node->getElementsByTagName('pubDate');
		$tmp2 = $node->getElementsByTagName('published');
		if($tmp1->count() > 0) {
			$time = $tmp1->item(0)->nodeValue;
		} else if($tmp2->count() > 0) {
			$time = $tmp2->item(0)->nodeValue;
		} else {
			$time = time();
		}
		//clean snippet
		$snippet = preg_replace('/\s?\;?\.\.\./', '...', $snippet);
		//add item?
		if($link && $title) {
			$items[$link] = [
				'link' => $link,
				'title' => str_replace('&nbsp', ' ', strip_tags($title)),
				'snippet' => str_replace('&nbsp', ' ', strip_tags($snippet)),
				'time' => strtotime($time),
			];
		}
	}
	//return
	return $items;
}