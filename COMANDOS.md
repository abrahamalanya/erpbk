# Comando para utilizar en el servidor el php8.2

```bash
/opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/composer/bin/composer install
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate:fresh --seed
```

⚠️ **`migrate:fresh` borra todas las tablas antes de recrearlas.** Solo es seguro mientras el servidor sigue en fase de configuración, sin datos reales. Una vez que producción tenga datos reales (clientes, créditos, movimientos), cambiar a:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan migrate --seed
```

`--seed` ahora solo corre los seeders de catálogo/config reales de CREDIMAS (roles, permisos, bancos, empresa, agencias, configuración de crédito prendario — todos idempotentes, seguros de re-ejecutar). Los seeders de datos de prueba (usuarios ficticios, clientes, bienes, créditos demo) solo corren si `APP_ENV=local` — confirmar que el `.env` del servidor tenga `APP_ENV=production` para que se salten automáticamente (ver `database/seeders/DatabaseSeeder.php`).

---

# Despliegue: migraciones de créditos vehiculares / hipotecarios (multi-garantía)

Este lote de migraciones (`2026_09_02_*`) generaliza el motor de crédito prendario a vehicular e hipotecario. **Toca datos reales**: hay migraciones que copian filas (back-fill), no solo cambios de esquema.

## Antes de migrar

1. **Backup de la base de datos.**
2. **Ventana de mantenimiento.** Detener workers / cron mientras corre.
3. **NO lanzar dos `php artisan migrate` a la vez.** Un proceso `migrate`/`migrate:fresh` interrumpido a media transacción deja un *metadata lock* en MySQL y cualquier `migrate` posterior se cuelga sin imprimir nada (esperando el lock, timeout por defecto ~1 año). Si pasa: matar el proceso PHP huérfano (`ps aux | grep artisan` / `taskkill`) y reintentar **un solo** `migrate` en primer plano.
4. Para `--seed`: **Reverb debe estar corriendo** (`php artisan reverb:start`) o exportar `BROADCAST_CONNECTION=null` para esa corrida. El `BovedaSeeder` emite eventos `ShouldBroadcastNow` y falla con `BroadcastException` si no hay WebSocket.

## Qué hace cada migración

| Migración | Efecto | Back-fill |
|---|---|---|
| `create_credito_garantia_table` | Pivote polimórfico `credito_garantia` (reemplaza `bien_credito_prendario`) | copia `bien_credito_prendario` → `credito_garantia` (`garantia_type='bien'`) |
| `add_tipo_credito_to_creditos_prendarios_table` | Columna `tipo_credito` (default `prendario`) + índice | automático por default |
| `add_tipo_credito_to_configuraciones_credito_prendario_table` | Columna `tipo_credito` (default `prendario`) | automático por default |
| `add_supervisado_por_to_creditos_prendarios_table` | FK nullable `supervisado_por` | — |
| `add_conformidad_to_creditos_prendarios_table` | `conformidad_path`, `conformidad_confirmada_at` nullable | — |
| `create_vehiculos_table` | Tabla `vehiculos` | — |
| `migrate_bien_fotos_to_garantia_fotos` | Tabla polimórfica `garantia_fotos`; **dropea `bien_fotos`** | copia `bien_fotos` → `garantia_fotos` (`garantia_type='bien'`) |
| `migrate_intereses_bien_to_polymorphic` | Tabla polimórfica `intereses`; **dropea `intereses_bien`** | copia `intereses_bien` → `intereses` (`articulo_type='bien'`) |
| `drop_bien_credito_prendario_table` | **Dropea `bien_credito_prendario`** (ya migrada a `credito_garantia`) | `down()` la reconstruye desde `credito_garantia` |
| `create_inmuebles_table` | Tabla `inmuebles` | — |

## Después de migrar — verificar conteos

```bash
php artisan tinker --execute="
echo 'credito_garantia: '.DB::table('credito_garantia')->where('garantia_type','bien')->count().PHP_EOL;
echo 'garantia_fotos:   '.DB::table('garantia_fotos')->where('garantia_type','bien')->count().PHP_EOL;
echo 'intereses:        '.DB::table('intereses')->where('articulo_type','bien')->count().PHP_EOL;
echo 'creditos sin tipo: '.DB::table('creditos_prendarios')->whereNull('tipo_credito')->count().' (debe ser 0)'.PHP_EOL;
echo 'bien_fotos existe: '.(Schema::hasTable('bien_fotos') ? 'SI (mal)' : 'no').PHP_EOL;
"
```

Los tres primeros deben cuadrar con lo que tenían las tablas viejas antes del despliegue.

## Sembrar catálogo nuevo (idempotente)

```bash
php artisan db:seed --class=PermissionSeeder --force                 # agrega vehiculos.* / inmuebles.* / creditos_vehiculares.* / creditos_hipotecarios.*
php artisan db:seed --class=ConfiguracionCreditoPrendarioSeeder --force   # agrega filas de config tipo 'vehicular' y 'hipotecario'
```

## Rollback

`php artisan migrate:rollback --step=10` revierte el lote. Cada `down()` re-hidrata las tablas viejas desde las polimórficas, así que es sin pérdida — pero igual restaurar del backup si algo falla a mitad.

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


# Seed

php artisan db:seed --class=ConceptoSeeder