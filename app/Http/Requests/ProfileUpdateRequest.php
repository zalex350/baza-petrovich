<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Профиль (SRS §8.12, BR-116): пользователь меняет только свои ФИО и телефон.
 * Email (логин), роль и организация здесь не принимаются — их ведёт администратор.
 */
class ProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[\d\s()+\-]*$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите ФИО',
            'phone.regex' => 'Телефон — цифры, пробелы, скобки, «+» и «-»',
            'phone.max' => 'Телефон — не длиннее 50 символов',
        ];
    }
}
