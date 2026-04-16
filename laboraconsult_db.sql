-- =============================================================================
--  LaboraConsult — Script de Base de Datos PostgreSQL
--  Sistema de Gestión Laboral (AFP / CAJA / Ministerio de Trabajo)
--  Versión: 1.0  |  Idioma: Español
-- =============================================================================
--  JERARQUÍA DE USUARIOS:
--    1. Administrador  → Registra y gestiona Consultores
--    2. Consultor      → Gestiona Empresas y su propio Equipo
--    3. Colaborador    → Personal del Consultor, asignado a Empresas (usuario del sistema)
--    4. Empresa        → Acceso de solo lectura a su propia información
-- =============================================================================


-- ─────────────────────────────────────────────────────────────────────────────
-- 0. EXTENSIONES
-- ─────────────────────────────────────────────────────────────────────────────
CREATE EXTENSION IF NOT EXISTS "pgcrypto";   -- Para gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS "unaccent";   -- Para búsquedas sin tilde


-- ─────────────────────────────────────────────────────────────────────────────
-- 1. TIPOS ENUMERADOS
-- ─────────────────────────────────────────────────────────────────────────────

-- Roles principales del sistema
CREATE TYPE tipo_usuario AS ENUM (
    'administrador',
    'consultor',
    'colaborador',
    'empresa'
);

-- Cargos posibles dentro del equipo de un consultor
CREATE TYPE tipo_cargo_colaborador AS ENUM (
    'coordinador_general',
    'analista_afp',
    'analista_caja',
    'analista_ministerio',
    'asistente_administrativo',
    'supervisor_gestion'
);

-- Módulos de gestión laboral disponibles
CREATE TYPE tipo_modulo AS ENUM (
    'afp',
    'caja',
    'ministerio'
);

-- Estado genérico de registros
CREATE TYPE estado_registro AS ENUM (
    'activo',
    'inactivo',
    'suspendido'
);

-- Estado de cumplimiento documental de un empleado en un módulo
CREATE TYPE estado_cumplimiento AS ENUM (
    'al_dia',
    'pendiente',
    'vencido',
    'sin_datos'
);

-- Tipos de contrato laboral (normativa boliviana)
CREATE TYPE tipo_contrato AS ENUM (
    'indefinido',
    'plazo_fijo',
    'por_obra',
    'eventual'
);

-- Modalidad de trabajo
CREATE TYPE modalidad_trabajo AS ENUM (
    'presencial',
    'remoto',
    'hibrido'
);

-- Género del empleado
CREATE TYPE tipo_genero AS ENUM (
    'masculino',
    'femenino',
    'no_especificado'
);

-- Estado civil
CREATE TYPE estado_civil AS ENUM (
    'soltero',
    'casado',
    'divorciado',
    'viudo',
    'concubinato'
);

-- Nivel de educación
CREATE TYPE nivel_educacion AS ENUM (
    'primaria',
    'secundaria',
    'tecnico',
    'universitario',
    'postgrado'
);

-- Nivel de severidad para alertas
CREATE TYPE nivel_alerta AS ENUM (
    'informativo',
    'normal',
    'urgente'
);

-- Extensiones de CI válidas en Bolivia
CREATE TYPE extension_ci AS ENUM (
    'SC', 'LP', 'CB', 'OR', 'PT', 'TJ', 'BE', 'CH', 'PD'
);


