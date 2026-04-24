<?php

namespace App\Http\Controllers;

use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Exceptions\PurchaseAlreadyVerifiedException;
use Abante4wd\Iap\Exceptions\StoreVerificationFailedException;
use Abante4wd\Iap\Services\PurchaseVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function __construct(
        private PurchaseVerificationService $verificationService,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'platform' => ['required', 'string', 'in:google,apple'],
            'product_id' => ['required', 'string'],
            'purchase_token' => ['required', 'string'],
            'receipt_data' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->verificationService->verify(
                userId: $request->user()->id,
                platform: Platform::from($request->input('platform')),
                productId: $request->input('product_id'),
                purchaseToken: $request->input('purchase_token'),
                receiptData: $request->input('receipt_data'),
            );

            return response()->json([
                'success' => true,
                'purchase_id' => $result['purchase']->id,
                'rewards' => $result['rewards'],
            ]);
        } catch (PurchaseAlreadyVerifiedException $e) {
            return response()->json([
                'success' => false,
                'error' => 'already_verified',
                'message' => $e->getMessage(),
            ], 409);
        } catch (StoreVerificationFailedException $e) {
            return response()->json([
                'success' => false,
                'error' => 'verification_failed',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
