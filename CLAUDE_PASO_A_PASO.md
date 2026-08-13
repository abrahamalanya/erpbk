# 📊 ERP Créditos - PASO A PASO DESDE CERO

**Proyecto**: umax  
**Stack**: Laravel 12 + PHP 8.2 + MySQL  
**Fecha**: 30 Julio 2026  
**Objetivo**: Crear desde cero, paso a paso, sin perderse

---

## 🎯 Versiones CONFIRMADAS

```
✅ Laravel: 12.x
✅ PHP: 8.2
✅ Base de Datos: MySQL
✅ Entorno: Herd (local)
```

---

# 📍 PASO 1: CREAR PROYECTO NUEVA (10 minutos)

## ✅ Paso 1.1: Verificar PHP 8.2 en Herd

**Abrir terminal (PowerShell, Terminal, o Terminal de VSCode):**

```bash
php --version
```

**Debe mostrar:**
```
PHP 8.2.x
```

**Si NO es 8.2:**
```bash
herd php:use 8.2
```

**Verificar de nuevo:**
```bash
php --version
# Debe ser 8.2
```

✅ **Checklist**: PHP 8.2 confirmado

---

## ✅ Paso 1.2: Crear Proyecto Laravel 12

**En terminal:**

```bash
cd ~/Herd
laravel new umax --php=8.2
```

**Esperar** mientras se instala (2-3 minutos)

**Verás mensajes como:**
```
✓ Scaffolding application...
✓ Installing dependencies...
✓ Application ready!
```

✅ **Checklist**: Proyecto creado

---

## ✅ Paso 1.3: Entrar al Proyecto

```bash
cd umax
```

---

## ✅ Paso 1.4: Verificar Versión de Laravel

```bash
php artisan --version
```

**Debe mostrar:**
```
Laravel Framework 12.x.x
```

✅ **Checklist**: Laravel 12 confirmado

---

## ✅ Paso 1.5: Abrir en VSCode

```bash
code .
```

**VSCode debe abrirse con la carpeta del proyecto**

✅ **Checklist**: Proyecto abierto en VSCode

---

# 📍 PASO 2: CONFIGURAR BD MYSQL (10 minutos)

## ✅ Paso 2.1: Abrir archivo .env

**En VSCode:**
1. Press `Ctrl+P`
2. Busca `.env`
3. Abre el archivo

**Debe verse algo como:**
```
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:xxx...
```

✅ **Checklist**: .env abierto

---

## ✅ Paso 2.2: Configurar BD MySQL

**En el archivo `.env`, busca:**
```
DB_CONNECTION=
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

**Reemplaza con:**
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=umax
DB_USERNAME=root
DB_PASSWORD=
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: .env configurado

---

## ✅ Paso 2.3: Crear BD en MySQL

**Opción A: Si usas Herd Pro (más fácil)**

```bash
herd database create umax
```

**Opción B: Si usas MySQL local**

```bash
mysql -u root
```

```sql
CREATE DATABASE umax;
EXIT;
```

✅ **Checklist**: BD creada

---

# 📍 PASO 3: INSTALAR SANCTUM (AUTENTICACIÓN API) (5 minutos)

## ✅ Paso 3.1: Ejecutar Install API

**En terminal de VSCode (Ctrl+`):**

```bash
php artisan install:api
```

**Verás:**
```
✓ Publishing Sanctum configuration...
✓ Publishing Sanctum migrations...
✓ Updating User model...
✓ Scaffolding the base Api Routes...

INFO API scaffolding installed successfully.
```

✅ **Checklist**: Sanctum instalado

---

# 📍 PASO 4: INSTALAR PERMISOS Y ROLES (5 minutos)

## ✅ Paso 4.1: Instalar Spatie Permission

```bash
composer require spatie/laravel-permission
```

**Esperar a que termine**

✅ **Checklist**: Package instalado

---

## ✅ Paso 4.2: Publicar Configuración

