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

    public function test_invalid_signed_transaction_info_returns_transaction_jws_error(): void
    {
        $handler = new TestableThrowingAppleServerNotificationHandler($this->deferredService);
        $payload = $this->makePayload('ONE_TIME_CHARGE', 'ACCEPTED', 'tx_bad');

        $result = $handler->handle($payload);

        $this->assertSame('error', $result['type']);
        $this->assertStringContainsString('Transaction JWS', $result['details']['error']);
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

    public function test_expired_returns_subscription_expired(): void
    {
        $result = $this->handler->handle($this->makePayload('EXPIRED', 'VOLUNTARY', 'tx_1'));

        $this->assertSame('EXPIRED', $result['type']);
        $this->assertSame('subscription_expired', $result['action']);
        $this->assertSame('VOLUNTARY', $result['details']['subtype']);
    }

    public function test_did_fail_to_renew_returns_billing_failed(): void
    {
        $result = $this->handler->handle($this->makePayload('DID_FAIL_TO_RENEW', '', 'tx_2'));

        $this->assertSame('DID_FAIL_TO_RENEW', $result['type']);
        $this->assertSame('subscription_billing_failed', $result['action']);
    }

    public function test_grace_period_expired_returns_subscription_expired(): void
    {
        $result = $this->handler->handle($this->makePayload('GRACE_PERIOD_EXPIRED', '', 'tx_3'));

        $this->assertSame('GRACE_PERIOD_EXPIRED', $result['type']);
        $this->assertSame('subscription_expired', $result['action']);
    }

    public function test_refund_returns_refunded(): void
    {
        $result = $this->handler->handle($this->makePayload('REFUND', '', 'tx_4', ['revocationReason' => 0]));

        $this->assertSame('REFUND', $result['type']);
        $this->assertSame('refunded', $result['action']);
        $this->assertSame(0, $result['details']['revocationReason']);
    }

    public function test_refund_declined_returns_refund_declined(): void
    {
        $result = $this->handler->handle($this->makePayload('REFUND_DECLINED', '', 'tx_5'));

        $this->assertSame('REFUND_DECLINED', $result['type']);
        $this->assertSame('refund_declined', $result['action']);
    }

    public function test_refund_reversed_returns_refund_reversed(): void
    {
        $result = $this->handler->handle($this->makePayload('REFUND_REVERSED', '', 'tx_6'));

        $this->assertSame('REFUND_REVERSED', $result['type']);
        $this->assertSame('refund_reversed', $result['action']);
    }

    public function test_did_change_renewal_pref_returns_subscription_updated(): void
    {
        $result = $this->handler->handle($this->makePayload('DID_CHANGE_RENEWAL_PREF', 'DOWNGRADE', 'tx_7'));

        $this->assertSame('DID_CHANGE_RENEWAL_PREF', $result['type']);
        $this->assertSame('subscription_updated', $result['action']);
    }

    public function test_offer_redeemed_returns_subscription_updated(): void
    {
        $result = $this->handler->handle($this->makePayload('OFFER_REDEEMED', 'INITIAL_BUY', 'tx_8'));

        $this->assertSame('OFFER_REDEEMED', $result['type']);
        $this->assertSame('subscription_updated', $result['action']);
    }

    public function test_price_increase_returns_subscription_updated(): void
    {
        $result = $this->handler->handle($this->makePayload('PRICE_INCREASE', 'PENDING', 'tx_9'));

        $this->assertSame('PRICE_INCREASE', $result['type']);
        $this->assertSame('subscription_updated', $result['action']);
    }

    public function test_test_notification_returns_test_action(): void
    {
        $result = $this->handler->handle($this->makePayload('TEST', '', ''));

        $this->assertSame('TEST', $result['type']);
        $this->assertSame('test', $result['action']);
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

/**
 * signedPayload の検証は成功するが signedTransactionInfo の検証で失敗するテスト用サブクラス
 */
class TestableThrowingAppleServerNotificationHandler extends AppleServerNotificationHandler
{
    private int $callCount = 0;

    protected function decodeJws(string $jws): array
    {
        $this->callCount++;
        if ($this->callCount === 1) {
            $parts = explode('.', $jws);
            if (count($parts) === 3) {
                return json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
            }
        }
        throw new \RuntimeException('JWS signature verification failed');
    }
}
