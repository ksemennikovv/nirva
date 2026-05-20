<?php

require_once __DIR__ . '/../../repositories/PaymentRepository.php';
require_once __DIR__ . '/../../repositories/SubscriptionRepository.php';
require_once dirname(__DIR__, 3) . '/config/business.php';

class PaymentService
{
    private PaymentRepository      $paymentRepo;
    private SubscriptionRepository $subRepo;

    public function __construct()
    {
        $this->paymentRepo = new PaymentRepository();
        $this->subRepo     = new SubscriptionRepository();
    }

    private static function parseMeta(string $description, string $key): ?string
    {
        if (preg_match('/\|' . preg_quote($key, '/') . '=([^|]+)/', $description, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function getPlans(): array
    {
        return BusinessConfig::PLAN_ANALYSES;
    }

    public static function getPrices(): array
    {
        return BusinessConfig::PRICES;
    }

    public static function getPrice(string $plan, string $period): int
    {
        return BusinessConfig::getPrice($plan, $period);
    }

    /**
     * Определяет платёжного провайдера по IP.
     * Простая эвристика: RU-адрес → YooKassa, остальные → Stripe.
     */
    public static function detectProvider(string $ip): string
    {
        // Для локальной разработки — всегда yookassa
        if (in_array($ip, ['127.0.0.1', '::1'])) return 'yookassa';
        // В продакшене можно подключить geoip-библиотеку
        return 'yookassa';
    }

    /**
     * Создаёт платёж YooKassa и возвращает URL для перенаправления.
     */
    public function createYooKassaPayment(
        int    $userId,
        string $plan,
        string $period,
        string $returnUrl
    ): array {
        $amount      = self::getPrice($plan, $period);
        $description = "Nirva — тариф «{$plan}», {$period}";

        $paymentId = $this->paymentRepo->create([
            'user_id'          => $userId,
            'amount'           => $amount,
            'currency'         => 'RUB',
            'status'           => 'pending',
            'payment_provider' => 'yookassa',
            'description'      => $description . '|plan=' . $plan . '|period=' . $period,
        ]);

        // Реальный вызов YooKassa API
        $shopId  = defined('YOOKASSA_SHOP_ID')  ? YOOKASSA_SHOP_ID  : '';
        $secret  = defined('YOOKASSA_SECRET_KEY') ? YOOKASSA_SECRET_KEY : '';
        $idempotenceKey = 'nirva-' . $paymentId . '-' . time();

        $payload = [
            'amount'       => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'description'  => $description,
            'metadata'     => ['nirva_payment_id' => $paymentId, 'plan' => $plan, 'period' => $period, 'user_id' => $userId],
            'capture'      => true,
        ];

        $ch = curl_init('https://api.yookassa.ru/v3/payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Idempotence-Key: ' . $idempotenceKey,
            ],
            CURLOPT_USERPWD => $shopId . ':' . $secret,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'yookassa_api_error'];
        }

        $data = json_decode($response, true);

        $this->paymentRepo->updateStatus($paymentId, 'pending', $data['id'] ?? null);

        return [
            'success'      => true,
            'redirect_url' => $data['confirmation']['confirmation_url'] ?? null,
            'payment_id'   => $paymentId,
        ];
    }

    /**
     * Создаёт платёж Stripe и возвращает URL Checkout Session.
     */
    public function createStripePayment(
        int    $userId,
        string $plan,
        string $period,
        string $returnUrl
    ): array {
        $amount      = self::getPrice($plan, $period);
        $description = "Nirva — {$plan}, {$period}";

        $paymentId = $this->paymentRepo->create([
            'user_id'          => $userId,
            'amount'           => $amount,
            'currency'         => 'RUB',
            'status'           => 'pending',
            'payment_provider' => 'stripe',
            'description'      => $description . '|plan=' . $plan . '|period=' . $period,
        ]);

        $secretKey = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';

        $payload = http_build_query([
            'payment_method_types[]'      => 'card',
            'line_items[0][price_data][currency]'                        => 'rub',
            'line_items[0][price_data][product_data][name]'              => $description,
            'line_items[0][price_data][unit_amount]'                     => $amount * 100,
            'line_items[0][quantity]'                                    => 1,
            'mode'                        => 'payment',
            'success_url'                 => $returnUrl . '?status=success',
            'cancel_url'                  => $returnUrl . '?status=cancel',
            'metadata[nirva_payment_id]'  => $paymentId,
            'metadata[plan]'              => $plan,
            'metadata[period]'            => $period,
            'metadata[user_id]'           => $userId,
        ]);

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_USERPWD        => $secretKey . ':',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'stripe_api_error'];
        }

