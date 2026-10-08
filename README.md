# group-payments/sdk для PHP

Клиент Group Pay API. PHP 8.1+, `ext-curl`, `ext-json`, без зависимостей Composer.

## Установка

Добавьте в `composer.json`:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/Group-Payments/sdk-php" }],
  "require": { "group-payments/sdk": "dev-main" }
}
```

и выполните `composer update`.

## Быстрый старт

```php
use GroupPayments\Client;

$gp = new Client(getenv('GP_API_KEY'), ['base_url' => getenv('GP_BASE_URL')]);

$payment = $gp->createPayment(['amount' => '100.00', 'description' => 'Заказ 42', 'orderId' => '42']);
echo $payment['paymentUrl'];
```

- Каждый POST получает `Idempotency-Key`, общий для всех повторов. Свой ключ передаётся последним аргументом.
- Повторы при 429, 500, 502, 503, 504 и сетевых ошибках, с экспоненциальной паузой и учётом `Retry-After`.
- Ошибки: `GroupPayments\ApiError` с полями `status`, `errorCode`, `requestId`, `param`, `docUrl`.
- Адрес API обязателен: опция `base_url` или переменная `GP_BASE_URL`. Ещё опция `max_attempts`. Ожидание ответа до 30 с на попытку.

## Вебхуки

```php
use GroupPayments\Webhook;

try {
    $event = Webhook::verify(file_get_contents('php://input'), getallheaders(), getenv('GP_WEBHOOK_SECRET'));
} catch (\UnexpectedValueException) {
    http_response_code(400);
    exit;
}
```

Принимает заголовки из `getallheaders()` и PSR-7 `getHeaders()`. Подпись `GP-Signature-V2` проверяется с допуском 300 с.

## Тесты

```sh
php tests/run.php
```

## Лицензия

MIT
