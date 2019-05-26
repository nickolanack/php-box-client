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

	public function __construct($config) {
		
		/**
		 * to test just get a developer token and set $config to (object) array("token"=>"....")
		 */
		
		/**
		 * From within the box api dashboard, you can generate public and private keypair for jwt auth. 
		 * pass the decoded the json file as $config
		 */

		$this->credentials = $config;

		if (!key_exists('token', $this->credentials)) {

			

			$authFn = $this->setAuthorization($config);
			print_r($authFn());
		}

		if (!(key_exists('token', $this->credentials)||$this->authFn)) {
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

	public function listItems($path) {

		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path: ' . $path);
		}

		return $this->_listItems($id);
	}


	protected function _listItems($folderId = 0) {
		$list=array();
		$this->_iterateItems($folderId, function($item)use(&$list){
			$list[]=$item;
		});
		return $list;
	}

	protected function _iterateItems($folderId, $callback) {

		$client = new \GuzzleHttp\Client();

		if($folderId<0){
			throw new \Exception('Expected valid id: '.$folderId);
		}

		$args = array(
			'timeout' => 15,
			'headers' => $this->getAuthHeader(),


		);

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


		while($offset<$results->total_count){

			$args['query'] = array(
					'limit' => $limit,
					'offset'=>$offset
				);

			

			$httpcode = 0;
			try {
				
				$this->apiCalls++;

				$response = $client->request('get', 'https://api.box.com/2.0/folders/' . $folderId . '/items', $args);
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

		$client = new \GuzzleHttp\Client();

		$args = array(
			'timeout' => 15,
			'headers' => $this->getAuthHeader(),
		);

		$args['multipart'] = array(
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

			));

		$httpcode = 0;
		try {
			echo 'post: ' . "\n";

			$this->apiCalls++;

			$response = $client->request('post', 'https://upload.box.com/api/2.0/files/content', $args);
			$httpcode = $response->getStatusCode();

		} catch (\RequestException $e) {
			//echo $e->getRequest();
			if ($e->hasResponse()) {
				$httpcode = $e->getResponse()->getStatusCode();
				// echo $e->getResponse()->getBody();
			}
		}

		if ($httpcode !== 201) {
			throw new \Exception('Request Error: ' . $httpcode);
		}

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

		if($this->hasCachedItems($fromId)&&(!key_exists(trim($path,'/'), $this->paths))){
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
		return key_exists(trim($path,'/'), $this->paths);
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


		if(!key_exists('token',$this->credentials)){

			if(!$this->authFn){
					throw new \Exception('No auth fn');
			}
			$auth=$this->authFn;
			print_r($auth());
			
		}

		if(key_exists('expires', $this->credentials)&& $this->credentials->expires-150<time()){

			if(!$this->authFn){
				throw new \Exception('No auth fn');
			}
			$auth=$this->authFn;
			print_r($auth());
		}


		if(!key_exists('token',$this->credentials)){
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

		$client = new \GuzzleHttp\Client();

		$args = array(
			'timeout' => 15,
			'headers' => $this->getAuthHeader(),
		);

		$args['json'] = array(
			'tags' => $tags,
		);

		echo 'set tags: ' . $id . ' ' . json_encode($tags);

		$httpcode = 0;
		try {

			echo 'put: ' . "\n";

			$this->apiCalls++;

			$response = $client->request('put', 'https://api.box.com/2.0/files/' . $id, $args);
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


		echo 'set file tags: '.json_encode(json_decode($response->getBody()))."\n";

	}

	public function setFolderTags($path, $tags = array()) {

		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path');
		}

		$client = new \GuzzleHttp\Client();

		$args = array(
			'timeout' => 15,
			'headers' => $this->getAuthHeader(),
		);

		$args['json'] = array(
			'tags' => $tags,
		);

		echo 'set tags: ' . $path . ' ' . json_encode($tags);

		$httpcode = 0;
		try {

			echo 'put: ' . "\n";

			$this->apiCalls++;

			$response = $client->request('put', 'https://api.box.com/2.0/folders/' . $id, $args);
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

		echo 'set folder tags: '.json_encode(json_decode($response->getBody()))."\n";

	}
	public function getCollaborators($path) {

		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path');
		}

		$client = new \GuzzleHttp\Client();

		$args = array(
			'timeout' => 15,
			'headers' => $this->getAuthHeader(),
		);

		// $args['json'] = array(
		// 	'owned_by' => $owner
		// );


		$httpcode = 0;
		try {

			echo 'put: ' . "\n";

			$this->apiCalls++;

			$response = $client->request('get', 'https://api.box.com/2.0/folders/'.$id.'/collaborations'  , $args);
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


		echo 'get folder collaborations: '.json_encode(json_decode($response->getBody()))."\n";


	}

	public function addCollaborator($path, $uid) {

		$id = $this->getFolderId($path);
		if ($id < 0) {
			throw new \Exception('Missing path');
		}

		$client = new \GuzzleHttp\Client();

		$args = array(
			'timeout' => 15,
			'headers' => $this->getAuthHeader(),
		);

		$args['json'] = array(
			'item'=>array(
				'type'=>'folder',
				'id'=>$id
			),
			'accessible_by'=>array(
				'type'=>'user',
				'id'=>$uid
			),
			'role'=>'co-owner'
		);


		$httpcode = 0;
		try {

			echo 'put: ' . "\n";

			$this->apiCalls++;

			$response = $client->request('post', 'https://api.box.com/2.0/collaborations'  , $args);
			$httpcode = $response->getStatusCode();

		} catch (\RequestException $e) {
			//echo $e->getRequest();
			if ($e->hasResponse()) {
				$httpcode = $e->getResponse()->getStatusCode();
				// echo $e->getResponse()->getBody();
			}
		}

		if ($httpcode !== 201) {
			throw new \Exception('Request Error: ' . $httpcode);
		}


		echo 'get folder collaborations: '.json_encode(json_decode($response->getBody()))."\n";


	}

}
