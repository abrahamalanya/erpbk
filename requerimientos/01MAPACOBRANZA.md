# UMAX: Unificar mapa de asesores en vivo y rutas de cobranza

## Rutas del proyecto

- Backend: `~/herd/umax`
- Frontend web: `~/dev/abrahamalanya/umax-frontend`
- App móvil: `~/dev/abrahamalanya/umax-app`

## 1. Objetivo

Actualmente, en el dashboard de Inicio existen dos mapas:

- **Asesores en Vivo:** muestra la ubicación GPS de los asesores.
- **Mapa de Ruta:** muestra las rutas y los clientes que debe visitar cada asesor para realizar las cobranzas.

Quiero implementar un **mapa unificado** que permita visualizar simultáneamente la ubicación de los asesores en tiempo real y sus respectivas rutas de cobranza.

Antes de modificar el código, revisa la implementación actual en el backend, frontend web y app móvil. Identifica los componentes, endpoints, servicios GPS, consultas, modelos y reglas de negocio que ya existen. Reutiliza lo que sea posible y evita duplicar lógica.

No elimines ni reemplaces definitivamente los mapas actuales hasta verificar que el nuevo mapa funciona correctamente.

## 2. Mapa unificado

El nuevo mapa debe mostrar simultáneamente:

- La ubicación actual de los asesores autorizados.
- Las rutas de cobranza correspondientes a cada asesor.
- Los clientes que forman parte de cada ruta.
- El estado de visita de cada cliente.
- El estado de cobranza del día.

Inicialmente, cuando el usuario ingrese al dashboard, debe visualizar a todos los asesores y sus rutas que tenga permitido consultar según sus roles, permisos y agencia.

La información debe actualizarse periódicamente sin necesidad de recargar toda la página o pantalla.

## 3. Identificación de asesores y colores

Cada asesor debe tener un color distintivo.

Ese color debe utilizarse de forma consistente para:

- La ruta del asesor.
- La identificación de su recorrido en el mapa.
- Los elementos visuales que permitan relacionar al asesor con su ruta.

El marcador de ubicación del asesor debe utilizar un **ícono de motocicleta**, ya que los asesores realizan sus recorridos en moto.

Evita asignar el mismo color a distintos asesores visibles simultáneamente, siempre que sea posible. Mantén los colores estables durante las actualizaciones del mapa para que no cambien constantemente.

## 4. Estados visuales de los clientes

Cada cliente debe tener un marcador cuyo color represente su estado de visita y cobranza.

### Estado 1: Color original

El cliente conserva su color original cuando:

- El asesor todavía no ha llegado a su zona.
- No existe evidencia suficiente para confirmar que permaneció en la ubicación del cliente durante el tiempo requerido.
- La información GPS disponible no permite confirmar una visita de forma confiable.

### Estado 2: Amarillo — Permanencia mínima de 3 minutos

El marcador del cliente debe cambiar a **amarillo cuando el asesor permanezca durante al menos 3 minutos consecutivos dentro del radio de proximidad establecido para ese cliente**.

Esta regla debe implementarse con las siguientes condiciones:

1. Utiliza las coordenadas GPS reales del asesor y las coordenadas de la ubicación de cobranza seleccionada para el cliente.
2. Calcula la distancia geográfica real entre ambas ubicaciones. No utilices la distancia visual entre marcadores en pantalla.
3. Implementa un radio de proximidad configurable, reutilizando la configuración existente si ya está disponible. Si no existe, establece un valor predeterminado razonable y permite ajustarlo desde una configuración apropiada.
4. Considera la precisión GPS de las lecturas para evitar falsos positivos.
5. Registra la hora de inicio de la permanencia y confirma la visita cuando hayan transcurrido al menos 3 minutos consecutivos con evidencia GPS suficiente.
6. El contador debe basarse en tiempo real transcurrido, no en la cantidad de actualizaciones GPS recibidas.
7. Si el asesor sale del radio de proximidad, reinicia el contador. No acumules tiempos de visitas separadas.
8. Si las lecturas GPS son insuficientes, tienen mala precisión, están desactualizadas o existe una interrupción que impide confirmar la permanencia, no marques automáticamente al cliente en amarillo.
9. No inventes ubicaciones ni utilices la ubicación anterior como si fuera una posición actual.
10. Guarda en el backend los datos necesarios para registrar y validar la permanencia, incluyendo hora de inicio, hora de confirmación y las coordenadas o evidencias necesarias según la arquitectura existente.
11. La validación debe persistir al actualizar el mapa, cambiar de pantalla o cerrar y volver a abrir la aplicación.
12. Evita generar registros duplicados en cada actualización GPS.