```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

**Verás:**
```
✓ Publishing...
```

✅ **Checklist**: Config publicada

---

# 📍 PASO 5: EJECUTAR MIGRACIONES (5 minutos)

## ✅ Paso 5.1: Migrar Todo

```bash
php artisan migrate
```

**Verás:**
```
Migrating: 2014_10_12_000000_create_users_table
Migrated:  2014_10_12_000000_create_users_table (xxx ms)
Migrating: 2014_10_12_100000_create_password_reset_tokens_table
Migrated:  2014_10_12_100000_create_password_reset_tokens_table
...más...
Migrating: 2024_01_01_000000_create_permission_tables
Migrated:  2024_01_01_000000_create_permission_tables

Database migrations completed successfully.
```

✅ **Checklist**: Todas las tablas creadas

---

## ✅ Paso 5.2: Verificar Tablas

```bash
php artisan tinker
```

```php
DB::select('SHOW TABLES')
```

**Debe mostrar:** users, personal_access_tokens, roles, permissions, etc.

```php
exit
```

✅ **Checklist**: Tablas verificadas

---

# 📍 PASO 6: CREAR ESTRUCTURA DE CARPETAS (5 minutos)

## ✅ Paso 6.1: En Terminal

```bash
mkdir -p app/Http/Controllers/Api
mkdir -p app/Services
mkdir -p app/Repositories
mkdir -p app/Traits
mkdir -p app/Events
mkdir -p app/Listeners
mkdir -p app/Policies
mkdir -p database/seeders
```

✅ **Checklist**: Carpetas creadas

---

## ✅ Paso 6.2: Verificar en VSCode

**Abre el Explorer (Ctrl+Shift+E)**

**Debe verse:**
```
app/
├── Http/
│   └── Controllers/
│       └── Api/         ← Nueva
├── Models/
├── Services/            ← Nueva
├── Traits/              ← Nueva
└── ...
```

✅ **Checklist**: Estructura visible

---

# 📍 PASO 7: CREAR MODELOS (10 minutos)

## ✅ Paso 7.1: User Model

**El User ya existe, EDITAR:**
`app/Models/User.php`

**Reemplaza TODO con:**

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, HasRoles;

    protected $fillable = [
        'nombre',
        'apellido',
        'email',
        'password',
        'estado',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];
}
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: User.php actualizado

---

## ✅ Paso 7.2: Crear Role Model

**En terminal:**

```bash
php artisan make:model Role
```

**Abre:** `app/Models/Role.php`

**Reemplaza TODO con:**

```php
<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'description'];
}
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: Role.php creado

---

## ✅ Paso 7.3: Crear Permission Model

**En terminal:**

```bash
php artisan make:model Permission
```

**Abre:** `app/Models/Permission.php`

**Reemplaza TODO con:**

