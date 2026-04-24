<?php

namespace Abante4wd\Iap\Tests\Services;

use Abante4wd\Iap\Contracts\PurchaseRepositoryInterface;
use Abante4wd\Iap\Contracts\RewardGrantServiceInterface;
use Abante4wd\Iap\Contracts\StoreVerifierInterface;
use Abante4wd\Iap\Contracts\SubscriptionRepositoryInterface;
use Abante4wd\Iap\DTO\ProductData;
use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\DTO\SubscriptionInfo;
use Abante4wd\Iap\DTO\VerificationResult;
use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseStatus;
use Abante4wd\Iap\Enums\PurchaseType;
use Abante4wd\Iap\Exceptions\PurchaseAlreadyVerifiedException;
use Abante4wd\Iap\Exceptions\StoreVerificationFailedException;
use Abante4wd\Iap\Services\PurchaseVerificationService;
use Abante4wd\Iap\Store\StoreVerifierFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PurchaseVerificationServiceTest extends TestCase
{
    private StoreVerifierFactory&MockObject $verifierFactory;

    private StoreVerifierInterface&MockObject $verifier;

    private PurchaseRepositoryInterface&MockObject $purchaseRepo;

    private RewardGrantServiceInterface&MockObject $rewardGrantService;

    private SubscriptionRepositoryInterface&MockObject $subscriptionRepo;

    private PurchaseVerificationService $service;

    protected function setUp(): void
    {
        $this->verifierFactory = $this->createMock(StoreVerifierFactory::class);
        $this->verifier = $this->createMock(StoreVerifierInterface::class);
        $this->purchaseRepo = $this->createMock(PurchaseRepositoryInterface::class);
        $this->rewardGrantService = $this->createMock(RewardGrantServiceInterface::class);
        $this->subscriptionRepo = $this->createMock(SubscriptionRepositoryInterface::class);

        $this->service = new PurchaseVerificationService(
            $this->verifierFactory,
            $this->rewardGrantService,
            $this->purchaseRepo,
            $this->subscriptionRepo,
        );
    }

    public function test_product_not_found_throws_exception(): void
    {
        $this->purchaseRepo->method('findProductByProductId')->willReturn(null);

        $this->expectException(StoreVerificationFailedException::class);
        $this->expectExceptionMessage('Product not found: unknown_product');

        $this->service->verify(1, Platform::Google, 'unknown_product', 'token123');
    }

    public function test_store_verification_fails_creates_failed_record(): void
    {
        $product = $this->makeProduct();
        $this->purchaseRepo->method('findProductByProductId')->willReturn($product);

        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('verifyProduct')->willReturn(
            new VerificationResult(
                isValid: false,
                transactionId: 'tx_fail',
                productId: 'com.example.gems',
                rawResponse: ['error' => 'invalid'],
                errorMessage: 'Verification failed',
            )
        );

        $failedPurchase = $this->makePurchaseData(status: PurchaseStatus::Failed);
        $this->purchaseRepo->expects($this->once())
            ->method('createFailed')
            ->willReturn($failedPurchase);

        $this->expectException(StoreVerificationFailedException::class);
        $this->expectExceptionMessage('Verification failed');

        $this->service->verify(1, Platform::Google, 'com.example.gems', 'token123');
    }

    public function test_pending_purchase_returns_pending_status(): void
    {
        $product = $this->makeProduct();
        $this->purchaseRepo->method('findProductByProductId')->willReturn($product);

        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('verifyProduct')->willReturn(
            new VerificationResult(
                isValid: false,
                transactionId: '',
                productId: 'com.example.gems',
                rawResponse: ['state' => 'pending'],
                isPending: true,
                pendingReason: PendingReason::PendingPayment,
            )
        );

        $pendingPurchase = $this->makePurchaseData(status: PurchaseStatus::Pending);
        $this->purchaseRepo->expects($this->once())
            ->method('createOrUpdatePending')
            ->willReturn($pendingPurchase);

        $result = $this->service->verify(1, Platform::Google, 'com.example.gems', 'token123');

        $this->assertSame('pending', $result['status']);
        $this->assertSame([], $result['rewards']);
        $this->assertSame($pendingPurchase, $result['purchase']);
    }

    public function test_valid_purchase_creates_verified_record_and_grants_rewards(): void
    {
        $product = $this->makeProduct();
        $this->purchaseRepo->method('findProductByProductId')->willReturn($product);

        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('verifyProduct')->willReturn(
            new VerificationResult(
                isValid: true,
                transactionId: 'tx_valid',
                productId: 'com.example.gems',
                rawResponse: ['valid' => true],
            )
        );

        $verifiedPurchase = $this->makePurchaseData(status: PurchaseStatus::Verified);

        // transaction() はコールバックをそのまま実行する
        $this->purchaseRepo->method('transaction')
            ->willReturnCallback(fn (callable $cb) => $cb());

        $this->purchaseRepo->method('findVerifiedByPlatformAndTransactionId')->willReturn(null);
        $this->purchaseRepo->method('createOrUpdateVerified')->willReturn($verifiedPurchase);

        $rewards = [['type' => 'gems', 'identifier' => null, 'quantity' => 100]];
        $this->rewardGrantService->method('grant')->willReturn($rewards);

        $this->purchaseRepo->expects($this->once())->method('markRewardsGranted');

        $result = $this->service->verify(1, Platform::Google, 'com.example.gems', 'token123');

        $this->assertSame($verifiedPurchase, $result['purchase']);
        $this->assertSame($rewards, $result['rewards']);
    }

    public function test_duplicate_verified_purchase_throws_exception(): void
    {
        $product = $this->makeProduct();
        $this->purchaseRepo->method('findProductByProductId')->willReturn($product);

        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('verifyProduct')->willReturn(
            new VerificationResult(
                isValid: true,
                transactionId: 'tx_dup',
                productId: 'com.example.gems',
                rawResponse: [],
            )
        );

        $existingPurchase = $this->makePurchaseData(status: PurchaseStatus::Verified);

        $this->purchaseRepo->method('transaction')
            ->willReturnCallback(fn (callable $cb) => $cb());

        $this->purchaseRepo->method('findVerifiedByPlatformAndTransactionId')
            ->willReturn($existingPurchase);

        $this->expectException(PurchaseAlreadyVerifiedException::class);

        $this->service->verify(1, Platform::Google, 'com.example.gems', 'token123');
    }

    public function test_google_consumable_calls_acknowledge(): void
    {
        $product = $this->makeProduct(type: PurchaseType::Consumable);
        $this->purchaseRepo->method('findProductByProductId')->willReturn($product);

        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('verifyProduct')->willReturn(
            new VerificationResult(
                isValid: true,
                transactionId: 'tx_ack',
                productId: 'com.example.gems',
                rawResponse: [],
            )
        );

        $verifiedPurchase = $this->makePurchaseData(status: PurchaseStatus::Verified);

        $this->purchaseRepo->method('transaction')
            ->willReturnCallback(fn (callable $cb) => $cb());
        $this->purchaseRepo->method('findVerifiedByPlatformAndTransactionId')->willReturn(null);
        $this->purchaseRepo->method('createOrUpdateVerified')->willReturn($verifiedPurchase);
        $this->rewardGrantService->method('grant')->willReturn([]);

        $this->verifier->expects($this->once())
            ->method('acknowledge')
            ->with('com.example.gems', 'token123')
            ->willReturn(true);

        $this->purchaseRepo->expects($this->once())->method('markAcknowledged');

        $this->service->verify(1, Platform::Google, 'com.example.gems', 'token123');
    }

    public function test_subscription_purchase_calls_subscription_repo_upsert(): void
    {
        $product = $this->makeProduct(type: PurchaseType::Subscription);
        $this->purchaseRepo->method('findProductByProductId')->willReturn($product);

        $this->verifierFactory->method('make')->willReturn($this->verifier);

        $subscriptionInfo = new SubscriptionInfo(
            originalTransactionId: 'tx_sub',
            currentTransactionId: 'tx_sub',
            startsAt: new \DateTimeImmutable('2024-01-01'),
            expiresAt: new \DateTimeImmutable('2025-01-01'),
            autoRenewing: true,
            status: 'active',
        );

        $this->verifier->method('verifySubscription')->willReturn(
            new VerificationResult(
                isValid: true,
                transactionId: 'tx_sub',
                productId: 'com.example.premium',
                rawResponse: [],
                subscriptionInfo: $subscriptionInfo,
            )
        );

        $verifiedPurchase = $this->makePurchaseData(status: PurchaseStatus::Verified);

        $this->purchaseRepo->method('transaction')
            ->willReturnCallback(fn (callable $cb) => $cb());
        $this->purchaseRepo->method('findVerifiedByPlatformAndTransactionId')->willReturn(null);
        $this->purchaseRepo->method('createOrUpdateVerified')->willReturn($verifiedPurchase);
        $this->rewardGrantService->method('grant')->willReturn([]);

        $this->subscriptionRepo->expects($this->once())
            ->method('upsert')
            ->with(1, $product->id, Platform::Google, $subscriptionInfo);

        $this->service->verify(1, Platform::Google, 'com.example.gems', 'token123');
    }

    // --- ヘルパーメソッド ---

    private function makeProduct(PurchaseType $type = PurchaseType::Consumable): ProductData
    {
        return new ProductData(
            id: 1,
            productId: 'com.example.gems',
            name: 'Gems Pack',
            type: $type,
            googleProductId: 'com.example.gems',
            appleProductId: 'com.example.gems',
        );
    }

    private function makePurchaseData(PurchaseStatus $status = PurchaseStatus::Verified): PurchaseData
    {
        return new PurchaseData(
            id: 1,
            userId: 1,
            productId: 1,
            platform: Platform::Google,
            storeTransactionId: 'tx_test',
            purchaseToken: 'token123',
            status: $status,
        );
    }
}
