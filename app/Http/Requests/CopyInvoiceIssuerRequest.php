<?php

namespace App\Http\Requests;

use App\Models\InvoiceIssuer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * „Rechnungssteller in andere Gruppe kopieren“ (Weboberfläche und API): Ziel ist
 * eine andere eigene Gruppe des Benutzers.
 */
class CopyInvoiceIssuerRequest extends FormRequest
{
    public const FIELD_GROUP = 'user_group_id';

    public function authorize(): bool
    {
        return $this->user()->can('view', $this->issuer());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            self::FIELD_GROUP => [
                'required', 'integer',
                Rule::in($this->user()->groups->pluck('id')),
                Rule::notIn([$this->issuer()->user_group_id]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            self::FIELD_GROUP.'.in' => __('Wähle eine Gruppe, in der du Mitglied bist.'),
            self::FIELD_GROUP.'.not_in' => __('Der Rechnungssteller gehört bereits zu dieser Gruppe.'),
        ];
    }

    public function targetGroupId(): int
    {
        return (int) $this->validated(self::FIELD_GROUP);
    }

    private function issuer(): InvoiceIssuer
    {
        return $this->route('invoiceIssuer');
    }
}
