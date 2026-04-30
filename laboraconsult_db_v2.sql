-- =============================================================================
--  Consult-360 — Script de Base de Datos PostgreSQL v2.0
--  Sistema de Gestión Laboral (AFP / CAJA / Ministerio de Trabajo)
--
--  ARQUITECTURA CORREGIDA:
--    La unidad central es la EMPRESA CONSULTORA (firma registrada),
--    no un consultor individual. Cada empresa consultora tiene:
--      - Su propia configuración (logo, datos bancarios, plantilla de entrega)
--      - Su equipo de colaboradores (con cargos y permisos por módulo)
--      - Sus empresas cliente registradas
--
--  JERARQUÍA DE ACCESO:
--    1. Administrador    → Registra y gestiona empresas consultoras
--    2. Empresa Consultora / Colaborador → Gestiona empresas cliente y su personal
--    3. Empresa Cliente  → Solo lectura de su propia información
-- =============================================================================


-- ─────────────────────────────────────────────────────────────────────────────
-- 0. EXTENSIONES
-- ─────────────────────────────────────────────────────────────────────────────
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "unaccent";


-- ─────────────────────────────────────────────────────────────────────────────
-- 1. TIPOS ENUMERADOS
-- ─────────────────────────────────────────────────────────────────────────────

-- Roles del sistema
CREATE TYPE tipo_usuario AS ENUM (
    'administrador',    -- Superusuario del sistema
    'consultora',       -- Usuario principal de la empresa consultora (titular)
    'colaborador',      -- Miembro del equipo de la consultora
    'empresa_cliente'   -- Usuario de acceso de la empresa cliente (solo lectura)
);

-- Cargos dentro del equipo de una consultora
CREATE TYPE tipo_cargo_colaborador AS ENUM (
    'coordinador_general',
    'analista_afp',
    'analista_caja',
    'analista_ministerio',
    'asistente_administrativo',
    'supervisor_gestion'
);

-- Módulos de gestión laboral
CREATE TYPE tipo_modulo AS ENUM (
    'afp',
    'caja',
    'ministerio'
);

-- Estados genéricos de registros
CREATE TYPE estado_registro AS ENUM (
    'activo',
    'inactivo',
    'suspendido',
    'pendiente_activacion'
);

-- Estado operativo de la consultora
CREATE TYPE estado_consultora AS ENUM (
    'pendiente_activacion',   -- Creada por admin, sin activar
    'activo_sin_config',      -- Activada pero sin completar configuración
    'activo_operativo',       -- Completamente configurada y operativa
    'suspendida',
    'inactivo'
);

-- Estado de cumplimiento documental de un empleado en un módulo
CREATE TYPE estado_cumplimiento AS ENUM (
    'al_dia',       -- Todos los documentos obligatorios presentes y vigentes
    'pendiente',    -- Tiene documentos pero falta al menos uno obligatorio del período
    'vencido',      -- Tenía documentos pero el período venció sin actualización
    'sin_datos'     -- No se ha subido ningún documento
);

-- Tipo de contrato laboral (Bolivia)
CREATE TYPE tipo_contrato AS ENUM (
    'indefinido',
    'plazo_fijo',
    'por_obra',
    'eventual'
);

CREATE TYPE modalidad_trabajo AS ENUM (
    'presencial',
    'remoto',
    'hibrido'
);

CREATE TYPE tipo_genero AS ENUM (
    'masculino',
    'femenino',
    'no_especificado'
);

CREATE TYPE estado_civil AS ENUM (
    'soltero',
    'casado',
    'divorciado',
    'viudo',
    'concubinato'
);

CREATE TYPE nivel_educacion AS ENUM (
    'primaria',
    'secundaria',
    'tecnico',
    'universitario',
    'postgrado'
);

CREATE TYPE nivel_alerta AS ENUM (
    'informativo',
    'normal',
    'urgente'
);

-- Extensiones del CI boliviano
CREATE TYPE extension_ci AS ENUM (
    'SC', 'LP', 'CB', 'OR', 'PT', 'TJ', 'BE', 'CH', 'PD'
);

-- Tipos de cuenta bancaria
CREATE TYPE tipo_cuenta_bancaria AS ENUM (
    'caja_ahorro',
    'cuenta_corriente',
    'cuenta_dolar'
);


