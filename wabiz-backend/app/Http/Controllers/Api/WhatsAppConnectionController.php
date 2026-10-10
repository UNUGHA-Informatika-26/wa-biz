<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppProviderException;
use App\Services\WhatsApp\WhatsAppProviderRegistry;
use App\Services\WhatsApp\WppConnectGateway;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WhatsAppConnectionController extends Controller
{
    public function verify(Request $request, int $connection, WhatsAppProviderRegistry $providers): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $row = $this->find($request, $connection);

        try {
            $provider = $providers->forConnection($row, (int) $request->user()->organization_id);
            $provider->verifyConnection($row);
            $row->forceFill(['status' => 'connected', 'last_connected_at' => now()])->save();
        } catch (WhatsAppProviderException $exception) {
            $row->forceFill(['status' => 'error'])->save();

            return response()->json([
                'success' => false,
                'message' => 'WhatsApp connection verification failed.',
                'code' => $exception->failureKind,
            ], $this->verificationHttpStatus($exception));
        } catch (\Throwable) {
            $row->forceFill(['status' => 'error'])->save();

            return response()->json([
                'success' => false,
                'message' => 'WhatsApp connection verification failed.',
                'code' => 'verification_unavailable',
            ], 502);
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $row->id,
            'provider' => $row->provider,
            'status' => $row->status,
            'verified_at' => $row->last_connected_at,
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $rows = WhatsAppConnection::query()
            ->where('organization_id', $request->user()->organization_id)
            ->orderByDesc('is_primary')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => $rows->getCollection()->map(fn (WhatsAppConnection $row) => $this->data($row))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $data = $this->validateConnection($request);
        $orgId = $request->user()->organization_id;

        try {
            $row = DB::transaction(function () use ($request, $orgId, $data): WhatsAppConnection {
                $request->user()->organization()->lockForUpdate()->firstOrFail();
                if ($data['is_primary'] ?? false) {
                    WhatsAppConnection::query()->where('organization_id', $orgId)->update(['is_primary' => false]);
                }

                return $request->user()->organization()->firstOrFail()->whatsappConnections()->create([
                    ...$data,
                    'status' => 'disconnected',
                    'last_connected_at' => null,
                    'last_webhook_at' => null,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return $this->duplicatePhoneId();
            }
            throw $exception;
        }

        return response()->json(['success' => true, 'data' => $this->data($row)], 201);
    }

    public function show(Request $request, int $connection): JsonResponse
    {
        Gate::authorize('access-organization-operations');

        return response()->json(['success' => true, 'data' => $this->data($this->find($request, $connection))]);
    }

    public function status(Request $request, int $connection, WppConnectGateway $gateway): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $row = $this->find($request, $connection);
        if ($row->provider === 'wppconnect' && $row->gateway_session_ref) {
            try {
                $live = $gateway->refreshStatus($row);
                $row->refresh();
            } catch (WhatsAppProviderException $exception) {
                return response()->json(['success' => false, 'message' => 'WhatsApp gateway status unavailable.', 'code' => $exception->failureKind], 502);
            }
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $row->id, 'status' => $row->status, 'enabled' => $row->enabled,
            'last_connected_at' => $row->last_connected_at, 'last_webhook_at' => $row->last_webhook_at,
            'phone_number' => $row->phone_number ?: null,
        ]]);
    }

    public function start(Request $request, int $connection, WppConnectGateway $gateway): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $row = $this->find($request, $connection);
        try {
            $live = $gateway->start($row);
            $row->refresh();
        } catch (WhatsAppProviderException $exception) {
            $row->forceFill(['status' => 'error'])->save();

            return response()->json(['success' => false, 'message' => 'WhatsApp gateway could not start the connection.', 'code' => $exception->failureKind], 502);
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $row->id,
            'status' => $row->status,
            'enabled' => $row->enabled,
            'phone_number' => $live['phone_number'],
        ]], 202)->header('Cache-Control', 'no-store');
    }

    public function qr(Request $request, int $connection, WppConnectGateway $gateway): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $row = $this->find($request, $connection);
        try {
            $qr = $gateway->qr($row);
        } catch (WhatsAppProviderException $exception) {
            return response()->json(['success' => false, 'message' => 'WhatsApp QR is not available.', 'code' => $exception->failureKind], 502)->header('Cache-Control', 'no-store');
        }

        return response()->json(['success' => true, 'data' => $qr])->header('Cache-Control', 'no-store');
    }

    public function disconnect(Request $request, int $connection, WppConnectGateway $gateway): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $row = $this->find($request, $connection);
        try {
            $gateway->disconnect($row);
        } catch (WhatsAppProviderException $exception) {
            return response()->json(['success' => false, 'message' => 'WhatsApp gateway could not disconnect the session.', 'code' => $exception->failureKind], 502);
        }

        return response()->json(['success' => true, 'data' => ['id' => $row->id, 'status' => 'disconnected']]);
    }

    public function update(Request $request, int $connection): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $data = $this->validateConnection($request, $connection, true);
        $orgId = $request->user()->organization_id;

        try {
            $row = DB::transaction(function () use ($request, $connection, $orgId, $data): WhatsAppConnection {
                $request->user()->organization()->lockForUpdate()->firstOrFail();
                $row = $this->find($request, $connection, true);
                $identityChanged = collect(['provider', 'phone_number', 'phone_number_id', 'business_account_id', 'credentials'])
                    ->contains(fn (string $key): bool => array_key_exists($key, $data) && $data[$key] !== $row->{$key});
                if (array_key_exists('provider', $data) && $data['provider'] !== $row->provider
                    && ! array_key_exists('credentials', $data)) {
                    $data['credentials'] = null;
                }
                if (($data['is_primary'] ?? false) === true) {
                    WhatsAppConnection::query()->where('organization_id', $orgId)->whereKeyNot($row->id)->update(['is_primary' => false]);
                }
                unset($data['status'], $data['last_connected_at'], $data['last_webhook_at']);
                if ($identityChanged) {
                    $data['status'] = 'disconnected';
                    $data['last_connected_at'] = null;
                }
                $row->fill($data)->save();

                return $row->refresh();
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return $this->duplicatePhoneId();
            }
            throw $exception;
        }

        return response()->json(['success' => true, 'data' => $this->data($row)]);
    }

    public function destroy(Request $request, int $connection): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        DB::transaction(function () use ($request, $connection): void {
            $this->find($request, $connection, true)->delete();
        });

        return response()->json(['success' => true, 'message' => 'WhatsApp connection deleted successfully.']);
    }

    private function validateConnection(Request $request, ?int $connection = null, bool $partial = false): array
    {
        $provider = $request->input('provider', $connection
            ? WhatsAppConnection::query()->whereKey($connection)->value('provider')
            : null);
        $credentialsSupplied = $request->exists('credentials');
        $uniquePhoneId = Rule::unique('whatsapp_connections', 'phone_number_id')
            ->where(fn ($query) => $query->where('provider', $request->input('provider', WhatsAppConnection::query()->whereKey($connection)->value('provider'))))
            ->ignore($connection);
        $rules = [
            'provider' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]{0,99}$/'],
            'display_name' => ['required', 'string', 'max:255'],
            'phone_number' => [$provider === 'wppconnect' ? 'nullable' : 'required', 'string', 'max:40'],
            'phone_number_id' => ['nullable', 'string', 'max:255', $uniquePhoneId],
            'business_account_id' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'is_primary' => ['sometimes', 'boolean'],
            'credentials' => ['sometimes', 'array'],
            'credentials.access_token' => [Rule::requiredIf(fn (): bool => $credentialsSupplied && $provider === 'meta_cloud'), 'string', 'min:1', 'max:4096'],
            'credentials.webhook_secret' => [Rule::requiredIf(fn (): bool => $credentialsSupplied && $provider !== 'meta_cloud'), 'string', 'min:32', 'max:4096'],
            'credentials.webhook_verify_token' => [Rule::requiredIf(fn (): bool => $credentialsSupplied && $provider !== 'meta_cloud'), 'string', 'min:1', 'max:4096'],
        ];
        if ($partial) {
            foreach (['provider', 'display_name', 'phone_number'] as $field) {
                $rules[$field][0] = 'sometimes';
            }
        }
        $data = $request->validate($rules);
        if ($provider === 'wppconnect' && empty($data['phone_number'])) {
            $data['phone_number'] = '';
        }
        unset($data['credentials.webhook_secret'], $data['credentials.webhook_verify_token']);
        if (array_key_exists('credentials', $request->all())) {
            $data['credentials'] = $request->input('credentials');
        }

        return $data;
    }

    private function find(Request $request, int $id, bool $lock = false): WhatsAppConnection
    {
        $query = WhatsAppConnection::query()->where('organization_id', $request->user()->organization_id)->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function data(WhatsAppConnection $row): array
    {
        return $row->only(['id', 'provider', 'display_name', 'phone_number', 'phone_number_id', 'business_account_id', 'enabled', 'status', 'is_primary', 'last_connected_at', 'last_webhook_at', 'created_at', 'updated_at']);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? (string) $exception->getCode()) === '23505';
    }

    private function duplicatePhoneId(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'This provider phone number ID is already registered.', 'errors' => ['phone_number_id' => ['The phone number ID has already been taken for this provider.']]], 422);
    }

    private function verificationHttpStatus(WhatsAppProviderException $exception): int
    {
        return match ($exception->failureKind) {
            'authentication_error' => 401,
            'permission_denied' => 403,
            'phone_number_not_found', 'not_found' => 404,
            'rate_limited' => 429,
            'provider_unavailable', 'transport_error' => 503,
            'malformed_provider_response', 'unexpected_provider_response' => 502,
            default => 422,
        };
    }
}
