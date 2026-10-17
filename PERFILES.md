# Análisis y planificación: perfiles de acceso por módulos para UMAX

## 1. Contexto del proyecto

Estoy trabajando en el sistema UMAX, que está dividido en tres proyectos independientes:

- **Backend:** `~/Herd/umax`
- **Frontend web:** `~/Dev/abrahamalanya/umax-frontend`
- **Aplicación móvil:** `~/Dev/abrahamalanya/umax-app`

Actualmente, el sistema ya cuenta con usuarios, roles y permisos. También existe una navegación organizada en módulos y submódulos que se muestra en el navbar de la versión web y en la navegación de la aplicación móvil.

Ejemplos de módulos existentes:

- INICIO
- ADMINISTRACIÓN
  - Empresas
  - Agencias
  - Usuarios
  - Clientes
  - Planilla
- GESTIÓN
  - Permisos temporales
- CRÉDITOS
  - Créditos diarios
  - Créditos prendarios
  - Otros módulos existentes
- FINANZAS
  - Mi caja
  - Otros módulos existentes
- VENTAS
  - Ventas
  - Otros módulos existentes

La estructura anterior es ilustrativa. Debes descubrir la estructura real revisando el código de los tres proyectos.

## 2. Objetivo funcional

Quiero implementar un módulo de administración denominado **Perfiles de acceso por módulos**.

Este módulo permitirá crear diferentes perfiles que agrupen módulos existentes del sistema. Después, podré asignar un perfil a cada usuario y el sistema determinará qué módulos tendrá disponibles.

Ejemplos de perfiles:

### Perfil: Créditos diarios
- CRÉDITOS → Créditos diarios.
- FINANZAS → Mi caja.

### Perfil: Créditos prendarios
- CRÉDITOS → Créditos prendarios.
- VENTAS → Ventas.

### Otros perfiles
- Créditos hipotecarios.
- Créditos vehiculares.
- Otros perfiles que se creen en el futuro.

Los perfiles deben ser configurables desde la interfaz administrativa. No quiero que las combinaciones de módulos estén codificadas de forma fija.

Un perfil debe poder contener módulos de diferentes categorías y permitir seleccionar los módulos específicos que correspondan a cada línea de negocio.

Los ejemplos anteriores son referenciales. Antes de definir las relaciones o la estructura de datos, identifica cómo se representan realmente los módulos, submódulos, rutas y permisos en el proyecto actual.

## 3. Funcionamiento esperado

### A. Administración de perfiles

Crear una interfaz para:

- Listar los perfiles existentes.
- Crear perfiles.
- Editar perfiles.
- Activar o desactivar perfiles, si resulta compatible con la arquitectura actual.
- Asignar un nombre y, si corresponde, una descripción.
- Seleccionar los módulos existentes que pertenecerán al perfil.
- Mostrar los módulos agrupados por sus categorías originales.
- Guardar y modificar las relaciones entre perfiles y módulos.

No duplicar el catálogo de módulos existente ni crear identificadores paralelos si ya existe una estructura que pueda reutilizarse.

### B. Asignación a usuarios

Adaptar el formulario existente de creación y edición de usuarios para permitir seleccionar el perfil de acceso.

El administrador debe poder asignar el perfil correspondiente sin tener que seleccionar manualmente todos los módulos para cada usuario.

Analizar cómo se relacionaría esta asignación con los usuarios, roles y permisos actuales.

Si se necesita soportar usuarios con más de un perfil, evalúa esa posibilidad y justifica la decisión. No implementes esa complejidad sin determinar primero si es necesaria.

### C. Inicio de sesión y navegación web

Cuando un usuario inicie sesión:

- El backend debe proporcionar la información necesaria sobre los módulos disponibles para ese usuario.
- El frontend debe mostrar únicamente las categorías, los módulos y los submódulos autorizados.
- Las categorías que no tengan ningún módulo visible deben ocultarse, excepto aquellas que deban mantenerse por una regla explícita del sistema.
- La navegación debe respetar los módulos ya existentes y su jerarquía.
- Los accesos directos, menús secundarios y rutas protegidas deben respetar la misma política.
- La actualización del perfil debe reflejarse correctamente según la estrategia actual de sesión, autenticación y caché.

