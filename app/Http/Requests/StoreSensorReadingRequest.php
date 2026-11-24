<?php

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreSensorReadingRequest extends FormRequest
{
    /**
     * Authorization is handled by AuthenticateDeviceToken middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the sensor payload.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:50'],
            'voltage' => ['required', 'numeric', 'min:0', 'max:100'],
            'current' => ['required', 'numeric', 'min:0', 'max:50000'],
            'power' => ['required', 'numeric', 'min:0', 'max:5000'],
            'temperature' => ['required', 'numeric', 'min:-40', 'max:125'],
            'lux' => ['required', 'numeric', 'min:0', 'max:200000'],
            'recorded_at' => ['nullable', 'date'],
        ];
    }

    /**
     * Custom Indonesian validation messages for easier debugging on Serial Monitor.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'device_id.required' => 'Field device_id wajib diisi.',
            'voltage.required' => 'Field voltage wajib diisi.',
            'voltage.min' => 'Tegangan tidak boleh bernilai negatif.',
            'voltage.max' => 'Tegangan melebihi batas wajar (100 V).',
            'current.required' => 'Field current wajib diisi.',
            'current.min' => 'Arus listrik tidak boleh bernilai negatif.',
            'power.required' => 'Field power wajib diisi.',
            'power.min' => 'Daya listrik tidak boleh bernilai negatif.',
            'temperature.required' => 'Field temperature wajib diisi.',
            'temperature.min' => 'Temperatur tidak valid (kemungkinan sensor DS18B20 terputus / -127 C).',
            'temperature.max' => 'Temperatur melebihi batas sensor DS18B20 (125 C).',
            'lux.required' => 'Field lux wajib diisi.',
            'lux.min' => 'Intensitas cahaya tidak boleh bernilai negatif.',
        ];
    }

    /**
     * Record transmission validation failure on the authenticated device and return 422 JSON.
     */
    protected function failedValidation(Validator $validator): void
    {
        $device = $this->attributes->get('authenticated_device');
        $firstError = $validator->errors()->first();

        if ($device instanceof Device) {
            $device->forceFill([
                'last_tx_status' => 'error',
                'last_tx_error' => mb_substr($firstError, 0, 250),
            ])->save();
        }

        throw new HttpResponseException(response()->json([
            'status' => 'error',
            'message' => 'Validasi data sensor gagal.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
