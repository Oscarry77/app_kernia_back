<?php
namespace App\Http\Requests\Panel;

use App\Models\Landlord\Cliente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Datos del cliente según la Constancia de Situación Fiscal (02-oct-2026).
 * El tipo de persona decide qué campos aplican y su formato (RFC de 12 o 13
 * caracteres, CURP solo física, régimen fiscal compatible). En el alta se
 * agrega el slug.
 */
class DatosFiscalesClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $mayusculas = ['rfc', 'curp', 'razon_social', 'nombre_comercial', 'nombres', 'primer_apellido', 'segundo_apellido',
            'nombre_vialidad', 'colonia', 'localidad', 'municipio', 'entre_calle', 'y_calle'];
        $datos = [];
        foreach ($mayusculas as $campo) {
            if (is_string($this->input($campo))) {
                $datos[$campo] = mb_strtoupper(trim($this->input($campo))) ?: null;
            }
        }
        if (is_string($this->input('correo'))) {
            $datos['correo'] = strtolower(trim($this->input('correo'))) ?: null;
        }
        $this->merge($datos);
    }

    public function rules(): array
    {
        $esAlta = $this->isMethod('post');
        $moral = $this->input('tipo_persona') === Cliente::PERSONA_MORAL;
        $fisica = $this->input('tipo_persona') === Cliente::PERSONA_FISICA;
        $regimenes = array_column(config('catalogos_fiscales.regimenes_fiscales'), 'clave');

        return [
            'slug' => $esAlta
                ? ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', 'unique:clientes,slug']
                : ['prohibited'],
            'notas' => ['nullable', 'string', 'max:2000'],
            // 05-oct-2026: tipo de cliente (comercial por omisión).
            'tipo' => ['nullable', Rule::in(Cliente::TIPOS)],

            'tipo_persona' => ['required', Rule::in([Cliente::PERSONA_MORAL, Cliente::PERSONA_FISICA])],
            'rfc' => ['required', 'string', $moral ? 'regex:/^[A-ZÑ&]{3}\d{6}[A-Z0-9]{3}$/u' : 'regex:/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/u'],

            'razon_social' => [$moral ? 'required' : 'prohibited', 'nullable', 'string', 'max:255'],
            'regimen_capital' => [$moral ? 'nullable' : 'prohibited', 'nullable', 'string', Rule::in(array_column(config('catalogos_fiscales.regimenes_capital'), 'clave'))],
            'nombre_comercial' => ['nullable', 'string', 'max:255'],

            'curp' => [$fisica ? 'required' : 'prohibited', 'nullable', 'string', 'regex:/^[A-Z][AEIOUX][A-Z]{2}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/'],
            'nombres' => [$fisica ? 'required' : 'prohibited', 'nullable', 'string', 'max:120'],
            'primer_apellido' => [$fisica ? 'required' : 'prohibited', 'nullable', 'string', 'max:80'],
            'segundo_apellido' => [$fisica ? 'nullable' : 'prohibited', 'nullable', 'string', 'max:80'],

            'fecha_inicio_operaciones' => ['nullable', 'date', 'before_or_equal:today'],
            'estatus_padron' => ['nullable', Rule::in(['activo', 'suspendido'])],
            'regimen_fiscal' => ['required', Rule::in($regimenes)],

            'codigo_postal' => ['required', 'regex:/^\d{5}$/'],
            'tipo_vialidad' => ['nullable', Rule::in(config('catalogos_fiscales.tipos_vialidad'))],
            'nombre_vialidad' => ['nullable', 'string', 'max:150'],
            'numero_exterior' => ['nullable', 'string', 'max:30'],
            'numero_interior' => ['nullable', 'string', 'max:30'],
            'colonia' => ['nullable', 'string', 'max:150'],
            'localidad' => ['nullable', 'string', 'max:150'],
            'municipio' => ['nullable', 'string', 'max:150'],
            'entidad_federativa' => ['required', Rule::in(config('catalogos_fiscales.entidades_federativas'))],
            'entre_calle' => ['nullable', 'string', 'max:150'],
            'y_calle' => ['nullable', 'string', 'max:150'],
            'correo' => ['required', 'email', 'max:150'],
            'telefono_lada' => ['nullable', 'regex:/^\d{2,3}$/', 'required_with:telefono_numero'],
            'telefono_numero' => ['nullable', 'regex:/^\d{7,8}$/', 'required_with:telefono_lada'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // El régimen fiscal debe aplicar al tipo de persona.
            $tipo = $this->input('tipo_persona') === Cliente::PERSONA_MORAL ? 'M' : 'F';
            $regimen = collect(config('catalogos_fiscales.regimenes_fiscales'))->firstWhere('clave', $this->input('regimen_fiscal'));
            if ($regimen && ! in_array($tipo, $regimen['aplica'], true)) {
                $v->errors()->add('regimen_fiscal', 'Ese régimen fiscal no aplica a una persona '.($tipo === 'M' ? 'moral' : 'física').'.');
            }

            $this->validarTipo($v);

            // Lada + número = 10 dígitos (número nacional de México).
            if ($this->filled('telefono_lada') && $this->filled('telefono_numero')
                && strlen($this->input('telefono_lada').$this->input('telefono_numero')) !== 10) {
                $v->errors()->add('telefono_numero', 'La lada y el número deben sumar 10 dígitos.');
            }
        });
    }

    /**
     * Tipo de cliente (05-oct-2026): en el alta, demo y capacitación llevan su
     * prefijo de slug y solo el superadmin crea clientes de prueba. Después,
     * solo el superadmin cambia el tipo (convertir en comercial cambia cobros
     * y vigencias).
     */
    private function validarTipo(Validator $v): void
    {
        $tipo = $this->input('tipo');
        $superadmin = (bool) $this->user('api')?->esSuperadmin();

        if ($this->isMethod('post')) {
            $tipo ??= Cliente::TIPO_COMERCIAL;
            $prefijo = Cliente::PREFIJOS_SLUG[$tipo] ?? null;
            if ($prefijo && ! str_starts_with((string) $this->input('slug'), $prefijo)) {
                $v->errors()->add('slug', "El slug de un cliente de este tipo empieza con «{$prefijo}».");
            }
            if ($tipo === Cliente::TIPO_PRUEBA && ! $superadmin) {
                $v->errors()->add('tipo', 'Solo el superadministrador crea clientes de prueba.');
            }

            return;
        }

        $actual = $this->route('cliente')?->tipo ?? Cliente::TIPO_COMERCIAL;
        if ($tipo !== null && $tipo !== $actual && ! $superadmin) {
            $v->errors()->add('tipo', 'Solo el superadministrador cambia el tipo de cliente.');
        }
    }

    /** Tipo que se guarda: en el alta, comercial por omisión; al editar, null si no cambia. */
    public function tipoCliente(): ?string
    {
        return $this->safe()->offsetExists('tipo') ? $this->safe()['tipo'] : ($this->isMethod('post') ? Cliente::TIPO_COMERCIAL : null);
    }

    public function messages(): array
    {
        return [
            'rfc.regex' => $this->input('tipo_persona') === Cliente::PERSONA_MORAL
                ? 'El RFC de una persona moral tiene 12 caracteres (3 letras, 6 dígitos de fecha y 3 de homoclave).'
                : 'El RFC de una persona física tiene 13 caracteres (4 letras, 6 dígitos de fecha y 3 de homoclave).',
            'curp.regex' => 'La CURP tiene 18 caracteres con el formato oficial.',
            'codigo_postal.regex' => 'El código postal tiene 5 dígitos.',
            'telefono_lada.regex' => 'La lada tiene 2 o 3 dígitos.',
            'telefono_numero.regex' => 'El número tiene 7 u 8 dígitos.',
            'slug.regex' => 'El slug va en minúsculas, números y guiones (3 a 63 caracteres), sin empezar ni terminar en guion.',
            'slug.unique' => 'Ya existe un cliente con ese slug.',
            '*.prohibited' => 'Este dato no aplica al tipo de persona seleccionado.',
        ];
    }

    /** Solo los campos que aplican al tipo de persona; los del otro tipo se limpian. */
    public function datosCliente(): array
    {
        $datos = $this->safe()->only([...Cliente::CAMPOS_FISCALES, 'notas']);
        $otros = $datos['tipo_persona'] === Cliente::PERSONA_MORAL
            ? ['curp', 'nombres', 'primer_apellido', 'segundo_apellido']
            : ['razon_social', 'regimen_capital'];

        foreach (Cliente::CAMPOS_FISCALES as $campo) {
            $datos[$campo] = in_array($campo, $otros, true) ? null : ($datos[$campo] ?? null);
        }

        return $datos;
    }
}
