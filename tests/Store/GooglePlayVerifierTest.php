<?php

namespace Abante4wd\Iap\Tests\Store;

use Abante4wd\Iap\Store\Config\GoogleConfig;
use Abante4wd\Iap\Store\GooglePlayVerifier;
use Google\Service\AndroidPublisher;
use Google\Service\AndroidPublisher\AutoRenewingPlan;
use Google\Service\AndroidPublisher\Resource\PurchasesSubscriptionsv2;
use Google\Service\AndroidPublisher\SubscriptionPurchaseLineItem;
use Google\Service\AndroidPublisher\SubscriptionPurchaseV2;
use PHPUnit\Framework\TestCase;

class GooglePlayVerifierTest extends TestCase
{
    private function makeConfig(): GoogleConfig
    {
        return new GoogleConfig(
            applicationName: 'Test App',
            serviceAccountJsonPath: '/nonexistent/service-account.json',
            packageName: 'com.example.app',
        );
    }

    /**
     * テスト用の GooglePlayVerifier と PurchasesSubscriptionsv2 リソースモックのペアを返す。
     *
     * @return array{0: GooglePlayVerifier, 1: PurchasesSubscriptionsv2&\PHPUnit\Framework\MockObject\MockObject}
     */
    private function makeVerifier(): array
    {
        $publisher = $this->createMock(AndroidPublisher::class);
        $subResource = $this->createMock(PurchasesSubscriptionsv2::class);
        $publisher->purchases_subscriptionsv2 = $subResource;

        return [new GooglePlayVerifier($this->makeConfig(), $publisher), $subResource];
    }

    /**
     * テスト用の SubscriptionPurchaseV2 モックを返す。
     */
    private function makeSubscriptionResponse(
        string $state,
        ?string $startTime = '2024-01-01T00:00:00Z',
        string $expiryTime = '2025-01-01T00:00:00Z',
        bool $autoRenew = true,
        string $latestOrderId = 'GPA.1234-5678',
    ): SubscriptionPurchaseV2 {
        $response = $this->createMock(SubscriptionPurchaseV2::class);
        $response->method('getSubscriptionState')->willReturn($state);
        $response->method('getLatestOrderId')->willReturn($latestOrderId);
        $response->method('getStartTime')->willReturn($startTime);
        $response->method('toSimpleObject')->willReturn(null);

        $autoRenewingPlan = $this->createMock(AutoRenewingPlan::class);
        $autoRenewingPlan->method('getAutoRenewEnabled')->willReturn($autoRenew);

        $lineItem = $this->createMock(SubscriptionPurchaseLineItem::class);
        $lineItem->method('getExpiryTime')->willReturn($expiryTime);
        $lineItem->method('getAutoRenewingPlan')->willReturn($autoRenewingPlan);

        $response->method('getLineItems')->willReturn([$lineItem]);

        return $response;
    }

    public function test_constructor_accepts_injected_publisher(): void
    {
        $publisher = $this->createMock(AndroidPublisher::class);

        $verifier = new GooglePlayVerifier($this->makeConfig(), $publisher);

        $this->assertInstanceOf(GooglePlayVerifier::class, $verifier);
    }

    // --- Bug 1: isValid 判定 ---

    public function test_on_hold_subscription_returns_invalid(): void
    {
        [$verifier, $resource] = $this->makeVerifier();
        $resource->method('get')->willReturn(
            $this->makeSubscriptionResponse('SUBSCRIPTION_STATE_ON_HOLD')
        );

        $result = $verifier->verifySubscription('com.example.premium', 'token_abc');

        $this->assertFalse($result->isValid);
        $this->assertSame('paused', $result->subscriptionInfo->status);
    }

    public function test_paused_subscription_returns_invalid(): void
    {
        [$verifier, $resource] = $this->makeVerifier();
        $resource->method('get')->willReturn(
            $this->makeSubscriptionResponse('SUBSCRIPTION_STATE_PAUSED')
        );

        $result = $verifier->verifySubscription('com.example.premium', 'token_abc');

        $this->assertFalse($result->isValid);
    }

    public function test_active_subscription_returns_valid(): void
    {
        [$verifier, $resource] = $this->makeVerifier();
        $resource->method('get')->willReturn(
            $this->makeSubscriptionResponse('SUBSCRIPTION_STATE_ACTIVE')
        );

        $result = $verifier->verifySubscription('com.example.premium', 'token_abc');

        $this->assertTrue($result->isValid);
        $this->assertSame('active', $result->subscriptionInfo->status);
    }

    public function test_cancelled_subscription_returns_valid(): void
    {
        [$verifier, $resource] = $this->makeVerifier();
        $resource->method('get')->willReturn(
            $this->makeSubscriptionResponse('SUBSCRIPTION_STATE_CANCELED')
        );

        $result = $verifier->verifySubscription('com.example.premium', 'token_abc');

        $this->assertTrue($result->isValid);
        $this->assertSame('cancelled', $result->subscriptionInfo->status);
    }

    public function test_grace_period_subscription_returns_valid(): void
    {
        [$verifier, $resource] = $this->makeVerifier();
        $resource->method('get')->willReturn(
            $this->makeSubscriptionResponse('SUBSCRIPTION_STATE_IN_GRACE_PERIOD')
        );

        $result = $verifier->verifySubscription('com.example.premium', 'token_abc');

        $this->assertTrue($result->isValid);
        $this->assertSame('grace_period', $result->subscriptionInfo->status);
    }

    // --- Bug 2: getStartTime() null safety ---

    public function test_null_start_time_does_not_throw(): void
    {
        [$verifier, $resource] = $this->makeVerifier();
        $resource->method('get')->willReturn(
            $this->makeSubscriptionResponse('SUBSCRIPTION_STATE_ACTIVE', startTime: null)
        );

        $result = $verifier->verifySubscription('com.example.premium', 'token_abc');

        $this->assertTrue($result->isValid);
        $this->assertInstanceOf(\DateTimeImmutable::class, $result->subscriptionInfo->startsAt);
    }

    // --- Bug 3: originalTransactionId は purchaseToken を使う ---

    public function test_original_transaction_id_uses_purchase_token(): void
    {
        [$verifier, $resource] = $this->makeVerifier();
        $resource->method('get')->willReturn(
            $this->makeSubscriptionResponse('SUBSCRIPTION_STATE_ACTIVE', latestOrderId: 'GPA.9999-renewal')
        );

        $result = $verifier->verifySubscription('com.example.premium', 'stable_purchase_token');

        $this->assertSame('stable_purchase_token', $result->subscriptionInfo->originalTransactionId);
        $this->assertSame('GPA.9999-renewal', $result->subscriptionInfo->currentTransactionId);
    }
}
