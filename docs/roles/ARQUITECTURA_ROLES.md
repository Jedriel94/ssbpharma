# 🏗️ Arquitectura del Sistema de Roles

## 📐 Diagrama de Arquitectura

```
┌─────────────────────────────────────────────────────────────────────┐
│                         FRONTEND (UI)                                │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌──────────────────┐    ┌──────────────────┐   ┌─────────────────┐│
│  │  login-sistema   │───▶│   Dashboard      │   │  Generador de   ││
│  │     .php         │    │   Admin          │   │   Passwords     ││
│  └──────────────────┘    └──────────────────┘   └─────────────────┘│
│                                  │                                   │
│                                  ▼                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │          admin/usuarios-sistema.php                         │   │
│  │  - Lista usuarios    - Crear usuarios    - Ver jerarquía   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                       │
└───────────────────────────────────────┬───────────────────────────────┘
                                        │
                    ┌───────────────────┴───────────────────┐
                    ▼                                       ▼
┌─────────────────────────────────────┐   ┌────────────────────────────┐
│     API LAYER (REST)                │   │   MIDDLEWARE               │
├─────────────────────────────────────┤   ├────────────────────────────┤
│                                     │   │                            │
│  api/usuarios-sistema.php           │   │  AuthMiddleware.php        │
│  ┌─────────────────────────┐       │   │  ┌────────────────────┐   │
│  │ GET    - Listar/Buscar  │       │   │  │ requireAuth()      │   │
│  │ POST   - Crear          │◀──────┼───┤  │ requireAdmin()     │   │
│  │ PUT    - Actualizar     │       │   │  │ tienePermiso()     │   │
│  │ DELETE - Eliminar       │       │   │  │ logActividad()     │   │
│  └─────────────────────────┘       │   │  └────────────────────┘   │
│                                     │   │                            │
└────────────────┬────────────────────┘   └──────────────┬─────────────┘
                 │                                       │
                 └───────────────┬───────────────────────┘
                                 ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      BUSINESS LOGIC (Models)                         │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌──────────────────────┐          ┌──────────────────────┐        │
│  │     Role.php         │          │  UsuarioSistema.php  │        │
│  ├──────────────────────┤          ├──────────────────────┤        │
│  │ - getAll()           │          │ - crear()            │        │
│  │ - getById()          │          │ - actualizar()       │        │
│  │ - getByCodigo()      │          │ - login()            │        │
│  │ - tienePermiso()     │          │ - validarSesion()    │        │
│  │ - getPermisos()      │          │ - getSubordinados()  │        │
│  │ - puedeGestionar()   │          │ - getJerarquia()     │        │
│  └──────────┬───────────┘          └──────────┬───────────┘        │
│             │                                  │                     │
└─────────────┼──────────────────────────────────┼─────────────────────┘
              │                                  │
              └──────────────┬───────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      DATABASE LAYER (MySQL)                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  ┌──────────────────┐   ┌──────────────────┐   ┌─────────────────┐ │
│  │     roles        │   │ usuarios_sistema │   │   sesiones_     │ │
│  ├──────────────────┤   ├──────────────────┤   │   sistema       │ │
│  │ id (PK)          │   │ id (PK)          │   ├─────────────────┤ │
│  │ nombre           │   │ username (UQ)    │   │ id (PK)         │ │
│  │ codigo (UQ)      │   │ password         │   │ usuario_        │ │
│  │ nivel_jerarquico │   │ nombre           │   │   sistema_id    │ │
│  │ descripcion      │   │ email (UQ)       │   │ token (UQ)      │ │
│  │ permisos (JSON)  │◀──┤ rol_id (FK)      │   │ expires_at      │ │
│  │ created_at       │   │ superior_id (FK) │   │ ip_address      │ │
│  │ updated_at       │   │ representante_id │   │ user_agent      │ │
│  └──────────────────┘   │ activo           │   └─────────────────┘ │
│                         │ ultimo_acceso    │                        │
│                         │ created_at       │   ┌─────────────────┐ │
│                         │ updated_at       │   │  logs_actividad │ │
│                         └──────────────────┘   │   _sistema      │ │
│                                                ├─────────────────┤ │
│  ┌──────────────────┐                         │ id (PK)         │ │
│  │  clientes        │  (NO MODIFICADA)        │ usuario_        │ │
│  │  (EXISTENTE)     │                         │   sistema_id    │ │
│  └──────────────────┘                         │ accion          │ │
│                                                │ modulo          │ │
│  ┌──────────────────┐                         │ entidad_tipo    │ │
│  │ representantes   │  (VINCULADA)            │ entidad_id      │ │
│  │  (EXISTENTE)     │                         │ datos_anteriores│ │
│  └──────────────────┘                         │ datos_nuevos    │ │
│                                                │ ip_address      │ │
│                                                │ created_at      │ │
│                                                └─────────────────┘ │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## 🔄 Flujo de Autenticación

```
Usuario                Frontend             Middleware           Model              BD
  │                      │                     │                   │                │
  │  1. Submit Login     │                     │                   │                │
  ├─────────────────────▶│                     │                   │                │
  │                      │ 2. Validar datos    │                   │                │
  │                      ├────────────────────▶│                   │                │
  │                      │                     │ 3. login()        │                │
  │                      │                     ├──────────────────▶│                │
  │                      │                     │                   │ 4. SELECT      │
  │                      │                     │                   ├───────────────▶│
  │                      │                     │                   │ 5. Usuario     │
  │                      │                     │                   │◀───────────────┤
  │                      │                     │ 6. verify password│                │
  │                      │                     │◀──────────────────┤                │
  │                      │                     │ 7. crear sesión   │                │
  │                      │                     ├──────────────────▶│                │
  │                      │                     │                   │ 8. INSERT      │
  │                      │                     │                   ├───────────────▶│
  │                      │ 9. Token + datos    │                   │                │
  │                      │◀────────────────────┤                   │                │
  │ 10. Redirigir        │                     │                   │                │
  │◀─────────────────────┤                     │                   │                │
  │                      │                     │                   │                │
