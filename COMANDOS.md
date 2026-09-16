# Comando para utilizar en el servidor el php8.2

```bash
/opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/composer/bin/composer install
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate:fresh --seed
/opt/cpanel/ea-php82/root/usr/bin/php artisan db:seed --class=RoleSeeder
/opt/cpanel/ea-php82/root/usr/bin/php artisan db:seed --class=PermissionSeeder

```

⚠️ **`migrate:fresh` borra todas las tablas antes de recrearlas.** Solo es seguro mientras el servidor sigue en fase de configuración, sin datos reales. Una vez que producción tenga datos reales (clientes, créditos, movimientos), cambiar a:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate --seed
```

# Comando para levantar websocket en local

```bash
php artisan reverb:start
```

# Comando para agregar un usuario (rol "sistemas")

El rol `sistemas` es el de acceso total: cruza todas las empresas, no lleva `empresa_id` ni `agencia_id`.

**Servidor (SSH / bash)** — la barra invertida escapa `$` y el `\Throwable`:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan tinker --execute="try { \$user = App\Modules\Usuario\Models\User::create(['nombre' => 'abraham', 'apellido' => 'alanya', 'email' => 'abrahamalanya@laravel.com', 'password' => bcrypt('abrahamalanya'), 'estado' => 'activo']); \$user->assignRole('sistemas'); echo 'Usuario sistemas creado: '.\$user->email; } catch (\Throwable \$e) { echo 'ERROR: '.\$e->getMessage(); }"
```

**Local (PowerShell, Windows)** — PowerShell NO trata `\` como escape, así que hay que usar backtick (`` ` ``) antes de cada `$` en vez de `\`; probado y funcionando:

```powershell
php artisan tinker --execute="try { `$user = App\Modules\Usuario\Models\User::create(['nombre' => 'abraham', 'apellido' => 'alanya', 'email' => 'abrahamalanya@laravel.com', 'password' => bcrypt('abrahamalanya'), 'estado' => 'activo']); `$user->assignRole('sistemas'); echo 'Usuario sistemas creado: '.`$user->email; } catch (\Throwable `$e) { echo 'ERROR: '.`$e->getMessage(); }"
```
