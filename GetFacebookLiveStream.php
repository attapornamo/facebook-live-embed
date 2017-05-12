<?php

// Like https://github.com/iacchus/youtube-live-embed

class GetFacebookLiveStream
{
	public $pageID;
	public $APP_Id;
	public $APP_Secret;

	public $jsonResponse; // pure server response
	public $objectResponse; // response decoded as object
	public $arrayRespone; // response decoded as array

	public $isLive; // true if there is a live streaming at the channel

	public $queryData; // query values as an array
	public $getTokenAddress;
	public $getAddress; // address to request GET
	public $getQuery; // data to request, encoded

	public $queryString; // Address + Data to request

	public $part;
	public $eventType;
	public $type;

	public $loaded_video_id;
	public $loaded_video_title;
	public $loaded_video_description;

	public $loaded_video_publishedAt;

	public $loaded_video_thumb_default;
	public $loaded_video_thumb_medium;
	public $loaded_video_thumb_high;

	public $channel_title;

	public function __construct($args)
	{

		$this->facebookPage 	= isset( $args['facebook_page'] ) ? $args['facebook_page'] : 0;
		$this->APP_Id					= isset( $args['app_id'] ) ? $args['app_id'] : 0;
		$this->APP_Secret 		= isset( $args['app_secret'] ) ? $args['app_secret'] : 0;
		$autoQuery 						= isset( $args['auto_query'] ) ? $args['auto_query'] : true;

		$this->embed_autoplay = false;//by default

		$this->getTokenAddress = "https://graph.facebook.com/oauth/access_token?";

		$this->videoPluginAddress = "https://www.facebook.com/plugins/video.php?";

		if($autoQuery == true) { $this->queryIt(); }
	}

	public function queryIt()
	{

		$this->fb = new Facebook\Facebook([
		  'app_id' => $this->APP_Id,
		  'app_secret' => $this->APP_Secret,
		  'default_graph_version' => 'v2.9',
	  ]);

		$this->access_token = $this->getFacebookToken()->objectResponse->access_token;

		$this->fb->setDefaultAccessToken( $this->access_token );


		if( isValidUrl( $this->facebookPage ) ){
			$this->facebookPageUrlData = parse_url( $this->facebookPage );

			// Break up path into parts
			$path_exploded = explode( "/", $this->facebookPageUrlData['path'] );

			$this->pageID = $path_exploded[1];
			//debug( $path_exploded[1] );

		} else {
			$this->pageID = $this->facebookPage;
		}


		$this->queryData = array(
			"fields" => "live_status,description,picture,from,created_time,permalink_url",
		);
		$this->getQuery = http_build_query($this->queryData); // transform array of data in url query
		$this->liveStreamsRequestPath = '/' . $this->pageID . '/videos?' . $this->getQuery;
		$this->liveStreamResponse = $this->getFacebookRequest( $this->liveStreamsRequestPath );

		$this->liveStreams = array();
		$this->vodStreams = array();

		foreach( $this->liveStreamResponse as $graphNode ){

			$live_status = $graphNode->getField('live_status');
			$id = $graphNode->getField('id');

			if( $live_status == "LIVE" ){
				$this->liveStreams[] = $graphNode;
			} else if( $live_status == "VOD" ){
				$this->vodStreams[] = $graphNode;
			}

		}

		// Autoplay if is stream is live
		$this->embed_autoplay = $this->isLive();

		$this->isLive();

		// Load up live video
		if($this->isLive)
		{
			$this->loaded_video = $this->liveStreams[0];
		// Else load up previous live stream
		} else if( !empty( $this->vodStreams ) ){
			$this->loaded_video = $this->vodStreams[0];
		// Else load up newest video
		} else {
			$this->loaded_video = $this->liveStreamResponse[0];
		}

		$this->loaded_video_id = $this->loaded_video->getField('id');
		$this->loaded_video_description = $this->loaded_video->getField('description');

		$this->loaded_video_published_at = $this->loaded_video->getField('created_time');
		$this->loaded_video_thumb_default = $this->loaded_video->getField('picture');
		// $this->loaded_video_thumb_medium = $this->objectResponse->items[0]->snippet->thumbnails->medium->url;
		// $this->loaded_video_thumb_high = $this->objectResponse->items[0]->snippet->thumbnails->high->url;
		//
		$this->channel_title = $this->loaded_video->getField('from')->getField('name');

		$this->loaded_video_url = 'https://www.facebook.com' . $this->loaded_video->getField('permalink_url');

		//return $this->liveStreamResponse;
	}