-- ─────────────────────────────────────────────────────────────────────────────
-- 2. USUARIOS — Tabla central de autenticación
--    Un único punto de login para todos los actores del sistema
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE usuarios (
    id                  SERIAL          PRIMARY KEY,
    nombre_usuario      VARCHAR(80)     NOT NULL UNIQUE,
    correo              VARCHAR(150)    NOT NULL UNIQUE,
    contrasena_hash     VARCHAR(255)    NOT NULL,
    tipo                tipo_usuario    NOT NULL,
    estado              estado_registro NOT NULL DEFAULT 'pendiente_activacion',
    verificado          BOOLEAN         NOT NULL DEFAULT FALSE,
    token_activacion    VARCHAR(255),
    token_activacion_exp TIMESTAMP,
    token_reset         VARCHAR(255),
    token_reset_exp     TIMESTAMP,
    ultimo_acceso       TIMESTAMP,
    ip_ultimo_acceso    INET,
    intentos_fallidos   SMALLINT        NOT NULL DEFAULT 0,
    bloqueado_hasta     TIMESTAMP,
    creado_en           TIMESTAMP       NOT NULL DEFAULT NOW(),
    actualizado_en      TIMESTAMP       NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  usuarios                      IS 'Tabla central de autenticación. Un registro por actor del sistema (admin, consultora, colaborador, empresa_cliente).';
COMMENT ON COLUMN usuarios.tipo                 IS 'Determina el perfil de acceso y las vistas disponibles en el frontend.';
COMMENT ON COLUMN usuarios.token_activacion     IS 'Token UUID generado al crear el usuario. Se invalida tras el primer uso o al expirar.';
COMMENT ON COLUMN usuarios.intentos_fallidos    IS 'Contador de logins fallidos consecutivos. Se reinicia a 0 en cada login exitoso.';
COMMENT ON COLUMN usuarios.bloqueado_hasta      IS 'Si no es NULL, el acceso está bloqueado temporalmente por demasiados intentos fallidos.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 3. ADMINISTRADORES
--    Perfil extendido del superusuario del sistema
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE administradores (
    id          SERIAL          PRIMARY KEY,
    usuario_id  INT             NOT NULL UNIQUE REFERENCES usuarios(id) ON DELETE CASCADE,
    nombres     VARCHAR(100)    NOT NULL,
    apellidos   VARCHAR(100)    NOT NULL,
    telefono    VARCHAR(20),
    creado_en   TIMESTAMP       NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE administradores IS 'Perfil del administrador del sistema. Tiene acceso total. Es el único que puede crear empresas consultoras.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 4. EMPRESAS CONSULTORAS
--    Registradas por el administrador. Son la entidad central del sistema.
--    Cada consultora gestiona N empresas clientes con su propio equipo.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE empresas_consultoras (
    id                      SERIAL              PRIMARY KEY,
    usuario_id              INT                 NOT NULL UNIQUE REFERENCES usuarios(id) ON DELETE CASCADE,
    registrada_por          INT                 NOT NULL REFERENCES administradores(id),

    -- Datos de la firma
    razon_social            VARCHAR(200)        NOT NULL,
    nombre_comercial        VARCHAR(150),
    nit                     VARCHAR(30)         NOT NULL UNIQUE,

    -- Representante legal / titular de la cuenta
    representante_nombres   VARCHAR(100)        NOT NULL,
    representante_apellidos VARCHAR(100)        NOT NULL,
    representante_ci        VARCHAR(20),
    representante_ext_ci    extension_ci,

    -- Contacto
    correo_principal        VARCHAR(150)        NOT NULL,
    telefono                VARCHAR(20),
    ciudad                  VARCHAR(100),
    departamento            VARCHAR(100),
    direccion               TEXT,

    -- Estado operativo
    estado                  estado_consultora   NOT NULL DEFAULT 'pendiente_activacion',
    configuracion_completa  BOOLEAN             NOT NULL DEFAULT FALSE,
    fecha_registro          DATE                NOT NULL DEFAULT CURRENT_DATE,
    observaciones           TEXT,
    creado_en               TIMESTAMP           NOT NULL DEFAULT NOW(),
    actualizado_en          TIMESTAMP           NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  empresas_consultoras                  IS 'Empresas consultoras registradas por el admin. Son la entidad central que gestiona empresas clientes.';
COMMENT ON COLUMN empresas_consultoras.estado           IS 'Ciclo: pendiente_activacion → activo_sin_config (activó cuenta) → activo_operativo (completó configuración).';
COMMENT ON COLUMN empresas_consultoras.configuracion_completa IS 'TRUE cuando la consultora ha completado logo, datos bancarios y plantilla de entrega. Solo entonces puede operar.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 5. CONFIGURACIÓN DE LA CONSULTORA
--    Perfil operativo de la consultora: identidad visual, datos bancarios
--    y plantilla de formulario de entrega de documentos.
--    Relación 1:1 con empresas_consultoras.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE configuracion_consultora (
    id                      SERIAL          PRIMARY KEY,
    consultora_id           INT             NOT NULL UNIQUE REFERENCES empresas_consultoras(id) ON DELETE CASCADE,

    -- Identidad visual
    logo_url                VARCHAR(500),
    color_marca             VARCHAR(7),         -- Hex color, ej: '#2563EB'
    correo_soporte          VARCHAR(150),       -- Correo visible para las empresas clientes
    telefono_soporte        VARCHAR(20),

    -- Datos bancarios (para cobros a empresas clientes)
    banco                   VARCHAR(100),
    nro_cuenta              VARCHAR(50),
    tipo_cuenta             tipo_cuenta_bancaria,
    titular_cuenta          VARCHAR(200),
    moneda                  CHAR(3) DEFAULT 'BOB',  -- BOB, USD

    -- Cuenta bancaria alternativa (opcional)
    banco_alt               VARCHAR(100),
    nro_cuenta_alt          VARCHAR(50),
    tipo_cuenta_alt         tipo_cuenta_bancaria,
    titular_cuenta_alt      VARCHAR(200),
    moneda_alt              CHAR(3),

    -- Plantilla del formulario de entrega de documentos
    -- Almacenada como JSON: array de { campo, tipo, obligatorio, descripcion }
    plantilla_entrega       JSONB NOT NULL DEFAULT '[]',

    -- Configuración de notificaciones
    notif_correo_alertas    BOOLEAN NOT NULL DEFAULT TRUE,
    notif_dias_anticipacion SMALLINT NOT NULL DEFAULT 3,  -- días antes del vencimiento

    -- Auditoría
    actualizado_en          TIMESTAMP NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  configuracion_consultora              IS 'Configuración operativa 1:1 de la consultora. Contiene identidad visual, datos bancarios y plantilla de entrega.';
COMMENT ON COLUMN configuracion_consultora.plantilla_entrega IS 'JSON con los campos del formulario de entrega: [{campo, tipo, obligatorio, descripcion}]. Configurable por la consultora.';
COMMENT ON COLUMN configuracion_consultora.color_marca  IS 'Color HEX para personalizar el portal de la empresa cliente (ej: #2563EB).';


-- ─────────────────────────────────────────────────────────────────────────────
-- 6. COLABORADORES — Equipo interno de la consultora
--    Cada colaborador es un usuario del sistema con cargo y permisos específicos.
--    Solo pueden acceder a las empresas clientes que les sean asignadas.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE colaboradores (
    id              SERIAL                  PRIMARY KEY,
    consultora_id   INT                     NOT NULL REFERENCES empresas_consultoras(id) ON DELETE CASCADE,
    usuario_id      INT                     NOT NULL UNIQUE REFERENCES usuarios(id) ON DELETE CASCADE,

    -- Datos personales
    nombres         VARCHAR(100)            NOT NULL,
    apellidos       VARCHAR(100)            NOT NULL,
    ci              VARCHAR(20),
    extension_ci    extension_ci,
    telefono        VARCHAR(20),

    -- Datos laborales dentro de la consultora
    cargo           tipo_cargo_colaborador  NOT NULL,
    fecha_ingreso   DATE,
    estado          estado_registro         NOT NULL DEFAULT 'activo',
    observaciones   TEXT,

    creado_en       TIMESTAMP               NOT NULL DEFAULT NOW(),
    actualizado_en  TIMESTAMP               NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  colaboradores         IS 'Equipo interno de la consultora. Cada colaborador tiene un cargo que determina sus permisos base.';
COMMENT ON COLUMN colaboradores.cargo   IS 'El cargo define los permisos iniciales. Un coordinador_general los tiene todos; los analistas solo tienen acceso a su módulo.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 7. PERMISOS DE COLABORADOR POR MÓDULO
--    Control granular: una fila por colaborador por módulo.
--    Los permisos aplican SOLO sobre las empresas clientes asignadas.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE colaborador_permisos (
    id                          SERIAL          PRIMARY KEY,
    colaborador_id              INT             NOT NULL REFERENCES colaboradores(id) ON DELETE CASCADE,
    modulo                      tipo_modulo     NOT NULL,

    -- Lectura
    puede_ver                   BOOLEAN         NOT NULL DEFAULT TRUE,

    -- Personal
    puede_registrar_personal    BOOLEAN         NOT NULL DEFAULT FALSE,
    puede_editar_personal       BOOLEAN         NOT NULL DEFAULT FALSE,

    -- Documentos
    puede_subir_documentos      BOOLEAN         NOT NULL DEFAULT FALSE,
    puede_eliminar_documentos   BOOLEAN         NOT NULL DEFAULT FALSE,

    -- Gestión específica del módulo
    puede_gestionar_modulo      BOOLEAN         NOT NULL DEFAULT FALSE,

    -- Reportes
    puede_exportar_reportes     BOOLEAN         NOT NULL DEFAULT FALSE,

    -- Acceso avanzado
    puede_invitar_empresa       BOOLEAN         NOT NULL DEFAULT FALSE,

    -- Metadatos
    configurado_por             INT             REFERENCES empresas_consultoras(id),
    actualizado_en              TIMESTAMP       NOT NULL DEFAULT NOW(),

    UNIQUE (colaborador_id, modulo)
);

COMMENT ON TABLE  colaborador_permisos              IS 'Permisos granulares por módulo para cada colaborador. Una fila por módulo (AFP, CAJA, Ministerio).';
COMMENT ON COLUMN colaborador_permisos.puede_invitar_empresa IS 'Permite que el colaborador genere credenciales de acceso para empresas clientes.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 8. EMPRESAS CLIENTES
--    Empresas registradas por la consultora (o sus colaboradores autorizados).
--    Pueden tener acceso de solo lectura al sistema (usuario_id).
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE empresas_cliente (
    id                      SERIAL          PRIMARY KEY,
    consultora_id           INT             NOT NULL REFERENCES empresas_consultoras(id) ON DELETE RESTRICT,
    usuario_id              INT             UNIQUE REFERENCES usuarios(id) ON DELETE SET NULL,
    registrada_por          INT             REFERENCES colaboradores(id) ON DELETE SET NULL,

    -- Datos de la empresa
    nombre                  VARCHAR(200)    NOT NULL,
    nit                     VARCHAR(30)     NOT NULL,
    razon_social            VARCHAR(200),
    ciudad                  VARCHAR(100),
    departamento            VARCHAR(100),
    direccion               TEXT,
    telefono                VARCHAR(20),
    correo_empresa          VARCHAR(150),
    actividad_economica     VARCHAR(200),
    matricula_comercio      VARCHAR(50),

    -- Representante legal
    rep_legal_nombres       VARCHAR(100),
    rep_legal_apellidos     VARCHAR(100),
    rep_legal_ci            VARCHAR(20),
    rep_legal_ext_ci        extension_ci,

    -- Estado
    estado                  estado_registro NOT NULL DEFAULT 'activo',
    fecha_registro          DATE            NOT NULL DEFAULT CURRENT_DATE,
    observaciones           TEXT,

    creado_en               TIMESTAMP       NOT NULL DEFAULT NOW(),
    actualizado_en          TIMESTAMP       NOT NULL DEFAULT NOW(),

    -- NIT único por consultora (dos consultoras distintas pueden tener la misma empresa)
    UNIQUE (consultora_id, nit)
);

COMMENT ON TABLE  empresas_cliente              IS 'Empresas clientes de la consultora. Una consultora puede eliminar sus empresas solo si no tienen personal activo.';
COMMENT ON COLUMN empresas_cliente.usuario_id   IS 'NULL si el acceso de la empresa no está activado. Se puebla al generar credenciales.';
COMMENT ON COLUMN empresas_cliente.registrada_por IS 'Colaborador que registró la empresa. NULL si fue la propia consultora (titular).';


-- ─────────────────────────────────────────────────────────────────────────────
-- 9. ASIGNACIÓN COLABORADOR ↔ EMPRESA CLIENTE
--    Un colaborador puede gestionar múltiples empresas cliente.
--    Una empresa puede ser gestionada por múltiples colaboradores.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE colaborador_empresa_cliente (
    id              SERIAL      PRIMARY KEY,
    colaborador_id  INT         NOT NULL REFERENCES colaboradores(id) ON DELETE CASCADE,
    empresa_id      INT         NOT NULL REFERENCES empresas_cliente(id) ON DELETE CASCADE,
    activo          BOOLEAN     NOT NULL DEFAULT TRUE,
    asignado_por    INT         REFERENCES empresas_consultoras(id),
    asignado_en     TIMESTAMP   NOT NULL DEFAULT NOW(),
    removido_en     TIMESTAMP,
    UNIQUE (colaborador_id, empresa_id)
);

COMMENT ON TABLE  colaborador_empresa_cliente IS 'Pivote de asignación colaborador-empresa. activo=FALSE retira el acceso del colaborador a esa empresa sin borrar el historial.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 10. PERSONAL DE EMPRESA CLIENTE
--     Empleados registrados por la consultora o sus colaboradores.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE personal (
    id                  SERIAL              PRIMARY KEY,
    empresa_id          INT                 NOT NULL REFERENCES empresas_cliente(id) ON DELETE RESTRICT,
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

    creado_en           TIMESTAMP           NOT NULL DEFAULT NOW(),
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW(),

    UNIQUE (empresa_id, ci)
);

COMMENT ON TABLE  personal              IS 'Empleados de cada empresa cliente. El CI es único dentro de cada empresa.';
COMMENT ON COLUMN personal.fecha_egreso IS 'Si no es NULL, el empleado ya no trabaja en la empresa. El estado pasa a inactivo.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 11. DATOS AFP DEL PERSONAL
--     Información de afiliación al sistema de pensiones boliviano.
--     Relación 1:1 con personal. Se crea automáticamente al registrar el empleado.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE personal_afp (
    id                  SERIAL              PRIMARY KEY,
    personal_id         INT                 NOT NULL UNIQUE REFERENCES personal(id) ON DELETE CASCADE,
    afp_nombre          VARCHAR(100),        -- 'Futuro de Bolivia' o 'BBVA Previsión AFP'
    numero_afiliado     VARCHAR(50),
    fecha_afiliacion    DATE,
    estado              estado_cumplimiento NOT NULL DEFAULT 'sin_datos',
    observaciones       TEXT,
    actualizado_por     INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  personal_afp         IS 'Datos AFP del empleado. Se crea con estado=sin_datos al registrar el personal. AFP Bolivia: Futuro de Bolivia o BBVA Previsión.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 12. DATOS CAJA DEL PERSONAL
--     Información del seguro de salud (Caja).
--     Relación 1:1 con personal.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE personal_caja (
    id                  SERIAL              PRIMARY KEY,
    personal_id         INT                 NOT NULL UNIQUE REFERENCES personal(id) ON DELETE CASCADE,
    caja_nombre         VARCHAR(150),        -- CNS, Caja Petrolera, Caja Bancaria, COSSMIL, etc.
    numero_asegurado    VARCHAR(50),
    fecha_afiliacion    DATE,
    estado              estado_cumplimiento NOT NULL DEFAULT 'sin_datos',
    observaciones       TEXT,
    actualizado_por     INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  personal_caja         IS 'Datos CAJA del empleado. Cajas válidas en Bolivia: CNS, Caja Petrolera, Caja Bancaria, COSSMIL.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 13. DATOS MINISTERIO DE TRABAJO DEL PERSONAL
--     Información laboral registrada ante el Ministerio.
--     Relación 1:1 con personal.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE personal_ministerio (
    id                  SERIAL              PRIMARY KEY,
    personal_id         INT                 NOT NULL UNIQUE REFERENCES personal(id) ON DELETE CASCADE,
    numero_registro_mt  VARCHAR(50),
    fecha_registro      DATE,
    tipo_contrato_mt    VARCHAR(100),
    estado              estado_cumplimiento NOT NULL DEFAULT 'sin_datos',
    observaciones       TEXT,
    actualizado_por     INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    actualizado_en      TIMESTAMP           NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE personal_ministerio IS 'Datos del registro ante el Ministerio de Trabajo. Se crea con estado=sin_datos al registrar el personal.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 14. CATÁLOGO DE TIPOS DE DOCUMENTO
--     Define qué archivos se pueden/deben subir por módulo.
--     Administrado a nivel del sistema. Extensible por el admin.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE tipos_documento (
    id                  SERIAL          PRIMARY KEY,
    modulo              tipo_modulo     NOT NULL,
    nombre              VARCHAR(150)    NOT NULL,
    descripcion         TEXT,
    obligatorio         BOOLEAN         NOT NULL DEFAULT FALSE,
    es_periodico        BOOLEAN         NOT NULL DEFAULT FALSE,  -- TRUE = se requiere uno por mes (ej: planilla)
    formatos_permitidos VARCHAR(100)    NOT NULL DEFAULT 'pdf,xlsx,docx,jpg,png',
    tamano_maximo_mb    SMALLINT        NOT NULL DEFAULT 10,
    activo              BOOLEAN         NOT NULL DEFAULT TRUE,
    orden_visualizacion SMALLINT        NOT NULL DEFAULT 0,
    creado_en           TIMESTAMP       NOT NULL DEFAULT NOW(),
    UNIQUE (modulo, nombre)
);

COMMENT ON TABLE  tipos_documento           IS 'Catálogo del sistema de tipos de documento por módulo. Los obligatorios generan alertas automáticas si faltan.';
COMMENT ON COLUMN tipos_documento.es_periodico IS 'TRUE indica que el documento debe actualizarse mensualmente (ej: Planilla AFP, Póliza CAJA). El sistema verifica el período actual.';
COMMENT ON COLUMN tipos_documento.obligatorio  IS 'TRUE: si falta, el estado_cumplimiento del empleado no puede ser al_dia.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 15. DOCUMENTOS
--     Archivos subidos por módulo para cada empleado.
--     Versioning: es_vigente=FALSE en versiones anteriores del mismo tipo.
--     Soft delete: eliminado=TRUE, no se borra físicamente del storage.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE documentos (
    id                  SERIAL              PRIMARY KEY,
    personal_id         INT                 NOT NULL REFERENCES personal(id) ON DELETE CASCADE,
    tipo_documento_id   INT                 NOT NULL REFERENCES tipos_documento(id) ON DELETE RESTRICT,
    modulo              tipo_modulo         NOT NULL,

    -- Datos del archivo
    nombre_archivo      VARCHAR(255)        NOT NULL,
    nombre_original     VARCHAR(255)        NOT NULL,
    ruta_archivo        VARCHAR(500)        NOT NULL,
    formato             VARCHAR(10)         NOT NULL,
    tamano_bytes        BIGINT,

    -- Contexto temporal (para documentos periódicos)
    periodo             VARCHAR(20),        -- 'Enero 2026', 'Marzo 2026', etc.
    fecha_documento     DATE,

    -- Metadatos
    observacion         TEXT,
    es_vigente          BOOLEAN             NOT NULL DEFAULT TRUE,
    subido_por          INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    fecha_subida        TIMESTAMP           NOT NULL DEFAULT NOW(),

    -- Soft delete
    eliminado           BOOLEAN             NOT NULL DEFAULT FALSE,
    eliminado_por       INT                 REFERENCES colaboradores(id) ON DELETE SET NULL,
    eliminado_en        TIMESTAMP
);

COMMENT ON TABLE  documentos            IS 'Archivos subidos por módulo. Control de versiones con es_vigente. Soft delete con campo eliminado.';
COMMENT ON COLUMN documentos.es_vigente IS 'Al subir una nueva versión del mismo tipo+período, la anterior pasa a es_vigente=FALSE. Solo la vigente se muestra en la UI principal.';
COMMENT ON COLUMN documentos.ruta_archivo IS 'Ruta en el storage: /docs/{consultora_id}/{empresa_id}/{personal_id}/{modulo}/{nombre_archivo}.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 16. ALERTAS Y PENDIENTES
--     Generadas automáticamente por el sistema (tarea diaria) o manualmente.
--     Vinculadas al consultor, opcionalmente a empresa y/o empleado.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE alertas (
    id                  SERIAL          PRIMARY KEY,
    consultora_id       INT             NOT NULL REFERENCES empresas_consultoras(id) ON DELETE CASCADE,
    empresa_id          INT             REFERENCES empresas_cliente(id) ON DELETE CASCADE,
    personal_id         INT             REFERENCES personal(id) ON DELETE CASCADE,
    colaborador_asignado INT            REFERENCES colaboradores(id) ON DELETE SET NULL,
    modulo              tipo_modulo,
    nivel               nivel_alerta    NOT NULL DEFAULT 'normal',
    titulo              VARCHAR(200)    NOT NULL,
    descripcion         TEXT,
    fecha_vencimiento   DATE,
    resuelta            BOOLEAN         NOT NULL DEFAULT FALSE,
    resuelta_por        INT             REFERENCES colaboradores(id) ON DELETE SET NULL,
    resuelta_en         TIMESTAMP,
    generada_auto       BOOLEAN         NOT NULL DEFAULT FALSE,
    creado_en           TIMESTAMP       NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  alertas                   IS 'Sistema de alertas y tareas pendientes. Las automáticas se generan diariamente por la tarea de evaluación de cumplimiento.';
COMMENT ON COLUMN alertas.colaborador_asignado IS 'Colaborador específico responsable de resolver esta alerta. NULL = alerta para toda la consultora.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 17. LOG DE AUDITORÍA
--     Registro inmutable de todas las acciones relevantes del sistema.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE actividad_log (
    id                  SERIAL          PRIMARY KEY,
    usuario_id          INT             NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    consultora_id       INT             REFERENCES empresas_consultoras(id) ON DELETE CASCADE,
    accion              VARCHAR(100)    NOT NULL,
    modulo              tipo_modulo,
    entidad             VARCHAR(50),
    entidad_id          INT,
    descripcion         TEXT,
    datos_anteriores    JSONB,
    datos_nuevos        JSONB,
    ip_origen           INET,
    creado_en           TIMESTAMP       NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  actividad_log IS 'Log de auditoría inmutable. Captura qué usuario hizo qué acción sobre qué entidad y cuándo, con snapshots JSON del estado antes y después.';


-- ─────────────────────────────────────────────────────────────────────────────
-- 18. SESIONES ACTIVAS
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE sesiones (
    id              SERIAL          PRIMARY KEY,
    usuario_id      INT             NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
    token_hash      VARCHAR(255)    NOT NULL UNIQUE,
    ip_origen       INET,
    agente_usuario  VARCHAR(500),
    activa          BOOLEAN         NOT NULL DEFAULT TRUE,
    creado_en       TIMESTAMP       NOT NULL DEFAULT NOW(),
    expira_en       TIMESTAMP       NOT NULL,
    cerrado_en      TIMESTAMP
);

COMMENT ON TABLE sesiones IS 'Control de sesiones JWT. Permite revocar accesos desde el panel de administración.';


-- =============================================================================
-- ÍNDICES — Optimización de consultas frecuentes
-- =============================================================================

-- Autenticación y sesiones
CREATE INDEX idx_usuarios_correo             ON usuarios(correo);
CREATE INDEX idx_usuarios_tipo               ON usuarios(tipo);
CREATE INDEX idx_usuarios_estado             ON usuarios(estado);
CREATE INDEX idx_sesiones_usuario            ON sesiones(usuario_id);
CREATE INDEX idx_sesiones_activa             ON sesiones(activa) WHERE activa = TRUE;

-- Empresas consultoras y colaboradores
CREATE INDEX idx_consultoras_estado          ON empresas_consultoras(estado);
CREATE INDEX idx_consultoras_nit             ON empresas_consultoras(nit);
CREATE INDEX idx_colaboradores_consultora    ON colaboradores(consultora_id);
CREATE INDEX idx_colaboradores_cargo         ON colaboradores(cargo);
CREATE INDEX idx_colaboradores_estado        ON colaboradores(estado);
CREATE INDEX idx_permisos_colaborador        ON colaborador_permisos(colaborador_id);

-- Empresas cliente
CREATE INDEX idx_emp_cliente_consultora      ON empresas_cliente(consultora_id);
CREATE INDEX idx_emp_cliente_nit             ON empresas_cliente(nit);
CREATE INDEX idx_emp_cliente_estado          ON empresas_cliente(estado);
CREATE INDEX idx_colab_emp_colaborador       ON colaborador_empresa_cliente(colaborador_id);
CREATE INDEX idx_colab_emp_empresa           ON colaborador_empresa_cliente(empresa_id);
CREATE INDEX idx_colab_emp_activo            ON colaborador_empresa_cliente(activo) WHERE activo = TRUE;

-- Personal
CREATE INDEX idx_personal_empresa            ON personal(empresa_id);
CREATE INDEX idx_personal_ci                 ON personal(ci);
CREATE INDEX idx_personal_estado             ON personal(estado);
CREATE INDEX idx_personal_registrado_por     ON personal(registrado_por);

-- Documentos
CREATE INDEX idx_documentos_personal         ON documentos(personal_id);
CREATE INDEX idx_documentos_modulo           ON documentos(modulo);
CREATE INDEX idx_documentos_tipo             ON documentos(tipo_documento_id);
CREATE INDEX idx_documentos_vigente          ON documentos(personal_id, tipo_documento_id)
    WHERE es_vigente = TRUE AND eliminado = FALSE;
CREATE INDEX idx_documentos_periodo          ON documentos(periodo);

-- Alertas
CREATE INDEX idx_alertas_consultora          ON alertas(consultora_id);
CREATE INDEX idx_alertas_empresa             ON alertas(empresa_id);
CREATE INDEX idx_alertas_personal            ON alertas(personal_id);
CREATE INDEX idx_alertas_pendientes          ON alertas(consultora_id, resuelta, nivel)
    WHERE resuelta = FALSE;
CREATE INDEX idx_alertas_vencimiento         ON alertas(fecha_vencimiento)
    WHERE resuelta = FALSE;

-- Log
CREATE INDEX idx_log_usuario                 ON actividad_log(usuario_id);
CREATE INDEX idx_log_consultora              ON actividad_log(consultora_id);
CREATE INDEX idx_log_entidad                 ON actividad_log(entidad, entidad_id);
CREATE INDEX idx_log_fecha                   ON actividad_log(creado_en DESC);


-- =============================================================================
-- TRIGGERS
-- =============================================================================

-- Función de actualización automática de timestamps
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


-- Función: al registrar personal, crear automáticamente las 3 fichas de gestión
CREATE OR REPLACE FUNCTION fn_crear_fichas_gestion()
RETURNS TRIGGER AS $$
BEGIN
    -- AFP
    INSERT INTO personal_afp  (personal_id) VALUES (NEW.id);
    -- CAJA
    INSERT INTO personal_caja (personal_id) VALUES (NEW.id);
    -- MINISTERIO
    INSERT INTO personal_ministerio (personal_id) VALUES (NEW.id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER tg_personal_fichas
    AFTER INSERT ON personal
    FOR EACH ROW EXECUTE FUNCTION fn_crear_fichas_gestion();

COMMENT ON FUNCTION fn_crear_fichas_gestion IS 'Al insertar un empleado, crea automáticamente los 3 registros de gestión (AFP, CAJA, Ministerio) con estado=sin_datos.';


-- Función: al crear una empresa consultora, crear su registro de configuración vacío
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

COMMENT ON FUNCTION fn_crear_config_consultora IS 'Al crear una empresa consultora, inicializa automáticamente su registro de configuración vacío.';


-- =============================================================================
-- DATOS SEMILLA (SEED DATA)
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- S1. Catálogo de tipos de documento
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO tipos_documento
    (modulo, nombre, descripcion, obligatorio, es_periodico, formatos_permitidos, tamano_maximo_mb, orden_visualizacion)
VALUES
-- ── AFP ──
('afp', 'Planilla de Aportes AFP',
 'Planilla mensual con el detalle de aportes al fondo de pensiones.',
 TRUE,  TRUE,  'pdf,xlsx', 10, 1),
('afp', 'Comprobante de Pago AFP',
 'Comprobante o recibo de pago de los aportes AFP del período.',
 TRUE,  TRUE,  'pdf,xlsx,jpg,png', 10, 2),
('afp', 'Ficha de Afiliación AFP',
 'Documento de alta del empleado a su AFP correspondiente.',
 TRUE,  FALSE, 'pdf,jpg,png', 5, 3),
('afp', 'Certificado de Saldo AFP',
 'Certificado emitido por la AFP con el saldo de la cuenta individual.',
 FALSE, FALSE, 'pdf', 5, 4),

-- ── CAJA ──
('caja', 'Formulario de Afiliación CNS',
 'Formulario oficial de afiliación del empleado a la caja de salud.',
 TRUE,  FALSE, 'pdf,jpg,png', 5, 1),
('caja', 'Póliza Mensual CAJA',
 'Póliza o extracto mensual del seguro de salud del empleado.',
 TRUE,  TRUE,  'pdf,xlsx', 10, 2),
('caja', 'Carnet de Asegurado',
 'Carnet digital o físico del asegurado emitido por la caja.',
 FALSE, FALSE, 'pdf,jpg,png', 5, 3),
('caja', 'Declaración de Beneficiarios',
 'Formulario con la declaración de beneficiarios del seguro de salud.',
 FALSE, FALSE, 'pdf', 5, 4),

-- ── MINISTERIO DE TRABAJO ──
('ministerio', 'Contrato de Trabajo',
 'Contrato laboral firmado y registrado ante el Ministerio de Trabajo.',
 TRUE,  FALSE, 'pdf,docx', 10, 1),
('ministerio', 'Planilla Laboral Mensual',
 'Planilla mensual con detalle de horas, salarios y descuentos.',
 TRUE,  TRUE,  'pdf,xlsx', 10, 2),
('ministerio', 'Comprobante de Registro MT',
 'Comprobante de inscripción del contrato ante el Ministerio de Trabajo.',
 TRUE,  FALSE, 'pdf,jpg,png', 5, 3),
('ministerio', 'Certificación Laboral',
 'Certificado de relación laboral emitido por el Ministerio.',
 FALSE, FALSE, 'pdf', 5, 4),
('ministerio', 'Liquidación de Beneficios',
 'Documento de liquidación de beneficios al término de la relación laboral.',
 FALSE, FALSE, 'pdf,docx', 10, 5);


-- ─────────────────────────────────────────────────────────────────────────────
-- S2. Usuario administrador del sistema
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO usuarios
    (nombre_usuario, correo, contrasena_hash, tipo, estado, verificado)
VALUES (
    'admin_sistema',
    'admin@laboraconsult.bo',
    -- REEMPLAZAR antes de producción: bcrypt de 'Admin#Labora2026!'
    '$2a$12$REEMPLAZAR_CON_HASH_REAL_EN_PRODUCCION_XXXXXXXXXXXXXXXXX',
    'administrador',
    'activo',
    TRUE
);

INSERT INTO administradores (usuario_id, nombres, apellidos, telefono)
VALUES (
    (SELECT id FROM usuarios WHERE nombre_usuario = 'admin_sistema'),
    'Administrador', 'Sistema', '+591 70000000'
);


-- ─────────────────────────────────────────────────────────────────────────────
-- S3. Empresa consultora de ejemplo: Ruiz Consultoría Laboral
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO usuarios
    (nombre_usuario, correo, contrasena_hash, tipo, estado, verificado)
VALUES (
    'ruiz_consultoria',
    'c.ruiz@laboraconsult.bo',
    '$2a$12$REEMPLAZAR_HASH_CONSULTORA_XXXXXXXXXXXXXXXXXXXXXXXXXXX',
    'consultora',
    'activo_operativo',  -- En seed asumimos ya activada (ver campo estado_registro)
    TRUE
);

-- Nota: el enum estado_registro no tiene 'activo_operativo',
-- ese estado está en empresas_consultoras. En usuarios, estado='activo'.
-- Corrección: actualizamos a 'activo'
UPDATE usuarios SET estado = 'activo'
WHERE nombre_usuario = 'ruiz_consultoria';

INSERT INTO empresas_consultoras (
    usuario_id, registrada_por,
    razon_social, nombre_comercial, nit,
    representante_nombres, representante_apellidos, representante_ci, representante_ext_ci,
    correo_principal, telefono, ciudad, departamento,
    estado, configuracion_completa, fecha_registro
) VALUES (
    (SELECT id FROM usuarios WHERE nombre_usuario = 'ruiz_consultoria'),
    (SELECT id FROM administradores LIMIT 1),
    'Ruiz Consultoría Laboral SRL', 'Ruiz Consultoría', '5678901',
    'Carlos', 'Ruiz Mamani', '5678901', 'SC',
    'c.ruiz@laboraconsult.bo', '+591 73456789', 'Santa Cruz de la Sierra', 'Santa Cruz',
    'activo_operativo', TRUE, CURRENT_DATE
);


-- ─────────────────────────────────────────────────────────────────────────────
-- S4. Configuración de la consultora
--     Nota: el trigger fn_crear_config_consultora ya creó el registro vacío.
--     Solo actualizamos con datos reales.
-- ─────────────────────────────────────────────────────────────────────────────
UPDATE configuracion_consultora
SET
    logo_url            = '/storage/logos/ruiz_consultoria_logo.png',
    color_marca         = '#2563EB',
    correo_soporte      = 'soporte@ruizconsultoria.bo',
    telefono_soporte    = '+591 73456789',
    banco               = 'Banco Nacional de Bolivia',
    nro_cuenta          = '1234567890123',
    tipo_cuenta         = 'caja_ahorro',
    titular_cuenta      = 'Ruiz Consultoría Laboral SRL',
    moneda              = 'BOB',
    plantilla_entrega   = '[
        {"campo": "fecha_entrega", "tipo": "fecha", "obligatorio": true, "descripcion": "Fecha de entrega de documentos"},
        {"campo": "periodo_gestion", "tipo": "texto", "obligatorio": true, "descripcion": "Período gestionado, ej: Enero 2026"},
        {"campo": "responsable_entrega", "tipo": "texto", "obligatorio": true, "descripcion": "Nombre del responsable"},
        {"campo": "empresa_cliente", "tipo": "auto", "obligatorio": true, "descripcion": "Auto-llenado con el nombre de la empresa"},
        {"campo": "observaciones", "tipo": "textarea", "obligatorio": false, "descripcion": "Observaciones adicionales"},
        {"campo": "firma_consultor", "tipo": "firma_digital", "obligatorio": true, "descripcion": "Firma digital del consultor responsable"}
    ]'::JSONB,
    notif_correo_alertas    = TRUE,
    notif_dias_anticipacion = 3
WHERE consultora_id = (SELECT id FROM empresas_consultoras WHERE nit = '5678901');


-- ─────────────────────────────────────────────────────────────────────────────
-- S5. Colaboradores de la consultora
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO usuarios
    (nombre_usuario, correo, contrasena_hash, tipo, estado, verificado)
VALUES
    ('l.vasquez',  'l.vasquez@laboraconsult.bo',  '$2a$12$HASH_COLAB_1_XXX', 'colaborador', 'activo', TRUE),
    ('r.mendoza',  'r.mendoza@laboraconsult.bo',  '$2a$12$HASH_COLAB_2_XXX', 'colaborador', 'activo', TRUE),
    ('p.quispe',   'p.quispe@laboraconsult.bo',   '$2a$12$HASH_COLAB_3_XXX', 'colaborador', 'activo', TRUE),
    ('j.torrico',  'j.torrico@laboraconsult.bo',  '$2a$12$HASH_COLAB_4_XXX', 'colaborador', 'activo', FALSE),
    ('a.miranda',  'a.miranda@laboraconsult.bo',  '$2a$12$HASH_COLAB_5_XXX', 'colaborador', 'activo', TRUE),
    ('d.heredia',  'd.heredia@laboraconsult.bo',  '$2a$12$HASH_COLAB_6_XXX', 'colaborador', 'suspendido', TRUE);

WITH con AS (SELECT id FROM empresas_consultoras WHERE nit = '5678901')
INSERT INTO colaboradores
    (consultora_id, usuario_id, nombres, apellidos, ci, extension_ci, telefono, cargo, fecha_ingreso, estado)
VALUES
    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'l.vasquez'),
     'Laura',    'Vásquez Salinas',  '7654321', 'SC', '+591 73456789', 'coordinador_general',      '2024-02-10', 'activo'),
    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'r.mendoza'),
     'Ricardo',  'Mendoza Quispe',   '8765432', 'LP', '+591 72345678', 'analista_afp',             '2024-05-01', 'activo'),
    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'p.quispe'),
     'Patricia', 'Quispe Torrez',    '9876543', 'CB', '+591 71234567', 'analista_caja',            '2024-05-01', 'activo'),
    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'j.torrico'),
     'Jorge',    'Torrico Bernal',   '3456789', 'SC', '+591 70123456', 'analista_ministerio',      '2024-08-15', 'activo'),
    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'a.miranda'),
     'Ana',      'Miranda Flores',   '4567890', 'SC', '+591 79012345', 'asistente_administrativo', '2025-01-10', 'activo'),
    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'd.heredia'),
     'Diego',    'Heredia Rojas',    '2345678', 'SC', '+591 78901234', 'supervisor_gestion',       '2024-11-01', 'suspendido');


