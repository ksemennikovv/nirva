# Фича: Подписки и оплата (/billing/)

## Что это

Монетизация платформы. Три тарифных плана × три периода оплаты. Платёжный провайдер выбирается автоматически по IP: для России — YooKassa, для остальных — Stripe. После успешной оплаты подписка активируется через webhook.

---

## Ключевые файлы

| Файл | Роль |
|------|------|
| `pages/billing/index.php` | Страница выбора тарифа |
| `src/services/Payment/PaymentService.php` | Создание платежей, активация подписок |
| `src/repositories/SubscriptionRepository.php` | CRUD подписок |
| `src/repositories/PaymentRepository.php` | CRUD платежей |
| `config/business.php` | Тарифы и цены (константы + PRICES массив) |

---

## Тарифные планы

Определены в `config/business.php`:

| План | Анализов/месяц | Описание |
|------|----------------|---------|
| `start` | 1 | Начальный |
| `basic` | 2 | Базовый |
| `transformation` | 8 | Трансформация |

Периоды: `monthly`, `semi-annual` (6 мес), `annual` (12 мес).

Цены в рублях хранятся в массиве `PRICES[plan][period]`.

---

## Поток оплаты

### 1. Пользователь выбирает тариф
На `/billing/` показаны карточки планов с ценами. Пользователь выбирает план + период → кнопка "Оплатить".

### 2. Создание платежа
`POST /billing/api/create-payment.php`:
- `PaymentService::detectProvider()` — определяет провайдера по IP (RU → YooKassa, иначе Stripe)
- `createYooKassaPayment()` или `createStripePayment()` — создаёт платёж
- Возвращает redirect URL на страницу оплаты провайдера

### 3. Оплата
Пользователь оплачивает на стороне провайдера.

### 4. Webhook
Провайдер присылает событие успешной оплаты:
- **YooKassa**: `payment.succeeded` → `/billing/api/yookassa-webhook.php`
- **Stripe**: `checkout.session.completed` → `/billing/api/stripe-webhook.php`

Обработка в `PaymentService`:
1. Верификация подписи (HMAC для Stripe, IP-check для YooKassa)
2. Находит платёж по `provider_payment_id`
3. Обновляет статус платежа на `succeeded`
4. `PaymentService::activateSubscription()`:
   - Деактивирует предыдущую активную подписку
   - Создаёт новую запись в `subscriptions`
   - Устанавливает `expires_at` на основе периода (30/180/365 дней)

---

## Подписка и лимиты

`src/middleware/subscription.php` вычисляет состояние при каждом запросе:
- `$subscription` — активная подписка или null
- `$subUsedReal` — сколько анализов уже сделано в текущем периоде
- `$subRemaining` — сколько осталось (0 = лимит исчерпан)
- `$subHeroState` — итоговое состояние (1=можно, 2=cooldown, 3=лимит, 4=нет подписки)
- `$subPaywallActive` — bool, показывать ли пейвол
- `$subBurnDate` — когда сгорит слот (dd.mm.YYYY)

Флаг `subscription_required` в `app_settings` позволяет отключить пейвол для тестирования.

---

## Реферальная программа

`src/repositories/ReferralRepository.php`:
- Каждый пользователь получает уникальный реферальный код (`findOrCreateCode()`)
- При успешной реферальной регистрации + оплате — `REFERRAL_BONUS_MONTHS` = 1 месяц бонуса
- Количество успешных рефералов: `countRewarded()`
