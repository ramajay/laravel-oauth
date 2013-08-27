<?php namespace Thomaswelton\LaravelOauth;

use \Config;
use \Input;
use \Str;
use \Redis;
use \URL;

use \Session as LaravelSession;

use OAuth\ServiceFactory;
use OAuth\Common\Storage\Redis as OAuthRedis;
use OAuth\Common\Storage\SymfonySession;
use Symfony\Component\HttpFoundation\Session\Session;
use OAuth\Common\Consumer\Credentials;
use OAuth\Common\Http\Exception\TokenResponseException;

class OAuth extends ServiceFactory{

	public function login($service, $redirect = null)
	{
		$routePrefix = Config::get('laravel-oauth::route');
		return URL::to("{$routePrefix}/{$service}/login/?redirect={$redirect}");
	}

	public function getAuthorizationUri($service, $redirect = null)
	{
		$factory = $this->getServiceFactory($service);

		$state = $this->encodeState(array(
			'redirect' => $redirect
		));

		if($this->isOAuth2($service)){
			$authUriArray = array('state' => $state);
		}else{
			$token = $factory->requestRequestToken();
			$requestToken = $token->getRequestToken();

			// No state in OAuth 1.0
			// Handles custom redirects by setting the redirect in a session
			$this->setOAuth1State($token->getRequestToken(), $state);

			$authUriArray = array( 'oauth_token' => $requestToken);
		}

		$authUrl = $factory->getAuthorizationUri($authUriArray);

		return htmlspecialchars_decode($authUrl);
	}

	public function getServiceFactory($service)
	{
		if(!$this->serviceExists($service)){
			throw  new ServiceNotSupportedException( Str::studly($service) . ' is not a supported OAuth1 or OAuth2 service provider');
		}

		$credentials = $this->getCredentials($service);
		$scopes 	 = array_values( $this->getScopes($service) );

		$storage 	 = $this->getStorage();

		if($this->isOAuth2($service)){
			return $this->createService($service, $credentials, $storage, $scopes);
		}else{
			return $this->createService($service, $credentials, $storage);
		}
	}

	public function getCredentials($service)
	{
		return new Credentials(
			Config::get("laravel-oauth::{$service}.key"),
		    Config::get("laravel-oauth::{$service}.secret"),
			url("oauth/{$service}")
		);
	}

	public function getScopes($service)
	{
		$array = explode(',', Config::get("laravel-oauth::{$service}.scope"));
		return array_map("trim", $array);
	}

	public function getStorage()
	{
		switch (Config::get('session.driver')) {
			case 'redis':
				$redis = Redis::connection();
				return new OAuthRedis($redis, 'Thomaswelton\LaravelOauth');
				break;

			default:
				$session = new Session();
				return new SymfonySession($session);
				break;
		}
	}

	public function serviceExists($service)
	{
		return ($this->isOAuth2($service) || $this->isOAuth1($service));
	}

	public function isOAuth2($service)
	{
		$serviceName = ucfirst($service);
		$className = "\\OAuth\\OAuth2\\Service\\$serviceName";

		return class_exists($className);
	}

	public function isOAuth1($service)
	{
		$serviceName = ucfirst($service);
		$className = "\\OAuth\\OAuth1\\Service\\$serviceName";

		return class_exists($className);
	}

	public function encodeState($state)
	{
		return base64_encode(json_encode($state));
	}

	public function decodeState($state)
	{
		return json_decode(base64_decode($state));
	}

	public function getRedirectFromState($provider)
	{
		$decodedState = null;

		if($this->isOAuth2($provider)){
			$state = Input::get('state');
			$decodedState = (object) $this->decodeState($state);
		}else{
			$service = $this->getServiceFactory($provider);

			$namespace 	= $this->getStorageNamespace($service);
			$token 		= $this->getStorage()->retrieveAccessToken($namespace);

			$requestToken = $token->getRequestToken();

			$state = $this->getOAuth1State($token->getRequestToken());

			$decodedState = $this->decodeState($state);
		}

		if(property_exists($decodedState, 'redirect')){
			return $decodedState->redirect;
		}
	}

	public function setOAuth1State($requestToken, $state)
	{
		LaravelSession::put($requestToken . '_state', $state);
	}

	public function getOAuth1State($requestToken)
	{
		return LaravelSession::get($requestToken . '_state');
	}

	public function getStorageNamespace($service)
	{
		// get class name without backslashes
        $classname = get_class($service);
        return preg_replace('/^.*\\\\/', '', $classname);
	}

	public function requestAccessToken($provider)
	{
		$service = $this->getServiceFactory($provider);

		if($this->isOAuth2($provider)){
			// error required by OAuth 2.0 error_description optional
			$error = Input::get('error');

			if($error){
				$errorDescription = Input::get('error_description');
				$errorMessage = ($errorDescription) ? $errorDescription : $error;

				if($error == 'access_denied'){
					throw new UserDeniedException($errorMessage, 1);
				}else{
					throw new Exception($errorMessage, 1);
				}
			}

			try{
				return $service->requestAccessToken(Input::get('code'));
			}catch(TokenResponseException $e){
				throw new Exception($e->getMessage(), 1);
			}
		}else{
			if(Input::get('denied') || Input::get('oauth_token') == 'denied'){
				throw new UserDeniedException('User Denied OAuth Permissions', 1);
			}

			if(!Input::get('oauth_token')){
				throw new Exception("OAuth token not found", 1);
			}

			$namespace 	= $this->getStorageNamespace($service);
			$token 		= $this->getStorage()->retrieveAccessToken($namespace);

			try{
				return $service->requestAccessToken( Input::get('oauth_token'), Input::get('oauth_verifier'), $token->getRequestTokenSecret() );
			}catch(TokenResponseException $e){
				throw new Exception($e->getMessage(), 1);
			}
		}
	}
}