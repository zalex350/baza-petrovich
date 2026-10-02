<?php

namespace App\Http\Controllers;

use App\Models\DeliveryAddress;
use App\Models\Organization;

/**
 * Кабинет клиента: адреса доставки своей организации (SRS UC-032).
 */
class DeliveryAddressController extends Controller
{
    public function index()
    {
        $organization = auth()->user()->organization;

        return view('addresses', [
            'organization' => $organization,
            'addresses'    => $organization ? $organization->deliveryAddresses : collect(),
        ]);
    }

    public function create()
    {
        $this->organization();

        return view('address_form', ['address' => new DeliveryAddress()]);
    }

    public function store()
    {
        DeliveryAddress::createFor(
            $this->organization(),
            request()->validate(DeliveryAddress::rules(), DeliveryAddress::messages())
        );

        return redirect('/addresses')->with('success', 'Адрес доставки добавлен');
    }

    public function edit($id)
    {
        return view('address_form', ['address' => $this->ownAddress($id)]);
    }

    public function update($id)
    {
        $this->ownAddress($id)->applyChanges(
            request()->validate(DeliveryAddress::rules(), DeliveryAddress::messages())
        );

        return redirect('/addresses')->with('success', 'Адрес доставки сохранён');
    }

    public function makeDefault($id)
    {
        $this->ownAddress($id)->makeDefault();

        return redirect('/addresses')->with('success', 'Адрес по умолчанию изменён');
    }

    public function destroy($id)
    {
        $this->ownAddress($id)->remove();

        return redirect('/addresses')->with('success', 'Адрес доставки удалён');
    }

    /**
     * Организация текущего пользователя; без неё адресами управлять нельзя.
     */
    private function organization(): Organization
    {
        return auth()->user()->organization ?? abort(403, 'Пользователь не привязан к организации');
    }

    /**
     * Адрес своей организации; чужой — 404, как будто его нет.
     */
    private function ownAddress($id): DeliveryAddress
    {
        return $this->organization()->deliveryAddresses()->findOrFail($id);
    }
}
