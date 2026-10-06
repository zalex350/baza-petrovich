<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use App\Models\Organization;

/**
 * Адреса доставки в карточке клиента (организации).
 */
class AdminDeliveryAddressController extends Controller
{
    public function create($organizationId)
    {
        return view('admin.address_form', [
            'organization' => Organization::findOrFail($organizationId),
            'address'      => new DeliveryAddress(),
        ]);
    }

    public function store($organizationId)
    {
        $organization = Organization::findOrFail($organizationId);

        DeliveryAddress::createFor(
            $organization,
            request()->validate(DeliveryAddress::rules(), DeliveryAddress::messages())
        );

        return $this->backToCard($organization, 'Адрес доставки добавлен');
    }

    public function edit($organizationId, $id)
    {
        $organization = Organization::findOrFail($organizationId);

        return view('admin.address_form', [
            'organization' => $organization,
            'address'      => $organization->deliveryAddresses()->findOrFail($id),
        ]);
    }

    public function update($organizationId, $id)
    {
        $organization = Organization::findOrFail($organizationId);

        $organization->deliveryAddresses()->findOrFail($id)->applyChanges(
            request()->validate(DeliveryAddress::rules(), DeliveryAddress::messages())
        );

        return $this->backToCard($organization, 'Адрес доставки сохранён');
    }

    public function makeDefault($organizationId, $id)
    {
        $organization = Organization::findOrFail($organizationId);

        $organization->deliveryAddresses()->findOrFail($id)->makeDefault();

        return $this->backToCard($organization, 'Адрес по умолчанию изменён');
    }

    public function destroy($organizationId, $id)
    {
        $organization = Organization::findOrFail($organizationId);

        $organization->deliveryAddresses()->findOrFail($id)->remove();

        return $this->backToCard($organization, 'Адрес доставки удалён');
    }

    private function backToCard(Organization $organization, string $message)
    {
        return redirect("/admin/organization/{$organization->id}")->with('success', $message);
    }
}
