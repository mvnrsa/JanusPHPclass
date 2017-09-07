<?php
/*
 * Client side php class for the janus-gateway
 * @author Marnus van Niekerk - mvn at mjvn dot net
 *  
 * Based extensively on janus.php by BenJaziaSadok
 *       https://github.com/BenJaziaSadok/janus-gateway-php
 *  
 */

class Janus
{
	var $server = null;
	var $password = null;
	var $last_json = '';
	var $last_assoc = '';
	var $sessionID = '';
	var $handleID = '';
	var $plugin = '';
	var $debug = 0;
	var $self_signed = false;

	function __construct($server, $password='janusrocks', $debug=0, $self_signed=false)
	{
		$this->server = $server;
		$this->password = $password;
		$this->debug = $debug;
		$this->self_signed = $self_signed;

		if ($this->debug > 2)
			error_reporting(E_ALL ^ E_NOTICE);

		return empty($server);
	}

	function gRS($length = 12)	// generate Random String
	{
	    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	    $charactersLength = strlen($characters);
	    $randomString = '';
	    for ($i = 0; $i < $length; $i++)
	        $randomString .= $characters[rand(0, $charactersLength - 1)];

	    return $randomString;
	}
	
	private function callAPI($method, $data=false, $url='')
	{
		if (empty($url))
		{
			$url = $this->server;
			if (!empty($this->sessionID))
				$url .= "/$this->sessionID";
			if (!empty($this->handleID) && $method != 'GET')
				$url .= "/$this->handleID";
		}

	    $curl = curl_init();
	
	    switch ($method)
	    {
	        case "POST":
	            curl_setopt($curl, CURLOPT_POST, 1);
	
	            if ($data)
	                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
	            break;
	        case "PUT":
	            curl_setopt($curl, CURLOPT_PUT, 1);
	            break;
	        default:
	            if ($data)
	                $url = sprintf("%s?%s", $url, http_build_query($data));
	    }
	
	    curl_setopt($curl, CURLOPT_URL, $url);
	    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

		if ($this->self_signed)	// Allow server to have a self signed cert
		{
			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		}

		if ($this->debug > 1)
			curl_setopt($curl, CURLOPT_VERBOSE, true);	// Debug curl session

		if ($this->debug)
			echo "$method $url\n";
	
	    $this->last_json = curl_exec($curl);
		$this->last_assoc = json_decode($this->last_json,true);

		if ($this->debug)
		{
			//print_r($this->last_json);
			print_r($this->last_assoc);
			echo "\n\n";
		}
	
	    curl_close($curl);

		if (!isset($this->last_assoc['janus']) || $this->last_assoc['janus'] != 'success')
			return false;
	
	    return $this->last_json;
	}

	function createSession()
	{
		$data = '{"janus":"create","transaction":"'.$this->gRS().'","apisecret":"'.$this->password.'"}';
		$result = $this->callAPI('POST',$data);
		if (isset($this->last_assoc['data']['id']))
			$this->sessionID = $this->last_assoc['data']['id'];
		else
			$this->sessionID = '';

		return $this->sessionID;
	}

	function connect()	// Alias for CreateSession
	{
		return $this->createSession();
	}

	function destroySession()
	{
		$data = '{"janus": "destroy", "transaction": "'.$this->gRS().'","apisecret":"'.$password.'"}';
		if ($this->callAPI('POST',$data))
			$this->sessionID = '';
	}

	function disconnect()	// Alias for DestroySession
	{
		return $this->destroySession();
	}

	function attach($plugin)
	{
		if (empty($this->sessionID))
			if (!$this->CreateSession())
				return false;

		$data = '{"janus": "attach", "plugin": "'.$plugin.'", "transaction": "'.$this->gRS().'","apisecret":"'.$password.'"}';
		$result = $this->callAPI('POST',$data);
		if (isset($this->last_assoc['data']['id']))
		{
			$this->handleID = $this->last_assoc['data']['id'];
			$this->plugin = $plugin;
		}
		else
		{
			$this->handleID= '';
			$this->plugin = '';
		}

		return $this->handleID;
	}

	function detach()
	{
		$data = '{"janus":"detach","transaction":"'.$this->gRS().'","apisecret":"'.$password.'"}';
		if ($this->callAPI('POST',$data))
		{
			$this->handleID = '';
			$this->plugin = '';
			return true;
		}
		else
			return false;
	}

	function sendTrickleCandidate($candidate)
	{
		$data = '{"janus": "trickle", "candidate": '.$candidate.', "transaction": "'.$this->gRS().'","apisecret":"'.$password.'"}';
		return $this->callAPI('POST',$data);
	}

	function sendMessage($message, $jsep='')
	{
		if ($jsep=='')
			$data = '{"janus": "message", "body": '.$message.', "transaction": "'.$this->gRS().'","apisecret":"'.$password.'"}';
		else
			$data = '{"janus": "message", "body": '.$message.', "transaction": "'.$this->gRS().'","jsep":'.$jsep.',"apisecret":"'.$password.'"}';

		return $this->callAPI('POST',$data);
	}

	function createRoom($room=0,$params=array())
	{
		if ($this->plugin != 'janus.plugin.videoroom' || empty($this->handleID))
			return false;

		$data = array('request'=>'create');
		if ($room > 0)
			$data['room'] = $room;
		foreach ($params as $key => $value)
			$data[$key] = $value;
		$message = json_encode($data);

		$retval = $this->sendMessage($message);

		if (isset($this->last_assoc['plugindata']['data']['videoroom']) &&
				$this->last_assoc['plugindata']['data']['videoroom'] == 'created')
			return $this->last_assoc['plugindata']['data']['room'];
		else
			return false;
	}

	function easyRoom($desc,$rec_dir='',$room=0,$bitrate=0,$publishers=6,$secret='')
	{
		if (empty($secret))
			$secret = $this->gRS(20);

		$params = array();
		$params['description'] = $desc;
		$params['secret'] = $secret;
		$params['publishers'] = $publishers;
		if ($bitrate > 0)
			$params['bitrate'] = $bitrate;
		if (!empty($rec_dir))
		{
			$params['record'] = true;
			$params['rec_dir'] = $rec_dir;
		}

		return $this->createRoom($room,$params);
	}

	function refresh()
	{
		$date = date_create();
		date_timestamp_get($date);
	
		$url = $this->server."/".$this->sessionID;
		$data = array("rid" => date_timestamp_get($date),
						"maxev" => 1,
						"_" => date_timestamp_get($date),
						"apisecret" => $this->password,
					);
		return $this->callAPI('GET',$data,$url);
	}
}