-- ─────────────────────────────────────────────────────────────────────────────
-- S6. Permisos por defecto según cargo
-- ─────────────────────────────────────────────────────────────────────────────
WITH con AS (SELECT id FROM empresas_consultoras WHERE nit = '5678901')

-- Coordinadora General (Laura): todos los permisos en todos los módulos
INSERT INTO colaborador_permisos
    (colaborador_id, modulo, puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos, puede_gestionar_modulo,
     puede_exportar_reportes, puede_invitar_empresa, configurado_por)
SELECT col.id, m.modulo, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE, TRUE,
       (SELECT id FROM con)
FROM colaboradores col
CROSS JOIN (VALUES ('afp'::tipo_modulo), ('caja'::tipo_modulo), ('ministerio'::tipo_modulo)) m(modulo)
WHERE col.ci = '7654321';

-- Analista AFP (Ricardo): permisos completos solo en AFP, solo lectura en CAJA y Ministerio
INSERT INTO colaborador_permisos
    (colaborador_id, modulo, puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos, puede_gestionar_modulo,
     puede_exportar_reportes, puede_invitar_empresa, configurado_por)
SELECT col.id, m.modulo,
    TRUE,
    CASE WHEN m.modulo = 'afp' THEN TRUE  ELSE FALSE END,
    CASE WHEN m.modulo = 'afp' THEN TRUE  ELSE FALSE END,
    CASE WHEN m.modulo = 'afp' THEN TRUE  ELSE FALSE END,
    FALSE,
    CASE WHEN m.modulo = 'afp' THEN TRUE  ELSE FALSE END,
    CASE WHEN m.modulo = 'afp' THEN TRUE  ELSE FALSE END,
    FALSE,
    (SELECT id FROM con)