```php
<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = ['name', 'guard_name', 'description'];
}
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: Permission.php creado

---

# 📍 PASO 8: CREAR AUTHCONTROLLER (15 minutos)

## ✅ Paso 8.1: Crear Archivo

**En terminal:**

```bash
php artisan make:controller Api/AuthController
```

**Se crea:** `app/Http/Controllers/Api/AuthController.php`

✅ **Checklist**: Archivo creado

---

## ✅ Paso 8.2: Escribir Código

**Abre:** `app/Http/Controllers/Api/AuthController.php`

**Reemplaza TODO con:**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Login - Generar token
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales inválidas',
            ], 401);
        }

        if ($user->estado !== 'activo') {
            return response()->json([
                'success' => false,
                'message' => 'Usuario inactivo',
            ], 403);
        }

        $token = $user->createToken('erp-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login exitoso',
            'data' => [
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Logout - Revocar token
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logout exitoso',
        ]);
    }

    /**
     * Get current user
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->load('roles'),
        ]);
    }
}
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: AuthController completado

---

# 📍 PASO 9: CREAR LOGIN REQUEST (10 minutos)

## ✅ Paso 9.1: Crear Archivo

**En terminal:**

```bash
php artisan make:request LoginRequest
```

**Se crea:** `app/Http/Requests/LoginRequest.php`

✅ **Checklist**: Archivo creado

---

## ✅ Paso 9.2: Escribir Validaciones

**Abre:** `app/Http/Requests/LoginRequest.php`

**Reemplaza TODO con:**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|min:6',
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'El email es requerido',
            'email.email' => 'El email debe ser válido',
            'password.required' => 'La contraseña es requerida',
            'password.min' => 'La contraseña debe tener al menos 6 caracteres',
        ];
    }
}
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: LoginRequest completado

---

# 📍 PASO 10: CREAR API RESPONSE TRAIT (10 minutos)

## ✅ Paso 10.1: Crear Archivo

**En VSCode, click derecho en `app/Traits` → New File**

**Nombre:** `ApiResponse.php`

✅ **Checklist**: Archivo creado

---

## ✅ Paso 10.2: Escribir Código

**Escribe:**

```php
<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    public function successResponse($data, $message = 'Success', $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    public function errorResponse($message = 'Error', $code = 400, $data = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
        ], $code);
    }
}
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: ApiResponse trait creado

---

# 📍 PASO 11: CONFIGURAR RUTAS API (10 minutos)

## ✅ Paso 11.1: Editar routes/api.php

**Abre:** `routes/api.php`

**Reemplaza TODO con:**

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

// ===== AUTH ROUTES (públicas) =====
Route::post('/auth/login', [AuthController::class, 'login']);

// ===== PROTECTED ROUTES (requieren autenticación) =====
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

// ===== HEALTH CHECK =====
Route::get('/health', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()]);
});
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: Rutas configuradas

---

# 📍 PASO 12: CREAR SEEDER DE USUARIO (10 minutos)

## ✅ Paso 12.1: Crear Seeder

**En terminal:**

```bash
php artisan make:seeder UserSeeder
```

**Se crea:** `database/seeders/UserSeeder.php`

✅ **Checklist**: Archivo creado

---

## ✅ Paso 12.2: Escribir Código

**Abre:** `database/seeders/UserSeeder.php`

