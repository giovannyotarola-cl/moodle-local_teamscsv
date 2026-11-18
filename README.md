# TeamsCSV para Moodle

Plugin local para vincular cursos de Moodle con Teams existentes mediante CSV, preparar membresías administradas y mostrar enlaces de reuniones, grabaciones y material de clases.

**Versión alfa: no es una garantía de sincronización ni una validación para uso masivo.** El operador reportó altas y bajas de alumnos en Teams y pestañas con vínculos funcionando en **un par de cursos de Moodle**. No se ha probado el uso masivo ni se garantiza el comportamiento en otros entornos; consulte el alcance y las pruebas pendientes en [PRUEBAS.md](PRUEBAS.md). Este repositorio tiene historial independiente del entorno original e incluye la GPLv3 oficial y las atribuciones en [LICENCIAS.md](LICENCIAS.md).

## Funcionalidades

| Funcionalidad | Qué permite hacer | Condiciones y límites |
| --- | --- | --- |
| Vinculación mediante CSV | Asociar cursos de Moodle con equipos de Teams existentes usando `course_id,team_name`. | Usa la caché local de Teams; no crea equipos ni sobrescribe vínculos existentes. Máximo 100 registros y 1 MiB por archivo. |
| Previsualización y confirmación | Revisar las asociaciones propuestas antes de aplicarlas. | Requiere administración del sitio. La previsualización es de un solo uso, está ligada al usuario y caduca a los 15 minutos. |
| Gestión de membresías | Incorporar alumnos al equipo y retirar los administrados cuando dejan de pertenecer al curso; contemplar docentes como propietarios según las capacidades de Microsoft 365. | Requiere identidades válidas, configuración y parche compatibles. Solo actúa sobre el alcance cuya responsabilidad tiene registrada; no administra indiscriminadamente a todos los miembros del equipo. |
| Trabajo programado | Encolar cambios y procesar membresías mediante tareas, con límites por ejecución y espera progresiva ante errores. | No es sincronización instantánea ni garantiza completar cada ejecución. Depende de la ejecución efectiva de las tareas y del acceso a Microsoft Graph. |
| Reuniones | Mostrar vínculos detectados en las publicaciones del canal General para acceder a reuniones en Teams. | No es una agenda completa ni consulta todas las reuniones del calendario. |
| Grabaciones y material | Mostrar enlaces a carpetas verificables de grabaciones y material de clases en Microsoft 365. | Publica vínculos: no copia los archivos a Moodle ni concede permisos sobre ellos. |
| Pestañas del curso | Dar acceso a **Reuniones**, **Grabaciones** y **Material**, priorizándolas junto a **Curso** y **Actividades**. | Se aplica donde hay un vínculo válido y los nodos requeridos; conserva el desbordamiento a **Más** en pantallas angostas. No es un editor configurable del orden. |
| Herramientas de operación | Actualizar la caché de Teams mediante CLI y consultar diagnósticos acotados de trabajos pendientes. | La actualización exige condiciones de ejecución controladas; los diagnósticos no sustituyen comprobar el resultado remoto. |

La gestión de membresías y el descubrimiento de enlaces son opciones independientes, **desactivadas por defecto**. No basta con importar el CSV para que se ejecuten: consulte [INSTALACION.md](INSTALACION.md) y [MEMBERSHIP-SYNC.md](MEMBERSHIP-SYNC.md). El parche O365 y la versión exacta indicada abajo son requisitos, no componentes instalados automáticamente por TeamsCSV. No use esta importación para Teams administrados por School Data Sync (SDS).

**Alcance probado:** el operador reportó altas, bajas y pestañas con vínculos funcionando en un par de cursos. **No se ha probado el uso masivo**, la carga elevada ni todos los escenarios de error; los resultados y límites se detallan en [PRUEBAS.md](PRUEBAS.md).

## Pestañas del curso

![Pestañas Curso, Reuniones, Grabaciones, Material, Actividades y Más](docs/imagenes/pestanas-curso.png)

Captura aportada por el operador, recortada para mostrar únicamente la navegación. Ilustra la disposición de las pestañas; no acredita por sí sola la sincronización de membresías ni el funcionamiento a gran escala.

## Compatibilidad exacta

| Componente | Requisito |
| --- | --- |
| Plugin | `local_teamscsv`, versión `2026060407` (407) |
| Release | `0.1.4-alpha`, `MATURITY_ALPHA` |
| Moodle | Mínimo declarado `2025040800`; no implica validación de todas las versiones posteriores |
| Microsoft 365 | `local_o365` **exactamente `2025040835`**, más el parche incluido y sus tres hashes exactos |

No basta con instalar una versión «igual o superior» de Microsoft 365. Aunque Moodle representa la dependencia como un mínimo, el adaptador comprueba la igualdad exacta y rechaza archivos parcheados diferentes.

## Antes de empezar

1. Lea [INSTALACION.md](INSTALACION.md) y pruebe únicamente en un entorno aislado con datos sintéticos.
2. Revise [SEGURIDAD.md](SEGURIDAD.md) y [MEMBERSHIP-SYNC.md](MEMBERSHIP-SYNC.md) antes de habilitar tareas.
3. Ejecute el subconjunto offline descrito en [PRUEBAS.md](PRUEBAS.md). No sustituye una prueba de integración.

## CSV

Encabezado literal: `course_id,team_name`. Use UTF-8, separador coma, máximo 100 registros y 1 MiB. `course_id` es el ID interno numérico positivo, sin ceros iniciales; no es el ID number del curso. Los nombres deben coincidir exactamente con la caché de Teams y no ser ambiguos.

Ejemplo exclusivamente sintético; sustituya ambos campos por datos de su laboratorio:

```csv
course_id,team_name
2,Equipo de prueba
```

La previsualización lee la caché local, no verifica el estado remoto en tiempo real. La aplicación guarda conexiones y trabajo pendiente, pero **no modifica Teams durante la importación**. No sobrescribe conexiones existentes. Excluya Teams administrados por School Data Sync (SDS).

## Contenido

- `teamscsv/`: plugin, idiomas y pruebas originales, incluidos los diagnósticos actuales de membresías.
- `patches/local_o365-managed-membership.patch`: parche obligatorio para la versión exacta de Microsoft 365.
- Documentación pública en español. Las cadenas y comentarios originales del código se conservan sin traducciones que alteren su fidelidad.

No se incluyen Moodle, Microsoft 365, credenciales, CSV reales, ZIP históricos, respaldos, registros ni herramientas de navegador. La única captura incluida muestra las pestañas, sin el nombre ni el código del curso. No hay descarga automática de fixtures ni instalación de dependencias.

## Errores y contribuciones

El repositorio está orientado a mantener parches con control de versiones y recibir propuestas de mejora mediante issues. Describa versiones exactas, resultado esperado y pasos reproducibles con datos sintéticos; consulte [SEGURIDAD.md](SEGURIDAD.md) antes de adjuntar información. Las mejoras propuestas deben conservar las guardas de compatibilidad e incluir pruebas y documentación. Para problemas del plugin Microsoft 365 sin relación con este parche, use los canales de upstream indicados en [LICENCIAS.md](LICENCIAS.md).
