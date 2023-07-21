<?php

namespace cjrasmussen\MastodonApi;

use Exception;
use RuntimeException;

/**
 * Class for interacting with the Mastodon API
 */
class MastodonApi
{
	private string $host;
	private bool $useIdempotencyKey;
	private ?string $bearer_token = null;

	public function __construct(string $host, bool $useIdempotencyKey = false)
	{
		$this->host = $host;
		$this->useIdempotencyKey = $useIdempotencyKey;
	}

	/**
	 * Set the bearer token
	 *
	 * @param string|null $bearer_token
	 * @return void
	 */
	public function setBearerToken(?string $bearer_token = null): void
	{
		$this->bearer_token = $bearer_token;
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
	 * @throws \JsonException
	 */
	public function request(string $type, string $request, array $args = [], ?string $body = null, bool $multipart = false)
	{
		$request = trim($request, ' /');

		$header = [];
		$directory = '';

		if (!$this->requestIsOauth($request)) {
			$directory = 'api/';
			if ($this->bearer_token) {
				$header[] = 'Authorization: Bearer ' . $this->bearer_token;
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
				curl_setopt($c, CURLOPT_POSTFIELDS, $args);
			} elseif ($type !== 'GET') {
				$header[] = 'Content-Type: application/json';
				curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($args, JSON_THROW_ON_ERROR));
			}
		}

		if (count($header)) {
			curl_setopt($c, CURLOPT_HTTPHEADER, $header);
		}

		curl_setopt($c, CURLOPT_HEADER, 0);
		curl_setopt($c, CURLOPT_VERBOSE, 0);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($c, CURLOPT_SSL_VERIFYPEER, 1);

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
	 * @throws \JsonException
	 */
	public function requestBearerToken(string $client_id, string $client_secret, string $redirect_uri, string $scope, string $code): string
	{
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