**Reemplaza TODO con:**

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Usuario Admin
        User::create([
            'nombre' => 'Admin',
            'apellido' => 'Sistema',
            'email' => 'admin@erp.test',
            'password' => bcrypt('password'),
            'estado' => 'activo',
        ]);

        // Usuario Gestor
        User::create([
            'nombre' => 'Gestor',
            'apellido' => 'Créditos',
            'email' => 'gestor@erp.test',
            'password' => bcrypt('password'),
            'estado' => 'activo',
        ]);

        // Usuario Ejecutivo
        User::create([
            'nombre' => 'Ejecutivo',
            'apellido' => 'Ventas',
            'email' => 'ejecutivo@erp.test',
            'password' => bcrypt('password'),
            'estado' => 'activo',
        ]);
    }
}
```

**Guarda:** `Ctrl+S`

✅ **Checklist**: UserSeeder completado

---

# 📍 PASO 13: EJECUTAR SEEDER (5 minutos)

## ✅ Paso 13.1: Correr Seeder

**En terminal:**

```bash
php artisan db:seed --class=UserSeeder
```

**Verás:**
```
Seeding: Database\Seeders\UserSeeder
Seeded:  Database\Seeders\UserSeeder (xxx ms)
Database seeding completed successfully.
```

✅ **Checklist**: Usuarios creados en BD

---

## ✅ Paso 13.2: Verificar Usuarios

**En terminal:**

```bash
php artisan tinker
```

```php
User::all()
```

**Debe mostrar los 3 usuarios creados**

```php
exit
```

✅ **Checklist**: Usuarios verificados

---

# 📍 PASO 14: INICIAR SERVIDOR (5 minutos)

## ✅ Paso 14.1: Ejecutar Servidor

**En terminal:**

```bash
php artisan serve
```

**Verás:**
```
INFO  Server running on [http://127.0.0.1:8000].
```

✅ **Checklist**: Servidor en vivo

---

## ✅ Paso 14.2: Abrir en Navegador

**Acceder a:**
```
http://localhost:8000
```

**Debe verse la página de bienvenida de Laravel**

✅ **Checklist**: Servidor funciona

---

# 📍 PASO 15: PROBAR LOGIN (10 minutos)

## ✅ Paso 15.1: Con cURL en Terminal

**En OTRA terminal (sin cerrar el servidor):**

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@erp.test",
    "password": "password"
  }'
```

**Debe retornar:**
```json
{
  "success": true,
  "message": "Login exitoso",
  "data": {
    "user": {
      "id": 1,
      "nombre": "Admin",
      "apellido": "Sistema",
      "email": "admin@erp.test",
      "estado": "activo",
      ...
    },
    "access_token": "3|abc123xyz789...",
    "token_type": "Bearer"
  }
}
```

✅ **Checklist**: Login funciona

---

## ✅ Paso 15.2: Usar Token en Ruta Protegida

**Copiar el token de la respuesta anterior (ej: `3|abc123xyz789...`)**

**En terminal:**

```bash
curl -X GET http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer 3|abc123xyz789..." \
  -H "Accept: application/json"
```

**Debe retornar:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "nombre": "Admin",
    "email": "admin@erp.test",
    ...
  }
}
```

✅ **Checklist**: Ruta protegida funciona

---

# 📍 PASO 16: PROBAR HEALTH CHECK (5 minutos)

## ✅ Paso 16.1: Health Check

```bash
curl http://localhost:8000/api/health
```

**Debe retornar:**
```json
{
  "status": "ok",
  "timestamp": "2026-07-30T..."
}
```

✅ **Checklist**: Health check funciona

---

# ✅ FASE 1 COMPLETADA

```
✅ Proyecto Laravel 12 creado
✅ PHP 8.2 confirmado
✅ MySQL configurado
✅ Sanctum instalado
✅ Roles y permisos instalados
✅ Migraciones ejecutadas
✅ User, Role, Permission models creados
✅ AuthController funcional
✅ Login Request con validaciones
✅ Rutas API configuradas
✅ Usuarios de prueba creados
✅ Servidor ejecutándose
✅ Login testeado ✅
✅ Ruta protegida funciona ✅
✅ Health check funciona ✅

ESTADO: LISTO PARA FASE 2
```

---

# 🚀 PRÓXIMO: FASE 2 (Clientes/CRM)

Cuando reportes que FASE 1 está completa:

```
1. Crear Model Cliente
2. Crear ClienteController
3. Crear CRUD (Create, Read, Update, Delete)
4. Validaciones
5. Tests

TIEMPO: 3-4 horas
```

---

# 📋 CHECKLIST FINAL

Marca ✅ mientras avanzas:

- [ ] PHP 8.2 verificado
- [ ] Proyecto Laravel 12 creado
- [ ] BD MySQL configurada
- [ ] Sanctum instalado
- [ ] Permisos instalados
- [ ] Migraciones ejecutadas
- [ ] Carpetas creadas
- [ ] User model actualizado
- [ ] Role model creado
- [ ] Permission model creado
- [ ] AuthController creado
- [ ] LoginRequest creado
- [ ] ApiResponse trait creado
- [ ] Rutas api.php configuradas
- [ ] UserSeeder creado
- [ ] Usuarios en BD
- [ ] Servidor ejecutándose
- [ ] Login testeado
- [ ] Token generado
- [ ] Ruta protegida funciona
- [ ] Health check funciona

**Si TODOS son ✅, FASE 1 está COMPLETA**

---

**Última actualización**: 30 Julio 2026  
**Para**: Recreación desde cero  
**Stack**: Laravel 12 + PHP 8.2 + MySQL

**¡Éxito!** 🚀