-- ─────────────────────────────────────────────────────────────────────────────
-- 2. TABLA BASE: USUARIOS
--    Centraliza autenticación para todos los tipos de acceso
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE usuarios (
    id                  SERIAL          PRIMARY KEY,
    nombre_usuario      VARCHAR(80)     NOT NULL UNIQUE,
    correo              VARCHAR(150)    NOT NULL UNIQUE,
    contrasena_hash     VARCHAR(255)    NOT NULL,
    tipo                tipo_usuario    NOT NULL,
    estado              estado_registro NOT NULL DEFAULT 'activo',
    verificado          BOOLEAN         NOT NULL DEFAULT FALSE,
    token_verificacion  VARCHAR(255),
    token_reset         VARCHAR(255),
    token_expiracion    TIMESTAMP,
    ultimo_acceso       TIMESTAMP,
    ip_ultimo_acceso    VARCHAR(45),
    intentos_fallidos   SMALLINT        NOT NULL DEFAULT 0,
    bloqueado_hasta     TIMESTAMP,
    creado_en           TIMESTAMP       NOT NULL DEFAULT NOW(),
    actualizado_en      TIMESTAMP       NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  usuarios                  IS 'Tabla central de autenticación para todos los tipos de usuario del sistema.';
COMMENT ON COLUMN usuarios.tipo             IS 'Determina el perfil de acceso: administrador, consultor, colaborador o empresa.';
COMMENT ON COLUMN usuarios.intentos_fallidos IS 'Contador de intentos de login fallidos consecutivos. Se resetea al hacer login correcto.';
COMMENT ON COLUMN usuarios.bloqueado_hasta  IS 'Si no es NULL, el usuario está bloqueado temporalmente por exceso de intentos.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 3. ADMINISTRADORES
--    Perfil extendido del usuario con rol 'administrador'
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE administradores (
    id          SERIAL      PRIMARY KEY,
    usuario_id  INT         NOT NULL UNIQUE REFERENCES usuarios(id) ON DELETE CASCADE,
    nombres     VARCHAR(100) NOT NULL,
    apellidos   VARCHAR(100) NOT NULL,
    telefono    VARCHAR(20),
    creado_en   TIMESTAMP   NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE administradores IS 'Perfil del administrador del sistema. Tiene acceso total y registra consultores.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 4. CONSULTORES
--    Registrados por el administrador. Gestionan empresas y su propio equipo.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE consultores (
    id                  SERIAL          PRIMARY KEY,
    usuario_id          INT             NOT NULL UNIQUE REFERENCES usuarios(id) ON DELETE CASCADE,
    registrado_por      INT             NOT NULL REFERENCES administradores(id),
    nombres             VARCHAR(100)    NOT NULL,
    apellidos           VARCHAR(100)    NOT NULL,
    ci                  VARCHAR(20),
    extension_ci        extension_ci,
    telefono            VARCHAR(20),
    correo_profesional  VARCHAR(150),
    ciudad              VARCHAR(100),
    departamento        VARCHAR(100),
    direccion           TEXT,
    nit                 VARCHAR(20),
    razon_social        VARCHAR(200),       -- Nombre legal de la consultora
    estado              estado_registro     NOT NULL DEFAULT 'activo',
    fecha_registro      DATE                NOT NULL DEFAULT CURRENT_DATE,
    observaciones       TEXT,
    creado_en           TIMESTAMP           NOT NULL DEFAULT NOW(),
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  consultores             IS 'Consultores registrados por el admin. Cada consultor gestiona N empresas y tiene su propio equipo.';
COMMENT ON COLUMN consultores.razon_social IS 'Nombre legal de la empresa consultora (si aplica).';


-- ─────────────────────────────────────────────────────────────────────────────
-- 5. COLABORADORES (Equipo del Consultor)
--    Personal propio del consultor con distintos cargos.
--    Tienen acceso al sistema como usuarios de tipo 'colaborador'.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE colaboradores (
    id              SERIAL                  PRIMARY KEY,
    consultor_id    INT                     NOT NULL REFERENCES consultores(id) ON DELETE CASCADE,
    usuario_id      INT                     NOT NULL UNIQUE REFERENCES usuarios(id) ON DELETE CASCADE,
    nombres         VARCHAR(100)            NOT NULL,
    apellidos       VARCHAR(100)            NOT NULL,
    ci              VARCHAR(20),
    extension_ci    extension_ci,
    telefono        VARCHAR(20),
    cargo           tipo_cargo_colaborador  NOT NULL,
    fecha_ingreso   DATE,
    estado          estado_registro         NOT NULL DEFAULT 'activo',
    observaciones   TEXT,
    creado_en       TIMESTAMP               NOT NULL DEFAULT NOW(),
    actualizado_en  TIMESTAMP               NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  colaboradores         IS 'Personal del consultor con acceso al sistema. Cada uno tiene un cargo que define sus permisos base.';
COMMENT ON COLUMN colaboradores.cargo   IS 'Cargo que determina los permisos por defecto: coordinador_general tiene acceso completo, los analistas solo a su módulo.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 6. EMPRESAS
--    Registradas por el consultor. Tienen su propio acceso de tipo 'empresa'.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE empresas (
    id                      SERIAL          PRIMARY KEY,
    consultor_id            INT             NOT NULL REFERENCES consultores(id) ON DELETE RESTRICT,
    usuario_id              INT             UNIQUE REFERENCES usuarios(id) ON DELETE SET NULL,
    -- usuario_id puede ser NULL si aún no se generaron credenciales de empresa
    nombre                  VARCHAR(200)    NOT NULL,
    nit                     VARCHAR(30)     NOT NULL UNIQUE,
    razon_social            VARCHAR(200),
    ciudad                  VARCHAR(100),
    departamento            VARCHAR(100),
    direccion               TEXT,
    telefono                VARCHAR(20),
    correo_empresa          VARCHAR(150),
    representante_legal     VARCHAR(200),
    ci_representante        VARCHAR(20),
    extension_ci_rep        extension_ci,
    actividad_economica     VARCHAR(200),
    matricula_comercio      VARCHAR(50),
    estado                  estado_registro NOT NULL DEFAULT 'activo',
    fecha_registro          DATE            NOT NULL DEFAULT CURRENT_DATE,
    observaciones           TEXT,
    creado_en               TIMESTAMP       NOT NULL DEFAULT NOW(),
    actualizado_en          TIMESTAMP       NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  empresas              IS 'Empresas clientes gestionadas por el consultor. Tienen acceso de solo lectura al sistema.';
COMMENT ON COLUMN empresas.usuario_id   IS 'Referencia al usuario de acceso de la empresa. NULL hasta que se activen las credenciales.';
COMMENT ON COLUMN empresas.consultor_id IS 'El consultor que registró y gestiona esta empresa. No puede eliminarse si tiene empresas activas.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 7. ASIGNACIÓN COLABORADOR ↔ EMPRESA
--    Un colaborador puede gestionar N empresas;
--    una empresa puede tener N colaboradores asignados.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE colaborador_empresa (
    id              SERIAL      PRIMARY KEY,
    colaborador_id  INT         NOT NULL REFERENCES colaboradores(id) ON DELETE CASCADE,
    empresa_id      INT         NOT NULL REFERENCES empresas(id) ON DELETE CASCADE,
    activo          BOOLEAN     NOT NULL DEFAULT TRUE,
    asignado_por    INT         REFERENCES consultores(id),   -- El consultor que hizo la asignación
    asignado_en     TIMESTAMP   NOT NULL DEFAULT NOW(),
    removido_en     TIMESTAMP,
    UNIQUE (colaborador_id, empresa_id)
);

COMMENT ON TABLE colaborador_empresa IS 'Tabla pivote que asigna colaboradores a empresas. Cuando activo=FALSE el colaborador pierde acceso a esa empresa.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 8. PERMISOS DE COLABORADOR POR MÓDULO
--    Control granular por módulo (AFP / CAJA / Ministerio).
--    Si un colaborador no tiene fila para un módulo, no tiene acceso a ese módulo.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE colaborador_permisos (
    id                          SERIAL          PRIMARY KEY,
    colaborador_id              INT             NOT NULL REFERENCES colaboradores(id) ON DELETE CASCADE,
    modulo                      tipo_modulo     NOT NULL,
    -- Permisos de lectura
    puede_ver                   BOOLEAN         NOT NULL DEFAULT TRUE,
    -- Permisos sobre personal
    puede_registrar_personal    BOOLEAN         NOT NULL DEFAULT FALSE,
    puede_editar_personal       BOOLEAN         NOT NULL DEFAULT FALSE,
    -- Permisos sobre documentos
    puede_subir_documentos      BOOLEAN         NOT NULL DEFAULT FALSE,
    puede_eliminar_documentos   BOOLEAN         NOT NULL DEFAULT FALSE,
    -- Permisos específicos de gestión
    puede_gestionar_modulo      BOOLEAN         NOT NULL DEFAULT FALSE,
    -- Permisos de reportes
    puede_exportar_reportes     BOOLEAN         NOT NULL DEFAULT FALSE,
    -- Permiso avanzado
    puede_invitar_empresa       BOOLEAN         NOT NULL DEFAULT FALSE,
    -- Auditoría
    configurado_por             INT             REFERENCES consultores(id),
    actualizado_en              TIMESTAMP       NOT NULL DEFAULT NOW(),
    UNIQUE (colaborador_id, modulo)
);

COMMENT ON TABLE  colaborador_permisos                      IS 'Permisos granulares por módulo para cada colaborador. Una fila por módulo por colaborador.';
COMMENT ON COLUMN colaborador_permisos.puede_gestionar_modulo IS 'Permiso para registrar/actualizar datos específicos del módulo (AFP, CAJA o Ministerio).';


-- ─────────────────────────────────────────────────────────────────────────────
-- 9. PERSONAL DE EMPRESA
--    Empleados registrados por el consultor o sus colaboradores.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE personal (
    id                  SERIAL              PRIMARY KEY,
    empresa_id          INT                 NOT NULL REFERENCES empresas(id) ON DELETE RESTRICT,
    registrado_por      INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    -- Datos personales
    nombres             VARCHAR(100)        NOT NULL,
    apellidos           VARCHAR(100)        NOT NULL,
    ci                  VARCHAR(20)         NOT NULL,
    extension_ci        extension_ci,
    fecha_nacimiento    DATE,
    genero              tipo_genero,
    estado_civil        estado_civil,
    telefono            VARCHAR(20),
    correo              VARCHAR(150),
    direccion           TEXT,
    nivel_educacion     nivel_educacion,
    profesion           VARCHAR(150),
    -- Datos laborales
    cargo               VARCHAR(150)        NOT NULL,
    fecha_ingreso       DATE                NOT NULL,
    fecha_egreso        DATE,
    tipo_contrato       tipo_contrato,
    salario_mensual     NUMERIC(10,2),
    modalidad           modalidad_trabajo   NOT NULL DEFAULT 'presencial',
    estado              estado_registro     NOT NULL DEFAULT 'activo',
    observaciones       TEXT,
    -- Auditoría
    creado_en           TIMESTAMP           NOT NULL DEFAULT NOW(),
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW(),
    UNIQUE (empresa_id, ci)
);

COMMENT ON TABLE  personal              IS 'Empleados de cada empresa. El CI es único dentro de la misma empresa.';
COMMENT ON COLUMN personal.registrado_por IS 'Colaborador que creó la ficha. Si el colaborador es eliminado, se conserva NULL.';
COMMENT ON COLUMN personal.fecha_egreso IS 'Si no es NULL, el empleado ya no trabaja en la empresa.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 10. DATOS AFP DEL PERSONAL
--     Información de afiliación al sistema de pensiones
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE personal_afp (
    id                  SERIAL              PRIMARY KEY,
    personal_id         INT                 NOT NULL UNIQUE REFERENCES personal(id) ON DELETE CASCADE,
    afp_nombre          VARCHAR(100)        NOT NULL,   -- 'Futuro de Bolivia', 'BBVA Previsión AFP'
    numero_afiliado     VARCHAR(50)         NOT NULL,
    fecha_afiliacion    DATE,
    estado              estado_cumplimiento NOT NULL DEFAULT 'sin_datos',
    actualizado_por     INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  personal_afp          IS 'Datos de afiliación AFP del empleado. Relación 1:1 con personal.';
COMMENT ON COLUMN personal_afp.afp_nombre IS 'AFP a la que está afiliado: Futuro de Bolivia o BBVA Previsión AFP (únicas en Bolivia).';


-- ─────────────────────────────────────────────────────────────────────────────
-- 11. DATOS CAJA DEL PERSONAL
--     Información de seguro de salud (Caja)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE personal_caja (
    id                  SERIAL              PRIMARY KEY,
    personal_id         INT                 NOT NULL UNIQUE REFERENCES personal(id) ON DELETE CASCADE,
    caja_nombre         VARCHAR(150)        NOT NULL,   -- 'CNS', 'Caja Petrolera', 'Caja Bancaria', 'COSSMIL'
    numero_asegurado    VARCHAR(50)         NOT NULL,
    fecha_afiliacion    DATE,
    estado              estado_cumplimiento NOT NULL DEFAULT 'sin_datos',
    actualizado_por     INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  personal_caja           IS 'Datos del seguro de salud (Caja) del empleado. Relación 1:1 con personal.';
COMMENT ON COLUMN personal_caja.caja_nombre IS 'Caja a la que está afiliado: CNS, Caja Petrolera, Caja Bancaria, COSSMIL, etc.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 12. DATOS MINISTERIO DE TRABAJO DEL PERSONAL
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE personal_ministerio (
    id                  SERIAL              PRIMARY KEY,
    personal_id         INT                 NOT NULL UNIQUE REFERENCES personal(id) ON DELETE CASCADE,
    numero_registro_mt  VARCHAR(50),        -- Número de registro ante el Ministerio
    fecha_registro      DATE,
    tipo_contrato_mt    VARCHAR(100),       -- Tipo de contrato registrado en el MT
    estado              estado_cumplimiento NOT NULL DEFAULT 'sin_datos',
    actualizado_por     INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE personal_ministerio IS 'Datos de registro del empleado ante el Ministerio de Trabajo. Relación 1:1 con personal.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 13. CATÁLOGO DE TIPOS DE DOCUMENTO
--     Define qué tipos de archivo se pueden subir por módulo.
--     Precargado con los tipos del sistema; extensible por el admin.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE tipos_documento (
    id                  SERIAL          PRIMARY KEY,
    modulo              tipo_modulo     NOT NULL,
    nombre              VARCHAR(150)    NOT NULL,
    descripcion         TEXT,
    obligatorio         BOOLEAN         NOT NULL DEFAULT FALSE,
    formatos_permitidos VARCHAR(100)    NOT NULL DEFAULT 'pdf,xlsx,docx,jpg,png',
    tamano_maximo_mb    SMALLINT        NOT NULL DEFAULT 10,
    activo              BOOLEAN         NOT NULL DEFAULT TRUE,
    creado_en           TIMESTAMP       NOT NULL DEFAULT NOW(),
    UNIQUE (modulo, nombre)
);

COMMENT ON TABLE  tipos_documento            IS 'Catálogo de tipos de documento por módulo. Define qué se puede subir en AFP, CAJA y Ministerio.';
COMMENT ON COLUMN tipos_documento.obligatorio IS 'Si TRUE, el sistema genera alerta cuando este tipo de documento no está cargado para un empleado.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 14. DOCUMENTOS (Archivos subidos)
--     Un documento pertenece a un empleado, en un módulo específico,
--     y corresponde a un tipo de documento del catálogo.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE documentos (
    id                  SERIAL              PRIMARY KEY,
    personal_id         INT                 NOT NULL REFERENCES personal(id) ON DELETE CASCADE,
    tipo_documento_id   INT                 NOT NULL REFERENCES tipos_documento(id) ON DELETE RESTRICT,
    modulo              tipo_modulo         NOT NULL,
    -- Datos del archivo
    nombre_archivo      VARCHAR(255)        NOT NULL,
    nombre_original     VARCHAR(255)        NOT NULL,   -- Nombre original que subió el usuario
    ruta_archivo        VARCHAR(500)        NOT NULL,   -- Ruta en storage (S3, disco, etc.)
    formato             VARCHAR(10)         NOT NULL,   -- 'pdf', 'xlsx', 'docx', 'jpg', etc.
    tamano_bytes        BIGINT,
    -- Contexto temporal
    periodo             VARCHAR(20),                    -- Ej: 'Marzo 2026', 'Q1 2026'
    fecha_documento     DATE,                           -- Fecha del documento (ej: fecha de la planilla)
    -- Metadatos
    observacion         TEXT,
    es_vigente          BOOLEAN             NOT NULL DEFAULT TRUE,   -- FALSE si fue reemplazado por uno nuevo
    subido_por          INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    fecha_subida        TIMESTAMP           NOT NULL DEFAULT NOW(),
    eliminado           BOOLEAN             NOT NULL DEFAULT FALSE,
    eliminado_por       INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    eliminado_en        TIMESTAMP
);

COMMENT ON TABLE  documentos            IS 'Archivos subidos por módulo para cada empleado. Soft delete con campo eliminado.';
COMMENT ON COLUMN documentos.es_vigente IS 'Cuando se sube una nueva versión del mismo tipo, los anteriores pasan a es_vigente=FALSE.';
COMMENT ON COLUMN documentos.eliminado  IS 'Soft delete: el archivo no se borra físicamente, solo se marca como eliminado.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 15. ALERTAS Y PENDIENTES
--     Generadas automáticamente por el sistema o manualmente por el consultor.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE alertas (
    id                  SERIAL          PRIMARY KEY,
    consultor_id        INT             NOT NULL REFERENCES consultores(id) ON DELETE CASCADE,
    empresa_id          INT             REFERENCES empresas(id) ON DELETE CASCADE,
    personal_id         INT             REFERENCES personal(id) ON DELETE CASCADE,
    modulo              tipo_modulo,
    nivel               nivel_alerta    NOT NULL DEFAULT 'normal',
    titulo              VARCHAR(200)    NOT NULL,
    descripcion         TEXT,
    fecha_vencimiento   DATE,
    resuelta            BOOLEAN         NOT NULL DEFAULT FALSE,
    resuelta_por        INT             REFERENCES colaboradores(id) ON DELETE SET NULL,
    resuelta_en         TIMESTAMP,
    generada_auto       BOOLEAN         NOT NULL DEFAULT FALSE,     -- TRUE = creada por el sistema automáticamente
    creado_en           TIMESTAMP       NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  alertas                IS 'Alertas y tareas pendientes. Pueden ser generadas automáticamente o de forma manual.';
COMMENT ON COLUMN alertas.generada_auto  IS 'TRUE cuando el sistema la crea automáticamente (ej: documento próximo a vencer).';


-- ─────────────────────────────────────────────────────────────────────────────
-- 16. LOG DE ACTIVIDAD
--     Registro de auditoría de todas las acciones relevantes del sistema.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE actividad_log (
    id              SERIAL          PRIMARY KEY,
    usuario_id      INT             NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    accion          VARCHAR(100)    NOT NULL,       -- 'subir_documento', 'registrar_personal', 'editar_empresa', etc.
    modulo          tipo_modulo,
    entidad         VARCHAR(50),                    -- 'personal', 'documento', 'empresa', 'colaborador'
    entidad_id      INT,                            -- ID del registro afectado
    descripcion     TEXT,
    datos_anteriores JSONB,                         -- Snapshot del estado previo (para auditoría)
    datos_nuevos    JSONB,                          -- Snapshot del estado nuevo
    ip_origen       VARCHAR(45),
    creado_en       TIMESTAMP       NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  actividad_log             IS 'Log de auditoría inmutable. Registra qué hizo quién, cuándo y sobre qué entidad.';
COMMENT ON COLUMN actividad_log.datos_anteriores IS 'JSON con el estado del registro antes del cambio (para poder hacer rollback o auditoría).';


-- ─────────────────────────────────────────────────────────────────────────────
-- 17. SESIONES ACTIVAS (opcional, para control de tokens JWT / sesiones)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE sesiones (
    id              SERIAL      PRIMARY KEY,
    usuario_id      INT         NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    token_hash      VARCHAR(255) NOT NULL UNIQUE,
    ip_origen       VARCHAR(45),
    agente_usuario  VARCHAR(255),       -- User-Agent del navegador/app
    activa          BOOLEAN     NOT NULL DEFAULT TRUE,
    creado_en       TIMESTAMP   NOT NULL DEFAULT NOW(),
    expira_en       TIMESTAMP   NOT NULL,
    cerrado_en      TIMESTAMP
);

COMMENT ON TABLE sesiones IS 'Control de sesiones activas. Permite invalidar sesiones desde el sistema.';


-- =============================================================================
-- ÍNDICES — Performance en consultas frecuentes
-- =============================================================================

-- Usuarios
CREATE INDEX idx_usuarios_correo         ON usuarios(correo);
CREATE INDEX idx_usuarios_tipo           ON usuarios(tipo);
CREATE INDEX idx_usuarios_estado         ON usuarios(estado);

-- Consultores
CREATE INDEX idx_consultores_usuario     ON consultores(usuario_id);
CREATE INDEX idx_consultores_estado      ON consultores(estado);

-- Colaboradores
CREATE INDEX idx_colaboradores_consultor ON colaboradores(consultor_id);
CREATE INDEX idx_colaboradores_cargo     ON colaboradores(cargo);
CREATE INDEX idx_colaboradores_estado    ON colaboradores(estado);

-- Empresas
CREATE INDEX idx_empresas_consultor      ON empresas(consultor_id);
CREATE INDEX idx_empresas_nit            ON empresas(nit);
CREATE INDEX idx_empresas_estado         ON empresas(estado);

-- Asignación colaborador-empresa
CREATE INDEX idx_colab_emp_colaborador   ON colaborador_empresa(colaborador_id);
CREATE INDEX idx_colab_emp_empresa       ON colaborador_empresa(empresa_id);
CREATE INDEX idx_colab_emp_activo        ON colaborador_empresa(activo);

-- Permisos colaborador
CREATE INDEX idx_permisos_colaborador    ON colaborador_permisos(colaborador_id);

-- Personal
CREATE INDEX idx_personal_empresa        ON personal(empresa_id);
CREATE INDEX idx_personal_ci             ON personal(ci);
CREATE INDEX idx_personal_estado         ON personal(estado);
CREATE INDEX idx_personal_cargo          ON personal(cargo);

-- Documentos
CREATE INDEX idx_documentos_personal     ON documentos(personal_id);
CREATE INDEX idx_documentos_modulo       ON documentos(modulo);
CREATE INDEX idx_documentos_tipo         ON documentos(tipo_documento_id);
CREATE INDEX idx_documentos_vigente      ON documentos(es_vigente) WHERE eliminado = FALSE;
CREATE INDEX idx_documentos_periodo      ON documentos(periodo);

-- Alertas
CREATE INDEX idx_alertas_consultor       ON alertas(consultor_id);
CREATE INDEX idx_alertas_empresa         ON alertas(empresa_id);
CREATE INDEX idx_alertas_resuelta        ON alertas(resuelta);
CREATE INDEX idx_alertas_vencimiento     ON alertas(fecha_vencimiento) WHERE resuelta = FALSE;

-- Log de actividad
CREATE INDEX idx_log_usuario             ON actividad_log(usuario_id);
CREATE INDEX idx_log_entidad             ON actividad_log(entidad, entidad_id);
CREATE INDEX idx_log_fecha               ON actividad_log(creado_en DESC);

-- Sesiones
CREATE INDEX idx_sesiones_usuario        ON sesiones(usuario_id);
CREATE INDEX idx_sesiones_activa         ON sesiones(activa) WHERE activa = TRUE;


-- =============================================================================
-- TRIGGERS — Actualización automática de timestamps
-- =============================================================================

CREATE OR REPLACE FUNCTION fn_actualizar_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.actualizado_en = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER tg_usuarios_actualizado
    BEFORE UPDATE ON usuarios
    FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();

CREATE TRIGGER tg_consultores_actualizado
    BEFORE UPDATE ON consultores
    FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();

CREATE TRIGGER tg_colaboradores_actualizado
    BEFORE UPDATE ON colaboradores
    FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();

CREATE TRIGGER tg_empresas_actualizado
    BEFORE UPDATE ON empresas
    FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();

CREATE TRIGGER tg_personal_actualizado
    BEFORE UPDATE ON personal
    FOR EACH ROW EXECUTE FUNCTION fn_actualizar_timestamp();


-- =============================================================================
-- DATOS SEMILLA (SEED DATA)
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- S1. Tipos de documento por módulo AFP
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO tipos_documento (modulo, nombre, descripcion, obligatorio, formatos_permitidos, tamano_maximo_mb) VALUES
-- AFP
('afp', 'Planilla de Aportes AFP',
    'Planilla mensual con el detalle de aportes al fondo de pensiones del empleado.',
    TRUE,  'pdf,xlsx', 10),
('afp', 'Comprobante de Pago AFP',
    'Comprobante o recibo de pago de los aportes AFP del período correspondiente.',
    TRUE,  'pdf,xlsx,jpg,png', 10),
('afp', 'Ficha de Afiliación AFP',
    'Documento de alta/afiliación del empleado a la AFP seleccionada.',
    TRUE,  'pdf,jpg,png', 5),
('afp', 'Certificado de Saldo AFP',
    'Certificado emitido por la AFP con el saldo acumulado en la cuenta individual.',
    FALSE, 'pdf', 5),
-- CAJA
('caja', 'Formulario de Afiliación CNS',
    'Formulario oficial de afiliación del empleado a la Caja Nacional de Salud u otra caja.',
    TRUE,  'pdf,jpg,png', 5),
('caja', 'Póliza Mensual CAJA',
    'Póliza o extracto mensual del seguro de salud del empleado.',
    TRUE,  'pdf,xlsx', 10),
('caja', 'Carnet de Asegurado',
    'Carnet físico o digital del asegurado emitido por la caja correspondiente.',
    FALSE, 'pdf,jpg,png', 5),
('caja', 'Declaración de Beneficiarios',
    'Formulario con la declaración de beneficiarios del seguro de salud.',
    FALSE, 'pdf', 5),
-- MINISTERIO DE TRABAJO
('ministerio', 'Contrato de Trabajo',
    'Contrato laboral firmado y registrado ante el Ministerio de Trabajo.',
    TRUE,  'pdf,docx', 10),
('ministerio', 'Planilla Laboral Mensual',
    'Planilla mensual con detalle de horas, salarios y descuentos presentada al Ministerio.',
    TRUE,  'pdf,xlsx', 10),
('ministerio', 'Certificación Laboral',
    'Certificado de relación laboral emitido o validado por el Ministerio de Trabajo.',
    FALSE, 'pdf', 5),
('ministerio', 'Liquidación de Beneficios',
    'Documento de liquidación final de beneficios sociales al término de la relación laboral.',
    FALSE, 'pdf,docx', 10),
('ministerio', 'Comprobante de Registro MT',
    'Comprobante de registro del contrato ante el Ministerio de Trabajo.',
    TRUE,  'pdf,jpg,png', 5);


-- ─────────────────────────────────────────────────────────────────────────────
-- S2. Usuario administrador inicial
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO usuarios (nombre_usuario, correo, contrasena_hash, tipo, verificado)
VALUES (
    'admin_sistema',
    'admin@laboraconsult.bo',
    -- Hash bcrypt de 'Admin#2026!' — CAMBIAR EN PRODUCCIÓN
    '$2a$12$exampleHashAdminXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
    'administrador',
    TRUE
);

INSERT INTO administradores (usuario_id, nombres, apellidos, telefono)
VALUES (
    (SELECT id FROM usuarios WHERE nombre_usuario = 'admin_sistema'),
    'Administrador',
    'Sistema',
    '+591 70000000'
);


-- ─────────────────────────────────────────────────────────────────────────────
-- S3. Consultor de ejemplo
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO usuarios (nombre_usuario, correo, contrasena_hash, tipo, verificado)
VALUES (
    'c.ruiz',
    'c.ruiz@laboraconsult.bo',
    '$2a$12$exampleHashConsultorXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
    'consultor',
    TRUE
);

INSERT INTO consultores (usuario_id, registrado_por, nombres, apellidos, ci, extension_ci, telefono, ciudad, departamento, razon_social)
VALUES (
    (SELECT id FROM usuarios WHERE nombre_usuario = 'c.ruiz'),
    (SELECT id FROM administradores LIMIT 1),
    'Carlos',
    'Ruiz Mamani',
    '5678901',
    'SC',
    '+591 73456789',
    'Santa Cruz de la Sierra',
    'Santa Cruz',
    'Ruiz Consultoría Laboral'
);


-- ─────────────────────────────────────────────────────────────────────────────
-- S4. Colaboradores del consultor de ejemplo
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO usuarios (nombre_usuario, correo, contrasena_hash, tipo, verificado) VALUES
    ('l.vasquez',   'l.vasquez@laboraconsult.bo',   '$2a$12$hashColabXXXXXXXXXX1', 'colaborador', TRUE),
    ('r.mendoza',   'r.mendoza@laboraconsult.bo',   '$2a$12$hashColabXXXXXXXXXX2', 'colaborador', TRUE),
    ('p.quispe',    'p.quispe@laboraconsult.bo',    '$2a$12$hashColabXXXXXXXXXX3', 'colaborador', TRUE),
    ('j.torrico',   'j.torrico@laboraconsult.bo',   '$2a$12$hashColabXXXXXXXXXX4', 'colaborador', FALSE),
    ('a.miranda',   'a.miranda@laboraconsult.bo',   '$2a$12$hashColabXXXXXXXXXX5', 'colaborador', TRUE),
    ('d.heredia',   'd.heredia@laboraconsult.bo',   '$2a$12$hashColabXXXXXXXXXX6', 'colaborador', TRUE);

-- Insertar perfiles de colaboradores
WITH consultor AS (SELECT id FROM consultores WHERE ci = '5678901')
INSERT INTO colaboradores (consultor_id, usuario_id, nombres, apellidos, ci, extension_ci, telefono, cargo, fecha_ingreso, estado) VALUES
    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'l.vasquez'),
     'Laura',    'Vásquez Salinas',  '7654321', 'SC', '+591 73456789', 'coordinador_general',      '2024-02-10', 'activo'),
    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'r.mendoza'),
     'Ricardo',  'Mendoza Quispe',   '8765432', 'LP', '+591 72345678', 'analista_afp',             '2024-05-01', 'activo'),
    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'p.quispe'),
     'Patricia', 'Quispe Torrez',    '9876543', 'CB', '+591 71234567', 'analista_caja',            '2024-05-01', 'activo'),
    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'j.torrico'),
     'Jorge',    'Torrico Bernal',   '3456789', 'SC', '+591 70123456', 'analista_ministerio',      '2024-08-15', 'activo'),
    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'a.miranda'),
     'Ana',      'Miranda Flores',   '4567890', 'SC', '+591 79012345', 'asistente_administrativo', '2025-01-10', 'activo'),
    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'd.heredia'),
     'Diego',    'Heredia Rojas',    '2345678', 'SC', '+591 78901234', 'supervisor_gestion',       '2024-11-01', 'suspendido');