### D. Aplicación móvil

Implementar el mismo comportamiento en la aplicación móvil:

- Mostrar únicamente los módulos permitidos para el usuario autenticado.
- Filtrar la navegación, los menús y los accesos a las pantallas correspondientes.
- Evitar mantener una configuración independiente de perfiles en la app.
- Reutilizar la información proporcionada por el backend.
- Manejar correctamente las sesiones existentes y la actualización de los perfiles.
- Evitar que un módulo oculto pueda abrirse mediante navegación directa dentro de la app.

La web y la app deben aplicar las mismas reglas de acceso, aunque cada una tenga una interfaz diferente.

## 4. Reglas de seguridad obligatorias

Este requerimiento no debe sustituir ni debilitar el sistema actual de roles y permisos.

Distinguir claramente:

1. **Roles:** definen las funciones y responsabilidades del usuario.
2. **Permisos:** determinan las operaciones que puede realizar.
3. **Perfiles de acceso:** limitan los módulos de negocio que tiene disponibles.

La autorización efectiva debe respetar las reglas de seguridad existentes y la nueva restricción por perfil.

Como regla general, un usuario solo debe poder acceder a una funcionalidad cuando su perfil permita el módulo y sus permisos actuales autoricen la operación correspondiente, salvo las excepciones administrativas que ya estén explícitamente definidas en el sistema.

Es obligatorio:

- Aplicar las restricciones en el backend, no solamente ocultar elementos visuales.
- Proteger los endpoints de la API y las operaciones sensibles.
- Verificar el acceso a recursos y registros conforme a las reglas existentes.
- Evitar que conocer una URL, identificador o endpoint permita saltarse las restricciones.
- Revisar las políticas, middleware, gates, guards, servicios y mecanismos de autorización existentes.
- No conceder permisos automáticamente por el hecho de asignar un perfil.
- No eliminar, sobrescribir ni alterar permisos existentes sin una justificación y un plan explícitos.

## 5. Compatibilidad con usuarios actuales

El sistema ya está funcionando y tiene usuarios, roles y permisos asignados.

Por ello, debes estudiar cómo introducir esta funcionalidad sin interrumpir la operación actual.

Analizar especialmente:

- Cómo migrar la base de datos sin perder información.
- Qué perfil tendrán los usuarios existentes.
- Cómo evitar bloquear accidentalmente a administradores u otros usuarios actuales.
- Qué comportamiento tendrá un usuario sin perfil asignado.
- Cómo manejar perfiles inactivos o eliminados.
- Cómo mantener la compatibilidad con los clientes web y móvil actuales.
- Cómo invalidar o actualizar la información de acceso almacenada en sesión o caché.

No asumas que todos los usuarios deben recibir un mismo perfil por defecto. Presenta las alternativas y recomienda una estrategia segura que no cambie silenciosamente los accesos actuales.

## 6. Fase actual: análisis, sin implementar

Por ahora, NO modifiques archivos, NO crees migraciones, NO ejecutes cambios en la base de datos y NO implementes la funcionalidad.

Primero inspecciona los tres proyectos y documenta su arquitectura real.

### Backend

Revisa:

- Framework, versión y arquitectura.
- Modelos de usuarios, roles y permisos.
- Relaciones y tablas existentes.
- Middleware, políticas y mecanismos de autorización.
- Rutas de la API.
- Servicio o endpoint de autenticación.
- Estructura de la respuesta de inicio de sesión.
- Cómo se obtienen y representan los módulos.
- Validaciones, controladores, servicios y patrones existentes.
- Pruebas automatizadas relevantes.

### Frontend web

Revisa:

- Framework y estructura del proyecto.
- Implementación del navbar y los menús.
- Cómo se construyen las categorías y los módulos.
- Cómo se obtiene la información del usuario autenticado.
- Dónde se almacenan los permisos y los datos de sesión.
- Cómo se protegen las rutas.
- Cómo se registran las rutas y los accesos directos.
- Los formularios actuales de usuarios y la interfaz administrativa.
- Las pruebas relevantes.

