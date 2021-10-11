<?php

namespace box;

class Client {

	protected $credentials;
	protected $paths = array();
	private $authFn;
	protected $cacheFolderItems=array();
	protected $cacheFolderItemsComplete=array();
	protected $cachePath=null;

	protected $apiCalls=0;


	protected $itemListFields;

	protected $syncFromLast=false;

	public function __construct($config) {
		
		/**
		 * to test just get a developer token and set $config to (object) array("token"=>"....")
		 */
		
		/**
		 * From within the box api dashboard, you can generate public and private keypair for jwt auth. 
		 * pass the decoded the json file as $config
		 */

		$this->credentials = $config;

		if (!isset($this->credentials->token)) {

			

			$authFn = $this->setAuthorization($config);
			print_r($authFn());
		}

		if (!(isset($this->credentials->token)||$this->authFn)) {
			throw new \Exeption('no token! or authFn');
		}

	}


	public function getApiCallCount(){
		return $this->apiCalls;
	}

	public function useCachePath($path){
		$this->cachePath=$path;
		return $this;
	}

	public function safeName($name){
		return preg_replace("/[^a-zA-Z0-9 _-]/", "", $name);
	}

	protected function setAuthorization($config) {

		$this->authFn = function () use ($config) {

			$private_key = $config->boxAppSettings->appAuth->privateKey;
			$passphrase = $config->boxAppSettings->appAuth->passphrase;
			$key = openssl_pkey_get_private($private_key, $passphrase);

			$authenticationUrl = 'https://api.box.com/oauth2/token';

			$claims = array(
				'iss' => $config->boxAppSettings->clientID,
				'sub' => $config->enterpriseID,
				'box_sub_type' => 'enterprise',
				'aud' => $authenticationUrl,
				// This is an identifier that helps protect against
				// replay attacks
				'jti' => base64_encode(random_bytes(64)),
				// We give the assertion a lifetime of 45 seconds
				// before it expires
				'exp' => time() + 45,
				'kid' => $config->boxAppSettings->appAuth->publicKeyID,
			);

			$assertion = \Firebase\JWT\JWT::encode($claims, $key, 'RS512');

			$client = new \GuzzleHttp\Client();

			$args = array(
				'timeout' => 15,
				'form_params' => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion' => $assertion,
					'client_id' => $config->boxAppSettings->clientID,
					'client_secret' => $config->boxAppSettings->clientSecret,
				),
			);

			$httpcode = 0;
			try {
				echo 'post auth: ' . "\n";

				$this->apiCalls++;

				$response = $client->request('post', $authenticationUrl, $args);
				$httpcode = $response->getStatusCode();

			} catch (\RequestException $e) {
				//echo $e->getRequest();
				if ($e->hasResponse()) {
					$httpcode = $e->getResponse()->getStatusCode();
					// echo $e->getResponse()->getBody();
				}
			}

			if ($httpcode !== 200) {
				throw new \Exception('Request Error: ' . $httpcode);
			}

			$auth = json_decode($response->getBody());

			$this->credentials = (object) array('token' => $auth->access_token, 'expires' => time() + $auth->expires_in);

			return $auth;

		};

