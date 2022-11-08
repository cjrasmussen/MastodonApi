# MastodonApi

Simple class for making requests to the Mastodon API.

## Usage

```php
use cjrasmussen\MastodonApi\MastodonApi;

$mastodon = new MastodonApi($server);

// SEND A TOOT WITH OAUTH TOKEN/SECRET
$mastodon->setBearerToken($bearer_token);
$response = $mastodon->request('POST', 'v1/statuses', ['status' => 'Toot text']);
```

## Installation

Simply add a dependency on cjrasmussen/mastodon-api to your composer.json file if you use [Composer](https://getcomposer.org/) to manage the dependencies of your project:

```sh
composer require cjrasmussen/mastodon-api
```

Although it's recommended to use Composer, you can actually include the file(s) any way you want.


## License

MastodonApi is [MIT](http://opensource.org/licenses/MIT) licensed.