### Aplicación móvil

Revisa:

- Framework, estructura y arquitectura.
- Proceso de autenticación.
- Obtención y almacenamiento de datos del usuario.
- Construcción de menús y navegación.
- Pantallas y rutas de los módulos.
- Manejo de permisos, sesión, caché y estado.
- Integración actual con el backend.
- Pruebas relevantes.

No te limites a buscar nombres de archivos: sigue el flujo real de datos desde el inicio de sesión hasta la construcción de la navegación y la autorización de las solicitudes.

## 7. Entregables del análisis

Presenta un informe estructurado con los siguientes apartados:

### A. Diagnóstico actual
Explica cómo funciona actualmente la autenticación, la gestión de permisos, el catálogo de módulos y la navegación en cada proyecto.

Incluye las rutas y los archivos relevantes que hayas verificado.

### B. Arquitectura propuesta
Describe cómo integrar los perfiles con los roles, permisos y módulos existentes.

Determina si es necesario introducir nuevas tablas, relaciones, endpoints o componentes, y explica por qué.

### C. Modelo de datos
Propón el modelo de datos más apropiado según la estructura actual.

Incluye las relaciones entre perfiles, módulos y usuarios, las restricciones de integridad y la estrategia de migración.

No inventes nombres definitivos de tablas o campos sin contrastarlos con la arquitectura real.

### D. Contrato de API
Propón los endpoints necesarios para gestionar perfiles y asignarlos a usuarios.

Explica cómo se entregarán los módulos efectivos al frontend y a la app, aprovechando los mecanismos actuales siempre que sea posible.

Incluye ejemplos de respuestas JSON únicamente como propuestas, diferenciándolas de las respuestas que ya existen.

### E. Cambios por proyecto
Detalla por separado las modificaciones necesarias en:

1. Backend.
2. Frontend web.
3. Aplicación móvil.

Identifica archivos existentes que podrían reutilizarse y los componentes nuevos que realmente serían necesarios.

### F. Seguridad y compatibilidad
Documenta las reglas de autorización, los riesgos, las excepciones administrativas, la gestión de usuarios existentes y las medidas para evitar accesos indebidos.

### G. Plan de implementación por fases
Propón una secuencia ordenada de trabajo, con dependencias entre proyectos y criterios de aceptación.

Incluye al menos:

1. Preparación y pruebas de referencia.
2. Modelo de datos y backend.
3. Administración de perfiles.
4. Asignación a usuarios.
5. Integración con la web.
6. Integración con la app.
7. Pruebas de seguridad y regresión.
8. Despliegue y verificación.

### H. Pruebas
Define casos de prueba para usuarios con perfiles distintos, usuarios sin perfil, usuarios con varios roles, permisos insuficientes, cambios de perfil, perfiles desactivados y accesos directos a endpoints o rutas.

Incluye pruebas que verifiquen que los usuarios actuales no pierdan accesos de forma inesperada.

### I. Decisiones pendientes
Enumera únicamente las decisiones que realmente requieran confirmación antes de implementar.

Para cada decisión, explica las alternativas, sus consecuencias y cuál recomiendas.

## 8. Restricciones de trabajo

- No implementar nada en esta fase.
- No modificar archivos ni configuraciones.
- No realizar cambios en la base de datos.
- No ejecutar migraciones.
- No reemplazar el sistema de permisos existente.
- No duplicar módulos ni mantener catálogos paralelos innecesarios.
- No asumir tecnologías, estructuras o relaciones sin comprobarlas.
- Respetar las convenciones y patrones actuales de los tres proyectos.
- No realizar refactorizaciones ajenas al objetivo.
- Identificar los riesgos de compatibilidad antes de proponer cambios.

## 9. Resultado esperado

Quiero terminar esta fase con un plan técnico concreto, coherente con la arquitectura real de UMAX y suficientemente detallado para implementar la funcionalidad de forma incremental en los tres proyectos.

Al finalizar, espera mi aprobación antes de realizar cualquier modificación.

Empieza inspeccionando los tres proyectos y luego presenta el diagnóstico y el plan.