-- Suspender acceso de Diego Heredia también en usuarios
UPDATE usuarios SET estado = 'suspendido' WHERE nombre_usuario = 'd.heredia';


-- ─────────────────────────────────────────────────────────────────────────────
-- S5. Permisos por defecto según cargo (coordinador = todo, analistas = su módulo)
-- ─────────────────────────────────────────────────────────────────────────────

-- Coordinadora General (Laura): acceso completo a los 3 módulos
INSERT INTO colaborador_permisos
    (colaborador_id, modulo,
     puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos,
     puede_gestionar_modulo, puede_exportar_reportes, puede_invitar_empresa)
SELECT c.id, m.modulo,
       TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE
FROM colaboradores c
CROSS JOIN (VALUES ('afp'::tipo_modulo), ('caja'::tipo_modulo), ('ministerio'::tipo_modulo)) AS m(modulo)
WHERE c.ci = '7654321';

-- Analista AFP (Ricardo): solo módulo AFP, sin eliminar ni invitar
INSERT INTO colaborador_permisos
    (colaborador_id, modulo,
     puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos,
     puede_gestionar_modulo, puede_exportar_reportes, puede_invitar_empresa)
SELECT c.id, 'afp',
       TRUE, TRUE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE
FROM colaboradores c WHERE c.ci = '8765432';