FROM colaboradores col
CROSS JOIN (VALUES ('afp'::tipo_modulo), ('caja'::tipo_modulo), ('ministerio'::tipo_modulo)) m(modulo)
WHERE col.ci = '8765432';

-- Analista CAJA (Patricia): ídem para CAJA
INSERT INTO colaborador_permisos
    (colaborador_id, modulo, puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos, puede_gestionar_modulo,
     puede_exportar_reportes, puede_invitar_empresa, configurado_por)
SELECT col.id, m.modulo,
    TRUE,
    CASE WHEN m.modulo = 'caja' THEN TRUE ELSE FALSE END,
    CASE WHEN m.modulo = 'caja' THEN TRUE ELSE FALSE END,
    CASE WHEN m.modulo = 'caja' THEN TRUE ELSE FALSE END,
    FALSE,
    CASE WHEN m.modulo = 'caja' THEN TRUE ELSE FALSE END,
    CASE WHEN m.modulo = 'caja' THEN TRUE ELSE FALSE END,
    FALSE,
    (SELECT id FROM con)
FROM colaboradores col
CROSS JOIN (VALUES ('afp'::tipo_modulo), ('caja'::tipo_modulo), ('ministerio'::tipo_modulo)) m(modulo)
WHERE col.ci = '9876543';

