<?php

namespace App\Http\Controllers;

use Fukazawa\Iap\Notifications\AppleServerNotificationHandler;
use Fukazawa\Iap\Notifications\GooglePlayNotificationHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private AppleServerNotificationHandler $appleHandler,
        private GooglePlayNotificationHandler $googleHandler,
    ) {}

    /**
     * Apple App Store Server Notifications V2 を受信するエンドポイント
     */
    public function apple(Request $request): JsonResponse
    {
        $result = $this->appleHandler->handle($request->getContent());

        return response()->json($result);
    }

    /**
     * Google Play Real-time Developer Notifications を受信するエンドポイント
     */
    public function google(Request $request): JsonResponse
    {
        $result = $this->googleHandler->handle($request->getContent());

        return response()->json($result);
    }
}
