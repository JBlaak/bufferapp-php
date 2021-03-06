<?php
class BufferApp {

	/**
	 * Client id
	 * @var string
	 */
	private $client_id;

	/**
	 * Client secret
	 * @var string
	 */
	private $client_secret;

	/**
	 * Authorization code
	 * @var string
	 */
	private $code;

	/**
	 * Access token
	 * @var string
	 */
	private $access_token;
	
	/**
	 * Callback url
	 * @var string
	 */
	private $callback_url;

	/**
	 * Url used to authorize an application
	 * @var string
	 */
	private $authorize_url = 'https://bufferapp.com/oauth2/authorize';

	/**
	 * Url used to resolve the access token
	 * @var string
	 */
	private $access_token_url = 'https://api.bufferapp.com/1/oauth2/token.json';

	/**
	 * ApiV1 base url
	 * @var string
	 */
	private $buffer_url = 'https://api.bufferapp.com/1';

	/**
	 * Weither or not to validate the ssl certificate
	 * @var boolean
	 */
	public $secure = true;

	/**
	 * Weither the last request was ok
	 * @var boolean
	 */
	private $ok = true;
	
	/**
	 * Supported endpoints
	 * @var array
	 */
	private $endpoints = array(
		'/user' => 'get',
		'/profiles' => 'get',
		'/profiles/:id/schedules/update' => 'post',	// Array schedules [0][days][]=mon, [0][times][]=12:00
		'/profiles/:id/updates/reorder' => 'post',	// Array order, int offset, bool utc
		'/profiles/:id/updates/pending' => 'get',
		'/profiles/:id/updates/sent' => 'get',
		'/profiles/:id/schedules' => 'get',
		'/profiles/:id' => 'get',		
		'/updates/:id/update' => 'post',			// String text, Bool now, Array media ['link'], ['description'], ['picture'], Bool utc
		'/updates/create' => 'post',				// String text, Array profile_ids, Aool shorten, Bool now, Array media ['link'], ['description'], ['picture']
		'/updates/:id/destroy' => 'post',
		'/updates/:id' => 'get',
		'/links/shares' => 'get',
	);
	
	/**
	 * List of BufferApp error codes
	 * @var array
	 */
	public $errors = array(
		'invalid-endpoint' => 'The endpoint you supplied does not appear to be valid.',

		'400' => 'Bad request.',
		'403' => 'Permission denied.',
		'404' => 'Endpoint not found.',
		'405' => 'Method not allowed.',
		'1000' => 'An unknown error occurred.',
		'1001' => 'Access token required.',
		'1002' => 'Not within application scope.',
		'1003' => 'Parameter not recognized.',
		'1004' => 'Required parameter missing.',
		'1005' => 'Unsupported response format.',
		'1010' => 'Profile could not be found.',
		'1011' => 'No authorization to access profile.',
		'1012' => 'Profile did not save successfully.',
		'1013' => 'Profile schedule limit reached.',
		'1014' => 'Profile limit for user has been reached.',
		'1020' => 'Update could not be found.',
		'1021' => 'No authorization to access update.',
		'1022' => 'Update did not save successfully.',
		'1023' => 'Update limit for profile has been reached.',
		'1024' => 'Update limit for team profile has been reached.',
		'1028' => 'Update soft limit for profile reached.',
		'1030' => 'Media filetype not supported.',
		'1031' => 'Media filesize out of acceptable range.',
	);
	
	/**
	 * Construct the BufferApp class
	 * 
	 * @param string $client_id     
	 * @param string $client_secret 
	 * @param string $callback_url  
	 */
	public function __construct($client_id, $client_secret, $callback_url) {
		if($client_id) $this->setClientId($client_id);
		if($client_secret) $this->setClientSecret($client_secret);
		if($callback_url) $this->setCallBackUrl($callback_url);
		
		if(isset($_GET['code'])) {
			$this->code = $_GET['code'];
		}
	}
	
	/**
	 * Do a request to an endpoint
	 * @param  string $endpoint 
	 * @param  string $data     
	 * @return mixed
	 */
	public function go($endpoint, $data = array()) {
		if(in_array($endpoint, array_keys($this->endpoints))) {
			$done_endpoint = $endpoint;
		} else {
			$ok = false;
			
			//Replace the placeholders
			foreach (array_keys($this->endpoints) as $done_endpoint) {
				if(preg_match('/' . preg_replace('/(\:\w+)/i', '(\w+)', str_replace('/', '\/', $done_endpoint)) . '/i', $endpoint, $match)) {
					$ok = true;
					break;
				}
			}
			
			if(!$ok) return $this->error('invalid-endpoint');
		}
		
		if(!$data || !is_array($data)) $data = array();

		//Add the access token
		$data['access_token'] = $this->access_token;
		
		$method = $this->endpoints[$done_endpoint]; //get() or post()
		return $this->$method($this->buffer_url . $endpoint . '.json', $data);
	}
	