-- Analista Ministerio (Jorge)
INSERT INTO colaborador_permisos
    (colaborador_id, modulo, puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos, puede_gestionar_modulo,
     puede_exportar_reportes, puede_invitar_empresa, configurado_por)
SELECT col.id, m.modulo,
    TRUE,
    CASE WHEN m.modulo = 'ministerio' THEN TRUE ELSE FALSE END,
    CASE WHEN m.modulo = 'ministerio' THEN TRUE ELSE FALSE END,
    CASE WHEN m.modulo = 'ministerio' THEN TRUE ELSE FALSE END,
    FALSE,
    CASE WHEN m.modulo = 'ministerio' THEN TRUE ELSE FALSE END,
    CASE WHEN m.modulo = 'ministerio' THEN TRUE ELSE FALSE END,
    FALSE,
    (SELECT id FROM con)
FROM colaboradores col
CROSS JOIN (VALUES ('afp'::tipo_modulo), ('caja'::tipo_modulo), ('ministerio'::tipo_modulo)) m(modulo)
WHERE col.ci = '3456789';

-- Asistente (Ana): solo puede ver y subir, en todos los módulos
INSERT INTO colaborador_permisos
    (colaborador_id, modulo, puede_ver, puede_registrar_personal, puede_editar_personal,
     puede_subir_documentos, puede_eliminar_documentos, puede_gestionar_modulo,
     puede_exportar_reportes, puede_invitar_empresa, configurado_por)