	public function requestFacebookToken()
	{

		$token = new stdClass();
		$token->birthday = date('U');

		$this->queryData = array(
			"client_id" => $this->APP_Id,
			"client_secret" => $this->APP_Secret,
			"grant_type" => "client_credentials",
		);

		$this->getQuery = http_build_query($this->queryData); // transform array of data in url query
		$this->queryString = $this->getTokenAddress . $this->getQuery;

		$token->jsonResponse = file_get_contents($this->queryString); // pure server response
		$token->objectResponse = json_decode($token->jsonResponse); // decode as object
		$token->arrayResponse = json_decode($token->jsonResponse, TRUE); // decode as array

		return $token;
	}

	public function getFacebookToken($key = 'default')
	{

		global $InstanceCache;

		$is_flushing = isFlushing("token");

		$CachedString = $InstanceCache->getItem( "fb_token_" . $key );

		if ( is_null($CachedString->get()) || $is_flushing !== false ) {

			$requested_data = $this->requestFacebookToken();

			$CachedString->set($requested_data)->expiresAfter( ONE_YEAR );//in seconds, also accepts Datetime
			$InstanceCache->save($CachedString); // Save the cache item just like you do with doctrine and entities

	    $output = $CachedString->get();

		} else {

			$output = $CachedString->get();

		}

		//debug( $CachedString->getTtl() / 3600 );

		return $output;
	}

	public function requestFacebookResource( $path )
	{

		try {
		  $response = $this->fb->get( $path )->getGraphEdge();
		} catch(Facebook\Exceptions\FacebookResponseException $e) {
		  // When Graph returns an error
		  echo 'Graph returned an error: ' . $e->getMessage();
		  exit;
		} catch(Facebook\Exceptions\FacebookSDKException $e) {
		  // When validation fails or other local issues
		  echo 'Facebook SDK returned an error: ' . $e->getMessage();
		  exit;
		}



		return $response;

	}


	public function getFacebookRequest( $path, $expires = 60 )
	{

		global $InstanceCache;

		$is_flushing = isFlushing();

		$key = filter_var( $path, FILTER_SANITIZE_STRING );

		$CachedString = $InstanceCache->getItem("fb_request_" . $key);

		if ( is_null($CachedString->get()) || $is_flushing !== false ) {

			$requested_data = $this->requestFacebookResource( $path );

			$CachedString->set($requested_data)->expiresAfter( $expires );//in seconds, also accepts Datetime
			$InstanceCache->save($CachedString); // Save the cache item just like you do with doctrine and entities

	    $output = $CachedString->get();

		} else {

			$output = $CachedString->get();

		}

		//debug( $CachedString->getTtl() / 3600 );

		return $output;
	}


	public function isLive($getOrNot = false)
	{
		if($getOrNot==true)
		{
			$this->queryIt();
		}

		$live_items = count( $this->liveStreams );

		if($live_items>0)
		{
			$this->isLive = true;
			return true;
		}
		else
		{
			$this->isLive = false;
			return false;
		}
	}

	public function getEmbedAddress()
	{

		$this->embedAddressQueryData = array(
			"href" => $this->loaded_video_url,
			"show_text" => 0,
			"autoplay" => $this->embed_autoplay,
			"allowfullscreen" => 1,
			"show_captions" => 0
		);
		$this->getembedAddressQuery = http_build_query($this->embedAddressQueryData); // transform array of data in url query

		return $this->videoPluginAddress . $this->getembedAddressQuery;

	}

}

?>