**Importante:** tres minutos entre dos lecturas GPS no demuestran por sí solos que el asesor permaneció en el lugar durante todo ese intervalo. Implementa una validación que considere la frecuencia de las lecturas, su antigüedad, su precisión y las posibles interrupciones. Si no existe evidencia suficiente, no confirmes la permanencia.

No consideres que una permanencia equivale a una cobranza. Son estados distintos.

### Estado 3: Verde — Cobranza realizada hoy

El marcador del cliente debe mostrarse en **verde cuando exista un cobro válido registrado para ese cliente durante el día actual**.

Condiciones:

- Utiliza los registros reales de cobranza del backend.
- No marques en verde por una promesa de pago, una visita sin cobro o un intento de cobranza.
- No consideres pagos anulados, reversados o invalidados.
- Define correctamente el día actual según la zona horaria utilizada por el sistema.
- El estado debe persistir al actualizar el mapa.
- Si el cliente ya tiene una cobranza válida hoy, conserva el color verde aunque el asesor se aleje.

El color verde tiene prioridad sobre el amarillo.

## 5. Ubicación de cobranza del cliente: CASA o NEGOCIO

El sistema debe respetar la ubicación de cobranza seleccionada en el módulo de clientes.

Cada cliente puede tener como ubicación de cobranza:

- CASA.
- NEGOCIO.

La ubicación seleccionada determina las coordenadas utilizadas para:

- Mostrar al cliente en el mapa.
- Calcular la distancia entre el asesor y el cliente.
- Detectar la permanencia mínima de 3 minutos.
- Validar la llegada del asesor a la zona del cliente.

Si la ubicación seleccionada no tiene coordenadas GPS válidas, muestra una advertencia clara y no utilices silenciosamente las coordenadas de la otra ubicación.

Revisa la implementación existente de este campo y reutilízala. Si todavía no está implementado en alguno de los proyectos, completa la integración respetando las reglas de negocio existentes.

## 6. Filtros por agencia y asesor

Agrega filtros para controlar qué información se muestra en el mapa.

### Comportamiento inicial

Si no se selecciona ninguna agencia ni asesor, mostrar todos los asesores y rutas que el usuario tenga autorización para consultar.

### Selección de agencia

Cuando el usuario seleccione una agencia, mostrar todos los asesores de esa agencia y sus respectivas rutas, siempre que estén dentro de sus permisos.

### Selección de asesor

Permitir seleccionar un asesor específico dentro de la agencia seleccionada y mostrar únicamente su ubicación, ruta y clientes.

### Combinaciones esperadas

- Sin filtros: todos los asesores autorizados.
- Solo agencia: todos los asesores autorizados de esa agencia.
- Agencia y asesor: únicamente el asesor seleccionado, perteneciente a esa agencia.
- Cambio de agencia: actualizar los asesores disponibles en el selector y limpiar la selección de asesor si ya no corresponde.

No permitas consultar información de otras agencias si el usuario no tiene autorización.

## 7. Información al seleccionar un asesor

Al pulsar el marcador de motocicleta, muestra una ventana informativa con:

