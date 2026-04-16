<?php

namespace Database\Seeders;

use App\Models\Administrador;
use App\Models\Alerta;
use App\Models\Colaborador;
use App\Models\ColaboradorPermiso;
use App\Models\ConfiguracionConsultora;
use App\Models\EmpresaCliente;
use App\Models\EmpresaConsultora;
use App\Models\InstitucionFinanciera;
use App\Models\Personal;
use App\Models\Usuario;
use App\Services\PersonalRegistroService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Credenciales demo (contraseña: password):
 * - admin@laboraconsult.demo / administrador
 * - carlos@ruizconsultoria.demo / titular consultora
 * - laura@ruizconsultoria.demo / colaboradora
 * - cliente@constructoranorte.demo / empresa cliente (portal lectura)
 */
class LaboraConsultDemoSeeder extends Seeder
{
    public function run(): void
    {
        $pass = Hash::make('password');

        $uAdmin = Usuario::query()->create([
            'nombre_usuario' => 'admin',
            'correo' => 'admin@laboraconsult.demo',
            'contrasena_hash' => $pass,
            'tipo' => 'administrador',
            'estado' => 'activo',
            'verificado' => true,
        ]);

        Administrador::query()->create([
            'usuario_id' => $uAdmin->id,
            'nombres' => 'Super',
            'apellidos' => 'Administrador',
            'telefono' => '+591 70000000',
            'creado_en' => now(),
        ]);

        $uCarlos = Usuario::query()->create([
            'nombre_usuario' => 'cruiz',
            'correo' => 'carlos@ruizconsultoria.demo',
            'contrasena_hash' => $pass,
            'tipo' => 'consultora',
            'estado' => 'activo',
            'verificado' => true,
        ]);

        $consultora = EmpresaConsultora::query()->create([
            'usuario_id' => $uCarlos->id,
            'registrada_por' => 1,
            'razon_social' => 'Ruiz Consultoría Laboral SRL',
            'nombre_comercial' => 'Ruiz Consultoría Laboral',
            'nit' => '5678901',
            'representante_nombres' => 'Carlos',
            'representante_apellidos' => 'Ruiz Mamani',
            'representante_ci' => '5678901',
            'correo_principal' => 'carlos@ruizconsultoria.demo',
            'telefono' => '+591 73456789',
            'ciudad' => 'Santa Cruz',
            'departamento' => 'Santa Cruz',
            'estado' => 'activo_operativo',
            'configuracion_completa' => true,
        ]);

        $idBnb = InstitucionFinanciera::query()
            ->where('nombre', 'Banco Nacional de Bolivia (BNB)')
            ->value('id');

        ConfiguracionConsultora::query()->where('consultora_id', $consultora->id)->update([
            'color_marca' => '#2563EB',
            'correo_soporte' => 'soporte@ruizconsultoria.bo',
            'telefono_soporte' => '+591 73456789',
            'institucion_financiera_id' => $idBnb,
            'banco' => 'Banco Nacional de Bolivia (BNB)',
            'nro_cuenta' => '1234567890123',
            'tipo_cuenta' => 'ahorro',
            'titular_cuenta' => 'Ruiz Consultoría Laboral SRL',
            'moneda' => 'BOB',
            'plantilla_entrega' => [
                'campos' => [
                    ['id' => 'fecha_entrega', 'etiqueta' => 'Fecha de entrega', 'obligatorio' => true],
                ],
            ],
        ]);

        $uLaura = Usuario::query()->create([
            'nombre_usuario' => 'lvasquez',
            'correo' => 'laura@ruizconsultoria.demo',
            'contrasena_hash' => $pass,
            'tipo' => 'colaborador',
            'estado' => 'activo',
            'verificado' => true,
        ]);

        $colab = Colaborador::query()->create([
            'consultora_id' => $consultora->id,
            'usuario_id' => $uLaura->id,
            'nombres' => 'Laura',
            'apellidos' => 'Vásquez',
            'ci' => '7654321',
            'telefono' => '+591 73456789',
            'cargo' => 'coordinador_general',
            'fecha_ingreso' => now()->subYear(),
            'estado' => 'activo',
        ]);

        $this->seedPermisosCoordinador($colab->id, $consultora->id);

        $uCliente = Usuario::query()->create([
            'nombre_usuario' => 'emp_constructora_norte',
            'correo' => 'cliente@constructoranorte.demo',
            'contrasena_hash' => $pass,
            'tipo' => 'empresa_cliente',
            'estado' => 'activo',
            'verificado' => true,
        ]);

        $empresaCliente = EmpresaCliente::query()->create([
            'consultora_id' => $consultora->id,
            'usuario_id' => $uCliente->id,
            'registrada_por' => $colab->id,
            'nombre' => 'Constructora Norte SA',
            'nit' => '5678901001',
            'razon_social' => 'Constructora Norte Sociedad Anónima',
            'ciudad' => 'Santa Cruz',
            'departamento' => 'Santa Cruz',
            'direccion' => 'Av. Principal 100',
            'telefono' => '+591 33456789',
            'correo_empresa' => 'info@constructoranorte.bo',
            'rep_legal_nombres' => 'Roberto',
            'rep_legal_apellidos' => 'Salinas',
            'rep_legal_ci' => '9876543',
            'actividad_economica' => 'Construcción',
            'estado' => 'activo',
        ]);

        $colab->empresasCliente()->attach($empresaCliente->id, [
            'activo' => true,
            'asignado_por' => $consultora->id,
        ]);

        $personal = Personal::query()->create([
            'empresa_id' => $empresaCliente->id,
            'registrado_por' => $colab->id,
            'nombres' => 'Juan Carlos',
            'apellidos' => 'Mamani Quispe',
            'ci' => '8234567',
            'fecha_nacimiento' => '1988-04-15',
            'cargo' => 'Ingeniero Civil',
            'fecha_ingreso' => '2022-01-15',
            'tipo_contrato' => 'indefinido',
            'salario_mensual' => 5800,
            'modalidad' => 'presencial',
            'estado' => 'activo',
        ]);

        app(PersonalRegistroService::class)->crearConModulos($personal, [
            'afp_nombre' => 'Futuro de Bolivia',
            'numero_afiliado' => 'AFP-00234567',
        ], [
            'caja_nombre' => 'Caja Nacional de Salud — CNS',
            'numero_asegurado' => 'CNS-8234567',
        ]);

        Alerta::query()->create([
            'consultora_id' => $consultora->id,
            'empresa_id' => $empresaCliente->id,
            'personal_id' => $personal->id,
            'colaborador_asignado' => $colab->id,
            'modulo' => 'afp',
            'nivel' => 'normal',
            'titulo' => 'Completar documentación AFP — Juan Carlos Mamani',
            'descripcion' => 'Ejemplo de alerta demo.',
            'fecha_vencimiento' => now()->addDays(7),
            'resuelta' => false,
            'generada_auto' => false,
        ]);
    }

    private function seedPermisosCoordinador(int $colaboradorId, int $consultoraId): void
    {
        foreach (['afp', 'caja', 'ministerio'] as $modulo) {
            ColaboradorPermiso::query()->create([
                'colaborador_id' => $colaboradorId,
                'modulo' => $modulo,
                'puede_ver' => true,
                'puede_registrar_personal' => true,
                'puede_editar_personal' => true,
                'puede_subir_documentos' => true,
                'puede_eliminar_documentos' => true,
                'puede_gestionar_modulo' => true,
                'puede_exportar_reportes' => true,
                'puede_invitar_empresa' => true,
                'configurado_por' => $consultoraId,
            ]);
        }
    }
}
