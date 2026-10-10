<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $query = Campaign::query()->where('organization_id', $request->user()->organization_id)->with('connection:id,display_name,phone_number,status')->withCount('recipients')->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['data' => $query->paginate(min(max($request->integer('per_page', 20), 1), 100))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $data = $request->validate([
            'whatsapp_connection_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:4096'], 'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $campaign = $this->campaigns->create((int) $request->user()->organization_id, (int) $request->user()->id, $data);

        return response()->json(['data' => $campaign], 201);
    }

    public function show(Request $request, int $campaign): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $record = $this->scoped($request, $campaign)
            ->load('connection:id,display_name,phone_number,status')
            ->loadCount('recipients');

        return response()->json(['data' => $record]);
    }

    public function update(Request $request, int $campaign): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $record = $this->scoped($request, $campaign);
        $data = $request->validate([
            'whatsapp_connection_id' => ['sometimes', 'required', 'integer'], 'name' => ['sometimes', 'required', 'string', 'max:255'],
            'message' => ['sometimes', 'required', 'string', 'max:4096'], 'client_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'client_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        return response()->json(['data' => $this->campaigns->update($record, $data)]);
    }

    public function destroy(Request $request, int $campaign): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $this->campaigns->deleteDraft($this->scoped($request, $campaign));

        return response()->json(['success' => true]);
    }

    public function send(Request $request, int $campaign): JsonResponse
    {
        Gate::authorize('manage-organization-operations');

        return response()->json(['data' => $this->campaigns->sendNow($this->scoped($request, $campaign)->load('recipients'))], 202);
    }

    public function schedule(Request $request, int $campaign): JsonResponse
    {
        Gate::authorize('manage-organization-operations');
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now'], 'timezone' => ['sometimes', 'timezone']]);
        $when = new \DateTimeImmutable($data['scheduled_at']);

        return response()->json(['data' => $this->campaigns->schedule($this->scoped($request, $campaign), $when)], 202);
    }

    public function cancel(Request $request, int $campaign): JsonResponse
    {
        Gate::authorize('manage-organization-operations');

        return response()->json(['data' => $this->campaigns->cancelScheduled($this->scoped($request, $campaign))]);
    }

    public function recipients(Request $request, int $campaign): JsonResponse
    {
        Gate::authorize('access-organization-operations');
        $record = $this->scoped($request, $campaign);

        return response()->json(['data' => $record->recipients()->with('client:id,name')->orderBy('id')->paginate(min(max($request->integer('per_page', 50), 1), 100))]);
    }

    private function scoped(Request $request, int $id): Campaign
    {
        return Campaign::query()->where('organization_id', $request->user()->organization_id)->findOrFail($id);
    }
}
