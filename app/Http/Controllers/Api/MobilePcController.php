<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MobileClientContextRequest;
use App\Http\Requests\Api\MobileOpenQrRequest;
use App\Http\Requests\Api\MobilePartyBookRequest;
use App\Http\Requests\Api\MobilePcBookRequest;
use App\Http\Requests\Api\MobileQuickRebookRequest;
use App\Http\Requests\Api\MobileSmartQueueJoinRequest;
use App\Http\Requests\Api\MobileSmartSeatHoldRequest;
use App\Http\Requests\Api\MobileSmartSeatRequest;
use App\Http\Resources\Mobile\MobilePayloadResource;
use App\Services\MobilePcService;
use App\Services\MobileQueueService;

class MobilePcController extends Controller
{
    public function __construct(
        private readonly MobilePcService $pcs,
        private readonly MobileQueueService $queues,
    ) {
    }

    public function index(MobileClientContextRequest $request)
    {
        return new MobilePayloadResource(
            $this->pcs->catalog($request->tenantId(), $request->clientId())
        );
    }

    public function book(MobilePcBookRequest $request, int $pcId)
    {
        return new MobilePayloadResource(
            $this->pcs->book(
                $request->tenantId(),
                $request->clientId(),
                $pcId,
                $request->startAt(),
                $request->holdMinutes(),
            )
        );
    }

    public function partyBook(MobilePartyBookRequest $request)
    {
        return new MobilePayloadResource(
            $this->pcs->partyBook(
                $request->tenantId(),
                $request->clientId(),
                $request->pcIds(),
                $request->startAt(),
                $request->holdMinutes(),
            )
        );
    }

    public function smartSeat(MobileSmartSeatRequest $request)
    {
        return new MobilePayloadResource(
            $this->pcs->smartSeat(
                $request->tenantId(),
                $request->clientId(),
                $request->zoneKey(),
                $request->arriveIn(),
                $request->limit(),
            )
        );
    }

    public function smartSeatHold(MobileSmartSeatHoldRequest $request)
    {
        return new MobilePayloadResource(
            $this->pcs->smartSeatHold(
                $request->tenantId(),
                $request->clientId(),
                $request->pcId(),
                $request->holdMinutes(),
            )
        );
    }

    public function quickRebook(MobileQuickRebookRequest $request)
    {
        return new MobilePayloadResource(
            $this->pcs->quickRebook(
                $request->tenantId(),
                $request->clientId(),
                $request->startAt(),
                $request->holdMinutes(),
            )
        );
    }

    public function smartQueueIndex(MobileClientContextRequest $request)
    {
        $queue = $this->queues->listForClient($request->tenantId(), $request->clientId());

        return new MobilePayloadResource([
            'items' => $queue['items'] ?? [],
            'notifications' => $queue['notifications'] ?? [],
        ]);
    }

    public function smartQueueJoin(MobileSmartQueueJoinRequest $request)
    {
        return new MobilePayloadResource(
            $this->queues->join(
                $request->tenantId(),
                $request->clientId(),
                $request->zoneKey(),
                $request->notifyOnFree(),
            )
        );
    }

    public function smartQueueCancel(MobileClientContextRequest $request, int $id)
    {
        return new MobilePayloadResource(
            $this->queues->cancel($request->tenantId(), $request->clientId(), $id)
        );
    }

    public function unbook(MobileClientContextRequest $request, int $pcId)
    {
        return new MobilePayloadResource(
            $this->pcs->unbook($request->tenantId(), $request->clientId(), $pcId)
        );
    }

    public function openByQr(MobileOpenQrRequest $request)
    {
        return new MobilePayloadResource(
            $this->pcs->openByQr(
                $request->tenantId(),
                $request->pcId(),
                $request->code(),
            )
        );
    }
}
