<?php

namespace App\Events;

use App\Models\Lead;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * С сайта пришла заявка (Lead) — после коммита записи.
 */
class LeadCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public Lead $lead) {}
}
