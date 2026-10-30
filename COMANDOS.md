# Comando para utilizar en el servidor el php8.2

```bash
/opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/composer/bin/composer install
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate:fresh --seed
/opt/cpanel/ea-php82/root/usr/bin/php artisan db:seed --class=RoleSeeder
/opt/cpanel/ea-php82/root/usr/bin/php artisan db:seed --class=PermissionSeeder
```

```bash
php -S 0.0.0.0:8000 -t public
adb reverse tcp:8000 tcp:8000

# APLICATIVO LEVANTAR
npx expo start

# APLICATIVO PRODUCCION - POWERSHELL
npx eas-cli@latest build --platform android --profile production

npx eas-cli@latest build --platform android --profile production --local

npx eas-cli@latest credentials --platform android

# RECONSTRUIR EL APP
npx expo prebuild --clean --platform android
npx expo run:android
npx expo start --dev-client
```

## Generar el APK BUILD en LOCAL

Desde Git Bash, ejecuta:

```bash
cd /c/Users/min/dev/abrahamalanya/umax-app

export JAVA_HOME="C:/Program Files/Eclipse Adoptium/jdk-17.0.20.8-hotspot"
export PATH="/c/Users/min/.config/herd/bin/nvm/v26.7.0:/c/Program Files/Eclipse Adoptium/jdk-17.0.20.8-hotspot/bin:$PATH"

export APP_ENV=production
export NODE_ENV=production
export API_URL="https://erpbk.credimasperu.com/api"
export DOTENV_CONFIG_PATH="C:/Users/min/dev/abrahamalanya/umax-app/.env.production.local"

cd android

./gradlew :app:assembleRelease \
  --max-workers=2 \
  -PreactNativeArchitectures=armeabi-v7a,arm64-v8a

Cuando aparezca BUILD SUCCESSFUL, encontrarás el APK en:

C:\Users\min\dev\abrahamalanya\umax-app\android\app\build\outputs\apk\release\app-release.apk

La firma de producción ya quedó configurada. Conserva estos archivos locales, que están excluidos de Git:

- credentials.json
- credentials/android/keystore.jks
- .env.production.local

Estos comandos recompilan la versión actual. Para distribuir una nueva actualización, incrementa versionCode, actualmente 27, en app.config.ts y en android/app/build.gradle.
```

```bash
# 1) Si cambiaste versión/versionCode en app.config.ts, sincroniza android/ primero:
export APP_ENV=production
npx expo prebuild --platform android --no-install

export JAVA_HOME="C:/Program Files/Eclipse Adoptium/jdk-17.0.20.8-hotspot"
export PATH="/c/Users/min/.config/herd/bin/nvm/v26.7.0:/c/Program Files/Eclipse Adoptium/jdk-17.0.20.8-hotspot/bin:$PATH"
export NODE_ENV=production
export API_URL="https://erpbk.credimasperu.com/api"
export DOTENV_CONFIG_PATH="C:/Users/min/dev/abrahamalanya/umax-app/.env.production.local"

cd android
./gradlew :app:assembleRelease --max-workers=2 -PreactNativeArchitectures=armeabi-v7a,arm64-v8a
```


## ⚠️ El servidor es PHP 8.2 — no regenerar el lock con PHP 8.4

En local, Herd tiene PHP 8.4 como `php` por defecto, pero **producción corre 8.2**
(`/opt/cpanel/ea-php82`). Si se corre `composer update` con el PHP de Herd, el
`composer.lock` se resuelve para 8.4 y `composer install` falla en el servidor con:

- `endroid/qr-code is locked to version 6.1.3 ... requires php ^8.4`
- `maennchen/zipstream-php is locked to version 3.2.2 ... requires php-64bit ^8.3`

Por eso `composer.json` fija `config.platform.php = "8.2.0"`: composer resuelve
siempre como si el PHP fuera 8.2, sin importar con qué versión se ejecute, así
que el lock siempre instala en el servidor. Versiones fijadas por ese platform pin:

| Paquete | Lock (PHP 8.4) | Lock (PHP 8.2) | Motivo |
| --- | --- | --- | --- |
| `endroid/qr-code` | 6.1.3 (`^8.4`) | **6.0.9** | 6.1+ exige PHP ^8.4 |
| `maennchen/zipstream-php` | 3.2.2 (`^8.3`) | **3.1.2** | 3.2+ exige PHP ^8.3 |
| `phpoffice/phpspreadsheet` | 5.9.0 | **5.10.0** | necesita `zipstream ^2.1 \|\| ^3.0` |

`endroid/qr-code` quedó en `^6.0` en el `require` porque `^6.1` excluiría la 6.0.9.
La API usada (`new QrCode(data:, size:, margin:)` + `PngWriter`) es igual en 6.0 y 6.1.

**Al tocar dependencias, resolver siempre con el PHP del servidor:**

```bash
# Windows / Herd — usar php82, no el php84 por defecto
C:\Users\min\.config\herd\bin\php82.bat C:\Users\min\.config\herd\bin\composer.phar update <paquete>
```

Ojo: `composer update` regenera `vendor/composer/platform_check.php`; si el proceso
se corta a mitad, ese archivo queda viejo y `artisan` muere con
`Composer detected issues in your platform: ... require a PHP version ">= 8.4.0"`.
Se arregla con `composer dump-autoload` y volver a correr `php artisan package:discover`.

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
