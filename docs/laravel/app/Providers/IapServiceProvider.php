<?php

namespace App\Providers;

use App\Services\Iap\EloquentPurchaseRepository;
use App\Services\Iap\EloquentSubscriptionRepository;
use App\Services\Iap\RewardGrantService;
use Abante4wd\Iap\Contracts\PurchaseRepositoryInterface;
use Abante4wd\Iap\Contracts\RewardGrantServiceInterface;
use Abante4wd\Iap\Contracts\SubscriptionRepositoryInterface;
use Abante4wd\Iap\Store\AppleStoreVerifier;
use Abante4wd\Iap\Store\Config\AppleConfig;
use Abante4wd\Iap\Store\Config\GoogleConfig;
use Abante4wd\Iap\Store\GooglePlayVerifier;
use Illuminate\Support\ServiceProvider;

class IapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('iap.php'), 'iap');

        // Config DTO
        $this->app->singleton(GoogleConfig::class, fn () => new GoogleConfig(
            applicationName: config('iap.google.application_name'),
            serviceAccountJsonPath: config('iap.google.service_account_json'),
            packageName: config('iap.google.package_name'),
        ));

        $this->app->singleton(AppleConfig::class, fn () => new AppleConfig(
            bundleId: config('iap.apple.bundle_id'),
            issuerId: config('iap.apple.issuer_id'),
            keyId: config('iap.apple.key_id'),
            privateKeyPath: config('iap.apple.private_key_path'),
            environment: config('iap.apple.environment'),
        ));

        // Verifier
        $this->app->singleton(GooglePlayVerifier::class);
        $this->app->singleton(AppleStoreVerifier::class);

        // Repository & Service
        $this->app->bind(PurchaseRepositoryInterface::class, EloquentPurchaseRepository::class);
        $this->app->bind(SubscriptionRepositoryInterface::class, EloquentSubscriptionRepository::class);
        $this->app->bind(RewardGrantServiceInterface::class, RewardGrantService::class);
    }
}
