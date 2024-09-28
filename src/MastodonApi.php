<?php

namespace cjrasmussen\MastodonApi;

use RuntimeException;

/**
 * Class for interacting with the Mastodon API
 */
class MastodonApi
{
	private ?string $host;
	private bool $useIdempotencyKey;
	private ?int $httpVersion = null;
	private ?string $bearerToken = null;
	private ?int $requestTimeout = null;

	public function __construct(?string $host = null, bool $useIdempotencyKey = false)
	{
		$this->host = $host;
		$this->useIdempotencyKey = $useIdempotencyKey;
	}

	/**
	 * @param string $host
	 * @param bool|null $useIdempotencyKey
	 * @return void
	 */
	public function initialize(string $host, ?bool $useIdempotencyKey = null): void
	{
		$this->host = $host;

		if ($useIdempotencyKey !== null) {
			$this->useIdempotencyKey = $useIdempotencyKey;
		}
	}

	/**
	 * Set the bearer token
	 *
	 * @param string|null $bearer_token
	 * @return void
	 */
	public function setBearerToken(?string $bearer_token = null): void
	{
		$this->bearerToken = $bearer_token;
	}

	/**
	 * Set the HTTP version for requests
	 *
	 * Expects a valid constant value for CURLOPT_HTTP_VERSION.
	 *
	 * @param int|null $http_version
	 * @return void
	 */
	public function setHttpVersion(?int $http_version = null): void
	{
		$this->httpVersion = $http_version;
	}

	public function setRequestTimeout(?int $timeout = null): void
	{
		$this->requestTimeout = $timeout;
	}

	/**
	 * Make a request to the Mastodon API
	 *
	 * @param string $type
	 * @param string $request
	 * @param array $args
	 * @param string|null $body
	 * @param bool $multipart
	 * @return mixed|object
	 * @throws RuntimeException
	 * @throws \JsonException
	 */
	public function request(string $type, string $request, array $args = [], ?string $body = null, bool $multipart = false)
	{
		if ($this->host === null) {
			$msg = 'Cannot execute API request without a host server defined.';
			throw new RuntimeException($msg);
		}

		$request = trim($request, ' /');

		$header = [];
		$directory = '';

		if (!$this->requestIsOauth($request)) {
			$directory = 'api/';
			if ($this->bearerToken) {
				$header[] = 'Authorization: Bearer ' . $this->bearerToken;
			}
		}

		$url = 'https://' . $this->host . '/' . $directory . $request;

		if (($type === 'GET') && (count($args))) {
			$url .= '?' . http_build_query($args);
		}

		if ($this->requestNeedsIdempotencyKey($type, $request)) {
			$header[] = 'Idempotency-Key: ' . $this->generateIdempotencyKey($type, $request, $args);
		}

		$c = curl_init();
		curl_setopt($c, CURLOPT_URL, $url);

		if ($this->httpVersion) {
			curl_setopt($c, CURLOPT_HTTP_VERSION, $this->httpVersion);
		}

		switch ($type) {
			case 'POST':
				curl_setopt($c, CURLOPT_POST, 1);
				break;
			case 'GET':
				curl_setopt($c, CURLOPT_HTTPGET, 1);
				break;
			default:
				curl_setopt($c, CURLOPT_CUSTOMREQUEST, $type);
		}

		if ($body) {
			curl_setopt($c, CURLOPT_POSTFIELDS, $body);
		} elseif (count($args)) {
			if ($multipart) {
				$header[] = 'Content-Type: multipart/form-data';
				curl_setopt($c, CURLOPT_POSTFIELDS, $args);
			} elseif ($type !== 'GET') {
				$header[] = 'Content-Type: application/json';
				curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($args, JSON_THROW_ON_ERROR));
			}
		} elseif ($type === 'POST') {
			curl_setopt($c, CURLOPT_POSTFIELDS, null);
		}

		if (count($header)) {
			curl_setopt($c, CURLOPT_HTTPHEADER, $header);
		}

		curl_setopt($c, CURLOPT_HEADER, 0);
		curl_setopt($c, CURLOPT_VERBOSE, 0);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 1);

		if ($this->requestTimeout !== null) {
			curl_setopt($c,CURLOPT_TIMEOUT, $this->requestTimeout);
		}

		$response = curl_exec($c);
		curl_close($c);

		if (!$response) {
			$msg = 'No response from Mastodon API at ' . $this->host;
			throw new RuntimeException($msg);
		}

		return json_decode($response, false, 512, JSON_THROW_ON_ERROR);
	}

	/**
	 * Request a bearer token
	 *
	 * This specific request needs to be handled differently for some reason
	 *
	 * @param string $client_id
	 * @param string $client_secret
	 * @param string $redirect_uri
	 * @param string $scope
	 * @param string $code
	 * @return string
	 * @throws RuntimeException
	 * @throws \JsonException
	 */
	public function requestBearerToken(string $client_id, string $client_secret, string $redirect_uri, string $scope, string $code): string
	{
		if ($this->host === null) {
			$msg = 'Cannot execute API request without a host server defined.';
			throw new RuntimeException($msg);
		}

		$c = curl_init();

		curl_setopt_array($c, array(
			CURLOPT_URL => 'https://' . $this->host . '/oauth/token?grant_type=authorization_code&client_id=' . $client_id . '&client_secret=' . $client_secret . '&redirect_uri=' . $redirect_uri . '&scope=' . urlencode($scope) . '&code=' . $code,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
		));

		$response = curl_exec($c);
		curl_close($c);

		return json_decode($response, false, 512, JSON_THROW_ON_ERROR)->access_token;
	}

	/**
	 * Determines if a specified request is in the oAuth pathing
	 *
	 * @param string $request
	 * @return bool
	 */
	private function requestIsOauth(string $request): bool
	{
		return strpos($request, 'oauth/') === 0;
	}

	/**
	 * Determines if a specified request needs an idempotency key
	 * @param string $type
	 * @param string $request
	 * @return bool
	 */
	private function requestNeedsIdempotencyKey(string $type, string $request): bool
	{
		return (($this->useIdempotencyKey) && ($type === 'POST') && (strpos($request, '/statuses') !== false));
	}

	/**
	 * Generates a unique key for the given request
	 *
	 * @param string $type
	 * @param string $request
	 * @param array $args
	 * @return string
	 * @throws \JsonException
	 */
	private function generateIdempotencyKey(string $type, string $request, array $args): string
	{
		return sha1(json_encode([$type, $request, $args], JSON_THROW_ON_ERROR));
	}
}