- Nombre del asesor.
- Agencia a la que pertenece.
- Fecha y hora de la última ubicación GPS recibida.
- Estado de actualización GPS, indicando si la ubicación está actualizada o desactualizada.
- Información adicional relevante que ya esté disponible en el sistema.

Si la ubicación está desactualizada, indícalo claramente. No presentes la última ubicación conocida como si fuera necesariamente la posición actual.

## 8. Información al seleccionar un cliente

Al pulsar el marcador de un cliente, muestra:

- Nombre del cliente.
- Asesor asignado.
- Agencia correspondiente, cuando esté disponible.
- Ubicación de cobranza seleccionada: CASA o NEGOCIO.
- Estado visual de visita: pendiente, permanencia confirmada o cobranza realizada hoy.
- Tiempo de permanencia, cuando se esté calculando o ya se haya confirmado.
- Hora de inicio y hora de confirmación de permanencia, cuando corresponda.
- Estado de cobranza del día.
- Información de saldo o cuota pendiente si ya existe y puede mostrarse respetando los permisos actuales.

No inventes información que no esté disponible en el sistema.

## 9. GPS y actualizaciones en tiempo real

Revisa cómo se obtiene y almacena actualmente la ubicación GPS de los asesores.

Reutiliza el mecanismo existente siempre que sea adecuado.

Implementa las actualizaciones de forma eficiente:

- Actualiza periódicamente la ubicación de los asesores.
- Refresca las rutas y estados de los clientes cuando sea necesario.
- Evita recargar toda la página o pantalla.
- Evita realizar solicitudes excesivas al backend.
- Evita crear múltiples procesos de actualización cuando se cambia de pantalla.
- Limpia correctamente los temporizadores, suscripciones o listeners al desmontar los componentes.
- Maneja errores de red, permisos GPS denegados y ausencia temporal de señal.
- Muestra la fecha y hora de la última actualización recibida.

Si el sistema no recibe nuevas coordenadas, conserva la última ubicación conocida, pero indica que está desactualizada y no la utilices para confirmar una nueva permanencia.

No cambies innecesariamente los permisos de ubicación ni implementes seguimiento en segundo plano sin revisar primero la arquitectura actual y los permisos que realmente requiere la aplicación.

## 10. Backend y validaciones

La lógica crítica debe validarse en el backend. No confíes únicamente en el estado visual del frontend.

Revisa e implementa, según corresponda:

- Obtención de las ubicaciones GPS de los asesores.
- Obtención de los clientes asignados a las rutas.
- Coordenadas de la ubicación CASA o NEGOCIO seleccionada.
- Cálculo de distancias geográficas.
- Validación de precisión y antigüedad de las lecturas GPS.
- Control del tiempo de permanencia de 3 minutos.
- Persistencia del estado de visita.
- Detección de cobros válidos realizados hoy.
- Control de acceso por roles, permisos y agencias.
- Protección frente a registros duplicados y actualizaciones concurrentes.

El backend debe ser la fuente de verdad para los estados de permanencia confirmada y cobranza realizada. El frontend puede mostrar un contador provisional, pero no debe confirmar por sí solo una visita.

No crees tablas, columnas o endpoints duplicados si la funcionalidad ya existe. Si necesitas cambios de base de datos, crea migraciones compatibles con los datos actuales.

## 11. Frontend web y app móvil

Implementa la funcionalidad en los proyectos que correspondan:

- Backend: `~/herd/umax`
- Frontend web: `~/dev/abrahamalanya/umax-frontend`
- App móvil: `~/dev/abrahamalanya/umax-app`

Mantén una experiencia coherente entre web y app, adaptando los controles al tamaño de pantalla y a los componentes existentes.

Reutiliza el proveedor de mapas, los componentes, los estilos y las librerías ya instaladas. No agregues dependencias nuevas sin comprobar primero que sean necesarias.

Incluye una leyenda visual que explique claramente los colores de los clientes y los colores de las rutas.

