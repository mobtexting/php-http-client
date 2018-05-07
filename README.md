# MOBtexting Http Client

## Installation

You can install the package via composer:

``` bash
composer require mobtexting/php-http-client
```

## Usage

```php
    $headers = ['Authorization: Bearer ' . $apiKey];
    $client = new Mobtexting\Client('https://api.example.com', $headers);

    $data = [
        'some' => 1, 'awesome' => 2, 'data' => 3
    ];

    $response = $client->post($data, $queryParams, $requestHeaders);

    var_dump(
        $response->statusCode(),
        $response->headers(),
        $response->body()
    );
```

## Security

If you discover any security related issues, please email support@mobtexting.com instead of using the issue tracker.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.