	/**
	 * Dispatch an error
	 * @param  integer $error 
	 * @param  string $content
	 * @return array
	 */
	public function error($error, $content) {
		$content = @json_decode($content, true);

		if(is_array($content)) {
			return $content;
		} else if(isset($this->errors[$error])) {
			return array('error' => $this->errors[$error]);
		} else {
			return array('error' => 'Unkown error ['.$error.']');
		}
	}
	
	/**
	 * Resolve the access token after the authentication
	 * @return string|boolean false on error
	 */
	public function resolveAccessToken($code = null) {
		if(!is_null($code)) {
			$this->code = $code;
		}

		$data = array(
			'code' => $this->code,
			'grant_type' => 'authorization_code',
			'client_id' => $this->client_id,
			'client_secret' => $this->client_secret,
			'redirect_uri' => $this->callback_url,
		);
		
		$response = $this->post($this->access_token_url, $data);

		if(!isset($response['access_token'])) {
			return $response;
		}

		$this->setAccessToken($response['access_token']);
		
		return $response;
	}
	
	/**
	 * Perform a request as the BufferApp api likes it
	 * @param  string  $url  
	 * @param  string  $data 
	 * @param  boolean $post 
	 * @return string
	 */
	public function request($url, $data = array(), $post = true) {

		//Reset ok status
		$this->ok = true;

		if(!$url) {
			return false;
		}
			
		//Curl options		
		$options = array(
			CURLOPT_RETURNTRANSFER => true, 
			CURLOPT_HEADER => false
			);
		
		//Dependent on HTTP method set data
		if($post) {
			$options += array(
				CURLOPT_POST => $post,
				CURLOPT_POSTFIELDS => $data
			);
		} else {
			$url .= '?' . http_build_query($data);
		}

		//Add ignoring of ssl when not in secure mode
		if(!$this->secure) {
			$options += array(
				CURLOPT_SSL_VERIFYHOST => false,
				CURLOPT_SSL_VERIFYPEER => false
				);
		}
		
		$ch = curl_init($url);
		curl_setopt_array($ch, $options);
		$rs = curl_exec($ch);

		$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		//Some kind of error code
		if($code >= 400) {
			$this->ok = false;
			return $this->error($code, $rs);
		}
		
		return json_decode($rs, true);
	}

	/**
	 * Check if everything is ok
	 * @return boolean 
	 */
	public function isOk() {
		return $this->ok;
	}
	
	/**
	 * Perform a get request
	 * @param  string $url  
	 * @param  string $data 
	 * @return mixed   
	 */
	public function get($url, $data = array()) {
		return $this->request($url, $data, false);
	}
	
	/**
	 * Perform a post request
	 * @param  string $url  
	 * @param  string $data 
	 * @return mixed      
	 */
	public function post($url, $data = array()) {
		return $this->request($url, $data, true);
	}
		
	/**
	 * Fetch url to redirect client to for the OAuth2 process
	 *
	 * @param  array $options are merged with the query
	 * @return String
	 */
	public function getLoginUrl($options) {
		$query = array(
			'client_id' => $this->client_id,
			'redirect_uri' => $this->callback_url,
			'response_type' => 'code'
			);

		$query = array_merge($query, $options);

		return $this->authorize_url . '?' . http_build_query($query);
	}

	/**
	 * Setter for the access token
	 * @param string $access_token 
	 */
	public function setAccessToken($access_token) {
		$this->access_token = $access_token;
	}
	
	/**
	 * Setter for the client id
	 * @param string $client_id 
	 */
	public function setClientId($client_id) {
		$this->client_id = $client_id;
	}
	
	/**
	 * Setter for the client secret
	 * @param string $client_secret 
	 */
	public function setClientSecret($client_secret) {
		$this->client_secret = $client_secret;
	}

	/**
	 * Setter for the callback url
	 * @param string $callback_url 
	 */
	public function setCallBackUrl($callback_url) {
		$this->callback_url = $callback_url;
	}
}