-- Analista CAJA (Patricia): solo módulo CAJA
INSERT INTO colaborador_permisos
    (colaborador_id, modulo,
     puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos,
     puede_gestionar_modulo, puede_exportar_reportes, puede_invitar_empresa)
SELECT c.id, 'caja',
       TRUE, TRUE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE
FROM colaboradores c WHERE c.ci = '9876543';

-- Analista Ministerio (Jorge): solo módulo ministerio
INSERT INTO colaborador_permisos
    (colaborador_id, modulo,
     puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos,
     puede_gestionar_modulo, puede_exportar_reportes, puede_invitar_empresa)
SELECT c.id, 'ministerio',
       TRUE, TRUE, TRUE, TRUE, FALSE, TRUE, TRUE, FALSE
FROM colaboradores c WHERE c.ci = '3456789';

-- Asistente Administrativo (Ana): puede ver y subir en todos, sin gestionar ni invitar
INSERT INTO colaborador_permisos
    (colaborador_id, modulo,
     puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos,
     puede_gestionar_modulo, puede_exportar_reportes, puede_invitar_empresa)
SELECT c.id, m.modulo,
       TRUE, FALSE, FALSE, TRUE, FALSE, FALSE, FALSE, FALSE
FROM colaboradores c
CROSS JOIN (VALUES ('afp'::tipo_modulo), ('caja'::tipo_modulo), ('ministerio'::tipo_modulo)) AS m(modulo)
WHERE c.ci = '4567890';


