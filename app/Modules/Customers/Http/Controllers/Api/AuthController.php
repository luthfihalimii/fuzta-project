<?php

namespace App\Modules\Customers\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginCustomerRequest;
use App\Http\Requests\RegisterCustomerRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Modules\Customers\Actions\IssueCustomerToken;
use App\Modules\Customers\Actions\LoginCustomer;
use App\Modules\Customers\Actions\RegisterCustomer;
use App\Modules\Customers\Actions\ResetCustomerPassword;
use App\Modules\Customers\Actions\RevokeCustomerToken;
use App\Modules\Customers\Actions\SendPasswordResetCode;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly RegisterCustomer $registerCustomer,
        private readonly LoginCustomer $loginCustomer,
        private readonly IssueCustomerToken $issueCustomerToken,
        private readonly CustomerPayload $payload,
    ) {}

    /**
     * Daftar akun customer; token mentah hanya muncul sekali di respons.
     */
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $customer = ($this->registerCustomer)(
            (string) $request->input('name'),
            (string) $request->input('email'),
            (string) $request->input('phone'),
            (string) $request->input('password'),
        );

        $issued = ($this->issueCustomerToken)($customer);

        return $this->authResponse($customer, $issued['token'], $issued['expires_at'], 201);
    }

    public function login(LoginCustomerRequest $request): JsonResponse
    {
        $result = ($this->loginCustomer)(
            (string) $request->input('phone'),
            (string) $request->input('password'),
        );

        return $this->authResponse($result['customer'], $result['token'], $result['expires_at'], 200);
    }

    /**
     * Selalu 202 dan pesan sama, baik email terdaftar atau tidak
     * (mencegah enumerasi akun).
     */
    public function forgotPassword(ForgotPasswordRequest $request, SendPasswordResetCode $send): JsonResponse
    {
        $customer = Customer::query()
            ->where('email', (string) $request->validated('email'))
            ->where('is_active', true)
            ->first();

        if ($customer !== null) {
            $send($customer);
        }

        return response()->json([
            'message' => 'Jika email terdaftar, kode reset sudah dikirim.',
        ], 202);
    }

    public function resetPassword(ResetPasswordRequest $request, ResetCustomerPassword $reset): JsonResponse
    {
        $customer = Customer::query()
            ->where('email', (string) $request->validated('email'))
            ->first();

        // Email tak dikenal memakai error yang sama dengan kode salah.
        if ($customer === null) {
            throw ValidationException::withMessages([
                'code' => 'Kode reset tidak valid atau sudah kedaluwarsa.',
            ]);
        }

        $reset(
            $customer,
            (string) $request->validated('code'),
            (string) $request->validated('password'),
        );

        return response()->json([
            'message' => 'Password berhasil diubah. Silakan masuk kembali.',
        ]);
    }

    /**
     * Logout token yang sedang dipakai; token lain untuk customer yang
     * sama tetap valid.
     */
    public function logout(Request $request, RevokeCustomerToken $revoke): Response
    {
        $token = $request->attributes->get('customer_token');

        // Defensif: middleware customer.auth selalu memasang token ini.
        if ($token === null) {
            abort(401, 'Silakan masuk terlebih dahulu.');
        }

        $revoke($token);

        return response()->noContent();
    }

    private function authResponse(Customer $customer, string $token, CarbonImmutable $expiresAt, int $status = 200): JsonResponse
    {
        return response()->json([
            'customer' => ($this->payload)($customer),
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $status);
    }
}
