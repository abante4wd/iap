<?php

namespace Fukazawa\Iap\Tests\Services;

use Fukazawa\Iap\Contracts\PurchaseRepositoryInterface;
use Fukazawa\Iap\Contracts\RewardGrantServiceInterface;
use Fukazawa\Iap\Contracts\StoreVerifierInterface;
use Fukazawa\Iap\DTO\ProductData;
use Fukazawa\Iap\DTO\PurchaseData;
use Fukazawa\Iap\DTO\VerificationResult;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\PurchaseStatus;
use Fukazawa\Iap\Enums\PurchaseType;
use Fukazawa\Iap\Services\DeferredPurchaseService;
use Fukazawa\Iap\Store\StoreVerifierFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DeferredPurchaseServiceTest extends TestCase
{
    private StoreVerifierFactory&MockObject $verifierFactory;

    private StoreVerifierInterface&MockObject $verifier;

    private PurchaseRepositoryInterface&MockObject $purchaseRepo;

    private RewardGrantServiceInterface&MockObject $rewardGrantService;

    private DeferredPurchaseService $service;

    protected function setUp(): void
    {
        $this->verifierFactory = $this->createMock(StoreVerifierFactory::class);
        $this->verifier = $this->createMock(StoreVerifierInterface::class);
        $this->purchaseRepo = $this->createMock(PurchaseRepositoryInterface::class);
        $this->rewardGrantService = $this->createMock(RewardGrantServiceInterface::class);

        $this->service = new DeferredPurchaseService(
            $this->verifierFactory,
            $this->purchaseRepo,
            $this->rewardGrantService,
        );
    }

    public function test_complete_pending_returns_null_when_no_pending_found(): void
    {
        $this->purchaseRepo->method('findPendingByPlatformAndToken')->willReturn(null);

        $result = $this->service->completePending(Platform::Google, 'token123');

        $this->assertNull($result);
    }

    public function test_complete_pending_returns_null_when_product_not_found(): void
    {
        $pending = $this->makePurchaseData(status: PurchaseStatus::Pending);
        $this->purchaseRepo->method('findPendingByPlatformAndToken')->willReturn($pending);
        $this->purchaseRepo->method('findProductByProductId')->willReturn(null);

        $result = $this->service->completePending(Platform::Google, 'token123');

        $this->assertNull($result);
    }

    public function test_complete_pending_returns_null_when_reverify_fails(): void
    {
        $pending = $this->makePurchaseData(status: PurchaseStatus::Pending);
        $product = $this->makeProduct();

        $this->purchaseRepo->method('findPendingByPlatformAndToken')->willReturn($pending);
        $this->purchaseRepo->method('findProductByProductId')->willReturn($product);

        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('verifyProduct')->willReturn(
            new VerificationResult(
                isValid: false,
                transactionId: '',
                productId: 'com.example.gems',
                rawResponse: [],
                errorMessage: 'Still pending',
            )
        );

        $result = $this->service->completePending(Platform::Google, 'token123');

        $this->assertNull($result);
    }

    public function test_complete_pending_success(): void
    {
        $pending = $this->makePurchaseData(status: PurchaseStatus::Pending);
        $product = $this->makeProduct();
        $completedPurchase = $this->makePurchaseData(status: PurchaseStatus::Verified);

        $this->purchaseRepo->method('findPendingByPlatformAndToken')->willReturn($pending);
        $this->purchaseRepo->method('findProductByProductId')->willReturn($product);

        $this->verifierFactory->method('make')->willReturn($this->verifier);
        $this->verifier->method('verifyProduct')->willReturn(
            new VerificationResult(
                isValid: true,
                transactionId: 'tx_completed',
                productId: 'com.example.gems',
                rawResponse: ['valid' => true],
            )
        );

        $this->purchaseRepo->method('transaction')
            ->willReturnCallback(fn (callable $cb) => $cb());

        $this->purchaseRepo->expects($this->once())
            ->method('completePending')
            ->willReturn($completedPurchase);

        $this->rewardGrantService->expects($this->once())
            ->method('grant')
            ->willReturn([]);

        $this->purchaseRepo->expects($this->once())->method('markRewardsGranted');

        $result = $this->service->completePending(Platform::Google, 'token123');

        $this->assertSame($completedPurchase, $result);
    }

    public function test_complete_pending_google_consumable_calls_acknowledge(): void
    {
        $pending = $this->makePurchaseData(status: PurchaseStatus::Pending);
        $product = $this->makeProduct(type: PurchaseType::Consumable);
        $completedPurchase = $this->makePurchaseData(status: PurchaseStatus::Verified);

        $this->purchaseRepo->method('findPendingByPlatformAndToken')->willReturn($pending);
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

        $this->purchaseRepo->method('transaction')
            ->willReturnCallback(fn (callable $cb) => $cb());
        $this->purchaseRepo->method('completePending')->willReturn($completedPurchase);
        $this->rewardGrantService->method('grant')->willReturn([]);

        $this->verifier->expects($this->once())
            ->method('acknowledge')
            ->willReturn(true);

        $this->purchaseRepo->expects($this->once())->method('markAcknowledged');

        $this->service->completePending(Platform::Google, 'token123');
    }

    public function test_cancel_pending_does_nothing_when_no_pending_found(): void
    {
        $this->purchaseRepo->method('findPendingByPlatformAndToken')->willReturn(null);

        // cancelPending が呼ばれないことを検証
        $this->purchaseRepo->expects($this->never())->method('cancelPending');

        $this->service->cancelPending(Platform::Google, 'token123');
    }

    public function test_cancel_pending_success(): void
    {
        $pending = $this->makePurchaseData(status: PurchaseStatus::Pending);
        $this->purchaseRepo->method('findPendingByPlatformAndToken')->willReturn($pending);

        $this->purchaseRepo->expects($this->once())
            ->method('cancelPending')
            ->with($pending->id, 'user_cancelled');

        $this->service->cancelPending(Platform::Google, 'token123', 'user_cancelled');
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