-- ─────────────────────────────────────────────────────────────────────────────
-- S6. Empresas del consultor de ejemplo
-- ─────────────────────────────────────────────────────────────────────────────

-- Usuarios de acceso empresa
INSERT INTO usuarios (nombre_usuario, correo, contrasena_hash, tipo, verificado) VALUES
    ('emp_constructora_norte',  'acceso@constructoranorte.bo',  '$2a$12$hashEmpXX1', 'empresa', TRUE),
    ('emp_importadora_central',  'acceso@importadoracentral.bo', '$2a$12$hashEmpXX2', 'empresa', TRUE),
    ('emp_agro_beni',           'acceso@agrobeni.bo',           '$2a$12$hashEmpXX3', 'empresa', TRUE),
    ('emp_techsoft',            'acceso@techsoft.bo',           '$2a$12$hashEmpXX4', 'empresa', TRUE),
    ('emp_mineria_cerro',       'acceso@mineriacerro.bo',       '$2a$12$hashEmpXX5', 'empresa', TRUE),
    ('emp_farmacia_salud',      'acceso@farmaciasalud.bo',      '$2a$12$hashEmpXX6', 'empresa', TRUE);

WITH consultor AS (SELECT id FROM consultores WHERE ci = '5678901')
INSERT INTO empresas
    (consultor_id, usuario_id, nombre, nit, razon_social, ciudad, departamento, telefono, correo_empresa, representante_legal, actividad_economica, estado)
