<?php

namespace Abante4wd\Iap\Tests\Services;

use Abante4wd\Iap\Contracts\StoreVerifierInterface;
use Abante4wd\Iap\Contracts\SubscriptionRepositoryInterface;
use Abante4wd\Iap\DTO\SubscriptionInfo;
use Abante4wd\Iap\DTO\VerificationResult;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Services\SubscriptionCheckService;
use Abante4wd\Iap\Store\StoreVerifierFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SubscriptionCheckServiceTest extends TestCase
{
    private StoreVerifierFactory&MockObject $verifierFactory;
    private SubscriptionRepositoryInterface&MockObject $subscriptionRepo;
    private StoreVerifierInterface&MockObject $verifier;
    private SubscriptionCheckService $service;

    protected function setUp(): void
    {
        $this->verifierFactory = $this->createMock(StoreVerifierFactory::class);
        $this->subscriptionRepo = $this->createMock(SubscriptionRepositoryInterface::class);
        $this->verifier = $this->createMock(StoreVerifierInterface::class);
        $this->service = new SubscriptionCheckService($this->verifierFactory, $this->subscriptionRepo);
    }

    public function test_check_expiring_calls_refreshSubscriptionStatus_with_original_transaction_id(): void
    {
        $subscription = $this->makeSubscription('orig_tx_123', Platform::Apple);
        $subscriptionInfo = new SubscriptionInfo(
            originalTransactionId: 'orig_tx_123',
            currentTransactionId: 'curr_tx_456',
            startsAt: new \DateTimeImmutable('-1 year'),
            expiresAt: new \DateTimeImmutable('+1 month'),
            autoRenewing: true,
            status: 'active',
        );
        $result = new VerificationResult(
            isValid: true,
            transactionId: 'curr_tx_456',
            productId: 'com.example.myapp.premium',
            rawResponse: [],
            subscriptionInfo: $subscriptionInfo,
        );

        $this->subscriptionRepo->method('findExpiringSubscriptions')->willReturn([$subscription]);
        $this->verifierFactory->method('make')->with(Platform::Apple)->willReturn($this->verifier);
        $this->verifier->expects($this->once())
            ->method('refreshSubscriptionStatus')
            ->with('orig_tx_123', 'com.example.myapp.premium')
            ->willReturn($result);
        $this->subscriptionRepo->expects($this->once())->method('updateFromStoreInfo');

        $stats = $this->service->checkExpiring();

        $this->assertSame(1, $stats['checked']);
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(0, $stats['errors']);
    }

    public function test_check_expiring_counts_error_on_exception(): void
    {
        $subscription = $this->makeSubscription('orig_tx_err', Platform::Apple);

        $this->subscriptionRepo->method('findExpiringSubscriptions')->willReturn([$subscription]);
        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('refreshSubscriptionStatus')
            ->willThrowException(new \RuntimeException('API error'));

        $stats = $this->service->checkExpiring();

        $this->assertSame(1, $stats['errors']);
        $this->assertSame(0, $stats['updated']);
    }

    public function test_check_expiring_skips_update_when_no_subscription_info(): void
    {
        $subscription = $this->makeSubscription('orig_tx_no_info', Platform::Apple);
        $result = new VerificationResult(
            isValid: false,
            transactionId: 'orig_tx_no_info',
            productId: 'prod1',
            rawResponse: [],
            errorMessage: 'Expired',
        );

        $this->subscriptionRepo->method('findExpiringSubscriptions')->willReturn([$subscription]);
        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('refreshSubscriptionStatus')->willReturn($result);
        $this->subscriptionRepo->expects($this->never())->method('updateFromStoreInfo');

        $stats = $this->service->checkExpiring();

        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['errors']);
    }

    private function makeSubscription(string $originalTransactionId, Platform $platform): object
    {
        return new class($originalTransactionId, $platform) {
            public string $storeProductId = 'com.example.myapp.premium';
            public string $id = 'sub_1';

            public function __construct(
                public string $originalTransactionId,
                public Platform $platform,
            ) {}
        };
    }
}