## 12. Rendimiento y estabilidad

El mapa puede mostrar varios asesores y numerosos clientes simultáneamente, por lo que debes cuidar el rendimiento.

- Evita recalcular todas las rutas cuando cambia únicamente una ubicación GPS.
- Evita duplicar marcadores y líneas durante las actualizaciones.
- Evita consultas individuales innecesarias por cada cliente.
- Agrupa las consultas cuando sea conveniente.
- No reinicies el contador de permanencia por simples actualizaciones de la interfaz.
- Mantén el estado consistente al cambiar los filtros.
- Maneja adecuadamente errores y estados de carga.
- No introduzcas regresiones en el dashboard ni en las rutas de cobranza existentes.

## 13. Pruebas obligatorias

Prueba como mínimo los siguientes casos:

1. El mapa inicia mostrando todos los asesores autorizados y sus rutas.
2. Al seleccionar una agencia, aparecen únicamente sus asesores autorizados.
3. Al seleccionar un asesor, se muestra solamente su información y ruta.
4. Cada asesor mantiene un color identificable y su marcador utiliza una motocicleta.
5. Un cliente conserva su color original mientras no exista evidencia de permanencia suficiente.
6. El cliente cambia a amarillo solo después de al menos 3 minutos consecutivos dentro del radio, con lecturas GPS válidas y suficientes.
7. El cliente no cambia a amarillo si el asesor estuvo cerca durante menos de 3 minutos.
8. El cliente no cambia a amarillo cuando la precisión GPS o la antigüedad de las lecturas impide confirmar la permanencia.
9. Si el asesor sale del radio antes de completar los 3 minutos, el contador se reinicia.
10. No se acumulan los tiempos de visitas separadas.
11. La permanencia confirmada se conserva después de actualizar el mapa.
12. Un cobro válido registrado hoy cambia el cliente a verde.
13. Un pago anulado o reversado no genera el estado verde.
14. El estado verde prevalece sobre el amarillo.
15. El mapa utiliza las coordenadas CASA o NEGOCIO seleccionadas para el cliente.
16. Si la ubicación seleccionada carece de coordenadas válidas, aparece una advertencia y no se usa la otra ubicación automáticamente.
17. Si la ubicación del asesor queda desactualizada, se informa al usuario y no se confirma una nueva permanencia con datos insuficientes.
18. Los usuarios no pueden consultar agencias o asesores fuera de sus permisos.
19. La actualización del mapa no genera duplicados ni ralentiza la interfaz.
20. Web y app funcionan correctamente con errores de red y permisos GPS denegados.

Ejecuta las pruebas automatizadas disponibles y las verificaciones de lint o compilación que correspondan. Si alguna prueba no puede ejecutarse, indica la razón.

## 14. Forma de trabajo

Trabaja en este orden:

1. Inspecciona el backend, frontend web y app móvil.
2. Documenta brevemente cómo funcionan actualmente los dos mapas, el GPS, las rutas y las cobranzas.
3. Identifica qué componentes y servicios pueden reutilizarse.
4. Define los cambios mínimos necesarios en backend, base de datos y frontend.
5. Implementa la funcionalidad completa.
6. Ejecuta las pruebas y corrige los errores encontrados.
7. Verifica que no se hayan alterado otras reglas de negocio.
8. Conserva los mapas anteriores hasta comprobar que el nuevo mapa unificado funciona correctamente.

No te limites a entregar un análisis o una propuesta: **implementa los cambios directamente en el código de los proyectos**.

Al finalizar, presenta un resumen concreto que incluya:

- Archivos modificados en cada proyecto.
- Cambios realizados en backend, web y app.
- Migraciones creadas, si las hubo.
- Cómo funciona la detección de permanencia de 3 minutos.
- Pruebas ejecutadas y sus resultados.
- Pendientes o limitaciones que no hayan podido resolverse.