<?php

require_once (__DIR__ . '/vendor/autoload.php');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
use Coderjerk\ElephantBird\TweetLookup;
use Coderjerk\ElephantBird\RecentSearch;

$conversation_data = array();
$conversation_includes = array();

function get_all($twid, $next=null) {
	global $conversation_data;
	global $conversation_includes;

	if (null != $next) {
		$params = [
		    'query'        => "conversation_id:{$twid}",
			 'tweet.fields' => "in_reply_to_user_id,author_id,created_at,conversation_id,referenced_tweets,public_metrics",
			 "expansions"   => "author_id",
			 'user.fields'  => "username,profile_image_url",
			 "next_token"   => $next,
			 'max_results'  => 100
		];
	} else {
		$params = [
		    'query'        => "conversation_id:{$twid}",
			 'tweet.fields' => "in_reply_to_user_id,author_id,created_at,conversation_id,referenced_tweets,public_metrics",
			 "expansions"   => "author_id",
			 'user.fields'  => "username,profile_image_url",
			 'max_results'  => 100
		];
	}

	$search = new RecentSearch;
	$results = $search->RecentSearchRequest($params);

	$data_objects = $results->data;
	foreach ($data_objects as $datum) {
		array_push($conversation_data, $datum);
	}

	$includes_objects = $results->includes->users;
	foreach ($includes_objects as $included) {
		array_push($conversation_includes, $included);
	}

	//	If there's a next token, there are more results. Can't rely on the number of results returned
	if (null != $results->meta->next_token) {
		get_all($twid, $results->meta->next_token);
	} else {
		// echo "Done!\n";
	}
}

function get_conversation($twid) {
	global $conversation_data;
	global $conversation_includes;
	//	Get the head of the conversation
	$id = [$twid];

	$params = [
	    'tweet.fields' => 'attachments,author_id,created_at,conversation_id',
		 "expansions"   => "author_id",
		 'user.fields'  => "username,profile_image_url",
	];

	$lookup = new TweetLookup;
	$root = $lookup->getTweetsById($id, $params);

	get_all($twid);
	$conversation = array("data" => $conversation_data, "includes" => $conversation_includes);

	$references = array();

	$posts = $conversation["data"];
	// Add root to start
	array_unshift($posts , $root->data[0]);

	$user_objects = $conversation["includes"];
	$root_user = $root->includes->users[0];
	array_unshift($user_objects , $root_user);

	$users = array();

	foreach ($user_objects as $user) {
		$users[$user->id] = $user->profile_image_url;
	}

	// var_dump($users);

	//	Iterate through, generating the object needed
	foreach ($posts as $post)
	{
		//	Get the data from the post
		$id = $post->id;
		$parent = $post->referenced_tweets[0]->id;
		$text = $post->text;
		// $bodyHTML = twitter_format($post);
		// $username = $post['user']['screen_name'];
		// $name = $post['user']['name'];
		$avatar = $users[$post->author_id];
		$time = strtotime($post->created_at)*1000;
		// if($rt) {
		// 	$retweets = $post['retweet_count'];
		// }
		// if($fav) {
		// 	$favs = $post['favorite_count'];
		// }

		//	Place the post in the array based on its ID
		$references[$id] = array(
			"tweet" => array(
				"reply_to" => $parent,
				// "images"   => array(),	//	TODO
				// "username" => htmlspecialchars($username,ENT_QUOTES),
				// "name"     => htmlspecialchars($name,ENT_QUOTES),
				"bodyText" => html_entity_decode($text),
				// "bodyHtml" => $bodyHTML,
				"id"       => $id,
				"avatar"   => $avatar,
				// "avatar" => "https://eu.ui-avatars.com/api/?name={$id}",
				"time"     => $time,
				// "replies"  => 0,
				// "retweets" => $retweets,
				// "favs"     => $favs,
			),
			"children" => array()
		);
	}

	//	Create the Tree
	$tree = array();

	//	Iterate through the references, using `&` means this is a reference,
	//	http://php.net/manual/en/language.references.php
	foreach ($references as &$post)
	{
		$id = $post['tweet']['id'];
		$parentId = $post['tweet']['reply_to'];

		//	If this is the start of the conversation
		if (!$parentId)
		{
			$tree[] =& $references[$id];
		}
		//	If this is a reply, add it as a child of its parent
		else
		{
			$references[$parentId]['children'][] =& $post;
		}
		//	Clear the reference
		unset($post);
	}
	//	Get rid of all the null values - otherwise things screw up
	$tree = array_filter($tree);

	//	Pretty Print so we can visually assess if this has worked
	$output = json_encode($tree,JSON_PRETTY_PRINT);

	// var_dump($output);

	//	Trim the "[" and "]"
	$output = substr($output, 1, -1);

	//	Output into the JavaScript
	// return "Treeverse.initializeForStaticData(document.getElementById('tweetContainer')," . $output .");";
	return $output;
}