SELECT col.id, m.modulo,
    TRUE, FALSE, FALSE, TRUE, FALSE, FALSE, FALSE, FALSE,
    (SELECT id FROM con)
FROM colaboradores col
CROSS JOIN (VALUES ('afp'::tipo_modulo), ('caja'::tipo_modulo), ('ministerio'::tipo_modulo)) m(modulo)
WHERE col.ci = '4567890';


-- ─────────────────────────────────────────────────────────────────────────────
-- S7. Empresas clientes con sus usuarios de acceso
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO usuarios
    (nombre_usuario, correo, contrasena_hash, tipo, estado, verificado)
VALUES
    ('emp_constructora_norte',  'acceso@constructoranorte.bo',  '$2a$12$HASH_EMP1', 'empresa_cliente', 'activo', TRUE),
    ('emp_importadora_central', 'acceso@importadoracentral.bo', '$2a$12$HASH_EMP2', 'empresa_cliente', 'activo', TRUE),
    ('emp_agro_beni',           'acceso@agrobeni.bo',           '$2a$12$HASH_EMP3', 'empresa_cliente', 'activo', TRUE),
    ('emp_techsoft',            'acceso@techsoft.bo',           '$2a$12$HASH_EMP4', 'empresa_cliente', 'activo', TRUE),
    ('emp_mineria_cerro',       'acceso@mineriacerro.bo',       '$2a$12$HASH_EMP5', 'empresa_cliente', 'activo', TRUE),
    ('emp_farmacia_salud',      'acceso@farmaciasalud.bo',      '$2a$12$HASH_EMP6', 'empresa_cliente', 'activo', TRUE);

