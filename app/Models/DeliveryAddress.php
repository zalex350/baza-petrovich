<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Адрес доставки организации (SRS UC-032). У организации не больше одного
 * адреса по умолчанию; пока адреса есть — он ровно один.
 */
class DeliveryAddress extends Model
{
    protected $fillable = [
        'organization_id',
        'title',
        'address',
        'contact_name',
        'contact_phone',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Правила полей формы — общие для админки и кабинета клиента.
     */
    public static function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:255'],
            'address'       => ['required', 'string', 'max:500'],
            'contact_name'  => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'is_default'    => ['nullable', 'boolean'],
        ];
    }

    public static function messages(): array
    {
        return [
            'title.required'    => 'Укажите название адреса',
            'title.max'         => 'Название адреса не длиннее 255 символов',
            'address.required'  => 'Укажите адрес',
            'address.max'       => 'Адрес не длиннее 500 символов',
            'contact_name.max'  => 'Контактное лицо не длиннее 255 символов',
            'contact_phone.max' => 'Телефон не длиннее 50 символов',
        ];
    }

    /**
     * Создаёт адрес организации. Первый адрес становится адресом по умолчанию сам.
     */
    public static function createFor(Organization $organization, array $data): self
    {
        return DB::transaction(function () use ($organization, $data) {
            $isFirst = ! $organization->deliveryAddresses()->exists();

            $address = $organization->deliveryAddresses()->create([
                'title'         => $data['title'],
                'address'       => $data['address'],
                'contact_name'  => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'is_default'    => false,
            ]);

            if ($isFirst || ! empty($data['is_default'])) {
                $address->makeDefault();
            }

            return $address;
        });
    }

    /**
     * Снять «по умолчанию» через форму нельзя — только выбрать другой адрес.
     */
    public function applyChanges(array $data): void
    {
        DB::transaction(function () use ($data) {
            $this->update([
                'title'         => $data['title'],
                'address'       => $data['address'],
                'contact_name'  => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
            ]);

            if (! empty($data['is_default'])) {
                $this->makeDefault();
            }
        });
    }

    public function makeDefault(): void
    {
        DB::transaction(function () {
            static::where('organization_id', $this->organization_id)
                ->where('id', '!=', $this->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->is_default = true;
            $this->save();
        });
    }

    /**
     * Удаляет адрес; если он был по умолчанию — им становится самый старый из оставшихся.
     */
    public function remove(): void
    {
        DB::transaction(function () {
            $this->delete();

            if ($this->is_default) {
                static::where('organization_id', $this->organization_id)->orderBy('id')->first()?->makeDefault();
            }
        });
    }
}