```

## 🔐 Flujo de Autorización

```
Usuario                Frontend             Middleware           Model              BD
  │                      │                     │                   │                │
  │  1. Acceder página   │                     │                   │                │
  ├─────────────────────▶│                     │                   │                │
  │                      │ 2. requireAuth()    │                   │                │
  │                      ├────────────────────▶│                   │                │
  │                      │                     │ 3. validarSesion()│                │
  │                      │                     ├──────────────────▶│                │
  │                      │                     │                   │ 4. SELECT      │
  │                      │                     │                   ├───────────────▶│
  │                      │                     │                   │ 5. Sesión      │
  │                      │                     │                   │◀───────────────┤
  │                      │                     │ 6. Usuario datos  │                │
  │                      │                     │◀──────────────────┤                │
  │                      │ 7. Usuario OK       │                   │                │
  │                      │◀────────────────────┤                   │                │
  │                      │ 8. tienePermiso()   │                   │                │
  │                      ├────────────────────▶│                   │                │
  │                      │                     │ 9. getPermisos()  │                │
  │                      │                     ├──────────────────▶│                │
  │                      │                     │                   │ 10. SELECT     │
  │                      │                     │                   ├───────────────▶│
  │                      │                     │ 11. Permisos JSON │                │
  │                      │                     │◀──────────────────┤                │
  │                      │ 12. true/false      │                   │                │
  │                      │◀────────────────────┤                   │                │
  │ 13. Renderizar UI    │                     │                   │                │
  │◀─────────────────────┤                     │                   │                │
  │                      │                     │                   │                │
```

## 📊 Flujo de Jerarquía

```
┌──────────────────────────────────────────────────────────────────┐
│                   Consulta de Subordinados                        │
└──────────────────────────────────────────────────────────────────┘

                              Director General (ID: 1)
                                      │
                    ┌─────────────────┼─────────────────┐
                    ▼                 ▼                 ▼
             Director UN 1      Director UN 2     Director UN 3
               (ID: 2)             (ID: 3)           (ID: 4)
                    │                 │                 │
          ┌─────────┴────────┐       │       ┌─────────┴────────┐
          ▼                  ▼       ▼       ▼                  ▼
      Gerente 1          Gerente 2  ...  Gerente 3          Gerente 4
       (ID: 5)            (ID: 6)         (ID: 7)            (ID: 8)
          │                  │               │                  │
    ┌─────┴─────┐      ┌────┴────┐    ┌────┴────┐        ┌────┴────┐
    ▼           ▼      ▼         ▼    ▼         ▼        ▼         ▼
  Rep 1       Rep 2  Rep 3     Rep 4 Rep 5    Rep 6    Rep 7     Rep 8
  (ID:9)     (ID:10)(ID:11)   (ID:12)(ID:13) (ID:14)  (ID:15)   (ID:16)

Consulta recursiva:
  getJerarquia(1) → Retorna todo el árbol
  getSubordinados(2) → Retorna [Gerente 1, Gerente 2]
  puedeVerUsuario(2, 9) → true (Rep 1 está bajo Director UN 1)