WITH
    con AS (SELECT id FROM empresas_consultoras WHERE nit = '5678901'),
    laura AS (SELECT id FROM colaboradores WHERE ci = '7654321')
INSERT INTO empresas_cliente (
    consultora_id, usuario_id, registrada_por,
    nombre, nit, razon_social, ciudad, departamento,
    telefono, correo_empresa, rep_legal_nombres, rep_legal_apellidos,
    actividad_economica, estado
) VALUES
    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_constructora_norte'),
     (SELECT id FROM laura),
     'Constructora Norte SA', '5678901001', 'Constructora Norte Sociedad Anónima',
     'Santa Cruz', 'Santa Cruz', '+591 3 3456789', 'info@constructoranorte.bo',
     'Roberto', 'Salinas', 'Construcción de obras civiles', 'activo'),

    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_importadora_central'),
     (SELECT id FROM laura),
     'Importadora Central', '3456789001', 'Importadora Central SRL',
     'La Paz', 'La Paz', '+591 2 2345678', 'contacto@importadoracentral.bo',
     'Gloria', 'Mendez', 'Importación y distribución', 'activo'),

    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_agro_beni'),
     (SELECT id FROM laura),
     'Agro Beni Ltda.', '9012345001', 'Agro Beni Limitada',
     'Trinidad', 'Beni', '+591 3 4567890', 'gerencia@agrobeni.bo',
     'Marco', 'Antelo', 'Producción agropecuaria', 'activo'),

    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_techsoft'),
     (SELECT id FROM laura),
     'TechSoft Bolivia', '1234567001', 'TechSoft Bolivia SRL',
     'Cochabamba', 'Cochabamba', '+591 4 5678901', 'admin@techsoft.bo',
     'Diana', 'Quiroga', 'Desarrollo de software', 'activo'),

    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_mineria_cerro'),
     (SELECT id FROM laura),
     'Minería del Cerro', '6789012001', 'Minería del Cerro SA',
     'Potosí', 'Potosí', '+591 2 6789012', 'info@mineriacerro.bo',
     'Juan', 'Mamani', 'Extracción minera', 'activo'),

    ((SELECT id FROM con), (SELECT id FROM usuarios WHERE nombre_usuario = 'emp_farmacia_salud'),
     (SELECT id FROM laura),
     'Farmacia Salud', '2345678001', 'Farmacia Salud SRL',
     'Oruro', 'Oruro', '+591 2 7890123', 'farmacia@saludbo.com',
     'Elena', 'Ticona', 'Venta de productos farmacéuticos', 'activo');


-- ─────────────────────────────────────────────────────────────────────────────
-- S8. Personal de ejemplo (empresa Constructora Norte SA)
--     El trigger fn_crear_fichas_gestion se ejecuta automáticamente.
-- ─────────────────────────────────────────────────────────────────────────────
WITH
    emp AS (SELECT id FROM empresas_cliente WHERE nit = '5678901001'),
    laura AS (SELECT id FROM colaboradores WHERE ci = '7654321')
INSERT INTO personal (
    empresa_id, registrado_por,
    nombres, apellidos, ci, extension_ci, fecha_nacimiento, genero, estado_civil,
    telefono, correo, cargo, fecha_ingreso, tipo_contrato, salario_mensual,
    modalidad, nivel_educacion, profesion
) VALUES
    ((SELECT id FROM emp), (SELECT id FROM laura),
     'Juan Carlos', 'Mamani Quispe', '8234567', 'SC', '1988-04-15',
     'masculino', 'casado', '+591 72345678', 'j.mamani@mail.com',
     'Ingeniero Civil', '2022-01-15', 'indefinido', 5800.00, 'presencial', 'universitario', 'Ingeniería Civil'),

    ((SELECT id FROM emp), (SELECT id FROM laura),
     'María Elena', 'López Vaca', '5678901', 'SC', '1991-09-23',
     'femenino', 'soltera', '+591 71234567', 'm.lopez@mail.com',
     'Contadora', '2022-03-01', 'indefinido', 4500.00, 'presencial', 'universitario', 'Contaduría Pública'),

    ((SELECT id FROM emp), (SELECT id FROM laura),
     'Pedro', 'Cruz Villanueva', '4567890', 'SC', '1985-07-10',
     'masculino', 'casado', '+591 70123456', 'p.cruz@mail.com',
     'Supervisor de Obra', '2021-06-01', 'indefinido', 6200.00, 'presencial', 'tecnico', 'Construcción Civil'),

    ((SELECT id FROM emp), (SELECT id FROM laura),
     'Ana Lucía', 'Quispe Torrez', '7890123', 'SC', '1993-12-05',
     'femenino', 'soltera', '+591 79012345', 'a.quispe@mail.com',
     'Arquitecta', '2023-02-14', 'plazo_fijo', 5200.00, 'presencial', 'universitario', 'Arquitectura');


-- ─────────────────────────────────────────────────────────────────────────────
-- S9. Actualizar datos AFP y CAJA del personal (creados por trigger)
-- ─────────────────────────────────────────────────────────────────────────────
WITH
    ricardo AS (SELECT id FROM colaboradores WHERE ci = '8765432'),
    patricia AS (SELECT id FROM colaboradores WHERE ci = '9876543'),
    jorge    AS (SELECT id FROM colaboradores WHERE ci = '3456789'),
    emp AS (SELECT id FROM empresas_cliente WHERE nit = '5678901001')
UPDATE personal_afp pa SET
    afp_nombre       = CASE p.ci
                           WHEN '8234567' THEN 'Futuro de Bolivia'
                           WHEN '5678901' THEN 'BBVA Previsión AFP'
                           ELSE 'Futuro de Bolivia'
                       END,
    numero_afiliado  = 'AFP-00' || p.ci,
    fecha_afiliacion = p.fecha_ingreso,
    estado           = CASE p.ci
                           WHEN '8234567' THEN 'al_dia'::estado_cumplimiento
                           WHEN '5678901' THEN 'al_dia'::estado_cumplimiento
                           WHEN '4567890' THEN 'al_dia'::estado_cumplimiento
                           WHEN '7890123' THEN 'pendiente'::estado_cumplimiento
                       END,
    actualizado_por  = (SELECT id FROM ricardo)
