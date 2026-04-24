<?php

namespace Abante4wd\Iap\Tests\Notifications;

use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseStatus;
use Abante4wd\Iap\Notifications\AppleServerNotificationHandler;
use Abante4wd\Iap\Services\DeferredPurchaseService;
use Abante4wd\Iap\Store\AppleJwsVerifier;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AppleServerNotificationHandlerTest extends TestCase
{
    private DeferredPurchaseService&MockObject $deferredService;

    private AppleServerNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->deferredService = $this->createMock(DeferredPurchaseService::class);
        $this->handler = new TestableAppleServerNotificationHandler($this->deferredService);
    }

    public function test_invalid_json_returns_error(): void
    {
        $result = $this->handler->handle('not json');

        $this->assertSame('error', $result['type']);
        $this->assertSame('none', $result['action']);
    }

    public function test_no_signed_payload_returns_error(): void
    {
        $result = $this->handler->handle(json_encode(['foo' => 'bar']));

        $this->assertSame('error', $result['type']);
        $this->assertStringContainsString('signedPayload', $result['details']['error']);
    }

    public function test_one_time_charge_accepted_calls_complete_pending(): void
    {
        $purchase = $this->makePurchaseData();

        $this->deferredService->expects($this->once())
            ->method('completePending')
            ->with(Platform::Apple, 'tx_12345')
            ->willReturn($purchase);

        $payload = $this->makePayload('ONE_TIME_CHARGE', 'ACCEPTED', 'tx_12345');

        $result = $this->handler->handle($payload);

        $this->assertSame('ONE_TIME_CHARGE', $result['type']);
        $this->assertSame('completed', $result['action']);
    }

    public function test_one_time_charge_declined_calls_cancel_pending(): void
    {
        $this->deferredService->expects($this->once())
            ->method('cancelPending')
            ->with(Platform::Apple, 'tx_12345', 'declined_by_parent');

        $payload = $this->makePayload('ONE_TIME_CHARGE', 'DECLINED', 'tx_12345');

        $result = $this->handler->handle($payload);

        $this->assertSame('ONE_TIME_CHARGE', $result['type']);
        $this->assertSame('cancelled', $result['action']);
    }

    public function test_revoke_calls_cancel_pending(): void
    {
        $this->deferredService->expects($this->once())
            ->method('cancelPending')
            ->with(Platform::Apple, 'tx_revoke', 'revoked');

        $payload = $this->makePayload('REVOKE', '', 'tx_revoke', ['revocationReason' => 1]);

        $result = $this->handler->handle($payload);

        $this->assertSame('REVOKE', $result['type']);
        $this->assertSame('cancelled', $result['action']);
    }

    public function test_subscription_events_return_subscription_updated(): void
    {
        $subscriptionTypes = ['DID_CHANGE_RENEWAL_STATUS', 'SUBSCRIBED', 'DID_RENEW'];

        foreach ($subscriptionTypes as $type) {
            $payload = $this->makePayload($type, 'AUTO_RENEW_ENABLED', 'tx_sub');
            $result = $this->handler->handle($payload);

            $this->assertSame($type, $result['type']);
            $this->assertSame('subscription_updated', $result['action']);
        }
    }

    public function test_unknown_notification_type_returns_ignored(): void
    {
        $payload = $this->makePayload('SOME_UNKNOWN_TYPE', '', 'tx_unknown');

        $result = $this->handler->handle($payload);

        $this->assertSame('SOME_UNKNOWN_TYPE', $result['type']);
        $this->assertSame('ignored', $result['action']);
    }

    public function test_real_handler_rejects_invalid_jws_in_signed_payload(): void
    {
        $jwsVerifier = new AppleJwsVerifier();
        $handler = new AppleServerNotificationHandler($this->deferredService, $jwsVerifier);

        // フォーマット不正なフェイクJWSは本物の署名検証で必ず失敗する
        $payload = json_encode(['signedPayload' => 'invalid.jws.token']);

        $result = $handler->handle($payload);

        $this->assertSame('error', $result['type']);
        $this->assertStringContainsString('JWS', $result['details']['error']);
    }

    // --- ヘルパーメソッド ---

    /**
     * テスト用のJWSペイロードを生成する
     */
    private function makePayload(
        string $notificationType,
        string $subtype,
        string $transactionId,
        array $extraTransactionInfo = [],
    ): string {
        $transactionInfo = array_merge(
            ['transactionId' => $transactionId, 'productId' => 'com.example.gems'],
            $extraTransactionInfo,
        );

        $notificationBody = [
            'notificationType' => $notificationType,
            'subtype' => $subtype,
            'data' => [
                'signedTransactionInfo' => $this->makeFakeJws($transactionInfo),
            ],
        ];

        $signedPayload = $this->makeFakeJws($notificationBody);

        return json_encode(['signedPayload' => $signedPayload]);
    }

    /**
     * テスト用のフェイクJWS（header.payload.signature）を生成する
     */
    private function makeFakeJws(array $payload): string
    {
        $header = base64_encode(json_encode(['alg' => 'ES256']));
        $body = base64_encode(json_encode($payload));
        $signature = base64_encode('fake_signature');

        return "{$header}.{$body}.{$signature}";
    }

    private function makePurchaseData(): PurchaseData
    {
        return new PurchaseData(
            id: 1,
            userId: 1,
            productId: 1,
            platform: Platform::Apple,
            storeTransactionId: 'tx_test',
            purchaseToken: 'token123',
            status: PurchaseStatus::Verified,
        );
    }
}

/**
 * JWS 署名検証をバイパスするテスト用サブクラス
 */
class TestableAppleServerNotificationHandler extends AppleServerNotificationHandler
{
    protected function decodeJws(string $jws): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return [];
        }
        return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
    }
}