```

## 🔄 Flujo CRUD (Crear Usuario)

```
Admin                  UI                   API                Model              BD
  │                    │                    │                   │                 │
  │ 1. Clic "Nuevo"    │                    │                   │                 │
  ├───────────────────▶│                    │                   │                 │
  │                    │ 2. Mostrar modal   │                   │                 │
  │◀───────────────────┤                    │                   │                 │
  │ 3. Llenar form     │                    │                   │                 │
  ├───────────────────▶│                    │                   │                 │
  │ 4. Submit          │                    │                   │                 │
  ├───────────────────▶│                    │                   │                 │
  │                    │ 5. POST request    │                   │                 │
  │                    ├───────────────────▶│                   │                 │
  │                    │                    │ 6. requireAdmin() │                 │
  │                    │                    ├───[Middleware]────┤                 │
  │                    │                    │ 7. crear()        │                 │
  │                    │                    ├──────────────────▶│                 │
  │                    │                    │                   │ 8. INSERT       │
  │                    │                    │                   ├────────────────▶│
  │                    │                    │                   │ 9. ID           │
  │                    │                    │                   │◀────────────────┤
  │                    │                    │ 10. logActividad()│                 │
  │                    │                    ├──────────────────▶│                 │
  │                    │                    │                   │ 11. INSERT log  │
  │                    │                    │                   ├────────────────▶│
  │                    │ 12. Success + ID   │                   │                 │
  │                    │◀───────────────────┤                   │                 │
  │ 13. Alert + refresh│                    │                   │                 │
  │◀───────────────────┤                    │                   │                 │
  │                    │                    │                   │                 │
```

## 🏛️ Capas de la Arquitectura

### 1. Capa de Presentación (UI)
**Responsabilidad:** Interfaz de usuario
- `login-sistema.php` - Formulario de login
- `admin/usuarios-sistema.php` - Panel de gestión
- `scripts/tools/generar-password.php` - Utilidad de passwords

### 2. Capa de API
**Responsabilidad:** Exponer endpoints REST
- `api/usuarios-sistema.php` - CRUD operations
- Validación de inputs
- Respuestas JSON estandarizadas

### 3. Capa de Middleware
**Responsabilidad:** Seguridad y autenticación
- `AuthMiddleware.php` - Verificación de permisos
- Gestión de sesiones
- Log de actividad

### 4. Capa de Lógica de Negocio (Models)
**Responsabilidad:** Reglas de negocio
- `Role.php` - Lógica de roles
- `UsuarioSistema.php` - Lógica de usuarios
- Validaciones de negocio
- Operaciones complejas

### 5. Capa de Datos (Database)
**Responsabilidad:** Persistencia
- Tablas MySQL
- Relaciones FK
- Índices optimizados
- Constraints

## 🔒 Modelo de Seguridad

```
┌─────────────────────────────────────────────────────────────┐
│                    NIVELES DE SEGURIDAD                      │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  Nivel 1: AUTENTICACIÓN                                      │
│  ├─ Username + Password                                      │
│  ├─ Bcrypt hash                                              │
│  ├─ Token seguro (32 bytes)                                  │
│  └─ Sesión con expiración                                    │
│                                                               │
│  Nivel 2: AUTORIZACIÓN                                       │
│  ├─ RBAC (Role-Based Access Control)                         │
│  ├─ Permisos granulares (JSON)                              │
│  ├─ Jerarquía organizacional                                 │
│  └─ Verificación en cada operación                           │
│                                                               │
│  Nivel 3: AUDITORÍA                                          │
│  ├─ Log de todas las acciones                                │
│  ├─ Registro de cambios (before/after)                       │
│  ├─ IP + User-Agent                                          │
│  └─ Timestamp preciso                                        │
│                                                               │
│  Nivel 4: VALIDACIÓN                                         │
│  ├─ PDO Prepared Statements                                  │
│  ├─ Sanitización de inputs                                   │
│  ├─ Validación de tipos                                      │
│  └─ Escape de outputs (XSS)                                  │
│                                                               │
└─────────────────────────────────────────────────────────────┘
```

## 📈 Escalabilidad

### Horizontal
- Sesiones en base de datos (no en filesystem)
- API stateless
- Tokens independientes
- Sin dependencia de servidor específico

### Vertical
- Índices optimizados
- Consultas preparadas
- Lazy loading de jerarquías
- Cache de permisos (futuro)

### Performance
- Joins eficientes
- Índices en FKs
- Paginación (implementar)
- Búsqueda optimizada

---

*Diagrama de arquitectura actualizado: 2026-01-14*