        $data = json_decode($response, true);

        $this->paymentRepo->updateStatus($paymentId, 'pending', $data['id'] ?? null);

        return [
            'success'      => true,
            'redirect_url' => $data['url'] ?? null,
            'payment_id'   => $paymentId,
        ];
    }

    /**
     * Активирует подписку после успешного платежа.
     */
    public function activateSubscription(int $userId, string $plan, string $period): void
    {
        $analyses   = BusinessConfig::getPlanAnalyses($plan);
        $months     = match ($period) { '6months' => 6, '12months' => 12, default => 1 };
        $startsAt   = date('Y-m-d H:i:s');
        $expiresAt  = date('Y-m-d H:i:s', strtotime("+{$months} months"));

        // Деактивировать предыдущую подписку
        $active = $this->subRepo->getActive($userId);
        if ($active) {
            $this->subRepo->updateStatus($active['id'], 'cancelled');
        }

        $this->subRepo->create([
            'user_id'           => $userId,
            'plan'              => $plan,
            'period'            => $period,
            'analyses_per_month'=> $analyses,
            'starts_at'         => $startsAt,
            'expires_at'        => $expiresAt,
        ]);
    }

    /**
     * Обрабатывает webhook от YooKassa.
     */
    public function handleYooKassaWebhook(array $payload): bool
    {
        $event  = $payload['event']  ?? '';
        $object = $payload['object'] ?? [];

        if ($event !== 'payment.succeeded') return true;

        $providerPaymentId = $object['id'] ?? '';
        $meta              = $object['metadata'] ?? [];

        $payment = $this->paymentRepo->findByProviderPaymentId('yookassa', $providerPaymentId);
        if (!$payment || $payment['status'] === 'completed') return true;

        $this->paymentRepo->updateStatus($payment['id'], 'completed', $providerPaymentId);

        $plan   = $meta['plan']   ?? self::parseMeta($payment['description'], 'plan');
        $period = $meta['period'] ?? self::parseMeta($payment['description'], 'period');
        $userId = (int)($meta['user_id'] ?? $payment['user_id']);

        if ($plan && $period && $userId) {
            $this->activateSubscription($userId, $plan, $period);
        }

        return true;
    }

    /**
     * Обрабатывает webhook от Stripe.
     */
    public function handleStripeWebhook(string $payload, string $sigHeader): bool
    {
        $secret = defined('STRIPE_WEBHOOK_SECRET') ? STRIPE_WEBHOOK_SECRET : '';

        // Проверка подписи
        $parts    = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = explode('=', $part, 2);
            $parts[$k][] = $v;
        }
        $timestamp = $parts['t'][0] ?? 0;
        $expected  = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        if (!in_array($expected, $parts['v1'] ?? [])) return false;

        $event = json_decode($payload, true);
        if (($event['type'] ?? '') !== 'checkout.session.completed') return true;

        $session = $event['data']['object'] ?? [];
        $meta    = $session['metadata']     ?? [];

        $providerPaymentId = $session['id'] ?? '';
        $payment = $this->paymentRepo->findByProviderPaymentId('stripe', $providerPaymentId);

        if ($payment && $payment['status'] !== 'completed') {
            $this->paymentRepo->updateStatus($payment['id'], 'completed', $providerPaymentId);

            $plan   = $meta['plan']   ?? self::parseMeta($payment['description'], 'plan');
            $period = $meta['period'] ?? self::parseMeta($payment['description'], 'period');
            $userId = (int)($meta['user_id'] ?? $payment['user_id']);

            if ($plan && $period && $userId) {
                $this->activateSubscription($userId, $plan, $period);
            }
        }

        return true;
    }
}
