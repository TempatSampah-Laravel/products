<?php

namespace LaravelEnso\Products\app\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use LaravelEnso\Products\app\Models\Product;
use LaravelEnso\Products\app\Enums\MeasurementUnits;

class ValidateProductRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'manufacturer_id' => 'nullable|integer|exists:companies,id',
            'suppliers' => 'array',
            'suppliers.id' => 'exists:companies,id',
            'defaultSupplierId' => 'nullable|exists:companies,id|required_with:suppliers',
            'name' => 'required|string|max:255',
            'part_number' => 'required|string',
            'internal_code' => 'nullable|string|max:255',
            'measurement_unit' => ['required', 'integer', $this->measurementUnits()],
            'package_quantity' => 'nullable|integer',
            'list_price' => 'required|numeric|min:0.01',
            'vat_percent' => 'required|integer',
            'description' => 'nullable|string',
            'link' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ];
    }

    protected function measurementUnits()
    {
        return Rule::in(MeasurementUnits::keys());
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->productQuery()->exists()) {
                $validator->errors()->add('part_number', 'A product with the specified part number and made by the selected manufacturer already exists!');
                $validator->errors()->add('manufacturer_id', 'A product with the specified part number and made by the selected manufacturer already exists!');
            }

            if (collect($this->get('suppliers'))->isNotEmpty()
                && ! collect($this->get('suppliers'))->pluck('id')->contains($this->get('defaultSupplierId'))) {
                $validator->errors()->add('defaultSupplierId', 'This supplier must be within selected suppliers');
            }

            if ($this->hasInvalidSuppliers()) {
                $validator->errors()->add(
                    'suppliers',
                    __('Part number and acquisition price are mandatory for each supplier')
                );
            }

            if ($this->hasInvalidListPrice()) {
                $validator->errors()->add(
                    'list_price',
                    __('Acquisition price must be smaller that list price')
                );
            }

            if ($this->hasInvalidDefaultSupplier()) {
                $validator->errors()->add(
                    'defaultSupplierId',
                    __('The chosen supplier does not have the minimum price')
                );
            }
        });
    }

    protected function productQuery()
    {
        return Product::where('part_number', $this->get('part_number'))
            ->where('manufacturer_id', $this->get('manufacturer_id'))
            ->where('id', '<>', optional($this->route('product'))->id);
    }

    private function hasInvalidSuppliers()
    {
        return collect($this->get('suppliers'))
            ->filter(function ($supplier) {
                return ! $supplier['pivot']['acquisition_price']
                    || ! is_numeric($supplier['pivot']['acquisition_price'])
                    || ! $supplier['pivot']['part_number'];
            })->isNotEmpty();
    }

    private function hasInvalidListPrice()
    {
        $suppliers = collect($this->get('suppliers'));

        return $suppliers->isNotEmpty() &&
            $suppliers->every(function ($supplier) {
                return $supplier['pivot']['acquisition_price'] > $this->get('list_price');
            });
    }

    private function hasInvalidDefaultSupplier()
    {
        $suppliers = collect($this->get('suppliers'));
        $cheapestSupplier = $suppliers->first(function ($supplier) {
            return $supplier['id'] = $this->get('defaultSupplierId');
        });

        return $suppliers
            ->first(function ($supplier) use ($cheapestSupplier) {
                return
                    $supplier['pivot']['acquisition_price'] < $cheapestSupplier['pivot']['acquisition_price']
                    && $supplier['id'] !== $cheapestSupplier['id'];
            });
    }
}
