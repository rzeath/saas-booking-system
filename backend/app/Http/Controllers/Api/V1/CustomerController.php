<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterDataIndexRequest;
use App\Http\Requests\SaveCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(
        MasterDataIndexRequest $request,
        TenantContext $tenant,
    ): AnonymousResourceCollection {
        $validated = $request->validated();
        $search = $validated['search'] ?? null;
        $status = $validated['status'];

        $customers = Customer::query()
            ->where('organization_id', $tenant->organizationId())
            ->when($status !== 'all', fn ($query) => $query->where('is_active', $status === 'active'))
            ->when($search, fn ($query, string $term) => $query->where(function ($query) use ($term): void {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            }))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function store(
        SaveCustomerRequest $request,
        TenantContext $tenant,
    ): CustomerResource {
        $customer = $tenant->organization()->customers()->create($request->validated());

        return new CustomerResource($customer);
    }

    public function show(int $customer, TenantContext $tenant): CustomerResource
    {
        return new CustomerResource($this->resolve($customer, $tenant));
    }

    public function update(
        SaveCustomerRequest $request,
        int $customer,
        TenantContext $tenant,
    ): CustomerResource {
        $resolvedCustomer = $this->resolve($customer, $tenant);
        $resolvedCustomer->update($request->validated());

        return new CustomerResource($resolvedCustomer->refresh());
    }

    private function resolve(int $customer, TenantContext $tenant): Customer
    {
        return Customer::query()
            ->where('organization_id', $tenant->organizationId())
            ->whereKey($customer)
            ->firstOrFail();
    }
}
