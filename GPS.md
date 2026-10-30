# GPS por dispositivo

Desplegar el backend (incluida `php artisan migrate`) antes de actualizar web y app. La migración conserva los puntos históricos sin inventarles un dispositivo. Su reversión está bloqueada para evitar pérdida de posiciones al volver a una sola fila por asesor; requiere conciliación previa y una migración explícita.

Android usa SHA-256 de `Application.getAndroidId()` con prefijo UMAX, sin permisos telefónicos ni IMEI. Es estable para la combinación dispositivo, usuario Android y firma de la app; puede cambiar tras restablecer el equipo o cambiar la firma. Mantener la firma de producción al actualizar. En otras plataformas se usa un UUID de instalación guardado en SecureStore. No es una prueba de integridad del hardware ni autentica al usuario.

Referencias verificadas: https://docs.expo.dev/versions/v57.0.0/sdk/application/ y https://developer.android.com/about/versions/10/privacy/changes#non-resettable-device-ids.

Cada inicio de sesión vincula el dispositivo y un token. Reingresar en el mismo equipo invalida únicamente el token anteriormente vinculado a ese equipo. Cambiar de asesor conserva el historial con su autor original y elimina la ubicación en vivo anterior del equipo. Cerrar sesión detiene el tracking local; en el servidor conserva la última posición como sesión inactiva. Sin conexión, el servidor solo podrá advertir la antigüedad hasta recuperar comunicación. Clientes anteriores sin identidad se separan provisionalmente por token; para identidad física persistente deben actualizarse.

El reporte usa `fecha=YYYY-MM-DD` en la zona horaria de la aplicación y devuelve recorridos separados por dispositivo. Los datos anteriores aparecen como «sin dispositivo». El trazado reutiliza OpenRouteService y su caché existente; configurar `OPENROUTESERVICE_KEY` en el backend. No se envía la clave a los clientes. Es una reconstrucción estimada por calles entre muestras GPS, no una prueba de la calle exacta transitada. Sin clave, con cuota agotada o ante fallos, muestra puntos GPS y desactiva la reproducción: no presenta líneas rectas como calles. La animación recorre la geometría a distancia uniforme en 60 segundos, con pausa y reinicio; no representa la velocidad real.

Se usan dependencias ya instaladas. Para validar en un celular: iniciar el mismo asesor en dos equipos, comprobar dos marcadores e historiales, cerrar uno y verificar que el otro siga activo; cambiar de usuario en un equipo y verificar que el historial previo mantenga su autor. Validar permisos y persistencia del servicio GPS en un build Android de producción.
