<?php

namespace App\Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Delivery\Clients\BiteshipClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DeliveryAreaController extends Controller
{
    public function index(Request $request, BiteshipClient $biteship): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        try {
            $areas = $biteship->areas((string) $request->query('query'));
        } catch (ConnectionException|RuntimeException) {
            return response()->json(['message' => 'Layanan pengiriman sedang tidak tersedia.'], 502);
        }

        return response()->json(['areas' => $areas]);
    }
}
