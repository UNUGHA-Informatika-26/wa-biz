<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListClientsRequest;
use App\Http\Requests\Api\StoreClientRequest;
use App\Http\Requests\Api\UpdateClientRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClientController extends Controller
{
    public function index(ListClientsRequest $request): JsonResponse
    {
        Gate::authorize('access-organization-operations');

        $perPage = (int) $request->validated('per_page', 20);
        $clients = Client::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with('latestWhatsAppConsent:id,client_id,action,recorded_at')
            ->orderBy('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $clients->getCollection()
                ->map(fn (Client $client): array => $this->clientData($client))
                ->values(),
            'meta' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
            ],
        ]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        Gate::authorize('access-organization-operations');

        $client = $request->user()
            ->organization()
            ->firstOrFail()
            ->clients()
            ->create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->clientData($client),
        ], 201);
    }

    public function show(Request $request, int $client): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $client = $this->findForOrganization($request, $client)->load('latestWhatsAppConsent');

        return response()->json([
            'success' => true,
            'data' => $this->clientData($client),
        ]);
    }

    public function update(UpdateClientRequest $request, int $client): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $client = $this->findForOrganization($request, $client);
        $client->update($request->validated());
        $client->refresh();

        return response()->json([
            'success' => true,
            'data' => $this->clientData($client),
        ]);
    }

    public function recordWhatsAppConsent(Request $request, int $client): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $data = $request->validate([
            'action' => ['required', 'in:granted,withdrawn'],
            'source' => ['required', 'string', 'max:100'],
            'evidence_type' => ['required', 'string', 'max:100'],
            'evidence_reference' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $client, $data): void {
            $clientModel = Client::query()
                ->where('organization_id', $request->user()->organization_id)
                ->whereKey($client)
                ->lockForUpdate()
                ->firstOrFail();

            $clientModel->whatsappConsentEvents()->create([
                ...$data,
                'recorded_by' => $request->user()->id,
                'recorded_at' => now(),
            ]);
        });

        $clientModel = $this->findForOrganization($request, $client)->load('latestWhatsAppConsent');

        return response()->json([
            'success' => true,
            'data' => $this->clientData($clientModel),
        ]);
    }

    public function destroy(Request $request, int $client): JsonResponse
    {
        Gate::authorize('manage-organization-operations');

        $canDelete = DB::transaction(function () use ($request, $client): bool {
            $clientModel = Client::query()
                ->where('organization_id', $request->user()->organization_id)
                ->whereKey($client)
                ->lockForUpdate()
                ->firstOrFail();

            if ($clientModel->conversations()->exists()
                || $clientModel->whatsappConsentEvents()->exists()) {
                return false;
            }

            $clientModel->delete();

            return true;
        });

        if (! $canDelete) {
            return response()->json([
                'success' => false,
                'message' => 'Client cannot be deleted because it has related operational or consent history.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'message' => 'Client deleted successfully.',
        ]);
    }

    private function findForOrganization(Request $request, int $clientId): Client
    {
        return Client::query()
            ->where('organization_id', $request->user()->organization_id)
            ->whereKey($clientId)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function clientData(Client $client): array
    {
        $consent = $client->latestWhatsAppConsent;

        return $client->only([
            'id',
            'name',
            'phone_number',
            'email',
            'group',
            'status',
            'notes',
            'created_at',
            'updated_at',
        ]) + [
            'whatsapp_consent_status' => $consent?->action ?? 'unknown',
            'whatsapp_consent_recorded_at' => $consent?->recorded_at,
        ];
    }
}
