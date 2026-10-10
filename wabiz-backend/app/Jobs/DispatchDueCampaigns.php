<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchDueCampaigns implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'campaign-dispatch-due-and-reconcile';
    }

    public function handle(CampaignService $campaigns): void
    {
        $campaigns->reconcileStaleRecipients();

        Campaign::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('id')->chunkById(100, function ($items) use ($campaigns): void {
            foreach ($items as $campaign) {
                $campaigns->dispatchScheduled($campaign);
            }
        });
    }
}
