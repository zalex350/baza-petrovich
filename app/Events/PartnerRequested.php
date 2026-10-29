<?php

namespace App\Events;

use App\Models\PartnerRequest;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * С сайта пришла заявка «Стать партнёром» (после коммита записи).
 */
class PartnerRequested implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public PartnerRequest $partnerRequest) {}
}