FROM personal p
WHERE pa.personal_id = p.id
  AND p.empresa_id = (SELECT id FROM emp);

UPDATE personal_caja pc SET
    caja_nombre      = 'Caja Nacional de Salud',
    numero_asegurado = 'CNS-' || p.ci,
    fecha_afiliacion = p.fecha_ingreso,
    estado           = CASE p.ci
                           WHEN '8234567' THEN 'al_dia'::estado_cumplimiento
                           WHEN '5678901' THEN 'sin_datos'::estado_cumplimiento
                           WHEN '4567890' THEN 'al_dia'::estado_cumplimiento
                           WHEN '7890123' THEN 'al_dia'::estado_cumplimiento
                       END,
    actualizado_por  = (SELECT id FROM patricia)
FROM personal p
WHERE pc.personal_id = p.id
  AND p.empresa_id = (SELECT id FROM emp);

UPDATE personal_ministerio pm SET
    numero_registro_mt = 'MT-SC-' || p.ci,
    fecha_registro     = p.fecha_ingreso,
    estado             = CASE p.ci
                             WHEN '8234567' THEN 'pendiente'::estado_cumplimiento
                             WHEN '5678901' THEN 'sin_datos'::estado_cumplimiento
                             WHEN '4567890' THEN 'al_dia'::estado_cumplimiento
                             WHEN '7890123' THEN 'pendiente'::estado_cumplimiento
                         END,
    actualizado_por    = (SELECT id FROM jorge)
FROM personal p
WHERE pm.personal_id = p.id
  AND p.empresa_id = (SELECT id FROM emp);


-- ─────────────────────────────────────────────────────────────────────────────
-- S10. Alertas de ejemplo
-- ─────────────────────────────────────────────────────────────────────────────
INSERT INTO alertas
    (consultora_id, empresa_id, personal_id, modulo, nivel, titulo, descripcion, fecha_vencimiento, generada_auto)
SELECT
    con.id, emp.id, per.id,
    'ministerio', 'urgente',
    'Planilla MT Marzo 2026 vence hoy — ' || per.nombres || ' ' || per.apellidos,
    'La planilla laboral mensual del Ministerio de Trabajo para Marzo 2026 no ha sido cargada.',
    CURRENT_DATE, TRUE
FROM empresas_consultoras con
JOIN empresas_cliente emp ON emp.consultora_id = con.id AND emp.nit = '5678901001'
JOIN personal per ON per.empresa_id = emp.id AND per.ci = '8234567'
WHERE con.nit = '5678901';

INSERT INTO alertas
    (consultora_id, empresa_id, modulo, nivel, titulo, descripcion, fecha_vencimiento, generada_auto)
SELECT
    con.id, emp.id,
    'caja', 'normal',
    'Constructora Norte: 1 empleado sin datos CAJA',
    'María Elena López no tiene documentos CAJA registrados.',
    CURRENT_DATE + 5, TRUE
FROM empresas_consultoras con
JOIN empresas_cliente emp ON emp.consultora_id = con.id AND emp.nit = '5678901001'
WHERE con.nit = '5678901';


-- =============================================================================
-- VISTAS
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- V1. Estado consolidado de cumplimiento por empleado
-- ─────────────────────────────────────────────────────────────────────────────
CREATE VIEW v_cumplimiento_personal AS
SELECT
    p.id                                        AS personal_id,
    p.nombres || ' ' || p.apellidos             AS nombre_completo,
    p.ci, p.cargo, p.estado                     AS estado_personal,
    e.id                                        AS empresa_id,
    e.nombre                                    AS empresa,
    con.id                                      AS consultora_id,
    con.nombre_comercial                        AS consultora,
    COALESCE(afp.estado, 'sin_datos'::estado_cumplimiento) AS estado_afp,
    COALESCE(cja.estado, 'sin_datos'::estado_cumplimiento) AS estado_caja,
    COALESCE(mt.estado,  'sin_datos'::estado_cumplimiento) AS estado_ministerio,
    afp.afp_nombre, afp.numero_afiliado,
    cja.caja_nombre, cja.numero_asegurado,
    mt.numero_registro_mt
FROM personal p
JOIN empresas_cliente         e   ON e.id   = p.empresa_id
JOIN empresas_consultoras     con ON con.id = e.consultora_id
LEFT JOIN personal_afp        afp ON afp.personal_id = p.id
LEFT JOIN personal_caja       cja ON cja.personal_id = p.id
LEFT JOIN personal_ministerio mt  ON mt.personal_id  = p.id;

COMMENT ON VIEW v_cumplimiento_personal IS 'Vista consolidada del estado AFP/CAJA/Ministerio de cada empleado. Usada en la tabla de personal y el panel de empresa cliente.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V2. Cobertura de gestiones por empresa cliente (para dashboard)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE VIEW v_cobertura_empresa AS
SELECT
    e.id                    AS empresa_id,
    e.nombre                AS empresa,
    e.consultora_id,
    COUNT(p.id)             AS total_personal,
    -- AFP
    COUNT(CASE WHEN afp.estado = 'al_dia' THEN 1 END)   AS afp_al_dia,
    ROUND(COUNT(CASE WHEN afp.estado = 'al_dia' THEN 1 END) * 100.0
          / NULLIF(COUNT(p.id), 0), 1)                  AS pct_afp,
    -- CAJA
    COUNT(CASE WHEN cja.estado = 'al_dia' THEN 1 END)   AS caja_al_dia,
    ROUND(COUNT(CASE WHEN cja.estado = 'al_dia' THEN 1 END) * 100.0
          / NULLIF(COUNT(p.id), 0), 1)                  AS pct_caja,
    -- Ministerio
    COUNT(CASE WHEN mt.estado  = 'al_dia' THEN 1 END)   AS ministerio_al_dia,
    ROUND(COUNT(CASE WHEN mt.estado = 'al_dia' THEN 1 END) * 100.0
          / NULLIF(COUNT(p.id), 0), 1)                  AS pct_ministerio
FROM empresas_cliente e
LEFT JOIN personal          p   ON p.empresa_id  = e.id AND p.estado = 'activo'
LEFT JOIN personal_afp      afp ON afp.personal_id = p.id
LEFT JOIN personal_caja     cja ON cja.personal_id = p.id
LEFT JOIN personal_ministerio mt ON mt.personal_id  = p.id
GROUP BY e.id, e.nombre, e.consultora_id;

COMMENT ON VIEW v_cobertura_empresa IS 'Porcentaje de cobertura AFP/CAJA/Ministerio por empresa. Usada en las cards de empresa y el dashboard de la consultora.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V3. Documentos vigentes con contexto completo
-- ─────────────────────────────────────────────────────────────────────────────
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

COMMENT ON VIEW v_documentos_vigentes IS 'Solo documentos activos, vigentes y no eliminados. Usada para mostrar documentos en el perfil del empleado.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V4. Resumen del equipo de la consultora
-- ─────────────────────────────────────────────────────────────────────────────
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

COMMENT ON VIEW v_equipo_consultora IS 'Vista operativa del equipo: estado de acceso, empresas asignadas y módulos que puede gestionar.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V5. Alertas activas priorizadas (para el panel de pendientes)
-- ─────────────────────────────────────────────────────────────────────────────
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

COMMENT ON VIEW v_alertas_activas IS 'Alertas no resueltas ordenadas por urgencia y días para vencer. Usada en el panel de pendientes del consultor.';


-- ─────────────────────────────────────────────────────────────────────────────
-- V6. Estadísticas globales por consultora (para dashboard principal)
-- ─────────────────────────────────────────────────────────────────────────────
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

COMMENT ON VIEW v_stats_consultora IS 'KPIs del dashboard principal de la consultora: empresas, personal, colaboradores, alertas y documentos del mes.';


-- =============================================================================
-- FIN DEL SCRIPT — Consult-360 DB v2.0
-- =============================================================================
