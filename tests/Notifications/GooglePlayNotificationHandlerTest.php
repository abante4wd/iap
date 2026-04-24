<?php

namespace Abante4wd\Iap\Tests\Notifications;

use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseStatus;
use Abante4wd\Iap\Notifications\GooglePlayNotificationHandler;
use Abante4wd\Iap\Services\DeferredPurchaseService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GooglePlayNotificationHandlerTest extends TestCase
{
    private DeferredPurchaseService&MockObject $deferredService;

    private GooglePlayNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->deferredService = $this->createMock(DeferredPurchaseService::class);
        $this->handler = new GooglePlayNotificationHandler($this->deferredService);
    }

    public function test_invalid_json_returns_error(): void
    {
        $result = $this->handler->handle('not json');

        $this->assertSame('error', $result['type']);
        $this->assertSame('none', $result['action']);
    }

    public function test_one_time_product_purchased_calls_complete_pending(): void
    {
        $purchase = $this->makePurchaseData();

        $this->deferredService->expects($this->once())
            ->method('completePending')
            ->with(Platform::Google, 'token_abc')
            ->willReturn($purchase);

        $payload = json_encode([
            'oneTimeProductNotification' => [
                'notificationType' => 1,
                'purchaseToken' => 'token_abc',
                'sku' => 'com.example.gems',
            ],
        ]);

        $result = $this->handler->handle($payload);

        $this->assertSame('ONE_TIME_PRODUCT_PURCHASED', $result['type']);
        $this->assertSame('completed', $result['action']);
    }

    public function test_one_time_product_canceled_calls_cancel_pending(): void
    {
        $this->deferredService->expects($this->once())
            ->method('cancelPending')
            ->with(Platform::Google, 'token_abc', 'cancelled_by_store');

        $payload = json_encode([
            'oneTimeProductNotification' => [
                'notificationType' => 2,
                'purchaseToken' => 'token_abc',
                'sku' => 'com.example.gems',
            ],
        ]);

        $result = $this->handler->handle($payload);

        $this->assertSame('ONE_TIME_PRODUCT_CANCELED', $result['type']);
        $this->assertSame('cancelled', $result['action']);
    }

    public function test_subscription_notification_returns_subscription_updated(): void
    {
        $payload = json_encode([
            'subscriptionNotification' => [
                'notificationType' => 4,
                'purchaseToken' => 'sub_token',
                'subscriptionId' => 'com.example.premium',
            ],
        ]);

        $result = $this->handler->handle($payload);

        $this->assertSame('SUBSCRIPTION_NOTIFICATION', $result['type']);
        $this->assertSame('subscription_updated', $result['action']);
    }

    public function test_voided_purchase_calls_cancel_pending(): void
    {
        $this->deferredService->expects($this->once())
            ->method('cancelPending')
            ->with(Platform::Google, 'voided_token', 'voided');

        $payload = json_encode([
            'voidedPurchaseNotification' => [
                'purchaseToken' => 'voided_token',
                'orderId' => 'order_123',
            ],
        ]);

        $result = $this->handler->handle($payload);

        $this->assertSame('VOIDED_PURCHASE', $result['type']);
        $this->assertSame('cancelled', $result['action']);
    }

    public function test_pubsub_wrapped_message_is_decoded(): void
    {
        $innerData = json_encode([
            'oneTimeProductNotification' => [
                'notificationType' => 1,
                'purchaseToken' => 'pubsub_token',
                'sku' => 'com.example.gems',
            ],
        ]);

        $this->deferredService->method('completePending')->willReturn($this->makePurchaseData());

        $payload = json_encode([
            'message' => [
                'data' => base64_encode($innerData),
            ],
        ]);

        $result = $this->handler->handle($payload);

        $this->assertSame('ONE_TIME_PRODUCT_PURCHASED', $result['type']);
    }

    public function test_unknown_notification_returns_ignored(): void
    {
        $payload = json_encode(['unknownField' => 'value']);

        $result = $this->handler->handle($payload);

        $this->assertSame('unknown', $result['type']);
        $this->assertSame('ignored', $result['action']);
    }

    private function makePurchaseData(): PurchaseData
    {
        return new PurchaseData(
            id: 1,
            userId: 1,
            productId: 1,
            platform: Platform::Google,
            storeTransactionId: 'tx_test',
            purchaseToken: 'token123',
            status: PurchaseStatus::Verified,
        );
    }
}
