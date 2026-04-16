<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices parciales, triggers y vistas alineados a laboraconsult_db_v2.sql (solo PostgreSQL).
 * En otros drivers las tablas ya tienen los índices estándar definidos en migraciones anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE INDEX idx_log_fecha ON actividad_log(creado_en DESC);
            CREATE INDEX idx_sesiones_activa ON sesiones(activa) WHERE activa = TRUE;
            CREATE INDEX idx_colab_emp_activo ON colaborador_empresa_cliente(activo) WHERE activo = TRUE;
            CREATE INDEX idx_documentos_vigente ON documentos(personal_id, tipo_documento_id)
                WHERE es_vigente = TRUE AND eliminado = FALSE;
            CREATE INDEX idx_alertas_pendientes ON alertas(consultora_id, resuelta, nivel)
                WHERE resuelta = FALSE;
            CREATE INDEX idx_alertas_vencimiento ON alertas(fecha_vencimiento)
                WHERE resuelta = FALSE;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_actualizar_timestamp()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.actualizado_en = NOW();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tg_usuarios_ts
                BEFORE UPDATE ON usuarios
                FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();

            CREATE TRIGGER tg_consultoras_ts
                BEFORE UPDATE ON empresas_consultoras
                FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();

            CREATE TRIGGER tg_colaboradores_ts
                BEFORE UPDATE ON colaboradores
                FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();

            CREATE TRIGGER tg_emp_cliente_ts
                BEFORE UPDATE ON empresas_cliente
                FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();

            CREATE TRIGGER tg_personal_ts
                BEFORE UPDATE ON personal
                FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_crear_fichas_gestion()
            RETURNS TRIGGER AS $$
            BEGIN
                INSERT INTO personal_afp  (personal_id) VALUES (NEW.id);
                INSERT INTO personal_caja (personal_id) VALUES (NEW.id);
                INSERT INTO personal_ministerio (personal_id) VALUES (NEW.id);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tg_personal_fichas
                AFTER INSERT ON personal
                FOR EACH ROW EXECUTE FUNCTION fn_crear_fichas_gestion();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_crear_config_consultora()
            RETURNS TRIGGER AS $$
            BEGIN
                INSERT INTO configuracion_consultora (consultora_id)
                VALUES (NEW.id);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tg_consultora_config
                AFTER INSERT ON empresas_consultoras
                FOR EACH ROW EXECUTE FUNCTION fn_crear_config_consultora();
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE VIEW v_cumplimiento_personal AS
            SELECT
                p.id                                        AS personal_id,
                p.nombres || ' ' || p.apellidos             AS nombre_completo,
                p.ci, p.cargo, p.estado                     AS estado_personal,
                e.id                                        AS empresa_id,
                e.nombre                                    AS empresa,
                con.id                                      AS consultora_id,
                con.nombre_comercial                        AS consultora,
                COALESCE(afp.estado, 'sin_datos')           AS estado_afp,
                COALESCE(cja.estado, 'sin_datos')           AS estado_caja,
                COALESCE(mt.estado, 'sin_datos')            AS estado_ministerio,
                afp.afp_nombre, afp.numero_afiliado,
                cja.caja_nombre, cja.numero_asegurado,
                mt.numero_registro_mt
            FROM personal p
            JOIN empresas_cliente         e   ON e.id   = p.empresa_id
            JOIN empresas_consultoras     con ON con.id = e.consultora_id
            LEFT JOIN personal_afp        afp ON afp.personal_id = p.id
            LEFT JOIN personal_caja       cja ON cja.personal_id = p.id
            LEFT JOIN personal_ministerio mt  ON mt.personal_id  = p.id;

            CREATE VIEW v_cobertura_empresa AS
            SELECT
                e.id                    AS empresa_id,
                e.nombre                AS empresa,
                e.consultora_id,
                COUNT(p.id)             AS total_personal,
                COUNT(CASE WHEN afp.estado = 'al_dia' THEN 1 END)   AS afp_al_dia,
                ROUND(COUNT(CASE WHEN afp.estado = 'al_dia' THEN 1 END) * 100.0
                      / NULLIF(COUNT(p.id), 0), 1)                  AS pct_afp,
                COUNT(CASE WHEN cja.estado = 'al_dia' THEN 1 END)   AS caja_al_dia,
                ROUND(COUNT(CASE WHEN cja.estado = 'al_dia' THEN 1 END) * 100.0
                      / NULLIF(COUNT(p.id), 0), 1)                  AS pct_caja,
                COUNT(CASE WHEN mt.estado  = 'al_dia' THEN 1 END)   AS ministerio_al_dia,
                ROUND(COUNT(CASE WHEN mt.estado = 'al_dia' THEN 1 END) * 100.0
                      / NULLIF(COUNT(p.id), 0), 1)                  AS pct_ministerio
            FROM empresas_cliente e
            LEFT JOIN personal          p   ON p.empresa_id  = e.id AND p.estado = 'activo'
            LEFT JOIN personal_afp      afp ON afp.personal_id = p.id
            LEFT JOIN personal_caja     cja ON cja.personal_id = p.id
            LEFT JOIN personal_ministerio mt ON mt.personal_id  = p.id
            GROUP BY e.id, e.nombre, e.consultora_id;

            CREATE VIEW v_documentos_vigentes AS
            SELECT
                d.id                            AS documento_id,
                d.personal_id,
                p.nombres || ' ' || p.apellidos AS empleado,
                p.ci                            AS ci_empleado,
                e.nombre                        AS empresa,
                e.id                            AS empresa_id,
                d.modulo,
                td.nombre                       AS tipo_documento,
                td.obligatorio,
                td.es_periodico,
                d.nombre_original,
                d.formato,
                d.tamano_bytes,
                d.periodo,
                d.fecha_subida,
                d.observacion,
                u.nombre_usuario                AS subido_por_usuario
            FROM documentos d
            JOIN tipos_documento td             ON td.id = d.tipo_documento_id
            JOIN personal p                     ON p.id  = d.personal_id
            JOIN empresas_cliente e             ON e.id  = p.empresa_id
            LEFT JOIN colaboradores col         ON col.id = d.subido_por
            LEFT JOIN usuarios u                ON u.id  = col.usuario_id
            WHERE d.es_vigente = TRUE
              AND d.eliminado  = FALSE;

            CREATE VIEW v_equipo_consultora AS
            SELECT
                col.id                                      AS colaborador_id,
                col.consultora_id,
                col.nombres || ' ' || col.apellidos         AS nombre_completo,
                u.correo,
                u.nombre_usuario,
                col.cargo,
                u.estado                                    AS estado_acceso,
                u.ultimo_acceso,
                COUNT(DISTINCT ce.empresa_id)
                    FILTER (WHERE ce.activo = TRUE)         AS empresas_asignadas,
                COUNT(DISTINCT cp.modulo)                   AS modulos_con_permiso_gestion
            FROM colaboradores col
            JOIN usuarios u                     ON u.id  = col.usuario_id
            LEFT JOIN colaborador_empresa_cliente ce ON ce.colaborador_id = col.id
            LEFT JOIN colaborador_permisos cp   ON cp.colaborador_id = col.id
                                               AND cp.puede_gestionar_modulo = TRUE
            GROUP BY col.id, col.consultora_id, col.nombres, col.apellidos,
                     u.correo, u.nombre_usuario, col.cargo, u.estado, u.ultimo_acceso;

            CREATE VIEW v_alertas_activas AS
            SELECT
                a.id,
                a.consultora_id,
                a.nivel,
                a.titulo,
                a.descripcion,
                a.modulo,
                a.fecha_vencimiento,
                a.fecha_vencimiento - CURRENT_DATE      AS dias_para_vencer,
                e.nombre                                AS empresa,
                p.nombres || ' ' || p.apellidos         AS empleado,
                col.nombres || ' ' || col.apellidos     AS colaborador_asignado,
                a.generada_auto,
                a.creado_en
            FROM alertas a
            LEFT JOIN empresas_cliente  e   ON e.id  = a.empresa_id
            LEFT JOIN personal          p   ON p.id  = a.personal_id
            LEFT JOIN colaboradores     col ON col.id = a.colaborador_asignado
            WHERE a.resuelta = FALSE
            ORDER BY
                CASE a.nivel WHEN 'urgente' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
                a.fecha_vencimiento ASC NULLS LAST;

            CREATE VIEW v_stats_consultora AS
            SELECT
                con.id                                          AS consultora_id,
                con.nombre_comercial,
                COUNT(DISTINCT e.id)                            AS total_empresas_activas,
                COUNT(DISTINCT col.id)
                    FILTER (WHERE col.estado = 'activo')        AS total_colaboradores_activos,
                COUNT(DISTINCT p.id)
                    FILTER (WHERE p.estado  = 'activo')         AS total_personal_activo,
                COUNT(DISTINCT a.id)
                    FILTER (WHERE a.resuelta = FALSE
                              AND a.nivel = 'urgente')          AS alertas_urgentes_pendientes,
                COUNT(DISTINCT a.id)
                    FILTER (WHERE a.resuelta = FALSE)           AS alertas_totales_pendientes,
                COUNT(DISTINCT d.id)
                    FILTER (WHERE d.fecha_subida >= DATE_TRUNC('month', NOW())
                              AND d.eliminado = FALSE)          AS documentos_subidos_este_mes
            FROM empresas_consultoras con
            LEFT JOIN empresas_cliente    e   ON e.consultora_id = con.id AND e.estado = 'activo'
            LEFT JOIN colaboradores       col ON col.consultora_id = con.id
            LEFT JOIN personal            p   ON p.empresa_id = e.id
            LEFT JOIN alertas             a   ON a.consultora_id = con.id
            LEFT JOIN documentos          d   ON d.personal_id = p.id
            GROUP BY con.id, con.nombre_comercial;
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP VIEW IF EXISTS v_stats_consultora;
            DROP VIEW IF EXISTS v_alertas_activas;
            DROP VIEW IF EXISTS v_equipo_consultora;
            DROP VIEW IF EXISTS v_documentos_vigentes;
            DROP VIEW IF EXISTS v_cobertura_empresa;
            DROP VIEW IF EXISTS v_cumplimiento_personal;
            SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tg_consultora_config ON empresas_consultoras;
            DROP FUNCTION IF EXISTS fn_crear_config_consultora();
            DROP TRIGGER IF EXISTS tg_personal_fichas ON personal;
            DROP FUNCTION IF EXISTS fn_crear_fichas_gestion();
            DROP TRIGGER IF EXISTS tg_personal_ts ON personal;
            DROP TRIGGER IF EXISTS tg_emp_cliente_ts ON empresas_cliente;
            DROP TRIGGER IF EXISTS tg_colaboradores_ts ON colaboradores;
            DROP TRIGGER IF EXISTS tg_consultoras_ts ON empresas_consultoras;
            DROP TRIGGER IF EXISTS tg_usuarios_ts ON usuarios;
            DROP FUNCTION IF EXISTS fn_actualizar_timestamp();
            SQL);

        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_log_fecha;
            DROP INDEX IF EXISTS idx_alertas_vencimiento;
            DROP INDEX IF EXISTS idx_alertas_pendientes;
            DROP INDEX IF EXISTS idx_documentos_vigente;
            DROP INDEX IF EXISTS idx_colab_emp_activo;
            DROP INDEX IF EXISTS idx_sesiones_activa;
            SQL);
    }
};