		return $this->authFn;
	}

	public function listItems($path, $fields=null) {

		if($fields){
			$this->returnItemFields($fields);
		}


		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path: ' . $path);
		}

		return $this->_listItems($id);
	}
	

	
	protected function returnItemFields($fields){
		$this->itemListFields=$fields;
		return $this;
	}

	protected function _listItems($folderId = 0) {
		$list=array();
		$this->_iterateItems($folderId, function($item)use(&$list){
			$list[]=$item;
		});
		return $list;
	}

	protected function _iterateItems($folderId, $callback) {

		if($folderId<0){
			throw new \Exception('Expected valid id: '.$folderId);
		}


		$offset=0;
		$limit=1000;
		$itemIndex=0;

		$results=(object) array(
			'total_count' => 1,
		);

		$shouldCache=$this->shouldCacheItems($folderId);
		if($shouldCache){

			print_r(array_search($folderId, $this->paths));
			print_r(array_map(function($stack){
				return array_intersect_key($stack, array(
					'file'=>'', 'line'=>''
				));
			}, debug_backtrace()));
		}
		$shouldSkipCallback=false;


		$itemListFields=$this->itemListFields;
		if(is_array($itemListFields)){
			$itemListFields=implode(',', $itemListFields);
		}
		$this->itemListFields=null;

		while($offset<$results->total_count){

			$query=array(
					'limit' => $limit,
					'offset'=>$offset
				);

			if($itemListFields){
				$query['fields']=$itemListFields;
			}

			$response=$this->guzzleRequest('get', 'https://api.box.com/2.0/folders/' . $folderId . '/items', array('query' =>$query));
			

			$data= json_decode($response->getBody());

			//print_r($data);
			//$data->entries;
			
			if($shouldCache){
				foreach($data->entries as $item){
					$p=trim($this->resolvePath($folderId, $item),'/');
					//echo "cache: ".$item->id." ".$p."\n";
					$this->putPath($p,$item->id);
				}
			}

			foreach($data->entries as $item){
				if($shouldSkipCallback){
					echo 'skip callback (caching)'."\n";
					continue;
				}
				
				$shouldContinue=$callback($item, $itemIndex++);
				if($shouldContinue===false&&$shouldCache){
					$shouldSkipCallback=true;
					echo 'stop iterating, but continue to cache'."\n";
					continue;
				}


				if($shouldContinue===false){
					echo 'stop iterating and quit'."\n";
					return $this;
				}
			}
			$results->total_count=$data->total_count;

			$offset+=$limit;
		}

		if($shouldCache){
			$this->doneCachingItems($folderId);
		}

		return $this;
	}


	public function cacheItemsInPath($path){
		$this->cacheFolderItems[]=$this->getFolderId($path);
		return $this;
	}

	protected function resolvePath($parentId, $pathComponent){

		if(is_object($pathComponent)){
			$pathComponent=$pathComponent->name;
		}

		if($parentId>0){
			$path=array_search($parentId, $this->paths);
			if($path===false){



				throw new \Exception('path not found '.$parentId);
			}
			return $path.'/'.$pathComponent;
		}


		return $pathComponent;
	}

	protected function shouldCacheItems($folderId){
		return in_array($folderId, $this->cacheFolderItems)&&(!$this->hasCachedItems($folderId));
	}
	protected function doneCachingItems($folderId){
		echo "finished caching: ".$folderId.print_r(array_search($folderId, $this->paths), true)."\n";

		$this->cacheFolderItemsComplete[]=$folderId;
	}
	protected function hasCachedItems($folderId){
		return in_array($folderId, $this->cacheFolderItemsComplete);
	}


	public function hasFolder($path) {
		return $this->getFolderId($path) >= 0;
	}

	public function makeFolder($path) {


		if ($this->hasPath($path)) {
			return;
		}

		

		$result= $this->_makeFolder($path);
		echo 'make folder: '.$path.': '.$result."\n";
		return $result;
	}

	public function _makeFolder($path, $fromId = 0) {

		$path = trim($path, '/');
		$parts = explode('/', $path);

		$currentPath='/';

		while (count($parts)) {

			$current = array_shift($parts);
			$currentPath = trim($currentPath.'/'.$current, '/');
			//echo $current . "\n";

			$currentId = $this->_getFileOrFolderId($currentPath);
			if ($currentId >= 0) {
				//echo "found: " . $currentId;
				$fromId = $currentId;
				continue;
			}

			$client = new \GuzzleHttp\Client();

			$args = array(
				'timeout' => 15,
				'headers' => $this->getAuthHeader(),
			);

			$args['json'] = array(
				'parent' => array('id' => $fromId),
				'name' => $current,
			);

			echo 'make: ' . $path . ' in ' . $fromId;

			//print_r($args);

			$httpcode = 0;
			try {
				echo 'post: ' . "\n";

				$this->apiCalls++;

				$response = $client->request('post', 'https://api.box.com/2.0/folders', $args);
				$httpcode = $response->getStatusCode();

			} catch (\Exception $e) {
				//echo $e->getRequest();
				if ($e->hasResponse()) {
					$response=$e->getResponse();
					$httpcode = $e->getResponse()->getStatusCode();
					// echo $e->getResponse()->getBody();
				}
			}

			if ($httpcode !== 201) {

				if($httpcode === 409){
					//file name conflic already exists
					echo "confict: "+json_encode(json_decode($response->getBody()));
					continue;
				}

				throw new \Exception('Request Error: ' . $httpcode);
			}

			$data=json_decode($response->getBody());

			$currentId = $data->id;
			if ($currentId >= 0) {
				$fromId = $currentId;

				if(count($parts)==0){
					echo 'made folder: '.$path.' '.$currentId.' '.json_encode($data)."\n";
					$this->putPath($currentPath, $currentId);
					return $currentId;

				}else{
					echo 'made folder: '.$path.' '.$currentId.' '.json_encode($data).' '.json_encode($parts)."\n";
				}

				continue;
			}

			throw new \Exception('Failed to create folder');

		}
	}

	public function hasFile($remotePath, $filename) {
		if ($this->_getFileOrFolderId($remotePath . '/' . $filename) > 0) {
			return true;
		}
		return false;
	}

	public function uploadFile($remotePath, $file, $newname = null) {

		$parentId = $this->getFolderId($remotePath);
		if ($parentId < 0) {
			throw new \Exception('Missing path: '.$remotePath);
		}

		if (!$newname) {
			$newname = basename($file);
		}

		echo 'upload ' . $remotePath . ' ' . $newname . "\n";

		if ($this->_getFileOrFolderId($remotePath . '/' . $newname) > 0) {
			throw new \Exception('Already Exists');
		}


		$response=$this->guzzleRequest('post', 'https://upload.box.com/api/2.0/files/content' . $id, array('multipart' => array(
				array(
					'name' => 'attributes',
					'contents' => json_encode(array(
						'parent' => array('id' => $parentId),
						'name' => $newname,
					)),
				),
				array(
					'name' => 'FileContents',
					'contents' => file_get_contents($file),
					'filename' => $newname,

				)))
		);


		$data=json_decode($response->getBody());

		$path=$remotePath.'/'.$newname;
		$this->putPath($path, $data->entries[0]->id);

		echo 'uploaded file: '.$path.' ' .json_encode($data)."\n";

		return $data->entries[0]->id;

	}

	


	protected function _getFileOrFolderId($path, $fromId = 0) {

		$path = trim($path, '/');


		if ($fromId == 0 && $this->hasPath($path)) {
			return $this->getPathId($path);
		}

		if($this->hasCachedItems($fromId)&&(!isset($this->paths[trim($path,'/'),]))){
			echo 'not found cache : `' .$path."\n\n";
			return -1;
		}

		
		$parts = explode('/', $path);

		//echo 'get '.$path."\n";

		if(count($parts)>=2){

			

			$last=array_pop($parts);
			$fromId=$this->_getFileOrFolderId(implode('/', $parts));

			

			if($fromId<0){
				return $fromId;
			}
			$parts=array($last);

		}

		
		if(count($parts)==1){



			$current=$parts[0];

			$foundFolder=false;

			

			$this->_iterateItems($fromId, function($folder)use(&$foundFolder, $current){

				if (strtolower($folder->name) == strtolower($current)) {	
					$foundFolder=$folder;
					return false; //stop iterating!!
				}

			});

			if($foundFolder!==false){
				$this->putPath($this->resolvePath($fromId, $path),  $foundFolder->id);
				return $foundFolder->id;
			}


			echo 'not found in list : `' .$current."\n\n";

			//throw new \Exception('Not found: '.$path);
			return -1;

		}

		throw new \Exception('Invalid path: '.$path);

	}


	public function hasPath($path){
		if($this->cachePath&&file_exists($this->cachePath.'/'.trim($path,'/').'.json')){
			return true;
		}
		return isset($this->paths[trim($path,'/')]);
	}
	protected function getPathId($path){
		if($this->cachePath&&file_exists($this->cachePath.'/'.trim($path,'/').'.json')){
			$id= json_decode(file_get_contents($this->cachePath.'/'.trim($path,'/').'.json'))->id;
			$this->paths[trim($path,'/')]=$id;
			return $id;
		}
		return $this->paths[trim($path,'/')];
	}
	protected function putPath($path, $id){

		if(strpos(trim($path,'/'), 'geolive-site-images')!==0){
			throw new \Exception('expected root folder: geolive-site-images: '.$path);
		}

		if($this->cachePath){
			$file=$this->cachePath.'/'.trim($path,'/').'.json';
			$dir=dirname($file);
			if(!file_exists($dir)){
				mkdir($dir, 0777, true);
			}
			file_put_contents($file, json_encode(array('id'=>$id)));
			
		}
		$this->paths[trim($path,'/')] = $id;
		return $this;
	}


	public function getFolderId($path) {

		if (is_numeric($path)) {
			return $path;
		}

		return intval($this->_getFileOrFolderId($path, 0));
	}

	protected function getAuthHeader() {


		if(!isset($this->credentials->token)){

			if(!$this->authFn){
					throw new \Exception('No auth fn');
			}
			$auth=$this->authFn;
			print_r($auth());
			
		}

		if(isset($this->credentials->expires)&& $this->credentials->expires-150<time()){

			if(!$this->authFn){
				throw new \Exception('No auth fn');
			}
			$auth=$this->authFn;
			print_r($auth());
		}


		if(!isset($this->credentials->token)){
			throw new \Exception('No auth token');
		}


		return array(
			"Authorization" => "Bearer " . $this->credentials->token,
			'Accept-Encoding' => 'gzip, deflate'
		);

	}

	public function setFileTags($id, $tags = array()) {

		if(is_string($id)&&strpos($id, '/')!==false){
			$id=$this->getFolderId($id);
		}

		if ($id < 0) {
			throw new \Exception('Missing path');
		}

		echo 'set tags: ' . $id . ' ' . json_encode($tags);

		$response=$this->guzzleRequest('put', 'https://api.box.com/2.0/files/' . $id, array('json' => array(
			'tags' => $tags,
		)));


		echo 'set file tags: '.json_encode(json_decode($response->getBody()))."\n";

	}


	public function getFileTags($id) {

		if(is_string($id)&&strpos($id, '/')!==false){
			$id=$this->getFolderId($id);
		}

		if ($id < 0) {
			throw new \Exception('Missing path');
		}

		$response=$this->guzzleRequest('get', 'https://api.box.com/2.0/files/' . $id, array('query' => array(
			'fields' => 'tags',
		)));


		return json_decode($response->getBody())->tags;

	}

	public function setFolderTags($path, $tags = array()) {

		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path');
		}

		
		echo 'set tags: ' . $path . ' ' . json_encode($tags);
		$response=$this->guzzleRequest('put', 'https://api.box.com/2.0/folders/' . $id, array('json' => array(
			'tags' => $tags,
		)));


		echo 'set folder tags: '.json_encode(json_decode($response->getBody()))."\n";

	}
	public function getCollaborators($path) {

		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path');
		}

		
		$response=$this->guzzleRequest('get', 'https://api.box.com/2.0/folders/'.$id.'/collaborations');

		$collabs=json_decode($response->getBody());

		//echo 'get folder collaborations: '.json_encode($collabs)."\n";

		return $collabs->entries;

	}

	public function addCollaborator($path, $uid) {

		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path');
		}


		$response=$this->guzzleRequest('post', 'https://api.box.com/2.0/collaborations'  , array('json'=>array(
			'item'=>array(
				'type'=>'folder',
				'id'=>$id
			),
			'accessible_by'=>array(
				'type'=>'user',
				'id'=>$uid
			),
			'role'=>'co-owner'
		)));



		echo 'get folder collaborations: '.json_encode(json_decode($response->getBody()))."\n";


	}


	public function getWebhooks() {



		$response=$this->guzzleRequest('get', 'https://api.box.com/2.0/webhooks');
		$webhooks=json_decode($response->getBody());

		echo 'get webhooks: '.json_encode($webhooks)."\n";

		return $webhooks->entries;

	}

	public function getWebhook($id) {



		$response=$this->guzzleRequest('get', 'https://api.box.com/2.0/webhooks/'.$id);
		$webhook=json_decode($response->getBody());

		echo 'get webhook: '.$id.json_encode($webhook)."\n";

		return $webhook;

	}

	public function createWebhook($path, $url) {

		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path');
		}

		$args=array('json'=>array(
			'target'=>array(
				'type'=>'folder',
				'id'=>$id.""
			),
			'triggers'=>["FILE.UPLOADED", "FILE.TRASHED", "FILE.DELETED", "FILE.RESTORED", "FILE.COPIED", "FILE.MOVED", "FILE.LOCKED", "FILE.UNLOCKED", "FILE.RENAMED",   "COMMENT.CREATED", "COMMENT.UPDATED", "COMMENT.DELETED"],
			'address'=>$url
		));

		print_r($args);
		$response=$this->guzzleRequest('post', 'https://api.box.com/2.0/webhooks'  , $args);



		echo 'create webhook: '.json_encode(json_decode($response->getBody()))."\n";


	}




	public function getEvents($callback){

		

		$events=array();
		$date=time()-3*24*60*60;
		$query=array(
				"stream_type"=>"all",
				"event_type"=>implode(',', array("TAG_ITEM_CREATE", "ITEM_RENAME", "ITEM_CREATE", "ITEM_UPLOAD")),
				"limit"=>500

			);

		$query['stream_position']=15201207722636874;

		if($this->syncFromLast&&($file=$this->getStreamPositionCacheFile())!==false&&file_exists($file)){
					
			$next=json_decode(file_get_contents($file));
			if($next&&isset($next->stream_position)){
				$query['stream_position']=$next->stream_position;
			}
			
		}

		

		print_r($date);

		while(true){

	
			$response=$this->guzzleRequest('get', 'https://api.box.com/2.0/events/', array('query'=>$query));

			$eventResponse=json_decode($response->getBody());
			echo 'get events: '.json_encode(array_diff_key(get_object_vars($eventResponse), array('entries'=>'')), JSON_PRETTY_PRINT)."\n";

			if(count($eventResponse->entries)==0){
				break;
			}

			foreach ($eventResponse->entries as $event) {
				//print_r($event);
				if(strtotime($event->created_at)<$date){
					continue;
				}
				$events[]=$event;
				if($callback){
					$callback($event);
				}
			}

			if($eventResponse->next_stream_position){
				$query['stream_position']=$eventResponse->next_stream_position;
				if(($file=$this->getStreamPositionCacheFile())!==false){
					
					file_put_contents($file, json_encode(array('stream_position'=>$eventResponse->next_stream_position)));
					
				}
				
			}


		}

		return $events;


	}

	protected function getStreamPositionCacheFile(){
		if($this->cachePath){
			$file=$this->cachePath.'/next_stream_position.json';
			$dir=dirname($file);
			if(!file_exists($dir)){
				mkdir($dir, 0777, true);
			}

			return $file;
			
		}
		return false;
	}


	public function getFile($id){

		$response=$this->guzzleRequest('get', 'https://api.box.com/2.0/files/'.$id.'/content');
		return $response->getBody();

	}


	protected function guzzleRequest($method, $url, $args=array()){


		$args = array_merge(array(
			'timeout' => 15,
			'headers' => $this->getAuthHeader()
		), $args);


		$httpcode = 0;
		try {

			echo 'box '.$method.': ' .$url. "\n";

			$this->apiCalls++;

			$client = new \GuzzleHttp\Client();
			$response = $client->request($method, $url, $args);
			$httpcode = $response->getStatusCode();

		} catch (GuzzleHttp\Exception\RequestException $e) {
			//echo $e->getRequest();
			if ($e->hasResponse()) {
				$httpcode = $e->getResponse()->getStatusCode();
				// echo $e->getResponse()->getBody();
			}
		} catch (GuzzleHttp\Exception\ServerException $e) {
			sleep(2);
			return guzzleRequestAttempt($method, $url, $args=array());

		} catch (GuzzleHttp\Exception\ConnectException $e) {
			sleep(2);
			return guzzleRequestAttempt($method, $url, $args=array());

		}



		if ($httpcode !== 200&&$httpcode !== 201) {



			throw new \Exception('Request Error: ' . $httpcode);
		}

		return $response;

	}


	protected function guzzleRequestAttempt($method, $url, $args=array()){


		$args = array_merge(array(
			'timeout' => 15,
			'headers' => $this->getAuthHeader()
		), $args);


		$httpcode = 0;
		

		echo 'box '.$method.': ' .$url. "\n";

		$this->apiCalls++;

		$client = new \GuzzleHttp\Client();
		$response = $client->request($method, $url, $args);
		$httpcode = $response->getStatusCode();

		



		if ($httpcode !== 200&&$httpcode !== 201) {



			throw new \Exception('Request Error: ' . $httpcode);
		}

		return $response;

	}

}