VALUES
    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_constructora_norte'),
     'Constructora Norte SA',  '5678901001', 'Constructora Norte Sociedad Anónima',
     'Santa Cruz', 'Santa Cruz', '+591 3 3456789', 'info@constructoranorte.bo',
     'Roberto Salinas', 'Construcción de obras civiles', 'activo'),

    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_importadora_central'),
     'Importadora Central',    '3456789001', 'Importadora Central SRL',
     'La Paz', 'La Paz', '+591 2 2345678', 'contacto@importadoracentral.bo',
     'Gloria Mendez', 'Importación y distribución de mercadería', 'activo'),

    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_agro_beni'),
     'Agro Beni Ltda.',        '9012345001', 'Agro Beni Limitada',
     'Trinidad', 'Beni', '+591 3 4567890', 'gerencia@agrobeni.bo',
     'Marco Antelo', 'Producción agropecuaria', 'activo'),

    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_techsoft'),
     'TechSoft Bolivia',       '1234567001', 'TechSoft Bolivia SRL',
     'Cochabamba', 'Cochabamba', '+591 4 5678901', 'admin@techsoft.bo',
     'Diana Quiroga', 'Desarrollo de software y tecnología', 'activo'),

    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_mineria_cerro'),
     'Minería del Cerro',      '6789012001', 'Minería del Cerro SA',
     'Potosí', 'Potosí', '+591 2 6789012', 'info@mineriacerro.bo',
     'Juan Mamani', 'Extracción minera', 'activo'),

    ((SELECT id FROM consultor), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_farmacia_salud'),
     'Farmacia Salud',         '2345678001', 'Farmacia Salud SRL',
     'Oruro', 'Oruro', '+591 2 7890123', 'farmacia@saludbo.com',
     'Elena Ticona', 'Venta de productos farmacéuticos', 'activo');


-- ─────────────────────────────────────────────────────────────────────────────
-- S7. Asignación de colaboradores a empresas
-- ─────────────────────────────────────────────────────────────────────────────
WITH
    consultor AS (SELECT id FROM consultores WHERE ci = '5678901'),
    laura     AS (SELECT c.id FROM colaboradores c WHERE c.ci = '7654321'),
    ricardo   AS (SELECT c.id FROM colaboradores c WHERE c.ci = '8765432'),
    patricia  AS (SELECT c.id FROM colaboradores c WHERE c.ci = '9876543'),
    jorge     AS (SELECT c.id FROM colaboradores c WHERE c.ci = '3456789'),
    ana       AS (SELECT c.id FROM colaboradores c WHERE c.ci = '4567890'),
    cn        AS (SELECT id FROM empresas WHERE nit = '5678901001'),
    ic        AS (SELECT id FROM empresas WHERE nit = '3456789001'),
    ab        AS (SELECT id FROM empresas WHERE nit = '9012345001'),
    ts        AS (SELECT id FROM empresas WHERE nit = '1234567001'),
    mc        AS (SELECT id FROM empresas WHERE nit = '6789012001'),
    fs        AS (SELECT id FROM empresas WHERE nit = '2345678001')
