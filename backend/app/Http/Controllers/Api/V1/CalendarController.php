<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CalendarIndexRequest;
use App\Http\Resources\CalendarEventResource;
use App\Support\Calendar\CalendarBookingQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CalendarController extends Controller
{
    public function __invoke(
        CalendarIndexRequest $request,
        TenantContext $tenant,
        CalendarBookingQuery $calendar,
    ): AnonymousResourceCollection {
        return CalendarEventResource::collection($calendar->get(
            $tenant->organizationId(),
            $request->rangeStart(),
            $request->rangeEnd(),
            $request->statuses(),
            $request->serviceId(),
        ));
    }
}