INSERT INTO colaborador_empresa (colaborador_id, empresa_id, asignado_por) VALUES
    -- Laura (coordinadora): todas las empresas
    ((SELECT id FROM laura), (SELECT id FROM cn), (SELECT id FROM consultor)),
    ((SELECT id FROM laura), (SELECT id FROM ic), (SELECT id FROM consultor)),
    ((SELECT id FROM laura), (SELECT id FROM ab), (SELECT id FROM consultor)),
    ((SELECT id FROM laura), (SELECT id FROM ts), (SELECT id FROM consultor)),
    ((SELECT id FROM laura), (SELECT id FROM mc), (SELECT id FROM consultor)),
    ((SELECT id FROM laura), (SELECT id FROM fs), (SELECT id FROM consultor)),
    -- Ricardo (AFP): Agro Beni, Minería, TechSoft
    ((SELECT id FROM ricardo), (SELECT id FROM ab), (SELECT id FROM consultor)),
    ((SELECT id FROM ricardo), (SELECT id FROM mc), (SELECT id FROM consultor)),
    ((SELECT id FROM ricardo), (SELECT id FROM ts), (SELECT id FROM consultor)),
    -- Patricia (CAJA): Importadora, Farmacia
    ((SELECT id FROM patricia), (SELECT id FROM ic), (SELECT id FROM consultor)),
    ((SELECT id FROM patricia), (SELECT id FROM fs), (SELECT id FROM consultor)),
    -- Jorge (Ministerio): Constructora, Agro Beni
    ((SELECT id FROM jorge),   (SELECT id FROM cn), (SELECT id FROM consultor)),
    ((SELECT id FROM jorge),   (SELECT id FROM ab), (SELECT id FROM consultor)),
    -- Ana (Asistente): TechSoft, Farmacia
    ((SELECT id FROM ana),     (SELECT id FROM ts), (SELECT id FROM consultor)),
    ((SELECT id FROM ana),     (SELECT id FROM fs), (SELECT id FROM consultor));


-- ─────────────────────────────────────────────────────────────────────────────
-- S8. Personal de ejemplo (empresa Constructora Norte)
-- ─────────────────────────────────────────────────────────────────────────────
WITH
    emp AS (SELECT id FROM empresas WHERE nit = '5678901001'),
    reg AS (SELECT c.id FROM colaboradores c WHERE c.ci = '7654321')  -- Laura registra
INSERT INTO personal
    (empresa_id, registrado_por, nombres, apellidos, ci, extension_ci,
     fecha_nacimiento, genero, estado_civil, telefono, correo,
     cargo, fecha_ingreso, tipo_contrato, salario_mensual, modalidad, nivel_educacion, profesion)
VALUES
    ((SELECT id FROM emp), (SELECT id FROM reg),
     'Juan Carlos', 'Mamani Quispe', '8234567', 'SC',
     '1988-04-15', 'masculino', 'casado', '+591 72345678', 'j.mamani@mail.com',
     'Ingeniero Civil', '2022-01-15', 'indefinido', 5800.00, 'presencial', 'universitario', 'Ingeniería Civil'),

    ((SELECT id FROM emp), (SELECT id FROM reg),
     'María Elena', 'López Vaca', '5678901', 'SC',
     '1991-09-23', 'femenino', 'soltera', '+591 71234567', 'm.lopez@mail.com',
     'Contadora', '2022-03-01', 'indefinido', 4500.00, 'presencial', 'universitario', 'Contaduría Pública'),

    ((SELECT id FROM emp), (SELECT id FROM reg),
     'Pedro', 'Cruz Villanueva', '4567890', 'SC',
     '1985-07-10', 'masculino', 'casado', '+591 70123456', 'p.cruz@mail.com',
     'Supervisor de Obra', '2021-06-01', 'indefinido', 6200.00, 'presencial', 'tecnico', 'Construcción Civil'),

    ((SELECT id FROM emp), (SELECT id FROM reg),
     'Ana Lucía', 'Quispe Torrez', '7890123', 'SC',
     '1993-12-05', 'femenino', 'soltera', '+591 79012345', 'a.quispe@mail.com',
     'Arquitecta', '2023-02-14', 'plazo_fijo', 5200.00, 'presencial', 'universitario', 'Arquitectura');


-- ─────────────────────────────────────────────────────────────────────────────
-- S9. Datos AFP / CAJA / Ministerio del personal de ejemplo
-- ─────────────────────────────────────────────────────────────────────────────

-- AFP
WITH colab AS (SELECT c.id FROM colaboradores c WHERE c.ci = '7654321')
INSERT INTO personal_afp (personal_id, afp_nombre, numero_afiliado, fecha_afiliacion, estado, actualizado_por)
SELECT p.id,
       CASE p.ci
           WHEN '8234567' THEN 'Futuro de Bolivia'
           WHEN '5678901' THEN 'BBVA Previsión AFP'
           WHEN '4567890' THEN 'Futuro de Bolivia'
           WHEN '7890123' THEN 'Futuro de Bolivia'
       END,
       'AFP-00' || p.ci,
       p.fecha_ingreso,
       CASE p.ci
           WHEN '8234567' THEN 'al_dia'
           WHEN '5678901' THEN 'al_dia'
           WHEN '4567890' THEN 'al_dia'
           WHEN '7890123' THEN 'pendiente'
       END::estado_cumplimiento,
       (SELECT id FROM colab)
FROM personal p
WHERE p.empresa_id = (SELECT id FROM empresas WHERE nit = '5678901001');

-- CAJA
WITH colab AS (SELECT c.id FROM colaboradores c WHERE c.ci = '9876543')
INSERT INTO personal_caja (personal_id, caja_nombre, numero_asegurado, fecha_afiliacion, estado, actualizado_por)
SELECT p.id,
       'Caja Nacional de Salud',
       'CNS-' || p.ci,
       p.fecha_ingreso,
       CASE p.ci
           WHEN '8234567' THEN 'al_dia'
           WHEN '5678901' THEN 'sin_datos'   -- María sin datos de CAJA
           WHEN '4567890' THEN 'al_dia'
           WHEN '7890123' THEN 'al_dia'
       END::estado_cumplimiento,
       (SELECT id FROM colab)
FROM personal p
WHERE p.empresa_id = (SELECT id FROM empresas WHERE nit = '5678901001');

-- Ministerio de Trabajo
WITH colab AS (SELECT c.id FROM colaboradores c WHERE c.ci = '3456789')
INSERT INTO personal_ministerio (personal_id, numero_registro_mt, fecha_registro, tipo_contrato_mt, estado, actualizado_por)
SELECT p.id,
       'MT-SC-00' || p.ci,
       p.fecha_ingreso,
       p.tipo_contrato::TEXT,
       CASE p.ci
           WHEN '8234567' THEN 'pendiente'   -- Juan: planilla del mes pendiente
           WHEN '5678901' THEN 'sin_datos'
           WHEN '4567890' THEN 'al_dia'
           WHEN '7890123' THEN 'pendiente'
       END::estado_cumplimiento,
       (SELECT id FROM colab)
FROM personal p
WHERE p.empresa_id = (SELECT id FROM empresas WHERE nit = '5678901001');


-- ─────────────────────────────────────────────────────────────────────────────
-- S10. Alertas de ejemplo
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO alertas (consultor_id, empresa_id, personal_id, modulo, nivel, titulo, descripcion, fecha_vencimiento, generada_auto)
SELECT
    con.id,
    emp.id,
    per.id,
    'ministerio',
    'urgente',
    'Planilla MT de ' || per.nombres || ' ' || per.apellidos || ' vence hoy',
    'La planilla laboral mensual del Ministerio de Trabajo para el período actual no ha sido cargada.',
    CURRENT_DATE,
    TRUE
FROM consultores con
JOIN empresas emp ON emp.consultor_id = con.id AND emp.nit = '5678901001'
JOIN personal per ON per.empresa_id = emp.id AND per.ci = '8234567'
WHERE con.ci = '5678901';

INSERT INTO alertas (consultor_id, empresa_id, modulo, nivel, titulo, descripcion, fecha_vencimiento, generada_auto)
SELECT
    con.id, emp.id,
    'caja', 'normal',
    'Constructora Norte: 3 empleados sin CAJA actualizada',
    'Existen empleados sin póliza CNS del período actual registrada en el sistema.',
    CURRENT_DATE + 2,
    TRUE
FROM consultores con
JOIN empresas emp ON emp.consultor_id = con.id AND emp.nit = '5678901001'
WHERE con.ci = '5678901';


-- =============================================================================
-- VISTAS ÚTILES
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- V1. Estado de cumplimiento por personal (resumen AFP/CAJA/Ministerio)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE VIEW v_cumplimiento_personal AS
SELECT
    p.id                        AS personal_id,
    p.nombres || ' ' || p.apellidos AS nombre_completo,
    p.ci,
    p.cargo,
    p.estado                    AS estado_personal,
    e.nombre                    AS empresa,
    e.id                        AS empresa_id,
    con.id                      AS consultor_id,
    COALESCE(afp.estado,  'sin_datos'::estado_cumplimiento) AS estado_afp,
    COALESCE(cja.estado,  'sin_datos'::estado_cumplimiento) AS estado_caja,
    COALESCE(mt.estado,   'sin_datos'::estado_cumplimiento) AS estado_ministerio,
    afp.afp_nombre,
    afp.numero_afiliado,
    cja.caja_nombre,
    cja.numero_asegurado
FROM personal p
JOIN empresas e        ON e.id = p.empresa_id
JOIN consultores con   ON con.id = e.consultor_id
LEFT JOIN personal_afp       afp ON afp.personal_id = p.id
LEFT JOIN personal_caja      cja ON cja.personal_id = p.id
LEFT JOIN personal_ministerio mt  ON mt.personal_id  = p.id;

COMMENT ON VIEW v_cumplimiento_personal IS 'Vista consolidada del estado AFP/CAJA/Ministerio de cada empleado.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V2. Cobertura de gestiones por empresa (para dashboard del consultor)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE VIEW v_cobertura_empresa AS
SELECT
    e.id                    AS empresa_id,
    e.nombre                AS empresa,
    e.consultor_id,
    COUNT(p.id)             AS total_personal,
    -- AFP
    COUNT(CASE WHEN afp.estado = 'al_dia' THEN 1 END)       AS afp_al_dia,
    ROUND(COUNT(CASE WHEN afp.estado = 'al_dia' THEN 1 END) * 100.0
          / NULLIF(COUNT(p.id), 0), 1)                      AS pct_afp,
    -- CAJA
    COUNT(CASE WHEN cja.estado = 'al_dia' THEN 1 END)       AS caja_al_dia,
    ROUND(COUNT(CASE WHEN cja.estado = 'al_dia' THEN 1 END) * 100.0
          / NULLIF(COUNT(p.id), 0), 1)                      AS pct_caja,
    -- Ministerio
    COUNT(CASE WHEN mt.estado = 'al_dia' THEN 1 END)        AS ministerio_al_dia,
    ROUND(COUNT(CASE WHEN mt.estado = 'al_dia' THEN 1 END) * 100.0
          / NULLIF(COUNT(p.id), 0), 1)                      AS pct_ministerio
FROM empresas e
LEFT JOIN personal p              ON p.empresa_id = e.id AND p.estado = 'activo'
LEFT JOIN personal_afp       afp  ON afp.personal_id  = p.id
LEFT JOIN personal_caja      cja  ON cja.personal_id  = p.id
LEFT JOIN personal_ministerio mt   ON mt.personal_id   = p.id
GROUP BY e.id, e.nombre, e.consultor_id;

COMMENT ON VIEW v_cobertura_empresa IS 'Porcentaje de cobertura AFP/CAJA/Ministerio por empresa. Usada en el dashboard y en las cards de empresa.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V3. Documentos vigentes por personal y módulo
-- ─────────────────────────────────────────────────────────────────────────────
CREATE VIEW v_documentos_vigentes AS
SELECT
    d.id                    AS documento_id,
    d.personal_id,
    p.nombres || ' ' || p.apellidos AS empleado,
    e.nombre                AS empresa,
    d.modulo,
    td.nombre               AS tipo_documento,
    td.obligatorio,
    d.nombre_archivo,
    d.formato,
    d.tamano_bytes,
    d.periodo,
    d.fecha_subida,
    u.nombre_usuario        AS subido_por_usuario
FROM documentos d
JOIN tipos_documento  td ON td.id = d.tipo_documento_id
JOIN personal          p ON p.id  = d.personal_id
JOIN empresas          e ON e.id  = p.empresa_id
LEFT JOIN colaboradores col ON col.id = d.subido_por
LEFT JOIN usuarios       u  ON u.id  = col.usuario_id
WHERE d.es_vigente = TRUE
  AND d.eliminado  = FALSE;

COMMENT ON VIEW v_documentos_vigentes IS 'Lista de documentos activos y vigentes, sin los eliminados ni las versiones anteriores.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V4. Resumen del equipo del consultor
-- ─────────────────────────────────────────────────────────────────────────────
CREATE VIEW v_equipo_consultor AS
SELECT
    col.id                      AS colaborador_id,
    col.consultor_id,
    col.nombres || ' ' || col.apellidos AS nombre_completo,
    u.correo,
    u.nombre_usuario,
    col.cargo,
    u.estado                    AS estado_acceso,
    u.ultimo_acceso,
    COUNT(DISTINCT ce.empresa_id) FILTER (WHERE ce.activo = TRUE) AS empresas_asignadas,
    COUNT(DISTINCT cp.modulo)   AS modulos_con_permiso
FROM colaboradores col
JOIN usuarios u              ON u.id  = col.usuario_id
LEFT JOIN colaborador_empresa ce ON ce.colaborador_id = col.id
LEFT JOIN colaborador_permisos cp ON cp.colaborador_id = col.id
GROUP BY col.id, col.consultor_id, col.nombres, col.apellidos,
         u.correo, u.nombre_usuario, col.cargo, u.estado, u.ultimo_acceso;

COMMENT ON VIEW v_equipo_consultor IS 'Resumen operativo del equipo del consultor: estado de acceso, empresas y módulos.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V5. Alertas activas con contexto (para el panel de pendientes)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE VIEW v_alertas_activas AS
SELECT
    a.id,
    a.consultor_id,
    a.nivel,
    a.titulo,
    a.descripcion,
    a.modulo,
    a.fecha_vencimiento,
    a.fecha_vencimiento - CURRENT_DATE  AS dias_para_vencer,
    e.nombre                            AS empresa,
    p.nombres || ' ' || p.apellidos     AS empleado,
    a.generada_auto,
    a.creado_en
FROM alertas a
LEFT JOIN empresas  e ON e.id = a.empresa_id
LEFT JOIN personal  p ON p.id = a.personal_id
WHERE a.resuelta = FALSE
ORDER BY
    CASE a.nivel WHEN 'urgente' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
    a.fecha_vencimiento ASC NULLS LAST;

COMMENT ON VIEW v_alertas_activas IS 'Alertas no resueltas ordenadas por urgencia y fecha de vencimiento.';


-- =============================================================================
-- FIN DEL SCRIPT
-- =============================================================================
